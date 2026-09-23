<?php
/**
 * LAPORAN PDF — KERTAS KERJA PENILAIAN RISIKO (KKPR)
 * Format landscape A3/A4, persis sesuai dok UPR-T.II Kemenkes
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
// PDF berisi data sensitif — batasi ke Admin & Risk Manager
requireRole('Admin', 'Risk Manager', 'Pimpinan');
$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); echo 'ID tidak valid'; exit; }
$scope = canAccessAllRecords() ? '' : ' AND created_by = ?';
$s = $db->prepare('SELECT * FROM kkpr_header WHERE id=?' . $scope);
if ($scope) { $uid = (int)$_SESSION['user_id']; $s->bind_param('ii', $id, $uid); }
else $s->bind_param('i', $id);
$s->execute();
$h = $s->get_result()->fetch_assoc(); $s->close();
if (!$h) { http_response_code(404); echo 'KKPR tidak ditemukan'; exit; }
$s2 = $db->prepare('SELECT * FROM kkpr_risiko WHERE id_kkpr=? ORDER BY no_urut, id');
$s2->bind_param('i',$id); $s2->execute();
$rows = $s2->get_result()->fetch_all(MYSQLI_ASSOC); $s2->close();

// ── Periode laporan: tahunan, tw1-tw4, atau bulan_ini ──────
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

function pdfKkprColor(string $t): array {
    return match($t) {
        'Sangat Tinggi' => ['#dc2626','#fff'],
        'Tinggi'        => ['#f97316','#fff'],
        'Sedang'        => ['#FFFF00','#000'],
        'Rendah'        => ['#22c55e','#fff'],
        default         => ['#3b82f6','#fff'],
    };
}
$barcodeUrl = APP_URL . '/?page=kkpr&id=' . $id;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>KKPR — <?= xss($h['tahun']) ?><?= $periode !== 'tahunan' ? ' (' . xss($periodeLabel) . ')' : '' ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:8.5px}
body{font-family:Arial,Helvetica,sans-serif;color:#000;background:#fff;padding:12px 14px;line-height:1.25}
.print-bar{display:flex;gap:10px;align-items:center;padding:7px 12px;background:#eff6ff;border:1px dashed #93c5fd;border-radius:6px;margin-bottom:10px;font-size:11px}
.btn-print{padding:6px 14px;background:#1e3a5f;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:11px;font-weight:700}
.judul{text-align:center;font-size:10px;font-weight:900;text-transform:uppercase;padding:6px;border:2px solid #000;letter-spacing:.03em}
.periode-box{text-align:center;font-size:9px;font-weight:700;padding:4px;border:1px solid #000;border-top:none;background:#f0f9ff;letter-spacing:.05em}
.info-table{width:100%;border-collapse:collapse;font-size:8px;border:1px solid #000;border-top:none;margin-bottom:0}
.info-table td{border:1px solid #ccc;padding:3px 5px;vertical-align:top}
.info-table .info-lbl{width:130px;font-weight:700;background:#f0f0f0}
.info-table .info-sep{border-left:2px solid #000}
.tbl-wrap{border:1px solid #000;border-top:none}
table{width:100%;border-collapse:collapse;font-size:7.5px}
th,td{border:1px solid #999;padding:2px 3px;vertical-align:top}
.th-main{background:#1e3a5f;color:#fff;font-weight:700;text-align:center;font-size:7px;white-space:nowrap}
.th-identifikasi{background:#c8d8e8;font-weight:700;text-align:center;font-size:7px}
.th-analisis{background:#fde68a;font-weight:700;text-align:center;font-size:7px}
.th-evaluasi{background:#fca5a5;font-weight:700;text-align:center;font-size:7px}
.th-rpti{background:#a7f3d0;font-weight:700;text-align:center;font-size:7px}
.th-target{background:#c4b5fd;font-weight:700;text-align:center;font-size:7px}
.badge-t{display:inline-block;padding:1px 4px;border-radius:2px;font-weight:700;font-size:7px;text-align:center;white-space:nowrap}
.ttd-area{display:grid;grid-template-columns:1fr 1fr;border:1px solid #000;border-top:none}
.ttd-box{padding:8px 12px;min-height:90px;border-right:1px solid #000}
.ttd-box:last-child{border-right:none}
.ttd-lbl{font-size:8px;color:#444;margin-bottom:3px}
.ttd-img{height:42px;margin:3px 0}
.ttd-nm{font-size:9px;font-weight:700;margin-top:2px}
.ttd-nip{font-size:8px;color:#333}
.barcode-box{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:8px;font-size:7px;color:#666;text-align:center}
.barcode-box img{width:55px;height:55px;margin-bottom:3px}
@page{size:A3 landscape;margin:8mm}
@media print{body{padding:0}.print-bar{display:none!important}}
</style>
</head>
<body>
<div class="print-bar">
  <button class="btn-print" onclick="window.print()">🖨 Cetak / PDF</button>
  <span style="color:#1d4ed8"><strong>Ctrl+P</strong> → Ukuran kertas: <strong>A3 Landscape</strong> (atau A4 Landscape untuk ukuran kecil)</span>
</div>

<div class="judul">KERTAS KERJA PENILAIAN RISIKO TINGKAT UNIT PEMILIK RISIKO TINGKAT II (UPR-T.II) KEMENTERIAN KESEHATAN</div>
<div class="periode-box">
  PERIODE LAPORAN: <?= strtoupper(xss($periodeLabel)) ?> — TAHUN <?= xss($h['tahun']) ?> &nbsp;|&nbsp; DICETAK: <?= date('d-m-Y') ?>
</div>

<table class="info-table">
  <tbody>
    <tr>
      <td class="info-lbl">Tujuan</td>
      <td><?= xss($h['tujuan']??'-') ?></td>
      <td class="info-lbl info-sep">Unit Pemilik Risiko</td>
      <td><?= xss($h['unit_pemilik_risiko']??'-') ?></td>
    </tr>
    <tr>
      <td class="info-lbl">Sasaran</td>
      <td><?= nl2br(xss($h['sasaran']??'-')) ?></td>
      <td class="info-lbl info-sep">Nama Pemilik Risiko</td>
      <td><?= xss($h['nama_pemilik_risiko']??'-') ?></td>
    </tr>
    <tr>
      <td class="info-lbl">Indikator Kinerja Kegiatan</td>
      <td><?= nl2br(xss($h['indikator_kinerja']??'-')) ?></td>
      <td class="info-lbl info-sep">Nama Pengelola Risiko</td>
      <td><?= xss($h['nama_pengelola_risiko']??'-') ?></td>
    </tr>
    <tr>
      <td class="info-lbl">Target</td>
      <td><?= nl2br(xss($h['target']??'-')) ?></td>
      <td class="info-lbl info-sep">Tgl Penilaian Risiko</td>
      <td><?= tglIndo($h['tgl_penilaian']??'') ?></td>
    </tr>
    <tr>
      <td class="info-lbl">Program</td>
      <td><?= xss($h['program']??'-') ?></td>
      <td class="info-lbl info-sep">Periode Risiko</td>
      <td><?= xss($h['periode_risiko']??'-') ?></td>
    </tr>
    <tr>
      <td class="info-lbl">Kegiatan</td>
      <td><?= xss($h['kegiatan']??'-') ?></td>
      <td class="info-lbl info-sep">Tgl Update Risiko</td>
      <td><?= tglIndo($h['tgl_update']??'') ?></td>
    </tr>
    <tr>
      <td class="info-lbl">Periode Laporan</td>
      <td><strong><?= xss($periodeLabel) ?> — Tahun <?= xss($h['tahun']) ?></strong></td>
      <td class="info-lbl info-sep">Tanggal Cetak</td>
      <td><?= date('d-m-Y H:i') ?> WIB</td>
    </tr>
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
      <th class="th-target" style="width:14px">P</th>
      <th class="th-target" style="width:14px">D</th>
      <th class="th-target" style="width:22px">BOBOT</th>
      <th class="th-target" style="width:22px">NILAI</th>
      <th class="th-target" style="width:50px">TINGKAT RISIKO</th>
    </tr>
  
    <tr style="background-color:#e0e7ef; text-align:center;">
      <th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">1</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">2</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">3</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">4</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">5</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">6</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">7</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">8</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">9</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">10</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">11</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">12</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">13</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">14</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">15</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">16</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">17</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">18</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">19</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">20</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">21</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">22</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">23</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">24</th><th style="background-color:#e0e7ef !important; color:#000000 !important; padding:4px; border:1px solid #cbd5e1; font-size:inherit; font-weight:normal; font-style:normal;">25</th>
    </tr>
  </thead>
  <tbody>
  <?php if(empty($rows)): ?>
  <tr><td colspan="25" style="text-align:center;padding:20px;color:#999">Belum ada data risiko</td></tr>
  <?php else: ?>
  <?php
  $noUrut = null; $rowNo = 0;
  foreach ($rows as $r):
    $isFirst = ($r['no_urut'] !== $noUrut);
    if($isFirst){$noUrut=$r['no_urut'];$rowNo++;}
    [$bgT,$clT]   = pdfKkprColor($r['tingkat_risiko']??'Rendah');
    [$bgTT,$clTT] = pdfKkprColor($r['target_tingkat']??'Rendah');
    $bgEv = $r['evaluasi_warna'] ?: $bgT;
  ?>
  <tr>
    <?php if($isFirst): ?>
    <td style="text-align:center;font-weight:700"><?= $rowNo ?></td>
    <td><?= nl2br(xss($r['nama_risiko'])) ?></td>
    <td style="text-align:center"><span style="color:#1d4ed8;font-family:monospace"><?= xss($r['kode_risiko']??'') ?></span></td>
    <td><?= formatUraianList($r['sebab'] ?? '') ?></td>
    <td style="text-align:center"><span style="font-size:7px"><?= xss(normalizeSumberRisiko($r['sumber'] ?? '')) ?></span></td>
    <td style="text-align:center;font-weight:700"><?= xss($r['c_uc']??'') ?></td>
    <td><?= formatUraianList($r['dampak_uraian'] ?? '') ?></td>
    <?php else: ?>
    <td></td><td></td><td></td><td></td><td></td><td></td><td></td>
    <?php endif; ?>
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
  <?php endforeach; ?>
  <?php endif; ?>
  </tbody>
</table>
</div>

<!-- TTD -->
<div class="ttd-area">
  <?php foreach([['Pemilik Risiko',$h['ttd_pemilik'],$h['nama_ttd_pemilik'],$h['nip_ttd_pemilik']],['Pengelola Risiko',$h['ttd_pengelola'],$h['nama_ttd_pengelola'],$h['nip_ttd_pengelola']]] as [$lbl,$ttd,$nama,$nip]): ?>
  <div class="ttd-box">
    <div class="ttd-lbl"><?= $lbl ?></div>
    <?php if($ttd): ?><img src="<?= $ttd ?>" class="ttd-img" alt="TTD"><?php else: ?><div style="height:42px;border-bottom:1px solid #999;width:160px;margin:3px 0"></div><?php endif; ?>
    <div class="ttd-nm"><?= xss($nama??'') ?></div>
    <div class="ttd-nip">NIP <?= xss($nip??'') ?></div>
  </div>
  <?php endforeach; ?>
</div>
</body>
</html>
