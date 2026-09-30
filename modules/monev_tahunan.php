<?php
/**
 * MODUL Monev Triwulanan & Tahunan — Premium UX Redesign
 * Fitur: segmented control periode, insight banner, progress kelengkapan,
 * stat cards klik-untuk-filter, chart tren TW1-4,
 * tabel dengan highlight delta + pagination, modal isi monev dengan
 * navigasi antar-risiko (Sebelumnya/Berikutnya).
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
requireRole('Admin', 'Risk Manager', 'Pimpinan', 'Staff');
$db = getDB();
$chkReal = $db->query("SHOW COLUMNS FROM monev_triwulan LIKE 'realisasi_pengendalian'");
if ($chkReal && $chkReal->num_rows === 0) {
    $db->query("ALTER TABLE monev_triwulan ADD COLUMN realisasi_pengendalian TEXT NULL AFTER upaya_pengendalian");
}

$activeTahun = trim((string)($_GET['tahun'] ?? date('Y')));
$tahunList = getDaftarTahun($db, 'kkpr_header', [$activeTahun]);
if (!in_array($activeTahun, $tahunList, true) && !empty($tahunList)) {
    $activeTahun = $tahunList[0];
}
$jenisLaporan = $_GET['jenis'] ?? 'tw1';
if (!in_array($jenisLaporan, ['tw1', 'tw2', 'tw3', 'tw4', 'tahunan'], true)) $jenisLaporan = 'tw1';
$twTarget = $jenisLaporan === 'tahunan' ? 4 : (int)substr($jenisLaporan, 2);
$canInput = hasRole('Admin', 'Risk Manager', 'Staff');
$jenisLabel = $jenisLaporan === 'tahunan' ? 'Monev Tahunan (Awal vs TW4)' : 'Monev Triwulan ' . $twTarget;
// Tampilkan kolom hasil pengisian triwulan sebelumnya (TW n-1) untuk tw2-4 non-tahunan
$showPrevQ = $twTarget > 1 && $jenisLaporan !== 'tahunan';

// ── Handler simpan (logika bisnis tidak berubah) ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'simpan_monev') {
    if (!$canInput || !verifyCsrf()) {
        setFlash('error', 'Anda tidak memiliki akses atau token tidak valid.');
        header('Location: ' . APP_URL . '/?page=monev_tahunan'); exit;
    }

    $idRisiko = (int)($_POST['id_risiko'] ?? 0);
    $tahunPost = trim((string)($_POST['tahun'] ?? $activeTahun));
    $jenisPost = $_POST['jenis'] ?? $jenisLaporan;
    $twPost = $jenisPost === 'tahunan' ? 4 : (int)substr($jenisPost, 2);
    $ownerSqlPost = canAccessAllRecords() ? '' : ' AND h.created_by = ?';
    $check = $db->prepare("SELECT r.id FROM kkpr_risiko r JOIN kkpr_header h ON h.id=r.id_kkpr WHERE r.id=? AND h.tahun=? $ownerSqlPost LIMIT 1");
    if ($ownerSqlPost !== '') {
        $uid = (int)$_SESSION['user_id']; $check->bind_param('isi', $idRisiko, $tahunPost, $uid);
    } else $check->bind_param('is', $idRisiko, $tahunPost);
    $check->execute(); $allowedRisk = $check->get_result()->fetch_assoc(); $check->close();
    if (!$allowedRisk || $twPost < 1 || $twPost > 4) {
        setFlash('error', 'Risiko atau periode tidak valid.');
        header('Location: ' . APP_URL . '/?page=monev_tahunan&tahun=' . urlencode($tahunPost) . '&jenis=' . urlencode($jenisPost)); exit;
    }

    $pp = max(1, min(5, (int)($_POST['pantau_p'] ?? 1)));
    $pd = max(1, min(5, (int)($_POST['pantau_d'] ?? 1)));
    $pb = getBobot($pp, $pd); $pNilai = round($pp * $pd * $pb); $pTingkat = getLevelRisiko($pNilai);
    $upaya = trim($_POST['upaya_pengendalian'] ?? '');
    $realisasi = trim($_POST['realisasi_pengendalian'] ?? '');
    $link = trim($_POST['link_data_dukung'] ?? '');
    $kendala = trim($_POST['kendala'] ?? ''); $rtl = trim($_POST['rencana_tindak_lanjut'] ?? '');
    // Simpulan & efektivitas dibandingkan dengan PENILAIAN AWAL (konsisten dengan
    // laporan_monev dan modul KKPMR) — bukan triwulan sebelumnya.
    $previous = $db->prepare('SELECT nilai_risiko AS nilai FROM kkpr_risiko WHERE id=?');
    $previous->bind_param('i', $idRisiko);
    $previous->execute(); $previousValue = (float)($previous->get_result()->fetch_assoc()['nilai'] ?? 0); $previous->close();
    $simpulan = $pNilai < $previousValue ? 'Tingkat risiko mengalami penurunan' : ($pNilai > $previousValue ? 'Tingkat risiko mengalami peningkatan' : 'Tingkat risiko tetap');
    $efektifitas = $pNilai < $previousValue ? 'Efektif' : 'Tidak Efektif';
    $uid = (int)$_SESSION['user_id'];
    $upsert = $db->prepare('INSERT INTO monev_triwulan (id_risiko,triwulan,pantau_p,pantau_d,pantau_bobot,pantau_nilai,pantau_tingkat,upaya_pengendalian,realisasi_pengendalian,link_data_dukung,simpulan_tingkat,efektifitas,kendala,rencana_tindak_lanjut,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE pantau_p=VALUES(pantau_p),pantau_d=VALUES(pantau_d),pantau_bobot=VALUES(pantau_bobot),pantau_nilai=VALUES(pantau_nilai),pantau_tingkat=VALUES(pantau_tingkat),upaya_pengendalian=VALUES(upaya_pengendalian),realisasi_pengendalian=VALUES(realisasi_pengendalian),link_data_dukung=VALUES(link_data_dukung),simpulan_tingkat=VALUES(simpulan_tingkat),efektifitas=VALUES(efektifitas),kendala=VALUES(kendala),rencana_tindak_lanjut=VALUES(rencana_tindak_lanjut),created_by=VALUES(created_by)');
    $upsert->bind_param('iiiddsssssssssi', $idRisiko, $twPost, $pp, $pd, $pb, $pNilai, $pTingkat, $upaya, $realisasi, $link, $simpulan, $efektifitas, $kendala, $rtl, $uid);
    $upsert->execute(); $upsert->close();
    setFlash('success', 'Monev berhasil disimpan.');
    header('Location: ' . APP_URL . '/?page=monev_tahunan&tahun=' . urlencode($tahunPost) . '&jenis=' . urlencode($jenisPost) . '#monev-table'); exit;
}

// ── Ambil data (logika query tidak berubah) ──────────────────────────────────
$fQ = trim($_GET['q'] ?? '');
$ownerSql = canAccessAllRecords() ? '' : ' AND h.created_by = ?';
$searchSql = '';
if ($fQ !== '') {
    $searchSql = ' AND (r.nama_risiko LIKE ? OR r.kode_risiko LIKE ? OR h.unit_pemilik_risiko LIKE ?)';
}
$s2 = $db->prepare("SELECT r.*, h.unit_pemilik_risiko, h.nama_pemilik_risiko FROM kkpr_risiko r JOIN kkpr_header h ON r.id_kkpr = h.id WHERE h.tahun = ? $ownerSql $searchSql ORDER BY SUBSTRING_INDEX(r.kode_risiko, '.', 1) ASC, CAST(SUBSTRING_INDEX(r.kode_risiko, '.', -1) AS UNSIGNED) ASC, r.kode_risiko ASC, h.unit_pemilik_risiko, r.no_urut");

if ($ownerSql !== '' && $fQ !== '') {
    $userId = (int)$_SESSION['user_id'];
    $qParam = "%{$fQ}%";
    $s2->bind_param('sisss', $activeTahun, $userId, $qParam, $qParam, $qParam);
} elseif ($ownerSql !== '') {
    $userId = (int)$_SESSION['user_id'];
    $s2->bind_param('si', $activeTahun, $userId);
} elseif ($fQ !== '') {
    $qParam = "%{$fQ}%";
    $s2->bind_param('ssss', $activeTahun, $qParam, $qParam, $qParam);
} else {
    $s2->bind_param('s', $activeTahun);
}
$s2->execute();
$baseRisks = $s2->get_result()->fetch_all(MYSQLI_ASSOC); $s2->close();

$m_s = $db->prepare("SELECT m.* FROM monev_triwulan m JOIN kkpr_risiko r ON r.id = m.id_risiko JOIN kkpr_header h ON h.id = r.id_kkpr WHERE m.triwulan=? AND h.tahun=?");
$m_s->bind_param('is', $twTarget, $activeTahun);
$m_s->execute();
$monevCurrRaw = $m_s->get_result()->fetch_all(MYSQLI_ASSOC); $m_s->close();
$monevCurr = []; foreach ($monevCurrRaw as $m) $monevCurr[$m['id_risiko']] = $m;

// Hasil pengisian triwulan sebelumnya (untuk kolom tampilan & prefill modal)
$monevPrev = [];
if ($showPrevQ) {
    $twPrev = $twTarget - 1;
    $m_p = $db->prepare("SELECT m.* FROM monev_triwulan m JOIN kkpr_risiko r ON r.id = m.id_risiko JOIN kkpr_header h ON h.id = r.id_kkpr WHERE m.triwulan=? AND h.tahun=?");
    $m_p->bind_param('is', $twPrev, $activeTahun); $m_p->execute();
    $monevPrevRaw = $m_p->get_result()->fetch_all(MYSQLI_ASSOC); $m_p->close();
    foreach ($monevPrevRaw as $m) $monevPrev[$m['id_risiko']] = $m;
}

$rows = [];
foreach ($baseRisks as $r) {
    $idr = $r['id']; $curr = $monevCurr[$idr] ?? null; $pq = $monevPrev[$idr] ?? null;

    // Baseline pembanding = PENILAIAN AWAL untuk semua periode (tw1-tw4 & tahunan),
    // konsisten dengan laporan_monev, KKPMR, dan mode tahunan.
    $pA = $r['probabilitas']; $dA = $r['dampak_level']; $bA = $r['bobot']; $nA = $r['nilai_risiko']; $tA = $r['tingkat_risiko']; $prioA = $r['prioritas_risiko'] ?? '-';
    $linkPrev = '-';

    $r['prev_p'] = $pA; $r['prev_d'] = $dA; $r['prev_bobot'] = $bA; $r['prev_nilai'] = $nA; $r['prev_tingkat'] = $tA; $r['prev_prio'] = $prioA; $r['prev_link'] = $linkPrev;
    // Kondisi pengisian triwulan sebelumnya (null bila belum diisi)
    $r['prevq'] = $pq;
    $r['curr'] = $curr;
    $rows[] = $r;
}

// ── Statistik ────────────────────────────────────────────────────────────────
$chartStats = ['total' => count($rows), 'terpantau' => 0, 'efektif' => 0, 'tidak_efektif' => 0, 'belum' => 0, 'penurunan' => 0, 'eskalasi' => 0];
$chartBaseline = 0; $chartCurrent = 0;
$chartMonitoredBaseline = 0;
$levelCounts = ['Sangat Tinggi' => 0, 'Tinggi' => 0, 'Sedang' => 0, 'Rendah' => 0, 'Sangat Rendah' => 0];
foreach ($rows as $chartRow) {
    $chartBaseline += (float)($chartRow['prev_nilai'] ?? 0);
    if (!empty($chartRow['curr']) && $chartRow['curr']['pantau_nilai'] !== null) {
        $chartStats['terpantau']++;
        $chartCurrent += (float)$chartRow['curr']['pantau_nilai'];
        $chartMonitoredBaseline += (float)($chartRow['prev_nilai'] ?? 0);
        if (($chartRow['curr']['efektifitas'] ?? '') === 'Efektif') $chartStats['efektif']++;
        if (($chartRow['curr']['efektifitas'] ?? '') === 'Tidak Efektif') $chartStats['tidak_efektif']++;
        if ((float)$chartRow['curr']['pantau_nilai'] < (float)($chartRow['prev_nilai'] ?? 0)) $chartStats['penurunan']++;
        if ((float)$chartRow['curr']['pantau_nilai'] > (float)($chartRow['prev_nilai'] ?? 0)) $chartStats['eskalasi']++;
        $level = (string)($chartRow['curr']['pantau_tingkat'] ?? '');
        if (isset($levelCounts[$level])) $levelCounts[$level]++;
    } else $chartStats['belum']++;
}
$chartAvgBaseline = $chartStats['terpantau'] ? round($chartMonitoredBaseline / $chartStats['terpantau'], 2) : ($chartStats['total'] ? round($chartBaseline / $chartStats['total'], 2) : 0);
$chartAvgCurrent = $chartStats['terpantau'] ? round($chartCurrent / $chartStats['terpantau'], 2) : 0;
$chartDecreasePct = $chartAvgBaseline > 0 && $chartStats['terpantau'] ? round((($chartAvgBaseline - $chartAvgCurrent) / $chartAvgBaseline) * 100) : 0;
$trendLabels = ['Triwulan 1', 'Triwulan 2', 'Triwulan 3', 'Triwulan 4'];
$trendValues = [null, null, null, null];
$allMonevSql = "SELECT m.triwulan, AVG(m.pantau_nilai) AS avg_nilai FROM monev_triwulan m JOIN kkpr_risiko r ON r.id=m.id_risiko JOIN kkpr_header h ON h.id=r.id_kkpr WHERE h.tahun=? AND m.pantau_nilai IS NOT NULL" . ($ownerSql !== '' ? ' AND h.created_by=?' : '') . " GROUP BY m.triwulan ORDER BY m.triwulan";
$trendStmt = $db->prepare($allMonevSql);
if ($ownerSql !== '') { $trendUid = (int)$_SESSION['user_id']; $trendStmt->bind_param('si', $activeTahun, $trendUid); }
else $trendStmt->bind_param('s', $activeTahun);
$trendStmt->execute();
foreach ($trendStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $trendRow) $trendValues[(int)$trendRow['triwulan'] - 1] = round((float)$trendRow['avg_nilai'], 2);
$trendStmt->close();

// ── Data Matriks Risiko 5x5 Triwulanan ─────────────────────────────
$allTwQuery = $db->prepare("SELECT m.*, r.nama_risiko, r.kode_risiko, h.unit_pemilik_risiko FROM monev_triwulan m JOIN kkpr_risiko r ON r.id=m.id_risiko JOIN kkpr_header h ON h.id=r.id_kkpr WHERE h.tahun=?" . ($ownerSql !== '' ? ' AND h.created_by=?' : ''));
if ($ownerSql !== '') {
    $allTwUid = (int)$_SESSION['user_id'];
    $allTwQuery->bind_param('si', $activeTahun, $allTwUid);
} else {
    $allTwQuery->bind_param('s', $activeTahun);
}
$allTwQuery->execute();
$allTwRows = $allTwQuery->get_result()->fetch_all(MYSQLI_ASSOC);
$allTwQuery->close();

$twByRisk = [];
foreach ($allTwRows as $twItem) {
    $twByRisk[(int)$twItem['triwulan']][(int)$twItem['id_risiko']] = $twItem;
}

$emptyLevels = [
    'Sangat Tinggi' => 0,
    'Tinggi'        => 0,
    'Sedang'        => 0,
    'Rendah'        => 0,
    'Sangat Rendah' => 0,
];

$matrixData = [
    'awal' => ['label' => 'Kondisi Awal (Baseline KKPR)', 'counts' => [], 'items' => [], 'total' => 0, 'levels' => $emptyLevels],
    'tw1'  => ['label' => 'Triwulan 1', 'counts' => [], 'items' => [], 'total' => 0, 'levels' => $emptyLevels],
    'tw2'  => ['label' => 'Triwulan 2', 'counts' => [], 'items' => [], 'total' => 0, 'levels' => $emptyLevels],
    'tw3'  => ['label' => 'Triwulan 3', 'counts' => [], 'items' => [], 'total' => 0, 'levels' => $emptyLevels],
    'tw4'  => ['label' => 'Triwulan 4', 'counts' => [], 'items' => [], 'total' => 0, 'levels' => $emptyLevels],
];

for ($p = 1; $p <= 5; $p++) {
    for ($d = 1; $d <= 5; $d++) {
        foreach (['awal', 'tw1', 'tw2', 'tw3', 'tw4'] as $k) {
            $matrixData[$k]['counts'][$p][$d] = 0;
            $matrixData[$k]['items'][$p][$d] = [];
        }
    }
}

// 1. Matriks Awal (Baseline)
foreach ($rows as $r) {
    $p = max(1, min(5, (int)($r['probabilitas'] ?? 1)));
    $d = max(1, min(5, (int)($r['dampak_level'] ?? 1)));
    $skor = (int)round((float)($r['nilai_risiko'] ?? ($p * $d * getBobot($p, $d))));
    $level = trim((string)($r['tingkat_risiko'] ?: getLevelRisiko($skor)));
    $matrixData['awal']['counts'][$p][$d]++;
    if (isset($matrixData['awal']['levels'][$level])) {
        $matrixData['awal']['levels'][$level]++;
    }
    $matrixData['awal']['items'][$p][$d][] = [
        'id' => $r['id'],
        'kode' => $r['kode_risiko'] ?: '-',
        'nama' => $r['nama_risiko'] ?: '-',
        'unit' => $r['unit_pemilik_risiko'] ?: '-',
        'p' => $p,
        'd' => $d,
        'nilai' => $skor,
        'tingkat' => $level,
        'status' => 'Baseline Awal'
    ];
    $matrixData['awal']['total']++;
}

// 2. Matriks TW1..TW4
for ($tw = 1; $tw <= 4; $tw++) {
    $k = 'tw' . $tw;
    foreach ($rows as $r) {
        $twData = $twByRisk[$tw][$r['id']] ?? null;
        if ($twData && $twData['pantau_nilai'] !== null && $twData['pantau_p'] !== null && $twData['pantau_d'] !== null) {
            $p = max(1, min(5, (int)$twData['pantau_p']));
            $d = max(1, min(5, (int)$twData['pantau_d']));
            $skor = (int)round((float)$twData['pantau_nilai']);
            $level = trim((string)($twData['pantau_tingkat'] ?: getLevelRisiko($skor)));
            $matrixData[$k]['counts'][$p][$d]++;
            if (isset($matrixData[$k]['levels'][$level])) {
                $matrixData[$k]['levels'][$level]++;
            }
            $matrixData[$k]['items'][$p][$d][] = [
                'id' => $r['id'],
                'kode' => $r['kode_risiko'] ?: '-',
                'nama' => $r['nama_risiko'] ?: '-',
                'unit' => $r['unit_pemilik_risiko'] ?: '-',
                'p' => $p,
                'd' => $d,
                'nilai' => $skor,
                'tingkat' => $level,
                'efektifitas' => $twData['efektifitas'] ?? '-',
                'simpulan' => $twData['simpulan_tingkat'] ?? '-',
                'status' => 'Dipantau'
            ];
            $matrixData[$k]['total']++;
        }
    }
}

// ── Data pendukung UI ────────────────────────────────────────────────────────
$pctTerisi = $chartStats['total'] > 0 ? round($chartStats['terpantau'] / $chartStats['total'] * 100) : 0;
$pctEfektif = $chartStats['terpantau'] > 0 ? round($chartStats['efektif'] / $chartStats['terpantau'] * 100) : 0;
$pctEskalasi = $chartStats['terpantau'] > 0 ? round($chartStats['eskalasi'] / $chartStats['terpantau'] * 100) : 0;
$pctPenurunan = $chartStats['terpantau'] > 0 ? round($chartStats['penurunan'] / $chartStats['terpantau'] * 100) : 0;
$selisihAvg = round($chartAvgBaseline - $chartAvgCurrent, 2);

// Bobot dari satu sumber kebenaran (PHP), dikonsumsi JS
$bobotJs = [];
foreach ([1, 2, 3, 4, 5] as $bp) foreach ([1, 2, 3, 4, 5] as $bd) $bobotJs[$bp][$bd] = getBobot($bp, $bd);

// Baris ringkas untuk modal navigasi
$jsRows = [];
foreach ($rows as $r) {
    $c = $r['curr'];

    // Upaya Pengendalian: jika TW aktif belum terisi, otomatis prefill dari triwulan sebelumnya (TW n-1 .. TW 1) atau KKPR
    $upayaPrefill = '';
    if ($c && !empty(trim((string)($c['upaya_pengendalian'] ?? '')))) {
        $upayaPrefill = (string)$c['upaya_pengendalian'];
    } else {
        $searchTw = ($jenisLaporan === 'tahunan') ? 4 : $twTarget;
        for ($t = $searchTw; $t >= 1; $t--) {
            $prevUpaya = $twByRisk[$t][$r['id']]['upaya_pengendalian'] ?? null;
            if ($prevUpaya !== null && trim((string)$prevUpaya) !== '') {
                $upayaPrefill = (string)$prevUpaya;
                break;
            }
        }
        if ($upayaPrefill === '') {
            if (!empty($r['pengendalian_uraian']) && trim((string)$r['pengendalian_uraian']) !== '') {
                $upayaPrefill = (string)$r['pengendalian_uraian'];
            } elseif (!empty($r['rpti_uraian']) && trim((string)$r['rpti_uraian']) !== '') {
                $upayaPrefill = (string)$r['rpti_uraian'];
            }
        }
    }

    $jsRows[] = [
        'id'    => (int)$r['id'],
        'nama'  => (string)$r['nama_risiko'],
        'unit'  => (string)($r['unit_pemilik_risiko'] ?? ''),
        'p'     => $c ? (int)$c['pantau_p'] : ($r['prevq'] ? (int)$r['prevq']['pantau_p'] : (int)$r['prev_p']),
        'd'     => $c ? (int)$c['pantau_d'] : ($r['prevq'] ? (int)$r['prevq']['pantau_d'] : (int)$r['prev_d']),
        'upaya' => $upayaPrefill,
        'realisasi' => (string)($c['realisasi_pengendalian'] ?? ''),
        'link'  => (string)($c['link_data_dukung'] ?? ''),
        'kendala' => (string)($c['kendala'] ?? ''),
        'rtl'   => (string)($c['rencana_tindak_lanjut'] ?? ''),
    ];
}

// Helper render (closure — aman dari kolisi nama fungsi)
$levelPill = function (?string $level): string {
    if ($level === null || $level === '') return '<span style="color:var(--text-muted)">–</span>';
    $map = ['Sangat Tinggi' => 'lp-st', 'Tinggi' => 'lp-t', 'Sedang' => 'lp-s', 'Rendah' => 'lp-r', 'Sangat Rendah' => 'lp-sr'];
    return '<span class="monev-level-pill ' . ($map[$level] ?? 'lp-s') . '">' . xss($level) . '</span>';
};
$deltaBadge = function ($delta): string {
    if ($delta === null) return '<span class="monev-delta monev-delta-same">–</span>';
    if ($delta > 0) return '<span class="monev-delta monev-delta-down" title="Skor turun ' . round($delta) . ' poin"><i class="fas fa-arrow-down"></i> ' . round($delta) . '</span>';
    if ($delta < 0) return '<span class="monev-delta monev-delta-up" title="Skor naik ' . round(abs($delta)) . ' poin"><i class="fas fa-arrow-up"></i> ' . round(abs($delta)) . '</span>';
    return '<span class="monev-delta monev-delta-same"><i class="fas fa-equals"></i> 0</span>';
};
$simpulanBadge = function (?string $s): string {
    if ($s === null || $s === '') return '<span style="color:var(--text-muted)">–</span>';
    if (stripos($s, 'penurunan') !== false) return '<span class="badge badge-success"><i class="fas fa-arrow-down"></i> Penurunan</span>';
    if (stripos($s, 'peningkatan') !== false) return '<span class="badge badge-danger"><i class="fas fa-arrow-up"></i> Peningkatan</span>';
    if (stripos($s, 'tetap') !== false) return '<span class="badge badge-secondary"><i class="fas fa-equals"></i> Tetap</span>';
    return '<span class="badge badge-secondary">' . xss($s) . '</span>';
};
$efektifBadge = function (?string $e): string {
    if ($e === null || $e === '') return '<span style="color:var(--text-muted)">–</span>';
    if ($e === 'Efektif') return '<span class="badge badge-success"><i class="fas fa-check-circle"></i> Efektif</span>';
    return '<span class="badge badge-danger"><i class="fas fa-times-circle"></i> Tidak Efektif</span>';
};
?>
<div class="risiko-hero profil-risiko-hero" style="background:linear-gradient(115deg,#0284c7 0%,#0369a1 55%,#075985 100%); align-items: flex-start !important;">
  <div class="risiko-hero-copy">
    <div class="risiko-eyebrow"><i class="fas fa-chart-line"></i> Monitoring &amp; Evaluasi</div>
    <h1 class="page-title" style="color:#fff">Monev Triwulanan &amp; Tahunan</h1>
    <p class="page-sub" style="color:rgba(255,255,255,.8)">Monitoring dan evaluasi risiko berdasarkan periode pelaporan.</p>
  </div>
  <div class="profil-hero-tools risiko-hero-tools-align">
    <select class="form-control hero-year-select wide" onchange="if(this.value) window.location.href='<?= APP_URL ?>/?page=monev_tahunan&tahun=<?= urlencode($activeTahun) ?>&jenis='+this.value" aria-label="Pilih periode">
      <?php foreach (['tw1' => 'Triwulan 1', 'tw2' => 'Triwulan 2', 'tw3' => 'Triwulan 3', 'tw4' => 'Triwulan 4', 'tahunan' => 'Tahunan'] as $segKey => $segLbl): ?>
      <option value="<?= $segKey ?>" <?= $jenisLaporan === $segKey ? 'selected' : '' ?>><?= $segLbl ?></option>
      <?php endforeach; ?>
    </select>
    <select class="form-control hero-year-select" style="max-width:130px;width:auto;text-align:center;text-align-last:center;" onchange="if(this.value) window.location.href='<?= APP_URL ?>/?page=monev_tahunan&tahun='+encodeURIComponent(this.value)+'&jenis=<?= $jenisLaporan ?>'" aria-label="Pilih tahun">
      <?php foreach ($tahunList as $y): ?>
        <option value="<?= xss($y) ?>" <?= (string)$y === (string)$activeTahun ? 'selected' : '' ?> style="text-align:center;"><?= xss($y) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="risiko-export-actions" style="margin-top:0">
        <a href="<?= APP_URL ?>/?page=monev_tahunan&tahun=<?= urlencode($activeTahun) ?>&jenis=<?= urlencode($jenisLaporan) ?>&type=excel" class="btn btn-hero-ghost"><i class="fas fa-file-excel"></i> Excel</a>
        <select class="form-control hero-year-select" style="width: 155px !important; max-width: 155px !important; padding: 0 24px 0 14px !important; text-align-last: center !important;" onchange="if(this.value){window.open('<?= APP_URL ?>/?page=monev_tahunan&tahun=<?= urlencode($activeTahun) ?>&type=pdf&jenis='+encodeURIComponent(this.value),'_blank');this.selectedIndex=0;}" aria-label="Cetak Laporan" title="Cetak Laporan per triwulan / tahunan">
          <option value="">&#128196; Cetak / PDF</option>
          <option value="tw1" style="text-align: left;">Laporan Triwulan I</option>
          <option value="tw2" style="text-align: left;">Laporan Triwulan II</option>
          <option value="tw3" style="text-align: left;">Laporan Triwulan III</option>
          <option value="tw4" style="text-align: left;">Laporan Triwulan IV</option>
          <option value="tahunan" style="text-align: left;">Laporan Tahunan</option>
        </select>
      </div>
  </div>

  <!-- Stat cards: klik untuk memfilter tabel -->
  <div class="stats-grid" style="width:100%;margin-top:20px;margin-bottom:0">
    <div class="stat-card stat-card-glass monev-filter-card" data-filter="all" style="--ga:#60a5fa;--ga-tint:rgba(96,165,250,.3);--ga-line:rgba(96,165,250,.45);--ga-glow:rgba(96,165,250,.3)">
      <div class="stat-icon"><i class="fas fa-clipboard-list"></i></div>
      <div class="stat-content">
        <div class="stat-value" data-count="<?= $chartStats['total'] ?>">0</div>
        <div class="stat-label">Total Risiko</div>
      </div>
    </div>
    <div class="stat-card stat-card-glass monev-filter-card" data-filter="terisi" style="--ga:#a78bfa;--ga-tint:rgba(167,139,250,.3);--ga-line:rgba(167,139,250,.45);--ga-glow:rgba(167,139,250,.3)">
      <div class="stat-icon"><i class="fas fa-search"></i></div>
      <div class="stat-content">
        <div class="stat-value" data-count="<?= $chartStats['terpantau'] ?>">0</div>
        <div class="stat-label">Sudah Dipantau</div>
      </div>
    </div>
    <div class="stat-card stat-card-glass monev-filter-card" data-filter="efektif" style="--ga:#4ade80;--ga-tint:rgba(74,222,128,.28);--ga-line:rgba(74,222,128,.45);--ga-glow:rgba(74,222,128,.28)">
      <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
      <div class="stat-content">
        <div class="stat-value" data-count="<?= $pctEfektif ?>" data-suffix="%">0</div>
        <div class="stat-label">Efektif (<?= $chartStats['efektif'] ?>)</div>
      </div>
    </div>
    <div class="stat-card stat-card-glass monev-filter-card" data-filter="eskalasi" style="--ga:#f87171;--ga-tint:rgba(248,113,113,.28);--ga-line:rgba(248,113,113,.5);--ga-glow:rgba(248,113,113,.32)">
      <div class="stat-icon"><i class="fas fa-arrow-up"></i></div>
      <div class="stat-content">
        <div class="stat-value" data-count="<?= $pctEskalasi ?>" data-suffix="%">0</div>
        <div class="stat-label">Eskalasi (<?= $chartStats['eskalasi'] ?>)</div>
      </div>
    </div>
    <div class="stat-card stat-card-glass monev-filter-card" data-filter="penurunan" style="--ga:#38bdf8;--ga-tint:rgba(56,189,248,.28);--ga-line:rgba(56,189,248,.45);--ga-glow:rgba(56,189,248,.28)">
      <div class="stat-icon"><i class="fas fa-arrow-down"></i></div>
      <div class="stat-content">
        <div class="stat-value" data-count="<?= $pctPenurunan ?>" data-suffix="%">0</div>
        <div class="stat-label">Penurunan (<?= $chartStats['penurunan'] ?>)</div>
      </div>
    </div>
  </div>
</div>

<?php if ($chartStats['total'] > 0): ?>
<!-- Banner keterangan ringkas (di luar hero) -->
<?php
  $insCls = ''; $insIcon = 'fa-circle-info';
  $insFilter = '';
  if ($chartStats['eskalasi'] > 0) {
    $insCls = 'bad'; $insIcon = 'fa-triangle-exclamation'; $insFilter = 'eskalasi';
  } elseif ($chartStats['belum'] > 0) {
    $insCls = 'warn'; $insIcon = 'fa-clock'; $insFilter = 'belum';
  } else {
    $insIcon = 'fa-circle-check'; $insFilter = ($chartStats['penurunan'] > 0) ? 'penurunan' : '';
  }
?>
<div class="monev-insight <?= $insCls ?> <?= $insFilter ? 'is-clickable' : '' ?>"
     <?= $insFilter ? 'onclick="triggerBannerFilter(\'' . $insFilter . '\')"' : '' ?>
     <?= $insFilter ? 'title="Klik untuk langsung melihat daftar risiko ini pada tabel di bawah"' : '' ?>
     style="margin-top:18px">
  <i class="fas <?= $insIcon ?>"></i>
  <div class="ins-text" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;width:100%">
    <div style="flex:1 1 320px;min-width:0">
      <?php if ($chartStats['eskalasi'] > 0): ?>
        <strong><?= $chartStats['eskalasi'] ?> risiko mengalami eskalasi skor</strong> pada <?= xss($jenisLabel) ?> — prioritaskan evaluasi ulang pengendalian.
      <?php elseif ($chartStats['belum'] > 0): ?>
        <strong><?= $chartStats['belum'] ?> dari <?= $chartStats['total'] ?> risiko belum dipantau</strong> pada periode ini.<?= $canInput ? ' Klik "Isi Monev" pada baris tabel untuk mengisi.' : '' ?>
      <?php else: ?>
        <strong>Semua risiko telah dipantau</strong> — <?= $pctEfektif ?>% pengendalian efektif, <?= $chartStats['penurunan'] ?> risiko mengalami penurunan skor.
      <?php endif; ?>
      <?php if ($chartStats['terpantau'] > 0): ?>
        Rata-rata skor: <strong><?= $chartAvgBaseline ?></strong> &rarr; <strong><?= $chartAvgCurrent ?></strong> (<strong><?= $selisihAvg > 0 ? '&darr; ' . abs($selisihAvg) : ($selisihAvg < 0 ? '&uarr; ' . abs($selisihAvg) : '= 0') ?></strong>).
      <?php endif; ?>
    </div>
    <?php if ($chartStats['eskalasi'] > 0): ?>
      <span class="btn btn-sm btn-danger monev-banner-btn" style="pointer-events:none">
        <i class="fas fa-arrow-up"></i> Lihat <?= $chartStats['eskalasi'] ?> Risiko Eskalasi &rarr;
      </span>
    <?php elseif ($chartStats['belum'] > 0): ?>
      <span class="btn btn-sm btn-outline monev-banner-btn" style="pointer-events:none; border-color:var(--warning); color:#b45309">
        <i class="fas fa-clock"></i> Lihat <?= $chartStats['belum'] ?> Belum Dipantau &rarr;
      </span>
    <?php elseif ($chartStats['penurunan'] > 0): ?>
      <span class="btn btn-sm btn-outline monev-banner-btn" style="pointer-events:none; border-color:var(--accent); color:var(--accent)">
        <i class="fas fa-arrow-down"></i> Lihat <?= $chartStats['penurunan'] ?> Penurunan &rarr;
      </span>
    <?php endif; ?>
  </div>
</div>

<!-- Progress kelengkapan periode (di luar hero) -->
<div class="monev-progress-wrap">
  <div class="monev-progress-head">
    <span><i class="fas fa-list-check"></i> Kelengkapan <?= xss($jenisLabel) ?> — <?= $chartStats['terpantau'] ?>/<?= $chartStats['total'] ?> risiko terisi</span>
    <span class="pct"><?= $pctTerisi ?>%</span>
  </div>
  <div class="monev-progress"><div class="monev-progress-bar <?= $pctTerisi < 40 ? 'pct-low' : ($pctTerisi < 75 ? 'pct-mid' : '') ?>" data-target="<?= $pctTerisi ?>"></div></div>
</div>
<?php endif; ?>

<?php if ($chartStats['total'] > 0): ?>
<?php
  $pLabels = [1=>'Jarang', 2=>'Kecil', 3=>'Sedang', 4=>'Besar', 5=>'Hampir Pasti'];
  $dLabels = [1=>'Tidak Signifikan', 2=>'Kecil', 3=>'Sedang', 4=>'Besar', 5=>'Katastropik'];
  $tingkatShort = fn($s) => $s>=20?'Sangat Tinggi':($s>=15?'Tinggi':($s>=10?'Sedang':($s>=5?'Rendah':'Sangat Rendah')));
  $activeMatrixPeriod = $jenisLaporan === 'tahunan' ? 'tw4' : ($jenisLaporan === 'tw1' ? 'tw1' : ($jenisLaporan === 'tw2' ? 'tw2' : ($jenisLaporan === 'tw3' ? 'tw3' : 'tw4')));
?>
<!-- ── Grid 2-Kolom: Matriks Risiko 5x5 & Distribusi Tingkat Risiko (Opsi 1) ── -->
<div class="monev-matrix-analytics-grid">
  <!-- Kolom Kiri: Matriks Risiko 5x5 Interaktif -->
  <div class="card monev-matrix-card" style="margin-bottom:0;display:flex;flex-direction:column;height:100%">
    <div class="card-header" style="background:linear-gradient(135deg,rgba(59,130,246,.06),transparent);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;padding:14px 20px">
      <div style="display:flex;align-items:center;gap:10px">
        <div style="width:36px;height:36px;border-radius:10px;background:var(--primary-glow);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:1.15rem">
          <i class="fas fa-th"></i>
        </div>
        <div>
          <span class="card-title" style="margin:0;font-size:1.05rem;font-weight:800"><i class="fas fa-table-cells" style="color:var(--accent);margin-right:4px"></i> Matriks Risiko 5&times;5</span>
          <div id="matrixSubtitle" style="font-size:.74rem;color:var(--text-muted);margin-top:2px">
            Periode aktif: <strong id="matrixPeriodLabel" style="color:var(--accent)">Triwulan <?= $twTarget ?></strong> &bull; <span id="matrixCountInfo">Memuat data...</span>
          </div>
        </div>
      </div>

      <!-- Switcher Periode Triwulanan (Dropdown Ringkas & Rapi) -->
      <div class="monev-matrix-tabs">
        <label for="monevPeriodSelect" class="matrix-period-label" style="margin-bottom:0">
          <i class="fas fa-sliders"></i> Pantau Periode:
        </label>
        <div class="matrix-select-wrap">
          <select id="monevPeriodSelect" class="form-control matrix-period-select" onchange="switchMonevPeriod(this.value)">
            <option value="awal" <?= $activeMatrixPeriod==='awal'?'selected':'' ?>>Kondisi Awal</option>
            <option value="tw1" <?= $activeMatrixPeriod==='tw1'?'selected':'' ?>>Triwulan 1</option>
            <option value="tw2" <?= $activeMatrixPeriod==='tw2'?'selected':'' ?>>Triwulan 2</option>
            <option value="tw3" <?= $activeMatrixPeriod==='tw3'?'selected':'' ?>>Triwulan 3</option>
            <option value="tw4" <?= $activeMatrixPeriod==='tw4'?'selected':'' ?>>Triwulan 4</option>
          </select>
        </div>
      </div>
    </div>

    <div class="card-body heatmap-wrap compact-heatmap" style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px 16px;text-align:center">
      <table class="heatmap dashboard-heatmap-table" id="monevHeatmapTable">
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
              $skor = (int)round($p * $d * getBobot($p,$d));
              $bg   = heatmapColor($p,$d);
              $tks  = $tingkatShort($skor);
              $fg   = ($skor>=10 && $skor<=14) ? '#000' : '#fff';
            ?>
            <td id="mcell_<?= $p ?>_<?= $d ?>"
                data-p="<?= $p ?>" data-d="<?= $d ?>" data-skor="<?= $skor ?>"
                style="background:<?= $bg ?>;color:<?= $fg ?>;cursor:pointer;transition:transform 0.2s,box-shadow 0.2s;"
                title="P<?= $p ?> (<?= $pLabels[$p] ?>) &times; D<?= $d ?> (<?= $dLabels[$d] ?>) &times; Bobot <?= getBobot($p,$d) ?> = <?= $skor ?> &rarr; <?= $tks ?>"
                onclick="clickMonevCell(<?= $p ?>, <?= $d ?>, <?= $skor ?>)"
                onmouseover="this.style.transform='scale(1.08)';this.style.boxShadow='0 6px 16px rgba(0,0,0,.25)'"
                onmouseout="this.style.transform='scale(1)';this.style.boxShadow='none'">
              <div style="font-size:1.15rem;font-weight:900;line-height:1"><?= $skor ?></div>
              <div style="font-size:.56rem;font-weight:700;opacity:.88;line-height:1.1;margin-top:2px;white-space:normal;word-wrap:break-word;text-align:center"><?= $tks ?></div>
              <span class="count-badge" id="mbadge_<?= $p ?>_<?= $d ?>" style="display:none">0</span>
            </td>
            <?php endfor; ?>
          </tr>
          <?php endfor; ?>
        </tbody>
      </table>

      <div class="heatmap-legend compact-heatmap-legend" style="display:flex;flex-wrap:wrap;justify-content:center;gap:12px;margin-top:14px">
        <div style="font-size:.7rem;color:var(--text-muted);font-weight:700;margin-bottom:4px;width:100%;text-align:center">Nilai = P &times; D &times; Bobot</div>
        <div class="legend-item" style="display:flex;align-items:center;gap:5px;font-size:.68rem;color:var(--text-muted)"><span class="legend-dot" style="background:#dc2626;display:inline-block;width:10px;height:10px;border-radius:50%"></span>Sangat Tinggi (&ge; 20)</div>
        <div class="legend-item" style="display:flex;align-items:center;gap:5px;font-size:.68rem;color:var(--text-muted)"><span class="legend-dot" style="background:#f97316;display:inline-block;width:10px;height:10px;border-radius:50%"></span>Tinggi (15&ndash;19)</div>
        <div class="legend-item" style="display:flex;align-items:center;gap:5px;font-size:.68rem;color:var(--text-muted)"><span class="legend-dot" style="background:#FFFF00;display:inline-block;width:10px;height:10px;border-radius:50%"></span>Sedang (10&ndash;14)</div>
        <div class="legend-item" style="display:flex;align-items:center;gap:5px;font-size:.68rem;color:var(--text-muted)"><span class="legend-dot" style="background:#22c55e;display:inline-block;width:10px;height:10px;border-radius:50%"></span>Rendah (5&ndash;9)</div>
        <div class="legend-item" style="display:flex;align-items:center;gap:5px;font-size:.68rem;color:var(--text-muted)"><span class="legend-dot" style="background:#3b82f6;display:inline-block;width:10px;height:10px;border-radius:50%"></span>Sangat Rendah (1&ndash;4)</div>
      </div>

      <div class="heatmap-desc" style="margin-top:10px;font-size:.68rem;color:var(--text-muted);text-align:center;line-height:1.6;width:100%">
        <strong>P</strong> = Probabilitas (1=Jarang, 2=Kecil, 3=Sedang, 4=Besar, 5=Hampir Pasti)<br>
        <strong>D</strong> = Dampak (1=Tidak Signifikan, 2=Kecil, 3=Sedang, 4=Besar, 5=Katastropik)<br>
        <span style="font-size:.64rem;opacity:.85;display:inline-block;margin-top:4px"><i class="fas fa-hand-pointer"></i> Klik sel untuk melihat daftar risiko di area tersebut &bull; Klik tombol triwulan di atas untuk memantau pergeseran risiko tiap periode.</span>
      </div>
    </div>
  </div>

  <!-- Kolom Kanan: Distribusi Tingkat Risiko & Donut Chart (Opsi 1) -->
  <div class="card monev-analytics-card" style="margin-bottom:0;display:flex;flex-direction:column;height:100%">
    <div class="card-header" style="background:linear-gradient(135deg,rgba(59,130,246,.06),transparent);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;padding:14px 20px">
      <div style="display:flex;align-items:center;gap:10px">
        <div style="width:36px;height:36px;border-radius:10px;background:var(--primary-glow);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:1.15rem">
          <i class="fas fa-chart-pie"></i>
        </div>
        <div>
          <span class="card-title" style="margin:0;font-size:1.05rem;font-weight:800"><i class="fas fa-chart-pie" style="color:var(--accent);margin-right:4px"></i> Distribusi Tingkat Risiko</span>
          <div id="analyticsSubtitle" style="font-size:.74rem;color:var(--text-muted);margin-top:2px">
            Periode: <strong id="analyticsPeriodLabel" style="color:var(--accent)">Triwulan <?= $twTarget ?></strong> &bull; <span id="analyticsTotalBadge">0 Risiko Terpantau</span>
          </div>
        </div>
      </div>
      <button type="button" class="btn btn-sm btn-outline" id="btnResetLevelFilter" style="display:none;font-size:.72rem;padding:4px 10px;gap:5px;align-items:center" onclick="clearLevelFilter()" title="Hapus filter tingkat risiko">
        <i class="fas fa-rotate-left"></i> Reset Filter
      </button>
    </div>

    <div class="card-body monev-analytics-body" style="padding:16px 20px;display:flex;flex-direction:column;justify-content:space-between;flex:1;gap:14px">
      <div class="monev-analytics-body-content">
        <!-- Donut Chart -->
        <div class="monev-donut-wrapper">
          <div class="monev-donut-canvas-box">
            <canvas id="monevLevelDonut"></canvas>
            <div id="donutCenterInfo" class="monev-donut-center-info">
              <div id="donutCenterTotal" class="donut-center-num">0</div>
              <div class="donut-center-label">Risiko</div>
            </div>
          </div>
        </div>

        <!-- 5 Level Stat Cards / Breakdown -->
        <div class="monev-level-cards-list">
          <div class="monev-level-card card-st" data-level="Sangat Tinggi" onclick="filterByRiskLevel('Sangat Tinggi')" role="button" tabindex="0" title="Klik untuk memfilter risiko Sangat Tinggi">
            <div class="level-info">
              <span class="level-dot" style="background:#dc2626"></span>
              <div>
                <div class="level-name">Sangat Tinggi</div>
                <div class="level-range">&ge; 20</div>
              </div>
            </div>
            <div class="level-metrics">
              <span class="level-count-badge" id="lvlCount_ST">0</span>
              <span class="level-pct" id="lvlPct_ST">0%</span>
            </div>
          </div>

          <div class="monev-level-card card-t" data-level="Tinggi" onclick="filterByRiskLevel('Tinggi')" role="button" tabindex="0" title="Klik untuk memfilter risiko Tinggi">
            <div class="level-info">
              <span class="level-dot" style="background:#ea580c"></span>
              <div>
                <div class="level-name">Tinggi</div>
                <div class="level-range">15 &ndash; 19</div>
              </div>
            </div>
            <div class="level-metrics">
              <span class="level-count-badge" id="lvlCount_T">0</span>
              <span class="level-pct" id="lvlPct_T">0%</span>
            </div>
          </div>

          <div class="monev-level-card card-s" data-level="Sedang" onclick="filterByRiskLevel('Sedang')" role="button" tabindex="0" title="Klik untuk memfilter risiko Sedang">
            <div class="level-info">
              <span class="level-dot" style="background:#eab308"></span>
              <div>
                <div class="level-name">Sedang</div>
                <div class="level-range">10 &ndash; 14</div>
              </div>
            </div>
            <div class="level-metrics">
              <span class="level-count-badge" id="lvlCount_S">0</span>
              <span class="level-pct" id="lvlPct_S">0%</span>
            </div>
          </div>

          <div class="monev-level-card card-r" data-level="Rendah" onclick="filterByRiskLevel('Rendah')" role="button" tabindex="0" title="Klik untuk memfilter risiko Rendah">
            <div class="level-info">
              <span class="level-dot" style="background:#16a34a"></span>
              <div>
                <div class="level-name">Rendah</div>
                <div class="level-range">5 &ndash; 9</div>
              </div>
            </div>
            <div class="level-metrics">
              <span class="level-count-badge" id="lvlCount_R">0</span>
              <span class="level-pct" id="lvlPct_R">0%</span>
            </div>
          </div>

          <div class="monev-level-card card-sr" data-level="Sangat Rendah" onclick="filterByRiskLevel('Sangat Rendah')" role="button" tabindex="0" title="Klik untuk memfilter risiko Sangat Rendah">
            <div class="level-info">
              <span class="level-dot" style="background:#2563eb"></span>
              <div>
                <div class="level-name">Sangat Rendah</div>
                <div class="level-range">1 &ndash; 4</div>
              </div>
            </div>
            <div class="level-metrics">
              <span class="level-count-badge" id="lvlCount_SR">0</span>
              <span class="level-pct" id="lvlPct_SR">0%</span>
            </div>
          </div>
        </div>
      </div>

      <div class="monev-analytics-hint">
        <i class="fas fa-hand-pointer"></i> Klik salah satu kartu level atau potongan grafik untuk memfilter tabel di bawah
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Tabel monev -->
<div class="card" id="monev-table">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px">
    <div>
      <span class="card-title" id="monevTableTitle"><i class="fas fa-table"></i> <?= xss($jenisLabel) ?> — Tahun <?= xss($activeTahun) ?></span>
      <span style="font-size:.72rem;color:var(--text-muted);display:block;margin-top:2px"><i class="fas fa-circle-info"></i> <?= count($rows) ?> risiko &bull; geser tabel ke kiri/kanan untuk melihat kolom yang tidak muat</span>
    </div>
    <div class="monev-toolbar">
      <span class="monev-chip" id="monevFilterChip" style="display:none" onclick="clearMonevFilter()" title="Klik untuk hapus filter"><i class="fas fa-filter"></i> <span id="monevFilterChipText"></span> <i class="fas fa-times-circle"></i></span>
      <div class="search-bar">
        <i class="fas fa-search"></i>
        <input type="text" class="form-control" id="searchMonevTahunan" placeholder="Cari nama risiko..." style="height:38px">
      </div>
      <div class="datatable-dropdown" style="margin:0;display:flex;align-items:center">
        <select class="datatable-selector" id="limitMonevTahunan">
          <option value="10" selected>10</option>
          <option value="15">15</option>
          <option value="25">25</option>
          <option value="50">50</option>
        </select>
        <label style="margin-left:8px;margin-bottom:0;font-size:.8rem;color:var(--text-muted)">/ halaman</label>
      </div>
    </div>
  </div>
  <div class="monev-scroll-wrap">
    <table class="data-table profil-detail-table monev-data-table no-datatable" id="tableMonevTahunan">
      <colgroup>
        <col style="width:36px"><col style="width:72px"><col style="min-width:200px">
        <col style="width:32px"><col style="width:32px"><col style="width:52px"><col style="width:50px"><col style="width:106px">
        <?php if ($showPrevQ): ?><col style="width:32px"><col style="width:32px"><col style="width:52px"><col style="width:50px"><col style="width:106px"><?php endif; ?>
        <col style="min-width:180px">
        <col style="width:32px"><col style="width:32px"><col style="width:52px"><col style="width:62px"><col style="width:106px">
        <col style="width:100px"><col style="width:104px">
        <col style="width:140px">
        <?php if ($canInput): ?><col style="width:80px"><?php endif; ?>
      </colgroup>
      <thead>
        <tr>
          <th rowspan="2" class="col-no" style="text-align:center">No</th>
          <th rowspan="2" class="col-code" style="text-align:center">Kode</th>
          <th rowspan="2" class="col-risk" style="text-align:center">Risiko &amp; Unit Kerja</th>
          <th colspan="5" style="text-align:center;background:rgba(100,116,139,.07)">Kondisi Awal</th>
          <?php if ($showPrevQ): ?><th colspan="5" style="text-align:center;background:rgba(148,163,184,.12)">Kondisi Triwulan <?= $twTarget - 1 ?></th><?php endif; ?>
          <th rowspan="2" style="text-align:center;background:rgba(100,116,139,.04);white-space:normal;line-height:1.2">Upaya Pengendalian</th>
          <th colspan="5" style="text-align:center;background:rgba(59,130,246,.08)">Kondisi Saat Ini</th>
          <th colspan="2" style="text-align:center;background:rgba(124,58,237,.07)">Simpulan</th>
          <th rowspan="2" class="col-status" style="text-align:center;white-space:normal;line-height:1.2">Status</th>
          <?php if ($canInput): ?><th rowspan="2" class="col-action" style="text-align:center">Aksi</th><?php endif; ?>
        </tr>
        <tr>
          <th style="text-align:center">P</th><th style="text-align:center">D</th><th style="text-align:center">Bobot</th><th style="text-align:center">Nilai</th><th style="text-align:center">Tingkat</th>
          <?php if ($showPrevQ): ?><th style="text-align:center">P</th><th style="text-align:center">D</th><th style="text-align:center">Bobot</th><th style="text-align:center">Nilai</th><th style="text-align:center">Tingkat</th><?php endif; ?>
          <th style="text-align:center">P</th><th style="text-align:center">D</th><th style="text-align:center">Bobot</th><th style="text-align:center">Nilai</th><th style="text-align:center">Tingkat</th>
          <th style="text-align:center">Tingkat</th><th style="text-align:center">Efektivitas</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
        <tr><td colspan="<?= ($canInput ? 18 : 17) + ($showPrevQ ? 5 : 0) ?>" style="text-align:center;padding:36px;color:var(--text-muted)">Belum ada data risiko untuk tahun <?= xss($activeTahun) ?>.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $i => $r): $c = $r['curr'];
          $delta = ($c && $c['pantau_nilai'] !== null) ? (float)$r['prev_nilai'] - (float)$c['pantau_nilai'] : null;
          $searchIdx = mb_strtolower(($r['unit_pemilik_risiko'] ?? '') . ' ' . ($r['nama_risiko'] ?? '') . ' ' . ($r['kode_risiko'] ?? ''));
          $lvlAwal = trim((string)($r['prev_tingkat'] ?: ($r['tingkat_risiko'] ?: '')));
          $lvlTw1 = (isset($twByRisk[1][$r['id']]) && $twByRisk[1][$r['id']]['pantau_nilai'] !== null) ? trim((string)($twByRisk[1][$r['id']]['pantau_tingkat'] ?: getLevelRisiko((int)round((float)$twByRisk[1][$r['id']]['pantau_nilai'])))) : '';
          $lvlTw2 = (isset($twByRisk[2][$r['id']]) && $twByRisk[2][$r['id']]['pantau_nilai'] !== null) ? trim((string)($twByRisk[2][$r['id']]['pantau_tingkat'] ?: getLevelRisiko((int)round((float)$twByRisk[2][$r['id']]['pantau_nilai'])))) : '';
          $lvlTw3 = (isset($twByRisk[3][$r['id']]) && $twByRisk[3][$r['id']]['pantau_nilai'] !== null) ? trim((string)($twByRisk[3][$r['id']]['pantau_tingkat'] ?: getLevelRisiko((int)round((float)$twByRisk[3][$r['id']]['pantau_nilai'])))) : '';
          $lvlTw4 = (isset($twByRisk[4][$r['id']]) && $twByRisk[4][$r['id']]['pantau_nilai'] !== null) ? trim((string)($twByRisk[4][$r['id']]['pantau_tingkat'] ?: getLevelRisiko((int)round((float)$twByRisk[4][$r['id']]['pantau_nilai'])))) : '';
          $lvlCurr = ($c && $c['pantau_nilai'] !== null) ? trim((string)($c['pantau_tingkat'] ?: getLevelRisiko((int)round((float)$c['pantau_nilai'])))) : '';
        ?>
        <tr class="monev-row"
            data-unit="<?= xss($r['unit_pemilik_risiko'] ?? '') ?>"
            data-search="<?= xss($searchIdx) ?>"
            data-terisi="<?= $c ? 1 : 0 ?>"
            data-efektif="<?= ($c && ($c['efektifitas'] ?? '') === 'Efektif') ? 1 : 0 ?>"
            data-naik="<?= ($delta !== null && $delta < 0) ? 1 : 0 ?>"
            data-turun="<?= ($delta !== null && $delta > 0) ? 1 : 0 ?>"
            data-level-awal="<?= xss($lvlAwal) ?>"
            data-level-tw1="<?= xss($lvlTw1) ?>"
            data-level-tw2="<?= xss($lvlTw2) ?>"
            data-level-tw3="<?= xss($lvlTw3) ?>"
            data-level-tw4="<?= xss($lvlTw4) ?>"
            data-level-curr="<?= xss($lvlCurr) ?>">
          <td style="text-align:center;font-weight:600;color:var(--text-muted)"><?= $i + 1 ?></td>
          <td style="text-align:center;white-space:nowrap"><span class="badge-kode-risiko"><?= xss($r['kode_risiko'] ?? '-') ?></span></td>
          <td>
            <div style="font-weight:600;color:var(--text-main);line-height:1.35;margin-bottom:3px"><?= xss($r['nama_risiko']) ?></div>
            <?php if(!empty($r['unit_pemilik_risiko'])): ?>
            <div style="font-size:.71rem;color:var(--text-muted);display:flex;align-items:center;gap:4px">
              <i class="fas fa-building" style="font-size:.65rem;opacity:.7"></i>
              <span><?= xss($r['unit_pemilik_risiko']) ?></span>
            </div>
            <?php endif; ?>
          </td>
          <td style="text-align:center;color:var(--text-muted)"><?= $r['prev_p'] ?></td>
          <td style="text-align:center;color:var(--text-muted)"><?= $r['prev_d'] ?></td>
          <td style="text-align:center;color:var(--text-muted)"><?= number_format((float)$r['prev_bobot'], 2, '.', '') ?></td>
          <td style="text-align:center;font-weight:700"><?= round((float)$r['prev_nilai']) ?></td>
          <td style="text-align:center"><?= $levelPill($r['prev_tingkat']) ?></td>
          <?php if ($showPrevQ): $pq = $r['prevq']; ?>
          <td style="text-align:center;<?= $pq ? '' : 'color:var(--text-muted)' ?>"><?= $pq ? (int)$pq['pantau_p'] : '–' ?></td>
          <td style="text-align:center;<?= $pq ? '' : 'color:var(--text-muted)' ?>"><?= $pq ? (int)$pq['pantau_d'] : '–' ?></td>
          <td style="text-align:center;<?= $pq ? '' : 'color:var(--text-muted)' ?>"><?= $pq ? number_format((float)$pq['pantau_bobot'], 2, '.', '') : '–' ?></td>
          <td style="text-align:center;font-weight:700;<?= $pq ? '' : 'color:var(--text-muted)' ?>"><?= $pq ? round((float)$pq['pantau_nilai']) : '–' ?></td>
          <td style="text-align:center"><?= $levelPill($pq['pantau_tingkat'] ?? null) ?></td>
          <?php endif; ?>
          <td style="font-size:.76rem;line-height:1.4"><?= !empty($jsRows[$i]['upaya']) ? nl2br(xss($jsRows[$i]['upaya'])) : '<span style="color:var(--text-muted)">–</span>' ?></td>
          <?php if ($c): ?>
          <td style="text-align:center"><?= (int)$c['pantau_p'] ?></td>
          <td style="text-align:center"><?= (int)$c['pantau_d'] ?></td>
          <td style="text-align:center"><?= number_format((float)$c['pantau_bobot'], 2, '.', '') ?></td>
          <td style="text-align:center">
            <div style="font-weight:800;font-size:.95rem"><?= round((float)$c['pantau_nilai']) ?></div>
            <div style="margin-top:3px"><?= $deltaBadge($delta) ?></div>
          </td>
          <td style="text-align:center"><?= $levelPill($c['pantau_tingkat'] ?? null) ?></td>
          <?php else: ?>
          <td style="text-align:center;color:var(--text-muted)">–</td>
          <td style="text-align:center;color:var(--text-muted)">–</td>
          <td style="text-align:center;color:var(--text-muted)">–</td>
          <td style="text-align:center"><span class="monev-delta monev-delta-same" style="opacity:.6">belum diisi</span></td>
          <td style="text-align:center;color:var(--text-muted)">–</td>
          <?php endif; ?>
          <td style="text-align:center"><?= $simpulanBadge($c['simpulan_tingkat'] ?? null) ?></td>
          <td style="text-align:center"><?= $efektifBadge($c['efektifitas'] ?? null) ?></td>
          <?php
            $stLabel = '–';
            $stDesc = '';
            $stBadgeCls = '';
            if ($c && $c['pantau_nilai'] !== null) {
                $pTingkat = trim((string)($c['pantau_tingkat'] ?? ''));
                if ($pTingkat === 'Sangat Rendah') {
                    $stLabel = 'Closed (Selesai)';
                    $stDesc = 'Level risiko turun di bawah ambang batas';
                    $stBadgeCls = 'badge-success';
                } else {
                    $stLabel = 'Open';
                    $stDesc = 'Level risiko belum di bawah ambang batas';
                    $stBadgeCls = 'badge-warning';
                }
            }
          ?>
          <td style="text-align:center;line-height:1.3">
            <?php if ($stLabel === '–'): ?>
            <span style="color:var(--text-muted)">–</span>
            <?php else: ?>
            <span class="badge <?= $stBadgeCls ?>" style="font-size:.74rem;padding:3px 8px;font-weight:700"><?= htmlspecialchars($stLabel) ?></span>
            <div style="font-size:.68rem;color:var(--text-muted);margin-top:3px;line-height:1.25"><?= htmlspecialchars($stDesc) ?></div>
            <?php endif; ?>
          </td>
          <?php if ($canInput): ?>
          <td style="text-align:center;white-space:nowrap">
            <button type="button" class="btn <?= $c ? 'btn-outline' : 'btn-primary' ?> btn-sm" onclick="openMonev(<?= $i ?>)" title="Isi / ubah monev risiko ini" style="font-size:.74rem;padding:4px 10px">
              <i class="fas <?= $c ? 'fa-pen' : 'fa-plus' ?>"></i> <?= $c ? 'Ubah' : 'Isi' ?>
            </button>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="monev-pagination">
    <span class="pagination-info" id="monevPageInfo">Memuat…</span>
    <div class="pagination" id="monevPages" style="margin:0;gap:5px"></div>
  </div>
</div>

<?php if ($canInput && !empty($jsRows)): ?>
<!-- Modal Isi Monev: overlay + backdrop + Esc + navigasi antar-risiko -->
<div class="modal-overlay" id="modalMonev" style="display:none">
  <div class="modal modal-lg" style="max-width:920px">
    <div class="modal-header">
      <div>
        <h5 class="modal-title" id="monevModalTitle"><i class="fas fa-pen-to-square" style="color:var(--accent)"></i> Isi Monev — <?= xss($jenisLabel) ?></h5>
        <div class="monev-meta" id="monevModalMeta"></div>
      </div>
      <button type="button" class="btn-close" onclick="closeModal('modalMonev')" aria-label="Tutup"><i class="fas fa-times"></i></button>
    </div>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="aksi" value="simpan_monev">
      <input type="hidden" name="id_risiko" id="monevRiskId">
      <input type="hidden" name="tahun" value="<?= xss($activeTahun) ?>">
      <input type="hidden" name="jenis" id="monevModalFormJenis" value="<?= xss($jenisLaporan) ?>">
      <div class="modal-body">
        <div class="monev-form-grid">
          <div>
            <div class="monev-slider-block">
              <div class="monev-slider-item">
                <div class="monev-slider-label">
                  <span>Probabilitas</span>
                  <span class="val"><span class="vbadge" id="monevPBadge">1</span><span class="vtext" id="monevPText">Jarang</span></span>
                </div>
                <input type="range" class="monev-range" name="pantau_p" id="monevP" min="1" max="5" value="1">
                <div class="monev-scale-hints"><span>1 Jarang</span><span>2 Kecil</span><span>3 Sedang</span><span>4 Besar</span><span>5 Hampir Pasti</span></div>
              </div>
              <div class="monev-slider-item">
                <div class="monev-slider-label">
                  <span>Dampak</span>
                  <span class="val"><span class="vbadge" id="monevDBadge">1</span><span class="vtext" id="monevDText">Tidak Signifikan</span></span>
                </div>
                <input type="range" class="monev-range" name="pantau_d" id="monevD" min="1" max="5" value="1">
                <div class="monev-scale-hints"><span>1 T.Signifikan</span><span>2 Kecil</span><span>3 Sedang</span><span>4 Besar</span><span>5 Katastropik</span></div>
              </div>
            </div>
            <div class="monev-text-grid">
              <div class="full">
                <label class="form-label" style="font-size:.78rem;font-weight:700">Upaya Pengendalian</label>
                <textarea name="upaya_pengendalian" id="monevUpaya" class="form-control" rows="2" placeholder="Tuliskan upaya pengendalian yang dilakukan..." style="font-size:.82rem"></textarea>
              </div>
              <div class="full">
                <label class="form-label" style="font-size:.78rem;font-weight:700">Link Data Dukung</label>
                <input type="url" name="link_data_dukung" id="monevLink" class="form-control" placeholder="https://..." style="font-size:.82rem">
              </div>
              <div>
                <label class="form-label" style="font-size:.78rem;font-weight:700">Kendala / Masalah</label>
                <textarea name="kendala" id="monevKendala" class="form-control" rows="3" placeholder="Tuliskan kendala/masalah yang dihadapi..." style="font-size:.82rem"></textarea>
              </div>
              <div>
                <label class="form-label" style="font-size:.78rem;font-weight:700">Rencana Tindak Lanjut</label>
                <textarea name="rencana_tindak_lanjut" id="monevRtl" class="form-control" rows="3" placeholder="Tuliskan rencana tindak lanjut..." style="font-size:.82rem"></textarea>
              </div>
            </div>
          </div>
          <div class="monev-calc">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
              <div>
                <div style="font-size:.72rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em">Nilai Risiko</div>
                <div class="monev-calc-nilai" id="monevNilai">1</div>
              </div>
              <div style="text-align:right">
                <div style="font-size:.72rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px">Tingkat</div>
                <span id="monevTingkat" class="monev-level-pill lp-sr">Sangat Rendah</span>
              </div>
            </div>
            <div class="monev-calc-grid">
              <div class="cell"><b id="monevHasilP">1</b><span>P</span></div>
              <div class="cell"><b id="monevHasilD">1</b><span>D</span></div>
              <div class="cell"><b id="monevBobotVal">1.00</b><span>Bobot</span></div>
              <div class="cell"><b id="monevSkor" style="background:var(--success)">1</b><span>Skor</span></div>
            </div>
            <p style="font-size:.66rem;color:var(--text-muted);margin-top:12px;line-height:1.5"><i class="fas fa-lightbulb" style="color:var(--warning)"></i> Skor turun dibanding <b>penilaian awal</b> &rarr; pengendalian <b>Efektif</b>. Gunakan tombol <b>Berikutnya</b> untuk mengisi risiko lain tanpa menutup form.</p>
          </div>
        </div>
      </div>
      <div class="modal-footer" style="flex-wrap:wrap;gap:8px">
        <div class="monev-modal-nav">
          <button type="button" id="monevPrevBtn" onclick="monevNav(-1)" title="Risiko sebelumnya (←)"><i class="fas fa-chevron-left"></i> <span class="d-none-mobile">Sebelumnya</span></button>
          <span class="counter" id="monevCounter">–</span>
          <button type="button" id="monevNextBtn" onclick="monevNav(1)" title="Risiko berikutnya (→)"><span class="d-none-mobile">Berikutnya</span> <i class="fas fa-chevron-right"></i></button>
        </div>
        <button type="button" class="btn btn-outline" onclick="closeModal('modalMonev')">Tutup</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Monev</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<style>
  /* ── Multi-row Sticky Table Header (Sejajar Sempurna untuk Status & Aksi) ── */
  .monev-scroll-wrap {
    overflow-x: auto;
    overflow-y: auto;
    max-height: 65vh;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    scrollbar-width: thin;
  }
  .monev-data-table {
    width: 100%;
    min-width: <?= $showPrevQ ? 1750 : 1500 ?>px;
    table-layout: fixed;
    border-collapse: separate;
    border-spacing: 0;
  }
  /* Baris 1 header sticky di top 0 */
  .monev-scroll-wrap thead tr:first-child th {
    position: sticky !important;
    top: 0 !important;
    z-index: 10 !important;
    background: var(--surface2);
    height: 34px !important;
    box-sizing: border-box !important;
    border-top: 1px solid var(--border) !important;
    border-bottom: 1px solid var(--border) !important;
    border-right: 1px solid var(--border) !important;
    vertical-align: middle !important;
    padding: 6px 8px !important;
    font-size: .68rem !important;
    line-height: 1.3 !important;
    box-shadow: none !important;
    white-space: nowrap;
  }
  .monev-scroll-wrap thead tr:first-child th:first-child {
    border-left: 1px solid var(--border) !important;
  }
  /* Baris 2 header sticky tepat di bawah baris 1 (top 34px) */
  .monev-scroll-wrap thead tr:nth-child(2) th {
    position: sticky !important;
    top: 34px !important;
    z-index: 9 !important;
    background: var(--surface2);
    height: 34px !important;
    box-sizing: border-box !important;
    border-bottom: 2px solid var(--border) !important;
    border-right: 1px solid var(--border) !important;
    vertical-align: middle !important;
    padding: 6px 8px !important;
    font-size: .68rem !important;
    line-height: 1.3 !important;
    box-shadow: none !important;
    white-space: nowrap;
  }
  .monev-scroll-wrap thead tr:nth-child(2) th:first-child {
    border-left: 1px solid var(--border) !important;
  }
  /* Kolom rowspan="2" (No, Kode, Risiko, Upaya Pengendalian, Status, Aksi):
     Tinggi tepat 68px (baris 1 + baris 2), vertikal di tengah, dan border-bottom sejajar baris 2 */
  .monev-scroll-wrap thead tr:first-child th[rowspan="2"] {
    height: 68px !important;
    border-bottom: 2px solid var(--border) !important;
    vertical-align: middle !important;
    z-index: 10 !important;
  }
  .monev-scroll-wrap thead th.col-status {
    background: var(--surface2) !important;
    font-weight: 700;
  }
  .monev-scroll-wrap thead th.col-action {
    background: var(--surface2) !important;
    font-weight: 700;
  }
  .dark-mode .monev-scroll-wrap thead tr:first-child th,
  .dark-mode .monev-scroll-wrap thead tr:nth-child(2) th {
    background: #1e293b !important;
  }
  .monev-data-table td {
    border-bottom: 1px solid var(--border);
    border-right: 1px solid var(--border);
    padding: 10px 9px;
    line-height: 1.45;
    vertical-align: middle;
    overflow-wrap: anywhere;
  }
  .monev-data-table td:first-child {
    border-left: 1px solid var(--border);
  }
  .monev-data-table tbody tr:nth-child(even) { background: rgba(241,245,249,.55); }
  .monev-data-table thead th.col-risk { text-align: center !important; }
  .monev-data-table .badge, .monev-data-table .monev-level-pill { font-size:.62rem; padding:3px 8px; box-sizing: border-box; }
  .monev-data-table .monev-level-pill {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    text-align: center !important;
    margin: 0 auto !important;
    white-space: nowrap !important;
  }
  .monev-data-table .btn { white-space: nowrap; padding: 6px 10px; gap: 6px; }
  /* Toolbar: kotak cari & pilihan jumlah data sejajar satu baris (tidak naik-turun) */
  .monev-toolbar { flex-wrap: nowrap; }
  .monev-toolbar .search-bar { flex: 1 1 auto; min-width: 120px; max-width: 220px; }
  .monev-toolbar .datatable-dropdown { flex: 0 0 auto; }
  /* Header tabel: toolbar (cari + jumlah data) sejajar dengan judul, tidak turun ke bawah */
  #monev-table { scroll-margin-top: calc(var(--navbar-h, 64px) + 12px); }
  #monev-table .card-header { flex-wrap: nowrap !important; align-items: flex-start !important; gap: 16px; }
  #monev-table .card-header > div:first-child { flex: 1 1 auto; min-width: 0; }
  #monev-table .monev-toolbar { flex: 0 0 auto; }
  @media (max-width: 820px) { #monev-table .card-header { flex-wrap: wrap !important; } }
  /* Geser horizontal: scrollbar selalu tampak agar kolom yang tidak muat mudah digeser */
  .monev-scroll-wrap::-webkit-scrollbar { height: 10px; width: 10px; }
  .monev-scroll-wrap::-webkit-scrollbar-thumb { background: rgba(100,116,139,.45); border-radius: 8px; }
  .monev-scroll-wrap::-webkit-scrollbar-thumb:hover { background: rgba(100,116,139,.7); }
  .monev-scroll-wrap::-webkit-scrollbar-track { background: rgba(148,163,184,.14); border-radius: 8px; }

  /* ── Banner Insight Klik & Filter ─────────────────────────── */
  .monev-insight.is-clickable {
    cursor: pointer !important;
    transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease !important;
    user-select: none;
  }
  .monev-insight.is-clickable:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0,0,0,.08) !important;
  }
  .monev-insight.bad.is-clickable:hover {
    box-shadow: 0 6px 18px rgba(220,38,38,.18) !important;
    border-color: rgba(220,38,38,.4) !important;
  }
  .monev-insight.warn.is-clickable:hover {
    box-shadow: 0 6px 18px rgba(245,158,11,.18) !important;
    border-color: rgba(245,158,11,.4) !important;
  }
  .monev-banner-btn {
    font-size: .75rem !important;
    font-weight: 700 !important;
    padding: 6px 14px !important;
    border-radius: 8px !important;
    white-space: nowrap !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    box-shadow: 0 2px 6px rgba(0,0,0,.08) !important;
    transition: all .15s ease !important;
  }
  .monev-insight.is-clickable:hover .monev-banner-btn {
    transform: scale(1.03);
  }

  /* ── Modal Isi Monev (dipercantik) ────────────────────────── */
  #modalMonev .modal{ border-radius:18px; box-shadow:0 26px 64px -16px rgba(2,6,23,.5); }
  #modalMonev .modal-header{ padding:16px 22px; background:linear-gradient(115deg,rgba(2,132,199,.10),rgba(7,89,133,.03)); }
  #modalMonev .modal-title{ font-size:1.02rem; }
  #modalMonev .monev-meta{ font-size:.76rem; color:var(--text-muted); margin-top:3px; }
  #modalMonev .modal-body{ padding:18px 22px 10px; }
  #modalMonev .monev-form-grid{ display:grid; grid-template-columns:minmax(0,1fr) 300px; gap:20px; align-items:start; }
  #modalMonev .monev-slider-block{ background:var(--surface2); border:1px solid var(--border); border-radius:16px; padding:18px; margin-bottom:0; }
  #modalMonev .monev-slider-item{ margin-bottom:0; }
  #modalMonev .monev-slider-item + .monev-slider-item{ margin-top:16px; padding-top:16px; border-top:1px dashed var(--border); }
  #modalMonev .monev-slider-label{ display:flex; justify-content:space-between; align-items:center; gap:10px; font-size:.82rem; font-weight:700; margin-bottom:10px; }
  #modalMonev .monev-slider-label .val{ display:inline-flex; align-items:center; gap:8px; }
  #modalMonev .monev-slider-label .vbadge{ display:inline-flex; align-items:center; justify-content:center; min-width:24px; height:24px; padding:0 9px; border-radius:99px; background:var(--accent); color:#fff; font-size:.74rem; font-weight:800; line-height:1; }
  #modalMonev .monev-scale-hints{ display:flex; justify-content:space-between; gap:8px; font-size:.62rem; color:var(--text-muted); margin-top:6px; }
  #modalMonev .monev-scale-hints span{ white-space:nowrap; }
  #modalMonev input[type=range].monev-range{ width:100%; accent-color:var(--accent); cursor:pointer; height:24px; }
  #modalMonev .monev-text-grid{ display:grid; grid-template-columns:1fr 1fr; gap:14px 16px; margin-top:18px; }
  #modalMonev .monev-text-grid > div{ min-width:0; }
  #modalMonev .monev-text-grid > .full{ grid-column:1 / -1; }
  #modalMonev .monev-text-grid label{ display:block; font-size:.76rem; font-weight:700; margin-bottom:6px; }
  #modalMonev .monev-text-grid .form-control{ font-size:.82rem; }
  #modalMonev .monev-text-grid textarea.form-control{ min-height:92px; resize:vertical; }
  #modalMonev .monev-calc{ background:linear-gradient(180deg,var(--surface2),var(--surface)); border:1px solid var(--border); border-radius:16px; padding:18px; align-self:start; position:sticky; top:8px; box-shadow:var(--shadow-sm); }
  #modalMonev .monev-calc-nilai{ font-size:2.8rem; font-weight:800; color:var(--accent); line-height:1; letter-spacing:-.02em; font-variant-numeric:tabular-nums; }
  #modalMonev .monev-calc-grid{ display:grid; grid-template-columns:repeat(4,1fr); gap:8px; text-align:center; margin-top:14px; }
  #modalMonev .monev-calc-grid .cell b{ display:block; border-radius:9px; padding:8px 0; font-size:.9rem; font-weight:800; background:var(--primary); color:#fff; font-variant-numeric:tabular-nums; }
  #modalMonev .monev-calc-grid .cell span{ display:block; font-size:.6rem; color:var(--text-muted); margin-top:4px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
  #modalMonev .modal-footer{ padding:14px 22px; background:var(--surface2); border-top:1px solid var(--border); flex-wrap:wrap; gap:8px; }
  #modalMonev .monev-modal-nav{ display:flex; align-items:center; gap:6px; margin-right:auto; }
  #modalMonev .monev-modal-nav button{ min-width:34px; height:34px; padding:0 12px; border-radius:9px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-weight:700; font-size:.74rem; cursor:pointer; transition:all .15s; display:inline-flex; align-items:center; gap:6px; }
  #modalMonev .monev-modal-nav button:hover:not(:disabled){ border-color:var(--accent); color:var(--accent); }
  #modalMonev .monev-modal-nav button:disabled{ opacity:.35; cursor:not-allowed; }
  #modalMonev .monev-modal-nav .counter{ font-size:.72rem; font-weight:800; color:var(--text-muted); padding:0 8px; white-space:nowrap; }
  @media (max-width:860px){
    #modalMonev .monev-form-grid{ grid-template-columns:1fr; }
    #modalMonev .monev-text-grid{ grid-template-columns:1fr; }
    #modalMonev .monev-calc{ position:static; }
  }
  .monev-chart-grid { grid-template-columns: 1fr; }
  @media (max-width:768px) { .monev-chart-grid { margin-bottom: 14px; } }
