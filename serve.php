<?php
/**
 * SERVE.PHP — Endpoint Terotentikasi untuk Mengunduh File
 * Menjembatani akses ke folder uploads yang diblokir oleh .htaccess
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

// Wajib login untuk akses file apapun
requireLogin();

$file = $_GET['f'] ?? '';
$type = $_GET['t'] ?? 'bukti'; // 'bukti' atau 'ttd'

if (empty($file)) {
    http_response_code(400);
    die('File tidak ditentukan.');
}

// Validasi path traversal
if (!in_array($type, ['bukti', 'ttd'], true) || !preg_match('/^[a-zA-Z0-9_\-\.]+$/', $file)) {
    http_response_code(400);
    die('Nama file tidak valid.');
}

// ── Cek kepemilikan file (cegah IDOR / broken access control) ──
// Admin/Pimpinan/Koordinator boleh akses semua; user lain hanya file miliknya.
if (!canAccessAllRecords()) {
    $db  = getDB();
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $allowed = false;

    if ($type === 'bukti') {
        $chk = $db->prepare('SELECT r.id_user_input FROM mitigasi m JOIN risiko r ON r.id = m.id_risiko WHERE m.bukti_file = ? LIMIT 1');
        $chk->bind_param('s', $file);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        $chk->close();
        $allowed = $row && (int)$row['id_user_input'] === $uid;
    } else { // ttd: bisa milik mitigasi atau profil_risiko
        $chk = $db->prepare('SELECT r.id_user_input FROM mitigasi m JOIN risiko r ON r.id = m.id_risiko WHERE m.ttd_file = ? LIMIT 1');
        $chk->bind_param('s', $file);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        $chk->close();
        if ($row && (int)$row['id_user_input'] === $uid) {
            $allowed = true;
        } else {
            // profil_risiko menyimpan path lengkap (uploads/ttd/xxx.png)
            $like = '%/' . $file;
            $chk2 = $db->prepare('SELECT created_by FROM profil_risiko WHERE ttd_pemilik LIKE ? OR ttd_pengelola LIKE ? LIMIT 1');
            $chk2->bind_param('ss', $like, $like);
            $chk2->execute();
            $row2 = $chk2->get_result()->fetch_assoc();
            $chk2->close();
            $allowed = $row2 && (int)$row2['created_by'] === $uid;
        }
    }

    if (!$allowed) {
        http_response_code(403);
        error_log('[manris] serve.php IDOR blocked: user ' . $uid . ' attempted file ' . $file);
        die('Akses ditolak.');
    }
}

$basePath = ($type === 'ttd') ? TTD_PATH : BUKTI_PATH;
$filePath = $basePath . $file;

if (!file_exists($filePath)) {
    http_response_code(404);
    die('File tidak ditemukan.');
}

$mime = mime_content_type($filePath);
if (!$mime) $mime = 'application/octet-stream';

// Tampilkan secara aman
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($filePath));
// "inline" mencoba menampilkannya di browser jika bisa (PDF/Gambar), "attachment" memaksa download
header('Content-Disposition: ' . ($mime === 'application/pdf' ? 'attachment' : 'inline') . '; filename="' . basename($file) . '"');
header('Cache-Control: private, max-age=3600'); // Cache di browser user tersebut

readfile($filePath);
exit;
