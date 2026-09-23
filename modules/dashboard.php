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
$triwulan = ['Q1' => ['jml' => 0, 'avg' => 0, 'tinggi' => 0], 'Q2' => ['jml' => 0, 'avg' => 0, 'tinggi' => 0], 'Q3' => ['jml' => 0, 'avg' => 0, 'tinggi' => 0], 'Q4' => ['jml' => 0, 'avg' => 0, 'tinggi' => 0]];
$trenLabels = []; $trenAvg = []; $trenJml = []; $trenTinggi = []; $trenTotalRisk = 0; $trenTotalHigh = 0;
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
  SELECT d.nama_risiko, d.nilai AS skor_risiko, d.tingkat_risiko AS level_risiko,
         d.rencana_penanganan, d.penanggungjawab, d.jadwal_pelaksanaan
  FROM profil_risiko_detail d
  JOIN profil_risiko p ON d.id_profil = p.id
  WHERE $whereStr
  ORDER BY d.nilai DESC LIMIT ? OFFSET ?
", $types . 'ii', array_merge($params, [$priorityPerPage, $priorityOffset]));

// -- Heatmap Data --
$heatRaw = dashQuery($db, "
  SELECT d.probabilitas, d.dampak AS dampak_level, COUNT(*) AS cnt
  FROM profil_risiko_detail d
  JOIN profil_risiko p ON d.id_profil = p.id
  WHERE $whereStr
  GROUP BY d.probabilitas, d.dampak
", $types, $params);
$heatMap = [];
foreach ($heatRaw as $h) {
    $heatMap[$h['probabilitas']][$h['dampak_level']] = $h['cnt'];
}

$dashboardFilters = array_filter(['page'=>'dashboard','q'=>$search,'level'=>$fLevel,'tahun'=>$fTahun,'unit'=>$fUnit,'priority_limit'=>$_GET['priority_limit']??''], static fn($value) => $value !== '');
$priorityPageQuery = http_build_query($dashboardFilters);

// -- Tren Triwulanan (skor rata-rata) --
$triwulanRaw = dashQuery($db, "
  SELECT
    QUARTER(p.tgl_penilaian) AS q,
    COUNT(d.id) AS jml,
    AVG(d.nilai) AS avg_skor,
    SUM(d.tingkat_risiko IN ('Tinggi','Sangat Tinggi')) AS jml_tinggi
  FROM profil_risiko_detail d
  JOIN profil_risiko p ON d.id_profil = p.id
  WHERE $whereStr AND p.tgl_penilaian IS NOT NULL
  GROUP BY QUARTER(p.tgl_penilaian)
", $types, $params);
$triwulan = ['Q1' => ['jml' => 0, 'avg' => 0, 'tinggi' => 0], 'Q2' => ['jml' => 0, 'avg' => 0, 'tinggi' => 0], 'Q3' => ['jml' => 0, 'avg' => 0, 'tinggi' => 0], 'Q4' => ['jml' => 0, 'avg' => 0, 'tinggi' => 0]];
foreach ($triwulanRaw as $t) {
    if ($t['q']) {
        $key = 'Q' . $t['q'];
        if (isset($triwulan[$key])) {
            $triwulan[$key] = ['jml' => (int)$t['jml'], 'avg' => round((float)$t['avg_skor'], 1), 'tinggi' => (int)$t['jml_tinggi']];
        }
    }
}
$trenLabels=[];$trenAvg=[];$trenJml=[];$trenTinggi=[];
foreach(['Q1','Q2','Q3','Q4'] as $q){$trenLabels[]=$q;$trenAvg[]=(float)$triwulan[$q]['avg'];$trenJml[]=(int)$triwulan[$q]['jml'];$trenTinggi[]=(int)$triwulan[$q]['tinggi'];}
$trenTotalRisk = array_sum($trenJml);
$trenTotalHigh = array_sum($trenTinggi);

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
      <div class="card-header" style="background:linear-gradient(135deg,rgba(59,130,246,.05),transparent)">
        <span class="card-title"><i class="fas fa-th" style="color:var(--accent)"></i> Matriks Risiko 5x5</span>
      </div>
      <div class="card-body heatmap-wrap compact-heatmap" style="display:flex; flex-direction:column; align-items:center;justify-content:center;flex:1;padding:14px 12px;text-align:center">
        <?php
        $pLabels = [1=>'Jarang',2=>'Kecil',3=>'Sedang',4=>'Besar',5=>'Hampir Pasti'];
        $dLabels = [1=>'Tidak Signifikan',2=>'Kecil',3=>'Sedang',4=>'Besar',5=>'Katastropik'];
        $tingkatShort = fn($s) => $s>=20?'Sangat Tinggi':($s>=15?'Tinggi':($s>=10?'Sedang':($s>=5?'Rendah':'Sangat Rendah')));
        ?>
        <table class="heatmap dashboard-heatmap-table">
          <thead>
            <tr>
              <th style="text-align:right;padding-right:8px">P \ D</th>
              <?php for($d=1;$d<=5;$d++): ?>
                <th title="D<?= $d ?> = <?= $dLabels[$d] ?>">D<?= $d ?></th>
              <?php endfor; ?>
            </tr>
          </thead>
          <tbody>
            <?php for($p=5;$p>=1;$p--): ?>
            <tr>
              <th style="text-align:right;padding-right:8px;color:var(--text-muted);font-size:.7rem" title="P<?= $p ?> = <?= $pLabels[$p] ?>">P<?= $p ?></th>
              <?php for($d=1;$d<=5;$d++): ?>
              <?php
                $skor = (int)round($p*$d*getBobot($p,$d));
                $cnt  = $heatMap[$p][$d] ?? 0;
                $bg   = heatmapColor($p,$d);
                $tks  = $tingkatShort($skor);
                $fg   = ($skor>=10 && $skor<=14) ? '#000' : '#fff';
              ?>
              <td style="background:<?= $bg ?>;color:<?= $fg ?>; <?= $cnt > 0 ? 'cursor:pointer; transition: transform 0.2s, box-shadow 0.2s;' : '' ?>"
                  title="P<?= $p ?> (<?= $pLabels[$p] ?>) x D<?= $d ?> (<?= $dLabels[$d] ?>) x Bobot <?= getBobot($p,$d) ?> = <?= $skor ?> &rarr; <?= $tks ?> (<?= $cnt ?> risiko)"
                  <?= $cnt > 0 ? "onclick=\"showHeatmapDetails($p, $d, $skor)\"" : "" ?>
                  onmouseover="this.style.transform='scale(1.08)';this.style.boxShadow='0 4px 12px rgba(0,0,0,.2)'" onmouseout="this.style.transform='scale(1)';this.style.boxShadow='none'">
                <div style="font-size:1rem;font-weight:800;line-height:1"><?= $skor ?></div>
                <div style="font-size:.5rem;font-weight:600;opacity:.85;line-height:1.1;margin-top:1px;white-space:normal;word-wrap:break-word;text-align:center"><?= $tks ?></div>
                <?php if($cnt>0): ?><span class="count-badge"><?= $cnt ?></span><?php endif; ?>
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
          <span style="font-size:.6rem;opacity:.8;display:inline-block;margin-top:4px">Klik sel untuk melihat daftar risiko di area tersebut.</span>
        </div>
      </div>
    </div>

    <!-- Tren Triwulanan -->
    <div class="card dashboard-chart-card" style="display:flex;flex-direction:column">
      <div class="card-header">
        <span class="card-title"><i class="fas fa-chart-line" style="color:var(--accent)"></i> Tren Triwulanan</span>
        <span class="dash-tab-count" style="background:var(--surface2);color:var(--text-muted)"><?= $trenTotalRisk ?> risiko</span>
      </div>
      <div class="trend-summary">
        <span class="trend-chip"><i class="fas fa-database" style="color:var(--accent)"></i> Total <strong><?= $trenTotalRisk ?></strong></span>
        <span class="trend-chip"><i class="fas fa-fire" style="color:var(--danger)"></i> Tinggi+ <strong><?= $trenTotalHigh ?></strong></span>
        <span class="trend-chip"><i class="fas fa-chart-simple" style="color:var(--success)"></i> Rata-rata triwulan terakhir <strong><?= max($trenAvg) >= 0 ? number_format(max($trenAvg),1) : 0 ?></strong></span>
      </div>
      <div class="card-body trend-chart-body" style="flex:1">
        <canvas id="trenChart"></canvas>
      </div>
      <div class="trend-quarter-note">
        <strong>Keterangan:</strong> Q berarti Triwulan &bull; Q1 Jan&ndash;Mar &bull; Q2 Apr&ndash;Jun &bull; Q3 Jul&ndash;Sep &bull; Q4 Okt&ndash;Des
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
          <a href="<?= APP_URL ?>/?page=laporan_konsolidasi&tab=profil" style="display:grid;grid-template-columns:46px minmax(0,1fr) 54px;gap:12px;align-items:center;min-height:68px;padding:11px 20px;text-decoration:none;color:inherit;border-bottom:1px solid var(--border);transition:background .15s" onmouseover="this.style.background='var(--surface2)'" onmouseout="this.style.background=''">
            <strong style="font-size:1.05rem;color:var(--text-muted)"><?= $priorityRankStart + $rank ?></strong>
            <span style="min-width:0;font-size:.82rem;font-weight:700;line-height:1.35;overflow-wrap:anywhere"><?= xss($priority['nama_risiko']) ?>
              <small style="display:block;color:<?= $priorityLevelColor ?>;font-size:.7rem;font-weight:700;margin-top:3px"><?= xss($priority['level_risiko']) ?></small>
              <?php if ($priority['penanggungjawab'] || $priority['jadwal_pelaksanaan'] || $priority['rencana_penanganan']): ?>
              <small style="display:flex;flex-wrap:wrap;gap:4px 12px;margin-top:4px;color:var(--text-muted);font-size:.68rem;font-weight:500">
                <?php if ($priority['penanggungjawab']): ?><span><i class="fas fa-user"></i> <?= xss($priority['penanggungjawab']) ?></span><?php endif; ?>
                <?php if ($priority['jadwal_pelaksanaan']): ?><span><i class="fas fa-calendar"></i> <?= xss($priority['jadwal_pelaksanaan']) ?></span><?php endif; ?>
                <?php if ($priority['rencana_penanganan']): ?><span style="min-width:0"><i class="fas fa-shield-halved"></i> <?= xss($priority['rencana_penanganan']) ?></span><?php endif; ?>
              </small>
              <?php endif; ?>
            </span>
            <strong style="font-size:1.25rem;text-align:right;color:<?= (float)$priority['skor_risiko'] >= 20 ? '#dc2626' : ((float)$priority['skor_risiko'] >= 15 ? '#ea580c' : '#ca8a04') ?>"><?= (float)$priority['skor_risiko'] ?></strong>
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


// Semua zona tampil berurutan → inisialisasi chart tren saat halaman dimuat
initRingkasanCharts();

function initRingkasanCharts() {
  if (ringkasInitDone) return;
  ringkasInitDone = true;
  if (typeof Chart === 'undefined') return;

  // Tren Triwulanan (zona Ringkasan)
  const trenEl = document.getElementById('trenChart');
  if (trenEl) {
    const labels = <?= json_encode($trenLabels) ?>;
    const jml    = <?= json_encode($trenJml) ?>;
    const tinggi = <?= json_encode($trenTinggi) ?>;
    const avg    = <?= json_encode($trenAvg) ?>;
    const gridColor = isDarkOnLoad ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.05)';
    trenChart = new Chart(trenEl.getContext('2d'), {
      data: {
        labels: labels,
        datasets: [
          { type: 'bar', label: 'Jumlah Risiko', data: jml, backgroundColor: isDarkOnLoad ? 'rgba(59,130,246,.75)' : 'rgba(59,130,246,.80)', borderRadius: 6, yAxisID: 'y', order: 2 },
          { type: 'bar', label: 'Risiko Tinggi+', data: tinggi, backgroundColor: isDarkOnLoad ? 'rgba(239,68,68,.75)' : 'rgba(239,68,68,.80)', borderRadius: 6, yAxisID: 'y', order: 3 },
          { type: 'line', label: 'Rata-rata Skor', data: avg, borderColor: '#16a34a', backgroundColor: '#16a34a', tension: .35, pointRadius: 4, pointBackgroundColor: '#16a34a', borderWidth: 2.5, yAxisID: 'y1', order: 1 }
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: {
          y: { beginAtZero: true, grid: { color: gridColor }, title: { display: true, text: 'Jumlah risiko' } },
          y1: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, title: { display: true, text: 'Rata-rata skor' } }
        },
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 12, boxHeight: 12, usePointStyle: true, pointStyle: 'circle', font: { size: 11 } } },
          tooltip: { backgroundColor: isDarkOnLoad ? '#1e293b' : '#fff', titleColor: isDarkOnLoad ? '#fff' : '#1e293b', bodyColor: isDarkOnLoad ? '#cbd5e1' : '#475569', borderColor: isDarkOnLoad ? 'rgba(255,255,255,.1)' : 'rgba(0,0,0,.1)', borderWidth: 1, padding: 10, cornerRadius: 8 }
        }
      }
    });
  }
}

