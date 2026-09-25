<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$activeId = (int)($_GET['id'] ?? 0);
$activeTw = (int)($_GET['tw'] ?? 1);
$type = $_GET['type'] ?? 'excel';

if ($activeId <= 0) die('ID KKPR tidak valid.');

$db = getDB();
$s = $db->prepare("SELECT * FROM kkpr_header WHERE id=?");
$s->bind_param('i', $activeId); $s->execute();
$kkpr = $s->get_result()->fetch_assoc(); $s->close();
if (!$kkpr) die('Data KKPR tidak ditemukan.');

$s2 = $db->prepare("SELECT * FROM kkpr_risiko WHERE id_kkpr=? ORDER BY SUBSTRING_INDEX(kode_risiko, '.', 1) ASC, CAST(SUBSTRING_INDEX(kode_risiko, '.', -1) AS UNSIGNED) ASC, no_urut, id");
$s2->bind_param('i', $activeId); $s2->execute();
$baseRisks = $s2->get_result()->fetch_all(MYSQLI_ASSOC); $s2->close();

$m_s = $db->prepare("SELECT * FROM monev_triwulan WHERE triwulan=?");
$m_s->bind_param('i', $activeTw); $m_s->execute();
$monevCurrRaw = $m_s->get_result()->fetch_all(MYSQLI_ASSOC); $m_s->close();
$monevCurr = []; foreach ($monevCurrRaw as $m) $monevCurr[$m['id_risiko']] = $m;

$monevPrev = [];
if ($activeTw > 1) {
    $twPrev = $activeTw - 1;
    $m_p = $db->prepare("SELECT * FROM monev_triwulan WHERE triwulan=?");
    $m_p->bind_param('i', $twPrev); $m_p->execute();
    $monevPrevRaw = $m_p->get_result()->fetch_all(MYSQLI_ASSOC); $m_p->close();
    foreach ($monevPrevRaw as $m) $monevPrev[$m['id_risiko']] = $m;
}

$rows = [];
foreach ($baseRisks as $r) {
    $idr = $r['id']; $curr = $monevCurr[$idr] ?? null; $prev = $monevPrev[$idr] ?? null;

    if ($activeTw == 1) {
        $pA = $r['probabilitas']; $dA = $r['dampak_level']; $bA = $r['bobot']; $nA = $r['nilai_risiko']; $tA = $r['tingkat_risiko']; $prioA = $r['prioritas_risiko'] ?? '-';
        $linkPrev = '-';
    } else {
        $pA = $prev ? $prev['pantau_p'] : $r['probabilitas'];
        $dA = $prev ? $prev['pantau_d'] : $r['dampak_level'];
        $bA = $prev ? $prev['pantau_bobot'] : $r['bobot'];
        $nA = $prev ? $prev['pantau_nilai'] : $r['nilai_risiko'];
        $tA = $prev ? $prev['pantau_tingkat'] : $r['tingkat_risiko'];
        $prioA = '-'; // not tracked in monev historically, just dash
        $linkPrev = $prev ? $prev['link_data_dukung'] : '-';
    }

    $r['prev_p'] = $pA; $r['prev_d'] = $dA; $r['prev_bobot'] = $bA; $r['prev_nilai'] = $nA; $r['prev_tingkat'] = $tA; $r['prev_prio'] = $prioA; $r['prev_link'] = $linkPrev;
    $r['curr'] = $curr;
    $rows[] = $r;
}

if ($type === 'excel') {
    while (ob_get_level() > 0) ob_end_clean();
    header("Content-type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=Monev_Triwulan_".$activeTw."_".$kkpr['tahun'].".xls");
    header("Pragma: no-cache");
    header("Expires: 0");
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
}

