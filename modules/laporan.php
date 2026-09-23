<?php
/**
 * MODUL LAPORAN — Filter, View, Export Excel & PDF
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$db = getDB();

// ── Filter ────────────────────────────────────────────────────
$fDept    = trim($_GET['departemen'] ?? '');
$fTahun   = trim($_GET['tahun'] ?? '');
$fLevel   = trim($_GET['level'] ?? '');
$fSumber  = trim($_GET['sumber'] ?? '');
$fSearch  = trim($_GET['q'] ?? '');
$export   = $_GET['export'] ?? '';

$kategoris  = $db->query('SELECT id, nama FROM kategori_risiko ORDER BY nama')->fetch_all(MYSQLI_ASSOC);
$deptList   = $db->query('SELECT DISTINCT departemen FROM risiko WHERE deleted_at IS NULL AND departemen != "" ORDER BY departemen')->fetch_all(MYSQLI_ASSOC);
$tahunRows = $db->query("SELECT DISTINCT YEAR(tanggal_identifikasi) AS tahun FROM risiko WHERE deleted_at IS NULL AND tanggal_identifikasi IS NOT NULL ORDER BY tahun DESC")->fetch_all(MYSQLI_ASSOC);
$tahunList = array_column($tahunRows, 'tahun');

// ── Build Query ───────────────────────────────────────────────
$where = ['r.deleted_at IS NULL'];
if (!hasRole('Admin', 'Pimpinan')) {
    $where[] = "r.id_user_input = " . (int)$_SESSION['user_id'];
} $params = []; $types = '';
if ($fDept)   { $where[] = 'r.departemen = ?'; $params[]=$fDept; $types.='s'; }
if ($fTahun)  { $where[] = 'YEAR(r.tanggal_identifikasi) = ?'; $params[]=$fTahun; $types.='s'; }
if ($fLevel)  {
    if ($fLevel === 'tinggi_plus') { $where[] = 'd.nilai >= 15'; }
    else { $where[] = 'd.tingkat_risiko = ?'; $params[]=$fLevel; $types.='s'; }
}
if ($fSumber) { $where[] = 'r.sumber = ?'; $params[]=$fSumber; $types.='s'; }
if ($fSearch) {
    $where[] = '(r.nama_risiko LIKE ? OR r.kode_risiko LIKE ? OR r.deskripsi LIKE ?)';
    $s = "%$fSearch%"; $params[]=$s;$params[]=$s;$params[]=$s; $types.='sss';
}
$whereStr = implode(' AND ', $where);

// Penilaian P/D, bobot, nilai, level, rencana, jadwal berasal dari Profil Risiko.
// Master Identifikasi Risiko hanya menyimpan identitas risikonya.
$sql = "SELECT r.*, r.sumber AS kategori_nama,
               d.probabilitas, d.dampak AS dampak_level, d.bobot,
               d.nilai AS skor_risiko, d.tingkat_risiko AS level_risiko,
               d.rencana_penanganan AS rencana_pengendalian,
               d.jadwal_pelaksanaan AS jadwal
        FROM risiko r
        JOIN (
            SELECT prd.*
            FROM profil_risiko_detail prd
            JOIN (
                SELECT kode_risiko, MAX(id) AS latest_id
                FROM profil_risiko_detail
                GROUP BY kode_risiko
            ) latest ON latest.latest_id=prd.id
        ) d ON d.kode_risiko=r.kode_risiko
        WHERE $whereStr
        ORDER BY SUBSTRING_INDEX(r.kode_risiko, '.', 1) ASC, LPAD(SUBSTRING_INDEX(r.kode_risiko, '.', -1), 12, '0') ASC, r.kode_risiko ASC";
$stmt = $db->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($rows as &$r) {
    if ($r['departemen'] === 'Administrasi Umum') {
        $r['departemen'] = 'Kepala Subbagian Administrasi Umum';
    }
    $sumber = trim((string)($r['kategori_nama'] ?? ''));
    if ($sumber === '0' || $sumber === '') {
        $r['kategori_nama'] = 'Internal';
    }
}
unset($r);

// ── Statistik Laporan ─────────────────────────────────────────
$statLap = ['total'=>count($rows),'tinggi'=>0,'sedang'=>0,'rendah'=>0,'ditangani'=>0];
foreach ($rows as $r) {
    if (in_array($r['level_risiko'],['Sangat Tinggi','Tinggi'])) $statLap['tinggi']++;
    elseif ($r['level_risiko']==='Sedang') $statLap['sedang']++;
    else $statLap['rendah']++;
    if ($r['status']==='Ditangani') $statLap['ditangani']++;
}

// ── Export Excel (SpreadsheetML — proper format, no library) ──
if ($export === 'excel') {
    $headers = ['No', 'Kode Risiko', 'Nama Risiko', 'Sumber Risiko', 'Peristiwa Risiko', 'Penyebab', 'Dampak', 'Pemilik Risiko', 'Pengelola Risiko', 'Probabilitas', 'Dampak Level', 'Skor Risiko', 'Level Risiko', 'Rencana Pengendalian', 'Jadwal', 'Tanggal Identifikasi', 'Status'];
    $excelRows = [];
    $excelRows[] = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17];
    foreach ($rows as $i => $r) {
        $excelRows[] = [
            $i + 1,
            $r['kode_risiko'],
            $r['nama_risiko'],
            $r['kategori_nama'], $r['deskripsi'], $r['penyebab'], $r['dampak'],
            $r['pemilik_risiko'], $r['departemen'],
            $r['probabilitas'],
            $r['dampak_level'],
            $r['skor_risiko'],
            $r['level_risiko'],
            $r['rencana_pengendalian'] ?? '',
            $r['jadwal'] ? tglIndo($r['jadwal']) : '',
            tglIndo($r['tanggal_identifikasi']),
            $r['status'],
        ];
    }
    exportExcel('laporan_risiko_' . date('Ymd_His'), $headers, $excelRows);
}

// ── Export PDF (Print View) ───────────────────────────────────
if ($export === 'pdf') {
    // Statistik untuk summary
    $totalSkor = 0;
    foreach ($rows as $r) $totalSkor += $r['skor_risiko'];
    $avgSkor   = count($rows) > 0 ? round($totalSkor / count($rows), 1) : 0;
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Laporan Risiko — <?= APP_NAME ?></title>
<style>
  /* ── Reset ── */
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  html { font-size: 12px; }
  body {
    font-family: Arial, Helvetica, sans-serif;
    color: #1e293b;
    background: #fff;
    padding: 28px 32px;
    line-height: 1.5;
  }

  /* ── Kop Laporan ── */
  .kop {
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 3px solid #1e3a5f;
    padding-bottom: 14px;
    margin-bottom: 18px;
  }
  .kop-left { display: flex; align-items: center; gap: 14px; }
  .kop-logo {
    width: 52px; height: 52px; border-radius: 12px;
    background: linear-gradient(135deg, #1e3a5f, #3b82f6);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 22px; font-weight: 900;
    flex-shrink: 0;
  }
  .kop-title { font-size: 16px; font-weight: 700; color: #1e3a5f; }
  .kop-sub   { font-size: 10px; color: #64748b; margin-top: 2px; }
  .kop-meta  { text-align: right; font-size: 10px; color: #64748b; line-height: 1.7; }
  .kop-meta strong { color: #1e3a5f; }

  /* ── Filter Info ── */
  .filter-info {
    background: #f0f4f8;
    border-left: 4px solid #3b82f6;
    border-radius: 0 6px 6px 0;
    padding: 8px 14px;
    margin-bottom: 16px;
    font-size: 10px;
    color: #475569;
  }
  .filter-info span { margin-right: 14px; }
  .filter-info strong { color: #1e3a5f; }

  /* ── Stat Cards ── */
  .stat-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-bottom: 18px;
  }
  .stat-box {
    border-radius: 8px;
    padding: 10px 14px;
    border: 1px solid #e2e8f0;
  }
  .stat-box .val { font-size: 20px; font-weight: 800; }
  .stat-box .lbl { font-size: 9px; color: #64748b; margin-top: 2px; text-transform: uppercase; letter-spacing: .04em; }

  .stat-total  { background: #eff6ff; border-color: #93c5fd; }
  .stat-total .val  { color: #1d4ed8; }
  .stat-tinggi { background: #fef2f2; border-color: #fca5a5; }
  .stat-tinggi .val { color: #dc2626; }
  .stat-sedang { background: #fefce8; border-color: #fde047; }
  .stat-sedang .val { color: #ca8a04; }
  .stat-handled{ background: #f0fdf4; border-color: #86efac; }
  .stat-handled .val{ color: #16a34a; }

  /* ── Tabel ── */
  .tbl-wrap { overflow: hidden; border-radius: 8px; border: 1px solid #e2e8f0; }
  table { width: 100%; border-collapse: collapse; font-size: 10.5px; }
  thead tr { background: #1e3a5f; }
  thead th {
    padding: 9px 10px;
    text-align: left;
    color: #fff;
    font-weight: 700;
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: .06em;
    white-space: normal;
    word-break: break-word;
  }
  tbody td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
  tbody tr:last-child td { border-bottom: none; }
  tbody tr:nth-child(even) { background: #f8fafc; }
  tbody tr:hover { background: #eff6ff; }

  /* ── Level Badge ── */
  .badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 9px;
    font-weight: 700;
    white-space: nowrap;
  }
  .lv-sangat-tinggi { background: #fee2e2; color: #991b1b; }
  .lv-tinggi        { background: #ffedd5; color: #c2410c; }
  .lv-sedang        { background: #FFFF00; color: #000000; }
  .lv-rendah        { background: #dcfce7; color: #166534; }
  .lv-sangat-rendah { background: #dbeafe; color: #1e40af; }

  .st-teridentifikasi { background: #dbeafe; color: #1d4ed8; }
  .st-ditangani       { background: #fef3c7; color: #d97706; }
  .st-dimonitor       { background: #ede9fe; color: #7c3aed; }
  .st-ditutup         { background: #dcfce7; color: #16a34a; }

  .skor-high { color: #dc2626; font-weight: 800; font-size: 12px; }
  .skor-mid  { color: #d97706; font-weight: 700; }
  .skor-low  { color: #16a34a; font-weight: 600; }

  /* ── Kode ── */
  code { font-family: monospace; font-size: 10px; color: #3b82f6; background: #eff6ff; padding: 1px 5px; border-radius: 4px; }

  /* ── Footer ── */
  .footer {
    margin-top: 20px;
    padding-top: 10px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    font-size: 9px;
    color: #94a3b8;
  }

  /* ── Tombol cetak (tidak ikut tercetak) ── */
  .print-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px;
    background: #1e3a5f;
    color: #fff; border: none; border-radius: 8px;
    font-size: 13px; font-weight: 600; cursor: pointer;
    transition: background .2s;
  }
  .print-btn:hover { background: #2d5a8e; }
  .print-bar {
    display: flex; align-items: center; gap: 10px;
    margin-bottom: 18px;
    padding: 10px 14px;
    background: #eff6ff;
    border-radius: 8px;
    border: 1px dashed #93c5fd;
  }
  .print-bar p { font-size: 11px; color: #1d4ed8; }

  /* ── Print rules ── */
  @media print {
    @page { size: landscape; margin: 10mm; }
    body { padding: 0; }
    .print-bar { display: none !important; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    .kop { page-break-after: avoid; }
  }
</style>
</head>
<body>

<!-- Tombol Cetak — hilang saat print -->
<div class="print-bar">
  <button class="print-btn" onclick="window.print()">&#128196; Cetak / PDF</button>
  <p>Klik tombol cetak atau tekan <strong>Ctrl+P</strong> — Sidebar &amp; navbar tidak akan muncul di cetakan.</p>
</div>

<!-- Kop Laporan -->
<div class="kop">
  <div class="kop-left">
    <div class="kop-logo">R</div>
    <div>
      <div class="kop-title"><?= APP_NAME ?></div>
      <div class="kop-sub">Laporan Manajemen Risiko Organisasi</div>
    </div>
  </div>
  <div class="kop-meta">
    <div><strong>Tanggal Cetak:</strong> <?= date('d F Y, H:i') ?> WIB</div>
    <div><strong>Dicetak oleh:</strong> <?= xss($_SESSION['user_nama'] ?? '-') ?></div>
    <div><strong>Total Risiko:</strong> <?= count($rows) ?> data</div>
  </div>
</div>

<!-- Info Filter Aktif -->
<div class="filter-info">
  <strong>Filter aktif:</strong>
  <span>Pengelola Risiko: <strong><?= $fDept ?: 'Semua' ?></strong></span>
  <span>Level: <strong><?= $fLevel ?: 'Semua' ?></strong></span>
  <span>Status: <strong><?= $fStatus ?: 'Semua' ?></strong></span>
  <?php if ($fFrom || $fTo): ?>
  <span>Periode: <strong><?= ($fFrom ? tglIndo($fFrom) : '—') ?> s/d <?= ($fTo ? tglIndo($fTo) : '—') ?></strong></span>
  <?php endif; ?>
</div>

<!-- Ringkasan Statistik -->
<div class="stat-row">
  <div class="stat-box stat-total">
    <div class="val"><?= $statLap['total'] ?></div>
    <div class="lbl">Total Risiko</div>
  </div>
  <div class="stat-box stat-tinggi">
    <div class="val"><?= $statLap['tinggi'] ?></div>
    <div class="lbl">Level Tinggi+</div>
  </div>
  <div class="stat-box stat-sedang">
    <div class="val"><?= $statLap['sedang'] ?></div>
    <div class="lbl">Level Sedang</div>
  </div>
  <div class="stat-box stat-handled">
    <div class="val"><?= $statLap['ditangani'] ?></div>
    <div class="lbl">Sedang Ditangani</div>
  </div>
</div>

<!-- Tabel Data -->
<div class="tbl-wrap">
<table>
  <thead>
    <tr>
      <th style="width:24px;text-align:center">No</th>
      <th style="width:50px">Kode</th>
      <th style="min-width:140px">Nama Risiko</th>
      <th style="min-width:80px">Sumber Risiko</th>
      <th style="min-width:100px">Penyebab</th>
      <th style="min-width:100px">Dampak</th>
      <th style="min-width:70px">Pemilik</th>
      <th style="min-width:70px">Pengelola</th>
      <th style="width:20px;text-align:center">P</th>
      <th style="width:20px;text-align:center">D</th>
      <th style="width:28px;text-align:center">Nilai</th>
      <th style="width:60px">Level</th>
      <th style="width:55px">Status</th>
    </tr>
  
    <tr style="background-color:#1e3a5f; text-align:center;">
      <th style="padding:4px; border:1px solid rgba(255,255,255,0.2); font-size:inherit; font-weight:normal; font-style:normal; text-align:center;">1</th><th style="padding:4px; border:1px solid rgba(255,255,255,0.2); font-size:inherit; font-weight:normal; font-style:normal; text-align:center;">2</th><th style="padding:4px; border:1px solid rgba(255,255,255,0.2); font-size:inherit; font-weight:normal; font-style:normal; text-align:center;">3</th><th style="padding:4px; border:1px solid rgba(255,255,255,0.2); font-size:inherit; font-weight:normal; font-style:normal; text-align:center;">4</th><th style="padding:4px; border:1px solid rgba(255,255,255,0.2); font-size:inherit; font-weight:normal; font-style:normal; text-align:center;">5</th><th style="padding:4px; border:1px solid rgba(255,255,255,0.2); font-size:inherit; font-weight:normal; font-style:normal; text-align:center;">6</th><th style="padding:4px; border:1px solid rgba(255,255,255,0.2); font-size:inherit; font-weight:normal; font-style:normal; text-align:center;">7</th><th style="padding:4px; border:1px solid rgba(255,255,255,0.2); font-size:inherit; font-weight:normal; font-style:normal; text-align:center;">8</th><th style="padding:4px; border:1px solid rgba(255,255,255,0.2); font-size:inherit; font-weight:normal; font-style:normal; text-align:center;">9</th><th style="padding:4px; border:1px solid rgba(255,255,255,0.2); font-size:inherit; font-weight:normal; font-style:normal; text-align:center;">10</th><th style="padding:4px; border:1px solid rgba(255,255,255,0.2); font-size:inherit; font-weight:normal; font-style:normal; text-align:center;">11</th><th style="padding:4px; border:1px solid rgba(255,255,255,0.2); font-size:inherit; font-weight:normal; font-style:normal; text-align:center;">12</th><th style="padding:4px; border:1px solid rgba(255,255,255,0.2); font-size:inherit; font-weight:normal; font-style:normal; text-align:center;">13</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($rows)): ?>
    <tr><td colspan="13" style="text-align:center;padding:30px;color:#94a3b8">Tidak ada data risiko</td></tr>
  <?php else: ?>
    <?php foreach ($rows as $i => $r):
      $lvSlug  = strtolower(str_replace(' ', '-', $r['level_risiko']));
      $stSlug  = strtolower(str_replace(' ', '-', $r['status']));
      $skorCls = $r['skor_risiko'] >= 15 ? 'skor-high' : ($r['skor_risiko'] >= 10 ? 'skor-mid' : 'skor-low');
    ?>
    <tr>
      <td style="color:#94a3b8;text-align:center"><?= $i + 1 ?></td>
      <td><code><?= xss($r['kode_risiko']) ?></code></td>
      <td><strong style="font-size:10px"><?= xss($r['nama_risiko']) ?></strong></td>
      <td style="font-size:9px"><?= xss($r['kategori_nama']) ?></td>
      <td style="font-size:9px;white-space:normal;word-break:break-word"><?= formatUraianList($r['penyebab'] ?: '-') ?></td>
      <td style="font-size:9px;white-space:normal;word-break:break-word"><?= formatUraianList($r['dampak'] ?: '-') ?></td>
      <td style="font-size:9px"><?= xss($r['pemilik_risiko'] ?: '-') ?></td>
      <td style="font-size:9px;color:#475569"><?= xss($r['departemen'] ?: '-') ?></td>
      <td style="text-align:center;font-weight:700"><?= $r['probabilitas'] ?></td>
      <td style="text-align:center;font-weight:700"><?= $r['dampak_level'] ?></td>
      <td style="text-align:center"><span class="<?= $skorCls ?>"><?= round((float)$r['skor_risiko']) ?></span></td>
      <td><span class="badge lv-<?= $lvSlug ?>"><?= xss($r['level_risiko']) ?></span></td>
      <td><span class="badge st-<?= $stSlug ?>"><?= xss($r['status']) ?></span></td>
    </tr>
    <?php endforeach; ?>
  <?php endif; ?>
  </tbody>
</table>
</div>

<!-- Footer -->
<div class="footer">
  <span><?= APP_NAME ?> — Laporan digenerate otomatis pada <?= date('d/m/Y H:i:s') ?></span>
  <span>Rata-rata Skor Risiko: <strong style="color:#1e3a5f"><?= $avgSkor ?></strong> | Total: <?= count($rows) ?> risiko</span>
</div>

<script>
// Auto print jika dibuka dari link export
if (window.opener || document.referrer.includes('laporan')) {
  // tidak auto print, biarkan user klik tombol
}
</script>
</body>
</html>
<?php exit;
}
?>

<?php
$qStr = http_build_query(['page'=>'laporan','q'=>$fSearch,'departemen'=>$fDept,'tahun'=>$fTahun,'level'=>$fLevel,'sumber'=>$fSumber]);
?>
<div class="risiko-hero profil-risiko-hero" style="background:linear-gradient(115deg,#1e3a8a 0%,#1e40af 55%,#1d4ed8 100%); align-items: flex-start !important;">
  <div class="risiko-hero-copy">
    <div class="risiko-eyebrow"><i class="fas fa-file-invoice"></i> Pelaporan Risiko</div>
    <h1 class="page-title">Laporan Risiko</h1>
    <p class="page-sub" style="color:rgba(255,255,255,.8)">Penilaian P/D, skor, dan level diambil dari Profil Risiko terbaru</p>
  </div>
  <div class="profil-hero-tools risiko-hero-tools-align">
    <div class="risiko-export-actions" style="margin-top:0">
      <a href="<?= APP_URL ?>/?<?= $qStr ?>&export=excel" class="btn btn-hero-ghost"><i class="fas fa-file-excel"></i> Excel</a>
      <a href="<?= APP_URL ?>/?<?= $qStr ?>&export=pdf" target="_blank" class="btn btn-hero-ghost">&#128196; Cetak / PDF</a>
    </div>
  </div>

  <!-- Stat cards (glassmorphism inside hero) -->
  <div class="stats-grid cols-4" style="width:100%;margin-top:20px;margin-bottom:0">
    <div class="stat-card stat-card-glass" style="--ga:#60a5fa;--ga-tint:rgba(96,165,250,.3);--ga-line:rgba(96,165,250,.45);--ga-glow:rgba(96,165,250,.3)">
      <div class="stat-icon"><i class="fas fa-list"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $statLap['total'] ?></div>
        <div class="stat-label">Total Ditemukan</div>
      </div>
    </div>
    <div class="stat-card stat-card-glass" style="--ga:#f87171;--ga-tint:rgba(248,113,113,.28);--ga-line:rgba(248,113,113,.5);--ga-glow:rgba(248,113,113,.32)">
      <div class="stat-icon"><i class="fas fa-fire"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $statLap['tinggi'] ?></div>
        <div class="stat-label">Level Tinggi+</div>
      </div>
    </div>
    <div class="stat-card stat-card-glass" style="--ga:#fbbf24;--ga-tint:rgba(251,191,36,.3);--ga-line:rgba(251,191,36,.5);--ga-glow:rgba(251,191,36,.3)">
      <div class="stat-icon"><i class="fas fa-minus-circle"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $statLap['sedang'] ?></div>
        <div class="stat-label">Level Sedang</div>
      </div>
    </div>
    <div class="stat-card stat-card-glass" style="--ga:#4ade80;--ga-tint:rgba(74,222,128,.28);--ga-line:rgba(74,222,128,.45);--ga-glow:rgba(74,222,128,.28)">
      <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $statLap['ditangani'] ?></div>
        <div class="stat-label">Sedang Ditangani</div>
      </div>
    </div>
  </div>
</div>

<!-- Tabel Laporan -->
<div class="card">
  <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
    <span class="card-title">Hasil Laporan <span style="color:var(--text-muted);font-weight:400">(<?= count($rows) ?> risiko)</span></span>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;flex:1;justify-content:flex-end">
      <div class="search-bar" style="max-width:250px;width:100%">
        <i class="fas fa-search"></i>
        <input type="text" class="form-control" id="searchLaporan" placeholder="Cari nama/kode..." onkeyup="filterTableLaporan()" style="height:38px">
      </div>
      <div class="datatable-dropdown" style="margin:0; display:flex; align-items:center;">
        <select class="datatable-selector" id="limitLaporan" onchange="filterTableLaporan()">
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
    <table class="data-table no-datatable" id="tableLaporan" style="font-size:.82rem">
      <thead>
        <tr>
          <th style="width:40px;text-align:center">No</th>
          <th style="width:70px">Kode</th>
          <th style="min-width:200px">Nama Risiko</th>
          <th>Sumber Risiko</th>
          <th style="min-width:150px">Penyebab</th>
          <th style="min-width:150px">Dampak</th>
          <th>Pemilik</th>
          <th>Pengelola</th>
          <th style="width:40px;text-align:center" title="Probabilitas">P</th>
          <th style="width:40px;text-align:center" title="Dampak">D</th>
          <th style="width:50px;text-align:center">Nilai</th>
          <th style="width:100px">Level</th>
          <th style="width:90px">Status</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="13"><div class="empty-state"><i class="fas fa-search"></i><h3>Tidak ada data</h3><p>Coba ubah filter pencarian</p></div></td></tr>
      <?php else: ?>
        <?php foreach($rows as $i=>$r): ?>
        <tr>
          <td style="color:var(--text-muted);text-align:center"><?= $i+1 ?></td>
          <td><code style="font-size:.76rem;color:var(--accent);white-space:nowrap"><?= xss($r['kode_risiko']) ?></code></td>
          <td style="font-weight:600;white-space:normal;word-break:break-word"><?= xss($r['nama_risiko']) ?></td>
          <td><span class="badge badge-secondary" style="white-space:normal"><?= xss($r['kategori_nama']) ?></span></td>
          <td style="font-size:.76rem;white-space:normal;word-break:break-word;max-width:180px"><?= formatUraianList($r['penyebab'] ?: '-') ?></td>
          <td style="font-size:.76rem;white-space:normal;word-break:break-word;max-width:180px"><?= formatUraianList($r['dampak'] ?: '-') ?></td>
          <td style="font-size:.76rem"><?= xss($r['pemilik_risiko']?:'-') ?></td>
          <td style="font-size:.76rem"><?= xss($r['departemen']?:'-') ?></td>
          <td style="text-align:center;font-weight:700"><?= $r['probabilitas'] ?></td>
          <td style="text-align:center;font-weight:700"><?= $r['dampak_level'] ?></td>
          <td style="text-align:center;font-weight:800;color:var(--accent)"><?= round((float)$r['skor_risiko']) ?></td>
          <td style="white-space:nowrap"><?= badgeLevel($r['level_risiko']) ?></td>
          <td style="white-space:nowrap"><?= badgeStatus($r['status']) ?></td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="monev-pagination" id="laporanPagination" style="display:flex;">
    <span class="info" id="laporanPageInfo">Memuat...</span>
    <div class="pages" id="laporanPages"></div>
  </div>
</div>

<script>
const laporanState = { page: 1 };
let emptyRow = null;

function filterTableLaporan() {
  const query = (document.getElementById('searchLaporan')?.value || '').toLowerCase();
  const limit = parseInt(document.getElementById('limitLaporan')?.value || 10, 10);
  const allRows = [...document.querySelectorAll('#tableLaporan tbody tr:not(.empty-state-row)')];
  
  const visible = allRows.filter(row => {
    if(row.id === 'emptySearchLaporan') return false;
    const text = row.textContent.toLowerCase();
    if (query && !text.includes(query)) return false;
    return true;
  });

  const total = visible.length;
  const pages = Math.max(1, Math.ceil(total / limit));
  if (laporanState.page > pages) laporanState.page = pages;
  const start = (laporanState.page - 1) * limit;

  allRows.forEach(row => { row.style.display = 'none'; });
  visible.slice(start, start + limit).forEach(row => { row.style.display = ''; });

  const infoEl = document.getElementById('laporanPageInfo');
  if(infoEl) {
    infoEl.textContent = total === 0 ? 'Tidak ada data' : 'Menampilkan ' + (start + 1) + '–' + Math.min(start + limit, total) + ' dari ' + total + ' data';
  }

  const pagesEl = document.getElementById('laporanPages');
  if(pagesEl) {
    pagesEl.innerHTML = '';
    const mkBtn = (html, page, disabled, active) => {
      const b = document.createElement('button');
      b.type = 'button'; b.innerHTML = html; b.disabled = disabled;
      if (active) b.classList.add('active');
      if (!disabled) b.onclick = () => { laporanState.page = page; filterTableLaporan(); };
      pagesEl.appendChild(b);
    };
    mkBtn('<i class="fas fa-angle-double-left"></i>', 1, laporanState.page <= 1, false);
    mkBtn('<i class="fas fa-angle-left"></i>', laporanState.page - 1, laporanState.page <= 1, false);
    const winStart = Math.max(1, Math.min(laporanState.page - 2, pages - 4));
    const winEnd = Math.min(pages, winStart + 4);
    for (let p = winStart; p <= winEnd; p++) mkBtn(String(p), p, false, p === laporanState.page);
    mkBtn('<i class="fas fa-angle-right"></i>', laporanState.page + 1, laporanState.page >= pages, false);
    mkBtn('<i class="fas fa-angle-double-right"></i>', pages, laporanState.page >= pages, false);
  }
  
  if (total === 0 && allRows.length > 0) {
    if (!emptyRow) {
      emptyRow = document.createElement('tr');
      emptyRow.id = 'emptySearchLaporan';
      emptyRow.className = 'empty-state-row';
      document.querySelector('#tableLaporan tbody').appendChild(emptyRow);
    }
    emptyRow.style.display = '';
    emptyRow.innerHTML = `<td colspan="20"><div class="empty-state"><i class="fas fa-search"></i><p>Pencarian "<b>${query}</b>" tidak ditemukan.</p></div></td>`;
  } else {
    if(emptyRow) emptyRow.style.display = 'none';
  }
}
// Init limit
setTimeout(() => filterTableLaporan(), 100);
</script>