// -- Heatmap Interaktif (SweetAlert2) --
window.showHeatmapDetails = function(p, d, skor) {
    Swal.fire({ title: 'Memuat data...', text: 'Silakan tunggu', allowOutsideClick: false, didOpen: () => { Swal.showLoading() } });

    fetch('<?= APP_URL ?>/api.php/heatmap?p=' + p + '&d=' + d, {
        headers: {'X-CSRF-Token': window.CSRF_TOKEN || ''},
        credentials: 'same-origin'
    })
    .then(res => res.json())
    .then(res => {
        if (res.status === 'success') {
            if (res.data.length === 0) { Swal.fire('Info', 'Tidak ada risiko di area ini.', 'info'); return; }
            const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
            let html = '<div style="max-height: 300px; overflow-y: auto; text-align: left;">';
            html += '<table class="data-table" style="width: 100%; border-collapse: collapse;">';
            html += '<thead style="position: sticky; top: 0; background: var(--surface);"><tr><th style="padding:8px;border-bottom:1px solid var(--border)">Kode</th><th style="padding:8px;border-bottom:1px solid var(--border)">Nama Risiko</th><th style="padding:8px;border-bottom:1px solid var(--border)">Level</th></tr></thead><tbody>';
            res.data.forEach(item => {
                let bgC = '#3b82f6', fgC = '#fff';
                if(item.level_risiko === 'Sangat Tinggi') bgC = '#dc2626';
                else if(item.level_risiko === 'Tinggi') bgC = '#f97316';
                else if(item.level_risiko === 'Sedang') { bgC = '#FFFF00'; fgC = '#1e293b'; }
                else if(item.level_risiko === 'Rendah') bgC = '#22c55e';
                const kodeRisiko = escapeHtml(item.kode_risiko);
                const namaRisiko = escapeHtml(item.nama_risiko);
                const levelRisiko = escapeHtml(item.level_risiko);
                const namaCell = item.risiko_id
                    ? `<a href="<?= APP_URL ?>/?page=risiko&detail=${encodeURIComponent(item.risiko_id)}" style="color:var(--accent);font-weight:700;text-decoration:none" title="Buka detail risiko ${namaRisiko}">${namaRisiko}</a>`
                    : namaRisiko;
                html += `<tr>
                    <td style="padding:8px;border-bottom:1px solid var(--border)"><code style="color:var(--accent);font-size:0.8rem">${kodeRisiko}</code></td>
                    <td style="padding:8px;border-bottom:1px solid var(--border);max-width:200px;white-space:normal">${namaCell}</td>
                    <td style="padding:8px;border-bottom:1px solid var(--border)"><span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:0.75rem;font-weight:700;background:${bgC};color:${fgC}">${levelRisiko}</span></td>
                </tr>`;
            });
            html += '</tbody></table></div>';
            Swal.fire({ title: `Daftar Risiko (P${p} x D${d} = ${skor})`, html: html, width: 600, showCloseButton: true, showConfirmButton: false });
        } else { Swal.fire('Error', res.message, 'error'); }
    })
    .catch(err => { Swal.fire('Error', 'Gagal mengambil data', 'error'); });
};

}); // end DOMContentLoaded
</script>

<style>
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