function tBg($t){
    if($t=='Sangat Tinggi') return '#dc2626'; if($t=='Tinggi') return '#f97316';
    if($t=='Sedang') return '#FFFF00'; if($t=='Rendah') return '#22c55e';
    if($t=='Sangat Rendah') return '#3b82f6'; return '';
}
function tCl($t){
    if($t=='Sedang') return '#000';
    return '#fff';
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Monev Triwulan <?= $activeTw ?></title>
<style>
    body { font-family: Arial, sans-serif; font-size: 11px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #000; padding: 5px; vertical-align: top; }
    th { background-color: #f2f2f2; text-align: center; vertical-align: middle; font-weight: bold; }
    .text-center { text-align: center; }
    .title { text-align: center; font-size: 14px; font-weight: bold; margin-bottom: 5px; }
    .subtitle { text-align: center; font-size: 12px; font-weight: normal; margin-bottom: 15px; }
    @media print {
        @page { size: landscape; }
        body { margin: 1cm; }
        .no-print { display: none; }
        .print-bar { display: none !important; }
    }
    .print-bar{display:flex;gap:10px;align-items:center;padding:8px 14px;background:#eff6ff;border:1px dashed #93c5fd;border-radius:6px;margin-bottom:10px;font-size:12px}
    .btn-print{padding:7px 16px;background:#1e3a5f;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:12px;font-weight:700}
</style>
</head>
<body>
    <?php if ($type === 'pdf'): ?>
    <div class="print-bar">
        <button class="btn-print" onclick="window.print()">🖨 Cetak / PDF</button>
        <span style="color:#1d4ed8"><strong>Ctrl+P</strong> → Orientasi: <strong>Landscape</strong></span>
    </div>
    <?php endif; ?>
    <div class="title">Monev Manajemen Risiko Triwulan <?= $activeTw ?> Tahun <?= htmlspecialchars($kkpr['tahun']) ?></div>
    <div class="subtitle"><?= htmlspecialchars($kkpr['unit_pemilik_risiko']) ?></div>
    <br>
    <table>
        <thead>
            <tr>
                <th rowspan="2">NO</th>
                <th rowspan="2">RISIKO</th>
                <th rowspan="2">KODE RISIKO</th>
                <th colspan="6">KONDISI <?= $activeTw == 1 ? 'AWAL' : 'AKHIR TRIWULAN '.($activeTw-1) ?></th>
                <th rowspan="2">UPAYA PENGENDALIAN</th>
                <th rowspan="2">LINK DATA DUKUNG <?= $activeTw == 1 ? '' : 'TRIWULAN '.($activeTw-1) ?></th>
                <th colspan="5">KONDISI AKHIR TRIWULAN <?= $activeTw ?></th>
                <th colspan="2">SIMPULAN</th>
                <th rowspan="2">KENDALA / MASALAH</th>
                <th rowspan="2">RENCANA TINDAK LANJUT</th>
                <th rowspan="2">LINK DATA DUKUNG TRIWULAN <?= $activeTw ?></th>
            </tr>
            <tr>
                <th>P</th><th>D</th><th>BOBOT</th><th>NILAI</th><th>TINGKAT RISIKO</th><th>PRIORITAS RISIKO</th>
                <th>P</th><th>D</th><th>BOBOT</th><th>NILAI</th><th>TINGKAT RISIKO</th>
                <th>TINGKAT RISIKO</th><th>EFEKTIFITAS</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($rows as $i => $r): $c = $r['curr']; ?>
            <tr>
                <td class="text-center"><?= $i+1 ?></td>
                <td><?= nl2br(htmlspecialchars($r['nama_risiko'])) ?></td>
                <td class="text-center"><?= htmlspecialchars($r['kode_risiko']??'-') ?></td>
                <td class="text-center"><?= $r['prev_p'] ?></td>
                <td class="text-center"><?= $r['prev_d'] ?></td>
                <td class="text-center"><?= $r['prev_bobot'] ?></td>
                <td class="text-center"><?= $r['prev_nilai'] ?></td>
                <td class="text-center" style="background:<?=tBg($r['prev_tingkat'])?>;color:<?=tCl($r['prev_tingkat'])?>"><?= $r['prev_tingkat'] ?></td>
                <td class="text-center"><?= $r['prev_prio'] ?></td>
                <td><?= $c ? nl2br(htmlspecialchars($c['upaya_pengendalian'])) : '-' ?></td>
                <td><?= $r['prev_link'] === '-' ? '-' : htmlspecialchars($r['prev_link']) ?></td>
                <td class="text-center"><?= $c ? $c['pantau_p'] : '-' ?></td>
                <td class="text-center"><?= $c ? $c['pantau_d'] : '-' ?></td>
                <td class="text-center"><?= $c ? $c['pantau_bobot'] : '-' ?></td>
                <td class="text-center"><?= $c ? $c['pantau_nilai'] : '-' ?></td>
                <td class="text-center" style="<?= $c ? 'background:'.tBg($c['pantau_tingkat']).';color:'.tCl($c['pantau_tingkat']) : '' ?>"><?= $c ? $c['pantau_tingkat'] : '-' ?></td>
                <td class="text-center"><?= $c ? htmlspecialchars($c['simpulan_tingkat']) : '-' ?></td>
                <td class="text-center" style="<?= $c && $c['efektifitas']=='Efektif'?'background:#22c55e;color:#fff':'background:#dc2626;color:#fff' ?>"><?= $c ? htmlspecialchars($c['efektifitas']) : '-' ?></td>
                <td><?= $c ? nl2br(htmlspecialchars($c['kendala'])) : '-' ?></td>
                <td><?= $c ? nl2br(htmlspecialchars($c['rencana_tindak_lanjut'])) : '-' ?></td>
                <td><?= $c && !empty($c['link_data_dukung']) ? htmlspecialchars($c['link_data_dukung']) : '-' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
