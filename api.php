<?php
/**
 * REST API — Sistem Manajemen Risiko
 *
 * Endpoint: /api.php/{resource}/{id}
 * Auth: Header `X-API-Key: <api_key>`
 *
 * Resources:
 *   GET    /api.php/risiko          — list semua risiko
 *   GET    /api.php/risiko/{id}     — detail risiko
 *   POST   /api.php/risiko          — buat risiko baru (permission: risiko:write)
 *   GET    /api.php/kategori        — list kategori
 *   GET    /api.php/mitigasi/{id_risiko} — mitigasi per risiko
 *   GET    /api.php/laporan         — summary laporan
 *
 * Response: JSON
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

// Rate limit: 100 request per menit per IP.
// Atomik via tabel DB (api_rate_limit) — fallback ke file bila tabel belum ada.
$ipAddr = getIPAddress();
$ipHash = hash_hmac('sha256', $ipAddr, APP_KEY);
$window = intdiv(time(), 60) * 60;
$rlHit = null;
try {
    $rlDb = getDB();
    $rlStmt = $rlDb->prepare(
        'INSERT INTO api_rate_limit (ip_hash, window_start, hits) VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE
           hits = IF(window_start = VALUES(window_start), hits + 1, 1),
           window_start = VALUES(window_start)'
    );
    $rlStmt->bind_param('si', $ipHash, $window);
    $rlStmt->execute();
    $rlStmt->close();
    $rlStmt = $rlDb->prepare('SELECT hits FROM api_rate_limit WHERE ip_hash = ? AND window_start = ?');
    $rlStmt->bind_param('si', $ipHash, $window);
    $rlStmt->execute();
    $rlHit = (int)($rlStmt->get_result()->fetch_column() ?: 0);
    $rlStmt->close();
} catch (Throwable $e) {
    $rlHit = null; // fallback file-based di bawah
}
if ($rlHit === null) {
    // Fallback file-based (race-prone, hanya jika tabel belum termigrasi)
    $rlFile = sys_get_temp_dir() . '/manris_api_' . $ipHash . '.txt';
    $rl = 0;
    if (is_file($rlFile)) {
        if ((time() - filemtime($rlFile)) >= 60) {
            $rl = 0;
        } else {
            $rl = (int) file_get_contents($rlFile);
        }
    }
    if ($rl >= 100) {
        http_response_code(429);
        header('Content-Type: application/json');
        header('Retry-After: 60');
        echo json_encode(['error' => 'Rate limit exceeded. Max 100 req/min.']);
        exit;
    }
    file_put_contents($rlFile, $rl + 1);
} elseif ($rlHit > 100) {
    http_response_code(429);
    header('Content-Type: application/json');
    header('Retry-After: 60');
    echo json_encode(['error' => 'Rate limit exceeded. Max 100 req/min.']);
    exit;
}

// CORS (hanya izinkan origin tertentu, atau sesuai APP_URL)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigin = defined('APP_URL') ? rtrim((string)parse_url(APP_URL, PHP_URL_SCHEME) . '://' . (string)parse_url(APP_URL, PHP_URL_HOST) . (parse_url(APP_URL, PHP_URL_PORT) ? ':' . parse_url(APP_URL, PHP_URL_PORT) : ''), '/') : '';
if ($origin === $allowedOrigin) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-CSRF-Token');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Auth via session (untuk notif endpoint) atau API key
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
$isSessionAuth = hasValidSession();

if ($apiKey === '' && !$isSessionAuth) {
    http_response_code(401);
    echo json_encode(['error' => 'Auth required. Provide X-API-Key header or login session.']);
    exit;
}

$db = getDB();

// ── Parse route (harus di atas sebelum dipakai) ─────────────
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/api.php';
$route = $uri;
if (str_starts_with($route, $scriptName)) {
    $route = substr($route, strlen($scriptName));
}
$route = ltrim($route, '/');
$parts = array_values(array_filter(explode('/', $route)));
$resource = $parts[0] ?? '';
$idParam  = isset($parts[1]) ? (int)$parts[1] : 0;
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Handle notif endpoints (session-based, no API key needed)
if ($isSessionAuth && $resource === 'notif_read' && $method === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        http_response_code(419); echo json_encode(['error' => 'CSRF token invalid.']); exit;
    }
    $notifId = (int)($_POST['id'] ?? 0);
    $userId = (int)$_SESSION['user_id'];
    $stmt = $db->prepare('UPDATE notifikasi SET is_read = 1 WHERE id = ? AND id_user = ?');
    $stmt->bind_param('ii', $notifId, $userId);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['ok' => true]);
    exit;
}
if ($isSessionAuth && $resource === 'notif_read_all' && $method === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        http_response_code(419); echo json_encode(['error' => 'CSRF token invalid.']); exit;
    }
    $userId = (int)$_SESSION['user_id'];
    $stmt = $db->prepare('UPDATE notifikasi SET is_read = 1 WHERE id_user = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['ok' => true]);
    exit;
}

// ── Endpoint internal session-based: set_theme ────────────────
// POST /api.php/set_theme  body: theme=light|dark
// Menggantikan includes/set_theme.php yang diblokir .htaccess.
if ($isSessionAuth && $resource === 'set_theme' && $method === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        http_response_code(419); echo json_encode(['error' => 'CSRF token invalid.']); exit;
    }
    $theme = in_array($_POST['theme'] ?? '', ['light', 'dark'], true) ? $_POST['theme'] : 'light';
    $_SESSION['theme'] = $theme;
    echo json_encode(['ok' => true, 'theme' => $theme]);
    exit;
}

// ── Endpoint internal session-based: heatmap ──────────────────
// GET /api.php/heatmap?p={1-5}&d={1-5}
// Menggantikan includes/ajax_heatmap.php yang diblokir .htaccess.
// Filter: Admin & Pimpinan lihat semua; role lain hanya miliknya sendiri.
if ($isSessionAuth && $resource === 'heatmap' && $method === 'GET') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        http_response_code(419); echo json_encode(['error' => 'CSRF token invalid.']); exit;
    }
    $p = (int)($_GET['p'] ?? 0);
    $d = (int)($_GET['d'] ?? 0);
    if ($p < 1 || $p > 5 || $d < 1 || $d > 5) {
        echo json_encode(['status' => 'error', 'message' => 'Parameter tidak valid']);
        exit;
    }
    if (hasRole('Admin', 'Pimpinan')) {
        $stmt = $db->prepare("SELECT r.id AS risiko_id, d.kode_risiko, d.nama_risiko, d.tingkat_risiko AS level_risiko, 'Teridentifikasi' AS status FROM profil_risiko_detail d JOIN profil_risiko p ON d.id_profil = p.id LEFT JOIN risiko r ON r.kode_risiko=d.kode_risiko WHERE d.probabilitas=? AND d.dampak=? ORDER BY d.nilai DESC");
        $stmt->bind_param('ii', $p, $d);
    } else {
        $uid = (int)$_SESSION['user_id'];
        $stmt = $db->prepare("SELECT r.id AS risiko_id, d.kode_risiko, d.nama_risiko, d.tingkat_risiko AS level_risiko, 'Teridentifikasi' AS status FROM profil_risiko_detail d JOIN profil_risiko p ON d.id_profil = p.id LEFT JOIN risiko r ON r.kode_risiko=d.kode_risiko WHERE d.probabilitas=? AND d.dampak=? AND p.created_by=? ORDER BY d.nilai DESC");
        $stmt->bind_param('iii', $p, $d, $uid);
    }
    $stmt->execute();
    $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo json_encode(['status' => 'success', 'data' => $res], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Endpoint internal session-based: copy_header ─────────────
// GET /api.php/copy_header/{source}/{id}
// source: profil | kkpr  →  returns header fields untuk auto-fill form
if ($isSessionAuth && $resource === 'copy_header' && $method === 'GET') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        http_response_code(419); echo json_encode(['error' => 'CSRF token invalid.']); exit;
    }
    $source = $parts[1] ?? '';
    $srcId  = (int)($parts[2] ?? 0);
    if (!in_array($source, ['profil', 'kkpr'], true) || $srcId <= 0) {
        http_response_code(400); echo json_encode(['error' => 'Param: copy_header/{profil|kkpr}/{id}']); exit;
    }
    $table = $source === 'profil' ? 'profil_risiko' : 'kkpr_header';
    $scope = hasRole('Admin', 'Pimpinan') ? '' : ' AND created_by = ?';
    $s = $db->prepare("SELECT * FROM $table WHERE id = ?$scope LIMIT 1");
    if ($scope) { $uid = (int)$_SESSION['user_id']; $s->bind_param('ii', $srcId, $uid); }
    else $s->bind_param('i', $srcId);
    $s->execute();
    $row = $s->get_result()->fetch_assoc(); $s->close();
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'Data tidak ditemukan.']); exit; }
    echo json_encode(['data' => $row], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Endpoint internal session-based: import_detail ───────────
// GET /api.php/import_detail/{id_profil}
// returns detail rows dari profil_risiko_detail untuk diimpor ke KKPR
if ($isSessionAuth && $resource === 'import_detail' && $method === 'GET') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        http_response_code(419); echo json_encode(['error' => 'CSRF token invalid.']); exit;
    }
    $profilId = (int)($parts[1] ?? 0);
    if ($profilId <= 0) {
        http_response_code(400); echo json_encode(['error' => 'ID profil required.']); exit;
    }
    $scope = hasRole('Admin', 'Pimpinan') ? '' : ' AND p.created_by = ?';
    $chk = $db->prepare("SELECT p.id FROM profil_risiko p WHERE p.id = ?$scope LIMIT 1");
    if ($scope) { $uid = (int)$_SESSION['user_id']; $chk->bind_param('ii', $profilId, $uid); }
    else $chk->bind_param('i', $profilId);
    $chk->execute();
    if (!$chk->get_result()->fetch_assoc()) { $chk->close(); http_response_code(404); echo json_encode(['error' => 'Profil tidak ditemukan.']); exit; }
    $chk->close();
    $s = $db->prepare('SELECT d.no_urut, d.kode_risiko, d.nama_risiko, d.probabilitas, d.dampak, d.bobot, d.nilai, d.tingkat_risiko, d.prioritas_risiko, d.rencana_penanganan, d.jadwal_pelaksanaan, d.penanggungjawab, d.target_p, d.target_d, d.target_bobot, d.target_nilai, d.target_tingkat_risiko, d.unit_kerja FROM profil_risiko_detail d WHERE d.id_profil = ? ORDER BY d.no_urut, d.id');
    $s->bind_param('i', $profilId);
    $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
    echo json_encode(['data' => $rows, 'count' => count($rows)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Endpoint internal session-based: list_for_copy ───────────
// GET /api.php/list_for_copy/{type}
// type: profil | kkpr → returns id,tahun,unit untuk dropdown salin header
if ($isSessionAuth && $resource === 'list_for_copy' && $method === 'GET') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        http_response_code(419); echo json_encode(['error' => 'CSRF token invalid.']); exit;
    }
    $type = $parts[1] ?? '';
    if (!in_array($type, ['profil', 'kkpr'], true)) {
        http_response_code(400); echo json_encode(['error' => 'Param: list_for_copy/{profil|kkpr}']); exit;
    }
    $table = $type === 'profil' ? 'profil_risiko' : 'kkpr_header';
    $scope = hasRole('Admin', 'Pimpinan') ? '' : ' WHERE t.created_by = ' . (int)$_SESSION['user_id'];
    $rows = $db->query("SELECT t.id, t.tahun, t.unit_pemilik_risiko, t.nama_pemilik_risiko, t.nama_pengelola_risiko, u.nama AS nama_creator, u.username AS username_creator, u.kode_prefix FROM $table t LEFT JOIN users u ON t.created_by = u.id$scope ORDER BY t.tahun DESC, t.id DESC")->fetch_all(MYSQLI_ASSOC) ?: [];
    echo json_encode(['data' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Endpoint internal session-based: ai_saran ─────────────────
// GET /api.php/ai_saran?id_risiko={id}  (CSRF via X-CSRF-Token)
// Memanggil Gemini AI dari sisi server untuk menghasilkan rekomendasi aksi
// mitigasi berdasarkan konteks risiko. API key tidak pernah terekspos ke
// browser — panggilan ke Google dilakukan PHP (cURL/stream).
if ($isSessionAuth && $resource === 'ai_saran' && $method === 'GET') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        http_response_code(419); echo json_encode(['error' => 'CSRF token invalid.']); exit;
    }
    $idRisiko = (int)($_GET['id_risiko'] ?? 0);
    if ($idRisiko <= 0) {
        http_response_code(400); echo json_encode(['error' => 'Parameter id_risiko wajib diisi.']); exit;
    }
    // Ambil konteks risiko (dengan scope kepemilikan sama seperti modul lain).
    $scope = hasRole('Admin', 'Pimpinan') ? '' : ' AND id_user_input = ?';
    $sql = 'SELECT id, kode_risiko, nama_risiko, deskripsi, penyebab, dampak,
                   level_risiko, probabilitas, dampak_level
            FROM risiko WHERE id = ?' . $scope . ' LIMIT 1';
    $stmt = $db->prepare($sql);
    if ($scope) { $uid = (int)$_SESSION['user_id']; $stmt->bind_param('ii', $idRisiko, $uid); }
    else { $stmt->bind_param('i', $idRisiko); }
    $stmt->execute();
    $risiko = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$risiko) {
        http_response_code(404); echo json_encode(['error' => 'Risiko tidak ditemukan.']); exit;
    }

    // Cek cache dulu — hindari pemanggilan Gemini berulang untuk risiko yang sama.
    $cached = getAiSaranCache($idRisiko);
    if ($cached !== null) {
        echo json_encode([
            'ok'    => true,
            'saran' => $cached['saran'],
            'model' => $cached['model'] . ' (cached)',
            'cached'=> true,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = generateAiSaran($risiko);
    if (empty($result['ok'])) {
        error_log('[manris AI fallback] ' . ($result['error'] ?? 'unknown'));
        $result = [
            'ok'    => true,
            'saran' => getSmartMitigasiFallback($risiko),
            'model' => 'MANRIS Expert Engine (Fallback)',
        ];
    }
    // Simpan ke cache lintas-user agar klik berikutnya tidak memanggil Gemini lagi.
    setAiSaranCache($idRisiko, $result['saran'] ?? [], (string)($result['model'] ?? ''));
    logAktivitas('AI_SARAN', 'risiko', $idRisiko, 'Generate saran AI mitigasi (' . ($result['model'] ?? '') . ')');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// Jika session auth dan bukan endpoint notif/internal, tetap perlu resource valid
if ($apiKey === '' && !in_array($resource, ['notif_read', 'notif_read_all', 'set_theme', 'heatmap', 'copy_header', 'import_detail', 'list_for_copy', 'ai_saran'], true)) {
    http_response_code(401);
    echo json_encode(['error' => 'API key required for data endpoints.']);
    exit;
}

if ($apiKey !== '') {
    $hashedKey = hash('sha256', $apiKey);
    $stmt = $db->prepare('SELECT a.id, a.name, a.permissions, a.is_active, a.created_by, u.role AS owner_role FROM api_keys a LEFT JOIN users u ON u.id=a.created_by WHERE a.api_key = ? LIMIT 1');
    $stmt->bind_param('s', $hashedKey);
    $stmt->execute();
    $keyRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$keyRow || (int)$keyRow['is_active'] !== 1) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or inactive API key.']);
        exit;
    }
    $used = $db->prepare('UPDATE api_keys SET last_used_at = NOW() WHERE id = ?');
    $used->bind_param('i', $keyRow['id']); $used->execute(); $used->close();
    $permissions = array_filter(explode(',', $keyRow['permissions']));
    $apiOwnerId = (int)($keyRow['created_by'] ?? 0);
    $apiIsGlobal = in_array($keyRow['owner_role'] ?? '', ['Admin', 'Pimpinan'], true);
} else {
    $permissions = [];
}
function hasPerm(array $perms, string $perm): bool {
    return in_array($perm, $perms, true);
}

try {
    switch ($resource) {
        case 'risiko':
            if (!hasPerm($permissions, 'risiko:read') && !hasPerm($permissions, 'risiko:write')) {
                http_response_code(403);
                echo json_encode(['error' => 'Permission denied: risiko:read required.']);
                exit;
            }
            if ($method === 'GET') {
                if ($idParam > 0) {
                    // Detail risiko
                    $scope = $apiIsGlobal ? '' : ' AND r.id_user_input = ?';
                    $s = $db->prepare('
                        SELECT r.*, k.nama AS kategori_nama
                        FROM risiko r
                        LEFT JOIN kategori_risiko k ON r.id_kategori = k.id
                        WHERE r.id = ?' . $scope);
                    if ($scope) $s->bind_param('ii', $idParam, $apiOwnerId); else $s->bind_param('i', $idParam);
                    $s->execute();
                    $r = $s->get_result()->fetch_assoc();
                    $s->close();
                    if (!$r) {
                        http_response_code(404);
                        echo json_encode(['error' => 'Risiko tidak ditemukan.']);
                        exit;
                    }
                    echo json_encode(['data' => $r], JSON_UNESCAPED_UNICODE);
                } else {
                    // List semua risiko (limit 100, pagination via ?page=&limit=)
                    $page = max(1, (int)($_GET['page'] ?? 1));
                    $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
                    $offset = ($page - 1) * $limit;
                    $ownerWhere = ' WHERE r.deleted_at IS NULL' . ($apiIsGlobal ? '' : ' AND r.id_user_input = ' . $apiOwnerId);
                    $rows = $db->query("
                        SELECT r.id, r.kode_risiko, r.nama_risiko, r.skor_risiko, r.level_risiko, r.status,
                               r.probabilitas, r.dampak_level, k.nama AS kategori_nama,
                               r.departemen, r.rencana_pengendalian, r.jadwal, r.tanggal_identifikasi
                         FROM risiko r
                         LEFT JOIN kategori_risiko k ON r.id_kategori = k.id
                         $ownerWhere ORDER BY r.id DESC
                        LIMIT $limit OFFSET $offset
                    ")->fetch_all(MYSQLI_ASSOC);
                    $totalQuery = 'SELECT COUNT(*) FROM risiko WHERE deleted_at IS NULL' . ($apiIsGlobal ? '' : ' AND id_user_input=' . $apiOwnerId);
                    $total = (int)$db->query($totalQuery)->fetch_column();
                    echo json_encode([
                        'data' => $rows,
                        'meta' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'total_pages' => (int)ceil($total / $limit)]
                    ], JSON_UNESCAPED_UNICODE);
                }
            } elseif ($method === 'POST') {
                if (!hasPerm($permissions, 'risiko:write')) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Permission denied: risiko:write required.']);
                    exit;
                }
                $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
                // Validasi minimal
                $required = ['kode_risiko', 'nama_risiko', 'id_kategori', 'probabilitas', 'dampak_level'];
                foreach ($required as $f) {
                    if (empty($input[$f])) {
                        http_response_code(422);
                        echo json_encode(['error' => "Field '$f' wajib diisi."]);
                        exit;
                    }
                }
                $prob = (int)$input['probabilitas'];
                $dampak = (int)$input['dampak_level'];
                if ($prob < 1 || $prob > 5 || $dampak < 1 || $dampak > 5) {
                    http_response_code(422);
                    echo json_encode(['error' => 'Probabilitas & dampak_level harus 1-5.']);
                    exit;
                }
                $skor = $prob * $dampak;
                $level = getLevelRisiko($skor);
                $stmt = $db->prepare('
                    INSERT INTO risiko (kode_risiko, nama_risiko, id_kategori, deskripsi, penyebab, dampak,
                        probabilitas, dampak_level, skor_risiko, level_risiko, pemilik_risiko, departemen,
                        rencana_pengendalian, jadwal, tanggal_identifikasi, status, id_user_input)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), "Teridentifikasi", ?)
                ');
                $idUser = (int)($keyRow['created_by'] ?? 0);
                if ($idUser <= 0) {
                    http_response_code(500);
                    echo json_encode(['error' => 'API key belum memiliki owner.']);
                    exit;
                }
                $stmt->bind_param('ssisssiiisssssi',
                    $input['kode_risiko'], $input['nama_risiko'], $input['id_kategori'],
                    $input['deskripsi'] ?? '', $input['penyebab'] ?? '', $input['dampak'] ?? '',
                    $prob, $dampak, $skor, $level,
                    $input['pemilik_risiko'] ?? '', $input['departemen'] ?? '',
                    $input['rencana_pengendalian'] ?? '', $input['jadwal'] ?? null, $idUser
                );
                    $stmt->execute();
                $newId = $db->insert_id;
                $stmt->close();
                http_response_code(201);
                echo json_encode(['data' => ['id' => $newId, 'kode_risiko' => $input['kode_risiko'], 'skor_risiko' => $skor, 'level_risiko' => $level]], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed.']);
            }
            break;

        case 'kategori':
            if (!hasPerm($permissions, 'kategori:read')) {
                http_response_code(403);
                echo json_encode(['error' => 'Permission denied: kategori:read required.']);
                exit;
            }
            $rows = $db->query('
                SELECT k.*, COUNT(r.id) AS jumlah_risiko
                FROM kategori_risiko k
                LEFT JOIN risiko r ON r.id_kategori = k.id
                GROUP BY k.id ORDER BY k.nama
            ')->fetch_all(MYSQLI_ASSOC);
            echo json_encode(['data' => $rows], JSON_UNESCAPED_UNICODE);
            break;

        case 'mitigasi':
            if (!hasPerm($permissions, 'mitigasi:read')) {
                http_response_code(403);
                echo json_encode(['error' => 'Permission denied: mitigasi:read required.']);
                exit;
            }
            if ($idParam <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'ID risiko required: /api.php/mitigasi/{id_risiko}']);
                exit;
            }
            $sql = 'SELECT m.* FROM mitigasi m';
            if (!$apiIsGlobal) $sql .= ' INNER JOIN risiko r ON r.id = m.id_risiko';
            $sql .= ' WHERE m.id_risiko = ?';
            if (!$apiIsGlobal) $sql .= ' AND r.id_user_input = ?';
            $sql .= ' ORDER BY m.id';
            $s = $db->prepare($sql);
            if ($apiIsGlobal) $s->bind_param('i', $idParam);
            else $s->bind_param('ii', $idParam, $apiOwnerId);
            $s->execute();
            $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
            $s->close();
            echo json_encode(['data' => $rows], JSON_UNESCAPED_UNICODE);
            break;

        case 'laporan':
            if (!hasPerm($permissions, 'laporan:read')) {
                http_response_code(403);
                echo json_encode(['error' => 'Permission denied: laporan:read required.']);
                exit;
            }
            $laporanScope = $apiIsGlobal ? '' : ' WHERE id_user_input = ' . $apiOwnerId;
            $stats = $db->query('
                SELECT
                  COUNT(*) AS total,
                  SUM(level_risiko = "Sangat Tinggi") AS sangat_tinggi,
                  SUM(level_risiko = "Tinggi") AS tinggi,
                  SUM(level_risiko = "Sedang") AS sedang,
                  SUM(level_risiko = "Rendah") AS rendah,
                  SUM(level_risiko = "Sangat Rendah") AS sangat_rendah,
                  SUM(status = "Teridentifikasi") AS teridentifikasi,
                  SUM(status = "Ditangani") AS ditangani,
                  SUM(status = "Dimonitor") AS dimonitor,
                  SUM(status = "Ditutup") AS ditutup,
                  AVG(skor_risiko) AS avg_skor
                FROM risiko' . $laporanScope . '
            ')->fetch_assoc();
            echo json_encode(['data' => $stats], JSON_UNESCAPED_UNICODE);
            break;

        default:
            http_response_code(404);
            echo json_encode([
                'error' => 'Resource tidak ditemukan.',
                'available' => ['risiko', 'kategori', 'mitigasi', 'laporan'],
                'docs' => 'GET /api.php/{resource}[/{id}]  Auth: X-API-Key header',
            ]);
    }
} catch (Throwable $e) {
    error_log('[manris API] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error.']);
}
