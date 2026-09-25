<?php
/**
 * LAPORAN PDF — PROFIL RISIKO
 * Format sesuai dok: Profil Risiko Tingkat Unit UPR-T.II Kemenkes
 * Standalone — dipanggil langsung, tanpa sidebar/navbar
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
// PDF berisi data sensitif — batasi ke Admin & Risk Manager
requireRole('Admin', 'Risk Manager', 'Pimpinan');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); echo 'ID profil tidak valid'; exit; }

$scope = hasRole('Admin', 'Pimpinan') ? '' : ' AND created_by = ?';
$s = $db->prepare('SELECT * FROM profil_risiko WHERE id=?'.$scope);
if ($scope) { $uid = (int)$_SESSION['user_id']; $s->bind_param('ii', $id, $uid); } else $s->bind_param('i', $id);
$s->execute();
$p = $s->get_result()->fetch_assoc(); $s->close();
if (!$p) { http_response_code(404); echo 'Profil tidak ditemukan'; exit; }

$s2 = $db->prepare("SELECT * FROM profil_risiko_detail WHERE id_profil=? ORDER BY SUBSTRING_INDEX(kode_risiko, '.', 1) ASC, CAST(SUBSTRING_INDEX(kode_risiko, '.', -1) AS UNSIGNED) ASC, no_urut, id");
$s2->bind_param('i',$id); $s2->execute();
$details = $s2->get_result()->fetch_all(MYSQLI_ASSOC); $s2->close();

// ?? Periode laporan: tahunan, tw1-tw4, atau bulan_ini
$periode = $_GET['periode'] ?? 'tahunan';
if (!in_array($periode, ['tahunan','tw1','tw2','tw3','tw4','bulan_ini'], true)) $periode = 'tahunan';
$triwulanRomawi = ['I','II','III','IV'];
$periodeLabel = 'Laporan Tahunan';
if ($periode === 'bulan_ini') {
    $namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $periodeLabel = 'Laporan Bulan Ini (' . $namaBulan[(int)date('n')] . ' ' . date('Y') . ')';
} elseif ($periode !== 'tahunan') {
    $periodeLabel = 'Laporan Triwulan ' . $triwulanRomawi[(int)substr($periode, 2) - 1];
}

// Warna tingkat
function pdfColorTingkat(string $t): array {
  return match($t) {
    'Sangat Tinggi' => ['bg'=>'#dc2626','fg'=>'#fff'],
    'Tinggi'        => ['bg'=>'#f97316','fg'=>'#fff'],
    'Sedang'        => ['bg'=>'#FFFF00','fg'=>'#000'],
    'Rendah'        => ['bg'=>'#22c55e','fg'=>'#fff'],
    default         => ['bg'=>'#3b82f6','fg'=>'#fff'],
  };
}

// Barcode: QR-like identifier menggunakan URL
$barcodeUrl = APP_URL . '/?page=profil_risiko&id=' . $id;

// Group detail per no_urut (risiko bisa punya banyak rencana penanganan)
$grouped = [];
foreach ($details as $d) {
    $grouped[$d['no_urut']][] = $d;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Profil Risiko — <?= xss($p['tahun']) ?><?= $periode !== 'tahunan' ? ' (' . xss($periodeLabel) . ')' : '' ?></title>
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  html{font-size:10px}
  body{font-family:Arial,Helvetica,sans-serif;color:#000;background:#fff;padding:16px 20px;line-height:1.3}

  /* Print bar */
  .print-bar{display:flex;align-items:center;gap:10px;padding:8px 12px;background:#eff6ff;border:1px dashed #93c5fd;border-radius:6px;margin-bottom:14px}
  .btn-print{padding:7px 16px;background:#1e3a5f;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700}

  /* Judul */
  .judul{text-align:center;font-size:12px;font-weight:900;text-transform:uppercase;padding:8px;border:2px solid #000;margin-bottom:0;letter-spacing:.03em}
  .periode-box{text-align:center;font-size:10px;font-weight:700;padding:4px;border:1px solid #000;border-top:none;background:#f0f9ff;letter-spacing:.05em}

  /* Info header table */
  .info-table{width:100%;border-collapse:collapse;font-size:9.5px;border:1px solid #000;border-top:none;margin-bottom:0}
  .info-table td{border:1px solid #ccc;padding:4px 6px;vertical-align:top}
  .info-table .info-label{width:140px;font-weight:700;background:#f0f0f0}
  .info-table .info-sep{border-left:2px solid #000}

  /* Tabel risiko */
  .tbl-wrap{margin-top:0;border:1px solid #000;border-top:none}
  table{width:100%;border-collapse:collapse;font-size:8.5px}
  th,td{border:1px solid #aaa;padding:3px 4px;vertical-align:top}
  thead tr th{background:#d0e4f0;font-weight:700;text-align:center;font-size:8px;line-height:1.2}
  .th-group{background:#b8d4e8;font-weight:700;text-align:center;font-size:8px}
  .th-target{background:#c8e6c9;font-weight:700;text-align:center;font-size:8px}
  tbody td{font-size:8.5px}
  .td-center{text-align:center}
  .badge-tingkat{display:inline-block;padding:1px 6px;border-radius:3px;font-weight:700;font-size:8px;text-align:center}

  /* TTD area */
  .ttd-area{display:grid;grid-template-columns:1fr 1fr;border:1px solid #000;border-top:none;margin-top:0}
  .ttd-box{padding:10px 14px;min-height:110px;border-right:1px solid #000}
  .ttd-box:last-child{border-right:none}
  .ttd-label{font-size:9px;color:#555;margin-bottom:4px}
  .ttd-img{height:50px;margin:4px 0}
  .ttd-name{font-size:10px;font-weight:700;margin-top:2px}
  .ttd-nip{font-size:9px;color:#333}

  /* Barcode area inline */
  .barcode-box{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:10px;font-size:7px;color:#666;text-align:center}
  .barcode-box img{width:60px;height:60px;margin-bottom:4px}

  /* Page */
  @page{size:A4 landscape;margin:10mm}
  @media print{
    body{padding:0}
    .print-bar{display:none!important}
  }
</style>
</head>
<body>

<!-- Tombol cetak -->
<div class="print-bar">
  <button class="btn-print" onclick="window.print()">🖨 Cetak / Simpan PDF</button>
  <span style="font-size:11px;color:#1d4ed8">Tekan <strong>Ctrl+P</strong> → Simpan sebagai PDF | Orientasi: <strong>Landscape</strong></span>
</div>

<!-- Judul -->
<div class="judul">
  PROFIL RISIKO TINGKAT UNIT PEMILIK RISIKO TINGKAT II (UPR-T.II) KEMENTERIAN KESEHATAN
</div>
<div class="periode-box">
  PERIODE LAPORAN: <?= strtoupper(xss($periodeLabel)) ?> — TAHUN <?= xss($p['tahun']) ?> &nbsp;|&nbsp; DICETAK: <?= date('d-m-Y') ?>
</div>

<!-- Info Header -->
<table class="info-table">
  <tbody>
    <tr>
      <td class="info-label">Tujuan</td>
      <td><?= nl2br(xss($p['tujuan']??'-')) ?></td>
      <td class="info-label info-sep">Unit Pemilik Risiko</td>
      <td><?= xss($p['unit_pemilik_risiko']??'-') ?></td>
    </tr>
    <tr>
      <td class="info-label">Sasaran</td>
      <td><?= nl2br(xss($p['sasaran']??'-')) ?></td>
      <td class="info-label info-sep">Nama Pemilik Risiko</td>
      <td><?= xss($p['nama_pemilik_risiko']??'-') ?></td>
    </tr>
    <tr>
      <td class="info-label">Indikator Kinerja Kegiatan</td>
      <td><?= nl2br(xss($p['indikator_kinerja']??'-')) ?></td>
      <td class="info-label info-sep">Nama Tim Pengelola Risiko</td>
      <td><?= xss($p['nama_pengelola_risiko']??'-') ?></td>
    </tr>
    <tr>
      <td class="info-label">Target</td>
      <td><?= nl2br(xss($p['target']??'-')) ?></td>
      <td class="info-label info-sep">Tgl Penilaian Risiko</td>
      <td><?= tglIndo($p['tgl_penilaian']??'') ?></td>
    </tr>
    <tr>
      <td class="info-label">Program</td>
      <td><?= nl2br(xss($p['program']??'-')) ?></td>
      <td class="info-label info-sep">Periode Risiko</td>
      <td><?= xss($p['periode_risiko']??'-') ?></td>
    </tr>
    <tr>
      <td class="info-label">Kegiatan</td>
      <td><?= nl2br(xss($p['kegiatan']??'-')) ?></td>
      <td class="info-label info-sep">Tgl Update Risiko</td>
      <td><?= tglIndo($p['tgl_update']??'') ?></td>
    </tr>
    <tr>
      <td class="info-label">Periode Laporan</td>
      <td><strong><?= xss($periodeLabel) ?> — Tahun <?= xss($p['tahun']) ?></strong></td>
      <td class="info-label info-sep">Tanggal Cetak</td>
      <td><?= date('d-m-Y H:i') ?> WIB</td>
    </tr>
  </tbody>
</table>

<!-- Tabel Risiko -->
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
      <th class="th-group" style="width:18px">P</th>
      <th class="th-group" style="width:18px">D</th>
      <th class="th-group" style="width:28px">BOBOT</th>
      <th class="th-group" style="width:25px">NILAI</th>
      <th class="th-group" style="width:60px">TINGKAT RISIKO</th>
      <th class="th-target" style="width:18px">P</th>
      <th class="th-target" style="width:18px">D</th>
      <th class="th-target" style="width:28px">BOBOT</th>
      <th class="th-target" style="width:25px">NILAI</th>
      <th class="th-target" style="width:60px">TINGKAT RISIKO</th>
    </tr>
  
    <tr style="background-color:#e0e7ef; text-align:center;">
      <th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">1</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">2</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">3</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">4</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">5</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">6</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">7</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">8</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">9</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">10</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">11</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">12</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">13</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">14</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">15</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">16</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">17</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">18</th>
    </tr>
  </thead>
  <tbody>
  <?php if(empty($details)): ?>
    <tr><td colspan="17" style="text-align:center;padding:20px;color:#999">Belum ada data risiko</td></tr>
  <?php else: ?>
    <?php
    $noUrut = null;
    $rowNo  = 0;
    foreach ($details as $dr):
      $isFirst = ($dr['no_urut'] !== $noUrut);
      if ($isFirst) { $noUrut = $dr['no_urut']; $rowNo++; }
      $tkColor  = pdfColorTingkat($dr['tingkat_risiko']??'Rendah');
      $ttkColor = pdfColorTingkat($dr['target_tingkat_risiko']??'Rendah');
    ?>
    <tr>
      <?php if($isFirst): ?>
      <td class="td-center" style="font-weight:700"><?= $rowNo ?></td>
      <td><?= xss($dr['unit_kerja']??'') ?></td>
      <td><?= xss($dr['nama_risiko']) ?></td>
      <td class="td-center"><code style="color:#1d4ed8"><?= xss($dr['kode_risiko']??'') ?></code></td>
      <?php else: ?>
      <td></td><td></td><td></td><td></td>
      <?php endif; ?>
      <td class="td-center" style="font-weight:700"><?= $dr['probabilitas'] ?></td>
      <td class="td-center" style="font-weight:700"><?= $dr['dampak'] ?></td>
      <td class="td-center" style="font-weight:700;color:#1d4ed8"><?= $dr['bobot'] ?></td>
      <td class="td-center" style="font-weight:700"><?= round((float)$dr['nilai']) ?></td>
      <td class="td-center">
        <span class="badge-tingkat" style="background:<?= $tkColor['bg'] ?>;color:<?= $tkColor['fg'] ?>">
          <?= xss($dr['tingkat_risiko']??'-') ?>
        </span>
      </td>
      <td class="td-center" style="font-weight:700"><?= $dr['prioritas_risiko'] ?></td>
      <td style="font-size:8px"><?= nl2br(xss($dr['rencana_penanganan']??'')) ?></td>
      <td style="font-size:8px"><?= xss($dr['jadwal_pelaksanaan']??'') ?></td>
      <td style="font-size:8px"><?= xss($dr['penanggungjawab']??'') ?></td>
      <td class="td-center" style="font-weight:700"><?= $dr['target_p'] ?></td>
      <td class="td-center" style="font-weight:700"><?= $dr['target_d'] ?></td>
      <td class="td-center" style="font-weight:700;color:#16a34a"><?= $dr['target_bobot'] ?></td>
      <td class="td-center" style="font-weight:700"><?= round((float)$dr['target_nilai']) ?></td>
      <td class="td-center">
        <span class="badge-tingkat" style="background:<?= $ttkColor['bg'] ?>;color:<?= $ttkColor['fg'] ?>">
          <?= xss($dr['target_tingkat_risiko']??'-') ?>
        </span>
      </td>
    </tr>
    <?php endforeach; ?>
  <?php endif; ?>
  </tbody>
</table>
</div>

<?php
$nama_pemilik = !empty($p['nama_ttd_pemilik']) ? $p['nama_ttd_pemilik'] : (!empty($p['nama_pemilik_risiko']) ? $p['nama_pemilik_risiko'] : '_________________________');
$nip_pemilik = !empty($p['nip_ttd_pemilik']) ? $p['nip_ttd_pemilik'] : (!empty($p['nip_pemilik_risiko']) ? $p['nip_pemilik_risiko'] : '_________________________');
$nama_pengelola = !empty($p['nama_ttd_pengelola']) ? $p['nama_ttd_pengelola'] : (!empty($p['nama_pengelola_risiko']) ? $p['nama_pengelola_risiko'] : '_________________________');
$nip_pengelola = !empty($p['nip_ttd_pengelola']) ? $p['nip_ttd_pengelola'] : (!empty($p['nip_pengelola_risiko']) ? $p['nip_pengelola_risiko'] : '_________________________');
?>
<div class="ttd-area">
  <div class="ttd-box">
    <div class="ttd-label">Pemilik Risiko</div>
    <?php if(!empty($p['ttd_pemilik'])): ?>
    <img src="<?= $p['ttd_pemilik'] ?>" class="ttd-img" alt="TTD Pemilik">
    <?php else: ?>
    <div style="height:50px;margin:4px 0"></div>
    <?php endif; ?>
    <div class="ttd-name"><?= xss($nama_pemilik) ?></div>
    <div class="ttd-nip">NIP <?= xss($nip_pemilik) ?></div>
  </div>
  <div class="ttd-box" style="border-right:none">
    <div class="ttd-label">Pengelola Risiko</div>
    <?php if(!empty($p['ttd_pengelola'])): ?>
    <img src="<?= $p['ttd_pengelola'] ?>" class="ttd-img" alt="TTD Pengelola">
    <?php else: ?>
    <div style="height:50px;margin:4px 0"></div>
    <?php endif; ?>
    <div class="ttd-name"><?= xss($nama_pengelola) ?></div>
    <div class="ttd-nip">NIP <?= xss($nip_pengelola) ?></div>
  </div>
</div>

<script>window.onload = function(){ /* tidak auto-print */ }</script>
</body>
</html>
