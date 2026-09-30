<?php
if (isset($_GET['fix_kode'])) {
    $db = getDB();
    echo "Mulai memperbaiki kode risiko yang mengandung '-' (hash)...<br>\n";
    // Cari SEMUA kode_risiko unik yang memiliki hash dari SEMUA tabel
    $q = $db->query("
        SELECT DISTINCT kode_risiko FROM profil_risiko_detail WHERE kode_risiko LIKE '%-%'
        UNION
        SELECT DISTINCT kode_risiko FROM risiko WHERE kode_risiko LIKE '%-%'
    ");
    $updated = 0;
    while ($r = $q->fetch_assoc()) {
        $oldKode = $r['kode_risiko'];
        $parts = explode('.', $oldKode);
        if (count($parts) < 2) continue;
        $prefix = $parts[0];
        
        $qMax = $db->query("SELECT MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(kode_risiko, '-', 1), '.', -1) AS UNSIGNED)) as max_num FROM risiko WHERE kode_risiko LIKE '$prefix.%'");
        $maxRow = $qMax->fetch_assoc();
        $nextNum = (int)($maxRow['max_num'] ?? 0) + 1;
        
        // Cek juga max num di profil_risiko_detail untuk berjaga-jaga
        $qMax2 = $db->query("SELECT MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(kode_risiko, '-', 1), '.', -1) AS UNSIGNED)) as max_num FROM profil_risiko_detail WHERE kode_risiko LIKE '$prefix.%'");
        $maxRow2 = $qMax2->fetch_assoc();
        $nextNum2 = (int)($maxRow2['max_num'] ?? 0) + 1;
        
        if ($nextNum2 > $nextNum) $nextNum = $nextNum2;
        
        $newKode = $prefix . '.' . $nextNum;
        
        echo "Memperbarui <b>$oldKode</b> menjadi <b>$newKode</b>...<br>\n";
        
        $db->query("UPDATE risiko SET kode_risiko = '$newKode' WHERE kode_risiko = '$oldKode'");
        $db->query("UPDATE profil_risiko_detail SET kode_risiko = '$newKode' WHERE kode_risiko = '$oldKode'");
        $db->query("UPDATE kkpr_risiko SET kode_risiko = '$newKode' WHERE kode_risiko = '$oldKode'");
        
        $updated++;
    }
    echo "<br><b>Selesai!</b> Total $updated kode risiko unik diperbarui.<br>\n";
    echo "Silakan klik menu Profil Risiko untuk melihat hasilnya.";
    exit;
}
/**
 * MODUL DASHBOARD
 * Statistik, Chart, Heatmap Risiko — UX 2.0 (3 zona + tab)
 */

// === TRIGGER RESET SEMENTARA (Aman dihapus nanti) ===
if (isset($_GET['reset_status']) && $_GET['reset_status'] === '1') {
    $db = getDB();
    $db->query("UPDATE profil_risiko SET status = 'Draft'");
    $db->query("UPDATE kkpr_header SET status_kkpr = 'Draft', status_kkpmr = 'Draft'");
    echo "<script>alert('Berhasil mereset semua status menjadi Draft!'); window.location.href='".APP_URL."/?page=dashboard';</script>";
    exit;
}
// ====================================================

require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();

// -- Filter & Search (Profil Risiko) --
$search  = trim($_GET['q'] ?? '');
$fLevel  = trim($_GET['level'] ?? '');
$fTahun  = trim($_GET['tahun'] ?? '');
$fUnit   = trim($_GET['unit'] ?? '');

$where  = ['1=1'];
$params = [];
$types  = '';

if ($search)  {
    $where[] = '(d.nama_risiko LIKE ? OR d.kode_risiko LIKE ? OR d.rencana_penanganan LIKE ? OR d.penanggungjawab LIKE ?)';
    $s="%$search%";
    $params[]=$s;$params[]=$s;$params[]=$s;$params[]=$s;
    $types.='ssss';
}
if ($fLevel)  {
    if ($fLevel === 'tinggi_plus') { $where[] = 'd.nilai >= 15'; }
    else { $where[] = 'd.tingkat_risiko = ?'; $params[]=$fLevel; $types.='s'; }
}
if ($fTahun) {
    $where[] = 'p.tahun = ?'; $params[]=$fTahun; $types.='s';
}
if ($fUnit) {
    $where[] = 'd.unit_kerja = ?'; $params[]=$fUnit; $types.='s';
}

if (!hasRole('Admin', 'Pimpinan')) {
    $where[] = "p.created_by = " . (int)$_SESSION['user_id'];
}
$whereStr = implode(' AND ', $where);

// ── Default aman: dipakai jika ada query DB yang gagal (mis. tabel/kolom
//    belum ter-migrate di server) supaya dashboard tetap render, bukan blank.
//    Di production (display_errors=0), exception query yang tidak tertangkap
//    akan menjadi halaman blank — error aslinya dicatat ke error_log di bawah.
$dashError = null;
$tahunList = []; $unitList = [];
$stats = ['total_profil' => 0, 'total_risiko' => 0, 'tinggi' => 0, 'sangat_tinggi' => 0];
$priorityPage     = max(1, (int)($_GET['priority_page'] ?? 1));
$priorityPerPage  = (isset($_GET['priority_limit']) && in_array((int)$_GET['priority_limit'], [5, 10, 15, 20, 25])) ? (int)$_GET['priority_limit'] : 5;
$priorityTotalRows = 0; $priorityTotalPages = 1; $priorityOffset = 0;
$topPrioritas = [];
$heatMap = [];
$dashboardFilters = []; $priorityPageQuery = '';
$triwulan = ['Q1' => ['jml' => 0, 'avg' => null, 'tinggi' => 0], 'Q2' => ['jml' => 0, 'avg' => null, 'tinggi' => 0], 'Q3' => ['jml' => 0, 'avg' => null, 'tinggi' => 0], 'Q4' => ['jml' => 0, 'avg' => null, 'tinggi' => 0]];
$trenLabels = ['Q1', 'Q2', 'Q3', 'Q4']; $trenAvg = [null, null, null, null]; $trenJml = [0, 0, 0, 0]; $trenTinggi = [0, 0, 0, 0]; $trenTotalRisk = 0; $trenTotalHigh = 0;
$latestMonitoredAvg = null;
$dashMatrixData = [];
$dashName = trim($_SESSION['user_nama'] ?? 'Pengguna');
$dashFirstName = explode(' ', $dashName)[0] ?: 'Pengguna';
$highRiskPct = 0;
$cntRisiko = 0; $cntProfil = 0; $cntProfilDetail = 0; $cntKkpr = 0; $cntKkprRisiko = 0;
$myPendingDocs = []; $pendingApprovals = [];

try {

// Daftar tahun & unit untuk dropdown filter
$tahunList = $db->query("SELECT DISTINCT tahun FROM profil_risiko ORDER BY tahun DESC")->fetch_all(MYSQLI_ASSOC) ?: [];
$unitList  = $db->query("SELECT DISTINCT d.unit_kerja FROM profil_risiko_detail d JOIN profil_risiko p ON d.id_profil=p.id WHERE d.unit_kerja != '' " . (hasRole('Admin','Pimpinan') ? '' : 'AND p.created_by='.(int)$_SESSION['user_id']) . " ORDER BY d.unit_kerja LIMIT 20")->fetch_all(MYSQLI_ASSOC) ?: [];

// Helper: eksekusi SELECT dgn filter parameter
function dashQuery($db, $sql, $types, $params) {
    $stmt = $db->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// -- Statistik Utama (with filter) --
$statsRow = dashQuery($db, "
  SELECT
    COUNT(DISTINCT p.id) AS total_profil,
    COUNT(d.id) AS total_risiko,
    COALESCE(SUM(d.nilai >= 15), 0) AS tinggi,
    COALESCE(SUM(d.tingkat_risiko = 'Sangat Tinggi'), 0) AS sangat_tinggi
  FROM profil_risiko p
  LEFT JOIN profil_risiko_detail d ON d.id_profil = p.id
  WHERE $whereStr
", $types, $params);
$stats = $statsRow[0] ?? ['total_profil'=>0,'total_risiko'=>0,'tinggi'=>0,'sangat_tinggi'=>0];

// -- Top Risiko Prioritas --
$priorityPage = max(1, (int)($_GET['priority_page'] ?? 1));
$priorityPerPage = (isset($_GET['priority_limit']) && in_array((int)$_GET['priority_limit'], [5, 10, 15, 20, 25])) ? (int)$_GET['priority_limit'] : 5;
$priorityCountRow = dashQuery($db, "
  SELECT COUNT(*) AS total
  FROM profil_risiko_detail d
  JOIN profil_risiko p ON d.id_profil = p.id
  WHERE $whereStr
", $types, $params);
$priorityTotalRows = (int)($priorityCountRow[0]['total'] ?? 0);
$priorityTotalPages = max(1, (int)ceil($priorityTotalRows / $priorityPerPage));
if ($priorityPage > $priorityTotalPages) $priorityPage = $priorityTotalPages;
$priorityOffset = ($priorityPage - 1) * $priorityPerPage;
$topPrioritas = dashQuery($db, "
  SELECT d.id AS id_detail, d.id_profil, d.id_risiko, d.kode_risiko, d.nama_risiko, d.nilai AS skor_risiko, d.tingkat_risiko AS level_risiko,
         d.rencana_penanganan, d.penanggungjawab, d.jadwal_pelaksanaan,
         p.tahun, p.unit_pemilik_risiko
  FROM profil_risiko_detail d
  JOIN profil_risiko p ON d.id_profil = p.id
  WHERE $whereStr
  ORDER BY d.nilai DESC LIMIT ? OFFSET ?
", $types . 'ii', array_merge($params, [$priorityPerPage, $priorityOffset]));

// -- Matriks Risiko 5x5 per Periode (Kondisi Awal & Triwulan 1–4) --
$dashPeriods = ['awal' => 'Kondisi Awal', 'tw1' => 'Triwulan 1', 'tw2' => 'Triwulan 2', 'tw3' => 'Triwulan 3', 'tw4' => 'Triwulan 4'];
$dashMatrixData = [];
foreach ($dashPeriods as $k => $lbl) {
    $dashMatrixData[$k] = [
        'label' => $lbl,
        'total' => 0,
        'counts' => array_fill(1, 5, array_fill(1, 5, 0)),
        'items' => array_fill(1, 5, array_fill(1, 5, []))
    ];
}

// 1. Data Matriks Kondisi Awal (dari profil_risiko_detail)
$baseRows = dashQuery($db, "
    SELECT d.id, d.id_risiko, d.id_profil, d.kode_risiko, d.nama_risiko, d.unit_kerja, d.probabilitas, d.dampak, d.nilai, d.tingkat_risiko
    FROM profil_risiko_detail d
    JOIN profil_risiko p ON d.id_profil = p.id
    WHERE $whereStr
    ORDER BY d.nilai DESC
", $types, $params);

$heatMap = [];
foreach ($baseRows as $r) {
    $p = max(1, min(5, (int)($r['probabilitas'] ?? 1)));
    $d = max(1, min(5, (int)($r['dampak'] ?? 1)));
    $skor = (int)round((float)($r['nilai'] ?? ($p * $d * getBobot($p, $d))));
    $level = trim((string)($r['tingkat_risiko'] ?: getLevelRisiko($skor)));
    $dashMatrixData['awal']['counts'][$p][$d]++;
    $dashMatrixData['awal']['items'][$p][$d][] = [
        'id' => (!empty($r['id_risiko']) && (int)$r['id_risiko'] > 0) ? (int)$r['id_risiko'] : (int)$r['id'],
        'kode' => $r['kode_risiko'] ?: '-',
        'nama' => $r['nama_risiko'] ?: '-',
        'unit' => $r['unit_kerja'] ?: '-',
        'p' => $p,
        'd' => $d,
        'nilai' => $skor,
        'tingkat' => $level,
        'status' => 'Baseline'
    ];
    $dashMatrixData['awal']['total']++;
    $heatMap[$p][$d] = ($heatMap[$p][$d] ?? 0) + 1;
}

$dashboardFilters = array_filter(['page'=>'dashboard','q'=>$search,'level'=>$fLevel,'tahun'=>$fTahun,'unit'=>$fUnit,'priority_limit'=>$_GET['priority_limit']??''], static fn($value) => $value !== '');
$priorityPageQuery = http_build_query($dashboardFilters);

// 2. Data Monev Triwulan (untuk Tren Triwulanan dan Matriks TW1..TW4)
$whereMonev = ["m.pantau_nilai IS NOT NULL"];
$paramsMonev = [];
$typesMonev = '';

if ($fTahun) {
    $whereMonev[] = "h.tahun = ?";
    $paramsMonev[] = $fTahun;
    $typesMonev .= 's';
}
if ($fUnit) {
    $whereMonev[] = "h.unit_pemilik_risiko = ?";
    $paramsMonev[] = $fUnit;
    $typesMonev .= 's';
}
if ($search) {
    $whereMonev[] = "(r.nama_risiko LIKE ? OR r.kode_risiko LIKE ?)";
    $s = "%$search%";
    $paramsMonev[] = $s; $paramsMonev[] = $s;
    $typesMonev .= 'ss';
}
if (!hasRole('Admin', 'Pimpinan')) {
    $whereMonev[] = "h.created_by = ?";
    $paramsMonev[] = (int)$_SESSION['user_id'];
    $typesMonev .= 'i';
}
$whereMonevStr = implode(' AND ', $whereMonev);

// Tren Triwulanan Query (berdasarkan data monev riil)
$trenRaw = dashQuery($db, "
    SELECT
        m.triwulan AS q,
        COUNT(m.id) AS jml,
        AVG(m.pantau_nilai) AS avg_skor,
        SUM(IF(m.pantau_tingkat IN ('Tinggi','Sangat Tinggi') OR m.pantau_nilai >= 15, 1, 0)) AS jml_tinggi
    FROM monev_triwulan m
    JOIN kkpr_risiko r ON r.id = m.id_risiko
    JOIN kkpr_header h ON h.id = r.id_kkpr
    WHERE $whereMonevStr
    GROUP BY m.triwulan
    ORDER BY m.triwulan
", $typesMonev, $paramsMonev);

$triwulan = [
    'Q1' => ['jml' => 0, 'avg' => null, 'tinggi' => 0],
    'Q2' => ['jml' => 0, 'avg' => null, 'tinggi' => 0],
    'Q3' => ['jml' => 0, 'avg' => null, 'tinggi' => 0],
    'Q4' => ['jml' => 0, 'avg' => null, 'tinggi' => 0]
];
$latestMonitored = null;
foreach ($trenRaw as $t) {
    if (!empty($t['q'])) {
        $key = 'Q' . $t['q'];
        if (isset($triwulan[$key])) {
            $triwulan[$key] = [
                'jml' => (int)$t['jml'],
                'avg' => round((float)$t['avg_skor'], 1),
                'tinggi' => (int)$t['jml_tinggi']
            ];
            $latestMonitored = $triwulan[$key];
            $latestTwNum = (int)$t['q'];
        }
    }
}

$baseAvgRow = dashQuery($db, "
    SELECT AVG(d.nilai) as avg_score, SUM(d.nilai >= 15) as jml_tinggi
    FROM profil_risiko_detail d
    JOIN profil_risiko p ON d.id_profil = p.id
    WHERE $whereStr
", $types, $params);
$baseAvgScore = round((float)($baseAvgRow[0]['avg_score'] ?? 0), 1);
$baseHighCount = (int)($baseAvgRow[0]['jml_tinggi'] ?? 0);

$trenLabelsOpt3 = ['Kondisi Awal', 'Triwulan 1', 'Triwulan 2', 'Triwulan 3', 'Triwulan 4'];
$trenScoreData = [
    $baseAvgScore,
    $triwulan['Q1']['avg'],
    $triwulan['Q2']['avg'],
    $triwulan['Q3']['avg'],
    $triwulan['Q4']['avg']
];

$trenTotalRisk = (int)$stats['total_risiko'];
$trenTotalHigh = $latestMonitored ? (int)$latestMonitored['tinggi'] : (int)$stats['tinggi'];
$latestMonitoredAvg = $latestMonitored ? (float)$latestMonitored['avg'] : null;
$scoreReductionPct = ($baseAvgScore > 0 && $latestMonitoredAvg !== null) ? round(($baseAvgScore - $latestMonitoredAvg) / $baseAvgScore * 100, 1) : 0;

// Distribusi 5 Tingkat Risiko (Awal & TW1..TW4)
$twLevels = [
    'awal' => ['Sangat Tinggi' => 0, 'Tinggi' => 0, 'Sedang' => 0, 'Rendah' => 0, 'Sangat Rendah' => 0],
    'Q1'   => ['Sangat Tinggi' => 0, 'Tinggi' => 0, 'Sedang' => 0, 'Rendah' => 0, 'Sangat Rendah' => 0],
    'Q2'   => ['Sangat Tinggi' => 0, 'Tinggi' => 0, 'Sedang' => 0, 'Rendah' => 0, 'Sangat Rendah' => 0],
    'Q3'   => ['Sangat Tinggi' => 0, 'Tinggi' => 0, 'Sedang' => 0, 'Rendah' => 0, 'Sangat Rendah' => 0],
    'Q4'   => ['Sangat Tinggi' => 0, 'Tinggi' => 0, 'Sedang' => 0, 'Rendah' => 0, 'Sangat Rendah' => 0],
];
$baseLevelsRaw = dashQuery($db, "
    SELECT d.tingkat_risiko, COUNT(d.id) as cnt
    FROM profil_risiko_detail d
    JOIN profil_risiko p ON d.id_profil = p.id
    WHERE $whereStr
    GROUP BY d.tingkat_risiko
", $types, $params);
foreach ($baseLevelsRaw as $bl) {
    $k = trim($bl['tingkat_risiko']);
    if (isset($twLevels['awal'][$k])) $twLevels['awal'][$k] = (int)$bl['cnt'];
}
$monevLevelsRaw = dashQuery($db, "
    SELECT m.triwulan, m.pantau_tingkat, COUNT(m.id) as cnt
    FROM monev_triwulan m
    JOIN kkpr_risiko r ON r.id = m.id_risiko
    JOIN kkpr_header h ON h.id = r.id_kkpr
    WHERE $whereMonevStr
    GROUP BY m.triwulan, m.pantau_tingkat
", $typesMonev, $paramsMonev);
foreach ($monevLevelsRaw as $ml) {
    $qKey = 'Q' . $ml['triwulan'];
    $k = trim($ml['pantau_tingkat']);
    if (isset($twLevels[$qKey][$k])) $twLevels[$qKey][$k] = (int)$ml['cnt'];
}

$levelKeys = ['Sangat Rendah', 'Rendah', 'Sedang', 'Tinggi', 'Sangat Tinggi'];
$periodsKey = ['awal', 'Q1', 'Q2', 'Q3', 'Q4'];
$trenLevelDatasets = [];
foreach ($levelKeys as $lvl) {
    $data = [];
    foreach ($periodsKey as $pk) {
        $data[] = $twLevels[$pk][$lvl] ?? 0;
    }
    $trenLevelDatasets[$lvl] = $data;
}

// Matriks TW1..TW4 Detail Items
$twRows = dashQuery($db, "
    SELECT m.id, m.id_risiko, m.triwulan, m.pantau_p, m.pantau_d, m.pantau_nilai, m.pantau_tingkat,
           r.nama_risiko, r.kode_risiko, h.unit_pemilik_risiko
    FROM monev_triwulan m
    JOIN kkpr_risiko r ON r.id = m.id_risiko
    JOIN kkpr_header h ON h.id = r.id_kkpr
    WHERE $whereMonevStr
    ORDER BY m.pantau_nilai DESC
", $typesMonev, $paramsMonev);

foreach ($twRows as $m) {
    $twKey = 'tw' . (int)$m['triwulan'];
    if (!isset($dashMatrixData[$twKey])) continue;
    $p = max(1, min(5, (int)$m['pantau_p']));
    $d = max(1, min(5, (int)$m['pantau_d']));
    $skor = (int)round((float)$m['pantau_nilai']);
    $level = trim((string)($m['pantau_tingkat'] ?: getLevelRisiko($skor)));
    $dashMatrixData[$twKey]['counts'][$p][$d]++;
    $dashMatrixData[$twKey]['items'][$p][$d][] = [
        'id' => $m['id_risiko'],
        'kode' => $m['kode_risiko'] ?: '-',
        'nama' => $m['nama_risiko'] ?: '-',
        'unit' => $m['unit_pemilik_risiko'] ?: '-',
        'p' => $p,
        'd' => $d,
        'nilai' => $skor,
        'tingkat' => $level,
        'status' => 'Dipantau'
    ];
    $dashMatrixData[$twKey]['total']++;
}

$dashName = trim($_SESSION['user_nama'] ?? 'Pengguna');
$dashFirstName = explode(' ', $dashName)[0] ?: 'Pengguna';
$highRiskPct = (int)$stats['total_risiko'] > 0 ? round(((int)$stats['tinggi'] / (int)$stats['total_risiko']) * 100) : 0;

// Wizard counter (cakupan global — bukan terfilter profil risiko)
$cntRisiko     = (int)$db->query("SELECT COUNT(*) FROM risiko WHERE deleted_at IS NULL AND approval_status='approved'" . (hasRole('Admin', 'Pimpinan') ? '' : ' AND id_user_input = ' . (int)$_SESSION['user_id']))->fetch_column();
$cntProfil     = (int)$db->query("SELECT COUNT(*) FROM profil_risiko" . (hasRole('Admin', 'Pimpinan') ? '' : ' WHERE created_by = ' . (int)$_SESSION['user_id']))->fetch_column();
$cntProfilDetail = (int)$db->query("SELECT COUNT(*) FROM profil_risiko_detail d JOIN profil_risiko p ON d.id_profil=p.id" . (hasRole('Admin', 'Pimpinan') ? '' : ' WHERE p.created_by = ' . (int)$_SESSION['user_id']))->fetch_column();

// -- Panel "Menunggu Persetujuan Saya" (dokumen belum diajukan oleh Risk Manager) --
$myPendingDocs = [];
if (hasRole('Risk Manager')) {
    $uid = (int)$_SESSION['user_id'];
    // Profil Risiko: Draft / Revisi
    $prp = $db->prepare("SELECT id, tahun, unit_pemilik_risiko, status FROM profil_risiko WHERE created_by=? AND status IN ('Draft','Revisi') ORDER BY tahun DESC, id DESC LIMIT 5");
    $prp->bind_param('i', $uid); $prp->execute();
    foreach ($prp->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $myPendingDocs[] = [
            'jenis' => 'Profil Risiko',
            'judul' => $row['tahun'] . ' — ' . ($row['unit_pemilik_risiko'] ?: '-'),
            'status' => $row['status'],
            'url' => APP_URL . '/?page=profil_risiko&id=' . (int)$row['id'],
            'icon' => 'fa-file-signature', 'color' => '#10b981',
        ];
    }
    $prp->close();
    // KKPR: Draft / Revisi
    $kpp = $db->prepare("SELECT id, tahun, unit_pemilik_risiko, status_kkpr FROM kkpr_header WHERE created_by=? AND status_kkpr IN ('Draft','Revisi') ORDER BY tahun DESC, id DESC LIMIT 5");
    $kpp->bind_param('i', $uid); $kpp->execute();
    foreach ($kpp->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $myPendingDocs[] = [
            'jenis' => 'KKPR',
            'judul' => $row['tahun'] . ' — ' . ($row['unit_pemilik_risiko'] ?: '-'),
            'status' => $row['status_kkpr'],
            'url' => APP_URL . '/?page=kkpr&id=' . (int)$row['id'],
            'icon' => 'fa-clipboard-list', 'color' => '#8b5cf6',
        ];
    }
    $kpp->close();
    // KKPMR: Draft / Revisi
    $kmp = $db->prepare("SELECT id, tahun, unit_pemilik_risiko, status_kkpmr FROM kkpr_header WHERE created_by=? AND status_kkpmr IN ('Draft','Revisi') ORDER BY tahun DESC, id DESC LIMIT 5");
    $kmp->bind_param('i', $uid); $kmp->execute();
    foreach ($kmp->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $myPendingDocs[] = [
            'jenis' => 'KKPMR',
            'judul' => $row['tahun'] . ' — ' . ($row['unit_pemilik_risiko'] ?: '-'),
            'status' => $row['status_kkpmr'],
            'url' => APP_URL . '/?page=kkpmr&id=' . (int)$row['id'],
            'icon' => 'fa-magnifying-glass-chart', 'color' => '#0d9488',
        ];
    }
    $kmp->close();
}

// -- Panel "Menunggu Keputusan Pimpinan" (untuk Pimpinan) --
$pendingApprovals = [];
if (hasRole('Pimpinan')) {
    $pap = $db->prepare("SELECT id, tahun, unit_pemilik_risiko, status FROM profil_risiko WHERE status='Menunggu Persetujuan' ORDER BY tahun DESC, id DESC LIMIT 5");
    $pap->execute();
    foreach ($pap->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $pendingApprovals[] = [
            'jenis' => 'Profil Risiko',
            'judul' => $row['tahun'] . ' — ' . ($row['unit_pemilik_risiko'] ?: '-'),
            'status' => $row['status'],
            'url' => APP_URL . '/?page=profil_risiko&id=' . (int)$row['id'],
            'icon' => 'fa-file-signature', 'color' => '#10b981',
        ];
    }
    $pap->close();
    $kap = $db->prepare("SELECT id, tahun, unit_pemilik_risiko, status_kkpr FROM kkpr_header WHERE status_kkpr='Menunggu Persetujuan' ORDER BY tahun DESC, id DESC LIMIT 5");
    $kap->execute();
    foreach ($kap->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $pendingApprovals[] = [
            'jenis' => 'KKPR',
            'judul' => $row['tahun'] . ' — ' . ($row['unit_pemilik_risiko'] ?: '-'),
            'status' => $row['status_kkpr'],
            'url' => APP_URL . '/?page=kkpr&id=' . (int)$row['id'],
            'icon' => 'fa-clipboard-list', 'color' => '#8b5cf6',
        ];
    }
    $kap->close();
    $kamp = $db->prepare("SELECT id, tahun, unit_pemilik_risiko, status_kkpmr FROM kkpr_header WHERE status_kkpmr='Menunggu Persetujuan' ORDER BY tahun DESC, id DESC LIMIT 5");
    $kamp->execute();
    foreach ($kamp->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $pendingApprovals[] = [
            'jenis' => 'KKPMR',
            'judul' => $row['tahun'] . ' — ' . ($row['unit_pemilik_risiko'] ?: '-'),
            'status' => $row['status_kkpmr'],
            'url' => APP_URL . '/?page=kkpmr&id=' . (int)$row['id'],
            'icon' => 'fa-magnifying-glass-chart', 'color' => '#0d9488',
        ];
    }
    $kamp->close();
}
$cntKkpr       = (int)$db->query("SELECT COUNT(*) FROM kkpr_header" . (hasRole('Admin', 'Pimpinan') ? '' : ' WHERE created_by = ' . (int)$_SESSION['user_id']))->fetch_column();
$cntKkprRisiko = (int)$db->query("SELECT COUNT(*) FROM kkpr_risiko kr JOIN kkpr_header kh ON kr.id_kkpr=kh.id" . (hasRole('Admin', 'Pimpinan') ? '' : ' WHERE kh.created_by = ' . (int)$_SESSION['user_id']))->fetch_column();

} catch (Throwable $e) {
    // Tangkap error DB (kolom/tabel belum ter-migrate, dsb) agar dashboard
    // tetap tampil dengan data kosong, bukan halaman blank. Error asli
    // dicatat ke error_log + ditampilkan via banner untuk membantu perbaikan.
    $dashError = $e->getMessage();
    error_log('[manris dashboard] query gagal: ' . $dashError . ' @ ' . $e->getFile() . ':' . $e->getLine());
}
?>

<?php if ($dashError): ?>
<div class="card" style="margin:0 0 16px;border-left:4px solid var(--warning);background:rgba(245,158,11,.06)">
  <div class="card-body" style="padding:12px 16px;display:flex;align-items:flex-start;gap:10px">
    <i class="fas fa-triangle-exclamation" style="color:var(--warning);font-size:1.2rem;margin-top:2px"></i>
    <div style="font-size:.82rem;line-height:1.5;flex:1">
      <strong>Dashboard memuat sebagian data.</strong> Beberapa statistik tidak dapat diambil &mdash; kemungkinan ada tabel/kolom database yang belum ter-migrate. Jalankan <code>php lib/migrate.php run</code> di server.
      <details style="margin-top:4px"><summary style="cursor:pointer;color:var(--text-muted);font-size:.74rem">Detail error</summary>
      <code style="font-size:.72rem;color:var(--danger);word-break:break-all;white-space:normal"><?= xss($dashError) ?></code></details>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
// Banner peringatan untuk Pimpinan: ada dokumen menunggu keputusan
$pendAppr = function_exists('countPendingApprovals') ? countPendingApprovals() : 0;
if (hasRole('Pimpinan') && $pendAppr > 0):
?>
<div class="card" style="margin:0 0 16px;border-left:4px solid var(--danger);background:linear-gradient(135deg,rgba(220,38,38,.08),rgba(245,158,11,.05));animation:pulse-soft 2s infinite">
  <div class="card-body" style="padding:14px 18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap">
    <div style="flex:0 0 auto;width:44px;height:44px;border-radius:50%;background:var(--danger);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.3rem">
      <i class="fas fa-gavel"></i>
    </div>
    <div style="flex:1;min-width:200px">
      <div style="font-weight:800;font-size:1rem;color:var(--danger)"><i class="fas fa-bell"></i> <?= $pendAppr ?> dokumen menunggu keputusan Anda</div>
      <div style="font-size:.82rem;color:var(--text-muted);margin-top:2px">Risk Manager telah mengajukan dokumen untuk disetujui/direvisi. Segera tindak lanjuti agar alur manajemen risiko tidak tertahan.</div>
    </div>
    <a href="<?= APP_URL ?>/?page=persetujuan" class="btn btn-danger" style="flex:0 0 auto;white-space:nowrap">
      <i class="fas fa-arrow-right"></i> Tinjau Sekarang
    </a>
  </div>
</div>
<?php endif; ?>

<?php
// Kotak pencarian dashboard kini berada di navbar (includes/header.php).
?>
  <!-- Wizard Alur 3 Langkah (progress + status) -->
  <?php
  $step1Done = $cntRisiko > 0;
  $step2Done = $cntProfil > 0;
  $step3Done = $cntKkpr > 0;
  $step2Avail = $step1Done;
  $step3Avail = $step2Done;
  $doneCount = ($step1Done?1:0)+($step2Done?1:0)+($step3Done?1:0);
  $progPct = round($doneCount/3*100);
  $wz = [
    1 => ['page'=>'risiko','title'=>'Identifikasi Risiko','desc'=>'Catat nama, sebab, dampak, kategori, dan unit. P/D diisi nanti di Profil.',
          'count'=>'<i class="fas fa-list-check"></i> '.$cntRisiko.' risiko terdaftar','color'=>'#2563eb','done'=>$step1Done,'avail'=>true],
    2 => ['page'=>'profil_risiko','title'=>'Profil Risiko Unit','desc'=>'Pilih risiko master, isi P/D, bobot otomatis, rencana penanganan, PIC, jadwal, target residual.',
          'count'=>'<i class="fas fa-file-signature"></i> '.$cntProfil.' profil, '.$cntProfilDetail.' risiko diinput','color'=>'#16a34a','done'=>$step2Done,'avail'=>$step2Avail],
    3 => ['page'=>'kkpr','title'=>'KKPR (Kertas Kerja)','desc'=>'Dokumen resmi. Salin header dari Profil, impor detail, lengkapi pengendalian &amp; RPTI.',
          'count'=>'<i class="fas fa-file-contract"></i> '.$cntKkpr.' KKPR, '.$cntKkprRisiko.' risiko diinput','color'=>'#f97316','done'=>$step3Done,'avail'=>$step3Avail],
  ];
  ?>
  <div class="card" style="margin-bottom:20px;border-left:4px solid var(--accent)">
    <div class="card-body" style="padding:20px">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
        <i class="fas fa-route" style="font-size:1.4rem;color:var(--accent)"></i>
        <div style="flex:1">
          <div style="font-weight:800;font-size:1rem;color:var(--text)">Alur Penginputan Manajemen Risiko</div>
          <div style="font-size:.78rem;color:var(--text-muted)">Ikuti 3 langkah berurutan untuk input risiko yang lengkap</div>
        </div>
        <div class="wizard-progress-label" style="text-align:right">
          <strong style="font-size:1.1rem;color:var(--success)"><?= $progPct ?>%</strong>
          <div style="font-size:.62rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em"><?= $doneCount ?>/3 selesai</div>
        </div>
      </div>
      <div class="wizard-progress"><div class="wizard-progress-fill" style="width:<?= $progPct ?>%"></div></div>
      <div class="wizard-stepper">
        <?php foreach ($wz as $n => $s): ?>
          <?php
            $status = $s['done'] ? 'is-done' : ($s['avail'] ? 'is-current' : 'is-locked');
            $badge = $s['done'] ? 'Selesai' : ($s['avail'] ? ($n===1?'Mulai':'Lanjutkan') : 'Terkunci');
            $badgeCls = $s['done'] ? 'ws-badge-done' : ($s['avail'] ? 'ws-badge-current' : 'ws-badge-locked');
            $locked = !$s['done'] && !$s['avail'];
          ?>
          <a href="<?= APP_URL ?>/?page=<?= $s['page'] ?>" class="wizard-step <?= $status ?>" data-step="<?= $n ?>"
             <?= $locked ? 'tabindex="-1" aria-disabled="true"' : '' ?>>
            <span class="ws-num">
              <span class="ws-num-label"><?= $n ?></span>
              <span class="ws-num-check"><i class="fas fa-check"></i></span>
            </span>
            <span class="ws-body">
              <span class="ws-title"><?= $s['title'] ?></span>
              <span class="ws-sub"><?= $s['desc'] ?></span>
              <span class="ws-sub ws-count" style="color:<?= $s['color'] ?>;font-weight:800"><?= $s['count'] ?></span>
              <span class="ws-status"><span class="ws-badge <?= $badgeCls ?>"><i class="fas <?= $s['done'] ? 'fa-circle-check' : ($s['avail'] ? 'fa-play' : 'fa-lock') ?>"></i> <?= $badge ?></span></span>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>


<?php if (!empty($myPendingDocs) || !empty($pendingApprovals)): ?>
<!-- Panel dokumen pending: Risk Manager (belum diajukan) / Pimpinan (menunggu keputusan) -->
<div class="dash-pending-panel">
  <?php if (!empty($myPendingDocs)): ?>
  <div class="dash-pending-col">
    <div class="dash-pending-head">
      <span class="dp-icon dp-warn"><i class="fas fa-paper-plane"></i></span>
      <div>
        <div class="dp-title">Menunggu Persetujuan Saya <span class="dp-count"><?= count($myPendingDocs) ?></span></div>
        <div class="dp-sub">Dokumen belum diajukan ke Pimpinan (Draft/Revisi)</div>
      </div>
    </div>
    <div class="dash-pending-list">
      <?php foreach (array_slice($myPendingDocs, 0, 5) as $doc): ?>
      <a href="<?= xss($doc['url']) ?>" class="dash-pending-item">
        <span class="dpi-icon" style="background:<?= $doc['color'] ?>18;color:<?= $doc['color'] ?>"><i class="fas <?= $doc['icon'] ?>"></i></span>
        <span class="dpi-body">
          <span class="dpi-judul"><?= xss($doc['judul']) ?></span>
          <span class="dpi-meta"><?= xss($doc['jenis']) ?> &bull; <?= xss($doc['status']) ?></span>
        </span>
        <span class="dpi-action"><i class="fas fa-arrow-right"></i></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php if (!empty($pendingApprovals)): ?>
  <div class="dash-pending-col">
    <div class="dash-pending-head">
      <span class="dp-icon dp-info"><i class="fas fa-gavel"></i></span>
      <div>
        <div class="dp-title">Menunggu Keputusan Saya <span class="dp-count"><?= count($pendingApprovals) ?></span></div>
        <div class="dp-sub">Dokumen diajukan Risk Manager, menunggu persetujuan</div>
      </div>
    </div>
    <div class="dash-pending-list">
      <?php foreach (array_slice($pendingApprovals, 0, 5) as $doc): ?>
      <a href="<?= xss($doc['url']) ?>" class="dash-pending-item">
        <span class="dpi-icon" style="background:<?= $doc['color'] ?>18;color:<?= $doc['color'] ?>"><i class="fas <?= $doc['icon'] ?>"></i></span>
        <span class="dpi-body">
          <span class="dpi-judul"><?= xss($doc['judul']) ?></span>
          <span class="dpi-meta"><?= xss($doc['jenis']) ?> &bull; <?= xss($doc['status']) ?></span>
        </span>
        <span class="dpi-action"><i class="fas fa-arrow-right"></i></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>



<!-- Tabs Zona -->
<!-- ══════════ ZONA 1 · RINGKASAN ══════════ -->
<div class="dash-zone active" data-zone="ringkas">

  <!-- Stat Cards - Premium -->
  <div class="stats-grid dashboard-kpis">
    <a href="<?= APP_URL ?>/?page=profil_risiko" class="stat-card dashboard-kpi kpi-blue" style="--stat-bg:rgba(59,130,246,.1);--stat-icon-color:#3b82f6">
      <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $stats['total_profil'] ?></div>
        <div class="stat-label">Total Profil Risiko</div><div class="kpi-meta"><i class="fas fa-layer-group"></i> <?= $stats['total_profil'] ?> unit terdaftar</div>
      </div>
    </a>
    <a href="<?= APP_URL ?>/?page=profil_risiko" class="stat-card dashboard-kpi kpi-cyan" style="--stat-bg:rgba(14,165,233,.1);--stat-icon-color:#0ea5e9">
      <div class="stat-icon"><i class="fas fa-list-ul"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $stats['total_risiko'] ?></div>
        <div class="stat-label">Total Risiko Teridentifikasi</div><div class="kpi-meta"><i class="fas fa-database"></i> <?= $stats['total_risiko'] ?> risiko aktif</div>
      </div>
    </a>
    <a href="<?= APP_URL ?>/?page=profil_risiko" class="stat-card dashboard-kpi kpi-red" style="--stat-bg:rgba(220,38,38,.1);--stat-icon-color:#dc2626">
      <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $stats['tinggi'] ?></div>
        <div class="stat-label">Risiko Tinggi+</div><div class="kpi-meta"><i class="fas fa-chart-line"></i> <?= $highRiskPct ?>% dari total</div>
      </div>
    </a>
    <a href="<?= APP_URL ?>/?page=profil_risiko" class="stat-card dashboard-kpi kpi-amber" style="--stat-bg:rgba(245,158,11,.1);--stat-icon-color:#f59e0b">
      <div class="stat-icon"><i class="fas fa-fire"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $stats['sangat_tinggi'] ?></div>
        <div class="stat-label">Sangat Tinggi</div><div class="kpi-meta"><i class="fas fa-bolt"></i> Prioritas segera</div>
      </div>
    </a>
  </div>

  

  <!-- Grafik perhatian: Matriks Risiko & Tren Triwulanan -->
  <div class="zone-analisis-grid" style="margin-top:4px">

    <!-- Matriks Risiko 5x5 -->
    <div class="card" style="display:flex;flex-direction:column">
      <div class="card-header" style="background:linear-gradient(135deg,rgba(59,130,246,.05),transparent);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;padding:12px 18px">
        <div style="display:flex;align-items:center;gap:10px">
          <div>
            <span class="card-title" style="margin:0;font-size:1.02rem;font-weight:800"><i class="fas fa-th" style="color:var(--accent);margin-right:4px"></i> Matriks Risiko 5&times;5</span>
            <div id="dashMatrixSubtitle" style="font-size:.74rem;color:var(--text-muted);margin-top:2px">
              Periode: <strong id="dashMatrixPeriodLabel" style="color:var(--accent)">Kondisi Awal</strong> &bull; <span id="dashMatrixCountInfo"><?= $dashMatrixData['awal']['total'] ?? (int)$stats['total_risiko'] ?> risiko</span>
            </div>
          </div>
        </div>

        <!-- Switcher Periode Triwulanan (Dropdown Ringkas & Rapi) -->
        <div class="monev-matrix-tabs">
          <label for="dashPeriodSelect" class="matrix-period-label" style="margin-bottom:0">
            <i class="fas fa-sliders"></i> Pantau Periode:
          </label>
          <div class="matrix-select-wrap">
            <select id="dashPeriodSelect" class="form-control matrix-period-select" onchange="switchDashMatrixPeriod(this.value)">
              <option value="awal" selected>Kondisi Awal</option>
              <option value="tw1">Triwulan 1</option>
              <option value="tw2">Triwulan 2</option>
              <option value="tw3">Triwulan 3</option>
              <option value="tw4">Triwulan 4</option>
            </select>
          </div>
        </div>
      </div>
      <div class="card-body heatmap-wrap compact-heatmap" style="display:flex; flex-direction:column; align-items:center;justify-content:center;flex:1;padding:14px 12px;text-align:center">
        <?php
        $pLabels = [1=>'Jarang',2=>'Kecil',3=>'Sedang',4=>'Besar',5=>'Hampir Pasti'];
        $dLabels = [1=>'Tidak Signifikan',2=>'Kecil',3=>'Sedang',4=>'Besar',5=>'Katastropik'];
        $tingkatShort = fn($s) => $s>=20?'Sangat Tinggi':($s>=15?'Tinggi':($s>=10?'Sedang':($s>=5?'Rendah':'Sangat Rendah')));
        ?>
        <table class="heatmap dashboard-heatmap-table" id="dashHeatmapTable">
          <thead>
            <tr>
              <th style="text-align:right;padding-right:8px;font-weight:800;color:var(--text-muted)">P \ D</th>
              <?php for($d=1;$d<=5;$d++): ?>
                <th title="D<?= $d ?> = <?= $dLabels[$d] ?>" style="font-weight:800">D<?= $d ?></th>
              <?php endfor; ?>
            </tr>
          </thead>
          <tbody>
            <?php for($p=5;$p>=1;$p--): ?>
            <tr>
              <th style="text-align:right;padding-right:8px;color:var(--text-muted);font-size:.7rem;font-weight:800" title="P<?= $p ?> = <?= $pLabels[$p] ?>">P<?= $p ?></th>
              <?php for($d=1;$d<=5;$d++): ?>
              <?php
                $skor = (int)round($p*$d*getBobot($p,$d));
                $cnt  = $dashMatrixData['awal']['counts'][$p][$d] ?? 0;
                $bg   = heatmapColor($p,$d);
                $tks  = $tingkatShort($skor);
                $fg   = ($skor>=10 && $skor<=14) ? '#000' : '#fff';
              ?>
              <td id="dash_cell_<?= $p ?>_<?= $d ?>"
                  data-p="<?= $p ?>" data-d="<?= $d ?>" data-skor="<?= $skor ?>"
                  style="background:<?= $bg ?>;color:<?= $fg ?>; <?= $cnt > 0 ? 'cursor:pointer; transition: transform 0.2s, box-shadow 0.2s;' : 'cursor:default;' ?>"
                  title="P<?= $p ?> (<?= $pLabels[$p] ?>) x D<?= $d ?> (<?= $dLabels[$d] ?>) x Bobot <?= getBobot($p,$d) ?> = <?= $skor ?> &rarr; <?= $tks ?> (<?= $cnt ?> risiko)"
                  onclick="showDashHeatmapDetails(<?= $p ?>, <?= $d ?>, <?= $skor ?>)"
                  onmouseover="this.style.transform='scale(1.08)';this.style.boxShadow='0 4px 12px rgba(0,0,0,.2)'" onmouseout="this.style.transform='scale(1)';this.style.boxShadow='none'">
                <div style="font-size:1rem;font-weight:800;line-height:1"><?= $skor ?></div>
                <div style="font-size:.5rem;font-weight:600;opacity:.85;line-height:1.1;margin-top:1px;white-space:normal;word-wrap:break-word;text-align:center"><?= $tks ?></div>
                <span class="count-badge" id="dash_badge_<?= $p ?>_<?= $d ?>" style="<?= $cnt > 0 ? '' : 'display:none' ?>"><?= $cnt ?></span>
              </td>
              <?php endfor; ?>
            </tr>
            <?php endfor; ?>
          </tbody>
        </table>
        <div class="heatmap-legend compact-heatmap-legend" style="display:flex;flex-wrap:wrap;justify-content:center;gap:10px;margin-top:10px">
          <div style="font-size:.65rem;color:var(--text-muted);font-weight:700;margin-bottom:5px;width:100%;text-align:center">Nilai = P &times; D &times; Bobot</div>
          <div class="legend-item" style="display:flex;align-items:center;gap:4px;font-size:.65rem;color:var(--text-muted)"><span class="legend-dot" style="background:#dc2626;display:inline-block;width:10px;height:10px;border-radius:50%"></span>Sangat Tinggi (&ge; 20)</div>
          <div class="legend-item" style="display:flex;align-items:center;gap:4px;font-size:.65rem;color:var(--text-muted)"><span class="legend-dot" style="background:#f97316;display:inline-block;width:10px;height:10px;border-radius:50%"></span>Tinggi (15&ndash;19)</div>
          <div class="legend-item" style="display:flex;align-items:center;gap:4px;font-size:.65rem;color:var(--text-muted)"><span class="legend-dot" style="background:#FFFF00;display:inline-block;width:10px;height:10px;border-radius:50%"></span>Sedang (10&ndash;14)</div>
          <div class="legend-item" style="display:flex;align-items:center;gap:4px;font-size:.65rem;color:var(--text-muted)"><span class="legend-dot" style="background:#22c55e;display:inline-block;width:10px;height:10px;border-radius:50%"></span>Rendah (5&ndash;9)</div>
          <div class="legend-item" style="display:flex;align-items:center;gap:4px;font-size:.65rem;color:var(--text-muted)"><span class="legend-dot" style="background:#3b82f6;display:inline-block;width:10px;height:10px;border-radius:50%"></span>Sangat Rendah (1&ndash;4)</div>
        </div>
        <div class="heatmap-desc" style="margin-top:8px;font-size:.65rem;color:var(--text-muted);text-align:center;line-height:1.6;width:100%">
          <strong>P</strong> = Probabilitas (1=Jarang, 2=Kecil, 3=Sedang, 4=Besar, 5=Hampir Pasti)<br>
          <strong>D</strong> = Dampak (1=Tidak Signifikan, 2=Kecil, 3=Sedang, 4=Besar, 5=Katastropik)<br>
          <span style="font-size:.6rem;opacity:.8;display:inline-block;margin-top:4px"><i class="fas fa-hand-pointer"></i> Klik sel untuk melihat daftar risiko &bull; Ganti <strong>Pantau Periode</strong> di atas untuk memantau pergeseran risiko tiap triwulan.</span>
        </div>
      </div>
    </div>

    <!-- Tren Triwulanan (Opsi 3: Tren Skor & Komposisi Level) -->
    <div class="card dashboard-chart-card" style="display:flex;flex-direction:column">
      <div class="card-header" style="background:linear-gradient(135deg,rgba(59,130,246,.05),transparent);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;padding:12px 18px">
        <div style="display:flex;align-items:center;gap:10px">
          <div>
            <span class="card-title" style="margin:0;font-size:1.02rem;font-weight:800"><i class="fas fa-chart-line" style="color:var(--accent);margin-right:4px"></i> Tren Evaluasi Triwulanan</span>
            <div id="trenSubtitle" style="font-size:.74rem;color:var(--text-muted);margin-top:2px">
              Progres risiko &bull; <strong id="trenModeLabel" style="color:var(--accent)">Tren Penurunan Skor</strong>
            </div>
          </div>
        </div>

        <!-- Switcher Mode: Tren Skor vs Komposisi Level -->
        <div class="trend-switcher-wrap">
          <button type="button" id="btnTrendScore" class="trend-mode-btn active" onclick="switchTrendChartMode('score')" title="Lihat tren penurunan rata-rata skor risiko">
            <i class="fas fa-chart-area"></i> Tren Skor
          </button>
          <button type="button" id="btnTrendLevel" class="trend-mode-btn" onclick="switchTrendChartMode('level')" title="Lihat komposisi 5 tingkat risiko">
            <i class="fas fa-chart-simple"></i> Komposisi Level
          </button>
        </div>
      </div>

      <!-- Summary Chips (Mode-aware) -->
      <div class="trend-summary" id="trendSummaryChips" style="padding:10px 18px 0;display:flex;flex-wrap:wrap;gap:8px">
        <div id="chipsTrendScore" style="display:flex;flex-wrap:wrap;gap:8px;width:100%">
          <span class="trend-chip"><i class="fas fa-flag-checkered" style="color:var(--text-muted)"></i> Skor Awal <strong><?= number_format($baseAvgScore, 1) ?></strong></span>
          <span class="trend-chip"><i class="fas fa-chart-line" style="color:var(--accent)"></i> Terakhir (TW <?= $latestTwNum ?>) <strong><?= number_format($latestMonitoredAvg, 1) ?></strong></span>
          <span class="trend-chip"><i class="fas fa-arrow-trend-down" style="color:var(--success)"></i> Penurunan <strong style="color:var(--success)">&darr; <?= number_format($scoreReductionPct, 1) ?>%</strong></span>
          <span class="trend-chip"><i class="fas fa-bullseye" style="color:#f59e0b"></i> Target <strong>&le; 10.0 (Sedang)</strong></span>
        </div>
        <div id="chipsTrendLevel" style="display:none;flex-wrap:wrap;gap:8px;width:100%">
          <span class="trend-chip"><i class="fas fa-fire" style="color:var(--danger)"></i> Tinggi+ Awal <strong><?= $baseHighCount ?></strong></span>
          <span class="trend-chip"><i class="fas fa-shield-halved" style="color:var(--accent)"></i> Tinggi+ TW <?= $latestTwNum ?> <strong><?= $trenTotalHigh ?></strong></span>
          <span class="trend-chip"><i class="fas fa-arrow-down" style="color:var(--success)"></i> Berkurang <strong style="color:var(--success)">&darr; <?= max(0, $baseHighCount - $trenTotalHigh) ?> risiko</strong></span>
          <span class="trend-chip"><i class="fas fa-circle-check" style="color:var(--success)"></i> Sangat Tinggi <strong><?= $twLevels['Q' . $latestTwNum]['Sangat Tinggi'] ?? 0 ?></strong></span>
        </div>
      </div>

      <div class="card-body trend-chart-body" style="flex:1">
        <canvas id="trenChart"></canvas>
      </div>

      <div class="trend-quarter-note" id="trendNote">
        <i class="fas fa-circle-info" style="color:var(--accent);margin-right:4px"></i>
        <span id="trendNoteText">
          Garis hijau menunjukkan skor rata-rata risiko menurun dari <strong><?= number_format($baseAvgScore, 1) ?></strong> menjadi <strong><?= number_format($latestMonitoredAvg, 1) ?></strong> (&darr; <?= number_format($scoreReductionPct, 1) ?>%), kini berada di bawah batas selera risiko (10.0).
        </span>
      </div>
    </div>

  </div>

</div>

<!-- ══════════ ZONA 3 · OPERASIONAL ══════════ -->
<div class="dash-zone" data-zone="operasional">
  <div class="priority-recent-grid priority-single">

    <!-- Top Risiko Prioritas: panel utama -->
    <div class="card dashboard-chart-card" style="display:flex;flex-direction:column">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:nowrap; gap:10px;">
        <span class="card-title" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><i class="fas fa-ranking-star" style="color:var(--danger)"></i> Top Risiko Prioritas</span>
        <div style="display:flex; align-items:center; gap:12px; flex-shrink:0;">
            <div class="datatable-dropdown" style="margin-bottom:0;">
                <label style="margin-bottom:0; font-size:13px; font-weight:normal; display:flex; align-items:center; gap:6px; white-space:nowrap;">
                    <select class="datatable-selector" style="padding:4px 20px 4px 10px; width:65px; height:32px; flex:none;" onchange="window.location.href='<?= APP_URL ?>/?<?= $priorityPageQuery ?>&priority_limit='+this.value">
                        <option value="5" <?= $priorityPerPage == 5 ? 'selected' : '' ?>>5</option>
                        <option value="10" <?= $priorityPerPage == 10 ? 'selected' : '' ?>>10</option>
                        <option value="15" <?= $priorityPerPage == 15 ? 'selected' : '' ?>>15</option>
                        <option value="20" <?= $priorityPerPage == 20 ? 'selected' : '' ?>>20</option>
                        <option value="25" <?= $priorityPerPage == 25 ? 'selected' : '' ?>>25</option>
                    </select> Data
                </label>
            </div>
            <a href="<?= APP_URL ?>/?page=laporan_konsolidasi&tab=profil" class="btn btn-sm btn-outline" style="white-space:nowrap;">Lihat Laporan</a>
        </div>
      </div>
      <div class="card-body" style="padding:0;flex:1;display:flex;flex-direction:column">
        <?php if (!$topPrioritas): ?><div class="empty-state" style="padding:24px">Belum ada risiko untuk ditampilkan.</div><?php else: ?><div style="display:grid;grid-template-columns:1fr;gap:0;flex:1">
          <?php $priorityRankStart = $priorityOffset + 1; ?>
          <?php foreach ($topPrioritas as $rank => $priority): ?>
            <?php $priorityLevelColor = match ($priority['level_risiko']) {
              'Sangat Tinggi' => '#dc2626',
              'Tinggi' => '#f97316',
              'Sedang' => '#ca8a04',
              'Rendah' => '#16a34a',
              'Sangat Rendah' => '#3b82f6',
              default => 'var(--text-muted)',
            }; ?>
          <a href="<?= APP_URL ?>/?page=risiko&detail=<?= (int)$priority['id_risiko'] ?>" style="display:grid;grid-template-columns:46px minmax(0,1fr) 54px;gap:12px;align-items:center;min-height:68px;padding:11px 20px;text-decoration:none;color:inherit;border-bottom:1px solid var(--border);transition:background .15s" onmouseover="this.style.background='var(--surface2)'" onmouseout="this.style.background=''" title="Buka Detail &amp; Rekomendasi Mitigasi: <?= xss($priority['nama_risiko']) ?>">
            <strong style="font-size:1.05rem;color:var(--text-muted)"><?= $priorityRankStart + $rank ?></strong>
            <span style="min-width:0;font-size:.82rem;font-weight:700;line-height:1.35;overflow-wrap:anywhere">
              <?php if (!empty($priority['kode_risiko'])): ?>
                <span class="badge-kode-risiko" style="font-size:.7rem;padding:1px 6px;margin-right:6px;vertical-align:middle"><?= xss($priority['kode_risiko']) ?></span>
              <?php endif; ?>
              <?= xss($priority['nama_risiko']) ?>
              <small style="display:flex;align-items:center;flex-wrap:wrap;gap:6px 12px;color:<?= $priorityLevelColor ?>;font-size:.7rem;font-weight:700;margin-top:3px">
                <span><?= xss($priority['level_risiko']) ?></span>
                <?php if (!empty($priority['unit_pemilik_risiko'])): ?>
                <span style="color:var(--text-muted);font-weight:500;display:inline-flex;align-items:center;gap:3px"><i class="fas fa-building" style="font-size:.65rem;opacity:.7"></i> <?= xss($priority['unit_pemilik_risiko']) ?></span>
                <?php endif; ?>
              </small>
              <?php if ($priority['penanggungjawab'] || $priority['jadwal_pelaksanaan'] || $priority['rencana_penanganan']): ?>
              <small style="display:flex;flex-wrap:wrap;gap:4px 12px;margin-top:4px;color:var(--text-muted);font-size:.68rem;font-weight:500">
                <?php if ($priority['penanggungjawab']): ?><span><i class="fas fa-user"></i> <?= xss($priority['penanggungjawab']) ?></span><?php endif; ?>
                <?php if ($priority['jadwal_pelaksanaan']): ?><span><i class="fas fa-calendar"></i> <?= xss($priority['jadwal_pelaksanaan']) ?></span><?php endif; ?>
                <?php if ($priority['rencana_penanganan']): ?><span style="min-width:0"><i class="fas fa-shield-halved"></i> <?= xss($priority['rencana_penanganan']) ?></span><?php endif; ?>
              </small>
              <?php endif; ?>
            </span>
            <strong style="font-size:1.25rem;text-align:right;color:<?= (float)$priority['skor_risiko'] >= 20 ? '#dc2626' : ((float)$priority['skor_risiko'] >= 15 ? '#ea580c' : '#ca8a04') ?>" title="Skor: <?= (float)$priority['skor_risiko'] ?>"><?= (float)$priority['skor_risiko'] ?></strong>
          </a><?php endforeach; ?>
        </div><?php endif; ?>
        <?php if ($priorityTotalPages > 1): ?>
        <div class="card-footer" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;padding:14px 20px;">
          <span class="pagination-info" style="font-size:.76rem;color:var(--text-muted);font-weight:600;">
            Menampilkan <?= $priorityOffset + 1 ?>–<?= min($priorityOffset + $priorityPerPage, $priorityTotalRows) ?> dari <?= $priorityTotalRows ?> data
          </span>
          <div class="pagination" style="margin:0;gap:5px;">
            <?php
              $baseP = APP_URL . '/?' . ($priorityPageQuery ? $priorityPageQuery . '&' : '') . 'priority_page=';
              echo '<a class="page-btn ' . ($priorityPage <= 1 ? 'disabled' : '') . '" href="' . ($priorityPage <= 1 ? '#' : $baseP . '1') . '" title="Halaman Pertama"><i class="fas fa-angles-left"></i></a>';
              echo '<a class="page-btn ' . ($priorityPage <= 1 ? 'disabled' : '') . '" href="' . ($priorityPage <= 1 ? '#' : $baseP . ($priorityPage - 1)) . '" title="Halaman Sebelumnya"><i class="fas fa-chevron-left"></i></a>';
              for ($p = max(1, $priorityPage - 2); $p <= min($priorityTotalPages, $priorityPage + 2); $p++) {
                echo '<a class="page-btn ' . ($p === $priorityPage ? 'active' : '') . '" href="' . $baseP . $p . '">' . $p . '</a>';
              }
              echo '<a class="page-btn ' . ($priorityPage >= $priorityTotalPages ? 'disabled' : '') . '" href="' . ($priorityPage >= $priorityTotalPages ? '#' : $baseP . ($priorityPage + 1)) . '" title="Halaman Berikutnya"><i class="fas fa-chevron-right"></i></a>';
              echo '<a class="page-btn ' . ($priorityPage >= $priorityTotalPages ? 'disabled' : '') . '" href="' . ($priorityPage >= $priorityTotalPages ? '#' : $baseP . $priorityTotalPages) . '" title="Halaman Terakhir"><i class="fas fa-angles-right"></i></a>';
            ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function() {

const isDarkOnLoad = document.documentElement.getAttribute('data-theme') === 'dark';
if (typeof Chart !== 'undefined') {
    Chart.defaults.color = isDarkOnLoad ? '#94a3b8' : '#64748b';
    Chart.defaults.borderColor = isDarkOnLoad ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.05)';
}

// Chart instance (lazy init)
let trenChart = null;
let ringkasInitDone = false;

// -- Filter: auto-submit pencarian (debounce) agar konsisten dgn dropdown --
const searchInput = document.getElementById('heroSearchInput');
if (searchInput) {
  let debounce;
  searchInput.addEventListener('input', function() {
    const icon = document.getElementById('heroSearchIcon');
    if (icon) icon.style.color = '#3b82f6'; // highlight color when typing
    clearTimeout(debounce);
    debounce = setTimeout(function() {
      document.getElementById('heroSearchForm').submit();
    }, 450);
  });
}


// Dataset & Konfigurasi Tren Triwulanan (Opsi 3)
const trenLabels = <?= json_encode($trenLabelsOpt3) ?>;
const trenScoreData = <?= json_encode($trenScoreData) ?>;
const trenLevelDatasets = <?= json_encode($trenLevelDatasets) ?>;
const baseAvgScore = <?= json_encode(number_format($baseAvgScore, 1)) ?>;
const latestAvgScore = <?= json_encode($latestMonitoredAvg !== null ? number_format($latestMonitoredAvg, 1) : '-') ?>;
const reductionPct = <?= json_encode(number_format($scoreReductionPct, 1)) ?>;
const gridColor = isDarkOnLoad ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.05)';
window.currentTrendMode = 'score';

function getScoreChartConfig(isDark, labels, scoreData, gridColor) {
  return {
    data: {
      labels: labels,
      datasets: [
        {
          type: 'line',
          label: 'Rata-rata Skor Risiko',
          data: scoreData,
          borderColor: '#10b981',
          backgroundColor: function(context) {
            const chart = context.chart;
            const {ctx, chartArea} = chart;
            if (!chartArea) return 'rgba(16,185,129,0.18)';
            const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
            gradient.addColorStop(0, 'rgba(16,185,129,0.35)');
            gradient.addColorStop(0.7, 'rgba(16,185,129,0.08)');
            gradient.addColorStop(1, 'rgba(16,185,129,0.00)');
            return gradient;
          },
          fill: true,
          tension: 0.35,
          spanGaps: false,
          pointRadius: 6,
          pointHoverRadius: 9,
          pointBackgroundColor: isDark ? '#1e293b' : '#ffffff',
          pointBorderColor: '#10b981',
          pointBorderWidth: 3,
          borderWidth: 3,
          order: 1
        },
        {
          type: 'line',
          label: 'Batas Selera Risiko (10.0)',
          data: [10, 10, 10, 10, 10],
          borderColor: '#f59e0b',
          borderDash: [5, 5],
          borderWidth: 2,
          pointRadius: 0,
          pointHoverRadius: 0,
          fill: false,
          tension: 0,
          order: 2
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      scales: {
        y: {
          beginAtZero: true,
          suggestedMax: 18,
          grid: { color: gridColor },
          title: { display: true, text: 'Rata-rata Skor Risiko' }
        },
        x: {
          grid: { display: false }
        }
      },
      plugins: {
        legend: {
          position: 'bottom',
          labels: {
            boxWidth: 14,
            boxHeight: 14,
            usePointStyle: true,
            font: { size: 11, weight: '600' }
          }
        },
        tooltip: {
          backgroundColor: isDark ? '#1e293b' : '#fff',
          titleColor: isDark ? '#fff' : '#1e293b',
          bodyColor: isDark ? '#cbd5e1' : '#475569',
          borderColor: isDark ? 'rgba(255,255,255,.1)' : 'rgba(0,0,0,.1)',
          borderWidth: 1,
          padding: 10,
          cornerRadius: 8,
          callbacks: {
            label: function(ctx) {
              if (ctx.raw === null || ctx.raw === undefined) return null;
              if (ctx.datasetIndex === 1) return ` Batas Toleransi: ${ctx.raw} (Sedang)`;
              return ` Rata-rata Skor: ${ctx.raw} poin`;
            }
          }
        }
      }
    }
  };
}

function getLevelChartConfig(isDark, labels, levelDatasets, gridColor) {
  return {
    data: {
      labels: labels,
      datasets: [
        {
          type: 'bar',
          label: 'Sangat Rendah',
          data: levelDatasets['Sangat Rendah'],
          backgroundColor: '#3b82f6',
          borderRadius: 4,
          stack: 'level'
        },
        {
          type: 'bar',
          label: 'Rendah',
          data: levelDatasets['Rendah'],
          backgroundColor: '#22c55e',
          borderRadius: 4,
          stack: 'level'
        },
        {
          type: 'bar',
          label: 'Sedang',
          data: levelDatasets['Sedang'],
          backgroundColor: '#eab308',
          borderRadius: 4,
          stack: 'level'
        },
        {
          type: 'bar',
          label: 'Tinggi',
          data: levelDatasets['Tinggi'],
          backgroundColor: '#f97316',
          borderRadius: 4,
          stack: 'level'
        },
        {
          type: 'bar',
          label: 'Sangat Tinggi',
          data: levelDatasets['Sangat Tinggi'],
          backgroundColor: '#dc2626',
          borderRadius: 4,
          stack: 'level'
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      scales: {
        x: {
          stacked: true,
          grid: { display: false }
        },
        y: {
          stacked: true,
          beginAtZero: true,
          suggestedMax: 85,
          grid: { color: gridColor },
          title: { display: true, text: 'Jumlah Risiko' }
        }
      },
      plugins: {
        legend: {
          position: 'bottom',
          labels: {
            boxWidth: 12,
            boxHeight: 12,
            usePointStyle: true,
            font: { size: 11, weight: '600' }
          }
        },
        tooltip: {
          backgroundColor: isDark ? '#1e293b' : '#fff',
          titleColor: isDark ? '#fff' : '#1e293b',
          bodyColor: isDark ? '#cbd5e1' : '#475569',
          borderColor: isDark ? 'rgba(255,255,255,.1)' : 'rgba(0,0,0,.1)',
          borderWidth: 1,
          padding: 10,
          cornerRadius: 8,
          callbacks: {
            label: function(ctx) {
              if (ctx.raw === 0) return null;
              return ` ${ctx.dataset.label}: ${ctx.raw} risiko`;
            }
          }
        }
      }
    }
  };
}

function initRingkasanCharts() {
  if (ringkasInitDone) return;
  ringkasInitDone = true;
  if (typeof Chart === 'undefined') return;

  const trenEl = document.getElementById('trenChart');
  if (trenEl) {
    const cfg = getScoreChartConfig(isDarkOnLoad, trenLabels, trenScoreData, gridColor);
    trenChart = new Chart(trenEl.getContext('2d'), cfg);
  }
}
initRingkasanCharts();

window.switchTrendChartMode = function(mode) {
  if (!trenChart) return;
  window.currentTrendMode = mode;
  const btnScore = document.getElementById('btnTrendScore');
  const btnLevel = document.getElementById('btnTrendLevel');
  const chipsScore = document.getElementById('chipsTrendScore');
  const chipsLevel = document.getElementById('chipsTrendLevel');
  const modeLabel = document.getElementById('trenModeLabel');
  const noteText = document.getElementById('trendNoteText');

  if (mode === 'level') {
    if (btnScore) btnScore.classList.remove('active');
    if (btnLevel) btnLevel.classList.add('active');
    if (chipsScore) chipsScore.style.display = 'none';
    if (chipsLevel) chipsLevel.style.display = 'flex';
    if (modeLabel) modeLabel.textContent = 'Komposisi 5 Level Risiko';
    if (noteText) noteText.innerHTML = 'Grafik batang bertumpuk menunjukkan pergeseran komposisi tingkat risiko. Porsi risiko <strong>Tinggi &amp; Sangat Tinggi</strong> menyusut drastis dan didominasi level aman (Sedang/Rendah).';

    const cfg = getLevelChartConfig(isDarkOnLoad, trenLabels, trenLevelDatasets, gridColor);
    trenChart.data = cfg.data;
    trenChart.options = cfg.options;
    trenChart.update();
  } else {
    if (btnLevel) btnLevel.classList.remove('active');
    if (btnScore) btnScore.classList.add('active');
    if (chipsLevel) chipsLevel.style.display = 'none';
    if (chipsScore) chipsScore.style.display = 'flex';
    if (modeLabel) modeLabel.textContent = 'Tren Penurunan Skor';
    if (noteText) noteText.innerHTML = 'Garis hijau menunjukkan skor rata-rata risiko menurun dari <strong>' + baseAvgScore + '</strong> menjadi <strong>' + latestAvgScore + '</strong> (&darr; ' + reductionPct + '%), kini berada di bawah batas selera risiko (10.0).';

    const cfg = getScoreChartConfig(isDarkOnLoad, trenLabels, trenScoreData, gridColor);
    trenChart.data = cfg.data;
    trenChart.options = cfg.options;
    trenChart.update();
  }
};

// ── Data & Interaksi Matriks Risiko Dashboard (Pantau Periode) ──
window.rawDashMatrixData = <?= json_encode($dashMatrixData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.currentDashPeriod = 'awal';

window.switchDashMatrixPeriod = function(period) {
  window.currentDashPeriod = period;
  const sel = document.getElementById('dashPeriodSelect');
  if (sel && sel.value !== period) {
    sel.value = period;
  }
  const pData = window.rawDashMatrixData[period] || { label: '', total: 0, counts: {}, items: {} };
  
  const lbl = document.getElementById('dashMatrixPeriodLabel');
  const cntInfo = document.getElementById('dashMatrixCountInfo');
  if (lbl) lbl.textContent = pData.label;
  if (cntInfo) cntInfo.textContent = `${pData.total} risiko terpantau`;

  for (let p = 1; p <= 5; p++) {
    for (let d = 1; d <= 5; d++) {
      const cnt = (pData.counts && pData.counts[p] && pData.counts[p][d]) || 0;
      const cell = document.getElementById(`dash_cell_${p}_${d}`);
      const badge = document.getElementById(`dash_badge_${p}_${d}`);
      if (badge) {
        if (cnt > 0) {
          badge.textContent = cnt;
          badge.style.display = 'inline-block';
        } else {
          badge.style.display = 'none';
        }
      }
      if (cell) {
        cell.style.cursor = cnt > 0 ? 'pointer' : 'default';
        cell.style.opacity = (pData.total > 0 && cnt === 0) ? '0.78' : '1';
        cell.title = `P${p} x D${d} = ${cell.dataset.skor} (${cnt} risiko pada ${pData.label})`;
      }
    }
  }
};

window.showDashHeatmapDetails = function(p, d, skor) {
  const pData = window.rawDashMatrixData[window.currentDashPeriod];
  const items = (pData && pData.items && pData.items[p] && pData.items[p][d]) || [];
  
  if (!items || items.length === 0) {
    Swal.fire({
      title: `Sel P${p} x D${d} = ${skor}`,
      text: `Tidak ada risiko pada koordinat ini untuk periode ${pData ? pData.label : ''}.`,
      icon: 'info',
      confirmButtonText: 'Tutup'
    });
    return;
  }

  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
  let html = '<div style="max-height: 320px; overflow-y: auto; text-align: left; margin-top:8px">';
  html += '<table class="data-table" style="width: 100%; border-collapse: collapse; font-size:.82rem">';
  html += '<thead style="position: sticky; top: 0; background: var(--surface2); z-index:2"><tr>' +
          '<th style="padding:8px;border-bottom:1px solid var(--border);width:75px">Kode</th>' +
          '<th style="padding:8px;border-bottom:1px solid var(--border)">Nama Risiko</th>' +
          '<th style="padding:8px;border-bottom:1px solid var(--border);text-align:center;width:95px">Level</th>' +
          '<th style="padding:8px;border-bottom:1px solid var(--border);text-align:center;width:55px">Skor</th>' +
          '</tr></thead><tbody>';

  items.forEach(item => {
    let bgC = '#3b82f6', fgC = '#fff';
    if (item.tingkat === 'Sangat Tinggi') bgC = '#dc2626';
    else if (item.tingkat === 'Tinggi') bgC = '#f97316';
    else if (item.tingkat === 'Sedang') { bgC = '#FFFF00'; fgC = '#1e293b'; }
    else if (item.tingkat === 'Rendah') bgC = '#22c55e';

    const kode = escapeHtml(item.kode);
    const nama = escapeHtml(item.nama);
    const level = escapeHtml(item.tingkat);
    const nilai = escapeHtml(item.nilai);
    const unit = escapeHtml(item.unit);
    const id = item.id;
    const namaLink = id
      ? `<a href="<?= APP_URL ?>/?page=risiko&detail=${encodeURIComponent(id)}" style="color:var(--accent);font-weight:700;text-decoration:none" title="Buka detail ${nama}">${nama}</a>`
      : `<span style="font-weight:700;color:var(--text)">${nama}</span>`;

    html += `<tr>
      <td style="padding:8px;border-bottom:1px solid var(--border)"><code style="color:var(--accent);font-size:0.8rem">${kode}</code></td>
      <td style="padding:8px;border-bottom:1px solid var(--border);max-width:240px;white-space:normal">
        <div>${namaLink}</div>
        <div style="font-size:.7rem;color:var(--text-muted);margin-top:2px"><i class="fas fa-building" style="opacity:.6"></i> ${unit}</div>
      </td>
      <td style="padding:8px;border-bottom:1px solid var(--border);text-align:center">
        <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:0.72rem;font-weight:700;background:${bgC};color:${fgC}">${level}</span>
      </td>
      <td style="padding:8px;border-bottom:1px solid var(--border);text-align:center;font-weight:800;color:var(--accent)">
        ${nilai}
      </td>
    </tr>`;
  });
  html += '</tbody></table></div>';

  Swal.fire({
    title: `Daftar Risiko (${pData.label} — P${p} x D${d} = ${skor})`,
    html: html,
    width: 620,
    showCloseButton: true,
    showConfirmButton: false
  });
};

// Alias untuk kompatibilitas
window.showHeatmapDetails = window.showDashHeatmapDetails;

}); // end DOMContentLoaded
</script>

<style>
/* Switcher Periode Dropdown Ringkas & Rapi */
.monev-matrix-tabs {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: var(--surface);
  padding: 4px 10px 4px 12px;
  border-radius: 10px;
  border: 1px solid var(--border);
  box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.matrix-period-label {
  font-size: .78rem;
  font-weight: 700;
  color: var(--text-muted);
  display: flex;
  align-items: center;
  gap: 5px;
  white-space: nowrap;
}
.matrix-period-label i {
  color: var(--accent);
}
.matrix-select-wrap {
  position: relative;
  display: inline-block;
}
.matrix-period-select {
  font-size: .8rem !important;
  font-weight: 700 !important;
  color: var(--text) !important;
  background-color: var(--surface2) !important;
  border: 1px solid var(--border) !important;
  border-radius: 8px !important;
  padding: 4px 28px 4px 10px !important;
  height: 32px !important;
  line-height: 1.2 !important;
  cursor: pointer !important;
  outline: none !important;
  appearance: none !important;
  -webkit-appearance: none !important;
  transition: all .2s ease;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2364748b'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E") !important;
  background-repeat: no-repeat !important;
  background-position: right 8px center !important;
  background-size: 14px 14px !important;
}
.matrix-period-select:hover {
  border-color: var(--accent) !important;
  background-color: var(--surface) !important;
}
.matrix-period-select:focus {
  border-color: var(--accent) !important;
  box-shadow: 0 0 0 3px rgba(59,130,246,0.18) !important;
}

/* Switcher Mode: Tren Skor vs Komposisi Level */
.trend-switcher-wrap {
  display: inline-flex;
  align-items: center;
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: 9px;
  padding: 3px;
  gap: 3px;
}
.trend-mode-btn {
  font-size: .75rem !important;
  font-weight: 700 !important;
  padding: 4px 10px !important;
  border-radius: 7px !important;
  border: none !important;
  background: transparent !important;
  color: var(--text-muted) !important;
  cursor: pointer;
  transition: all .2s ease;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  line-height: 1.2;
}
.trend-mode-btn:hover {
  color: var(--text) !important;
}
.trend-mode-btn.active {
  background: var(--primary) !important;
  color: #fff !important;
  box-shadow: 0 2px 6px rgba(59, 130, 246, 0.3) !important;
}

.heatmap-wrap.compact-heatmap{overflow:visible!important}
.dashboard-heatmap-table{width:100%!important;table-layout:fixed;border-collapse:separate;border-spacing:4px}
.dashboard-heatmap-table th,.dashboard-heatmap-table td{box-sizing:border-box}
.dashboard-toolbar .search-bar.is-loading::after{content:'';position:absolute;right:34px;top:50%;width:14px;height:14px;margin-top:-7px;border:2px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
@media(max-width:900px){
  .zone-analisis-grid{grid-template-columns:1fr!important}
  .heatmap{table-layout:fixed;border-spacing:3px;width:100%!important}
  .heatmap td{height:42px!important;font-size:.75rem!important;width:auto!important;min-width:0!important;max-width:none!important}
  .heatmap th{padding:4px 6px!important;font-size:.6rem!important;width:auto!important;min-width:0!important;max-width:none!important}
}
@media(min-width:901px){
  .compact-heatmap{align-items:flex-start!important;padding-left:20px!important}
  .dashboard-heatmap-table{margin-left:0!important;margin-right:0!important;text-align:center;max-width:none}
  .dashboard-heatmap-table th,.dashboard-heatmap-table td{text-align:center!important;vertical-align:middle!important;width:auto!important;min-width:0!important;max-width:none!important}
  .dashboard-heatmap-table thead th{height:30px!important;padding:4px!important}
  .dashboard-heatmap-table tbody th{height:58px!important;padding:4px!important}
  .compact-heatmap .heatmap{width:100%!important;max-width:none;table-layout:fixed}
  .compact-heatmap .heatmap td{height:58px!important;padding:6px!important;width:auto!important;min-width:0!important;max-width:none!important}
  .compact-heatmap .heatmap td>div:first-child{font-size:1.1rem!important}
  .compact-heatmap .heatmap td>div:nth-child(2){font-size:.56rem!important}
  .compact-heatmap .heatmap th{font-size:.72rem!important;padding:6px!important;width:auto!important;min-width:0!important;max-width:none!important}
  .compact-heatmap-legend{display:flex;flex-wrap:wrap;justify-content:flex-start;gap:6px 12px;margin-top:10px!important;max-width:none;margin-left:0}
  .compact-heatmap-legend>div:first-child{flex-basis:100%}
  .compact-heatmap-legend .legend-item{font-size:.72rem!important;gap:4px!important}
  .compact-heatmap-legend .legend-dot{width:10px!important;height:10px!important}
  .heatmap-desc{text-align:center!important;width:100%!important}
}
@media(max-width:560px){
  .priority-recent-grid .card:first-child a{grid-template-columns:34px minmax(0,1fr) 42px!important;padding-left:12px;padding-right:12px}
  .compact-heatmap{align-items:center!important;padding-left:12px!important}
  .dashboard-heatmap-table{margin-left:auto!important;margin-right:auto!important;table-layout:fixed;border-spacing:3px}
}
.priority-recent-grid.priority-single{grid-template-columns:1fr}

/* ── Penyempurnaan hero & kartu KPI ─────────────────────────── */
.dashboard-hero{
  background:
    radial-gradient(120% 130% at 100% 0%, rgba(56,189,248,.24), transparent 52%),
    radial-gradient(90% 130% at 0% 110%, rgba(45,212,191,.16), transparent 55%),
    linear-gradient(115deg,#0b1e3a 0%,#163b68 55%,#1a5f7a 100%) !important;
  box-shadow:0 22px 48px -22px rgba(13,42,78,.65);
  border:1px solid rgba(255,255,255,.07);
}
.dashboard-eyebrow{
  background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.16);
  -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px);
  padding:6px 14px; letter-spacing:.14em;
}
/* ── Bar pencarian dashboard kini di navbar (header.php) ───── */.dashboard-hero-primary,.dashboard-hero-secondary{ transition:transform .2s ease, background .2s ease, box-shadow .2s ease; }
.dashboard-hero-primary:hover{ transform:translateY(-2px); box-shadow:0 14px 28px -10px rgba(0,0,0,.5); }
.dashboard-hero-secondary:hover{ transform:translateY(-2px); }

.dashboard-kpi{
  border-radius:18px !important; border:1px solid var(--border);
  background:
    radial-gradient(130% 120% at 100% 0%, var(--stat-bg, rgba(59,130,246,.08)), transparent 58%),
    var(--surface) !important;
  box-shadow:0 8px 20px -12px rgba(15,23,42,.25);
  transition:transform .25s cubic-bezier(.4,0,.2,1), box-shadow .25s, border-color .25s;
}
.dashboard-kpi::before{ width:5px !important; opacity:1 !important; top:14%; bottom:14%; border-radius:99px; }
.dashboard-kpi:hover{
  transform:translateY(-4px) !important;
  border-color:var(--stat-icon-color) !important;
  box-shadow:0 24px 44px -22px rgba(15,23,42,.42), 0 6px 16px -10px rgba(15,23,42,.2) !important;
}
.dashboard-kpi .stat-icon{
  box-shadow:0 8px 18px -8px var(--stat-bg, rgba(15,23,42,.2));
  transition:transform .25s;
}
.dashboard-kpi:hover .stat-icon{ transform:scale(1.09) rotate(-4deg); }
.kpi-meta{
  display:inline-flex; align-items:center; gap:5px;
  background:var(--stat-bg, rgba(148,163,184,.12)); color:var(--text-muted);
  font-size:.64rem; font-weight:700; padding:3px 10px; border-radius:99px; margin-top:10px;
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.kpi-meta i{ opacity:.85; margin-right:0; }
@media(min-width:701px){
  .dashboard-hero{ padding:30px 32px; border-radius:22px; }
  .dashboard-hero h1{ font-size:clamp(1.2rem,2vw,1.72rem); }
  .dashboard-hero-actions{ gap:14px; }
  .dashboard-hero-actions > .btn{ height:52px; min-width:180px; border-radius:14px; }
  .dashboard-kpi .stat-icon{ width:48px; height:48px; border-radius:14px; }
  .dashboard-kpi .stat-value{ font-size:2.05rem !important; }
}

/* ── Rapikan panel persetujuan (menyatu dgn kartu KPI) ─────── */
.dash-pending-panel{ gap:16px; margin-bottom:18px; }
.dash-pending-col{
  position:relative; border-radius:16px; min-width:0;
  padding:18px 18px 16px;
  background:linear-gradient(180deg, var(--surface2), var(--surface) 55%);
  border:1px solid var(--border);
  box-shadow:0 8px 22px -16px rgba(15,23,42,.35);
  overflow:hidden;
}
.dash-pending-col::before{ content:''; position:absolute; left:0; top:14%; bottom:14%; width:4px; border-radius:99px; background:var(--accent); }
.dash-pending-col:has(.dp-warn)::before{ background:var(--warning); }
.dash-pending-col:has(.dp-info)::before{ background:var(--info); }
.dash-pending-head{ margin-bottom:14px; }
.dash-pending-head .dp-icon{ box-shadow:0 6px 16px -8px rgba(15,23,42,.35); }
/* Daftar item responsif: rapat & memakai lebar kartu */
.dash-pending-list{ display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:8px; }
.dash-pending-item{ padding:10px 12px; border-radius:12px; background:var(--surface); border:1px solid var(--border); }
.dash-pending-item:hover{ transform:translateX(2px); }
.dash-pending-item .dpi-icon{ width:32px; height:32px; border-radius:9px; }
.dash-pending-item .dpi-judul{ font-size:.79rem; white-space:normal; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
@media(max-width:700px){ .dash-pending-list{ grid-template-columns:1fr; } }
</style>
