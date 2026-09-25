<?php
/**
 * FUNGSI-FUNGSI UTAMA APLIKASI
 * Helper functions untuk seluruh modul
 */

require_once __DIR__ . '/config.php';

// Polyfills for PHP 7.4 compatibility
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle !== '' && mb_strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        return $needle !== '' && substr($haystack, -strlen($needle)) === (string)$needle;
    }
}

// ─────────────────────────────────────────────────────────────
// KEAMANAN
// ─────────────────────────────────────────────────────────────

/**
 * Bersihkan output dari XSS
 */
function xss(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Encode string untuk konteks JavaScript (onclick handler, JS string literal).
 * Mencegah XSS via quote/newline injection.
 */
function jsEncode(?string $str): string {
    return json_encode($str ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}

/** Hanya izinkan tautan internal aplikasi pada data yang berasal dari database. */
function safeInternalUrl(?string $url): string {
    $url = trim((string)$url);
    if ($url === '') return '#';
    if (str_starts_with($url, APP_URL . '/') || str_starts_with($url, APP_URL . '?') || str_starts_with($url, '/')) {
        return $url;
    }
    return '#';
}

/**
 * Validasi TTD base64 PNG — pastikan benar-benar PNG image, bukan malicious payload.
 * Re-encode via GD untuk memastikan keamanan.
 *
 * @return array{valid:bool, path?:string, error?:string}
 */
function saveTtdBase64(string $base64Data, string $filename): array {
    // Validasi format prefix
    if (!preg_match('#^data:image/(png|jpeg|jpg);base64,[A-Za-z0-9+/=]+$#', $base64Data)) {
        return ['valid' => false, 'error' => 'Format tanda tangan tidak valid.'];
    }
    // Extract MIME + data
    [$meta, $data] = explode(',', $base64Data, 2);
    $decoded = base64_decode($data, true);
    if ($decoded === false || strlen($decoded) < 64) {
        return ['valid' => false, 'error' => 'Data tanda tangan corrupt.'];
    }
    // Validasi via GD imagecreate â€” pastikan benar-benar image, bukan PHP code
    $im = @imagecreatefromstring($decoded);
    if ($im === false) {
        return ['valid' => false, 'error' => 'File bukan gambar valid.'];
    }
    // Re-encode sebagai PNG (strip metadata, pastikan clean)
    $targetPath = TTD_PATH . $filename . '.png';
    if (!is_dir(TTD_PATH)) {
        @mkdir(TTD_PATH, 0755, true);
    }
    $saved = imagepng($im, $targetPath, 9);
    imagedestroy($im);
    if (!$saved) {
        return ['valid' => false, 'error' => 'Gagal menyimpan tanda tangan.'];
    }
    return ['valid' => true, 'path' => 'uploads/ttd/' . $filename . '.png'];
}

/**
 * Generate CSRF Token dan simpan di session
 */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validasi CSRF Token dari form POST
 */
function verifyCsrf(): bool {
    $token = $_POST['csrf_token'] ?? '';
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

/**
 * Field hidden CSRF Token untuk form
 */
function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . xss(csrfToken()) . '">';
}

// ── AUTENTIKASI ──────────────────────────────────────────────────

/**
 * Cek apakah user sudah login
 */
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Validasi sesi tanpa melakukan redirect. Dipakai juga oleh endpoint JSON agar
 * sesi kedaluwarsa tidak tetap dianggap sah hanya karena user_id masih ada.
 */
function hasValidSession(): bool {
    if (!isLoggedIn()) return false;

    $currentUa = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $invalidUa = isset($_SESSION['user_agent']) && !hash_equals((string)$_SESSION['user_agent'], $currentUa);
    $expired = isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > SESSION_LIFETIME;
    if ($invalidUa || $expired) {
        session_unset();
        session_destroy();
        return false;
    }
    $_SESSION['last_activity'] = time();
    return true;
}

/**
 * Redirect ke halaman login jika belum login
 */
function requireLogin(): void {
    $hadSession = isset($_SESSION['user_id']);
    if (!hasValidSession()) {
        $extra = $hadSession ? '&timeout=1' : '';
        header('Location: ' . APP_URL . '/index.php?page=login' . $extra);
        exit;
    }
}

/**
 * Cek role user
 */
function hasRole(string ...$roles): bool {
    return in_array($_SESSION['user_role'] ?? '', $roles, true);
}

/**
 * Require role tertentu, redirect jika tidak memenuhi
 */
function requireRole(string ...$roles): void {
    requireLogin();
    if (!hasRole(...$roles)) {
        setFlash('error', 'Anda tidak memiliki akses ke halaman ini.');
        header('Location: ' . APP_URL . '/index.php');
        exit;
    }
}

function requireMaintenanceAccess(): void {
    if (PHP_SAPI === 'cli') return;
    requireRole('Admin');
}

/** Admin dan pimpinan merupakan role lintas-unit; role lain hanya mengelola data miliknya. */
function canAccessAllRecords(): bool {
    return hasRole('Admin', 'Pimpinan', 'Koordinator');
}

/** Cek apakah user berhak mengakses dan mengelola fitur backup & restore (Admin, Risk Manager, Pimpinan/Kepala). */
if (!function_exists('canManageBackup')) {
    function canManageBackup(): bool {
        return hasRole('Admin', 'Risk Manager', 'Pimpinan', 'Kepala');
    }
}

/** Cek kepemilikan baris untuk tabel header yang diizinkan. */
function ownsRecord(mysqli $db, string $table, int $id, string $ownerColumn = 'created_by'): bool {
    if (canAccessAllRecords()) return true;
    $allowed = [
        'risiko' => ['id_user_input'],
        'profil_risiko' => ['created_by'],
        'kkpr_header' => ['created_by'],
        'ikk' => ['created_by'],
    ];
    if ($id <= 0 || !isset($allowed[$table]) || !in_array($ownerColumn, $allowed[$table], true)) return false;
    $stmt = $db->prepare("SELECT `$ownerColumn` AS owner_id FROM `$table` WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row && (int)$row['owner_id'] === (int)($_SESSION['user_id'] ?? 0);
}

/** Cek kepemilikan melalui header KKPR. */
function ownsKkpr(mysqli $db, int $kkprId): bool {
    return ownsRecord($db, 'kkpr_header', $kkprId);
}

/** Nilai matriks risiko harus berada pada skala resmi 1–5. */
function validRiskScale(int $value): bool {
    return $value >= 1 && $value <= 5;
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// FLASH MESSAGE
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

/**
 * Set flash message di session
 */
function setFlash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

/**
 * Tampilkan dan hapus flash message (sekarang menggunakan SweetAlert2)
 */
function showFlash(): string {
    if (!isset($_SESSION['flash'])) return '';
    $f    = $_SESSION['flash'];
    unset($_SESSION['flash']);
    
    // Konversi tipe flash ke tipe icon sweetalert
    $icon = $f['type']; // 'success', 'error', 'warning', 'info'
    if ($icon === 'danger') $icon = 'error';
    
    $title = match($icon) {
        'success' => 'Berhasil!',
        'error'   => 'Terjadi Kesalahan!',
        'warning' => 'Perhatian!',
        default   => 'Informasi'
    };
    
    return sprintf(
        "<script>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                title: %s,
                text: %s,
                icon: %s,
                confirmButtonColor: '#3b82f6',
                timer: 4000,
                confirmButtonColor: '#3b82f6',
                timer: 4000,
                timerProgressBar: true
            });
        });
        </script>",
        jsEncode($title), jsEncode((string)$f['msg']), jsEncode($icon)
    );
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// LEVEL RISIKO
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

/**
 * Hitung bobot risiko berdasarkan probabilitas dan dampak
 */
function getBobot(int $p, int $d): float {
    $matriksBobot = [
        5 => [1 => 1.5, 2 => 1.4, 3 => 1.13, 4 => 1.15, 5 => 1],
        4 => [1 => 1.2, 2 => 1.19, 3 => 1.3, 4 => 1.16, 5 => 1.2],
        3 => [1 => 1.17, 2 => 1.42, 3 => 1.43, 4 => 1.46, 5 => 1.47],
        2 => [1 => 1, 2 => 1.8, 3 => 1.83, 4 => 1.9, 5 => 2.1],
        1 => [1 => 1, 2 => 1.5, 3 => 2, 4 => 3, 5 => 4]
    ];
    return $matriksBobot[$p][$d] ?? 1.00;
}

/**
 * Hitung level risiko berdasarkan skor
 */
function getLevelRisiko(int $skor): string {
    return match(true) {
        $skor >= 20           => 'Sangat Tinggi',
        $skor >= 15           => 'Tinggi',
        $skor >= 10           => 'Sedang',
        $skor >= 5            => 'Rendah',
        default               => 'Sangat Rendah',
    };
}

/**
 * Badge HTML untuk level risiko
 */
function badgeLevel(string $level): string {
    $map = [
        'Sangat Tinggi' => 'badge-danger',
        'Tinggi'        => 'badge-orange',
        'Sedang'        => 'badge-warning',
        'Rendah'        => 'badge-success',
        'Sangat Rendah' => 'badge-info',
    ];
    $cls = $map[$level] ?? 'badge-secondary';
    return '<span class="badge ' . $cls . '">' . xss($level) . '</span>';
}

/**
 * Badge HTML untuk status risiko
 */
function badgeStatus(string $status): string {
    $map = [
        'Teridentifikasi' => 'badge-info',
        'Ditangani'       => 'badge-warning',
        'Dimonitor'       => 'badge-primary',
        'Ditutup'         => 'badge-success',
    ];
    $cls = $map[$status] ?? 'badge-secondary';
    return '<span class="badge ' . $cls . '">' . xss($status) . '</span>';
}

/**
 * Badge untuk approval status workflow
 */
function badgeApproval(string $status): string {
    $map = [
        'draft'    => 'badge-secondary',
        'pending'  => 'badge-warning',
        'approved' => 'badge-success',
        'rejected' => 'badge-danger',
    ];
    $labels = ['draft' => 'Draft', 'pending' => 'Menunggu', 'approved' => 'Disetujui', 'rejected' => 'Ditolak'];
    $cls = $map[$status] ?? 'badge-secondary';
    return '<span class="badge ' . $cls . '">' . xss($labels[$status] ?? $status) . '</span>';
}

/**
 * Cek apakah user bisa approve/reject risiko (hanya Admin & Risk Manager)
 */
function canApprove(): bool {
    return hasRole('Admin', 'Risk Manager');
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// LOG AKTIVITAS
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

/**
 * Mendapatkan IP Address sebenarnya dengan dukungan Proxy/WAF.
 * Harus berhati-hati terhadap spoofing X-Forwarded-For jika tidak di belakang proxy yang dipercaya.
 * Idealnya, ini perlu divalidasi terhadap trusted proxies list (seperti Cloudflare IPs).
 */
function getIPAddress(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $trustedProxies = array_filter(array_map('trim', explode(',', (string)env('TRUSTED_PROXIES', ''))));
    if (in_array($remote, $trustedProxies, true) && isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP']; // Spesifik Cloudflare
    }
    if (in_array($remote, $trustedProxies, true) && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ipList = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ipList[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Catat log aktivitas (Audit Trail)
 * 
 * @param string $aksi CREATE/UPDATE/DELETE/LOGIN/etc
 * @param string $modul risiko/mitigasi/kategori/user/etc
 * @param int|null $idTarget ID record yang terpengaruh
 * @param string $deskripsi Pesan deskripsi
 * @param array|null $dataLama Data sebelum perubahan (audit trail)
 * @param array|null $dataBaru Data setelah perubahan (audit trail)
 */
function logAktivitas(string $aksi, string $modul, ?int $idTarget = null, string $deskripsi = '', ?array $dataLama = null, ?array $dataBaru = null): void {
    if (!isLoggedIn()) return;
    $db        = getDB();
    $idUser    = (int)$_SESSION['user_id'];
    // Mendapatkan IP dengan mempertimbangkan proxy
    $ip        = getIPAddress();
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    // Filter data sensitif (jangan log password)
    $sensitiveKeys = ['password', 'password_hash', 'csrf_token'];
    $filterData = function(?array $data) use ($sensitiveKeys): ?string {
        if ($data === null) return null;
        foreach ($sensitiveKeys as $k) {
            if (array_key_exists($k, $data)) $data[$k] = '***REDACTED***';
        }
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    };

    $jsonLama = $filterData($dataLama);
    $jsonBaru = $filterData($dataBaru);

    $stmt = $db->prepare(
        'INSERT INTO log_aktivitas (id_user, aksi, modul, id_target, deskripsi, data_lama, data_baru, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('isssissss', $idUser, $aksi, $modul, $idTarget, $deskripsi, $jsonLama, $jsonBaru, $ip, $userAgent);
    $stmt->execute();
    $stmt->close();
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// UTILITAS
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

/**
 * Export array as Excel (.xls SpreadsheetML format â€” kompatibel Excel/LibreOffice)
 * Tanpa library external.
 *
 * @param string $filename Nama file (tanpa extension)
 * @param array $headers Array kolom header
 * @param array $rows Array associative rows
 */
function exportExcel(string $filename, array $headers, array $rows): void {
    // Bersihkan output buffer
    while (ob_get_level() > 0) ob_end_clean();

    // Headers â€” format XML Spreadsheet 2003 yang kompatibel Excel
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    header('Cache-Control: max-age=0, no-store, no-cache, must-revalidate');
    header('Pragma: public');

    // BOM UTF-8 agar Excel baca karakter khusus dengan benar
    echo "\xEF\xBB\xBF";

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet" xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";

    // Styles HARUS didefinisikan sebelum Worksheet
    echo '<Styles>' . "\n";
    echo '<Style ss:ID="head"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1F2937" ss:Pattern="Solid"/><Alignment ss:Vertical="Top" ss:Horizontal="Left"/></Style>' . "\n";
    echo '<Style ss:ID="cell"><Alignment ss:Vertical="Top" ss:WrapText="1"/></Style>
    <Style ss:ID="cellCenter"><Alignment ss:Vertical="Top" ss:Horizontal="Center" ss:WrapText="1"/></Style>
    <Style ss:ID="headCenter"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1F2937" ss:Pattern="Solid"/><Alignment ss:Vertical="Top" ss:Horizontal="Center"/></Style>
<Style ss:ID="cellCenter"><Alignment ss:Vertical="Top" ss:Horizontal="Center" ss:WrapText="1"/></Style>' . "\n";
    echo '</Styles>' . "\n";

    echo '<Worksheet ss:Name="' . htmlspecialchars($filename, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo '<Table>' . "\n";

    if (isset($GLOBALS['EXPORT_PERIODE']) && $GLOBALS['EXPORT_PERIODE']) {
        echo '<Row>' . "\n";
        echo '<Cell ss:MergeAcross="' . (count($headers)-1) . '" ss:StyleID="headCenter"><Data ss:Type="String">PERIODE LAPORAN: ' . htmlspecialchars(strtoupper($GLOBALS['EXPORT_PERIODE']), ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</Data></Cell>' . "\n";
        echo '</Row>' . "\n";
        echo '<Row></Row>' . "\n";
    }

    // Header row
    echo '<Row>' . "\n";
    foreach ($headers as $h) {
        echo '<Cell ss:StyleID="head"><Data ss:Type="String">' . htmlspecialchars((string)$h, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</Data></Cell>' . "\n";
    }
    echo '</Row>' . "\n";

    // Data rows
    foreach ($rows as $r) {
        echo '<Row>' . "\n";
        $c = 0;
        foreach ($r as $val) {
            $forceStr = false;
            if (is_array($val)) { $forceStr = true; $val = (string)($val['v'] ?? ''); }
            $val = (string)$val;
            // is it the subheader row?
            $is_sub = ($val === "1" && $c === 0 && isset($r[1]) && $r[1] === "2");
            $style = ($c === 0 || $is_sub) ? ' ss:StyleID="cellCenter"' : ' ss:StyleID="cell"';
            // Numeric? (tapi bukan kode yang diawali 0 atau terlalu panjang)
            if (!$forceStr && $val !== '' && is_numeric($val) && !str_starts_with($val, '0') && strlen($val) < 15) {
                echo '<Cell' . $style . '><Data ss:Type="Number">' . htmlspecialchars($val, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</Data></Cell>' . "\n";
            } else {
                echo '<Cell' . $style . '><Data ss:Type="String">' . htmlspecialchars($val, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</Data></Cell>' . "\n";
            }
            $c++;
        }
        echo '</Row>' . "\n";
    }

    echo '</Table>' . "\n";
    echo '</Worksheet>' . "\n";
    echo '</Workbook>' . "\n";
    exit;
}

/**
 * Format Rupiah
 */
function rupiah(float $angka): string {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

/**
 * Format tanggal Indonesia
 */
function tglIndo(string $tgl): string {
    if (empty($tgl) || $tgl === '0000-00-00') return '-';
    if (strpos($tgl, '-') === false) return $tgl;
    $bulan = ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Ags','Sep','Okt','Nov','Des'];
    $d = explode('-', substr($tgl, 0, 10));
    if(count($d) !== 3) return $tgl;
    return $d[2] . ' ' . ($bulan[(int)$d[1]] ?? '') . ' ' . $d[0];
}

/**
 * Konversi angka positif menjadi label kolom gaya Excel.
 * 1 -> A, 2 -> B, ..., 26 -> Z, 27 -> AA, 28 -> AB, ..., 53 -> BA, ...
 *
 * @param int $n Angka >= 1
 */
function numberToLetters(int $n): string {
    if ($n < 1) return '';
    $s = '';
    while ($n > 0) {
        $n -= 1;
        $s = chr(ord('A') + ($n % 26)) . $s;
        $n = intdiv($n, 26);
    }
    return $s;
}

/**
 * Assign kode_prefix otomatis (A, B, C, ..., Z, AA, AB, ...) ke user Risk Manager.
 * Cari label terkecil yg belum dipakai agar nomor tetap rapat (no gap).
 *
 * @param mysqli $db
 * @param int    $userId
 * @return string Prefix yg di-assign
 */
function assignRiskManagerPrefix($db, int $userId): string {
    $next = 1;
    while (true) {
        $cand = numberToLetters($next);
        $stmt = $db->prepare("SELECT 1 FROM users WHERE kode_prefix = ? LIMIT 1");
        $stmt->bind_param('s', $cand);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        if (!$exists) break;
        $next++;
    }
    $prefix = numberToLetters($next);
    $stmt = $db->prepare('UPDATE users SET kode_prefix = ? WHERE id = ? AND kode_prefix IS NULL');
    $stmt->bind_param('si', $prefix, $userId);
    $stmt->execute();
    $stmt->close();
    return $prefix;
}

/**
 * Generate kode risiko otomatis berdasarkan prefix user (Risk Manager).
 * - Risk Manager dgn kode_prefix (A, B, ..., AA, AB) -> kode: A.1, A.2, B.1, ...
 *   Pemisah titik tanpa leading zero; nomor terkecil yg masih kosong dipakai
 *   agar urut saat risiko draft uji dihapus.
 * - User tanpa prefix (Admin/Staff) -> fallback: RSK-001, RSK-002, ...
 *
 * @param int|null $userId ID user yg input risiko
 */
function generateKodeRisiko(?int $userId = null): string {
    $db = getDB();

    $prefix = 'RSK'; // default untuk Admin/Staff tanpa kode_prefix
    $isRiskManager = false;
    if ($userId !== null) {
        $stmt = $db->prepare('SELECT kode_prefix FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!empty($row['kode_prefix'])) {
            $prefix = $row['kode_prefix'];
            $isRiskManager = true;
        }
    }

    // Risk Manager -> format <PREFIX>.<N> (mis. A.1, A.2, B.1).
    // Lainnya (fallback RSK) -> format RSK-NNN (3 digit dgn leading zero).
    $separator = $isRiskManager ? '.' : '-';
    $like = $prefix . $separator . '%';

    // Hanya risiko AKTIF (deleted_at IS NULL) yg menempati kode.
    // Risiko nonaktif/soft-delete tidak mengunci nomor agar bisa diisi ulang.
    $stmt = $db->prepare("SELECT kode_risiko FROM risiko WHERE kode_risiko LIKE ? AND deleted_at IS NULL");
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $usedNumbers = [];
    while ($row = $res->fetch_assoc()) {
        $parts = explode($separator, $row['kode_risiko']);
        $num = isset($parts[1]) ? (int)$parts[1] : 0;
        if ($num > 0) $usedNumbers[$num] = true;
    }
    $stmt->close();

    // Kode yang sudah dipakai di profil_risiko_detail / kkpr_risiko juga
    // tidak boleh dipakai ulang (cegah tabrakan referensi dokumen).
    foreach (['profil_risiko_detail', 'kkpr_risiko'] as $tbl) {
        $refStmt = $db->prepare("SELECT kode_risiko FROM $tbl WHERE kode_risiko LIKE ?");
        $refStmt->bind_param('s', $like);
        $refStmt->execute();
        $refRes = $refStmt->get_result();
        while ($refRow = $refRes->fetch_assoc()) {
            $parts = explode($separator, $refRow['kode_risiko']);
            $num = isset($parts[1]) ? (int)$parts[1] : 0;
            if ($num > 0) $usedNumbers[$num] = true;
        }
        $refStmt->close();
    }

    // Gunakan nomor terkecil yg kosong supaya kode tetap urut saat
    // risiko draft uji yg belum dipakai dokumen dihapus.
    $next = 1;
    while (isset($usedNumbers[$next])) $next++;

    if ($isRiskManager) {
        return $prefix . '.' . $next;
    }
    return $prefix . '-' . str_pad($next, 3, '0', STR_PAD_LEFT);
}

/**
 * Normalisasi nilai "Sumber Risiko".
 * '0' / kosong adalah warisan data lama (kolom sumber dulu ENUM) → dianggap 'Internal'.
 * Dipakai lintas modul (risiko, kkpr, kkpr_pdf, laporan_konsolidasi) agar konsisten.
 */
if (!function_exists('normalizeSumberRisiko')) {
    function normalizeSumberRisiko($value): string {
        $value = trim((string)$value);
        return ($value === '' || $value === '0') ? 'Internal' : $value;
    }
}

/**
 * Rapikan uraian bertingkat (Sebab/Dampak/Penyebab): setiap butir
 * ("1.", "2)", "(1).", "- ") otomatis dimulai pada baris baru, dan baris
 * baru yang sudah ada dipertahankan.
 *
 * @param mixed  $text   Teks uraian
 * @param string $mode   'html' -> <br> (halaman web/PDF) ; 'text' -> newline (Excel/teks)
 * @param bool   $escape Escape karakter HTML/XML lebih dulu
 */
if (!function_exists('formatUraianList')) {
    function formatUraianList($text, string $mode = 'html', bool $escape = true): string {
        $text = str_replace(["\r\n", "\r"], "\n", (string)$text);
        $text = trim($text);
        if ($text === '') return '';
        if ($escape) $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        if ($mode === 'html') $text = nl2br($text);

        $br  = ($mode === 'html') ? '<br>' : "\n";
        $num = '(?:\(\d{1,2}\)\.?|\d{1,2}[.)])';

        // Sisipkan pemisah sebelum butir bernomor bila didahului tanda akhir kalimat / spasi
        $text = preg_replace('/(?<=[.;:!?])[ \t]*(?=' . $num . '[ \t])/u', $br, $text);
        $text = preg_replace('/(?<=[ \t])(?=' . $num . '[ \t])/u', $br, $text);

        // Butir tanda hubung / bullet " - " (hindari rentang angka, mis. 2020 - 2025)
        $text = preg_replace_callback('/(\S)([ \t]+)(?=[\-\x{2022}][ \t])/u', function ($m) use ($br) {
            if (ctype_digit($m[1])) return $m[0];
            return $m[1] . $br;
        }, $text);

        if ($mode === 'html') {
            $text = preg_replace('/(?:<br\s*\/?>\s*){2,}/i', '<br>', $text);
            $text = preg_replace('/^(?:<br\s*\/?>)+/i', '', $text);
        } else {
            $text = preg_replace("/\n{2,}/", "\n", $text);
            $text = preg_replace("/^\n+/", '', $text);
        }
        return trim($text);
    }
}

/**
 * Warna sel heatmap berdasarkan probabilitas & dampak
 */
function heatmapColor(int $p, int $d): string {
    $skor = (int)round($p * $d * getBobot($p, $d));
    return match(true) {
        $skor >= 20 => '#dc2626', // Sangat Tinggi
        $skor >= 15 => '#f97316', // Tinggi
        $skor >= 10 => '#FFFF00', // Sedang
        $skor >= 5  => '#22c55e', // Rendah
        default     => '#3b82f6', // Sangat Rendah
    };
}

/**
 * Upload file helper â€“ return nama file tersimpan atau false
 */
function uploadFile(array $file, string $destDir, string $prefix = ''): string|false {
    if (!isset($file['tmp_name'], $file['name'], $file['size'], $file['error']) || !is_uploaded_file($file['tmp_name'])) return false;
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    if ($file['size'] > MAX_FILE_SIZE)    return false;
    
    // Validasi MIME Type secara harfiah (bukan dari ekstensi nama)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime, ALLOWED_TYPES, true)) return false;

    // Pastikan ekstensi juga aman
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
    if (!in_array($ext, $allowed_exts, true)) return false;
    if (in_array($mime, ['image/jpeg','image/png','image/gif'], true) && @getimagesize($file['tmp_name']) === false) return false;

    $filename = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest     = rtrim($destDir, '/') . '/' . $filename;

    if (!is_dir($destDir) && !@mkdir($destDir, 0750, true)) return false;
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        @chmod($dest, 0640);
        return $filename;
    }
    return false;
}

/**
 * Saran mitigasi otomatis berdasarkan kata kunci deskripsi
 */
function saranMitigasi(string $deskripsi, string $penyebab = ''): array {
    $teks = strtolower(trim($deskripsi . ' ' . $penyebab));

    // Tambahkan konteks semantik untuk istilah yang umum dipakai di unit kerja.
    // Contoh: "salah akun ketika membukukan" adalah risiko keuangan,
    // walaupun kalimatnya juga menyebut aplikasi/sistem.
    $financeContext = preg_match('/\b(bendahara|salah\s+akun|akun\s+(belanja|keuangan)|membukukan|pembukuan|jurnal|kode\s+rekening|spj|sakti|transaksi\s+(keuangan|belanja)|anggaran|kas|fraud)\b/i', $teks);
    if ($financeContext) $teks .= ' keuangan akuntansi pembukuan transaksi anggaran kontrol internal';
    $sampleContext = preg_match('/\b(sampel|sample|spesimen|laboratorium|lab|pemeriksaan|pengujian|uji\s+(laboratorium|sampel)|hasil\s+uji|reagen)\b/i', $teks);
    if ($sampleContext) $teks .= ' sampel spesimen laboratorium pemeriksaan pengujian mutu kapasitas';

    $saran = [];

    // Prioritas 1: Baca dari database (admin bisa edit/tambah via UI)
    try {
        $db = getDB();
        $res = $db->query("SELECT kategori, keyword, saran FROM saran_mitigasi WHERE is_active = 1 ORDER BY kategori, id");
        if ($res && $res->num_rows > 0) {
            $rules = [];
            while ($row = $res->fetch_assoc()) {
                $category = strtolower(trim((string)$row['kategori']));
                $keywordParts = preg_split('/\s*\|\s*/', strtolower((string)$row['keyword'])) ?: [];
                $score = 0;
                foreach ($keywordParts as $keyword) {
                    $keyword = trim($keyword);
                    if ($keyword !== '' && strpos($teks, $keyword) !== false) $score += max(1, min(10, strlen($keyword)));
                }
                if ($financeContext && preg_match('/keuang|akunt|finance|bendahara|anggaran/', $category)) $score += 20;
                if ($financeContext && preg_match('/it|keamanan|teknologi|sistem/', $category) && !preg_match('/keuang|akunt|finance|bendahara|anggaran/', $category)) $score -= 15;
                if ($sampleContext && preg_match('/labor|sampel|spesimen|mutu|kesehatan|operasional/', $category)) $score += 20;
                if ($sampleContext && preg_match('/keuang|akunt|finance|bendahara|anggaran/', $category)) $score -= 15;
                if ($score > 0) {
                    if (!isset($rules[$category])) $rules[$category] = ['score' => 0, 'items' => []];
                    $rules[$category]['score'] += $score;
                    $rules[$category]['items'][] = $row['saran'];
                }
            }
            if ($rules) {
                uasort($rules, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
                $bestScore = reset($rules)['score'];
                foreach ($rules as $rule) {
                    // Ambil hanya kategori terbaik agar saran IT tidak tercampur
                    // dengan saran keuangan pada risiko salah pembukuan.
                    if ($rule['score'] < $bestScore) break;
                    $saran = array_merge($saran, $rule['items']);
                }
            }
        }
    } catch (Throwable $e) {
        // Fallback ke hardcoded jika tabel belum ada
    }

    // Prioritas 2: Fallback hardcoded (jika DB kosong/belum ada tabel)
    if (empty($saran)) {
        $rules = [
            ['it|server|sistem|cyber|siber|hack|malware|data',
             ['Lakukan audit keamanan sistem secara berkala', 'Implementasi firewall dan antivirus mutakhir',
              'Backup data otomatis setiap hari ke offsite storage', 'Terapkan multi-factor authentication']],
            ['keuangan|likuiditas|anggaran|budget|kas|fraud|curang',
             ['Perkuat sistem kontrol internal keuangan', 'Audit internal rutin setiap kuartal',
              'Implementasi four-eyes principle untuk transaksi besar', 'Review anggaran bulanan secara ketat']],
            ['sdm|karyawan|turnover|pegawai|rekrut',
             ['Review dan tingkatkan paket kompensasi & benefit', 'Program employee engagement dan career path',
              'Implementasi mentoring dan coaching program', 'Exit interview untuk memahami penyebab turnover']],
            ['regulasi|hukum|kepatuhan|compliance|sanksi|denda',
             ['Monitoring regulasi terbaru secara proaktif', 'Penunjukan compliance officer dedicated',
              'Training kepatuhan regulasi untuk seluruh staf', 'Audit kepatuhan regulasi semi-tahunan']],
            ['reputasi|brand|citra|media|publik',
             ['Kembangkan crisis communication plan', 'Monitoring media sosial dan sentiment analisis',
              'Bangun hubungan baik dengan stakeholder kunci', 'Evaluasi standar kualitas produk/layanan']],
            ['supply|pasok|vendor|pemasok|supplier',
             ['Diversifikasi supplier untuk produk kritis', 'Evaluasi supplier secara berkala',
              'Bangun stok buffer untuk komponen kritis', 'Perjanjian SLA yang ketat dengan pemasok']],
        ];

        $matchedRules = [];
        foreach ($rules as [$pattern, $suggestions]) {
            $score = 0;
            foreach (preg_split('/\|/', $pattern) ?: [] as $keyword) {
                $keyword = trim($keyword);
                if ($keyword !== '' && strpos($teks, strtolower($keyword)) !== false) $score += strlen($keyword);
            }
            if ($score > 0) $matchedRules[] = ['score' => $score, 'suggestions' => $suggestions];
        }
        if ($matchedRules) {
            usort($matchedRules, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
            $saran = $matchedRules[0]['suggestions'];
        }
    }

    // Default fallback jika tidak ada keyword cocok
    if (empty($saran)) {
        $saran = [
            'Lakukan analisis mendalam terhadap akar penyebab risiko',
            'Kembangkan rencana kontinjensi untuk skenario terburuk',
            'Tetapkan PIC (Person In Charge) yang bertanggung jawab',
            'Monitor indikator risiko secara berkala',
        ];
    }

    // Saran domain laboratorium dipakai sebagai pelengkap agar selalu ada
    // empat pilihan yang relevan untuk risiko sampel/spesimen.
    if ($sampleContext) {
        $sampleSuggestions = [
            'Verifikasi metode dan prosedur pemeriksaan sampel sesuai standar laboratorium',
            'Tingkatkan pemantauan mutu dan lakukan evaluasi penyebab sampel tidak memenuhi target',
            'Pastikan ketersediaan reagen, peralatan, dan kapasitas petugas pemeriksa',
            'Lakukan monitoring capaian pemeriksaan sampel secara berkala dan tindak lanjuti deviasi',
        ];
        // Domain laboratorium harus mengalahkan kecocokan umum seperti
        // kata "target" yang bisa memicu kategori keuangan.
        $saran = array_merge($sampleSuggestions, $saran);
    }

    return array_slice(array_values(array_unique(array_filter(array_map('trim', $saran)))), 0, 4);
}

/**
 * Parse teks respons Gemini menjadi daftar saran mitigasi.
 * Fungsi murni (tanpa side-effect) agar mudah di-unit-test.
 *
 * Mendukung beberapa format keluaran model:
 *  - Array JSON murni:           ["a","b","c"]
 *  - JSON berbalut ```json … ```: ```json\n["a","b"]\n```
 *  - JSON dengan teks pembungkus: Berikut: ["a","b"] semoga membantu.
 *  - Daftar per baris (fallback): 1. a\n2. b  atau  - a\n- b
 *
 * @return string[] Daftar saran (bisa kosong bila tidak terparse).
 */
function parseAiSaranText(string $text): array {
    $text = trim($text);
    if ($text === '') return [];
    $start = strpos($text, '[');
    $end   = strrpos($text, ']');
    if ($start === false || $end === false || $end <= $start) {
        return parseAiSaranLines($text);
    }
    $jsonStr = substr($text, $start, $end - $start + 1);
    $decoded = json_decode($jsonStr, true);
    $saran = is_array($decoded) ? array_map('strval', $decoded) : [];
    if (!$saran) $saran = parseAiSaranLines($text);
    return normalizeAiSaran($saran);
}

/** Helper: pecah teks multiline menjadi daftar saran (fallback non-JSON). */
function parseAiSaranLines(string $text): array {
    $lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text)));
    $saran = [];
    foreach ($lines as $ln) {
        $ln = preg_replace('/^\s*[-*\d.)\]]+\s*/', '', $ln);
        $ln = trim(trim($ln, "\"'"));
        // Lewati baris kosong & baris pembuka (umumnya diakhiri ":").
        if ($ln === '' || str_ends_with($ln, ':')) continue;
        $saran[] = $ln;
    }
    return normalizeAiSaran($saran);
}

/** Helper: trim + buang duplikat + buang item kosong, pertahankan urutan. */
function normalizeAiSaran(array $saran): array {
    $out = [];
    $seen = [];
    foreach ($saran as $s) {
        $s = trim((string)$s);
        if ($s === '' || isset($seen[$s])) continue;
        $seen[$s] = true;
        $out[] = $s;
    }
    return $out;
}

/**
 * Transport HTTP nyata untuk memanggil Gemini API.
 *
 * @return array{resp:?string, err:?string, code:int}
 */
function aiSaranHttpPost(string $url, string $body): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $curlOpts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=UTF-8', 'Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if (!ini_get('curl.cainfo') && file_exists('C:/xampp/php/extras/ssl/cacert.pem')) {
            $curlOpts[CURLOPT_CAINFO] = 'C:/xampp/php/extras/ssl/cacert.pem';
        }
        curl_setopt_array($ch, $curlOpts);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }
        if ($resp === false || $cerr) {
            return ['resp' => null, 'err' => $cerr ?: 'curl_exec gagal', 'code' => $code];
        }
        return ['resp' => (string)$resp, 'err' => null, 'code' => $code];
    }
    if (ini_get('allow_url_fopen')) {
        $ctxOpts = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json; charset=UTF-8\r\nAccept: application/json\r\n",
                'content' => $body,
                'timeout' => 30,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ];
        $resp = @file_get_contents($url, false, stream_context_create($ctxOpts));
        if ($resp === false) {
            return ['resp' => null, 'err' => 'file_get_contents gagal (network/SSL)', 'code' => 0];
        }
        return ['resp' => (string)$resp, 'err' => null, 'code' => 200];
    }
    return ['resp' => null, 'err' => 'Tidak ada transport HTTP (cURL/allow_url_fopen) yang tersedia.', 'code' => 0];
}

/**
 * Generate saran mitigasi via Gemini AI (Google Generative Language API).
 *
 * Panggilan dilakukan dari sisi server (backend) supaya API key tidak terekspos
 * ke browser dan tetap patuh CSP `connect-src 'self'` yang dipasang di .htaccess.
 *
 * Konfigurasi via .env:
 *   GEMINI_API_KEY            — wajib. API key dari Google AI Studio (aistudio.google.com).
 *   GEMINI_MODEL              — opsional. Default 'gemini-1.5-flash'.
 *   GEMINI_MAX_REQ_USER_HOUR  — opsional. Batas request per user per jam. Default 20.
 *
 * @param array       $ctx       Konteks risiko: nama_risiko, kode_risiko, deskripsi,
 *                               penyebab, dampak, level_risiko, probabilitas, dampak_level.
 * @param callable|null $transport callable(string $url, string $body): array{resp:?string, err:?string, code:int}
 *                               Injeksi transport mock untuk testing. Bila null, pakai aiSaranHttpPost().
 * @return array{ok:bool, saran?:string[], error?:string, model?:string}
 */
function generateAiSaran(array $ctx, ?callable $transport = null): array {
    $apiKey = env('GEMINI_API_KEY', '');
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'GEMINI_API_KEY belum dikonfigurasi. Hubungi administrator.'];
    }

    // Rate limit sederhana per-user per-jam (session-based, tanpa tabel baru).
    // Hanya berlaku saat ada session aktif (web). Dilewati di CLI/test.
    $maxPerHour = (int)env('GEMINI_MAX_REQ_USER_HOUR', '20');
    $maxPerHour = $maxPerHour > 0 ? $maxPerHour : 20;
    $now = time();
    $sessionActive = (session_status() === PHP_SESSION_ACTIVE);
    if ($sessionActive) {
        if (!isset($_SESSION['ai_saran_log']) || !is_array($_SESSION['ai_saran_log'])) {
            $_SESSION['ai_saran_log'] = [];
        }
        $_SESSION['ai_saran_log'] = array_values(
            array_filter($_SESSION['ai_saran_log'], static fn(int $t): bool => ($now - $t) < 3600)
        );
        if (count($_SESSION['ai_saran_log']) >= $maxPerHour) {
            return ['ok' => false, 'error' => 'Batas request AI tercapai (' . $maxPerHour . '/jam). Coba lagi nanti.'];
        }
    }

    $model = env('GEMINI_MODEL', 'gemini-1.5-flash');
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . rawurlencode($model) . ':generateContent?key=' . $apiKey;

    // Rakit konteks risiko (field kosong tetap aman diteruskan ke prompt).
    $fields = [
        'Kode Risiko'         => trim((string)($ctx['kode_risiko'] ?? '')),
        'Nama Risiko'          => trim((string)($ctx['nama_risiko'] ?? '')),
        'Deskripsi/Peristiwa' => trim((string)($ctx['deskripsi'] ?? '')),
        'Sebab/Penyebab'      => trim((string)($ctx['penyebab'] ?? '')),
        'Dampak'               => trim((string)($ctx['dampak'] ?? '')),
        'Tingkat Risiko'      => trim((string)($ctx['level_risiko'] ?? '')),
        'Probabilitas'        => trim((string)($ctx['probabilitas'] ?? '')) . '/5',
        'Dampak Level'        => trim((string)($ctx['dampak_level'] ?? '')) . '/5',
    ];
    $konteks = '';
    foreach ($fields as $k => $v) {
        if ($v !== '' && $v !== '/5') $konteks .= "- {$k}: {$v}\n";
    }
    if ($konteks === '') {
        return ['ok' => false, 'error' => 'Konteks risiko kosong. Lengkapi deskripsi risiko terlebih dahulu.'];
    }

    $prompt = "TUGAS:\nSebagai ahli manajemen risiko sektor publik, berikan 4 hingga 5 saran mitigasi (rencana pengendalian) berdasarkan Konteks Risiko di bawah.\n\n"
        . "ATURAN WAJIB:\n"
        . "1. Seluruh jawaban WAJIB dalam BAHASA INDONESIA yang baku dan formal.\n"
        . "2. Saran mitigasi harus konkret, spesifik, dan dapat dilaksanakan (1-2 kalimat saja per saran).\n"
        . "3. Jawab HANYA berupa poin-poin daftar menggunakan angka (1, 2, 3, 4, 5).\n"
        . "4. JANGAN gunakan format JSON. JANGAN berikan teks pengantar atau penutup sama sekali.\n\n"
        . "KONTEKS RISIKO:\n" . $konteks;

    $payload = [
        'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
        'generationConfig' => [
            'temperature'     => 0.4,
            'maxOutputTokens' => 1000,
            'topP'            => 0.9,
        ],
    ];
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

    // Transport: pakai yang diinjeksi (test) atau transport nyata.
    $http = $transport !== null ? $transport($url, $body) : aiSaranHttpPost($url, $body);
    $resp = $http['resp'] ?? null;
    $err  = $http['err'] ?? null;
    $code = (int)($http['code'] ?? 0);

    if ($resp === null || $err !== null) {
        return ['ok' => false, 'error' => 'Gagal menghubungi layanan AI: ' . $err, 'model' => $model];
    }
    if ($code >= 400) {
        $ed = json_decode((string)$resp, true);
        $emsg = $ed['error']['message'] ?? ('HTTP ' . $code);
        return ['ok' => false, 'error' => 'Layanan AI menolak request: ' . $emsg, 'model' => $model];
    }

    $data = json_decode((string)$resp, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!is_string($text) || trim($text) === '') {
        $blockReason = $data['promptFeedback']['blockReason'] ?? null;
        $blockMsg = $data['candidates'][0]['finishReason'] ?? null;
        $msg = $blockReason ?: ($blockMsg ?: 'Respons AI kosong.');
        return ['ok' => false, 'error' => 'AI tidak menghasilkan saran: ' . $msg, 'model' => $model];
    }

    $saran = parseAiSaranText($text);
    if (!$saran) {
        return ['ok' => false, 'error' => 'Gagal mem-parsing saran dari respons AI.', 'model' => $model];
    }

    // Catat pemakaian untuk rate limit (setelah sukses parse, hanya saat session aktif).
    if ($sessionActive) {
        $_SESSION['ai_saran_log'][] = $now;
    }

    return ['ok' => true, 'saran' => array_slice($saran, 0, 6), 'model' => $model];
}

/**
 * Fallback cerdas berbasis domain pengetahuan manajemen risiko (MANRIS Expert Engine).
 * Menghasilkan tepat 5 saran mitigasi konkret dan relevan jika API Gemini offline atau tidak tersedia.
 *
 * @param array $ctx Konteks risiko (nama_risiko, deskripsi, penyebab, dampak, dll.)
 * @return string[] 5 butir saran mitigasi aplikatif
 */
function getSmartMitigasiFallback(array $ctx): array {
    $text = mb_strtolower(implode(' ', [
        $ctx['nama_risiko'] ?? '',
        $ctx['deskripsi'] ?? '',
        $ctx['penyebab'] ?? '',
        $ctx['dampak'] ?? '',
        $ctx['nama_kegiatan'] ?? '',
    ]));

    // 1. Absensi / Presensi / Kepegawaian / Disiplin / Tukin
    if (str_contains($text, 'absen') || str_contains($text, 'presensi') || str_contains($text, 'kehadiran') || str_contains($text, 'pulang') || str_contains($text, 'tukin') || str_contains($text, 'disiplin')) {
        return [
            'Penerapan sistem notifikasi pengingat otomatis (Auto-Reminder) via broadcast WhatsApp resmi atau mobile app pada jam 07.15 WIB dan 15.45 WIB.',
            'Penambahan dan optimalisasi mesin absensi biometrik (fingerprint & face recognition) di setiap lobby gedung utama untuk mengurai antrean.',
            'Penetapan SOP dispensasi lupa absensi dengan formulir keterangan terverifikasi atasan langsung maksimal 2 kali per semester.',
            'Rekonsiliasi data kehadiran harian oleh pengelola kepegawaian setiap pukul 09.00 WIB untuk verifikasi dini pegawai yang belum tercatat.',
            'Sosialisasi dan edukasi berkala terkait PP 94/2021 tentang Disiplin PNS dan transparansi skema pemotongan tunjangan kinerja akibat kelalaian presensi.',
        ];
    }

    // 2. Gajiweb / SIMPEG / SAKTI / Belanja Pegawai / Keuangan
    if (str_contains($text, 'gajiweb') || str_contains($text, 'gaji') || str_contains($text, 'sakti') || str_contains($text, 'simpeg') || str_contains($text, 'salah akun') || str_contains($text, 'tunjangan')) {
        return [
            'Pembuatan SOP verifikasi pencocokan silang (cross-matching) antara data Gajiweb, SIMPEG, dan SK fisik sebelum diimpor ke aplikasi SAKTI.',
            'Pemberlakuan validasi berjenjang (Maker-Checker-Approver) oleh PPK dan Kasubbag ADUM sebelum pengajuan SPM belanja pegawai.',
            'Pelaksanaan rekonsiliasi data tunjangan dan belanja pegawai bersama KPPN secara bulanan sebelum penerbitan SP2D gaji.',
            'Pelatihan penyegaran (refreshment course) operator aplikasi Gajiweb dan modul komitmen SAKTI secara berkala.',
            'Pembuatan lembar kendali checklist mutasi pegawai perorangan untuk mendeteksi kenaikan pangkat/fungsional otomatis.',
        ];
    }

    // 3. PNBP / Target Pendapatan / Tarif Layanan
    if (str_contains($text, 'pnbp') || str_contains($text, 'pendapatan') || str_contains($text, 'tarif') || str_contains($text, 'deviasi')) {
        return [
            'Analisis data historis penerimaan sampel 3 tahun terakhir sebagai dasar perumusan target PNBP yang realistis dan kredibel.',
            'Rapat monitoring dan evaluasi bulanan realisasi penerimaan pengujian sampel bersama seluruh koordinator tim laboratorium.',
            'Pengajuan revisi target PNBP pada revisi DIPA tengah tahun (Triwulan III) jika terjadi anomali atau fluktuasi permintaan pasar.',
            'Optimalisasi promosi layanan pengujian lingkungan ke sektor swasta/industri untuk mendongkrak penerimaan tarif uji.',
            'Penerapan sistem pembayaran non-tunai terintegrasi SIMPONI untuk percepatan pencatatan setoran PNBP ke kas negara.',
        ];
    }

    // 4. Sampel Uji / Target Pemeriksaan / TAT / Keterlambatan Layanan
    if (str_contains($text, 'sampel') || str_contains($text, 'pemeriksaan') || str_contains($text, 'tat') || str_contains($text, 'sertifikat') || str_contains($text, 'hasil uji')) {
        return [
            'Pemetaan kapasitas harian peralatan uji dan pemerataan rotasi beban kerja analis laboratorium (workload balancing).',
            'Menjalin kerja sama rujukan pengujian sampel kualitas lingkungan dengan Dinas Kesehatan dan balai laboratorium jejaring.',
            'Pemeliharaan rutin instrumen uji utama (AAS, GC, Spektrofotometer) untuk mencegah downtime pengujian saat hari kerja.',
            'Percepatan penerbitan hasil uji dengan digitalisasi LIMS/SIMLAB dan penerapan Tanda Tangan Elektronik (TTE) tersertifikasi.',
            'Pengaktifan sistem pemantauan early warning otomatis di dashboard saat sisa waktu pengerjaan sampel mendekati batas TAT.',
        ];
    }

    // 5. Reagen / Bahan Habis Pakai / Logistik Lab
    if (str_contains($text, 'reagen') || str_contains($text, 'bahan kimia') || str_contains($text, 'logistik') || str_contains($text, 'stok')) {
        return [
            'Menetapkan batas aman persediaan (buffer stock) reagen kritis minimal sebesar 2 bulan kebutuhan operasional pengujian.',
            'Mempercepat proses pemesanan e-katalog pada triwulan sebelumnya dengan klausul SLA pengiriman vendor maksimal 14 hari kalender.',
            'Mencantumkan klausul denda penalti keterlambatan dan jaminan penggantian darurat dalam kontrak kerja sama penyedia.',
            'Mengaktifkan sistem notifikasi stok minimum otomatis pada SIMLAB/inventaris saat sisa reagen mendekati 20%.',
            'Menjalin kesepakatan peminjaman bahan antar-laboratorium (inter-lab buffer network) dengan balai laboratorium terdekat.',
        ];
    }

    // 6. Alat / Instrumen / Kalibrasi / Kerusakan Mesin
    if (str_contains($text, 'alat') || str_contains($text, 'instrumen') || str_contains($text, 'kalibrasi') || str_contains($text, 'mesin') || str_contains($text, 'rusak')) {
        return [
            'Menerapkan jadwal pemeliharaan berkala (preventive maintenance) mingguan dan bulanan oleh teknisi internal bersertifikat.',
            'Memasang stabilizer industri dan sistem catu daya bebas gangguan (UPS online) serta genset otomatis di ruang instrumen.',
            'Melakukan re-kalibrasi tahunan terakreditasi KAN serta uji verifikasi harian menggunakan Bahan Acuan Bersertifikat (CRM).',
            'Mengikat kontrak servis berkala tahunan (annual maintenance contract) resmi dengan agen tunggal pemegang merk alat.',
            'Menyiapkan instrumen uji cadangan (backup instrument) dan SOP rujukan uji darurat ke lab mitra terakreditasi.',
        ];
    }

    // 7. K3 / Keselamatan / B3 / Paparan Kimia / Kecelakaan Kerja
    if (str_contains($text, 'k3') || str_contains($text, 'keselamatan') || str_contains($text, 'paparan') || str_contains($text, 'b3') || str_contains($text, 'kecelakaan') || str_contains($text, 'cedera')) {
        return [
            'Mengganti bahan kimia karsinogenik/berbahaya dengan senyawa alternatif ramah lingkungan (green chemistry) yang setara.',
            'Memastikan sertifikasi berkala kecepatan aliran hisap lemari asam (fume hood) dan Biosafety Cabinet (BSC) terpasang HEPA filter.',
            'Mewajibkan pelatihan K3 lab, sertifikasi penanganan B3, dan pembaruan berkala Lembar Data Keselamatan Bahan (MSDS).',
            'Menyediakan APD wajib terstandardisasi (respirator uap organik, sarung tangan nitril tebal tahan kimia, goggle, lab coat).',
            'Menyediakan eyewash, emergency shower, dan chemical spill kit di setiap lorong lab serta simulasi evakuasi tiap semester.',
        ];
    }

    // 8. IPAL / Limbah / Lingkungan
    if (str_contains($text, 'ipal') || str_contains($text, 'limbah') || str_contains($text, 'pencemaran')) {
        return [
            'Pemisahan ketat limbah kimia cair B3 di sumber pengujian menggunakan jeriken khusus bersandi warna sesuai karakteristik.',
            'Menguji parameter baku mutu influen dan efluen IPAL (pH, COD, TSS, logam berat) secara harian sebelum dialirkan ke badan air.',
            'Mengikat kerja sama resmi dengan transporter dan pengolah akhir limbah B3 yang memiliki izin aktif KLHK dengan manifest Festronik.',
            'Memasang sensor alarm digital otomatis pada bak penampung limbah untuk mendeteksi kenaikan volume berlebih (overflow) dini.',
            'Menyediakan tangki retensi cadangan darurat dan stok netralisator kimia asam/basa instan saat terjadi malfungsi bakteri IPAL.',
        ];
    }

    // 9. Siber / Server / IT / Jaringan / Keamanan
    if (str_contains($text, 'siber') || str_contains($text, 'server') || str_contains($text, 'jaringan') || str_contains($text, 'it') || str_contains($text, 'kebocoran data') || str_contains($text, 'down')) {
        return [
            'Mengaktifkan replikasi cadangan database harian terenkripsi ke server offsite/cloud terpisah.',
            'Mewajibkan autentikasi ganda (MFA), pergantian password periodik, dan pembatasan hak akses berbasis peran (RBAC).',
            'Memasang Web Application Firewall (WAF), antivirus endpoint terpusat, dan pembaruan patch keamanan sistem secara rutin.',
            'Melakukan pemindaian kerentanan (vulnerability assessment) dan uji penetrasi berkala setiap semester oleh tim IT.',
            'Menyiapkan Disaster Recovery Plan (DRP) dan server cadangan (failover) dengan target pemulihan (RTO) di bawah 2 jam.',
        ];
    }

    // 10. Fallback Umum 5 Hierarki Pengendalian
    return [
        'Tindakan Preventif pada Akar Masalah: Mengeliminasi sumber penyebab utama risiko sebelum peristiwa kegagalan terjadi.',
        'Rekayasa Teknis & Otomasi Sistem: Memasang mekanisme proteksi otomatis, alarm peringatan dini, atau perbaikan peralatan pendukung.',
        'Pengendalian Administratif & SOP: Memperketat instruksi kerja terstandar, validasi berjenjang, dan rotasi personel pelaksana.',
        'Tindakan Protektif & Proteksi Fisik: Menyediakan proteksi pengamanan fisik dan pengawasan mutu berlapis terhadap aset dan personel.',
        'Rencana Kontinjensi & Pemulihan Layanan: Menyiapkan SOP darurat dan sumber daya cadangan agar operasional lekas pulih jika risiko terjadi.',
    ];
}

// ── Cache saran AI per risiko (DB, lintas user, TTL-based) ─────
// Hindari pemanggilan berulang ke Gemini untuk risiko yang sama. Cache disimpan
// di tabel ai_saran_cache dan berlaku selama GEMINI_CACHE_TTL detik (default 24h).
// Otomatis di-invalidate ketika risiko di-update/dihapus (lihat modules/risiko.php).

/**
 * Ambil saran AI dari cache bila masih segar.
 *
 * @return array|null ['saran'=>string[],'model'=>string] atau null bila tidak ada/kadaluarsa.
 */
function getAiSaranCache(int $idRisiko): ?array {
    if ($idRisiko <= 0) return null;
    $ttl = (int)env('GEMINI_CACHE_TTL', (string)(24 * 3600));
    if ($ttl <= 0) return null; // caching dinonaktifkan
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT saran_json, model FROM ai_saran_cache WHERE id_risiko = ? AND generated_at >= (NOW() - INTERVAL ? SECOND) LIMIT 1');
        $stmt->bind_param('ii', $idRisiko, $ttl);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return null;
        $arr = json_decode((string)$row['saran_json'], true);
        if (!is_array($arr)) return null;
        return ['saran' => array_map('strval', $arr), 'model' => (string)$row['model']];
    } catch (Throwable $e) {
        return null; // tabel belum ada / error → anggap miss
    }
}

/**
 * Simpan saran AI ke cache (upsert).
 */
function setAiSaranCache(int $idRisiko, array $saran, string $model): void {
    if ($idRisiko <= 0 || !$saran) return;
    $json = json_encode(array_values($saran), JSON_UNESCAPED_UNICODE);
    try {
        $db = getDB();
        $stmt = $db->prepare('INSERT INTO ai_saran_cache (id_risiko, saran_json, model, generated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE saran_json=VALUES(saran_json), model=VALUES(model), generated_at=NOW()');
        $stmt->bind_param('iss', $idRisiko, $json, $model);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // Cache gagal tidak boleh memutus alur utama.
    }
}

/**
 * Hapus cache saran AI untuk risiko tertentu. Dipanggil saat risiko di-edit/dihapus.
 */
function invalidateAiSaranCache(int $idRisiko): void {
    if ($idRisiko <= 0) return;
    try {
        $db = getDB();
        $stmt = $db->prepare('DELETE FROM ai_saran_cache WHERE id_risiko = ?');
        $stmt->bind_param('i', $idRisiko);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // Tabel belum ada / error → diam-diam lewati.
    }
}

/**
 * Pagination helper
 */
function paginate(int $total, int $perPage, int $currentPage, string $baseUrl): array {
    $totalPages = (int)ceil($total / $perPage);
    return [
        'total'       => $total,
        'per_page'    => $perPage,
        'current'     => $currentPage,
        'total_pages' => $totalPages,
        'offset'      => ($currentPage - 1) * $perPage,
        'base_url'    => $baseUrl,
    ];
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// NOTIFIKASI & EMAIL
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

/**
 * Buat notifikasi in-app + kirim email jika SMTP terkonfigurasi.
 *
 * @param int $userId Target user ID
 * @param string $tipe risiko_baru|mitigasi_deadline|approval|status_change
 * @param string $judul Judul notifikasi
 * @param string $pesan Isi pesan
 * @param string|null $link URL tujuan (relatif)
 */
function notifikasi(int $userId, string $tipe, string $judul, string $pesan, ?string $link = null): void {
    $db = getDB();
    // Hindari notifikasi identik berulang pada hari yang sama, terutama saat
    // cron dijalankan lebih dari sekali.
    $duplicate = $db->prepare('SELECT id FROM notifikasi WHERE id_user=? AND tipe=? AND link <=> ? AND created_at >= CURDATE() LIMIT 1');
    if ($duplicate) {
        $duplicate->bind_param('iss', $userId, $tipe, $link); $duplicate->execute();
        $alreadySent = $duplicate->get_result()->fetch_assoc(); $duplicate->close();
        if ($alreadySent) return;
    }
    $stmt = $db->prepare('INSERT INTO notifikasi (id_user, tipe, judul, pesan, link) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('issss', $userId, $tipe, $judul, $pesan, $link);
    $stmt->execute();
    $stmt->close();

    // Forward notifikasi ke Telegram & WhatsApp jika terkonfigurasi
    try {
        $teleToken  = env('TELEGRAM_BOT_TOKEN', '');
        $teleChatId = env('TELEGRAM_DEFAULT_CHAT_ID', '');
        $waToken    = env('WA_GATEWAY_TOKEN', '');
        $waNumber   = env('WA_DEFAULT_NUMBER', '');

        if ((!empty($teleToken) && !empty($teleChatId)) || (!empty($waToken) && !empty($waNumber))) {
            $iconMap = [
                'mitigasi_deadline'    => '⏰',
                'risiko_baru'          => '⚠️',
                'approval'             => '📋',
                'status_change'        => '🔄',
                'konsolidasi_approved' => '✅',
            ];
            $icon = $iconMap[$tipe] ?? '🔔';
            $cleanTitle = trim($judul);
            $cleanMsg   = trim($pesan);
            $botMsg = "{$icon} *[MANRIS NOTIFIKASI]*\n\n*{$cleanTitle}*\n{$cleanMsg}";
            if ($link) {
                $fullLink = str_starts_with($link, 'http') ? $link : rtrim(APP_URL, '/') . '/' . ltrim($link, '/');
                $botMsg .= "\n\n🔗 [Buka Aplikasi]({$fullLink})";
            }

            if (!empty($teleToken) && !empty($teleChatId)) {
                kirimTelegram($teleChatId, $botMsg, 'Markdown');
            }

            if (!empty($waToken) && !empty($waNumber)) {
                $waMsg = str_replace(['[Buka Aplikasi](', ')'], ['', ''], $botMsg);
                kirimWhatsApp($waNumber, $waMsg);
            }
        }
    } catch (\Throwable $e) {
        error_log('[manris] Bot dispatch error: ' . $e->getMessage());
    }

    // Kirim email jika SMTP terkonfigurasi
    $smtpUser = getenv('SMTP_USER') ?: '';
    if ($smtpUser === '') return; // SMTP tidak dikonfigurasi, skip email

    // Ambil email user
    $u = $db->prepare('SELECT email, nama FROM users WHERE id = ? LIMIT 1');
    $u->bind_param('i', $userId);
    $u->execute();
    $user = $u->get_result()->fetch_assoc();
    $u->close();
    if (!$user || empty($user['email'])) return;

    kirimEmail($user['email'], $user['nama'], $judul, $pesan);
    // Tandai email_sent
    $notifId = $db->insert_id;
    $db->query("UPDATE notifikasi SET email_sent = 1 WHERE id = " . (int)$notifId);
}

/**
 * Kirim pesan via Telegram Bot API
 *
 * @param string $chatId ID Chat atau Channel/Group (misal: '123456789' atau '-100xxxxxxx')
 * @param string $pesan Isi pesan
 * @param string $parseMode 'Markdown' | 'HTML'
 * @return array{ok: bool, error?: string, response?: mixed}
 */
function kirimTelegram(string $chatId, string $pesan, string $parseMode = 'Markdown'): array {
    $token = env('TELEGRAM_BOT_TOKEN', '');
    if (empty($token) || empty($chatId)) {
        return ['ok' => false, 'error' => 'TELEGRAM_BOT_TOKEN atau Chat ID belum dikonfigurasi.'];
    }

    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $payload = [
        'chat_id' => $chatId,
        'text' => $pesan,
        'parse_mode' => $parseMode,
        'disable_web_page_preview' => true,
    ];

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'Ekstensi PHP cURL tidak aktif.'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErr) {
        return ['ok' => false, 'error' => 'cURL Error: ' . $curlErr];
    }

    $result = json_decode((string)$resp, true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($result['ok'])) {
        return ['ok' => true, 'response' => $result];
    }

    $errDesc = $result['description'] ?? ("HTTP " . $httpCode);
    return ['ok' => false, 'error' => $errDesc, 'response' => $result];
}

/**
 * Kirim pesan WhatsApp via Gateway (mendukung Fonnte, Wablas, atau Generic Webhook)
 *
 * @param string $nomor Nomor HP tujuan (format 628xxx atau 08xxx)
 * @param string $pesan Isi pesan
 * @return array{ok: bool, error?: string, response?: mixed}
 */
function kirimWhatsApp(string $nomor, string $pesan): array {
    $gatewayUrl = env('WA_GATEWAY_URL', 'https://api.fonnte.com/send');
    $token      = env('WA_GATEWAY_TOKEN', '');

    if (empty($token) || empty($nomor)) {
        return ['ok' => false, 'error' => 'WA_GATEWAY_TOKEN atau Nomor tujuan belum dikonfigurasi.'];
    }

    $cleanNomor = preg_replace('/[^0-9]/', '', $nomor);
    if (str_starts_with($cleanNomor, '08')) {
        $cleanNomor = '62' . substr($cleanNomor, 1);
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'Ekstensi PHP cURL tidak aktif.'];
    }

    $headers = ['Authorization: ' . $token];
    if (str_contains($gatewayUrl, 'fonnte.com')) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $body = http_build_query(['target' => $cleanNomor, 'message' => $pesan]);
    } elseif (str_contains($gatewayUrl, 'wablas.com')) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $body = http_build_query(['phone' => $cleanNomor, 'message' => $pesan]);
    } else {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode([
            'phone'   => $cleanNomor,
            'target'  => $cleanNomor,
            'message' => $pesan,
        ], JSON_UNESCAPED_UNICODE);
    }

    $ch = curl_init($gatewayUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErr) {
        return ['ok' => false, 'error' => 'cURL Error: ' . $curlErr];
    }

    $result = json_decode((string)$resp, true);
    if ($httpCode >= 200 && $httpCode < 300) {
        return ['ok' => true, 'response' => $result];
    }

    $errMsg = is_array($result) ? ($result['reason'] ?? $result['message'] ?? ("HTTP " . $httpCode)) : ("HTTP " . $httpCode);
    return ['ok' => false, 'error' => $errMsg, 'response' => $result];
}

/**
 * Kirim email via PHPMailer (jika ada) atau mail() native.
 * Untuk produksi: install PHPMailer via composer untuk SMTP yang andal.
 */
function kirimEmail(string $to, string $toName, string $subject, string $body): bool {
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . (getenv('SMTP_FROM_NAME') ?: 'Sistem Manajemen Risiko') . ' <' . (getenv('SMTP_USER') ?: 'no-reply@localhost') . '>',
    ];

    $htmlBody = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px">
        <div style="background:#1F2937;color:white;padding:16px;border-radius:8px 8px 0 0">
            <h2 style="margin:0;font-size:18px">' . htmlspecialchars($subject) . '</h2>
        </div>
        <div style="background:#F9FAFB;padding:16px;border:1px solid #E5E7EB;border-top:none;border-radius:0 0 8px 8px">
            <p style="color:#374151;line-height:1.6">' . nl2br(htmlspecialchars($body)) . '</p>
            <hr style="border:none;border-top:1px solid #E5E7EB;margin:16px 0">
            <p style="color:#9CA3AF;font-size:12px">Email ini dikirim otomatis oleh Sistem Manajemen Risiko.</p>
        </div>
    </body></html>';

    // Jika PHPMailer tersedia, pakai SMTP
    if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
            $mail->Port = (int)(getenv('SMTP_PORT') ?: 587);
            $mail->SMTPAuth = true;
            $mail->Username = getenv('SMTP_USER');
            $mail->Password = getenv('SMTP_PASS');
            $mail->SMTPSecure = getenv('SMTP_SECURE') ?: 'tls';
            $mail->setFrom(getenv('SMTP_USER'), getenv('SMTP_FROM_NAME') ?: 'Manris');
            $mail->addAddress($to, $toName);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->isHTML(true);
            $mail->send();
            return true;
        } catch (\Throwable $e) {
            error_log('[manris] Email failed (PHPMailer): ' . $e->getMessage());
            return false;
        }
    }

    // Fallback: mail() native (sering di-blok di shared hosting)
    return @mail($to, $subject, $htmlBody, implode("\r\n", $headers));
}

