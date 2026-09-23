<?php
/**
 * KONFIGURASI UTAMA APLIKASI
 * Sistem Manajemen Risiko Premium
 *
 * Semua kredensial dimuat dari environment variable / .env file.
 * Default development hanya untuk XAMPP lokal — JANGAN deploy apa adanya.
 */

// ── Load .env file (minimal parser, no vendor) ────────────────
// Lokasi .env yang aman berada DI LUAR webroot (mis. C:\xampp\manrisv2_config\.env)
// agar tidak pernah bisa diunduh via web berapa pun konfigurasi servernya.
// Path di dalam webroot masih didukung sebagai fallback untuk lingkungan dev.
if (!defined('ENV_LOADED')) {
    // Direktori config di luar webroot mengikuti nama folder aplikasi,
    // mis. C:\xampp\htdocs\manrisv3 → C:\xampp\manrisv3_config\.env
    // sehingga instalasi manrisv2 dan manrisv3 tidak saling menimpa.
    $appFolder     = basename(dirname(__DIR__));
    $configDir     = dirname(__DIR__, 3) . '/' . $appFolder . '_config';
    $envCandidates = array_filter([
        getenv('MANRIS_ENV_FILE') ?: null,               // override eksplisit (produksi)
        $configDir . '/.env',                            // luar webroot per-aplikasi (dev)
        __DIR__ . '/../.env',                            // fallback dev (webroot)
    ]);
    $envFile = null;
    foreach ($envCandidates as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            $envFile = $candidate;
            break;
        }
    }
    if ($envFile !== null) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k); $v = trim($v);
            if (strlen($v) >= 2 && (($v[0] === '"' && $v[-1] === '"') || ($v[0] === "'" && $v[-1] === "'"))) {
                $v = substr($v, 1, -1);
            }
            if (!array_key_exists($k, $_ENV) && getenv($k) === false) {
                $_ENV[$k] = $v; putenv("$k=$v");
            }
        }
    }
    define('ENV_LOADED', true);
}
function env(string $key, $default = null) {
    $v = $_ENV[$key] ?? getenv($key);
    return ($v === false || $v === null || $v === '') ? $default : $v;
}

// ── Error Reporting ───────────────────────────────────────────
define('APP_ENV', env('APP_ENV', 'production')); // default production-safe
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}

// ── Informasi Aplikasi ────────────────────────────────────────
define('APP_NAME',    'Sistem Informasi Manajemen Risiko');
define('APP_SHORT',   'Semar');
define('APP_VERSION', '1.0.0');
// APP_KEY wajib unik per instalasi. Fallback statis membuat signature backup dapat dipalsukan.
$appKey = env('APP_KEY');
if (!is_string($appKey) || strlen($appKey) < 32) {
    http_response_code(500);
    error_log('[manris] APP_KEY is missing or too short.');
    exit('Konfigurasi aplikasi belum lengkap. Hubungi administrator.');
}
define('APP_KEY', $appKey); // Kunci untuk HMAC / enkripsi
define('APP_URL',     env('APP_URL', 'http://localhost/manrisv2')); // Wajib statis di production

// ── Konfigurasi Database ──────────────────────────────────────
define('DB_HOST',    env('DB_HOST', 'localhost'));
define('DB_USER',    env('DB_USER', 'root'));
define('DB_PASS',    env('DB_PASS', ''));
define('DB_NAME',    env('DB_NAME', 'manris_db'));
define('DB_PORT',    (int) env('DB_PORT', 3306));
define('DB_CHARSET', 'utf8mb4');

// ── Path Direktori ────────────────────────────────────────────
define('BASE_PATH',       dirname(__DIR__));
define('UPLOAD_PATH',     BASE_PATH . '/uploads/');
define('BUKTI_PATH',      UPLOAD_PATH . 'bukti_mitigasi/');
define('TTD_PATH',        UPLOAD_PATH . 'ttd/');
define('MAX_FILE_SIZE',   5 * 1024 * 1024); // 5 MB
define('ALLOWED_TYPES',   ['image/jpeg','image/png','image/gif','application/pdf']);

// ── Session ───────────────────────────────────────────────────
define('SESSION_LIFETIME', (int) env('SESSION_LIFETIME', 7200)); // 2 jam

// ── Fallback error page: fatal/parse tidak boleh jadi layar putih ──
function manrisHandleFatal(): void {
    $err = error_get_last();
    if ($err === null) return;
    if (!in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    if (headers_sent()) return;
    http_response_code(500);
    while (ob_get_level() > 0) { @ob_end_clean(); }
    error_log('[manris] Fatal: ' . $err['message'] . ' in ' . $err['file'] . ' on line ' . $err['line']);
    $showDetail = (defined('APP_ENV') && APP_ENV === 'development')
        || (!empty($_GET['debug']))
        || (!empty($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['Admin', 'Risk Manager', 'Pimpinan', 'Kepala'], true));
    $detail = $showDetail
        ? '<div style="margin-top:12px;padding:12px;background:#fee2e2;border-radius:6px;font-family:monospace;font-size:12px;word-break:break-all;color:#7f1d1d">'
          . '<strong>Pesan:</strong> ' . htmlspecialchars($err['message']) . '<br>'
          . '<strong>File:</strong> ' . htmlspecialchars($err['file']) . ' (baris ' . $err['line'] . ')</div>'
          . '<div style="margin-top:14px;font-size:12px"><a href="' . (defined('APP_URL') ? APP_URL : '.') . '/index.php?page=dashboard" style="color:#b91c1c;text-decoration:underline">&larr; Kembali ke Dashboard</a></div>'
        : 'Kesalahan telah dicatat. Silakan hubungi administrator.';
    echo '<div style="padding:28px;margin:40px auto;max-width:560px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:10px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6">'
       . '<strong style="font-size:16px">Terjadi kesalahan sistem</strong><br>' . $detail . '</div>';
    exit;
}
register_shutdown_function('manrisHandleFatal');

// ── Inisialisasi Session ──────────────────────────────────────
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => $isHttps, // auto-detect HTTPS
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

// Security headers berlaku untuk seluruh response aplikasi.
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    if ($isHttps) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// ── Koneksi Database (MySQLi) ─────────────────────────────────
function getDB(): mysqli {
    static $conn = null;
    if ($conn === null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
            $conn->set_charset(DB_CHARSET);
        } catch (mysqli_sql_exception $e) {
            error_log('[manris] DB connection failed: ' . $e->getMessage());
            if (APP_ENV === 'development') {
                die('<div style="padding:20px;background:#fee2e2;color:#991b1b;border-radius:8px;font-family:sans-serif">
                    <strong>Database Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</div>');
            } else {
                die('Koneksi database gagal. Hubungi administrator.');
            }
        }
    }
    return $conn;
}

// ── Zona Waktu ────────────────────────────────────────────────
date_default_timezone_set('Asia/Jakarta');
