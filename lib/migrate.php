<?php
/**
 * Sistem Migrasi Database Formal — SEMAR
 *
 * Penggunaan (PHP CLI saja):
 *   php lib/migrate.php run      -- jalankan semua migrasi pending
 *   php lib/migrate.php status   -- tampilkan status semua migrasi
 *   php lib/migrate.php rollback -- rollback migrasi terakhir
 *
 * File migrasi disimpan di: lib/migrations/YYYYMMDD_HHMMSS_nama.sql
 * Setiap file harus memiliki blok -- UP: dan -- DOWN: opsional.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Hanya bisa dijalankan via PHP CLI.');
}

require_once __DIR__ . '/../includes/config.php';

$db = getDB();

// Buat tabel migrasi jika belum ada
$db->query("CREATE TABLE IF NOT EXISTS `_migrations` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `filename`   VARCHAR(255) NOT NULL UNIQUE,
    `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `checksum`   CHAR(64)     NOT NULL COMMENT 'SHA-256 dari isi file migrasi'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$migrationsDir = __DIR__ . '/migrations';
if (!is_dir($migrationsDir)) {
    mkdir($migrationsDir, 0755, true);
}

$command = $argv[1] ?? 'status';

// ── Ambil semua file migrasi ──────────────────────────────────
$files = glob($migrationsDir . '/*.sql');
sort($files);

// ── Ambil yang sudah diapply ──────────────────────────────────
$applied = [];
$res = $db->query("SELECT filename, checksum FROM `_migrations` ORDER BY applied_at ASC");
while ($row = $res->fetch_assoc()) {
    $applied[$row['filename']] = $row['checksum'];
}

// ── Command: status ───────────────────────────────────────────
if ($command === 'status') {
    echo str_pad('Status', 10) . str_pad('File', 50) . "\n";
    echo str_repeat('-', 60) . "\n";
    foreach ($files as $file) {
        $base = basename($file);
        $status = isset($applied[$base]) ? '[APPLIED]' : '[PENDING]';
        echo str_pad($status, 10) . $base . "\n";
    }
    if (empty($files)) {
        echo "Tidak ada file migrasi di $migrationsDir\n";
    }
    exit(0);
}

// ── Command: run ──────────────────────────────────────────────
if ($command === 'run') {
    $pending = array_filter($files, fn($f) => !isset($applied[basename($f)]));
    if (empty($pending)) {
        echo "Tidak ada migrasi pending.\n";
        exit(0);
    }

    foreach ($pending as $file) {
        $base = basename($file);
        $content = file_get_contents($file);
        $checksum = hash('sha256', $content);

        // Ekstrak blok UP (antara -- UP: dan -- DOWN: atau akhir file)
        $upSql = $content;
        if (preg_match('/--\s*UP:(.*?)(?:--\s*DOWN:|$)/si', $content, $m)) {
            $upSql = trim($m[1]);
        }

        if (empty(trim($upSql))) {
            echo "SKIP $base — blok UP kosong.\n";
            continue;
        }

        $db->begin_transaction();
        try {
            if ($db->multi_query($upSql)) {
                do {
                    if ($r = $db->store_result()) $r->free();
                } while ($db->more_results() && $db->next_result());
            }
            $ins = $db->prepare("INSERT INTO `_migrations` (filename, checksum) VALUES (?, ?)");
            $ins->bind_param('ss', $base, $checksum);
            $ins->execute();
            $ins->close();
            $db->commit();
            echo "OK    $base\n";
        } catch (Throwable $e) {
            $db->rollback();
            echo "FAIL  $base — " . $e->getMessage() . "\n";
            exit(1);
        }
    }
    echo "Selesai.\n";
    exit(0);
}

// ── Command: rollback ─────────────────────────────────────────
if ($command === 'rollback') {
    $res2 = $db->query("SELECT filename FROM `_migrations` ORDER BY applied_at DESC LIMIT 1");
    $last = $res2->fetch_assoc();
    if (!$last) {
        echo "Tidak ada migrasi yang bisa di-rollback.\n";
        exit(0);
    }
    $base = $last['filename'];
    $file = $migrationsDir . '/' . $base;
    if (!is_file($file)) {
        echo "File $base tidak ditemukan, tidak bisa rollback.\n";
        exit(1);
    }
    $content = file_get_contents($file);
    $downSql = '';
    if (preg_match('/--\s*DOWN:(.*?)$/si', $content, $m)) {
        $downSql = trim($m[1]);
    }
    if (empty($downSql)) {
        echo "File $base tidak memiliki blok DOWN. Rollback manual diperlukan.\n";
        exit(1);
    }
    $db->begin_transaction();
    try {
        if ($db->multi_query($downSql)) {
            do {
                if ($r = $db->store_result()) $r->free();
            } while ($db->more_results() && $db->next_result());
        }
        $del = $db->prepare("DELETE FROM `_migrations` WHERE filename = ?");
        $del->bind_param('s', $base);
        $del->execute();
        $del->close();
        $db->commit();
        echo "Rollback $base berhasil.\n";
    } catch (Throwable $e) {
        $db->rollback();
        echo "FAIL rollback $base — " . $e->getMessage() . "\n";
        exit(1);
    }
    exit(0);
}

echo "Perintah tidak dikenal: $command\nGunakan: run | status | rollback\n";
exit(1);
