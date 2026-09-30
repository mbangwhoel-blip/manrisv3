<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$activeId = (int)($_GET['id'] ?? 0);
$activeTw = (int)($_GET['tw'] ?? 1);
$type = $_GET['type'] ?? 'excel';

if ($activeId <= 0) die('ID KKPR tidak valid.');

$db = getDB();
$chkReal = $db->query("SHOW COLUMNS FROM monev_triwulan LIKE 'realisasi_pengendalian'");
if ($chkReal && $chkReal->num_rows === 0) {
    $db->query("ALTER TABLE monev_triwulan ADD COLUMN realisasi_pengendalian TEXT NULL AFTER upaya_pengendalian");
}
$s = $db->prepare("SELECT * FROM kkpr_header WHERE id=?");
$s->bind_param('i', $activeId); $s->execute();
$kkpr = $s->get_result()->fetch_assoc(); $s->close();
if (!$kkpr) die('Data KKPR tidak ditemukan.');

$s2 = $db->prepare("SELECT * FROM kkpr_risiko WHERE id_kkpr=? ORDER BY SUBSTRING_INDEX(kode_risiko, '.', 1) ASC, CAST(SUBSTRING_INDEX(kode_risiko, '.', -1) AS UNSIGNED) ASC, no_urut, id");
$s2->bind_param('i', $activeId); $s2->execute();
$baseRisks = $s2->get_result()->fetch_all(MYSQLI_ASSOC); $s2->close();

// Ambil semua data monev_triwulan untuk KKPR ini
$m_all = $db->prepare("SELECT m.* FROM monev_triwulan m JOIN kkpr_risiko r ON r.id = m.id_risiko WHERE r.id_kkpr=? ORDER BY m.triwulan ASC");
$m_all->bind_param('i', $activeId);
$m_all->execute();
$monevAllRaw = $m_all->get_result()->fetch_all(MYSQLI_ASSOC);
$m_all->close();

$monevByTwRisk = [];
foreach ($monevAllRaw as $m) {
    $monevByTwRisk[(int)$m['triwulan']][(int)$m['id_risiko']] = $m;
}

$monevCurr = $monevByTwRisk[$activeTw] ?? [];
$twPrev = ($activeTw > 1) ? ($activeTw - 1) : 1;
$monevPrev = $monevByTwRisk[$twPrev] ?? [];

