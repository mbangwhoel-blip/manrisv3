<?php
/**
 * MODUL LAPORAN KONSOLIDASI
 * Gabungan Profil Risiko + KKPR + KKPMR dengan filter per user
 * Akses: Admin, Pimpinan, Risk Manager
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
requireRole('Admin', 'Pimpinan', 'Risk Manager', 'Koordinator');
$db = getDB();

// Pastikan tabel master/snapshot tersedia sebelum laporan membaca pilihan indikator.
$db->query("CREATE TABLE IF NOT EXISTS master_indikator_kegiatan (
    id INT NOT NULL AUTO_INCREMENT, tahun VARCHAR(9) NOT NULL, program VARCHAR(200) DEFAULT NULL,
    kegiatan TEXT DEFAULT NULL, sasaran TEXT DEFAULT NULL, indikator TEXT NOT NULL,
    target VARCHAR(500) DEFAULT NULL, satuan VARCHAR(100) DEFAULT NULL,
    unit_pemilik_risiko VARCHAR(200) DEFAULT NULL, aktif TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT DEFAULT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id), KEY idx_master_indikator_tahun (tahun), KEY idx_master_indikator_aktif (aktif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$db->query("CREATE TABLE IF NOT EXISTS profil_risiko_indikator (
    id INT NOT NULL AUTO_INCREMENT, id_profil INT NOT NULL, id_master INT DEFAULT NULL,
    tahun VARCHAR(9) DEFAULT NULL, program VARCHAR(200) DEFAULT NULL, kegiatan TEXT DEFAULT NULL,
    sasaran TEXT DEFAULT NULL, indikator TEXT NOT NULL, target VARCHAR(500) DEFAULT NULL,
    satuan VARCHAR(100) DEFAULT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), KEY idx_profil_indikator (id_profil), KEY idx_master_profil_indikator (id_master)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── Auto-create tabel konsolidasi_approval jika belum ada ─────
$db->query("CREATE TABLE IF NOT EXISTS `konsolidasi_approval` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `tahun` varchar(9) NOT NULL,
    `status` enum('pending','disetujui','ditolak') NOT NULL DEFAULT 'pending',
    `approved_by` int(11) DEFAULT NULL,
    `approved_at` timestamp NULL DEFAULT NULL,
    `catatan` text DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tahun` (`tahun`),
    KEY `idx_status` (`status`),
    KEY `fk_konsol_approver` (`approved_by`),
    CONSTRAINT `fk_konsol_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$canAccessAll = canAccessAllRecords();
$userId       = (int)($_SESSION['user_id'] ?? 0);
$tab          = $_GET['tab'] ?? 'profil';
if (!in_array($tab, ['profil', 'kkpr', 'kkpmr'], true)) $tab = 'profil';
$export       = $_GET['export'] ?? '';

$fTahun  = trim($_GET['tahun'] ?? '');
$fUser   = trim($_GET['user'] ?? '');
$fLevel  = trim($_GET['level'] ?? '');
$fUnit   = trim($_GET['unit'] ?? '');
$fSimpu  = trim($_GET['simpulan'] ?? '');
$fQ      = trim($_GET['q'] ?? '');

// ── Handle POST approval (Pimpinan only) ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { setFlash('error', 'Token tidak valid'); header('Location: '.APP_URL.'/?page=laporan_konsolidasi&tab='.$tab); exit; }
    if (!hasRole('Pimpinan')) { setFlash('error', 'Hanya Pimpinan yang dapat menyetujui laporan'); header('Location: '.APP_URL.'/?page=laporan_konsolidasi&tab='.$tab); exit; }

    $aksi      = $_POST['aksi'] ?? '';
    $appTahun  = trim($_POST['tahun'] ?? '');
    $appCatatan = trim($_POST['catatan'] ?? '');

    if (empty($appTahun)) { setFlash('error', 'Tahun wajib dipilih untuk approval'); header('Location: '.APP_URL.'/?page=laporan_konsolidasi&tab='.$tab); exit; }

    if ($aksi === 'approve') {
        $stmt = $db->prepare("INSERT INTO konsolidasi_approval (tahun, status, approved_by, approved_at, catatan)
                              VALUES (?, 'disetujui', ?, NOW(), ?)
                              ON DUPLICATE KEY UPDATE status='disetujui', approved_by=?, approved_at=NOW(), catatan=?");
        $stmt->bind_param('sisis', $appTahun, $userId, $appCatatan, $userId, $appCatatan);
        $stmt->execute(); $stmt->close();
        logAktivitas('APPROVE', 'laporan_konsolidasi', null, 'Setujui laporan konsolidasi tahun ' . $appTahun);
        notifikasi((int)$_SESSION['user_id'], 'konsolidasi_approved', 'Laporan Konsolidasi Disetujui', 'Laporan konsolidasi tahun ' . $appTahun . ' telah disetujui.', APP_URL . '/?page=laporan_konsolidasi&tab=' . $tab . '&tahun=' . urlencode($appTahun));
        setFlash('success', 'Laporan konsolidasi tahun ' . $appTahun . ' telah disetujui');
    } elseif ($aksi === 'reject') {
        $stmt = $db->prepare("INSERT INTO konsolidasi_approval (tahun, status, approved_by, approved_at, catatan)
                              VALUES (?, 'ditolak', ?, NOW(), ?)
                              ON DUPLICATE KEY UPDATE status='ditolak', approved_by=?, approved_at=NOW(), catatan=?");
        $stmt->bind_param('sisis', $appTahun, $userId, $appCatatan, $userId, $appCatatan);
        $stmt->execute(); $stmt->close();
        logAktivitas('REJECT', 'laporan_konsolidasi', null, 'Tolak laporan konsolidasi tahun ' . $appTahun);
        setFlash('success', 'Laporan konsolidasi tahun ' . $appTahun . ' telah ditolak');
    } elseif ($aksi === 'revoke') {
        $stmt = $db->prepare("UPDATE konsolidasi_approval SET status='pending', approved_by=NULL, approved_at=NULL, catatan=NULL WHERE tahun=?");
        $stmt->bind_param('s', $appTahun);
        $stmt->execute(); $stmt->close();
        logAktivitas('REVOKE', 'laporan_konsolidasi', null, 'Batal approval konsolidasi tahun ' . $appTahun);
        setFlash('success', 'Status approval tahun ' . $appTahun . ' telah direset');
    }
    header('Location: '.APP_URL.'/?page=laporan_konsolidasi&tab='.$tab.'&tahun='.urlencode($appTahun));
    exit;
}

// ── Ambil status approval untuk tahun terpilih ────────────────
$approvalData = null;
if ($fTahun !== '') {
    $aps = $db->prepare("SELECT ka.*, u.nama AS approver_nama, u.nip AS approver_nip, u.role AS approver_role
                         FROM konsolidasi_approval ka
                         LEFT JOIN users u ON ka.approved_by = u.id
                         WHERE ka.tahun = ? LIMIT 1");
    $aps->bind_param('s', $fTahun); $aps->execute();
    $approvalData = $aps->get_result()->fetch_assoc(); $aps->close();
}

// Ambil daftar semua status approval (untuk badge per tahun di dropdown)
$allApprovals = [];
$ar = $db->query("SELECT tahun, status FROM konsolidasi_approval ORDER BY tahun DESC");
if ($ar) {
    while ($row = $ar->fetch_assoc()) { $allApprovals[$row['tahun']] = $row['status']; }
    $ar->free();
}

// Daftar user untuk dropdown filter (Admin/Pimpinan)
$userList = [];
if ($canAccessAll) {
    $userList = $db->query("SELECT id, nama, username FROM users WHERE aktif=1 ORDER BY nama ASC")->fetch_all(MYSQLI_ASSOC) ?: [];
}

// Daftar tahun tersedia (gabungan profil_risiko + kkpr_header)
$tahunList = $db->query("
    SELECT DISTINCT tahun FROM (
        SELECT tahun FROM profil_risiko
        UNION
        SELECT tahun FROM kkpr_header
    ) t ORDER BY tahun DESC
")->fetch_all(MYSQLI_ASSOC) ?: [];

// Scope user: Risk Manager hanya data miliknya, Admin/Pimpinan bisa pilih user
$scopeUser = '';
$scopeParams = [];
$scopeTypes = '';
if (!$canAccessAll) {
    $scopeUser = $userId;
} elseif ($fUser !== '') {
    $scopeUser = (int)$fUser;
}
$useUserFilter = ($scopeUser !== '');

// â”€â”€ QUERY BUILDER PER TAB â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function buildProfilQuery(string $useUserFilter, $scopeUser, string $fTahun, string $fUnit, string $fLevel, string $fQ, bool $forExport = false): array {
    $where = ['1=1'];
    $params = [];
    $types = '';

    if ($useUserFilter) { $where[] = 'p.created_by = ?'; $params[] = $scopeUser; $types .= 'i'; }
    if ($fTahun) { $where[] = 'p.tahun = ?'; $params[] = $fTahun; $types .= 's'; }
    if ($fUnit)  { $where[] = 'd.unit_kerja = ?'; $params[] = $fUnit; $types .= 's'; }
    if ($fLevel) {
        if ($fLevel === 'tinggi_plus') { $where[] = 'CAST(d.nilai AS DECIMAL(8,2)) >= 15'; }
        else { $where[] = 'd.tingkat_risiko = ?'; $params[] = $fLevel; $types .= 's'; }
    }
    if ($fQ) {
        $where[] = '(d.nama_risiko LIKE ? OR d.kode_risiko LIKE ?)';
        $qParam = "%{$fQ}%";
        $params[] = $qParam; $params[] = $qParam;
        $types .= 'ss';
    }
    $whereStr = implode(' AND ', $where);

    $limit = $forExport ? '' : ' LIMIT 200';
    $sql = "SELECT d.id, d.no_urut, d.unit_kerja, d.kode_risiko, d.nama_risiko,
                   d.probabilitas, d.dampak, d.bobot, d.nilai, d.tingkat_risiko,
                   d.prioritas_risiko, d.rencana_penanganan, d.jadwal_pelaksanaan,
                   d.penanggungjawab, d.target_p, d.target_d, d.target_bobot,
                   d.target_nilai, d.target_tingkat_risiko,
                   p.tahun, p.unit_pemilik_risiko, p.nama_pemilik_risiko,
                   p.tgl_penilaian, u.nama AS user_nama
            FROM profil_risiko_detail d
            JOIN profil_risiko p ON d.id_profil = p.id
            LEFT JOIN users u ON p.created_by = u.id
            WHERE $whereStr
             ORDER BY UPPER(SUBSTRING_INDEX(d.kode_risiko, '.', 1)) ASC,
                      LPAD(SUBSTRING_INDEX(d.kode_risiko, '.', -1), 12, '0') ASC,
                      d.kode_risiko ASC, p.tahun DESC, d.id ASC
            $limit";
    return [$sql, $params, $types];
}

function buildKkprQuery(string $useUserFilter, $scopeUser, string $fTahun, string $fUnit, string $fLevel, string $fQ, bool $forExport = false): array {
    $where = ['1=1'];
    $params = [];
    $types = '';

    if ($useUserFilter) { $where[] = 'h.created_by = ?'; $params[] = $scopeUser; $types .= 'i'; }
    if ($fTahun) { $where[] = 'h.tahun = ?'; $params[] = $fTahun; $types .= 's'; }
    if ($fUnit)  { $where[] = 'h.unit_pemilik_risiko = ?'; $params[] = $fUnit; $types .= 's'; }
    if ($fLevel) {
        if ($fLevel === 'tinggi_plus') { $where[] = 'CAST(r.nilai_risiko AS DECIMAL(8,2)) >= 15'; }
        else { $where[] = 'r.tingkat_risiko = ?'; $params[] = $fLevel; $types .= 's'; }
    }
    if ($fQ) {
        $where[] = '(r.nama_risiko LIKE ? OR r.kode_risiko LIKE ?)';
        $qParam = "%{$fQ}%";
        $params[] = $qParam; $params[] = $qParam;
        $types .= 'ss';
    }
    $whereStr = implode(' AND ', $where);

    $limit = $forExport ? '' : ' LIMIT 200';
    $sql = "SELECT r.id, r.no_urut, r.nama_risiko, r.kode_risiko, r.sebab, r.sumber,
                   r.c_uc, r.dampak_uraian, r.pengendalian_uraian, r.pengendalian_jenis,
                   r.pengendalian_efektivitas, r.probabilitas, r.dampak_level, r.bobot,
                   r.nilai_risiko, r.tingkat_risiko, r.prioritas_risiko, r.selera_risiko,
                   r.pilihan_penanganan, r.evaluasi_warna, r.rpti_uraian, r.rpti_jadwal,
                   r.target_p, r.target_d, r.target_bobot, r.target_nilai, r.target_tingkat,
                   h.tahun, h.unit_pemilik_risiko, h.nama_pemilik_risiko,
                   h.tgl_penilaian, u.nama AS user_nama
            FROM kkpr_risiko r
            JOIN kkpr_header h ON r.id_kkpr = h.id
            LEFT JOIN users u ON h.created_by = u.id
            WHERE $whereStr
            ORDER BY h.tahun DESC, SUBSTRING_INDEX(r.kode_risiko, '.', 1) ASC, CAST(SUBSTRING_INDEX(r.kode_risiko, '.', -1) AS UNSIGNED) ASC, r.no_urut ASC, r.id ASC
            $limit";
    return [$sql, $params, $types];
}

function buildKkpmrQuery(string $useUserFilter, $scopeUser, string $fTahun, string $fUnit, string $fSimpu, string $fQ, bool $forExport = false): array {
    $where = ['r.pantau_p IS NOT NULL'];
    $params = [];
    $types = '';

    if ($useUserFilter) { $where[] = 'h.created_by = ?'; $params[] = $scopeUser; $types .= 'i'; }
    if ($fTahun) { $where[] = 'h.tahun = ?'; $params[] = $fTahun; $types .= 's'; }
    if ($fUnit)  { $where[] = 'h.unit_pemilik_risiko = ?'; $params[] = $fUnit; $types .= 's'; }
    if ($fSimpu) { $where[] = 'r.simpulan = ?'; $params[] = $fSimpu; $types .= 's'; }
    if ($fQ) {
        $where[] = '(r.nama_risiko LIKE ? OR r.kode_risiko LIKE ?)';
        $qParam = "%{$fQ}%";
        $params[] = $qParam; $params[] = $qParam;
        $types .= 'ss';
    }
    $whereStr = implode(' AND ', $where);

    $limit = $forExport ? '' : ' LIMIT 200';
    $sql = "SELECT r.id, r.no_urut, r.nama_risiko, r.kode_risiko,
                   r.probabilitas AS awal_p, r.dampak_level AS awal_d,
                   r.bobot AS awal_bobot, r.nilai_risiko AS awal_nilai,
                   r.tingkat_risiko AS awal_tingkat,
                   r.pantau_p, r.pantau_d, r.pantau_bobot, r.pantau_nilai,
                   r.pantau_tingkat, r.simpulan, r.efektifitas,
                   r.pengendalian_uraian, r.rpti_uraian, r.rpti_jadwal,
                   h.tahun, h.unit_pemilik_risiko, h.nama_pemilik_risiko,
                   h.tgl_update, h.periode_risiko, u.nama AS user_nama
            FROM kkpr_risiko r
            JOIN kkpr_header h ON r.id_kkpr = h.id
            LEFT JOIN users u ON h.created_by = u.id
            WHERE $whereStr
            ORDER BY h.tahun DESC, SUBSTRING_INDEX(r.kode_risiko, '.', 1) ASC, CAST(SUBSTRING_INDEX(r.kode_risiko, '.', -1) AS UNSIGNED) ASC, r.no_urut ASC, r.id ASC
            $limit";
    return [$sql, $params, $types];
}

// â”€â”€ EKSEKUSI QUERY BERDASARKAN TAB â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($tab === 'profil') {
    [$sql, $params, $types] = buildProfilQuery($useUserFilter, $scopeUser, $fTahun, $fUnit, $fLevel, $fQ, $export !== '');
} elseif ($tab === 'kkpr') {
    [$sql, $params, $types] = buildKkprQuery($useUserFilter, $scopeUser, $fTahun, $fUnit, $fLevel, $fQ, $export !== '');
} else {
    [$sql, $params, $types] = buildKkpmrQuery($useUserFilter, $scopeUser, $fTahun, $fUnit, $fSimpu, $fQ, $export !== '');
}

$stmt = $db->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($rows as &$r) {
    if (isset($r['user_nama']) && $r['user_nama'] === null) $r['user_nama'] = '-';
    if (isset($r['departemen']) && $r['departemen'] === 'Administrasi Umum') {
        $r['departemen'] = 'Kepala Subbagian Administrasi Umum';
    }
}
unset($r);

// â”€â”€ EXPORT (Excel & PDF) â€” Format sama persis dengan modul asli â”€
if ($export === 'excel' || $export === 'pdf') {
    $periode = $_GET['periode'] ?? 'tahunan';
    if (!in_array($periode, ['tahunan','tw1','tw2','tw3','tw4'], true)) $periode = 'tahunan';
    $triwulanRomawi = ['I','II','III','IV'];
    $periodeLabel = $periode === 'tahunan' ? 'Laporan Tahunan' : 'Laporan Triwulan ' . $triwulanRomawi[(int)substr($periode, 2) - 1];
    $GLOBALS['EXPORT_PERIODE'] = $periodeLabel;

    // Jangan biarkan browser/proxy menyajikan hasil laporan konsolidasi lama.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    // 1. Ambil daftar header yang match filter
    $hWhere = [];
    $hParams = [];
    $hTypes = '';
    if ($useUserFilter) { $hWhere[] = 'created_by = ?'; $hParams[] = $scopeUser; $hTypes .= 'i'; }
    if ($fTahun) { $hWhere[] = 'tahun = ?'; $hParams[] = $fTahun; $hTypes .= 's'; }
    if ($fUnit) {
        if ($tab === 'profil') {
            $hWhere[] = 'id IN (SELECT DISTINCT id_profil FROM profil_risiko_detail WHERE unit_kerja = ?)';
        } else {
            $hWhere[] = 'unit_pemilik_risiko = ?';
        }
        $hParams[] = $fUnit; $hTypes .= 's';
    }
    $hWhereStr = implode(' AND ', $hWhere) ?: '1=1';

    $headerTable = ($tab === 'profil') ? 'profil_risiko' : 'kkpr_header';
    $hSql = "SELECT * FROM $headerTable WHERE $hWhereStr ORDER BY tahun DESC, unit_pemilik_risiko ASC, id ASC";
    $hStmt = $db->prepare($hSql);
    if ($hTypes) $hStmt->bind_param($hTypes, ...$hParams);
    $hStmt->execute();
    $headerRows = $hStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $hStmt->close();

    // Ambil nama user untuk setiap header
    $userNames = [];
    foreach ($headerRows as $hr) {
        $uid = (int)($hr['created_by'] ?? 0);
        if ($uid && !isset($userNames[$uid])) {
            $us = $db->prepare('SELECT nama FROM users WHERE id=?');
            $us->bind_param('i', $uid); $us->execute();
            $ur = $us->get_result()->fetch_assoc(); $us->close();
            $userNames[$uid] = $ur['nama'] ?? '-';
        }
    }

    // Untuk KKPMR: filter header yang punya data pantau
    if ($tab === 'kkpmr') {
        $filtered = [];
        foreach ($headerRows as $hr) {
            $chk = $db->prepare('SELECT COUNT(*) FROM kkpr_risiko WHERE id_kkpr=? AND pantau_p IS NOT NULL');
            $chk->bind_param('i', $hr['id']); $chk->execute();
            $cnt = (int)$chk->get_result()->fetch_row()[0]; $chk->close();
            if ($cnt > 0) $filtered[] = $hr;
        }
        $headerRows = $filtered;
    }

    // 2. Ambil detail per header
    $allDocs = [];
    foreach ($headerRows as $hr) {
        if ($tab === 'profil') {
            $ds = $db->prepare("SELECT * FROM profil_risiko_detail WHERE id_profil=? ORDER BY SUBSTRING_INDEX(kode_risiko, '.', 1) ASC, CAST(SUBSTRING_INDEX(kode_risiko, '.', -1) AS UNSIGNED) ASC, no_urut, id");
        } else {
            $ds = $db->prepare("SELECT * FROM kkpr_risiko WHERE id_kkpr=? ORDER BY SUBSTRING_INDEX(kode_risiko, '.', 1) ASC, CAST(SUBSTRING_INDEX(kode_risiko, '.', -1) AS UNSIGNED) ASC, no_urut, id");
        }
        $ds->bind_param('i', $hr['id']); $ds->execute();
        $details = $ds->get_result()->fetch_all(MYSQLI_ASSOC); $ds->close();

        // Filter level per detail
        if ($fLevel && $tab !== 'kkpmr') {
            $details = array_filter($details, function($d) use ($fLevel) {
                $lvl = $tab === 'profil' ? ($d['tingkat_risiko'] ?? '') : ($d['tingkat_risiko'] ?? '');
                if ($fLevel === 'tinggi_plus') {
                    $nilai = (float)($tab === 'profil' ? ($d['nilai'] ?? 0) : ($d['nilai_risiko'] ?? 0));
                    return $nilai >= 15;
                }
                return $lvl === $fLevel;
            });
            $details = array_values($details);
        }
        if ($fSimpu && $tab === 'kkpmr') {
            $details = array_filter($details, fn($d) => ($d['simpulan'] ?? '') === $fSimpu);
            $details = array_values($details);
        }

        if (!empty($details)) {
            $indicatorRows = [];
            $indicatorStmt = $db->prepare('SELECT tahun,program,kegiatan,sasaran,indikator,target,satuan FROM profil_risiko_indikator WHERE id_profil=? ORDER BY id');
            if ($indicatorStmt) {
                $indicatorStmt->bind_param('i', $hr['id']); $indicatorStmt->execute();
                $indicatorRows = $indicatorStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $indicatorStmt->close();
            }
            $allDocs[] = ['header' => $hr, 'details' => $details, 'indicator_rows' => $indicatorRows];
        }
    }

    // Profil konsolidasi memakai satu urutan kode risiko untuk semua user.
    $mergedProfilDetails = [];
    if ($tab === 'profil') {
        foreach ($allDocs as $doc) {
            foreach ($doc['details'] as $detail) {
                $mergedProfilDetails[] = $detail;
            }
        }
        usort($mergedProfilDetails, static function (array $a, array $b): int {
            $codeA = trim((string)($a['kode_risiko'] ?? ''));
            $codeB = trim((string)($b['kode_risiko'] ?? ''));
            $prefixA = strtoupper((string)strtok($codeA, '.'));
            $prefixB = strtoupper((string)strtok($codeB, '.'));
            $prefixCompare = strnatcasecmp($prefixA, $prefixB);
            if ($prefixCompare !== 0) return $prefixCompare;

            $partsA = array_map('intval', array_slice(explode('.', $codeA), 1));
            $partsB = array_map('intval', array_slice(explode('.', $codeB), 1));
            foreach (range(0, max(count($partsA), count($partsB)) - 1) as $index) {
                $partCompare = ($partsA[$index] ?? 0) <=> ($partsB[$index] ?? 0);
                if ($partCompare !== 0) return $partCompare;
            }
            return strnatcasecmp($codeA, $codeB);
        });
    }

    // 3a. EXPORT EXCEL â€” kolom sama persis dengan modul asli
    if ($export === 'excel') {
        if ($tab === 'profil') {
            $headers = ['NO', 'UNIT KERJA PEMILIK RISIKO', 'RISIKO', 'KODE RISIKO', 'P', 'D', 'BOBOT', 'NILAI', 'TINGKAT RISIKO', 'PRIORITAS RISIKO', 'URAIAN PENGENDALIAN', 'JADWAL PELAKSANAAN', 'PENANGGUNGJAWAB', 'P (TARGET)', 'D (TARGET)', 'BOBOT (TARGET)', 'NILAI (TARGET)', 'TINGKAT RISIKO (TARGET)'];
            $excelRows = [];
            $excelNo = 0;
            foreach ($mergedProfilDetails as $dr) {
                $excelNo++;
                $excelRows[] = [
                        $excelNo,
                        $dr['unit_kerja'] ?? '',
                        $dr['nama_risiko'] ?? '',
                        $dr['kode_risiko'] ?? '',
                        $dr['probabilitas'] ?? '',
                        $dr['dampak'] ?? '',
                        $dr['bobot'] ?? '',
                        $dr['nilai'] ?? '',
                        $dr['tingkat_risiko'] ?? '',
                        $dr['prioritas_risiko'] ?? '',
                        $dr['rencana_penanganan'] ?? '',
                        $dr['jadwal_pelaksanaan'] ?? '',
                        $dr['penanggungjawab'] ?? '',
                        $dr['target_p'] ?? '',
                        $dr['target_d'] ?? '',
                        $dr['target_bobot'] ?? '',
                        $dr['target_nilai'] ?? '',
                        $dr['target_tingkat_risiko'] ?? '',
                ];
            }
            exportExcel('profil_risiko_konsolidasi_' . date('Ymd_His'), $headers, $excelRows);
        } elseif ($tab === 'kkpr') {
            $headers = ['No', 'Kode Risiko', 'Nama Risiko', 'Sebab', 'Sumber Risiko', 'C/UC', 'Dampak', 'Pengendalian Uraian', 'Efektif', 'Tidak', 'P', 'D', 'Bobot', 'Nilai', 'Tingkat', 'Prioritas', 'Selera Risiko', 'Pilihan Penanganan', 'RPR Uraian', 'RPR Jadwal', 'Target P', 'Target D', 'Target Bobot', 'Target Nilai', 'Target Tingkat'];
            $excelRows = [];
            $excelNo = 0;
            foreach ($allDocs as $doc) {
                foreach ($doc['details'] as $r) {
                    $excelNo++;
                    $excelRows[] = [
                        $excelNo,
                        $r['kode_risiko'] ?? '',
                        $r['nama_risiko'] ?? '',
                        $r['sebab'] ?? '',
                        normalizeSumberRisiko($r['sumber'] ?? ''),
                        $r['c_uc'] ?? '',
                        $r['dampak_uraian'] ?? '',
                        $r['pengendalian_uraian'] ?? '',
                        $r['pengendalian_jenis'] ?? '',
                        $r['pengendalian_efektivitas'] ?? '',
                        $r['probabilitas'] ?? '',
                        $r['dampak_level'] ?? '',
                        $r['bobot'] ?? '',
                        $r['nilai_risiko'] ?? '',
                        $r['tingkat_risiko'] ?? '',
                        $r['prioritas_risiko'] ?? '',
                        $r['selera_risiko'] ?? '',
                        $r['pilihan_penanganan'] ?? '',
                        $r['rpti_uraian'] ?? '',
                        $r['rpti_jadwal'] ?? '',
                        $r['target_p'] ?? '',
                        $r['target_d'] ?? '',
                        $r['target_bobot'] ?? '',
                        $r['target_nilai'] ?? '',
                        $r['target_tingkat'] ?? '',
                    ];
                }
            }
            exportExcel('kkpr_konsolidasi_' . date('Ymd_His'), $headers, $excelRows);
        } else {
            $headers = ['No', 'Risiko', 'Kode', 'P Awal', 'D Awal', 'Bobot Awal', 'Nilai Awal', 'Tingkat Awal', 'Prioritas', 'Pengendalian', 'Jadwal', 'P Pantau', 'D Pantau', 'Bobot Pantau', 'Nilai Pantau', 'Tingkat Pantau', 'Simpulan', 'Efektifitas'];
            $excelRows = [];
            $excelNo = 0;
            foreach ($allDocs as $doc) {
                foreach ($doc['details'] as $r) {
                    $excelNo++;
                    $excelRows[] = [
                        $excelNo,
                        $r['nama_risiko'] ?? '',
                        $r['kode_risiko'] ?? '',
                        $r['probabilitas'] ?? '',
                        $r['dampak_level'] ?? '',
                        $r['bobot'] ?? '',
                        $r['nilai_risiko'] ?? '',
                        $r['tingkat_risiko'] ?? '',
                        $r['prioritas_risiko'] ?? '',
                        $r['rpti_uraian'] ?? '',
                        $r['rpti_jadwal'] ?? '',
                        $r['pantau_p'] ?? '',
                        $r['pantau_d'] ?? '',
                        $r['pantau_bobot'] ?? '',
                        $r['pantau_nilai'] ?? '',
                        $r['pantau_tingkat'] ?? '',
                        $r['simpulan'] ?? '',
                        $r['efektifitas'] ?? '',
                    ];
                }
            }
            exportExcel('kkpmr_konsolidasi_' . date('Ymd_His'), $headers, $excelRows);
        }
    }

    // 3b. EXPORT PDF â€” format sama persis dengan PDF modul asli
    if ($export === 'pdf') {
        $tabTitle = $tab === 'profil' ? 'Profil Risiko' : ($tab === 'kkpr' ? 'KKPR' : 'KKPMR');

        // Fungsi warna tingkat (sama dengan modul asli)
        $fnColor = function(string $t): array {
            return match($t) {
                'Sangat Tinggi' => ['#dc2626', '#fff'],
                'Tinggi'        => ['#f97316', '#fff'],
                'Sedang'        => ['#FFFF00', '#000'],
                'Rendah'        => ['#22c55e', '#fff'],
                default         => ['#3b82f6', '#fff'],
            };
        };
        ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Laporan Konsolidasi <?= $tabTitle ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:8.5px}
body{font-family:Arial,Helvetica,sans-serif;color:#000;background:#fff;padding:12px 14px;line-height:1.25}
.print-bar{display:flex;gap:10px;align-items:center;padding:7px 12px;background:#eff6ff;border:1px dashed #93c5fd;border-radius:6px;margin-bottom:10px;font-size:11px}
.btn-print{padding:6px 14px;background:#1e3a5f;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:11px;font-weight:700}
.judul{text-align:center;font-size:10px;font-weight:900;text-transform:uppercase;padding:6px;border:2px solid #000;letter-spacing:.03em} .periode-box{text-align:center;font-size:9px;font-weight:700;padding:4px;border:1px solid #000;border-top:none;background:#f0f9ff;letter-spacing:.05em}
.info-table{width:100%;border-collapse:collapse;font-size:8px;border:1px solid #000;border-top:none;margin-bottom:0}
.info-table td{border:1px solid #ccc;padding:3px 5px;vertical-align:top}
.info-table .info-lbl{width:130px;font-weight:700;background:#f0f0f0}
.info-table .info-sep{border-left:2px solid #000}
.tbl-wrap{border:1px solid #000;border-top:none}
table{width:100%;border-collapse:collapse;font-size:7.5px}
th,td{border:1px solid #999;padding:2px 3px;vertical-align:top}
.th-main{background:#1e3a5f;color:#fff;font-weight:700;text-align:center;font-size:7px;white-space:nowrap}
.th-identifikasi,.th-awal{background:#c8d8e8;font-weight:700;text-align:center;font-size:7px}
.th-group{background:#b8d4e8;font-weight:700;text-align:center;font-size:8px}
.th-analisis{background:#fde68a;font-weight:700;text-align:center;font-size:7px}
.th-evaluasi{background:#fca5a5;font-weight:700;text-align:center;font-size:7px}
.th-rpti{background:#a7f3d0;font-weight:700;text-align:center;font-size:7px}
.th-target{background:#c4b5fd;font-weight:700;text-align:center;font-size:7px}
.th-pengendalian{background:#fde68a;font-weight:700;text-align:center;font-size:7px}
.th-pantau{background:#a7f3d0;font-weight:700;text-align:center;font-size:7px}
.th-simpul{background:#fca5a5;font-weight:700;text-align:center;font-size:7px}
.badge-t{display:inline-block;padding:1px 4px;border-radius:2px;font-weight:700;font-size:7px;text-align:center;white-space:nowrap}
.badge-tingkat{display:inline-block;padding:1px 6px;border-radius:3px;font-weight:700;font-size:8px;text-align:center}
.td-center{text-align:center}
.ttd-area{display:grid;grid-template-columns:1fr 1fr;border:1px solid #000;border-top:none}
.ttd-box{padding:8px 12px;min-height:90px;border-right:1px solid #000}
.ttd-box:last-child{border-right:none}
.ttd-lbl{font-size:8px;color:#444;margin-bottom:3px}
.ttd-img{height:42px;margin:3px 0}
.ttd-nm{font-size:9px;font-weight:700;margin-top:2px}
.ttd-nip{font-size:8px;color:#333}
.doc-sep{page-break-after:always;break-after:page}
.doc-sep:last-child{page-break-after:auto;break-after:auto}
.kop-konsol{display:flex;justify-content:space-between;align-items:center;border-bottom:3px solid #1e3a5f;padding-bottom:10px;margin-bottom:14px}
.kop-konsol-title{font-size:14px;font-weight:800;color:#1e3a5f}
.kop-konsol-sub{font-size:9px;color:#64748b;margin-top:2px}
.kop-konsol-meta{text-align:right;font-size:9px;color:#64748b}
.kop-konsol-meta strong{color:#1e3a5f}
@page{size:A3 landscape;margin:8mm}
@media print{body{padding:0}.print-bar{display:none!important}}
</style>
</head>
<body>
<div class="print-bar">
  <button class="btn-print" onclick="window.print()">&#128196; Cetak / PDF</button>
  <span style="color:#1d4ed8"><strong>Ctrl+P</strong> &rarr; A3 Landscape | <?= count($allDocs) ?> dokumen</span>
</div>

<div class="kop-konsol">
  <div>
    <div class="kop-konsol-title"><?= APP_NAME ?></div>
    <div class="kop-konsol-sub">Laporan Konsolidasi &ndash; <?= $tabTitle ?> (<?= count($allDocs) ?> dokumen)</div>
  </div>
  <div class="kop-konsol-meta">
    Dicetak: <strong><?= date('d/m/Y H:i:s') ?></strong><br>
    Total: <strong><?= count($allDocs) ?> dokumen</strong>
  </div>
</div>

<?php
$firstH = !empty($allDocs) ? $allDocs[0]['header'] : [];
$consolUsers = [];
$consolUnits = [];
$consolIndikator = [];
$consolTarget = [];
foreach ($allDocs as $__doc) {
    $__h = $__doc['header'];
    $__uid = (int)($__h['created_by'] ?? 0);
    $__uname = $userNames[$__uid] ?? '-';
    if (!in_array($__uname, $consolUsers)) $consolUsers[] = $__uname;
    $__unit = $__h['unit_pemilik_risiko'] ?? '-';
    if (!in_array($__unit, $consolUnits)) $consolUnits[] = $__unit;
    $__indicatorRows = $__doc['indicator_rows'] ?? [];
    if ($__indicatorRows) {
        foreach ($__indicatorRows as $__indicatorRow) {
            $__indikator = trim((string)($__indicatorRow['indikator'] ?? ''));
            if ($__indikator !== '' && !in_array($__indikator, $consolIndikator, true)) $consolIndikator[] = $__indikator;
            $__target = trim((string)($__indicatorRow['target'] ?? ''));
            if ($__target !== '' && !in_array($__target, $consolTarget, true)) $consolTarget[] = $__target;
        }
    } else {
        $__indikator = trim((string)($__h['indikator_kinerja'] ?? ''));
        if ($__indikator !== '' && !in_array($__indikator, $consolIndikator, true)) $consolIndikator[] = $__indikator;
        $__target = trim((string)($__h['target'] ?? ''));
        if ($__target !== '' && !in_array($__target, $consolTarget, true)) $consolTarget[] = $__target;
    }
}
$consolIndikatorNumbered = [];
foreach ($consolIndikator as $idx => $ind) {
    $consolIndikatorNumbered[] = ($idx + 1) . '. ' . $ind;
}
$consolIndikatorText = implode("\n", $consolIndikatorNumbered);

$consolTargetNumbered = [];
foreach ($consolTarget as $idx => $tgt) {
    $consolTargetNumbered[] = ($idx + 1) . '. ' . $tgt;
}
$consolTargetText = implode("\n", $consolTargetNumbered);
?>
<?php if (!empty($allDocs)): ?>
<div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:6px;padding:6px 10px;margin-bottom:8px;font-size:9px;color:#1e3a5f">
  <strong>Konsolidasi <?= $tabTitle ?></strong> &ndash;
  <?= count($allDocs) ?> dokumen |
  User: <strong><?= xss(implode(', ', $consolUsers)) ?></strong> |
  Unit: <strong><?= xss(implode(', ', $consolUnits)) ?></strong>
</div>
<?php endif; ?>

<?php if ($tab === 'profil'): ?>
<!-- ====== FORMAT PROFIL RISIKO ====== -->
<div class="judul">PROFIL RISIKO TINGKAT UNIT PEMILIK RISIKO TINGKAT II (UPR-T.II) KEMENTERIAN KESEHATAN</div>
  <div class="periode-box">
    PERIODE LAPORAN: <?= strtoupper(xss($periodeLabel)) ?> - TAHUN <?= xss($fTahun ?: date('Y')) ?> &nbsp;|&nbsp; DICETAK: <?= date('d-m-Y') ?>
  </div>
<table class="info-table">
  <tbody>
    <tr><td class="info-lbl">Tujuan</td><td><?= nl2br(xss($firstH['tujuan']??'-')) ?></td><td class="info-lbl info-sep">Unit Pemilik Risiko</td><td><?= xss($firstH['unit_pemilik_risiko']??'-') ?></td></tr>
    <tr><td class="info-lbl">Sasaran</td><td><?= nl2br(xss($firstH['sasaran']??'-')) ?></td><td class="info-lbl info-sep">Nama Pemilik Risiko</td><td><?= xss($firstH['nama_pemilik_risiko']??'-') ?></td></tr>
    <tr><td class="info-lbl">Indikator Kinerja Kegiatan</td><td><?= nl2br(xss($consolIndikatorText ?: '-')) ?></td><td class="info-lbl info-sep">Nama Tim Pengelola Risiko</td><td><?= xss($firstH['nama_pengelola_risiko']??'-') ?></td></tr>
    <tr><td class="info-lbl">Target</td><td><?= nl2br(xss($consolTargetText ?: '-')) ?></td><td class="info-lbl info-sep">Tgl Penilaian Risiko</td><td><?= tglIndo($firstH['tgl_penilaian']??'') ?></td></tr>
    <tr><td class="info-lbl">Program</td><td><?= nl2br(xss($firstH['program']??'-')) ?></td><td class="info-lbl info-sep">Periode Risiko</td><td><?= xss($firstH['periode_risiko']??'-') ?></td></tr>
    <tr><td class="info-lbl">Kegiatan</td><td><?= nl2br(xss($firstH['kegiatan']??'-')) ?></td><td class="info-lbl info-sep">Tgl Update Risiko</td><td><?= tglIndo($firstH['tgl_update']??'') ?></td></tr>
  </tbody>
</table>
<div class="tbl-wrap">
<table>
  <thead>
    <tr>
      <th rowspan="2" style="width:22px">NO</th>
      <th rowspan="2" style="min-width:100px">UNIT KERJA PEMILIK RISIKO</th>
      <th rowspan="2" style="min-width:120px">RISIKO</th>
      <th rowspan="2" style="width:30px">KODE RISIKO</th>
      <th colspan="5" class="th-group">KONDISI RISIKO SAAT INI</th>
      <th rowspan="2" style="width:30px">PRIORITAS RISIKO</th>
      <th rowspan="2" style="min-width:130px">URAIAN PENGENDALIAN</th>
      <th rowspan="2" style="min-width:70px">JADWAL PELAKSANAAN</th>
      <th rowspan="2" style="min-width:80px">PENANGGUNGJAWAB</th>
      <th colspan="5" class="th-target">TARGET PENURUNAN TINGKAT RISIKO</th>
    </tr>
    <tr>
      <th class="th-group" style="width:18px">P</th><th class="th-group" style="width:18px">D</th><th class="th-group" style="width:28px">BOBOT</th><th class="th-group" style="width:25px">NILAI</th><th class="th-group" style="width:60px">TINGKAT RISIKO</th>
      <th class="th-target" style="width:18px">P</th><th class="th-target" style="width:18px">D</th><th class="th-target" style="width:28px">BOBOT</th><th class="th-target" style="width:25px">NILAI</th><th class="th-target" style="width:60px">TINGKAT RISIKO</th>
    </tr>
  </thead>
  <tbody>
  <?php if(empty($allDocs)): ?>
    <tr><td colspan="17" style="text-align:center;padding:20px;color:#999">Belum ada data risiko</td></tr>
  <?php else:
    $konsolNo = 0;
    foreach ($mergedProfilDetails as $dr):
        $konsolNo++;
        [$tkBg,$tkFg] = $fnColor($dr['tingkat_risiko']??'Rendah');
        [$ttkBg,$ttkFg] = $fnColor($dr['target_tingkat_risiko']??'Rendah');
  ?>
    <tr>
      <td class="td-center" style="font-weight:700"><?= $konsolNo ?></td>
      <td><?= xss($dr['unit_kerja']??'') ?></td>
      <td><?= xss($dr['nama_risiko']) ?></td>
      <td class="td-center"><code style="color:#1d4ed8"><?= xss($dr['kode_risiko']??'') ?></code></td>
      <td class="td-center" style="font-weight:700"><?= $dr['probabilitas'] ?></td>
      <td class="td-center" style="font-weight:700"><?= $dr['dampak'] ?></td>
      <td class="td-center" style="font-weight:700;color:#1d4ed8"><?= $dr['bobot'] ?></td>
      <td class="td-center" style="font-weight:700"><?= round((float)$dr['nilai']) ?></td>
      <td class="td-center"><span class="badge-tingkat" style="background:<?= $tkBg ?>;color:<?= $tkFg ?>"><?= xss($dr['tingkat_risiko']??'-') ?></span></td>
      <td class="td-center" style="font-weight:700"><?= $dr['prioritas_risiko'] ?></td>
      <td style="font-size:8px"><?= nl2br(xss($dr['rencana_penanganan']??'')) ?></td>
      <td style="font-size:8px"><?= xss($dr['jadwal_pelaksanaan']??'') ?></td>
      <td style="font-size:8px"><?= xss($dr['penanggungjawab']??'') ?></td>
      <td class="td-center" style="font-weight:700"><?= $dr['target_p'] ?></td>
      <td class="td-center" style="font-weight:700"><?= $dr['target_d'] ?></td>
      <td class="td-center" style="font-weight:700;color:#16a34a"><?= $dr['target_bobot'] ?></td>
      <td class="td-center" style="font-weight:700"><?= round((float)$dr['target_nilai']) ?></td>
      <td class="td-center"><span class="badge-tingkat" style="background:<?= $ttkBg ?>;color:<?= $ttkFg ?>"><?= xss($dr['target_tingkat_risiko']??'-') ?></span></td>
    </tr>
    <?php
    endforeach;
    ?>
  <?php endif; ?>
  </tbody>
</table>
</div>

<?php elseif ($tab === 'kkpr'): ?>
<!-- ====== FORMAT KKPR ====== -->
<div class="judul">KERTAS KERJA PENILAIAN RISIKO TINGKAT UNIT PEMILIK RISIKO TINGKAT II (UPR-T.II) KEMENTERIAN KESEHATAN</div>
  <div class="periode-box">
    PERIODE LAPORAN: <?= strtoupper(xss($periodeLabel)) ?> - TAHUN <?= xss($fTahun ?: date('Y')) ?> &nbsp;|&nbsp; DICETAK: <?= date('d-m-Y') ?>
  </div>
<table class="info-table">
  <tbody>
    <tr><td class="info-lbl">Tujuan</td><td><?= xss($firstH['tujuan']??'-') ?></td><td class="info-lbl info-sep">Unit Pemilik Risiko</td><td><?= xss($firstH['unit_pemilik_risiko']??'-') ?></td></tr>
    <tr><td class="info-lbl">Sasaran</td><td><?= nl2br(xss($firstH['sasaran']??'-')) ?></td><td class="info-lbl info-sep">Nama Pemilik Risiko</td><td><?= xss($firstH['nama_pemilik_risiko']??'-') ?></td></tr>
    <tr><td class="info-lbl">Indikator Kinerja Kegiatan</td><td><?= nl2br(xss($consolIndikatorText ?: '-')) ?></td><td class="info-lbl info-sep">Nama Pengelola Risiko</td><td><?= xss($firstH['nama_pengelola_risiko']??'-') ?></td></tr>
    <tr><td class="info-lbl">Target</td><td><?= nl2br(xss($consolTargetText ?: '-')) ?></td><td class="info-lbl info-sep">Tgl Penilaian Risiko</td><td><?= tglIndo($firstH['tgl_penilaian']??'') ?></td></tr>
    <tr><td class="info-lbl">Program</td><td><?= xss($firstH['program']??'-') ?></td><td class="info-lbl info-sep">Periode Risiko</td><td><?= xss($firstH['periode_risiko']??'-') ?></td></tr>
    <tr><td class="info-lbl">Kegiatan</td><td><?= xss($firstH['kegiatan']??'-') ?></td><td class="info-lbl info-sep">Tgl Update Risiko</td><td><?= tglIndo($firstH['tgl_update']??'') ?></td></tr>
  </tbody>
</table>
<div class="tbl-wrap">
<table>
  <thead>
    <tr>
      <th class="th-main" rowspan="3" style="width:16px">NO</th>
      <th class="th-identifikasi" colspan="6">IDENTIFIKASI RISIKO</th>
      <th class="th-analisis" colspan="8">ANALISIS RISIKO</th>
      <th class="th-evaluasi" colspan="3">EVALUASI RISIKO</th>
      <th class="th-rpti" colspan="2">RENCANA PENANGANAN RISIKO (RPR)</th>
      <th class="th-target" colspan="5" rowspan="2">TARGET PENURUNAN TINGKAT RISIKO</th>
    </tr>
    <tr>
      <th class="th-identifikasi" rowspan="2" style="min-width:80px">RISIKO</th>
      <th class="th-identifikasi" rowspan="2" style="width:28px">KODE RISIKO</th>
      <th class="th-identifikasi" rowspan="2" style="min-width:70px">SEBAB</th>
      <th class="th-identifikasi" rowspan="2" style="width:34px">SUMBER RISIKO</th>
      <th class="th-identifikasi" rowspan="2" style="width:18px">C/UC</th>
      <th class="th-identifikasi" rowspan="2" style="min-width:70px">DAMPAK</th>
      <th class="th-analisis" colspan="3">PENGENDALIAN YANG ADA</th>
      <th class="th-analisis" rowspan="2" style="width:14px">P</th>
      <th class="th-analisis" rowspan="2" style="width:14px">D</th>
      <th class="th-analisis" rowspan="2" style="width:22px">BOBOT</th>
      <th class="th-analisis" rowspan="2" style="width:22px">NILAI</th>
      <th class="th-analisis" rowspan="2" style="width:50px">TINGKAT RISIKO</th>
      <th class="th-evaluasi" rowspan="2" style="width:22px">PRIORITAS RISIKO</th>
      <th class="th-evaluasi" rowspan="2" style="width:45px">SELERA RISIKO</th>
      <th class="th-evaluasi" rowspan="2" style="min-width:65px">PILIHAN PENANGANAN RISIKO</th>
      <th class="th-rpti" rowspan="2" style="min-width:100px">URAIAN</th>
      <th class="th-rpti" rowspan="2" style="min-width:60px">JADWAL PELAKSANAAN</th>
    </tr>
    <tr>
      <th class="th-analisis" style="min-width:70px">URAIAN</th>
      <th class="th-analisis" style="width:25px">EFEKTIF</th>
      <th class="th-analisis" style="width:40px">TIDAK EFEKTIF</th>
      <th class="th-target" style="width:14px">P</th><th class="th-target" style="width:14px">D</th><th class="th-target" style="width:22px">BOBOT</th><th class="th-target" style="width:22px">NILAI</th><th class="th-target" style="width:50px">TINGKAT RISIKO</th>
    </tr>
  </thead>
  <tbody>
  <?php if(empty($allDocs)): ?>
    <tr><td colspan="25" style="text-align:center;padding:20px;color:#999">Belum ada data risiko</td></tr>
  <?php else:
    $konsolNo = 0;
    foreach ($allDocs as $__doc):
      foreach ($__doc['details'] as $r):
        $konsolNo++;
        [$bgT,$clT] = $fnColor($r['tingkat_risiko']??'Rendah');
        [$bgTT,$clTT] = $fnColor($r['target_tingkat']??'Rendah');
  ?>
    <tr>
      <td style="text-align:center;font-weight:700"><?= $konsolNo ?></td>
      <td><?= nl2br(xss($r['nama_risiko'])) ?></td>
      <td style="text-align:center"><span style="color:#1d4ed8;font-family:monospace"><?= xss($r['kode_risiko']??'') ?></span></td>
      <td><?= formatUraianList($r['sebab'] ?? '') ?></td>
      <td style="text-align:center"><span style="font-size:7px"><?= xss(normalizeSumberRisiko($r['sumber'] ?? '')) ?></span></td>
      <td style="text-align:center;font-weight:700"><?= xss($r['c_uc']??'') ?></td>
      <td><?= formatUraianList($r['dampak_uraian'] ?? '') ?></td>
      <td><?= nl2br(xss($r['pengendalian_uraian']??'')) ?></td>
      <td style="text-align:center;font-weight:700"><?= ($r['pengendalian_efektivitas'] === 'E') ? 'V' : '' ?></td>
      <td style="text-align:center;font-size:7px"><?= ($r['pengendalian_efektivitas'] === 'TE' || $r['pengendalian_efektivitas'] === 'BE') ? ($r['pengendalian_jenis'] ? xss($r['pengendalian_jenis']) : 'V') : '' ?></td>
      <td style="text-align:center;font-weight:700"><?= $r['probabilitas'] ?></td>
      <td style="text-align:center;font-weight:700"><?= $r['dampak_level'] ?></td>
      <td style="text-align:center;font-weight:700"><?= $r['bobot'] ?></td>
      <td style="text-align:center;font-weight:800"><?= round((float)$r['nilai_risiko']) ?></td>
      <td style="text-align:center"><span class="badge-t" style="background:<?= $bgT ?>;color:<?= $clT ?>"><?= xss($r['tingkat_risiko']??'-') ?></span></td>
      <td style="text-align:center;font-weight:700"><?= $r['prioritas_risiko']?:'-' ?></td>
      <td style="text-align:center;font-size:7px;font-weight:700;<?php
        $sr=$r['selera_risiko']??'';
        if(str_contains($sr,'Dalam')) echo 'background:#ffff00;color:#000;';
        elseif(str_contains($sr,'Diatas')) echo 'background:#f97316;color:#fff;';
      ?>"><?= xss($sr ?: '-') ?></td>
      <td style="text-align:center;font-size:7px;font-weight:700;<?php
        $pp = $r['pilihan_penanganan'] ?? '';
        if (str_contains($pp,'Menerima')) echo 'background:#ffff00;color:#000;';
        elseif (str_contains($pp,'Mitigasi')) echo 'background:#ef4444;color:#fff;';
      ?>"><?= xss($pp ?: '-') ?></td>
      <td style="font-size:7px"><?= nl2br(xss($r['rpti_uraian']??'')) ?></td>
      <td style="font-size:7px"><?= xss($r['rpti_jadwal']??'') ?></td>
      <td style="text-align:center;font-weight:700"><?= $r['target_p'] ?></td>
      <td style="text-align:center;font-weight:700"><?= $r['target_d'] ?></td>
      <td style="text-align:center;font-weight:700"><?= $r['target_bobot'] ?></td>
      <td style="text-align:center;font-weight:800"><?= round((float)$r['target_nilai']) ?></td>
      <td style="text-align:center"><span class="badge-t" style="background:<?= $bgTT ?>;color:<?= $clTT ?>"><?= xss($r['target_tingkat']??'-') ?></span></td>
    </tr>
    <?php
      endforeach;
    endforeach;
    ?>
  <?php endif; ?>
  </tbody>
</table>
</div>

<?php else: ?>
<!-- ====== FORMAT KKPMR ====== -->
<div class="judul">KERTAS KERJA PEMANTAUAN DAN REVIU UNIT PEMILIK RISIKO TINGKAT II (UPR-T.II) KEMENTERIAN KESEHATAN</div>
  <div class="periode-box">
    PERIODE LAPORAN: <?= strtoupper(xss($periodeLabel)) ?> - TAHUN <?= xss($fTahun ?: date('Y')) ?> &nbsp;|&nbsp; DICETAK: <?= date('d-m-Y') ?>
  </div>
<table class="info-table">
  <tbody>
    <tr><td class="info-lbl">Tujuan</td><td><?= xss($firstH['tujuan'] ?? '-') ?></td><td class="info-lbl info-sep">Unit Pemilik Risiko</td><td><?= xss($firstH['unit_pemilik_risiko'] ?? '-') ?></td></tr>
    <tr><td class="info-lbl">Sasaran</td><td><?= nl2br(xss($firstH['sasaran'] ?? '-')) ?></td><td class="info-lbl info-sep">Nama Pemilik Risiko</td><td><?= xss($firstH['nama_pemilik_risiko'] ?? '-') ?></td></tr>
    <tr><td class="info-lbl">Indikator Kinerja Utama</td><td><?= nl2br(xss($consolIndikatorText ?: '-')) ?></td><td class="info-lbl info-sep">Nama Tim Pengelola Risiko</td><td><?= xss($firstH['nama_pengelola_risiko'] ?? '-') ?></td></tr>
    <tr><td class="info-lbl">Target</td><td><?= nl2br(xss($consolTargetText ?: '-')) ?></td><td class="info-lbl info-sep">Tgl Penilaian Risiko</td><td><?= tglIndo($firstH['tgl_penilaian'] ?? '') ?></td></tr>
    <tr><td class="info-lbl">Program</td><td><?= xss($firstH['program'] ?? '-') ?></td><td class="info-lbl info-sep">Periode Risiko</td><td><?= xss($firstH['periode_risiko'] ?? '-') ?></td></tr>
    <tr><td class="info-lbl">Kegiatan</td><td><?= xss($firstH['kegiatan'] ?? '-') ?></td><td class="info-lbl info-sep">Tgl Update Risiko</td><td><?= tglIndo($firstH['tgl_update'] ?? '') ?></td></tr>
  </tbody>
</table>
<div class="tbl-wrap">
<table>
  <thead>
    <tr>
      <th class="th-main" rowspan="2" style="width:18px">NO</th>
      <th class="th-awal" rowspan="2" style="min-width:80px">RISIKO</th>
      <th class="th-awal" rowspan="2" style="width:30px">KODE RISIKO</th>
      <th class="th-awal" colspan="5">PENILAIAN AWAL</th>
      <th class="th-awal" rowspan="2" style="width:22px">PRIORITAS</th>
      <th class="th-pengendalian" rowspan="2" style="min-width:70px">URAIAN PENGENDALIAN</th>
      <th class="th-pengendalian" rowspan="2" style="width:50px">JADWAL PELAKSANAAN</th>
      <th class="th-pantau" colspan="5">HASIL PEMANTAUAN</th>
      <th class="th-simpul" colspan="2">SIMPULAN</th>
    </tr>
    <tr>
      <th class="th-awal" style="width:14px">P</th><th class="th-awal" style="width:14px">D</th><th class="th-awal" style="width:22px">BOBOT</th><th class="th-awal" style="width:22px">NILAI</th><th class="th-awal" style="width:50px">TINGKAT RISIKO</th>
      <th class="th-pantau" style="width:14px">P</th><th class="th-pantau" style="width:14px">D</th><th class="th-pantau" style="width:22px">BOBOT</th><th class="th-pantau" style="width:22px">NILAI</th><th class="th-pantau" style="width:50px">TINGKAT RISIKO</th>
      <th class="th-simpul" style="width:60px">TINGKAT RISIKO</th>
      <th class="th-simpul" style="width:55px">EFEKTIFITAS</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($allDocs)): ?>
    <tr><td colspan="18" style="text-align:center;padding:20px;color:#999">Belum ada risiko</td></tr>
  <?php else:
    $konsolNo = 0;
    foreach ($allDocs as $__doc):
      foreach ($__doc['details'] as $r):
        $konsolNo++;
        [$awalBg, $awalFg] = $fnColor($r['tingkat_risiko'] ?? '');
        [$pmrBg, $pmrFg] = $r['pantau_tingkat'] ? $fnColor($r['pantau_tingkat']) : ['#fff', '#000'];
        $sCls = ''; $sText = '-';
        if (!empty($r['simpulan'])) {
            if ($r['simpulan'] === 'Penurunan') { $sCls = 'background:#dcfce7;'; $sText = 'Tingkat risiko mengalami penurunan'; }
            elseif ($r['simpulan'] === 'Peningkatan') { $sCls = 'background:#fee2e2;'; $sText = 'Tingkat risiko mengalami peningkatan'; }
            else { $sCls = 'background:#fefce8;'; $sText = 'Tidak ada penurunan tingkat risiko'; }
        }
        $eCls = ''; $eText = '-';
        if (!empty($r['efektifitas'])) {
            if ($r['efektifitas'] === 'Efektif') { $eCls = 'background:#dcfce7;'; $eText = 'Efektif'; }
            else { $eCls = 'background:#fee2e2;'; $eText = 'Tidak Efektif'; }
        }
  ?>
    <tr>
      <td style="text-align:center;font-weight:700"><?= $konsolNo ?></td>
      <td><?= xss($r['nama_risiko'] ?? '') ?></td>
      <td style="text-align:center"><?= xss($r['kode_risiko'] ?: '-') ?></td>
      <td style="text-align:center;font-weight:700"><?= (int)$r['probabilitas'] ?></td>
      <td style="text-align:center;font-weight:700"><?= (int)$r['dampak_level'] ?></td>
      <td style="text-align:center"><?= number_format($r['bobot'], 2) ?></td>
      <td style="text-align:center;font-weight:700"><?= round((float)$r['nilai_risiko']) ?></td>
      <td style="text-align:center"><span class="badge-t" style="background:<?= $awalBg ?>;color:<?= $awalFg ?>"><?= xss($r['tingkat_risiko'] ?: '-') ?></span></td>
      <td style="text-align:center"><?= (int)($r['prioritas_risiko'] ?? 0) ?: '-' ?></td>
      <td><?= xss($r['rpti_uraian'] ?: '-') ?></td>
      <td style="text-align:left;white-space:nowrap"><?= xss($r['rpti_jadwal'] ?: '-') ?></td>
      <td style="text-align:center;font-weight:700"><?= $r['pantau_p'] !== null ? (int)$r['pantau_p'] : '-' ?></td>
      <td style="text-align:center;font-weight:700"><?= $r['pantau_d'] !== null ? (int)$r['pantau_d'] : '-' ?></td>
      <td style="text-align:center"><?= $r['pantau_bobot'] !== null ? number_format($r['pantau_bobot'], 2) : '-' ?></td>
      <td style="text-align:center;font-weight:700"><?= $r['pantau_nilai'] !== null ? round((float)$r['pantau_nilai']) : '-' ?></td>
      <td style="text-align:center">
        <?php if (!empty($r['pantau_tingkat'])): ?>
          <span class="badge-t" style="background:<?= $pmrBg ?>;color:<?= $pmrFg ?>"><?= xss($r['pantau_tingkat']) ?></span>
        <?php else: ?> - <?php endif; ?>
      </td>
      <td style="text-align:center;font-size:7px;<?= $sCls ?>"><?= xss($sText) ?></td>
      <td style="text-align:center;font-size:7px;<?= $eCls ?>"><?= xss($eText) ?></td>
    </tr>
    <?php
      endforeach;
    endforeach;
    ?>
  <?php endif; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php if (empty($allDocs)): ?>
<div style="text-align:center;padding:40px;color:#94a3b8;font-size:14px">Tidak ada dokumen untuk filter yang dipilih.</div>
<?php else: ?>
<!-- TTD hanya di akhir dokumen konsolidasi -->
<div style="page-break-before:always;break-before:page;margin-top:30px">
  <?php
  // Status approval di PDF
  $pdfApprovalStatus = $approvalData['status'] ?? 'pending';
  if ($pdfApprovalStatus === 'disetujui'):
      $approverNama = $approvalData['approver_nama'] ?? '-';
      $approverNip  = $approvalData['approver_nip'] ?? '-';
      $approveDate  = tglIndo($approvalData['approved_at'] ?? '');
  ?>
  <div style="text-align:center;margin-bottom:16px">
    <span style="display:inline-block;padding:6px 20px;border-radius:6px;background:#dcfce7;color:#16a34a;font-size:11px;font-weight:700;border:2px solid #16a34a">
      <i class="fas fa-check-circle"></i> DISETUJUI OLEH PEMIMPIN
    </span>
  </div>
  <div style="text-align:center;font-size:10px;font-weight:700;margin-bottom:20px">Mengetahui & Menyetujui,</div>
  <div class="ttd-area" style="grid-template-columns:1fr">
    <div class="ttd-box" style="border-right:none;text-align:center">
      <div class="ttd-lbl">Pemimpin yang Menyetujui</div>
      <div style="height:55px;margin:5px 0"></div>
      <div class="ttd-nm"><?= xss($approverNama) ?></div>
      <div class="ttd-nip">NIP. <?= xss($approverNip) ?></div>
      <div style="font-size:8px;color:#64748b;margin-top:4px">Disetujui pada <?= xss($approveDate) ?></div>
    </div>
  </div>
  <?php elseif ($pdfApprovalStatus === 'ditolak'): ?>
  <div style="text-align:center;margin-bottom:16px">
    <span style="display:inline-block;padding:6px 20px;border-radius:6px;background:#fee2e2;color:#dc2626;font-size:11px;font-weight:700;border:2px solid #dc2626">
      <i class="fas fa-times-circle"></i> DITOLAK OLEH PEMIMPIN
    </span>
  </div>
  <?php if (!empty($approvalData['catatan'])): ?>
  <div style="font-size:9px;color:#64748b;text-align:center;margin-bottom:14px">Catatan: <?= xss($approvalData['catatan']) ?></div>
  <?php endif; ?>
  <div style="text-align:center;font-size:10px;font-weight:700;margin-bottom:20px">Mengetahui,</div>
  <div class="ttd-area">
    <div class="ttd-box">
      <div class="ttd-lbl">Pemilik Risiko</div>
      <div style="height:50px;margin:3px 0"></div>
      <div class="ttd-nm">_________________________</div>
      <div class="ttd-nip">NIP. _________________________</div>
    </div>
    <div class="ttd-box" style="border-right:none">
      <div class="ttd-lbl">Pengelola Risiko</div>
      <div style="height:50px;margin:3px 0"></div>
      <div class="ttd-nm">_________________________</div>
      <div class="ttd-nip">NIP. _________________________</div>
    </div>
  </div>
  <?php else: ?>
  <div style="text-align:center;margin-bottom:16px">
    <span style="display:inline-block;padding:6px 20px;border-radius:6px;background:#fef9c3;color:#ca8a04;font-size:11px;font-weight:700;border:2px solid #ca8a04">
      <i class="fas fa-clock"></i> MENUNGGU PERSETUJUAN PEMIMPIN
    </span>
  </div>
  <div style="text-align:center;font-size:10px;font-weight:700;margin-bottom:20px">Mengetahui,</div>
  <div class="ttd-area">
    <div class="ttd-box">
      <div class="ttd-lbl">Pemilik Risiko</div>
      <div style="height:50px;margin:3px 0"></div>
      <div class="ttd-nm">_________________________</div>
      <div class="ttd-nip">NIP. _________________________</div>
    </div>
    <div class="ttd-box" style="border-right:none">
      <div class="ttd-lbl">Pengelola Risiko</div>
      <div style="height:50px;margin:3px 0"></div>
      <div class="ttd-nm">_________________________</div>
      <div class="ttd-nip">NIP. _________________________</div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

</body>
</html>
        <?php
        exit;
    }
}

// ── Statistik per tab ─────────────────────────────────────────
$statKonsol = ['total' => count($rows), 'tinggi' => 0, 'sedang' => 0, 'rendah' => 0];
if ($tab === 'profil') {
    foreach ($rows as $r) {
        $lvl = $r['tingkat_risiko'] ?? '';
        if (in_array($lvl, ['Sangat Tinggi', 'Tinggi'], true)) $statKonsol['tinggi']++;
        elseif ($lvl === 'Sedang') $statKonsol['sedang']++;
        else $statKonsol['rendah']++;
    }
} elseif ($tab === 'kkpr') {
    foreach ($rows as $r) {
        $lvl = $r['tingkat_risiko'] ?? '';
        if (in_array($lvl, ['Sangat Tinggi', 'Tinggi'], true)) $statKonsol['tinggi']++;
        elseif ($lvl === 'Sedang') $statKonsol['sedang']++;
        else $statKonsol['rendah']++;
    }
} else {
    foreach ($rows as $r) {
        $s = $r['simpulan'] ?? '';
        if ($s === 'Peningkatan') $statKonsol['tinggi']++;
        elseif ($s === 'Tetap') $statKonsol['sedang']++;
        else $statKonsol['rendah']++;
    }
}

// Unit list untuk filter
$unitList = [];
if ($tab === 'profil') {
    $unitList = $db->query("SELECT DISTINCT d.unit_kerja FROM profil_risiko_detail d JOIN profil_risiko p ON d.id_profil=p.id WHERE d.unit_kerja IS NOT NULL AND d.unit_kerja != '' ORDER BY d.unit_kerja ASC")->fetch_all(MYSQLI_ASSOC) ?: [];
} else {
    $unitList = $db->query("SELECT DISTINCT unit_pemilik_risiko FROM kkpr_header WHERE unit_pemilik_risiko IS NOT NULL AND unit_pemilik_risiko != '' ORDER BY unit_pemilik_risiko ASC")->fetch_all(MYSQLI_ASSOC) ?: [];
}

$baseFilter = http_build_query(array_filter([
    'page' => 'laporan_konsolidasi',
    'tab'  => $tab,
    'tahun' => $fTahun,
    'user'  => $fUser,
    'level' => $fLevel,
    'unit'  => $fUnit,
    'simpulan' => $fSimpu,
], fn($v) => $v !== ''));
?>

<!-- ── PAGE CONTENT ──────────────────────────────────────────── -->
<div class="risiko-hero profil-risiko-hero" style="background:linear-gradient(115deg,#0f766e 0%,#115e59 55%,#134e4a 100%); align-items: flex-start !important; margin-bottom:18px">
  <div class="risiko-hero-copy">
    <div class="risiko-eyebrow"><i class="fas fa-layer-group"></i> Pelaporan</div>
    <h1 class="page-title">Laporan Konsolidasi</h1>
    <p class="page-sub" style="color:rgba(255,255,255,.8)">Gabungan data Profil Risiko, KKPR & KKPMR per user</p>
  </div>
  <div class="profil-hero-tools risiko-hero-tools-align">
    <div class="risiko-export-actions" style="margin-top:0">
      <a href="<?= APP_URL ?>/?<?= http_build_query(['page'=>'laporan_konsolidasi','tab'=>$tab,'export'=>'excel','_ts'=>time()] + array_filter(['tahun'=>$fTahun,'user'=>$fUser,'level'=>$fLevel,'unit'=>$fUnit,'simpulan'=>$fSimpu])) ?>" class="btn btn-hero-ghost"><i class="fas fa-file-excel"></i> Excel</a>
      <?php $basePdfUrl = APP_URL . '/?' . http_build_query(['page'=>'laporan_konsolidasi','tab'=>$tab,'export'=>'pdf','_ts'=>time()] + array_filter(['tahun'=>$fTahun,'user'=>$fUser,'level'=>$fLevel,'unit'=>$fUnit,'simpulan'=>$fSimpu])); ?>
      <select class="form-control hero-year-select" style="width: 155px !important; max-width: 155px !important; padding: 0 24px 0 14px !important; text-align-last: center !important;" onchange="if(this.value){window.open('<?= $basePdfUrl ?>&periode='+encodeURIComponent(this.value),'_blank');this.selectedIndex=0;}" aria-label="Cetak Laporan" title="Cetak Laporan per triwulan / tahunan">
          <option value="">&#128196; Cetak / PDF</option>
          <option value="tw1" style="text-align: left;">Laporan Triwulan I</option>
          <option value="tw2" style="text-align: left;">Laporan Triwulan II</option>
          <option value="tw3" style="text-align: left;">Laporan Triwulan III</option>
          <option value="tw4" style="text-align: left;">Laporan Triwulan IV</option>
          <option value="tahunan" style="text-align: left;">Laporan Tahunan</option>
      </select>
    </div>
  </div>

  <!-- Stat cards (glassmorphism inside hero) — label dinamis per tab -->
  <div class="stats-grid cols-4" style="width:100%;margin-top:20px;margin-bottom:0">
    <div class="stat-card stat-card-glass" style="--ga:#60a5fa;--ga-tint:rgba(96,165,250,.3);--ga-line:rgba(96,165,250,.45);--ga-glow:rgba(96,165,250,.3)">
      <div class="stat-icon"><i class="fas fa-database"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $statKonsol['total'] ?></div>
        <div class="stat-label">Total Data</div>
      </div>
    </div>
    <div class="stat-card stat-card-glass" style="--ga:#f87171;--ga-tint:rgba(248,113,113,.28);--ga-line:rgba(248,113,113,.5);--ga-glow:rgba(248,113,113,.32)">
      <div class="stat-icon"><i class="fas <?= $tab==='kkpmr' ? 'fa-arrow-trend-up' : 'fa-fire' ?>"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $statKonsol['tinggi'] ?></div>
        <div class="stat-label"><?= $tab==='kkpmr' ? 'Peningkatan' : 'Tinggi+' ?></div>
      </div>
    </div>
    <div class="stat-card stat-card-glass" style="--ga:#fbbf24;--ga-tint:rgba(251,191,36,.3);--ga-line:rgba(251,191,36,.5);--ga-glow:rgba(251,191,36,.3)">
      <div class="stat-icon"><i class="fas <?= $tab==='kkpmr' ? 'fa-equals' : 'fa-minus-circle' ?>"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $statKonsol['sedang'] ?></div>
        <div class="stat-label"><?= $tab==='kkpmr' ? 'Tetap' : 'Sedang' ?></div>
      </div>
    </div>
    <div class="stat-card stat-card-glass" style="--ga:#4ade80;--ga-tint:rgba(74,222,128,.28);--ga-line:rgba(74,222,128,.45);--ga-glow:rgba(74,222,128,.28)">
      <div class="stat-icon"><i class="fas <?= $tab==='kkpmr' ? 'fa-arrow-trend-down' : 'fa-check-circle' ?>"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $statKonsol['rendah'] ?></div>
        <div class="stat-label"><?= $tab==='kkpmr' ? 'Penurunan' : 'Rendah' ?></div>
      </div>
    </div>
  </div>
</div>

<!-- ── Tab Navigation ────────────────────────────────────────── -->
<div style="display:flex;gap:4px;margin-bottom:16px;border-bottom:2px solid var(--border)">
  <?php
  $tabs = [
      'profil' => ['icon' => 'fa-clipboard-list', 'label' => 'Profil Risiko'],
      'kkpr'   => ['icon' => 'fa-table',          'label' => 'KKPR'],
      'kkpmr'  => ['icon' => 'fa-chart-line',     'label' => 'KKPMR'],
  ];
  foreach ($tabs as $tkey => $tval):
      $tQuery = http_build_query(array_filter(['page'=>'laporan_konsolidasi','tab'=>$tkey,'tahun'=>$fTahun,'user'=>$fUser,'level'=>$fLevel,'unit'=>$fUnit,'simpulan'=>$fSimpu], fn($v) => $v !== ''));
  ?>
  <a href="<?= APP_URL ?>/?<?= $tQuery ?>"
     style="padding:10px 18px;font-size:.85rem;font-weight:600;border-radius:8px 8px 0 0;border:2px solid var(--border);border-bottom:none;<?= $tab===$tkey ? 'background:var(--accent);color:#fff' : 'background:var(--surface2);color:var(--text-muted)' ?>">
     <i class="fas <?= $tval['icon'] ?>"></i> <?= $tval['label'] ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- ── Approval Panel ────────────────────────────────────────── -->
<?php
$approvalStatus = $approvalData['status'] ?? 'pending';
$approvalBadge = match($approvalStatus) {
    'disetujui' => '<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:20px;background:#dcfce7;color:#16a34a;font-size:.78rem;font-weight:700"><i class="fas fa-check-circle"></i> Disetujui</span>',
    'ditolak'   => '<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:20px;background:#fee2e2;color:#dc2626;font-size:.78rem;font-weight:700"><i class="fas fa-times-circle"></i> Ditolak</span>',
    default     => '<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:20px;background:#fef9c3;color:#ca8a04;font-size:.78rem;font-weight:700"><i class="fas fa-clock"></i> Menunggu Persetujuan</span>',
};
?>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px;padding:14px 18px;border-radius:10px;background:var(--surface2);border:1px solid var(--border)">
  <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <strong style="font-size:.9rem"><i class="fas fa-user-shield" style="color:var(--accent)"></i> Validasi Pimpinan:</strong>
    <?= $approvalBadge ?>
    <?php if ($approvalData && $approvalStatus === 'disetujui'): ?>
      <span style="font-size:.75rem;color:var(--text-muted)">
        oleh <strong><?= xss($approvalData['approver_nama'] ?? '-') ?></strong>
        pada <?= tglIndo($approvalData['approved_at'] ?? '') ?> <?= date('H:i', strtotime($approvalData['approved_at'] ?? 'now')) ?>
      </span>
    <?php elseif ($approvalData && $approvalStatus === 'ditolak'): ?>
      <span style="font-size:.75rem;color:var(--text-muted)">
        oleh <strong><?= xss($approvalData['approver_nama'] ?? '-') ?></strong>
        pada <?= tglIndo($approvalData['approved_at'] ?? '') ?>
        <?php if (!empty($approvalData['catatan'])): ?> — "<em><?= xss($approvalData['catatan']) ?></em>"<?php endif; ?>
      </span>
    <?php endif; ?>
  </div>
  <div style="display:flex;gap:6px;align-items:center">
    <?php if (!empty($fTahun) && hasRole('Pimpinan')): ?>
      <?php if ($approvalStatus !== 'disetujui'): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('Setujui laporan konsolidasi tahun <?= xss($fTahun) ?>?')">
        <?= csrfField() ?>
        <input type="hidden" name="aksi" value="approve">
        <input type="hidden" name="tahun" value="<?= xss($fTahun) ?>">
        <button type="submit" class="btn btn-success" style="padding:6px 14px;font-size:.8rem"><i class="fas fa-check"></i> Setujui</button>
      </form>
      <?php endif; ?>
      <?php if ($approvalStatus !== 'ditolak'): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('Tolak laporan konsolidasi tahun <?= xss($fTahun) ?>?')">
        <?= csrfField() ?>
        <input type="hidden" name="aksi" value="reject">
        <input type="hidden" name="tahun" value="<?= xss($fTahun) ?>">
        <button type="submit" class="btn btn-danger" style="padding:6px 14px;font-size:.8rem"><i class="fas fa-times"></i> Tolak</button>
      </form>
      <?php endif; ?>
      <?php if ($approvalStatus !== 'pending'): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('Reset status approval tahun <?= xss($fTahun) ?>?')">
        <?= csrfField() ?>
        <input type="hidden" name="aksi" value="revoke">
        <input type="hidden" name="tahun" value="<?= xss($fTahun) ?>">
        <button type="submit" class="btn btn-outline" style="padding:6px 14px;font-size:.8rem"><i class="fas fa-undo"></i> Reset</button>
      </form>
      <?php endif; ?>
    <?php else: ?>
      <span style="font-size:.75rem;color:var(--text-muted)">Hanya Pimpinan yang dapat memberikan validasi</span>
    <?php endif; ?>
  </div>
</div>

<!-- ── Data Table ────────────────────────────────────────────── -->
<div class="card">
  <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; padding: 12px 16px">
    <div style="font-weight: 700; font-size: 1.1rem; color: var(--text-dark);">
      <?php if ($tab === 'profil'): ?>Profil Risiko Konsolidasi
      <?php elseif ($tab === 'kkpr'): ?>KKPR Konsolidasi
      <?php else: ?>KKPMR Konsolidasi
      <?php endif; ?>
      <span style="color:var(--text-muted);font-weight:400;font-size:1rem">(<?= $statKonsol['total'] ?> data)</span>
    </div>
<!-- ── Filter Bar ────────────────────────────────────────────── -->
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;flex:1;justify-content:flex-end">
    <div class="search-bar" style="max-width:250px;width:100%">
      <i class="fas fa-search"></i>
      <input type="text" class="form-control" id="searchLaporanKonsol" placeholder="Cari nama/kode..." onkeyup="filterTableLaporanKonsol()" style="height:38px">
    </div>
      <div class="datatable-dropdown" style="margin:0; display:flex; align-items:center;">
      <select class="datatable-selector" id="limitLaporanKonsol" onchange="filterTableLaporanKonsol()">
        <option value="5">5</option>
        <option value="10" selected>10</option>
        <option value="15">15</option>
        <option value="20">20</option>
        <option value="25">25</option>
      </select>
      <label style="margin-left:8px; margin-bottom:0; font-size:14px;">Data</label>
    </div>
  </div>
  </div>
  <div class="table-responsive">
    <table class="data-table profil-detail-table no-datatable" id="tableLaporanKonsol">
      <thead>
        <?php if ($tab === 'profil'): ?>
        <tr>
          <th style="text-align:center;width:40px">No</th><th style="text-align:center">Tahun</th><th style="text-align:center">Unit Kerja</th>
          <th style="text-align:center">Kode</th><th style="text-align:center;min-width:200px">Nama Risiko</th><th style="text-align:center">P</th><th style="text-align:center">D</th>
          <th style="text-align:center">Nilai</th><th style="text-align:center">Tingkat</th><th style="text-align:center;min-width:300px">Rencana</th><th style="text-align:center;min-width:150px">PIC</th>
          <th style="text-align:center">User</th>
        </tr>
        <?php elseif ($tab === 'kkpr'): ?>
        <tr>
          <th style="text-align:center;width:40px">No</th><th style="text-align:center">Tahun</th><th style="text-align:center">Unit</th>
          <th style="text-align:center">Kode</th><th style="text-align:center;min-width:200px">Nama Risiko</th><th style="text-align:center">Sumber</th><th style="text-align:center">C/UC</th>
          <th style="text-align:center">P</th><th style="text-align:center">D</th><th style="text-align:center">Nilai</th><th style="text-align:center">Tingkat</th>
          <th style="text-align:center;min-width:250px">Penanganan</th><th style="text-align:center">User</th>
        </tr>
        <?php else: ?>
        <tr>
          <th style="text-align:center;width:40px">No</th><th style="text-align:center">Tahun</th><th style="text-align:center">Unit</th>
          <th style="text-align:center">Kode</th><th style="text-align:center;min-width:200px">Nama Risiko</th><th style="text-align:center">P Awal</th><th style="text-align:center">D Awal</th>
          <th style="text-align:center">Nilai Awal</th><th style="text-align:center">P Pantau</th><th style="text-align:center">D Pantau</th><th style="text-align:center">Nilai Pantau</th>
          <th style="text-align:center;min-width:150px">Simpulan</th><th style="text-align:center">Efektif</th><th style="text-align:center">User</th>
        </tr>
        <?php endif; ?>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
        <tr><td colspan="<?= $tab==='profil'?12:13 ?>">
          <div class="empty-state">
            <div class="empty-state-icon"><i class="fas fa-inbox"></i></div>
            <div class="empty-state-title">Tidak ada data</div>
            <div class="empty-state-desc">Belum ada data untuk filter yang dipilih.</div>
          </div>
        </td></tr>
        <?php else: ?>
          <?php foreach ($rows as $i => $r): ?>
          <tr>
            <td style="text-align:center;color:var(--text-muted)"><?= $i + 1 ?></td>
            <td style="text-align:center;font-size:.78rem"><?= xss($r['tahun'] ?? '') ?></td>
            <?php if ($tab === 'profil'): ?>
              <td style="font-size:.78rem"><?= xss($r['unit_kerja'] ?? '-') ?></td>
              <td style="text-align:center;white-space:nowrap"><span class="badge-kode-risiko"><?= xss($r['kode_risiko'] ?? '') ?></span></td>
              <td style="font-weight:600"><?= xss($r['nama_risiko'] ?? '') ?></td>
              <td style="text-align:center;font-size:.78rem"><?= xss($r['probabilitas'] ?? '') ?></td>
              <td style="text-align:center;font-size:.78rem"><?= formatUraianList($r['dampak'] ?? '') ?></td>
              <td style="text-align:center;font-weight:700"><?= isset($r['nilai']) && $r['nilai'] !== '' ? round((float)$r['nilai']) : '' ?></td>
              <td style="text-align:center;font-size:.78rem"><?= xss($r['tingkat_risiko'] ?? '') ?></td>
              <td style="font-size:.74rem"><?= xss($r['rencana_penanganan'] ?? '-') ?></td>
              <td style="font-size:.74rem"><?= xss($r['penanggungjawab'] ?? '-') ?></td>
            <?php elseif ($tab === 'kkpr'): ?>
              <td style="font-size:.78rem"><?= xss($r['unit_pemilik_risiko'] ?? '-') ?></td>
              <td style="text-align:center;white-space:nowrap"><span class="badge-kode-risiko"><?= xss($r['kode_risiko'] ?? '') ?></span></td>
              <td style="font-weight:600"><?= xss($r['nama_risiko'] ?? '') ?></td>
              <td style="text-align:center;font-size:.74rem"><?= xss(normalizeSumberRisiko($r['sumber'] ?? '')) ?></td>
              <td style="text-align:center;font-size:.74rem"><?= xss($r['c_uc'] ?? '-') ?></td>
              <td style="text-align:center;font-size:.78rem"><?= xss($r['probabilitas'] ?? '') ?></td>
              <td style="text-align:center;font-size:.78rem"><?= xss($r['dampak_level'] ?? '') ?></td>
              <td style="text-align:center;font-weight:700"><?= isset($r['nilai_risiko']) && $r['nilai_risiko'] !== '' ? round((float)$r['nilai_risiko']) : '' ?></td>
              <td style="text-align:center;font-size:.74rem"><?= xss($r['tingkat_risiko'] ?? '') ?></td>
              <td style="font-size:.74rem"><?= xss($r['pilihan_penanganan'] ?? '-') ?></td>
            <?php else: ?>
              <td style="font-size:.78rem"><?= xss($r['unit_pemilik_risiko'] ?? '-') ?></td>
              <td style="text-align:center;white-space:nowrap"><span class="badge-kode-risiko"><?= xss($r['kode_risiko'] ?? '') ?></span></td>
              <td style="font-weight:600"><?= xss($r['nama_risiko'] ?? '') ?></td>
              <td style="text-align:center;font-size:.78rem"><?= xss($r['awal_p'] ?? '') ?></td>
              <td style="text-align:center;font-size:.78rem"><?= xss($r['awal_d'] ?? '') ?></td>
              <td style="text-align:center;font-weight:700"><?= isset($r['awal_nilai']) && $r['awal_nilai'] !== '' ? round((float)$r['awal_nilai']) : '' ?></td>
              <td style="text-align:center;font-size:.78rem"><?= xss($r['pantau_p'] ?? '') ?></td>
              <td style="text-align:center;font-size:.78rem"><?= xss($r['pantau_d'] ?? '') ?></td>
              <td style="text-align:center;font-weight:700"><?= isset($r['pantau_nilai']) && $r['pantau_nilai'] !== '' ? round((float)$r['pantau_nilai']) : '' ?></td>
              <td style="text-align:center;font-size:.74rem"><?= xss($r['simpulan'] ?? '-') ?></td>
              <td style="text-align:center;font-size:.74rem"><?= xss($r['efektifitas'] ?? '-') ?></td>
            <?php endif; ?>
            <td style="font-size:.74rem;color:var(--text-muted)"><?= xss($r['user_nama'] ?? '-') ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="monev-pagination" id="laporanKonsolPagination" style="display:flex;">
    <span class="info" id="laporanKonsolPageInfo">Memuat...</span>
    <div class="pages" id="laporanKonsolPages"></div>
  </div>
</div>

<?php if (!$canAccessAll): ?>
<p style="margin-top:12px;font-size:.78rem;color:var(--text-muted);text-align:center">
  <i class="fas fa-info-circle"></i> Anda melihat data milik sendiri. Admin &amp; Pimpinan dapat melihat semua data user.
</p>
<?php endif; ?>


<script>
const laporanKonsolState = { page: 1 };
let emptyRowKonsol = null;

function filterTableLaporanKonsol() {
  const query = (document.getElementById('searchLaporanKonsol')?.value || '').toLowerCase();
  const limit = parseInt(document.getElementById('limitLaporanKonsol')?.value || 10, 10);
  const allRows = [...document.querySelectorAll('#tableLaporanKonsol tbody tr:not(.empty-state-row)')];
  
  const visible = allRows.filter(row => {
    if(row.id === 'emptySearchLaporanKonsol') return false;
    const text = row.textContent.toLowerCase();
    if (query && !text.includes(query)) return false;
    return true;
  });

  const total = visible.length;
  const pages = Math.max(1, Math.ceil(total / limit));
  if (laporanKonsolState.page > pages) laporanKonsolState.page = pages;
  const start = (laporanKonsolState.page - 1) * limit;

  allRows.forEach(row => { row.style.display = 'none'; });
  visible.slice(start, start + limit).forEach(row => { row.style.display = ''; });

  const infoEl = document.getElementById('laporanKonsolPageInfo');
  if(infoEl) {
    infoEl.textContent = total === 0 ? 'Tidak ada data' : 'Menampilkan ' + (start + 1) + '–' + Math.min(start + limit, total) + ' dari ' + total + ' data';
  }

  const pagesEl = document.getElementById('laporanKonsolPages');
  if(pagesEl) {
    pagesEl.innerHTML = '';
    const mkBtn = (html, page, disabled, active) => {
      const b = document.createElement('button');
      b.type = 'button'; b.innerHTML = html; b.disabled = disabled;
      if (active) b.classList.add('active');
      if (!disabled) b.onclick = () => { laporanKonsolState.page = page; filterTableLaporanKonsol(); };
      pagesEl.appendChild(b);
    };
    mkBtn('<i class="fas fa-angle-double-left"></i>', 1, laporanKonsolState.page <= 1, false);
    mkBtn('<i class="fas fa-angle-left"></i>', laporanKonsolState.page - 1, laporanKonsolState.page <= 1, false);
    const winStart = Math.max(1, Math.min(laporanKonsolState.page - 2, pages - 4));
    const winEnd = Math.min(pages, winStart + 4);
    for (let p = winStart; p <= winEnd; p++) mkBtn(String(p), p, false, p === laporanKonsolState.page);
    mkBtn('<i class="fas fa-angle-right"></i>', laporanKonsolState.page + 1, laporanKonsolState.page >= pages, false);
    mkBtn('<i class="fas fa-angle-double-right"></i>', pages, laporanKonsolState.page >= pages, false);
  }
  
  if (total === 0 && allRows.length > 0) {
    if (!emptyRowKonsol) {
      emptyRowKonsol = document.createElement('tr');
      emptyRowKonsol.id = 'emptySearchLaporanKonsol';
      emptyRowKonsol.className = 'empty-state-row';
      document.querySelector('#tableLaporanKonsol tbody').appendChild(emptyRowKonsol);
    }
    emptyRowKonsol.style.display = '';
    emptyRowKonsol.innerHTML = `<td colspan="20"><div class="empty-state"><i class="fas fa-search"></i><p>Pencarian "<b>${query}</b>" tidak ditemukan.</p></div></td>`;
  } else {
    if(emptyRowKonsol) emptyRowKonsol.style.display = 'none';
  }
}
// Init limit
setTimeout(() => filterTableLaporanKonsol(), 100);
</script>

