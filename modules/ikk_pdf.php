<?php
/**
 * LAPORAN PDF — KERTAS KERJA VERIFIKASI CAPAIAN TARGET TAHUNAN RENSTRA & PERMASALAHAN (IKK)
 * Format sesuai V6 KK-identifikasi Risiko IKK Kemenkes
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
requireRole('Admin', 'Risk Manager', 'Pimpinan');
$db = getDB();
$tahun = trim($_GET['tahun'] ?? date('Y'));
if (!$tahun) { http_response_code(400); echo 'Tahun tidak valid'; exit; }

$s = $db->prepare('SELECT * FROM ikk WHERE tahun=? ORDER BY no_urut, id');
$s->bind_param('s', $tahun); $s->execute();
$rows = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>IKK — Tahun <?= xss($tahun) ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:10px}
body{font-family:Arial,Helvetica,sans-serif;color:#000;background:#fff;padding:18px 22px;line-height:1.4}
.print-bar{display:flex;gap:10px;align-items:center;padding:8px 14px;background:#eff6ff;border:1px dashed #93c5fd;border-radius:6px;margin-bottom:14px;font-size:12px}
.btn-print{padding:7px 16px;background:#1e3a5f;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:12px;font-weight:700}
.judul{text-align:center;font-size:13px;font-weight:900;text-transform:uppercase;padding:10px;border:2px solid #000;letter-spacing:.02em;margin-bottom:14px}
.sub-judul{text-align:center;font-size:11px;font-weight:700;margin-bottom:14px}
table{width:100%;border-collapse:collapse;font-size:9px}
th,td{border:1px solid #000;padding:5px 7px;vertical-align:top}
th{background:#1e3a5f;color:#fff;font-weight:700;text-align:center;font-size:9px}
td.no{text-align:center;font-weight:700;color:#444}
td.pj{text-align:center;font-weight:600}
tr:nth-child(even) td{background:#f8fafc}
.catatan{margin-top:14px;font-size:9px;color:#444;font-style:italic;padding:8px 12px;background:#f1f5f9;border-left:3px solid #64748b;border-radius:0 4px 4px 0}
@page{size:A4 landscape;margin:12mm}
@media print{body{padding:0}.print-bar{display:none!important}}
</style>
</head>
<body>
<div class="print-bar">
  <button class="btn-print" onclick="window.print()">🖨 Cetak / PDF</button>
  <span style="color:#1d4ed8"><strong>Ctrl+P</strong> → Ukuran kertas: <strong>A4 Landscape</strong></span>
</div>

<div class="judul">KERTAS KERJA VERIFIKASI CAPAIAN TARGET TAHUNAN RENSTRA &amp; PERMASALAHAN</div>
<div class="sub-judul">Tahun <?= xss($tahun) ?></div>

<table>
  <thead>
    <tr>
      <th style="width:30px">NO</th>
      <th style="width:18%">TUJUAN</th>
      <th style="width:18%">SASARAN</th>
      <th style="width:22%">INDIKATOR KINERJA</th>
      <th style="width:14%">TARGET</th>
      <th style="width:12%">PENANGGUNG JAWAB</th>
      <th style="width:14%">KETERANGAN</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($rows)): ?>
    <tr><td colspan="7" style="text-align:center;padding:30px;color:#999">Belum ada data IKK tahun <?= xss($tahun) ?></td></tr>
  <?php else: ?>
    <?php foreach ($rows as $i => $r): ?>
    <tr>
      <td class="no"><?= $i + 1 ?></td>
      <td><?= nl2br(xss($r['tujuan'] ?: '-')) ?></td>
      <td><?= nl2br(xss($r['sasaran'] ?: '-')) ?></td>
      <td><?= nl2br(xss($r['indikator_kinerja'] ?: '-')) ?></td>
      <td><?= nl2br(xss($r['target'] ?: '-')) ?></td>
      <td class="pj"><?= xss($r['penanggung_jawab'] ?: '-') ?></td>
      <td><?= nl2br(xss($r['keterangan'] ?: '-')) ?></td>
    </tr>
    <?php endforeach; ?>
  <?php endif; ?>
  </tbody>
</table>

<div class="catatan">
  <strong>Catatan:</strong> Sesuaikan dengan Rencana tahun berjalan.
</div>

</body>
</html>