$rows = [];
foreach ($baseRisks as $r) {
    $idr = $r['id']; $curr = $monevCurr[$idr] ?? null; $prev = $monevPrev[$idr] ?? null;

    if ($activeTw == 1) {
        $pA = $r['probabilitas']; $dA = $r['dampak_level']; $bA = $r['bobot']; $nA = $r['nilai_risiko']; $tA = $r['tingkat_risiko']; $prioA = $r['prioritas_risiko'] ?? '-';
        $linkPrev = '-';
    } else {
        $prevFilled = null;
        for ($pt = $activeTw - 1; $pt >= 1; $pt--) {
            if (!empty($monevByTwRisk[$pt][$idr]) && $monevByTwRisk[$pt][$idr]['pantau_p'] !== null) {
                $prevFilled = $monevByTwRisk[$pt][$idr];
                break;
            }
        }
        $pA = $prevFilled ? $prevFilled['pantau_p'] : $r['probabilitas'];
        $dA = $prevFilled ? $prevFilled['pantau_d'] : $r['dampak_level'];
        $bA = $prevFilled ? $prevFilled['pantau_bobot'] : $r['bobot'];
        $nA = $prevFilled ? $prevFilled['pantau_nilai'] : $r['nilai_risiko'];
        $tA = ($prevFilled && !empty($prevFilled['pantau_tingkat'])) ? $prevFilled['pantau_tingkat'] : $r['tingkat_risiko'];
        $prioA = '-'; // not tracked in monev historically, just dash
        $linkPrev = $prevFilled ? ($prevFilled['link_data_dukung'] ?? '-') : '-';
    }

    $r['prev_p'] = $pA; $r['prev_d'] = $dA; $r['prev_bobot'] = $bA; $r['prev_nilai'] = $nA; $r['prev_tingkat'] = $tA; $r['prev_prio'] = $prioA; $r['prev_link'] = $linkPrev;
    $r['curr'] = $curr;

    // Upaya Pengendalian: ambil dari TW aktif, jika kosong fallback ke TW sebelumnya (TW n-1 .. TW 1), lalu ke KKPR
    $upayaDisplay = '';
    if ($curr && !empty(trim((string)($curr['upaya_pengendalian'] ?? '')))) {
        $upayaDisplay = trim((string)$curr['upaya_pengendalian']);
    } else {
        for ($t = $activeTw; $t >= 1; $t--) {
            $prevUpaya = $monevByTwRisk[$t][$idr]['upaya_pengendalian'] ?? null;
            if ($prevUpaya !== null && trim((string)$prevUpaya) !== '') {
                $upayaDisplay = trim((string)$prevUpaya);
                break;
            }
        }
        if ($upayaDisplay === '') {
            if (!empty($r['pengendalian_uraian']) && trim((string)$r['pengendalian_uraian']) !== '') {
                $upayaDisplay = trim((string)$r['pengendalian_uraian']);
            } elseif (!empty($r['rpti_uraian']) && trim((string)$r['rpti_uraian']) !== '') {
                $upayaDisplay = trim((string)$r['rpti_uraian']);
            }
        }
    }
    $r['upaya_display'] = $upayaDisplay !== '' ? $upayaDisplay : '-';

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

if (!function_exists('tBg')) {
    function tBg($t){
        if($t=='Sangat Tinggi') return '#dc2626'; if($t=='Tinggi') return '#f97316';
        if($t=='Sedang') return '#FFFF00'; if($t=='Rendah') return '#22c55e';
        if($t=='Sangat Rendah') return '#3b82f6'; return '';
    }
}
if (!function_exists('tCl')) {
    function tCl($t){
        if($t=='Sedang') return '#000';
        return '#fff';
    }
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
                <th colspan="6">KONDISI <?= $activeTw == 1 ? 'AWAL' : 'AWAL TRIWULAN '.($activeTw-1) ?></th>
                <th rowspan="2">UPAYA PENGENDALIAN</th>
                <th colspan="5">KONDISI AKHIR TRIWULAN <?= $activeTw ?></th>
                <th colspan="2">SIMPULAN</th>
                <th rowspan="2">KENDALA / MASALAH</th>
                <th rowspan="2">RENCANA TINDAK LANJUT</th>
                <th rowspan="2">STATUS</th>
                <th rowspan="2">LINK DATA DUKUNG TRIWULAN <?= $activeTw ?></th>
            </tr>
            <tr>
                <th>P</th><th>D</th><th>BOBOT</th><th>NILAI</th><th>TINGKAT RISIKO</th><th>PRIORITAS RISIKO</th>
                <th>P</th><th>D</th><th>BOBOT</th><th>NILAI</th><th>TINGKAT RISIKO</th>
                <th>TINGKAT RISIKO</th><th>EFEKTIFITAS</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($rows as $i => $r): $c = $r['curr'];
                $statusLabel = '-';
                $statusDesc = '';
                $statusBg = '';
                $statusColor = '';
                if ($c && $c['pantau_nilai'] !== null) {
                    $pTingkat = trim((string)($c['pantau_tingkat'] ?? ''));
                    if ($pTingkat === 'Sangat Rendah') {
                        $statusLabel = 'Closed (Selesai)';
                        $statusDesc = 'Level risiko turun di bawah ambang batas';
                        $statusBg = '#dcfce7';
                        $statusColor = '#166534';
                    } else {
                        $statusLabel = 'Open';
                        $statusDesc = 'Level risiko belum di bawah ambang batas';
                        $statusBg = '#fef3c7';
                        $statusColor = '#92400e';
                    }
                }
            ?>
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
                <td><?= $r['upaya_display'] !== '-' ? nl2br(htmlspecialchars($r['upaya_display'])) : '-' ?></td>
                <td class="text-center"><?= $c ? $c['pantau_p'] : '-' ?></td>
                <td class="text-center"><?= $c ? $c['pantau_d'] : '-' ?></td>
                <td class="text-center"><?= $c ? $c['pantau_bobot'] : '-' ?></td>
                <td class="text-center"><?= $c ? $c['pantau_nilai'] : '-' ?></td>
                <td class="text-center" style="<?= $c ? 'background:'.tBg($c['pantau_tingkat']).';color:'.tCl($c['pantau_tingkat']) : '' ?>"><?= $c ? $c['pantau_tingkat'] : '-' ?></td>
                <td class="text-center"><?= $c ? htmlspecialchars($c['simpulan_tingkat']) : '-' ?></td>
                <td class="text-center" style="<?= $c && $c['efektifitas']=='Efektif'?'background:#22c55e;color:#fff':'background:#dc2626;color:#fff' ?>"><?= $c ? htmlspecialchars($c['efektifitas']) : '-' ?></td>
                <td><?= $c ? nl2br(htmlspecialchars($c['kendala'])) : '-' ?></td>
                <td><?= $c ? nl2br(htmlspecialchars($c['rencana_tindak_lanjut'])) : '-' ?></td>
                <td class="text-center" style="<?= $statusBg ? "background:{$statusBg};color:{$statusColor};" : '' ?>">
                    <div style="font-weight:bold;font-size:10px;"><?= htmlspecialchars($statusLabel) ?></div>
                    <?php if ($statusDesc !== ''): ?>
                    <div style="font-size:8.5px;line-height:1.2;margin-top:2px;opacity:.9;"><?= htmlspecialchars($statusDesc) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= $c && !empty($c['link_data_dukung']) ? htmlspecialchars($c['link_data_dukung']) : '-' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