</style>

<style>
  .heatmap-wrap.compact-heatmap{overflow:visible!important}
  .dashboard-heatmap-table{width:100%!important;table-layout:fixed;border-collapse:separate;border-spacing:4px}
  .dashboard-heatmap-table th,.dashboard-heatmap-table td{box-sizing:border-box}
  @media(max-width:900px){
    .heatmap{table-layout:fixed;border-spacing:3px;width:100%!important}
    .heatmap td{height:44px!important;font-size:.75rem!important;width:auto!important;min-width:0!important;max-width:none!important}
    .heatmap th{padding:4px 6px!important;font-size:.65rem!important;width:auto!important;min-width:0!important;max-width:none!important}
  }
  @media(min-width:901px){
    .compact-heatmap{align-items:center!important}
    .dashboard-heatmap-table{margin-left:auto!important;margin-right:auto!important;text-align:center;max-width:650px}
    .dashboard-heatmap-table th,.dashboard-heatmap-table td{text-align:center!important;vertical-align:middle!important;width:auto!important;min-width:0!important;max-width:none!important}
    .dashboard-heatmap-table thead th{height:30px!important;padding:4px!important}
    .dashboard-heatmap-table tbody th{height:58px!important;padding:4px!important}
    .compact-heatmap .heatmap{width:100%!important;max-width:650px;table-layout:fixed}
    .compact-heatmap .heatmap td{height:58px!important;padding:6px!important;width:auto!important;min-width:0!important;max-width:none!important}
    .compact-heatmap .heatmap td>div:first-child{font-size:1.15rem!important}
    .compact-heatmap .heatmap td>div:nth-child(2){font-size:.56rem!important}
    .compact-heatmap .heatmap th{font-size:.75rem!important;padding:6px!important;width:auto!important;min-width:0!important;max-width:none!important}
  }

  /* ── Switcher Periode Matriks Risiko 5x5: Dropdown Ringkas & Rapi ── */
  .monev-matrix-tabs {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    flex-wrap: nowrap;
  }
  .monev-matrix-tabs .matrix-period-label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: .80rem;
    font-weight: 700;
    color: var(--text-muted);
    line-height: 1;
    white-space: nowrap;
    user-select: none;
  }
  .monev-matrix-tabs .matrix-period-label i {
    font-size: .82rem;
    color: var(--accent);
  }
  .matrix-select-wrap {
    position: relative;
    display: inline-flex;
    align-items: center;
  }
  .matrix-period-select {
    height: 34px !important;
    min-width: 140px;
    padding: 0 30px 0 12px !important;
    font-size: .80rem !important;
    font-weight: 700 !important;
    color: var(--text) !important;
    background-color: var(--surface) !important;
    border: 1.5px solid var(--border) !important;
    border-radius: 8px !important;
    cursor: pointer !important;
    box-shadow: 0 1px 3px rgba(0,0,0,.04) !important;
    transition: all .2s ease !important;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    background-image: url("data:image/svg+xml;utf8,<svg fill='%2364748b' height='20' viewBox='0 0 24 24' width='20' xmlns='http://www.w3.org/2000/svg'><path d='M7 10l5 5 5-5z'/></svg>") !important;
    background-repeat: no-repeat !important;
    background-position: right 8px center !important;
    background-size: 16px !important;
  }
  .matrix-period-select:hover {
    border-color: var(--accent) !important;
  }
  .matrix-period-select:focus {
    border-color: var(--accent) !important;
    outline: none !important;
    box-shadow: 0 0 0 3px var(--accent-glow) !important;
  }
  @media(max-width:640px){
    .monev-matrix-tabs {
      width: 100%;
      justify-content: space-between;
    }
    .matrix-period-select {
      flex: 1 1 auto;
      max-width: 200px;
    }
  }
  /* ── Opsi 1: Grid 2-Kolom Matriks & Distribusi Risiko ── */
  .monev-matrix-analytics-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.35fr) minmax(0, 1fr);
    gap: 20px;
    margin-bottom: 20px;
    align-items: stretch;
  }
  @media (max-width: 1180px) {
    .monev-matrix-analytics-grid {
      grid-template-columns: 1fr;
    }
  }

  /* Donut Chart Container */
  .monev-donut-wrapper {
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 6px 0 2px;
  }
  .monev-donut-canvas-box {
    position: relative;
    width: 180px;
    height: 160px;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .monev-donut-center-info {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    text-align: center;
    pointer-events: none;
    user-select: none;
  }
  .donut-center-num {
    font-size: 1.55rem;
    font-weight: 800;
    color: var(--text);
    line-height: 1;
    letter-spacing: -0.02em;
  }
  .donut-center-label {
    font-size: .62rem;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-top: 2px;
  }

  /* 5 Level Stat Cards */
  .monev-level-cards-list {
    display: flex;
    flex-direction: column;
    gap: 7px;
    flex: 1;
  }
  .monev-level-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 7px 11px;
    border-radius: 9px;
    background: var(--surface2);
    border: 1.5px solid var(--border);
    cursor: pointer;
    transition: all .2s cubic-bezier(.4, 0, .2, 1);
    user-select: none;
    position: relative;
    outline: none;
  }
  .monev-level-card:hover {
    transform: translateX(3px);
    border-color: var(--card-color);
    box-shadow: 0 3px 10px rgba(0,0,0,.06);
  }
  .monev-level-card.active {
    border-color: var(--card-color) !important;
    background: var(--card-tint) !important;
    box-shadow: 0 0 0 2px var(--card-color), 0 4px 12px rgba(0,0,0,.08) !important;
  }
  .monev-level-card .level-info {
    display: flex;
    align-items: center;
    gap: 9px;
    min-width: 0;
  }
  .monev-level-card .level-dot {
    width: 11px;
    height: 11px;
    border-radius: 50%;
    flex-shrink: 0;
    box-shadow: 0 0 0 2px rgba(255,255,255,.9);
  }
  .dark-mode .monev-level-card .level-dot {
    box-shadow: 0 0 0 2px rgba(30,41,59,.9);
  }
  .monev-level-card .level-name {
    font-size: .78rem;
    font-weight: 700;
    color: var(--text);
    line-height: 1.2;
  }
  .monev-level-card .level-range {
    font-size: .64rem;
    color: var(--text-muted);
    font-weight: 500;
    margin-top: 1px;
  }
  .monev-level-card .level-metrics {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
  }
  .monev-level-card .level-count-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 25px;
    height: 22px;
    padding: 0 7px;
    border-radius: 6px;
    font-size: .76rem;
    font-weight: 800;
    background: var(--card-badge-bg);
    color: var(--card-badge-color);
    line-height: 1;
  }
  .monev-level-card .level-pct {
    font-size: .74rem;
    font-weight: 700;
    color: var(--text-muted);
    min-width: 34px;
    text-align: right;
  }

  /* Specific level themes */
  .card-st {
    --card-color: #dc2626;
    --card-tint: rgba(220, 38, 38, .07);
    --card-badge-bg: #fee2e2;
    --card-badge-color: #991b1b;
  }
  .dark-mode .card-st {
    --card-badge-bg: rgba(220, 38, 38, .22);
    --card-badge-color: #fca5a5;
  }
  .card-t {
    --card-color: #ea580c;
    --card-tint: rgba(234, 88, 12, .07);
    --card-badge-bg: #ffedd5;
    --card-badge-color: #c2410c;
  }
  .dark-mode .card-t {
    --card-badge-bg: rgba(234, 88, 12, .22);
    --card-badge-color: #fdba74;
  }
  .card-s {
    --card-color: #d97706;
    --card-tint: rgba(217, 119, 6, .07);
    --card-badge-bg: #fef3c7;
    --card-badge-color: #b45309;
  }
  .dark-mode .card-s {
    --card-badge-bg: rgba(217, 119, 6, .22);
    --card-badge-color: #fde68a;
  }
  .card-r {
    --card-color: #16a34a;
    --card-tint: rgba(22, 163, 74, .07);
    --card-badge-bg: #dcfce7;
    --card-badge-color: #166534;
  }
  .dark-mode .card-r {
    --card-badge-bg: rgba(22, 163, 74, .22);
    --card-badge-color: #86efac;
  }
  .card-sr {
    --card-color: #2563eb;
    --card-tint: rgba(37, 99, 235, .07);
    --card-badge-bg: #dbeafe;
    --card-badge-color: #1d4ed8;
  }
  .dark-mode .card-sr {
    --card-badge-bg: rgba(37, 99, 235, .22);
    --card-badge-color: #93c5fd;
  }
  .monev-analytics-hint {
    font-size: .67rem;
    color: var(--text-muted);
    text-align: center;
    line-height: 1.4;
    margin-top: auto;
    padding-top: 4px;
  }
  @media (max-width: 1180px) and (min-width: 641px) {
    .monev-analytics-body-content {
      display: grid;
      grid-template-columns: 200px 1fr;
      gap: 20px;
      align-items: center;
    }
  }
