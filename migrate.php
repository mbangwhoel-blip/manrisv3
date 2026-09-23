<?php
/**
 * Migration runner sederhana untuk deployment ManRIS.
 * CLI: php migrate.php
 * Web: /migrate.php  dengan header X-Migration-Key: MIGRATION_SECRET (atau ?key=)
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    // Key dikirim via header X-Migration-Key (disarankan — tidak terekam di
    // access log). ?key=... masih didukung untuk kompatibilitas.
    $key = $_SERVER['HTTP_X_MIGRATION_KEY'] ?? $_GET['key'] ?? '';
    $secret = (string)(env('MIGRATION_SECRET', '') ?: getenv('MIGRATION_SECRET'));
    if ($secret === '' || !hash_equals($secret, $key)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$db = getDB();
$db->query("CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT NOT NULL AUTO_INCREMENT,
    migration VARCHAR(255) NOT NULL,
    checksum CHAR(64) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_schema_migration (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$files = ['update_workflow_phase.sql', 'add_approval_workflow.sql', 'add_security_tables.sql'];
$results = [];
foreach ($files as $file) {
    $path = __DIR__ . DIRECTORY_SEPARATOR . $file;
    if (!is_file($path)) { $results[] = ['file' => $file, 'status' => 'missing']; continue; }
    $checksum = hash_file('sha256', $path);
    $check = $db->prepare('SELECT checksum FROM schema_migrations WHERE migration=? LIMIT 1');
    $check->bind_param('s', $file); $check->execute();
    $existing = $check->get_result()->fetch_assoc(); $check->close();
    if ($existing && hash_equals((string)$existing['checksum'], $checksum)) {
        $results[] = ['file' => $file, 'status' => 'already_applied'];
        continue;
    }
    $sql = file_get_contents($path);
    if ($sql === false) { $results[] = ['file' => $file, 'status' => 'read_failed']; continue; }
    try {
        if (!$db->multi_query($sql)) throw new RuntimeException($db->error);
        do { $result = $db->store_result(); if ($result instanceof mysqli_result) $result->free(); } while ($db->more_results() && $db->next_result());
        if ($db->errno) throw new RuntimeException($db->error);
        $save = $db->prepare('INSERT INTO schema_migrations (migration,checksum) VALUES (?,?) ON DUPLICATE KEY UPDATE checksum=VALUES(checksum), applied_at=CURRENT_TIMESTAMP');
        $save->bind_param('ss', $file, $checksum); $save->execute(); $save->close();
        $results[] = ['file' => $file, 'status' => $existing ? 'reapplied' : 'applied'];
    } catch (Throwable $e) {
        while ($db->more_results()) { $db->next_result(); }
        error_log('[manris] migration failed: '.$file.' - '.$e->getMessage());
        $results[] = ['file' => $file, 'status' => 'failed', 'message' => $e->getMessage()];
        break;
    }
}

if ($isCli) {
    foreach ($results as $result) echo $result['file'].': '.$result['status'].(!empty($result['message']) ? ' - '.$result['message'] : '').PHP_EOL;
    exit;
}
header('Content-Type: application/json; charset=utf-8');
