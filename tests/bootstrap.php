<?php
/**
 * Bootstrap untuk PHPUnit — SEMAR
 * Muat konfigurasi tanpa memulai session atau output HTTP.
 */

// Simulasikan environment test
$_ENV['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');

// Load .env jika ada (urutan sama dengan includes/config.php)
$envCandidates = array_filter([
    getenv('MANRIS_ENV_FILE') ?: null,
    dirname(__DIR__, 2) . '/manrisv2_config/.env', // luar webroot
    dirname(__DIR__) . '/.env',                     // fallback webroot
]);
$envFile = null;
foreach ($envCandidates as $candidate) {
    if (is_file($candidate)) { $envFile = $candidate; break; }
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

require_once dirname(__DIR__) . '/includes/functions.php';
