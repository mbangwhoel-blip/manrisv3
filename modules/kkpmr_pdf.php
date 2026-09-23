<?php
/**
 * LAPORAN PDF — KERTAS KERJA PEMANTAUAN DAN REVIU (KKPMR)
 * Format landscape A3, sesuai V6 KK-identifikasi Risiko Kemenkes
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
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
$s2->bind_param('i', $id); $s2->execute();
$rows = $s2->get_result()->fetch_all(MYSQLI_ASSOC); $s2->close();

// ── Periode laporan: tahunan, tw1-tw4, atau bulan_ini ──────
$periode = $_GET['periode'] ?? 'tahunan';
if (!in_array($periode, ['tahunan','tw1','tw2','tw3','tw4','bulan_ini'], true)) $periode = 'tahunan';
$triwulanRomawi = ['I','II','III','IV'];

// Untuk twTarget: jika bulan_ini, kita ambil TW saat ini
$twTarget = 4;
if ($periode === 'bulan_ini') {
    $twTarget = ceil(date('n') / 3);
} elseif ($periode !== 'tahunan') {
    $twTarget = (int)substr($periode, 2);
}

$periodeLabel = 'Laporan Tahunan (Akhir Triwulan IV)';
if ($periode === 'bulan_ini') {
    $namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $periodeLabel = 'Laporan Bulan Ini (' . $namaBulan[(int)date('n')] . ' ' . date('Y') . ')';
} elseif ($periode !== 'tahunan') {
    $periodeLabel = 'Laporan Triwulan ' . $triwulanRomawi[$twTarget - 1];
}

$pantauHeaderLabel = 'HASIL PEMANTAUAN S.D. AKHIR TAHUN';
if ($periode === 'bulan_ini') {
    $pantauHeaderLabel = 'HASIL PEMANTAUAN BULAN INI';
} elseif ($periode !== 'tahunan') {
    $pantauHeaderLabel = 'HASIL PEMANTAUAN TRIWULAN ' . $triwulanRomawi[$twTarget - 1];
}

// ── Data pemantauan per triwulan dari monev_triwulan ───────────
// Tahunan memakai data Triwulan IV; jika belum ada diisi monev,
// fallback ke snapshot pemantauan terakhir di kkpr_risiko.
$monevMap = [];
try {
    $s3 = $db->prepare('SELECT m.* FROM monev_triwulan m JOIN kkpr_risiko r ON r.id = m.id_risiko WHERE m.triwulan = ? AND r.id_kkpr = ?');
    $s3->bind_param('ii', $twTarget, $id);
    $s3->execute();
    foreach ($s3->get_result()->fetch_all(MYSQLI_ASSOC) as $m) $monevMap[(int)$m['id_risiko']] = $m;
    $s3->close();
} catch (Throwable $e) {
    $monevMap = [];
}

function pmrColor(string $t): array {
    return match($t) {
        'Sangat Tinggi' => ['#dc2626', '#fff'],
        'Tinggi'        => ['#f97316', '#fff'],
        'Sedang'        => ['#FFFF00', '#000'],
        'Rendah'        => ['#22c55e', '#fff'],
        default         => ['#3b82f6', '#fff'],
    };
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>KKPMR — <?= xss($h['tahun']) ?><?= $periode !== 'tahunan' ? ' (' . xss($periodeLabel) . ')' : '' ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:8.5px}
body{font-family:Arial,Helvetica,sans-serif;color:#000;background:#fff;padding:12px 14px;line-height:1.25}
.print-bar{display:flex;gap:10px;align-items:center;padding:7px 12px;background:#eff6ff;border:1px dashed #93c5fd;border-radius:6px;margin-bottom:10px;font-size:11px}
.btn-print{padding:6px 14px;background:#1e3a5f;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:11px;font-weight:700}
.judul{text-align:center;font-size:10px;font-weight:900;text-transform:uppercase;padding:6px;border:2px solid #000;letter-spacing:.03em}
.periode-box{text-align:center;font-size:9px;font-weight:700;padding:4px;border:1px solid #000;border-top:none;background:#f0f9ff;letter-spacing:.05em}
.info-table{width:100%;border-collapse:collapse;margin-bottom:12px;font-size:7.5px}
.info-table td{border:1px solid #000;padding:4px 6px;vertical-align:top}
.info-label{background:#f1f5f9;font-weight:700;width:15%}
.info-sep{border-left:2px solid #000}
.tbl-wrap{border:1px solid #000;border-top:none}
table{width:100%;border-collapse:collapse;font-size:7.5px}
th,td{border:1px solid #999;padding:2px 3px;vertical-align:middle}
.th-main{background:#1e3a5f;color:#fff;font-weight:700;text-align:center;font-size:7px;white-space:nowrap}
.th-awal{background:#c8d8e8;font-weight:700;text-align:center;font-size:7px}
.th-pengendalian{background:#fde68a;font-weight:700;text-align:center;font-size:7px}
.th-pantau{background:#a7f3d0;font-weight:700;text-align:center;font-size:7px}
.th-simpul{background:#fca5a5;font-weight:700;text-align:center;font-size:7px}
.badge-t{display:inline-block;padding:1px 4px;border-radius:2px;font-weight:700;font-size:7px;text-align:center;white-space:nowrap}
.ttd-area{display:grid;grid-template-columns:1fr 1fr;border:1px solid #000;border-top:none}
.ttd-box{padding:8px 12px;min-height:90px;border-right:1px solid #000}
.ttd-box:last-child{border-right:none}
.ttd-lbl{font-size:8px;color:#444;margin-bottom:3px}
.ttd-img{height:42px;margin:3px 0}
.ttd-nm{font-size:9px;font-weight:700;margin-top:2px}
.ttd-nip{font-size:8px;color:#333}
@page{size:A3 landscape;margin:8mm}
@media print{body{padding:0}.print-bar{display:none!important}}
</style>
</head>
<body>
<div class="print-bar">
  <button class="btn-print" onclick="window.print()">🖨 Cetak / PDF</button>
  <span style="color:#1d4ed8"><strong>Ctrl+P</strong> → Ukuran kertas: <strong>A3 Landscape</strong></span>
</div>

<div class="judul">KERTAS KERJA PEMANTAUAN DAN REVIU UNIT PEMILIK RISIKO TINGKAT II (UPR-T.II) KEMENTERIAN KESEHATAN</div>
<div class="periode-box">
  PERIODE LAPORAN: <?= strtoupper(xss($periodeLabel)) ?> — TAHUN <?= xss($h['tahun']) ?> &nbsp;|&nbsp; DICETAK: <?= date('d-m-Y') ?>
</div>

<table class="info-table">
  <tbody>
    <tr>
      <td class="info-label">Tujuan</td>
      <td><?= xss($h['tujuan'] ?? '-') ?></td>
      <td class="info-label info-sep">Unit Pemilik Risiko</td>
      <td><?= xss($h['unit_pemilik_risiko'] ?? '-') ?></td>
    </tr>
    <tr>
      <td class="info-label">Sasaran</td>
      <td><?= nl2br(xss($h['sasaran'] ?? '-')) ?></td>
      <td class="info-label info-sep">Nama Pemilik Risiko</td>
      <td><?= xss($h['nama_pemilik_risiko'] ?? '-') ?></td>
    </tr>
    <tr>
      <td class="info-label">Indikator Kinerja Utama</td>
      <td><?= nl2br(xss($h['indikator_kinerja'] ?? '-')) ?></td>
      <td class="info-label info-sep">Nama Tim Pengelola Risiko</td>
      <td><?= xss($h['nama_pengelola_risiko'] ?? '-') ?></td>
    </tr>
    <tr>
      <td class="info-label">Target</td>
      <td><?= nl2br(xss($h['target'] ?? '-')) ?></td>
      <td class="info-label info-sep">Tgl Penilaian Risiko</td>
      <td><?= tglIndo($h['tgl_penilaian'] ?? '') ?></td>
    </tr>
    <tr>
      <td class="info-label">Program</td>
      <td><?= xss($h['program'] ?? '-') ?></td>
      <td class="info-label info-sep">Periode Risiko</td>
      <td><?= xss($h['periode_risiko'] ?? '-') ?></td>
    </tr>
    <tr>
      <td class="info-label">Kegiatan</td>
      <td><?= xss($h['kegiatan'] ?? '-') ?></td>
      <td class="info-label info-sep">Tgl Update Risiko</td>
      <td><?= tglIndo($h['tgl_update'] ?? '') ?></td>
    </tr>
    <tr>
      <td class="info-label">Periode Laporan</td>
      <td><strong><?= xss($periodeLabel) ?> — Tahun <?= xss($h['tahun']) ?></strong></td>
      <td class="info-label info-sep">Tanggal Cetak</td>
      <td><?= date('d-m-Y H:i') ?> WIB</td>
    </tr>
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
      <th class="th-pantau" colspan="5"><?= $pantauHeaderLabel ?></th>
      <th class="th-simpul" colspan="2">SIMPULAN</th>
    </tr>
    <tr>
      <th class="th-awal" style="width:14px">P</th>
      <th class="th-awal" style="width:14px">D</th>
      <th class="th-awal" style="width:22px">BOBOT</th>
      <th class="th-awal" style="width:22px">NILAI</th>
      <th class="th-awal" style="width:50px">TINGKAT RISIKO</th>
      <th class="th-pantau" style="width:14px">P</th>
      <th class="th-pantau" style="width:14px">D</th>
      <th class="th-pantau" style="width:22px">BOBOT</th>
      <th class="th-pantau" style="width:22px">NILAI</th>
      <th class="th-pantau" style="width:50px">TINGKAT RISIKO</th>
      <th class="th-simpul" style="width:60px">TINGKAT RISIKO</th>
      <th class="th-simpul" style="width:55px">EFEKTIFITAS</th>
    </tr>
    <tr style="font-style:italic;color:#666;font-size:7px">
      <th class="th-main">1</th>
      <th class="th-awal">2</th>
      <th class="th-awal">3</th>
      <th class="th-awal">4</th>
      <th class="th-awal">5</th>
      <th class="th-awal">6</th>
      <th class="th-awal">7</th>
      <th class="th-awal">8</th>
      <th class="th-awal">9</th>
      <th class="th-pengendalian">10</th>
      <th class="th-pengendalian">11</th>
      <th class="th-pantau">12</th>
      <th class="th-pantau">13</th>
      <th class="th-pantau">14</th>
      <th class="th-pantau">15</th>
      <th class="th-pantau">16</th>
      <th class="th-simpul">17</th>
      <th class="th-simpul">18</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($rows)): ?>
    <tr><td colspan="18" style="text-align:center;padding:20px;color:#999">Belum ada risiko</td></tr>
  <?php else: ?>
    <?php foreach ($rows as $i => $r): ?>
      <?php
        [$awalBg, $awalFg] = pmrColor($r['tingkat_risiko'] ?? '');

        // Sumber data pemantauan: monev_triwulan triwulan terpilih;
        // fallback ke snapshot kkpr_risiko hanya untuk laporan tahunan.
        $monev = $monevMap[(int)$r['id']] ?? null;
        $fallback = ($periode === 'tahunan');
        $pp = $monev ? $monev['pantau_p']        : ($fallback ? $r['pantau_p']        : null);
        $pd = $monev ? $monev['pantau_d']        : ($fallback ? $r['pantau_d']        : null);
        $pb = $monev ? $monev['pantau_bobot']    : ($fallback ? $r['pantau_bobot']    : null);
        $pn = $monev ? $monev['pantau_nilai']    : ($fallback ? $r['pantau_nilai']    : null);
        $pt = $monev ? $monev['pantau_tingkat']  : ($fallback ? $r['pantau_tingkat']  : null);
        $simpSrc = $monev ? (string)($monev['simpulan_tingkat'] ?? '') : ($fallback ? (string)($r['simpulan'] ?? '') : '');
        $efekSrc = $monev ? (string)($monev['efektifitas'] ?? '')      : ($fallback ? (string)($r['efektifitas'] ?? '') : '');
        [$pmrBg, $pmrFg] = $pt ? pmrColor($pt) : ['#fff', '#000'];

        $sCls = ''; $sText = '-';
        if ($simpSrc !== '') {
            if (stripos($simpSrc, 'tidak ada penurunan') !== false) { $sCls = 'background:#fefce8;'; $sText = 'Tidak ada penurunan tingkat risiko'; }
            elseif (stripos($simpSrc, 'peningkatan') !== false)     { $sCls = 'background:#fee2e2;'; $sText = 'Tingkat risiko mengalami peningkatan'; }
            elseif (stripos($simpSrc, 'penurunan') !== false)       { $sCls = 'background:#dcfce7;'; $sText = 'Tingkat risiko mengalami penurunan'; }
            elseif (trim($simpSrc) === 'Tetap')                     { $sCls = 'background:#fefce8;'; $sText = 'Tidak ada penurunan tingkat risiko'; }
            else { $sCls = 'background:#fefce8;'; $sText = $simpSrc; }
        }

        $eCls = ''; $eText = '-';
        if ($efekSrc !== '') {
            if ($efekSrc === 'Efektif') { $eCls = 'background:#dcfce7;'; $eText = 'Efektif'; }
            else { $eCls = 'background:#fee2e2;'; $eText = 'Tidak Efektif'; }
        }
      ?>
    <tr>
      <td style="text-align:center"><?= $i + 1 ?></td>
      <td><?= xss($r['nama_risiko'] ?? '') ?></td>
      <td style="text-align:center"><?= xss($r['kode_risiko'] ?: '-') ?></td>
      <!-- Penilaian awal -->
      <td style="text-align:center;font-weight:700"><?= (int)$r['probabilitas'] ?></td>
      <td style="text-align:center;font-weight:700"><?= (int)$r['dampak_level'] ?></td>
      <td style="text-align:center"><?= number_format($r['bobot'], 2) ?></td>
      <td style="text-align:center;font-weight:700"><?= round((float)$r['nilai_risiko']) ?></td>
      <td style="text-align:center"><span class="badge-t" style="background:<?= $awalBg ?>;color:<?= $awalFg ?>"><?= xss($r['tingkat_risiko'] ?: '-') ?></span></td>
      <td style="text-align:center"><?= (int)($r['prioritas_risiko'] ?? 0) ?: '-' ?></td>
      <td><?= xss($r['rpti_uraian'] ?: '-') ?></td>
      <td style="text-align:left;white-space:nowrap"><?= xss($r['rpti_jadwal'] ?: '-') ?></td>
      <!-- Hasil pemantauan (sesuai periode) -->
      <td style="text-align:center;font-weight:700"><?= $pp !== null ? (int)$pp : '-' ?></td>
      <td style="text-align:center;font-weight:700"><?= $pd !== null ? (int)$pd : '-' ?></td>
      <td style="text-align:center"><?= $pb !== null ? number_format($pb, 2) : '-' ?></td>
      <td style="text-align:center;font-weight:700"><?= $pn !== null ? round((float)$pn) : '-' ?></td>
      <td style="text-align:center">
        <?php if (!empty($pt)): ?>
          <span class="badge-t" style="background:<?= $pmrBg ?>;color:<?= $pmrFg ?>"><?= xss($pt) ?></span>
        <?php else: ?> - <?php endif; ?>
      </td>
      <!-- Simpulan & Efektifitas -->
      <td style="text-align:center;font-size:7px;<?= $sCls ?>"><?= xss($sText) ?></td>
      <td style="text-align:center;font-size:7px;<?= $eCls ?>"><?= xss($eText) ?></td>
    </tr>
    <?php endforeach; ?>
  <?php endif; ?>
  </tbody>
</table>
</div>

<!-- TTD -->
<div class="ttd-area">
  <div class="ttd-box">
    <div class="ttd-lbl">Pemilik Risiko</div>
    <?php if (!empty($h['ttd_pemilik'])): ?>
      <img class="ttd-img" src="<?= $h['ttd_pemilik'] ?>" alt="TTD">
    <?php else: ?>
      <div class="ttd-img"></div>
    <?php endif; ?>
    <div class="ttd-nm"><?= xss($h['nama_ttd_pemilik'] ?? '-') ?></div>
    <div class="ttd-nip">NIP. <?= xss($h['nip_ttd_pemilik'] ?? '-') ?></div>
  </div>
  <div class="ttd-box">
    <div class="ttd-lbl">Pengelola Risiko</div>
    <?php if (!empty($h['ttd_pengelola'])): ?>
      <img class="ttd-img" src="<?= $h['ttd_pengelola'] ?>" alt="TTD">
    <?php else: ?>
      <div class="ttd-img"></div>
    <?php endif; ?>
    <div class="ttd-nm"><?= xss($h['nama_ttd_pengelola'] ?? '-') ?></div>
    <div class="ttd-nip">NIP. <?= xss($h['nip_ttd_pengelola'] ?? '-') ?></div>
  </div>
</div>

</body>
</html>