/**
 * Ambil notifikasi untuk user (terbaru, belum dibaca)
 */
function getNotifikasi(int $userId, int $limit = 10): array {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM notifikasi WHERE id_user = ? ORDER BY is_read ASC, created_at DESC LIMIT ?');
    $stmt->bind_param('ii', $userId, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Hitung notifikasi belum dibaca
 * (cached per-request agar query tidak dobel saat dipanggil
 *  di header dan di modul pada request yang sama)
 */
function countUnreadNotif(int $userId): int {
    static $cache = [];
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }
    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM notifikasi WHERE id_user = ? AND is_read = 0');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_column();
    $stmt->close();
    return $count;
}

// ─────────────────────────────────────────────────────────────
// VALIDASI TERPUSAT (Rekomendasi #2)
// ─────────────────────────────────────────────────────────────

/**
 * Validasi nilai skala risiko (1-5) untuk probabilitas dan dampak.
 * Sudah ada alias validRiskScale(), ini versi array untuk batch validation.
 *
 * @param array $fields Associative array nama => nilai
 * @return array ['valid' => bool, 'errors' => string[]]
 */
function validateRiskScales(array $fields): array {
    $errors = [];
    foreach ($fields as $name => $value) {
        $v = (int)$value;
        if ($v < 1 || $v > 5) {
            $errors[] = "Nilai '$name' harus antara 1 sampai 5 (diterima: $value).";
        }
    }
    return ['valid' => empty($errors), 'errors' => $errors];
}

/**
 * Validasi format tanggal YYYY-MM-DD.
 *
 * @param string|null $date
 * @return bool
 */
function validateDate(?string $date): bool {
    if ($date === null || $date === '') return false;
    $d = \DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

/**
 * Validasi tanggal opsional — null/kosong dianggap valid.
 */
function validateDateOptional(?string $date): bool {
    if ($date === null || $date === '') return true;
    return validateDate($date);
}

/**
 * Validasi nilai enum terhadap daftar yang diizinkan.
 *
 * @param string $value Nilai yang disubmit
 * @param array  $allowed Daftar nilai yang valid
 * @return bool
 */
function validateEnum(string $value, array $allowed): bool {
    return in_array($value, $allowed, true);
}

/**
 * Konstanta enum yang dipakai di seluruh aplikasi.
 * Digunakan bersama validateEnum() untuk konsistensi.
 */
const STATUS_RISIKO   = ['Teridentifikasi', 'Ditangani', 'Dimonitor', 'Ditutup'];
const SUMBER_RISIKO   = ['Internal', 'Eksternal'];
const STATUS_MITIGASI = ['Belum Mulai', 'Dalam Proses', 'Selesai', 'Dibatalkan'];
const STATUS_APPROVAL = ['draft', 'pending', 'approved', 'rejected'];

/**
 * Validasi bobot risiko — harus > 0 dan <= 10 (batas matriks maksimum).
 *
 * @param float $bobot
 * @return bool
 */
function validateBobot(float $bobot): bool {
    return $bobot > 0 && $bobot <= 10.0;
}

/**
 * Sanitasi string input: trim + strip null bytes.
 * Gunakan sebelum menyimpan ke database.
 *
 * @param string|null $input
 * @param int $maxLen Batas panjang karakter (0 = tidak dibatasi)
 * @return string
 */
function sanitizeStr(?string $input, int $maxLen = 0): string {
    $s = trim(str_replace("\0", '', (string)($input ?? '')));
    if ($maxLen > 0 && mb_strlen($s) > $maxLen) {
        $s = mb_substr($s, 0, $maxLen);
    }
    return $s;
}

/**
 * Kumpulkan semua error validasi dan kembalikan sebagai string tunggal.
 * Cocok untuk setFlash('error', collectErrors($errors)).
 *
 * @param array $errors
 * @return string
 */
function collectErrors(array $errors): string {
    return implode(' ', array_filter($errors));
}

if (!function_exists('getDefaultPengelola')) {
    function getDefaultPengelola() {
        $username = strtolower($_SESSION['user_username'] ?? '');
        $map = [
            'adum' => 'Kepala Subbagian Administrasi Umum',
            'timker1' => 'Katimker 1',
            'timker2' => 'Katimker 2',
            'timker3' => 'Katimker 3',
            'instalasi' => 'Koordinator Instalasi',
            'upg' => 'Unit Pengendali Gratifikasi'
        ];
        return $map[$username] ?? '';
    }
}

/**
 * Hitung jumlah dokumen yang menunggu persetujuan Pimpinan
 * (Profil Risiko + KKPR + KKPMR). Dipakai untuk badge nav "Persetujuan".
 */
function countPendingApprovals(): int {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $db = getDB();
        $row = $db->query("
            SELECT
              (SELECT COUNT(*) FROM profil_risiko WHERE status='Menunggu Persetujuan') +
              (SELECT COUNT(*) FROM kkpr_header WHERE status_kkpr='Menunggu Persetujuan') +
              (SELECT COUNT(*) FROM kkpr_header WHERE status_kkpmr='Menunggu Persetujuan') AS total
        ")->fetch_assoc();
        $cache = (int)($row['total'] ?? 0);
        return $cache;
    } catch (Throwable $e) {
        return 0;
    }
}

// ─────────────────────────────────────────────────────────────
// APPROVAL WORKFLOW (Pengajuan ke Pimpinan)
// ─────────────────────────────────────────────────────────────

/**
 * Tabel yang mendukung approval workflow beserta kolom owner-nya.
 */
const APPROVAL_TABLES = [
    'profil_risiko' => 'created_by',
    'kkpr_header'   => 'created_by',
    'ikk'           => 'created_by',
];

/**
 * Ambil label badge untuk approval_status.
 */
function badgeApprovalStatus(string $status): string {
    $map = [
        'draft'    => ['label' => 'Draft',              'class' => 'badge-secondary'],
        'pending'  => ['label' => 'Menunggu Persetujuan','class' => 'badge-warning'],
        'approved' => ['label' => 'Disetujui',           'class' => 'badge-success'],
        'rejected' => ['label' => 'Ditolak',             'class' => 'badge-danger'],
    ];
    $info = $map[$status] ?? ['label' => ucfirst($status), 'class' => 'badge-secondary'];
    return '<span class="badge ' . $info['class'] . '">' . $info['label'] . '</span>';
}

/**
 * Ajukan dokumen ke Pimpinan (ubah status ke 'pending').
 * Hanya pemilik dokumen atau Admin yang boleh mengajukan.
 *
 * @param mysqli $db
 * @param string $table  profil_risiko | kkpr_header | ikk
 * @param int    $id
 * @return array ['ok' => bool, 'error' => string]
 */
function ajukanKePimpinan(mysqli $db, string $table, int $id): array {
    if (!isset(APPROVAL_TABLES[$table])) {
        return ['ok' => false, 'error' => 'Tabel tidak dikenali.'];
    }
    // Cek kepemilikan
    $ownerCol = APPROVAL_TABLES[$table];
    if (!canAccessAllRecords()) {
        $chk = $db->prepare("SELECT `$ownerCol`, approval_status FROM `$table` WHERE id=? LIMIT 1");
        $chk->bind_param('i', $id); $chk->execute();
        $row = $chk->get_result()->fetch_assoc(); $chk->close();
        if (!$row) return ['ok' => false, 'error' => 'Dokumen tidak ditemukan.'];
        if ((int)$row[$ownerCol] !== (int)($_SESSION['user_id'] ?? 0)) {
            return ['ok' => false, 'error' => 'Anda tidak memiliki hak untuk mengajukan dokumen ini.'];
        }
        if ($row['approval_status'] === 'pending') {
            return ['ok' => false, 'error' => 'Dokumen sudah diajukan, menunggu persetujuan Pimpinan.'];
        }
        if ($row['approval_status'] === 'approved') {
            return ['ok' => false, 'error' => 'Dokumen sudah disetujui Pimpinan.'];
        }
    }
    $upd = $db->prepare("UPDATE `$table` SET approval_status='pending', approval_note=NULL WHERE id=?");
    $upd->bind_param('i', $id); $upd->execute(); $upd->close();

    // Kirim notifikasi ke semua Pimpinan
    $pimpinanList = $db->query("SELECT id FROM users WHERE role='Pimpinan' AND aktif=1")->fetch_all(MYSQLI_ASSOC) ?: [];
    $tableLabel = ['profil_risiko'=>'Profil Risiko','kkpr_header'=>'KKPR','ikk'=>'IKK'][$table] ?? $table;
    $pageMap    = ['profil_risiko'=>'profil_risiko','kkpr_header'=>'kkpr','ikk'=>'ikk'];
    $link       = '/?page=' . ($pageMap[$table] ?? $table) . '&id=' . $id;
    foreach ($pimpinanList as $p) {
        notifikasi(
            (int)$p['id'],
            'approval',
            "Dokumen $tableLabel menunggu persetujuan",
            'Risk Manager telah mengajukan dokumen ' . $tableLabel . ' untuk ditinjau.',
            $link
        );
    }
    logAktivitas('SUBMIT', $table, $id, 'Ajukan ke Pimpinan: ' . $tableLabel . ' ID ' . $id);
    return ['ok' => true];
}

/**
 * Setujui dokumen oleh Pimpinan.
 */
function setujuiDokumen(mysqli $db, string $table, int $id): array {
    if (!hasRole('Admin', 'Pimpinan')) {
        return ['ok' => false, 'error' => 'Hanya Pimpinan yang dapat menyetujui dokumen.'];
    }
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $upd = $db->prepare("UPDATE `$table` SET approval_status='approved', approval_by=?, approval_at=NOW(), approval_note=NULL WHERE id=?");
    $upd->bind_param('ii', $uid, $id); $upd->execute(); $upd->close();

    // Notifikasi ke pemilik dokumen
    $ownerCol = APPROVAL_TABLES[$table] ?? 'created_by';
    $row = $db->prepare("SELECT `$ownerCol` AS owner_id FROM `$table` WHERE id=? LIMIT 1");
    $row->bind_param('i', $id); $row->execute();
    $r = $row->get_result()->fetch_assoc(); $row->close();
    $tableLabel = ['profil_risiko'=>'Profil Risiko','kkpr_header'=>'KKPR','ikk'=>'IKK'][$table] ?? $table;
    $pageMap    = ['profil_risiko'=>'profil_risiko','kkpr_header'=>'kkpr','ikk'=>'ikk'];
    if ($r && (int)$r['owner_id']) {
        notifikasi((int)$r['owner_id'], 'approval',
            "Dokumen $tableLabel Disetujui",
            'Pimpinan telah menyetujui dokumen ' . $tableLabel . ' Anda.',
            '/?page=' . ($pageMap[$table] ?? $table) . '&id=' . $id
        );
    }
    logAktivitas('APPROVE', $table, $id, 'Setujui dokumen: ' . $tableLabel . ' ID ' . $id);
    return ['ok' => true];
}

/**
 * Tolak dokumen oleh Pimpinan dengan catatan.
 */
function tolakDokumen(mysqli $db, string $table, int $id, string $catatan): array {
    if (!hasRole('Admin', 'Pimpinan')) {
        return ['ok' => false, 'error' => 'Hanya Pimpinan yang dapat menolak dokumen.'];
    }
    if (empty(trim($catatan))) {
        return ['ok' => false, 'error' => 'Catatan penolakan wajib diisi.'];
    }
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $upd = $db->prepare("UPDATE `$table` SET approval_status='rejected', approval_by=?, approval_at=NOW(), approval_note=? WHERE id=?");
    $upd->bind_param('isi', $uid, $catatan, $id); $upd->execute(); $upd->close();

    // Notifikasi ke pemilik dokumen
    $ownerCol = APPROVAL_TABLES[$table] ?? 'created_by';
    $row = $db->prepare("SELECT `$ownerCol` AS owner_id FROM `$table` WHERE id=? LIMIT 1");
    $row->bind_param('i', $id); $row->execute();
    $r = $row->get_result()->fetch_assoc(); $row->close();
    $tableLabel = ['profil_risiko'=>'Profil Risiko','kkpr_header'=>'KKPR','ikk'=>'IKK'][$table] ?? $table;
    $pageMap    = ['profil_risiko'=>'profil_risiko','kkpr_header'=>'kkpr','ikk'=>'ikk'];
    if ($r && (int)$r['owner_id']) {
        notifikasi((int)$r['owner_id'], 'approval',
            "Dokumen $tableLabel Ditolak",
            'Pimpinan menolak dokumen ' . $tableLabel . ' Anda. Catatan: ' . $catatan,
            '/?page=' . ($pageMap[$table] ?? $table) . '&id=' . $id
        );
    }
    logAktivitas('REJECT', $table, $id, 'Tolak dokumen: ' . $tableLabel . ' ID ' . $id . ' — ' . $catatan);
    return ['ok' => true];
}

// =============================================================
// LANDING PAGE FUNCTIONS
// =============================================================

/**
 * Ambil IP address klien, support proxy dan Cloudflare.
 */
function getLandingClientIP(): string {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($headers as $h) {
        $ip = $_SERVER[$h] ?? '';
        if ($ip === '') continue;
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Rate limiter berbasis file temp (tanpa DB) — aman untuk halaman publik.
 * Return true = masih dalam batas, false = sudah melebihi batas.
 */
function checkRateLimit(string $key, int $max = 5, int $windowSec = 60): bool {
    $ip   = getLandingClientIP();
    $hash = substr(hash_hmac('sha256', $ip . ':' . $key, APP_KEY), 0, 32);
    $file = sys_get_temp_dir() . '/manris_rl_' . $hash . '.json';

    $now  = time();
    $data = ['count' => 0, 'window_start' => $now];

    if (is_file($file) && is_readable($file)) {
        $cached = json_decode(file_get_contents($file), true);
        if ($cached && isset($cached['window_start']) && ($now - $cached['window_start']) < $windowSec) {
            $data = $cached;
        }
    }

    $data['count']++;
    if ($data['count'] > $max) {
        return false;
    }
    @file_put_contents($file, json_encode($data), LOCK_EX);
    return true;
}

/**
 * Log pelanggaran rate limit untuk audit.
 */
function logRateLimitViolation(string $key, string $context = ''): void {
    $ip = getLandingClientIP();
    error_log(sprintf('[manris] Rate limit exceeded: key=%s ip=%s ctx=%s', $key, $ip, $context));
}

/**
 * Ambil cache statistik dari tabel landing_statistics_cache.
 * Return null jika tabel belum ada atau cache expired (> 5 menit).
 */
function getLandingStatsCache(): ?array {
    try {
        $db  = getDB();
        $res = $db->query("SELECT stat_key, stat_value, updated_at FROM landing_statistics_cache");
        if (!$res || $res->num_rows === 0) return null;

        $stats      = [];
        $oldestTime = PHP_INT_MAX;
        while ($row = $res->fetch_assoc()) {
            $stats[$row['stat_key']] = (int)$row['stat_value'];
            $t = strtotime((string)$row['updated_at']);
            if ($t && $t < $oldestTime) $oldestTime = $t;
        }
        // Cache dianggap segar jika diupdate < 5 menit lalu
        if ($oldestTime !== PHP_INT_MAX && (time() - $oldestTime) < 300) {
            return $stats;
        }
        return null; // cache expired
    } catch (Throwable $e) {
        error_log('[manris] getLandingStatsCache: ' . $e->getMessage());
        return null;
    }
}

/**
 * Simpan statistik ke cache tabel.
 */
function updateLandingStatsCache(array $stats): void {
    try {
        $db   = getDB();
        $stmt = $db->prepare("INSERT INTO landing_statistics_cache (stat_key, stat_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE stat_value = VALUES(stat_value), updated_at = CURRENT_TIMESTAMP");
        foreach ($stats as $key => $value) {
            $v = (int)$value;
            $stmt->bind_param('si', $key, $v);
            $stmt->execute();
        }
        $stmt->close();
    } catch (Throwable $e) {
        error_log('[manris] updateLandingStatsCache: ' . $e->getMessage());
    }
}

/**
 * Hitung statistik live dari database dan simpan ke cache.
 */
function getLandingStatistics(): array {
    // Coba ambil dari cache dulu, skip jika semua nilai 0 (belum terisi)
    $cached = getLandingStatsCache();
    $cacheValid = $cached !== null && array_sum($cached) > 0;
    if ($cacheValid) {
        return $cached;
    }

    $db    = getDB();
    $stats = ['total_risiko' => 0, 'total_mitigasi' => 0, 'total_pengguna' => 0, 'total_unit_kerja' => 0];

    try {
        // Total risiko
        $r = $db->query("SELECT COUNT(*) FROM risiko");
        if ($r) $stats['total_risiko'] = (int)$r->fetch_row()[0];

        // Total mitigasi
        $r = $db->query("SELECT COUNT(*) FROM mitigasi");
        if ($r) $stats['total_mitigasi'] = (int)$r->fetch_row()[0];

        // Total pengguna aktif
        $r = $db->query("SELECT COUNT(*) FROM users WHERE aktif = 1");
        if ($r) $stats['total_pengguna'] = (int)$r->fetch_row()[0];

        // Total unit kerja unik
        $r = $db->query("SELECT COUNT(*) FROM users WHERE aktif = 1 AND role = 'Risk Manager'");
        if ($r) $stats['total_unit_kerja'] = (int)$r->fetch_row()[0];

        updateLandingStatsCache($stats);
    } catch (Throwable $e) {
        error_log('[manris] getLandingStatistics: ' . $e->getMessage());
    }

    return $stats;
}

/**
 * Versi aman getLandingStatistics — tidak pernah throw exception.
 */
function getLandingStatisticsSafe(): array {
    try {
        return getLandingStatistics();
    } catch (Throwable $e) {
        error_log('[manris] getLandingStatisticsSafe fallback: ' . $e->getMessage());
        return ['total_risiko' => 0, 'total_mitigasi' => 0, 'total_pengguna' => 0, 'total_unit_kerja' => 0];
    }
}

/**
 * Validasi input form kontak landing page.
 */
function validateContactForm(array $data): array {
    $errors = [];
    $name   = trim($data['name']    ?? '');
    $email  = trim($data['email']   ?? '');
    $msg    = trim($data['message'] ?? '');

    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
        $errors[] = 'Nama harus antara 2-100 karakter.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format email tidak valid.';
    }
    if (mb_strlen($msg) < 10 || mb_strlen($msg) > 2000) {
        $errors[] = 'Pesan harus antara 10-2000 karakter.';
    }
    return ['valid' => empty($errors), 'errors' => $errors];
}

/**
 * Simpan submission form kontak ke database.
 */
function saveContactSubmission(array $data): bool {
    try {
        $db   = getDB();
        $stmt = $db->prepare("INSERT INTO contact_submissions (name, email, subject, message, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
        if (!$stmt) return false;
        $name    = trim($data['name']);
        $email   = trim($data['email']);
        $subject = trim($data['subject'] ?? '');
        $msg     = trim($data['message']);
        $ip      = getLandingClientIP();
        $ua      = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $stmt->bind_param('ssssss', $name, $email, $subject, $msg, $ip, $ua);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    } catch (Throwable $e) {
        error_log('[manris] saveContactSubmission: ' . $e->getMessage());
        return false;
    }
}

/**
 * Mengambil daftar tahun unik untuk dropdown filter/pilihan tahun di seluruh modul.
 * - Selalu menyertakan tahun berjalan (date('Y'), misal 2026, 2027 dsb).
 * - Menggabungkan seluruh DISTINCT tahun yang ada di database pada tabel terkait.
 * - Menyertakan tahun aktif yang sedang dipilih ($extraYears) jika valid.
 * - Otomatis deduplikasi dan disortir secara descending (tahun terbaru di paling atas).
 *
 * @param mysqli|null $db Koneksi database
 * @param string|null $table Nama tabel utama ('profil_risiko', 'kkpr_header', 'ikk'), atau null untuk semua
 * @param array $extraYears Tahun tambahan yang ingin disertakan
 * @return string[] Daftar tahun unik (e.g. ['2026'] atau ['2027', '2026', '2025'])
 */
function getDaftarTahun(?mysqli $db = null, ?string $table = null, array $extraYears = []): array {
    if (!$db) {
        $db = getDB();
    }
    $years = [];

    // 1. Tahun saat ini (e.g. 2026, 2027)
    $currentYear = (string)date('Y');
    if ($currentYear !== '' && preg_match('/^\d{4}$/', $currentYear)) {
        $years[] = $currentYear;
    }

    // 2. Tahun tambahan (misal tahun dari GET atau active document)
    foreach ($extraYears as $ey) {
        $ey = trim((string)$ey);
        if ($ey !== '' && preg_match('/^\d{4}$/', $ey)) {
            $years[] = $ey;
        }
    }

    // 3. Ambil DISTINCT tahun dari database
    $tablesToQuery = [];
    if ($table && in_array($table, ['profil_risiko', 'kkpr_header', 'ikk', 'master_indikator_kegiatan'], true)) {
        $tablesToQuery[] = $table;
    } else {
        $tablesToQuery = ['profil_risiko', 'kkpr_header', 'ikk'];
    }

    foreach ($tablesToQuery as $tbl) {
        try {
            $res = $db->query("SELECT DISTINCT tahun FROM {$tbl} WHERE tahun IS NOT NULL AND tahun != ''");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $val = trim((string)$r['tahun']);
                    if ($val !== '' && preg_match('/^\d{4}$/', $val)) {
                        $years[] = $val;
                    }
                }
            }
        } catch (Throwable $e) {
            // fail-safe ignore
        }
    }

    // 4. Deduplikasi dan urutkan secara descending
    $years = array_values(array_unique($years));
    rsort($years, SORT_NUMERIC);

    // Fallback jika kosong
    if (empty($years)) {
        $years = [$currentYear ?: '2026'];
    }

    return $years;
}
