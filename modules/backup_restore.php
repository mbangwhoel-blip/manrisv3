<?php
/**
 * MODUL BACKUP & RESTORE
 * Mengelola pencadangan (backup) dan pemulihan (restore) database.
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

// Fallback jika canManageBackup belum terdefinisi di functions.php
if (!function_exists('canManageBackup')) {
    function canManageBackup(): bool {
        return function_exists('hasRole') && hasRole('Admin', 'Risk Manager', 'Pimpinan', 'Kepala');
    }
}

requireRole('Admin', 'Risk Manager', 'Pimpinan', 'Kepala');
$db = getDB();

$currentUid      = (int)($_SESSION['user_id'] ?? 0);
$currentUsername = preg_replace('/[^a-zA-Z0-9_-]/', '', $_SESSION['user_username'] ?? $_SESSION['username'] ?? 'user');
$currentUserNama = $_SESSION['user_nama'] ?? $currentUsername;
$currentUserRole = $_SESSION['user_role'] ?? 'Risk Manager';
$isAdmin         = hasRole('Admin');

// ── Helper: Cek Keberadaan Tabel Database ───────────────────────────────────
if (!function_exists('tableExists')) {
    function tableExists(mysqli $db, string $table): bool {
        static $cachedTables = null;
        if ($cachedTables === null) {
            $cachedTables = [];
            $res = $db->query("SHOW TABLES");
            if ($res) {
                while ($r = $res->fetch_row()) {
                    $cachedTables[strtolower($r[0])] = true;
                }
                $res->free();
            }
        }
        return isset($cachedTables[strtolower($table)]);
    }
}

// ── Helper: Escape Nilai SQL Baris ──────────────────────────────────────────
if (!function_exists('sqlEscapeRow')) {
    function sqlEscapeRow(mysqli $db, array $row): string {
        $vals = [];
        foreach ($row as $v) {
            if ($v === null) {
                $vals[] = 'NULL';
            } else {
                $vals[] = "'" . $db->real_escape_string((string)$v) . "'";
            }
        }
        return '(' . implode(',', $vals) . ')';
    }
}

// ── Helper: Ekspor Baris Tabel Tertentu ──────────────────────────────────────
if (!function_exists('exportTableRows')) {
    function exportTableRows(mysqli $db, string $table, string $whereClause): string {
        if (!tableExists($db, $table)) return "";
        try {
            $res = $db->query("SELECT * FROM `$table` WHERE $whereClause");
            if (!$res || $res->num_rows === 0) {
                if ($res) $res->free();
                return "";
            }

            $fields = [];
            while ($f = $res->fetch_field()) {
                $fields[] = "`" . $f->name . "`";
            }
            $colStr = implode(',', $fields);

            $out = "-- Table: $table (" . $res->num_rows . " data)\n";
            $rows = [];
            while ($row = $res->fetch_assoc()) {
                $rows[] = sqlEscapeRow($db, $row);
                if (count($rows) >= 100) {
                    $out .= "INSERT INTO `$table` ($colStr) VALUES \n" . implode(",\n", $rows) . ";\n";
                    $rows = [];
                }
            }
            if (!empty($rows)) {
                $out .= "INSERT INTO `$table` ($colStr) VALUES \n" . implode(",\n", $rows) . ";\n";
            }
            $out .= "\n";
            $res->free();
            return $out;
        } catch (Throwable $e) {
            error_log('[manris] exportTableRows error on ' . $table . ': ' . $e->getMessage());
            return "";
        }
    }
}

// ── Helper: Bangun SQL Backup Per User ───────────────────────────────────────
if (!function_exists('generateUserBackupSql')) {
    function generateUserBackupSql(mysqli $db, int $targetUserId, string $username, string $nama, string $role): string {
        $now = date('Y-m-d H:i:s');

        // Kumpulkan ID data induk milik user ini
        $rIds = [];
        if (tableExists($db, 'risiko')) {
            $q = $db->query("SELECT id FROM risiko WHERE id_user_input = $targetUserId");
            if ($q) {
                while ($row = $q->fetch_row()) $rIds[] = (int)$row[0];
                $q->free();
            }
        }
        $rList = !empty($rIds) ? implode(',', $rIds) : '0';

        $kIds = [];
        if (tableExists($db, 'kkpr_header')) {
            $q = $db->query("SELECT id FROM kkpr_header WHERE created_by = $targetUserId");
            if ($q) {
                while ($row = $q->fetch_row()) $kIds[] = (int)$row[0];
                $q->free();
            }
        }
        $kList = !empty($kIds) ? implode(',', $kIds) : '0';

        $pIds = [];
        if (tableExists($db, 'profil_risiko')) {
            $q = $db->query("SELECT id FROM profil_risiko WHERE created_by = $targetUserId");
            if ($q) {
                while ($row = $q->fetch_row()) $pIds[] = (int)$row[0];
                $q->free();
            }
        }
        $pList = !empty($pIds) ? implode(',', $pIds) : '0';

        $sql = "-- ========================================================\n"
             . "-- MANRIS BACKUP METADATA\n"
             . "-- BACKUP_SCOPE: USER\n"
             . "-- BACKUP_USER_ID: $targetUserId\n"
             . "-- BACKUP_USERNAME: $username\n"
             . "-- BACKUP_USER_NAMA: $nama\n"
             . "-- BACKUP_USER_ROLE: $role\n"
             . "-- BACKUP_DATE: $now\n"
             . "-- ========================================================\n\n"
             . "SET FOREIGN_KEY_CHECKS=0;\n\n";

        $tables = [
            ['risiko', "id_user_input = $targetUserId"],
            ['mitigasi', "id_risiko IN ($rList)"],
            ['mitigasi_riwayat', "id_user = $targetUserId OR id_mitigasi IN (SELECT id FROM mitigasi WHERE id_risiko IN ($rList))"],
            ['ai_saran_cache', "id_risiko IN ($rList)"],
            ['profil_risiko', "created_by = $targetUserId"],
            ['profil_risiko_detail', "id_profil IN ($pList)"],
            ['profil_risiko_indikator', "id_profil IN ($pList)"],
            ['kkpr_header', "created_by = $targetUserId"],
            ['kkpr_risiko', "id_kkpr IN ($kList)"],
            ['monev_triwulan', "created_by = $targetUserId OR id_risiko IN (SELECT id FROM kkpr_risiko WHERE id_kkpr IN ($kList))"],
            ['ikk', "created_by = $targetUserId"],
            ['master_indikator_kegiatan', "created_by = $targetUserId"],
        ];

        foreach ($tables as [$tbl, $where]) {
            if (tableExists($db, $tbl)) {
                $sql .= exportTableRows($db, $tbl, $where);
            }
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        return $sql;
    }
}

// ── Helper: Hapus Data Milik User Sebelum Restore ────────────────────────────
if (!function_exists('deleteUserData')) {
    function deleteUserData(mysqli $db, int $uid): void {
        $rIds = [];
        if (tableExists($db, 'risiko')) {
            $q = $db->query("SELECT id FROM risiko WHERE id_user_input = $uid");
            if ($q) {
                while ($row = $q->fetch_row()) $rIds[] = (int)$row[0];
                $q->free();
            }
        }
        $rList = !empty($rIds) ? implode(',', $rIds) : '0';

        $kIds = [];
        if (tableExists($db, 'kkpr_header')) {
            $q = $db->query("SELECT id FROM kkpr_header WHERE created_by = $uid");
            if ($q) {
                while ($row = $q->fetch_row()) $kIds[] = (int)$row[0];
                $q->free();
            }
        }
        $kList = !empty($kIds) ? implode(',', $kIds) : '0';

        $pIds = [];
        if (tableExists($db, 'profil_risiko')) {
            $q = $db->query("SELECT id FROM profil_risiko WHERE created_by = $uid");
            if ($q) {
                while ($row = $q->fetch_row()) $pIds[] = (int)$row[0];
                $q->free();
            }
        }
        $pList = !empty($pIds) ? implode(',', $pIds) : '0';

        if (tableExists($db, 'monev_triwulan')) {
            $db->query("DELETE FROM monev_triwulan WHERE created_by = $uid OR id_risiko IN (SELECT id FROM kkpr_risiko WHERE id_kkpr IN ($kList))");
        }
        if (tableExists($db, 'mitigasi_riwayat')) {
            $db->query("DELETE FROM mitigasi_riwayat WHERE id_user = $uid OR id_mitigasi IN (SELECT id FROM mitigasi WHERE id_risiko IN ($rList))");
        }
        if (tableExists($db, 'mitigasi')) {
            $db->query("DELETE FROM mitigasi WHERE id_risiko IN ($rList)");
        }
        if (tableExists($db, 'ai_saran_cache')) {
            $db->query("DELETE FROM ai_saran_cache WHERE id_risiko IN ($rList)");
        }
        if (tableExists($db, 'profil_risiko_detail')) {
            $db->query("DELETE FROM profil_risiko_detail WHERE id_profil IN ($pList)");
        }
        if (tableExists($db, 'profil_risiko_indikator')) {
            $db->query("DELETE FROM profil_risiko_indikator WHERE id_profil IN ($pList)");
        }
        if (tableExists($db, 'kkpr_risiko')) {
            $db->query("DELETE FROM kkpr_risiko WHERE id_kkpr IN ($kList)");
        }
        if (tableExists($db, 'ikk')) {
            $db->query("DELETE FROM ikk WHERE created_by = $uid");
        }
        if (tableExists($db, 'profil_risiko')) {
            $db->query("DELETE FROM profil_risiko WHERE created_by = $uid");
        }
        if (tableExists($db, 'kkpr_header')) {
            $db->query("DELETE FROM kkpr_header WHERE created_by = $uid");
        }
        if (tableExists($db, 'master_indikator_kegiatan')) {
            $db->query("DELETE FROM master_indikator_kegiatan WHERE created_by = $uid");
        }
        if (tableExists($db, 'risiko')) {
            $db->query("DELETE FROM risiko WHERE id_user_input = $uid");
        }
    }
}

// ── Helper: Parse Metadata Header SQL ───────────────────────────────────────
if (!function_exists('parseBackupMetadata')) {
    function parseBackupMetadata(string $content): array {
        $meta = [
            'scope'     => 'FULL',
            'user_id'   => 0,
            'username'  => '',
            'user_nama' => '',
            'user_role' => '',
            'date'      => '',
        ];
        if (preg_match('/--\s*BACKUP_SCOPE:\s*(\w+)/i', $content, $m)) {
            $meta['scope'] = strtoupper(trim($m[1]));
        }
        if (preg_match('/--\s*BACKUP_USER_ID:\s*(\d+)/i', $content, $m)) {
            $meta['user_id'] = (int)$m[1];
        }
        if (preg_match('/--\s*BACKUP_USERNAME:\s*([a-zA-Z0-9_-]+)/i', $content, $m)) {
            $meta['username'] = trim($m[1]);
        }
        if (preg_match('/--\s*BACKUP_USER_NAMA:\s*(.+)/i', $content, $m)) {
            $meta['user_nama'] = trim($m[1]);
        }
        if (preg_match('/--\s*BACKUP_USER_ROLE:\s*(.+)/i', $content, $m)) {
            $meta['user_role'] = trim($m[1]);
        }
        if (preg_match('/--\s*BACKUP_DATE:\s*(.+)/i', $content, $m)) {
            $meta['date'] = trim($m[1]);
        }
        return $meta;
    }
}

// ── Handle Download Backup ──────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'download') {
    if (!canManageBackup()) {
        http_response_code(403);
        error_log('[manris] Backup download blocked for role: ' . ($_SESSION['user_role'] ?? '?'));
        die('Akses ditolak. Anda tidak memiliki izin untuk mengunduh backup database.');
    }

    try {
        $scope = 'USER';
        $targetUid = $currentUid;
        $targetUsername = $currentUsername;
        $targetNama = $currentUserNama;
        $targetRole = $currentUserRole;

        if ($isAdmin) {
            $reqScope = $_GET['scope'] ?? 'full';
            if ($reqScope === 'user' && !empty($_GET['user_id'])) {
                $selId = (int)$_GET['user_id'];
                $uStmt = $db->prepare('SELECT id, username, nama, role FROM users WHERE id = ? LIMIT 1');
                $uStmt->bind_param('i', $selId);
                $uStmt->execute();
                $uRow = $uStmt->get_result()->fetch_assoc();
                $uStmt->close();
                if ($uRow) {
                    $scope = 'USER';
                    $targetUid = (int)$uRow['id'];
                    $targetUsername = preg_replace('/[^a-zA-Z0-9_-]/', '', $uRow['username']);
                    $targetNama = $uRow['nama'];
                    $targetRole = $uRow['role'];
                }
            } else {
                $scope = 'FULL';
            }
        }

        if ($scope === 'USER') {
            $sqlOut = generateUserBackupSql($db, $targetUid, $targetUsername, $targetNama, $targetRole);
            $filename = "backup_manris_" . $targetUsername . "_" . date('Y-m-d_H-i-s') . ".sql";
        } else {
            $now = date('Y-m-d H:i:s');
            $header = "-- ========================================================\n"
                    . "-- MANRIS BACKUP METADATA\n"
                    . "-- BACKUP_SCOPE: FULL\n"
                    . "-- BACKUP_USER_ID: $currentUid\n"
                    . "-- BACKUP_USERNAME: $currentUsername\n"
                    . "-- BACKUP_DATE: $now\n"
                    . "-- ========================================================\n\n"
                    . "SET FOREIGN_KEY_CHECKS=0;\n\n";

            $sqlOut = $header;
            $tables = [];
            $result = $db->query('SHOW TABLES');
            while ($row = $result->fetch_row()) { $tables[] = $row[0]; }

            foreach ($tables as $table) {
                $res = $db->query("SHOW CREATE TABLE `$table`");
                $row = $res->fetch_row();
                $sqlOut .= "DROP TABLE IF EXISTS `$table`;\n" . $row[1] . ";\n\n";

                $res = $db->query("SELECT * FROM `$table`");
                if ($res && $res->num_rows > 0) {
                    $fields = [];
                    while ($f = $res->fetch_field()) { $fields[] = "`" . $f->name . "`"; }
                    $colStr = implode(',', $fields);

                    $rowsSql = [];
                    while ($dataRow = $res->fetch_assoc()) {
                        $rowsSql[] = sqlEscapeRow($db, $dataRow);
                        if (count($rowsSql) >= 100) {
                            $sqlOut .= "INSERT INTO `$table` ($colStr) VALUES \n" . implode(",\n", $rowsSql) . ";\n";
                            $rowsSql = [];
                        }
                    }
                    if (!empty($rowsSql)) {
                        $sqlOut .= "INSERT INTO `$table` ($colStr) VALUES \n" . implode(",\n", $rowsSql) . ";\n";
                    }
                    $sqlOut .= "\n";
                }
            }
            $sqlOut .= "SET FOREIGN_KEY_CHECKS=1;\n";
            $filename = "backup_manris_admin_full_" . date('Y-m-d_H-i-s') . ".sql";
        }

        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/sql; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        @set_time_limit(0);

        $signature = hash_hmac('sha256', $sqlOut, APP_KEY);
        echo "-- HMAC-SHA256: $signature\n";
        echo $sqlOut;
        exit;
    } catch (Throwable $e) {
        error_log('[manris] Download backup failed: ' . $e->getMessage());
        setFlash('error', 'Gagal memproses pengunduhan backup: ' . $e->getMessage());
        header('Location: ' . APP_URL . '/?page=backup');
        exit;
    }
}

// ── Handle Restore Database ─────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restore') {
    if (!canManageBackup()) {
        http_response_code(403);
        setFlash('error', 'Hanya Admin, Risk Manager, atau Pimpinan/Kepala yang dapat melakukan restore data.');
        header('Location: ' . APP_URL . '/?page=backup');
        exit;
    }
    if (!verifyCsrf()) {
        setFlash('error', 'Token keamanan CSRF tidak valid.');
        header('Location: ' . APP_URL . '/?page=backup');
        exit;
    }

    if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
        $fileTmp  = $_FILES['backup_file']['tmp_name'];
        $fileName = $_FILES['backup_file']['name'];
        $fileSize = (int)$_FILES['backup_file']['size'];

        // Cek ukuran file (max 50MB)
        if ($fileSize > 50 * 1024 * 1024) {
            setFlash('error', 'Ukuran file terlalu besar. Maksimal 50MB.');
            header('Location: ' . APP_URL . '/?page=backup');
            exit;
        }

        // Cek ekstensi
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($ext !== 'sql') {
            setFlash('error', 'Format file tidak didukung. Harap unggah file cadangan berekstensi .sql');
            header('Location: ' . APP_URL . '/?page=backup');
            exit;
        }

        $sqlContent = file_get_contents($fileTmp);
        if (empty(trim($sqlContent))) {
            setFlash('error', 'File SQL cadangan kosong.');
            header('Location: ' . APP_URL . '/?page=backup');
            exit;
        }

        // ── Verifikasi HMAC Signature ──
        $lines = explode("\n", str_replace("\r", "", $sqlContent), 2);
        if (strpos($lines[0], '-- HMAC-SHA256: ') !== 0) {
            setFlash('error', 'File backup tidak memiliki tanda tangan digital yang valid (HMAC tidak ditemukan).');
            header('Location: ' . APP_URL . '/?page=backup');
            exit;
        }
        $fileSignature = trim(substr($lines[0], 16));
        $actualContent = $lines[1] ?? '';
        $expectedSignature = hash_hmac('sha256', $actualContent, APP_KEY);

        if (!hash_equals($expectedSignature, $fileSignature)) {
            error_log('[manris] Restore blocked — invalid HMAC signature.');
            setFlash('error', 'Integritas file backup gagal diverifikasi. File mungkin telah dimodifikasi atau rusak.');
            header('Location: ' . APP_URL . '/?page=backup');
            exit;
        }

        // ── Parse Metadata Backup ──
        $meta         = parseBackupMetadata($actualContent);
        $fileScope    = $meta['scope'];
        $fileUsername = $meta['username'];
        $fileUserId   = (int)$meta['user_id'];

        // ── Validasi Kepemilikan & Hak Akses Restore ──
        if ($fileScope === 'USER') {
            // Jika bukan Admin, wajib mencocokkan username akun yang sedang login
            if (!$isAdmin && strtolower($fileUsername) !== strtolower($currentUsername)) {
                setFlash('error', 'File backup ini milik user "' . htmlspecialchars($fileUsername ?: 'Lain') . '". Anda saat ini login sebagai "' . htmlspecialchars($currentUsername) . '". Pemulihan dibatalkan agar data antar unit tidak tertukar.');
                header('Location: ' . APP_URL . '/?page=backup');
                exit;
            }

            // Keamanan: File backup scoped USER dilarang keras mengandung perintah DROP atau TRUNCATE
            if (preg_match('/\b(DROP\s+TABLE|DROP\s+DATABASE|TRUNCATE\s+TABLE|TRUNCATE)\b/i', $actualContent)) {
                setFlash('error', 'File backup tidak valid: cadangan data pengguna tidak diizinkan memuat perintah DROP atau TRUNCATE.');
                header('Location: ' . APP_URL . '/?page=backup');
                exit;
            }
        } else {
            // FULL scope: Hanya Admin yang boleh restore database penuh
            if (!$isAdmin) {
                setFlash('error', 'File backup ini adalah cadangan sistem penuh (Full Database). Akun Anda tidak memiliki hak akses Administrator untuk memulihkan seluruh database.');
                header('Location: ' . APP_URL . '/?page=backup');
                exit;
            }
        }

        // ── Eksekusi Pemulihan Database ──
        $db->begin_transaction();
        try {
            $db->query("SET FOREIGN_KEY_CHECKS=0");

            if ($fileScope === 'USER') {
                $restoreTargetUid = ($isAdmin && $fileUserId > 0) ? $fileUserId : $currentUid;

                // 1. Hapus hanya data milik user ini agar tidak meninggalkan duplikat
                deleteUserData($db, $restoreTargetUid);

                // 2. Masukkan data dari file backup
                if ($db->multi_query($actualContent)) {
                    do {
                        if ($res = $db->store_result()) $res->free();
                    } while ($db->more_results() && $db->next_result());
                } else {
                    throw new Exception($db->error);
                }

                logAktivitas('RESTORE', 'backup', $restoreTargetUid, 'Restore data user ' . ($fileUsername ?: $currentUsername) . ' dari file: ' . $fileName);
                setFlash('success', 'Data milik akun ' . htmlspecialchars($fileUsername ?: $currentUsername) . ' berhasil dipulihkan secara aman tanpa mempengaruhi data unit lain!');
            } else {
                // FULL DATABASE RESTORE
                if ($db->multi_query($actualContent)) {
                    do {
                        if ($res = $db->store_result()) $res->free();
                    } while ($db->more_results() && $db->next_result());
                } else {
                    throw new Exception($db->error);
                }

                logAktivitas('RESTORE', 'system', 0, 'Restore database penuh dari file: ' . $fileName);
                setFlash('success', 'Seluruh database sistem berhasil dipulihkan!');
            }

            $db->query("SET FOREIGN_KEY_CHECKS=1");
            $db->commit();

        } catch (Throwable $e) {
            $db->rollback();
            $db->query("SET FOREIGN_KEY_CHECKS=1");
            error_log('[manris] Restore failed: ' . $e->getMessage());
            setFlash('error', 'Gagal memulihkan database: ' . $e->getMessage());
        }
    } else {
        setFlash('error', 'Tidak ada file yang diunggah atau terjadi kesalahan saat mengunggah file.');
    }

    header('Location: ' . APP_URL . '/?page=backup');
    exit;
}

// Daftar user untuk dropdown Admin
$userList = [];
if ($isAdmin) {
    try {
        $uRes = $db->query("SELECT id, username, nama, role FROM users ORDER BY role, username");
        if ($uRes) {
            while ($ur = $uRes->fetch_assoc()) $userList[] = $ur;
            $uRes->free();
        }
    } catch (Throwable $e) {
        error_log('[manris] userList query error: ' . $e->getMessage());
    }
}
?>

<div class="risiko-hero" style="background:linear-gradient(115deg,#475569 0%,#334155 55%,#1e293b 100%)">
  <div class="risiko-hero-copy">
    <div class="risiko-eyebrow"><i class="fas fa-database"></i> Sistem &middot; Cadangan Data</div>
    <h1 class="page-title" style="color:#fff">Backup &amp; Restore Data</h1>
    <p class="page-sub" style="color:rgba(255,255,255,.8)">
      <?php if ($isAdmin): ?>
        Kelola pencadangan dan pemulihan data sistem (Full Database) atau per unit kerja/pengguna.
      <?php else: ?>
        Pencadangan dan pemulihan khusus untuk data milik akun Anda (<strong><?= xss($currentUsername) ?></strong> &mdash; <?= xss($currentUserNama) ?>).
      <?php endif; ?>
    </p>
  </div>
</div>

<div class="form-row-2">
  <!-- Kartu Backup -->
  <div class="card" style="border-top:4px solid var(--accent)">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <span class="card-title">
        <i class="fas fa-download"></i> <?= $isAdmin ? 'Pencadangan Data (Backup)' : 'Backup Data Akun (' . xss($currentUsername) . ')' ?>
      </span>
      <span class="badge" style="background:var(--surface2);border:1px solid var(--border);color:var(--text-muted);font-size:.75rem;">
        <i class="fas fa-user"></i> <?= xss($currentUsername) ?>
      </span>
    </div>
    <div class="card-body">
      <?php if (!$isAdmin): ?>
        <!-- Tampilan untuk Pengguna Non-Admin (Risk Manager / Pimpinan) -->
        <p style="color:var(--text-muted); font-size: 0.88rem; margin-bottom: 20px; line-height: 1.5;">
          Klik tombol di bawah untuk mengunduh cadangan seluruh data risiko, mitigasi, profil risiko, KKPR, dan IKK milik akun <strong><?= xss($currentUsername) ?></strong>.
          File akan otomatis dinamai sesuai akun Anda (<code>backup_manris_<?= xss($currentUsername) ?>_[tanggal].sql</code>) agar tidak membingungkan dan tidak tertukar dengan data unit lain.
        </p>

        <div style="background:var(--surface2); padding: 20px; border-radius: 8px; border: 1px dashed var(--border); text-align:center;">
          <i class="fas fa-file-shield" style="font-size: 3rem; color: var(--accent); opacity: 0.85; margin-bottom: 12px;"></i>
          <div style="font-weight: 700; font-size: 1rem; margin-bottom: 4px;">Cadangkan Data <?= xss($currentUserNama) ?></div>
          <div style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 18px;">Format file: <code>backup_manris_<?= xss($currentUsername) ?>_[tanggal].sql</code></div>
          <a href="<?= APP_URL ?>/?page=backup&action=download" class="btn btn-primary btn-lg" style="width: 100%; justify-content:center;">
            <i class="fas fa-cloud-download-alt"></i> Unduh File Backup (<?= xss($currentUsername) ?>.sql)
          </a>
        </div>
      <?php else: ?>
        <!-- Tampilan Khusus Administrator -->
        <p style="color:var(--text-muted); font-size: 0.88rem; margin-bottom: 16px; line-height: 1.5;">
          Sebagai Administrator, Anda dapat mengunduh seluruh database sistem atau mengunduh cadangan data spesifik per unit pengguna.
        </p>

        <div style="background:var(--surface2); padding: 16px; border-radius: 8px; border: 1px solid var(--border); margin-bottom: 16px;">
          <div style="font-weight: 700; margin-bottom: 8px; display:flex; align-items:center; gap:8px;">
            <i class="fas fa-server" style="color:var(--accent);"></i> Backup Full Database
          </div>
          <p style="font-size:.82rem; color:var(--text-muted); margin-bottom:12px;">
            Mengunduh seluruh tabel dan data semua pengguna aplikasi.
          </p>
          <a href="<?= APP_URL ?>/?page=backup&action=download&scope=full" class="btn btn-primary" style="width: 100%; justify-content:center;">
            <i class="fas fa-database"></i> Unduh Full Database (.sql)
          </a>
        </div>

        <div style="background:var(--surface2); padding: 16px; border-radius: 8px; border: 1px solid var(--border);">
          <div style="font-weight: 700; margin-bottom: 8px; display:flex; align-items:center; gap:8px;">
            <i class="fas fa-user-tag" style="color:var(--primary);"></i> Backup Data Per User / Unit
          </div>
          <p style="font-size:.82rem; color:var(--text-muted); margin-bottom:10px;">
            Pilih pengguna untuk mengunduh cadangan data risiko dan mitigasi khusus user tersebut.
          </p>
          <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <select id="selAdminUserBackup" class="form-control" style="flex:1; min-width:180px;">
              <?php foreach ($userList as $u): ?>
                <option value="<?= (int)$u['id'] ?>" data-username="<?= xss($u['username']) ?>" <?= $u['username'] === 'adum' ? 'selected' : '' ?>>
                  <?= xss($u['username']) ?> &mdash; <?= xss($u['nama']) ?> (<?= xss($u['role']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
            <button type="button" onclick="downloadUserBackupAsAdmin()" class="btn btn-outline" style="white-space:nowrap;">
              <i class="fas fa-download"></i> Unduh User
            </button>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Kartu Restore -->
  <div class="card" style="border-top:4px solid var(--danger)">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <span class="card-title">
        <i class="fas fa-upload"></i> <?= $isAdmin ? 'Pemulihan Data (Restore)' : 'Restore Data Akun (' . xss($currentUsername) . ')' ?>
      </span>
      <span class="badge" style="background:#fee2e2;color:#b91c1c;font-size:.75rem;">
        <i class="fas fa-shield-alt"></i> Proteksi Aktif
      </span>
    </div>
    <div class="card-body">
      <div style="background:#fee2e2; color:#991b1b; padding:12px 15px; border-radius:var(--radius-sm); font-size:.85rem; margin-bottom: 20px; display:flex; gap:10px; align-items:flex-start;">
        <i class="fas fa-exclamation-triangle" style="margin-top:2px; font-size:1.1rem;"></i>
        <div>
          <strong>Perhatian:</strong>
          <?php if (!$isAdmin): ?>
            Proses pemulihan (restore) hanya akan memperbarui data milik akun <strong><?= xss($currentUsername) ?></strong>.
            Data milik unit lain (seperti timker1, timker2, dll.) <strong>100% aman dan tidak akan terhapus</strong>.
          <?php else: ?>
            Restore file cadangan pengguna hanya akan memperbarui data user bersangkutan. Jika mengunggah file Full Database, seluruh database akan diperbarui.
          <?php endif; ?>
        </div>
      </div>

      <form method="POST" action="<?= APP_URL ?>/?page=backup" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="restore">

        <div class="form-group">
          <label class="form-label">Pilih File Backup (.sql) <span class="required">*</span></label>
          <input type="file" name="backup_file" class="form-control" accept=".sql" required style="padding: 12px; background:var(--surface2);">
          <div class="form-hint">
            Hanya menerima file berformat <code>.sql</code> hasil backup resmi aplikasi Manris.
          </div>
        </div>

        <button type="submit" id="btnRestore" class="btn btn-danger" style="width: 100%; justify-content:center;">
          <i class="fas fa-sync-alt"></i> Pulihkan Data (Restore)
        </button>
      </form>
    </div>
  </div>
</div>

<script>
function downloadUserBackupAsAdmin() {
    const sel = document.getElementById('selAdminUserBackup');
    if (!sel) return;
    const uid = sel.value;
    window.location.href = '<?= APP_URL ?>/?page=backup&action=download&scope=user&user_id=' + encodeURIComponent(uid);
}

(function () {
    const form = document.querySelector('form[action*="page=backup"]');
    const btn = document.getElementById('btnRestore');
    if (!form || !btn) return;
    form.addEventListener('submit', function () {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memulihkan Data...';
    });
})();
</script>