</style>

<script>
(() => {
  // ── Data Matriks Risiko 5x5 Interaktif Triwulanan ────────────────
  const rawMatrixData = <?= json_encode($matrixData ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  let currentMatrixPeriod = <?= json_encode($activeMatrixPeriod ?? 'tw1') ?>;
  window.currentMatrixPeriod = currentMatrixPeriod;
  let monevLevelChart = null;

  const levelLabels = ['Sangat Tinggi', 'Tinggi', 'Sedang', 'Rendah', 'Sangat Rendah'];
  const levelColors = ['#dc2626', '#ea580c', '#eab308', '#16a34a', '#2563eb'];

  function initOrUpdateDonutChart(levels, total) {
    const canvas = document.getElementById('monevLevelDonut');
    if (!canvas || typeof Chart === 'undefined') return;

    const isDark = document.body.classList.contains('dark-mode');
    const borderColor = isDark ? '#1e293b' : '#ffffff';

    const dataValues = (total > 0)
      ? levelLabels.map(lvl => (levels && levels[lvl]) || 0)
      : [1];
    const bgColors = (total > 0)
      ? levelColors
      : [isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)'];

    if (!monevLevelChart) {
      monevLevelChart = new Chart(canvas.getContext('2d'), {
        type: 'doughnut',
        data: {
          labels: (total > 0) ? levelLabels : ['Belum Ada Data'],
          datasets: [{
            data: dataValues,
            backgroundColor: bgColors,
            borderColor: borderColor,
            borderWidth: 2,
            hoverOffset: (total > 0) ? 6 : 0
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          cutout: '72%',
          animation: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              enabled: total > 0,
              backgroundColor: isDark ? '#1e293b' : '#ffffff',
              titleColor: isDark ? '#ffffff' : '#0f172a',
              bodyColor: isDark ? '#cbd5e1' : '#334155',
              borderColor: isDark ? 'rgba(255,255,255,.15)' : 'rgba(0,0,0,.1)',
              borderWidth: 1,
              padding: 9,
              cornerRadius: 8,
              callbacks: {
                label: function(ctx) {
                  const val = ctx.raw || 0;
                  const pct = total > 0 ? Math.round((val / total) * 100) : 0;
                  return ` ${ctx.label}: ${val} risiko (${pct}%)`;
                }
              }
            }
          },
          onClick: (evt, activeEls) => {
            if (activeEls.length > 0 && total > 0) {
              const idx = activeEls[0].index;
              const clickedLevel = levelLabels[idx];
              if (clickedLevel) filterByRiskLevel(clickedLevel);
            }
          }
        }
      });
    } else {
      monevLevelChart.data.labels = (total > 0) ? levelLabels : ['Belum Ada Data'];
      monevLevelChart.data.datasets[0].data = dataValues;
      monevLevelChart.data.datasets[0].backgroundColor = bgColors;
      monevLevelChart.data.datasets[0].borderColor = borderColor;
      monevLevelChart.data.datasets[0].hoverOffset = (total > 0) ? 6 : 0;
      monevLevelChart.options.plugins.tooltip.enabled = total > 0;
      monevLevelChart.update('none');
    }
  }

  function updateLevelCards(levels, total, label) {
    const centerTotalEl = document.getElementById('donutCenterTotal');
    if (centerTotalEl) centerTotalEl.textContent = total;

    const totalBadgeEl = document.getElementById('analyticsTotalBadge');
    if (totalBadgeEl) totalBadgeEl.textContent = `${total} Risiko Terpantau`;

    const periodLabelEl = document.getElementById('analyticsPeriodLabel');
    if (periodLabelEl) periodLabelEl.textContent = label;

    const mapping = [
      { key: 'Sangat Tinggi', countId: 'lvlCount_ST', pctId: 'lvlPct_ST' },
      { key: 'Tinggi',        countId: 'lvlCount_T',  pctId: 'lvlPct_T' },
      { key: 'Sedang',        countId: 'lvlCount_S',  pctId: 'lvlPct_S' },
      { key: 'Rendah',        countId: 'lvlCount_R',  pctId: 'lvlPct_R' },
      { key: 'Sangat Rendah', countId: 'lvlCount_SR', pctId: 'lvlPct_SR' }
    ];

    mapping.forEach(m => {
      const cnt = (levels && levels[m.key]) || 0;
      const pct = total > 0 ? Math.round((cnt / total) * 100) : 0;
      const cEl = document.getElementById(m.countId);
      const pEl = document.getElementById(m.pctId);
      if (cEl) cEl.textContent = cnt;
      if (pEl) pEl.textContent = `${pct}%`;
    });
  }

  window.renderMonevAnalytics = function() {
    const pData = rawMatrixData[currentMatrixPeriod] || { counts: {}, items: {}, total: 0, label: '', levels: {} };
    updateLevelCards(pData.levels || {}, pData.total, pData.label);
    if (typeof Chart !== 'undefined') {
      initOrUpdateDonutChart(pData.levels || {}, pData.total);
    } else {
      setTimeout(() => {
        if (typeof Chart !== 'undefined') {
          initOrUpdateDonutChart(pData.levels || {}, pData.total);
        }
      }, 250);
    }
  };

  window.switchMonevPeriod = function(period) {
    currentMatrixPeriod = period;
    window.currentMatrixPeriod = period;
    const sel = document.getElementById('monevPeriodSelect');
    if (sel && sel.value !== period) {
      sel.value = period;
    }
    document.querySelectorAll('.matrix-period-btn').forEach(btn => {
      if (btn.dataset.period === period) {
        btn.className = 'btn btn-sm btn-primary matrix-period-btn';
      } else {
        btn.className = 'btn btn-sm btn-outline matrix-period-btn';
      }
    });
    renderMonevMatrix();
    renderMonevAnalytics();

    // Re-apply table filter if level filter is active
    if (window.monevTableState && window.monevTableState.level && typeof window.applyMonevTable === 'function') {
      window.applyMonevTable();
    }

    // Otomatis sesuaikan judul di tabel monev berdasarkan periode matrik risiko yang dipilih
    const tableTitleEl = document.getElementById('monevTableTitle');
    if (tableTitleEl) {
      let label = 'Monev Triwulan 1';
      if (period === 'awal') {
        label = 'Monev Kondisi Awal (Baseline KKPR)';
      } else if (period === 'tw1') {
        label = 'Monev Triwulan 1';
      } else if (period === 'tw2') {
        label = 'Monev Triwulan 2';
      } else if (period === 'tw3') {
        label = 'Monev Triwulan 3';
      } else if (period === 'tw4') {
        label = 'Monev Triwulan 4';
      }
      tableTitleEl.innerHTML = `<i class="fas fa-table"></i> ${label} — Tahun <?= xss($activeTahun) ?>`;
    }

    const modalTitleEl = document.getElementById('monevModalTitle');
    if (modalTitleEl && period !== 'awal') {
      modalTitleEl.innerHTML = `<i class="fas fa-pen-to-square" style="color:var(--accent)"></i> Isi Monev — Monev Triwulan ${period.replace('tw', '')}`;
    }
    const formJenisEl = document.getElementById('monevModalFormJenis');
    if (formJenisEl && period !== 'awal') {
      formJenisEl.value = period;
    }
  };

  window.renderMonevMatrix = function() {
    const pData = rawMatrixData[currentMatrixPeriod] || { counts: {}, items: {}, total: 0, label: '', levels: {} };
    const labelEl = document.getElementById('matrixPeriodLabel');
    const countEl = document.getElementById('matrixCountInfo');
    if (labelEl) labelEl.textContent = pData.label;
    if (countEl) countEl.innerHTML = `<strong>${pData.total}</strong> dari <?= count($rows) ?> risiko terpantau`;

    for (let p = 1; p <= 5; p++) {
      for (let d = 1; d <= 5; d++) {
        const cnt = (pData.counts && pData.counts[p] && pData.counts[p][d]) || 0;
        const badge = document.getElementById(`mbadge_${p}_${d}`);
        const cell = document.getElementById(`mcell_${p}_${d}`);
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

  window.filterByRiskLevel = function(level) {
    const state = window.monevTableState;
    if (!state) return;

    if (state.level === level) {
      clearLevelFilter();
      return;
    }
    state.level = level;
    state.page = 1;

    // Reset general stat filter to prevent conflict
    state.filter = 'all';
    document.querySelectorAll('.monev-filter-card').forEach(c => c.classList.remove('active'));

    document.querySelectorAll('.monev-level-card').forEach(card => {
      card.classList.toggle('active', card.dataset.level === level);
    });

    const resetBtn = document.getElementById('btnResetLevelFilter');
    if (resetBtn) resetBtn.style.display = 'inline-flex';

    const chip = document.getElementById('monevFilterChip');
    const chipText = document.getElementById('monevFilterChipText');
    if (chip && chipText) {
      chip.style.display = '';
      chipText.textContent = `Tingkat: ${level}`;
    }

    if (typeof window.applyMonevTable === 'function') {
      window.applyMonevTable();
    }

    const t = document.getElementById('monev-table');
    if (t) t.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };

  window.clearLevelFilter = function() {
    const state = window.monevTableState;
    if (!state) return;
    state.level = '';
    state.page = 1;

    document.querySelectorAll('.monev-level-card').forEach(card => {
      card.classList.remove('active');
    });

    const resetBtn = document.getElementById('btnResetLevelFilter');
    if (resetBtn) resetBtn.style.display = 'none';

    const chip = document.getElementById('monevFilterChip');
    if (chip) {
      if (state.filter === 'all') {
        chip.style.display = 'none';
      } else {
        const fLabels = { terisi: 'Sudah dipantau', efektif: 'Efektif', eskalasi: 'Eskalasi', penurunan: 'Penurunan' };
        document.getElementById('monevFilterChipText').textContent = fLabels[state.filter] || state.filter;
      }
    }

    if (typeof window.applyMonevTable === 'function') {
      window.applyMonevTable();
    }
  };

  window.clickMonevCell = function(p, d, skor) {
    const pData = rawMatrixData[currentMatrixPeriod];
    const items = (pData && pData.items && pData.items[p] && pData.items[p][d]) || [];
    if (!items || items.length === 0) {
      if (typeof Swal !== 'undefined') {
        Swal.fire({
          title: `Sel P${p} &times; D${d} (${pData ? pData.label : ''})`,
          text: 'Tidak ada risiko yang terdaftar di koordinat ini pada periode tersebut.',
          icon: 'info',
          confirmButtonText: 'Tutup'
        });
      }
      return;
    }

    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    let html = '<div style="max-height: 320px; overflow-y: auto; text-align: left; margin-top:8px">';
    html += '<table class="data-table" style="width: 100%; border-collapse: collapse; font-size:.82rem">';
    html += '<thead style="position: sticky; top: 0; background: var(--surface2); z-index:2"><tr><th style="padding:8px;border-bottom:1px solid var(--border);width:75px">Kode</th><th style="padding:8px;border-bottom:1px solid var(--border)">Nama Risiko</th><th style="padding:8px;border-bottom:1px solid var(--border);text-align:center;width:95px">Level</th><th style="padding:8px;border-bottom:1px solid var(--border);text-align:center;width:55px">Nilai</th></tr></thead><tbody>';

    items.forEach(item => {
      let bgC = '#3b82f6', fgC = '#fff';
      if(item.tingkat === 'Sangat Tinggi') bgC = '#dc2626';
      else if(item.tingkat === 'Tinggi') bgC = '#ea580c';
      else if(item.tingkat === 'Sedang') { bgC = '#FFFF00'; fgC = '#1e293b'; }
      else if(item.tingkat === 'Rendah') bgC = '#22c55e';

      const kode = escapeHtml(item.kode);
      const nama = escapeHtml(item.nama);
      const level = escapeHtml(item.tingkat);
      const nilai = escapeHtml(item.nilai);
      const unit = escapeHtml(item.unit);

      html += `<tr>
        <td style="padding:8px;border-bottom:1px solid var(--border)"><span class="badge-kode-risiko" style="font-size:0.75rem">${kode}</span></td>
        <td style="padding:8px;border-bottom:1px solid var(--border);max-width:260px;white-space:normal">
          <div style="font-weight:700;color:var(--text);line-height:1.3">${nama}</div>
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

    if (typeof Swal !== 'undefined') {
      Swal.fire({
        title: `Daftar Risiko: P${p} &times; D${d} (Skor ${skor})`,
        html: `<div style="font-size:.82rem;color:var(--text-muted);margin-bottom:6px">Periode: <strong>${escapeHtml(pData.label)}</strong> &bull; Total: <strong>${items.length}</strong> risiko</div>${html}`,
        width: 650,
        showCloseButton: true,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-filter"></i> Filter di Tabel Monev',
        cancelButtonText: 'Tutup',
        confirmButtonColor: 'var(--primary)'
      }).then(res => {
        if (res.isConfirmed) {
          const searchEl = document.getElementById('searchMonevTahunan');
          if (searchEl) {
            searchEl.value = items[0].kode;
            monevTableState.q = items[0].kode.toLowerCase();
            monevTableState.page = 1;
            applyMonevTable();
            const t = document.getElementById('monev-table');
            if (t) t.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        }
      });
    }
  };

  function initMonevPage() {
    // ── Count-up stat cards ──────────────────────────────────
    document.querySelectorAll('.stat-value[data-count]').forEach(el => {
      const target = parseFloat(el.dataset.count) || 0;
      const suffix = el.dataset.suffix || '';
      if (target === 0) { el.textContent = '0' + suffix; return; }
      const dur = 900, t0 = performance.now();
      const step = now => {
        const p = Math.min((now - t0) / dur, 1);
        const eased = 1 - Math.pow(1 - p, 3);
        el.textContent = Math.round(target * eased) + suffix;
        if (p < 1) requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
    });

    // ── Progress bar animasi ─────────────────────────────────
    setTimeout(() => {
      document.querySelectorAll('.monev-progress-bar').forEach(bar => {
        bar.style.width = bar.dataset.target + '%';
      });
    }, 150);

    // ── Inisialisasi Matriks Risiko 5x5 & Distribusi Tingkat ────
    renderMonevMatrix();
    renderMonevAnalytics();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMonevPage);
  } else {
    initMonevPage();
  }
})();
</script>

<script>
// ── Filter + pagination tabel ────────────────────────────────
window.monevTableState = { q: '', unit: '', filter: 'all', level: '', page: 1, size: 10 };
const monevTableState = window.monevTableState;
const filterLabels = { terisi: 'Sudah dipantau', belum: 'Belum dipantau', efektif: 'Efektif', eskalasi: 'Eskalasi', penurunan: 'Penurunan' };

function applyMonevTable() {
  const allRows = [...document.querySelectorAll('#tableMonevTahunan tbody tr.monev-row')];
  const activePeriod = window.currentMatrixPeriod || 'tw1';

  const visible = allRows.filter(tr => {
    if (monevTableState.q && !tr.dataset.search.includes(monevTableState.q)) return false;
    if (monevTableState.unit && tr.dataset.unit !== monevTableState.unit) return false;
    if (monevTableState.level) {
      let rowLevel = '';
      if (activePeriod === 'awal') {
        rowLevel = tr.dataset.levelAwal || '';
      } else if (activePeriod === 'tw1') {
        rowLevel = tr.dataset.levelTw1 || '';
      } else if (activePeriod === 'tw2') {
        rowLevel = tr.dataset.levelTw2 || '';
      } else if (activePeriod === 'tw3') {
        rowLevel = tr.dataset.levelTw3 || '';
      } else if (activePeriod === 'tw4') {
        rowLevel = tr.dataset.levelTw4 || '';
      } else {
        rowLevel = tr.dataset.levelCurr || '';
      }
      if (rowLevel !== monevTableState.level) return false;
    }
    switch (monevTableState.filter) {
      case 'terisi': return tr.dataset.terisi === '1';
      case 'belum': return tr.dataset.terisi === '0';
      case 'efektif': return tr.dataset.efektif === '1';
      case 'eskalasi': return tr.dataset.naik === '1';
      case 'penurunan': return tr.dataset.turun === '1';
      default: return true;
    }
  });
  const total = visible.length;
  const pages = Math.max(1, Math.ceil(total / monevTableState.size));
  if (monevTableState.page > pages) monevTableState.page = pages;
  const start = (monevTableState.page - 1) * monevTableState.size;

  allRows.forEach((tr, i) => { tr.style.display = 'none'; });
  visible.slice(start, start + monevTableState.size).forEach(tr => { tr.style.display = ''; });

  const info = document.getElementById('monevPageInfo');
  info.textContent = total === 0
    ? 'Tidak ada data yang cocok dengan filter'
    : 'Menampilkan ' + (start + 1) + '–' + Math.min(start + monevTableState.size, total) + ' dari ' + total + ' risiko';

  const pagesEl = document.getElementById('monevPages');
  pagesEl.innerHTML = '';
  const mkBtn = (html, page, disabled, active, title) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'page-btn' + (active ? ' active' : '');
    b.innerHTML = html;
    b.disabled = disabled;
    if (title) b.title = title;
    if (!disabled) b.onclick = () => { monevTableState.page = page; applyMonevTable(); };
    pagesEl.appendChild(b);
  };
  mkBtn('<i class="fas fa-angles-left"></i>', 1, monevTableState.page <= 1, false, 'Halaman Pertama');
  mkBtn('<i class="fas fa-chevron-left"></i>', monevTableState.page - 1, monevTableState.page <= 1, false, 'Halaman Sebelumnya');
  const winStart = Math.max(1, Math.min(monevTableState.page - 2, pages - 4));
  const winEnd = Math.min(pages, winStart + 4);
  for (let p = winStart; p <= winEnd; p++) mkBtn(String(p), p, false, p === monevTableState.page);
  mkBtn('<i class="fas fa-chevron-right"></i>', monevTableState.page + 1, monevTableState.page >= pages, false, 'Halaman Berikutnya');
}
window.applyMonevTable = applyMonevTable;

function setMonevFilter(f) {
  monevTableState.filter = f;
  monevTableState.level = '';
  document.querySelectorAll('.monev-level-card').forEach(c => c.classList.remove('active'));
  const resetBtn = document.getElementById('btnResetLevelFilter');
  if (resetBtn) resetBtn.style.display = 'none';

  monevTableState.page = 1;
  document.querySelectorAll('.monev-filter-card').forEach(c => {
    c.classList.toggle('active', c.dataset.filter === f);
  });
  const chip = document.getElementById('monevFilterChip');
  if (f === 'all') {
    chip.style.display = 'none';
  } else {
    chip.style.display = '';
    document.getElementById('monevFilterChipText').textContent = filterLabels[f] || f;
  }
  applyMonevTable();
}
window.setMonevFilter = setMonevFilter;

window.triggerBannerFilter = function(filterType) {
  if (typeof setMonevFilter === 'function') {
    setMonevFilter(filterType);
  }
  const t = document.getElementById('monev-table');
  if (t) {
    t.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
};

function clearMonevFilter() {
  monevTableState.filter = 'all';
  if (typeof clearLevelFilter === 'function') {
    clearLevelFilter();
  } else {
    monevTableState.level = '';
    applyMonevTable();
  }
  document.querySelectorAll('.monev-filter-card').forEach(c => c.classList.remove('active'));
}

document.addEventListener('DOMContentLoaded', () => {
  const scrollToMonevTable = () => {
    const t = document.getElementById('monev-table');
    if (t) t.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };
  document.querySelectorAll('.monev-filter-card').forEach(card => {
    // Kartu statistik berfungsi sebagai tombol filter: klik/Enter/Spasi
    // akan memfilter tabel lalu menggulir ke datanya.
    card.setAttribute('role', 'button');
    if (!card.hasAttribute('tabindex')) card.setAttribute('tabindex', '0');
    const activate = () => {
      setMonevFilter(card.dataset.filter === monevTableState.filter ? 'all' : card.dataset.filter);
      scrollToMonevTable();
    };
    card.addEventListener('click', activate);
    card.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); activate(); }
    });
  });
  const searchEl = document.getElementById('searchMonevTahunan');
  let debounce;
  searchEl.addEventListener('input', () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
      monevTableState.q = searchEl.value.trim().toLowerCase();
      monevTableState.page = 1;
      applyMonevTable();
    }, 200);
  });
  document.getElementById('limitMonevTahunan').addEventListener('change', function () {
    monevTableState.size = parseInt(this.value, 10) || 10;
    monevTableState.page = 1;
    applyMonevTable();
  });
  applyMonevTable();
});
</script>

<?php if ($canInput && !empty($jsRows)): ?>
<script>
// ── Modal Isi Monev + navigasi antar-risiko ──────────────────
const monevData = <?= json_encode($jsRows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const monevBobot = <?= json_encode($bobotJs) ?>;
const monevPLabels = { 1: 'Jarang', 2: 'Kecil', 3: 'Sedang', 4: 'Besar', 5: 'Hampir Pasti' };
const monevDLabels = { 1: 'Tidak Signifikan', 2: 'Kecil', 3: 'Sedang', 4: 'Besar', 5: 'Katastropik' };
const monevLevelStyle = {
  'Sangat Tinggi': ['lp-st', 'Sangat Tinggi'],
  'Tinggi': ['lp-t', 'Tinggi'],
  'Sedang': ['lp-s', 'Sedang'],
  'Rendah': ['lp-r', 'Rendah'],
  'Sangat Rendah': ['lp-sr', 'Sangat Rendah']
};
let monevIdx = 0;

function openMonev(idx) {
  monevIdx = idx;
  const row = monevData[idx];
  if (!row) return;
  document.getElementById('monevRiskId').value = row.id;
  document.getElementById('monevModalMeta').innerHTML = '<b>' + row.nama.replace(/</g, '&lt;') + '</b> — ' + row.unit.replace(/</g, '&lt;');
  document.getElementById('monevP').value = row.p || 1;
  document.getElementById('monevD').value = row.d || 1;
  document.getElementById('monevUpaya').value = row.upaya || '';
  document.getElementById('monevLink').value = row.link || '';
  document.getElementById('monevKendala').value = row.kendala || '';
  document.getElementById('monevRtl').value = row.rtl || '';
  updateMonevCalc();
  document.getElementById('monevCounter').textContent = (idx + 1) + ' / ' + monevData.length;
  document.getElementById('monevPrevBtn').disabled = idx <= 0;
  document.getElementById('monevNextBtn').disabled = idx >= monevData.length - 1;
  openModal('modalMonev');
}

function monevNav(dir) {
  const next = monevIdx + dir;
  if (next < 0 || next >= monevData.length) return;
  openMonev(next);
}

function updateMonevCalc() {
  const p = parseInt(document.getElementById('monevP').value) || 1;
  const d = parseInt(document.getElementById('monevD').value) || 1;
  const b = (monevBobot[p] && monevBobot[p][d]) || 1;
  const skor = p * d * b;
  const nilai = Math.round(skor);
  const level = nilai >= 20 ? 'Sangat Tinggi' : nilai >= 15 ? 'Tinggi' : nilai >= 10 ? 'Sedang' : nilai >= 5 ? 'Rendah' : 'Sangat Rendah';
  document.getElementById('monevPBadge').textContent = p;
  document.getElementById('monevPText').textContent = monevPLabels[p];
  document.getElementById('monevDBadge').textContent = d;
  document.getElementById('monevDText').textContent = monevDLabels[d];
  document.getElementById('monevNilai').textContent = nilai;
  const tingkatEl = document.getElementById('monevTingkat');
  tingkatEl.textContent = level;
  tingkatEl.className = 'monev-level-pill ' + monevLevelStyle[level][0];
  document.getElementById('monevHasilP').textContent = p;
  document.getElementById('monevHasilD').textContent = d;
  document.getElementById('monevBobotVal').textContent = b.toFixed(2);
  document.getElementById('monevSkor').textContent = nilai;
}

document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('monevP').addEventListener('input', updateMonevCalc);
  document.getElementById('monevD').addEventListener('input', updateMonevCalc);
});

// Navigasi keyboard saat modal terbuka
document.addEventListener('keydown', e => {
  const modal = document.getElementById('modalMonev');
  if (!modal || modal.style.display !== 'flex') return;
  if (e.key === 'ArrowLeft') monevNav(-1);
  if (e.key === 'ArrowRight') monevNav(1);
});
</script>
<?php endif; ?>
