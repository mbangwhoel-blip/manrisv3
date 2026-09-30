<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$isConsolidated = (($_GET['konsolidasi'] ?? '') === '1') || (($_GET['page'] ?? '') === 'monev_konsolidasi');
// Konsolidasi hanya bisa diakses Admin, Kepala/Pimpinan, Koordinator; monev tahunan bisa diakses Risk Manager & Staff
if ($isConsolidated) {
    requireRole('Admin', 'Kepala', 'Pimpinan', 'Koordinator');
} else {
    requireRole('Admin', 'Risk Manager', 'Kepala', 'Pimpinan', 'Koordinator', 'Staff');
}

$activeTahun = $_GET['tahun'] ?? date('Y');
$jenisLaporan = $_GET['jenis'] ?? 'tw1';
$type = $_GET['type'] ?? 'excel';

$db = getDB();
$chkReal = $db->query("SHOW COLUMNS FROM monev_triwulan LIKE 'realisasi_pengendalian'");
if ($chkReal && $chkReal->num_rows === 0) {
    $db->query("ALTER TABLE monev_triwulan ADD COLUMN realisasi_pengendalian TEXT NULL AFTER upaya_pengendalian");
}
$reportUser = strtolower(trim((string)($_SESSION['user_username'] ?? '')));
$subjudulMap = ['adum' => 'Administrasi Umum', 'risk_manager' => 'Risk Manager', 'timker1' => 'Timker1', 'timker2' => 'Timker2', 'timker3' => 'Timker3'];
$subjudul = $subjudulMap[$reportUser] ?? trim((string)($_SESSION['user_nama'] ?? ''));
if ($subjudul === '') $subjudul = 'Risk Manager';
if ($isConsolidated) {
    $subjudul = 'Balai Besar Laboratorium Kesehatan Lingkungan';
}
$ownerSql = canAccessAllRecords() ? '' : ' AND h.created_by = ?';
$s2 = $db->prepare("SELECT r.*, h.unit_pemilik_risiko, h.nama_pemilik_risiko FROM kkpr_risiko r JOIN kkpr_header h ON r.id_kkpr = h.id WHERE h.tahun = ? $ownerSql ORDER BY SUBSTRING_INDEX(r.kode_risiko, '.', 1) ASC, CAST(SUBSTRING_INDEX(r.kode_risiko, '.', -1) AS UNSIGNED) ASC, r.kode_risiko ASC, h.unit_pemilik_risiko, r.no_urut");
if ($ownerSql !== '') {
    $userId = (int)$_SESSION['user_id'];
    $s2->bind_param('si', $activeTahun, $userId);
} else {
    $s2->bind_param('s', $activeTahun);
}
$s2->execute();
$baseRisks = $s2->get_result()->fetch_all(MYSQLI_ASSOC); $s2->close();

$twTarget = 1; if ($jenisLaporan == 'tw2') $twTarget = 2; if ($jenisLaporan == 'tw3') $twTarget = 3; if ($jenisLaporan == 'tw4' || $jenisLaporan == 'tahunan') $twTarget = 4;

// Ambil semua data monev_triwulan untuk tahun aktif (TW1 s/d TW4)
$m_all = $db->prepare("SELECT m.* FROM monev_triwulan m JOIN kkpr_risiko r ON r.id = m.id_risiko JOIN kkpr_header h ON h.id = r.id_kkpr WHERE h.tahun=? ORDER BY m.triwulan ASC");
$m_all->bind_param('s', $activeTahun);
$m_all->execute();
$monevAllRaw = $m_all->get_result()->fetch_all(MYSQLI_ASSOC);
$m_all->close();

$monevByTwRisk = [];
foreach ($monevAllRaw as $m) {
    $monevByTwRisk[(int)$m['triwulan']][(int)$m['id_risiko']] = $m;
}

$monevCurr = $monevByTwRisk[$twTarget] ?? [];
$twPrev = ($twTarget > 1) ? ($twTarget - 1) : 1;
$monevPrev = $monevByTwRisk[$twPrev] ?? [];

$rows = [];
foreach ($baseRisks as $r) {
    $idr = $r['id'];
    $curr = $monevCurr[$idr] ?? null;
    $prev = $monevPrev[$idr] ?? null;

    if ($twTarget > 1 && $jenisLaporan !== 'tahunan') {
        // Kondisi awal mengambil hasil triwulan sebelumnya (misal TW1 untuk TW2)
        // Fallback ke triwulan yang lebih awal jika ada, atau baseline awal KKPR
        $prevFilled = null;
        for ($pt = $twTarget - 1; $pt >= 1; $pt--) {
            if (!empty($monevByTwRisk[$pt][$idr]) && $monevByTwRisk[$pt][$idr]['pantau_p'] !== null) {
                $prevFilled = $monevByTwRisk[$pt][$idr];
                break;
            }
        }
        $pA = ($prevFilled && $prevFilled['pantau_p'] !== null) ? $prevFilled['pantau_p'] : $r['probabilitas'];
        $dA = ($prevFilled && $prevFilled['pantau_d'] !== null) ? $prevFilled['pantau_d'] : $r['dampak_level'];
        $bA = ($prevFilled && $prevFilled['pantau_bobot'] !== null) ? $prevFilled['pantau_bobot'] : $r['bobot'];
        $nA = ($prevFilled && $prevFilled['pantau_nilai'] !== null) ? $prevFilled['pantau_nilai'] : $r['nilai_risiko'];
        $tA = ($prevFilled && !empty($prevFilled['pantau_tingkat'])) ? $prevFilled['pantau_tingkat'] : $r['tingkat_risiko'];
        $prioA = $r['prioritas_risiko'] ?? '-';
    } else {
        // Untuk Triwulan 1 dan Tahunan: Kondisi awal adalah Penilaian Awal (Baseline KKPR)
        $pA = $r['probabilitas'];
        $dA = $r['dampak_level'];
        $bA = $r['bobot'];
        $nA = $r['nilai_risiko'];
        $tA = $r['tingkat_risiko'];
        $prioA = $r['prioritas_risiko'] ?? '-';
    }

    $r['prev_p'] = $pA;
    $r['prev_d'] = $dA;
    $r['prev_bobot'] = $bA;
    $r['prev_nilai'] = $nA;
    $r['prev_tingkat'] = $tA;
    $r['prev_prio'] = $prioA;
    $r['curr'] = $curr;

    // Upaya Pengendalian:
    // 1. Ambil dari triwulan yang sedang dipilih/aktif jika sudah diisi.
    // 2. Jika belum diisi di triwulan ini, otomatis telusuri ke triwulan sebelumnya (TW n-1 .. TW 1),
    //    sehingga jika sudah diinput di TW 1, otomatis muncul di TW 2, TW 3, TW 4, maupun Tahunan.
    // 3. Jika belum pernah diinput di triwulan manapun, fallback ke uraian pengendalian KKPR.
    $upayaDisplay = '';
    if ($curr && !empty(trim((string)($curr['upaya_pengendalian'] ?? '')))) {
        $upayaDisplay = trim((string)$curr['upaya_pengendalian']);
    } else {
        $searchTw = ($jenisLaporan === 'tahunan') ? 4 : $twTarget;
        for ($t = $searchTw; $t >= 1; $t--) {
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

    // Evaluasi simpulan & efektivitas perbandingan triwulan aktif vs kondisi awal yang ditampilkan
    if ($curr && $curr['pantau_nilai'] !== null) {
        $skorAkhir = (float)$curr['pantau_nilai'];
        $skorAwal = (float)$nA;
        if ($skorAkhir < $skorAwal) {
            $r['eval_simpulan'] = 'Tingkat risiko mengalami penurunan';
            $r['eval_efektifitas'] = 'Efektif';
        } elseif ($skorAkhir > $skorAwal) {
            $r['eval_simpulan'] = 'Tingkat risiko mengalami peningkatan';
            $r['eval_efektifitas'] = 'Tidak Efektif';
        } else {
            $r['eval_simpulan'] = 'Tingkat risiko tetap';
            $r['eval_efektifitas'] = 'Tidak Efektif';
        }
    } else {
        $r['eval_simpulan'] = '-';
        $r['eval_efektifitas'] = '-';
    }

    $rows[] = $r;
}

$judul = $jenisLaporan === 'tahunan'
    ? "Monev Manajemen Risiko 1 Tahun"
    : "Monev Manajemen Risiko Triwulan $twTarget";

// Penyesuaian judul header kondisi awal & kondisi akhir sesuai triwulan yang dipilih
if ($jenisLaporan === 'tahunan') {
    $headerKondisiAwal = 'KONDISI AWAL';
    $headerKondisiAkhir = 'KONDISI AKHIR TRIWULAN 4';
} elseif ($twTarget == 1) {
    $headerKondisiAwal = 'KONDISI AWAL';
    $headerKondisiAkhir = 'KONDISI AKHIR TRIWULAN 1';
} else {
    $headerKondisiAwal = 'KONDISI AWAL TRIWULAN ' . ($twTarget - 1);
    $headerKondisiAkhir = 'KONDISI AKHIR TRIWULAN ' . $twTarget;
}

if ($type === 'excel') {
    while (ob_get_level() > 0) ob_end_clean();
    $excelFilename = ($isConsolidated ? "Konsolidasi_Monev_" : "Monev_") . $activeTahun . "_" . $jenisLaporan . ".xls";
    header("Content-type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=" . $excelFilename);
    header("Pragma: no-cache"); header("Expires: 0");
    echo "\xEF\xBB\xBF";
}

if (!function_exists('tBg')) {
    function tBg($t){ if($t=='Sangat Tinggi') return '#dc2626'; if($t=='Tinggi') return '#f97316'; if($t=='Sedang') return '#FFFF00'; if($t=='Rendah') return '#22c55e'; if($t=='Sangat Rendah') return '#3b82f6'; return ''; }
}
if (!function_exists('tCl')) {
    function tCl($t){ if($t=='Sedang') return '#000'; return '#fff'; }
}
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title><?= $judul ?></title>
<style>
    body { font-family: Arial, sans-serif; font-size: 9px; margin: 0; } table { width: 100%; min-width: 1200px; table-layout: fixed; border-collapse: collapse; } th, td { border: 1px solid #000; padding: 4px 5px; vertical-align: top; line-height: 1.25; overflow-wrap:anywhere; word-break:normal; } th { background-color: #e5e7eb; text-align: center; vertical-align: middle; font-weight: bold; line-height: 1.2; } .text-center { text-align: center; } .title { text-align: center; font-size: 14px; font-weight: bold; margin-bottom: 3px; } .subtitle { text-align: center; font-size: 11px; font-weight: normal; margin-bottom: 9px; } .column-number-row th { background:#d1d5db; font-size:8px; padding:2px; height:16px; }
    .export-table td:nth-child(1),.export-table td:nth-child(4),.export-table td:nth-child(5),.export-table td:nth-child(6),.export-table td:nth-child(7),.export-table td:nth-child(8),.export-table td:nth-child(10),.export-table td:nth-child(12),.export-table td:nth-child(13),.export-table td:nth-child(14),.export-table td:nth-child(15) { white-space:nowrap; text-align:center; }
    /* Kolom TINGKAT: bila tidak muat, teks turun ke baris berikutnya (tidak terpotong) */
    .export-table td:nth-child(9),.export-table td:nth-child(16) { text-align:center; white-space:normal; word-break:normal; }
    .export-table td:nth-child(17),.export-table td:nth-child(18) { white-space:normal; min-width:68px; }
    .export-table td:nth-child(21) { text-align:center; white-space:normal; }
    /* Header sub-kolom (P/D/BOBOT/NILAI/TINGKAT/PRIORITAS/EFEKTIFITAS) tidak boleh melipat */
    .export-table thead tr:nth-child(2) th { white-space: nowrap; font-size: 8px; }
    /* Lebar kolom proporsional 22 kolom (total 100%) */
    .export-table col:nth-child(1){width:2.2%}.export-table col:nth-child(2){width:7%}.export-table col:nth-child(3){width:10.5%}.export-table col:nth-child(4){width:3.5%}.export-table col:nth-child(5),.export-table col:nth-child(6){width:2%}.export-table col:nth-child(7){width:3.2%}.export-table col:nth-child(8){width:3%}.export-table col:nth-child(9){width:4.5%}.export-table col:nth-child(10){width:4.5%}.export-table col:nth-child(11){width:9%}.export-table col:nth-child(12),.export-table col:nth-child(13){width:2%}.export-table col:nth-child(14){width:3.2%}.export-table col:nth-child(15){width:3%}.export-table col:nth-child(16){width:4.5%}.export-table col:nth-child(17){width:5.5%}.export-table col:nth-child(18){width:5%}.export-table col:nth-child(19){width:6%}.export-table col:nth-child(20){width:6%}.export-table col:nth-child(21){width:7.4%}.export-table col:nth-child(22){width:5%}
    @media print { @page { size: A3 landscape; margin: 8mm; } body { margin: 0; font-size: 8px; } th, td { padding: 3px 4px; } .title { font-size: 12px; } .subtitle { font-size: 9px; margin-bottom: 6px; } .column-number-row th { font-size:7px; } }
    .print-bar{display:flex;gap:10px;align-items:center;padding:8px 14px;background:#eff6ff;border:1px dashed #93c5fd;border-radius:6px;margin:6px;font-size:12px}
    .btn-print{padding:7px 16px;background:#1e3a5f;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:12px;font-weight:700}
    @media print{.print-bar{display:none!important}}
.periode-box { text-align: center; font-size: 9px; font-weight: bold; padding: 4px; border: 1px solid #000; background: #f0f9ff; letter-spacing: 0.05em; margin-bottom: 9px; margin-left: 4px; margin-right: 4px; }
.ttd-area{display:grid;grid-template-columns:1fr 1fr;gap:0;margin-top:18px;page-break-inside:avoid}
.ttd-box{text-align:center;padding:4px 10px 8px;min-height:88px}
.ttd-lbl{font-size:9px;font-weight:bold;margin-bottom:2px}
.ttd-img{height:44px;margin:2px 0 4px;object-fit:contain}
.ttd-spacer{height:46px;margin:2px 0 4px}
.ttd-nm{font-size:10px;font-weight:bold;margin-top:2px}
.ttd-nip{font-size:9px;color:#333}
.ttd-approver{margin-top:18px;text-align:center;page-break-inside:avoid}
@media print{.ttd-area{margin-top:12px}.ttd-img{height:38px}}</style></head>
<body>
    <?php if ($type === 'pdf'): ?>
    <div class="print-bar">
        <button class="btn-print" onclick="window.print()">🖨 Cetak / PDF</button>
        <span style="color:#1d4ed8"><strong>Ctrl+P</strong> → Ukuran kertas: <strong>A3 Landscape</strong></span>
    </div>
    <?php endif; ?>
    <div class="title"><?= htmlspecialchars($judul) ?> Tahun <?= htmlspecialchars($activeTahun) ?></div>
    <div class="subtitle"><?= htmlspecialchars($subjudul) ?></div>
    <?php $periodeLabel = $jenisLaporan === 'tahunan' ? 'Laporan Tahunan' : 'Laporan Triwulan ' . $twTarget; ?>
    <div class="periode-box">
      PERIODE LAPORAN: <?= strtoupper(htmlspecialchars($periodeLabel)) ?> - TAHUN <?= htmlspecialchars($activeTahun) ?> &nbsp;|&nbsp; DICETAK: <?= date('d-m-Y') ?>
    </div>
    <table class="export-table">
        <colgroup>
            <?php foreach (range(1, 22) as $columnNo): ?><col><?php endforeach; ?>
        </colgroup>
        <thead>
            <tr>
                <th rowspan="2">NO</th><th rowspan="2">UNIT PEMILIK RISIKO</th><th rowspan="2">RISIKO</th><th rowspan="2">KODE RISIKO</th>
                <th colspan="6"><?= $headerKondisiAwal ?></th>
                <th rowspan="2">UPAYA PENGENDALIAN</th>
                <th colspan="5"><?= $headerKondisiAkhir ?></th>
                <th colspan="2">SIMPULAN</th><th rowspan="2">KENDALA / MASALAH</th><th rowspan="2">RENCANA TINDAK LANJUT</th>
                <th rowspan="2">STATUS</th>
                <th rowspan="2">LINK DATA DUKUNG TRIWULAN <?= $twTarget ?></th>
            </tr>
            <tr>
                <th>P</th><th>D</th><th>BOBOT</th><th>NILAI</th><th>TINGKAT</th><th>PRIORITAS</th>
                <th>P</th><th>D</th><th>BOBOT</th><th>NILAI</th><th>TINGKAT</th>
                <th>TINGKAT</th><th>EFEKTIFITAS</th>
            </tr>
            <tr class="column-number-row">
                <?php foreach (range(1, 22) as $columnNo): ?><th><?= $columnNo ?></th><?php endforeach; ?>
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
                <td class="text-center"><?= $i + 1 ?></td><td><?= htmlspecialchars($r['unit_pemilik_risiko']) ?></td><td><?= nl2br(htmlspecialchars($r['nama_risiko'])) ?></td><td class="text-center"><?= htmlspecialchars($r['kode_risiko']??'-') ?></td>
                <td class="text-center"><?= $r['prev_p'] ?></td><td class="text-center"><?= $r['prev_d'] ?></td><td class="text-center"><?= $r['prev_bobot'] ?></td><td class="text-center"><?= round((float)$r['prev_nilai']) ?></td>
                <td class="text-center" style="background:<?=tBg($r['prev_tingkat'])?>;color:<?=tCl($r['prev_tingkat'])?>"><?= $r['prev_tingkat'] ?></td><td class="text-center"><?= $r['prev_prio'] ?></td>
                <td><?= $r['upaya_display'] !== '-' ? nl2br(htmlspecialchars($r['upaya_display'])) : '-' ?></td>
                <td class="text-center"><?= $c ? $c['pantau_p'] : '-' ?></td><td class="text-center"><?= $c ? $c['pantau_d'] : '-' ?></td><td class="text-center"><?= $c ? $c['pantau_bobot'] : '-' ?></td><td class="text-center"><?= $c ? $c['pantau_nilai'] : '-' ?></td>
                <td class="text-center" style="<?= $c ? 'background:'.tBg($c['pantau_tingkat']).';color:'.tCl($c['pantau_tingkat']) : '' ?>"><?= $c ? $c['pantau_tingkat'] : '-' ?></td>
                <td class="text-center"><?= htmlspecialchars($r['eval_simpulan']) ?></td>
                <td class="text-center" style="<?= $r['eval_efektifitas']=='Efektif'?'background:#22c55e;color:#fff':($r['eval_efektifitas']=='Tidak Efektif'?'background:#dc2626;color:#fff':'') ?>"><?= htmlspecialchars($r['eval_efektifitas']) ?></td>
                <td><?= $c ? nl2br(htmlspecialchars($c['kendala'])) : '-' ?></td><td><?= $c ? nl2br(htmlspecialchars($c['rencana_tindak_lanjut'])) : '-' ?></td>
                <td class="text-center" style="<?= $statusBg ? "background:{$statusBg};color:{$statusColor};" : '' ?>">
                    <div style="font-weight:bold;font-size:8.5px;"><?= htmlspecialchars($statusLabel) ?></div>
                    <?php if ($statusDesc !== ''): ?>
                    <div style="font-size:7px;line-height:1.2;margin-top:2px;opacity:.9;"><?= htmlspecialchars($statusDesc) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= $c && !empty($c['link_data_dukung']) ? htmlspecialchars($c['link_data_dukung']) : '-' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    // ── Header representatif untuk TTD Pemilik & Pengelola Risiko ──
    // Mode per-RM (monev_tahunan): header milik user login (created_by).
    // Mode konsolidasi / Admin: header pertama urut unit (representatif).
    if ($isConsolidated || canAccessAllRecords()) {
        $repStmt = $db->prepare("SELECT * FROM kkpr_header WHERE tahun=? ORDER BY unit_pemilik_risiko, id ASC LIMIT 1");
        $repStmt->bind_param('s', $activeTahun);
    } else {
        $repStmt = $db->prepare("SELECT * FROM kkpr_header WHERE tahun=? AND created_by=? ORDER BY id ASC LIMIT 1");
        $repUid = (int)$_SESSION['user_id'];
        $repStmt->bind_param('si', $activeTahun, $repUid);
    }
    $repStmt->execute();
    $repHeader = $repStmt->get_result()->fetch_assoc() ?: [];
    $repStmt->close();

    // Fallback nama/NIP: kolom TTD (prioritas) -> kolom non-TTD -> blank.
    $namaPemilik  = !empty($repHeader['nama_ttd_pemilik'])  ? $repHeader['nama_ttd_pemilik']
        : (!empty($repHeader['nama_pemilik_risiko'])   ? $repHeader['nama_pemilik_risiko']   : '_________________________');
    $nipPemilik   = !empty($repHeader['nip_ttd_pemilik'])   ? $repHeader['nip_ttd_pemilik']
        : (!empty($repHeader['nip_pemilik_risiko'])    ? $repHeader['nip_pemilik_risiko']    : '_________________________');
    $namaPengelola= !empty($repHeader['nama_ttd_pengelola'])? $repHeader['nama_ttd_pengelola']
        : (!empty($repHeader['nama_pengelola_risiko'])? $repHeader['nama_pengelola_risiko']: '_________________________');
    $nipPengelola = !empty($repHeader['nip_ttd_pengelola']) ? $repHeader['nip_ttd_pengelola']
        : (!empty($repHeader['nip_pengelola_risiko']) ? $repHeader['nip_pengelola_risiko'] : '_________________________');

    // Resolve URL gambar TTD: data: URL dipakai apa adanya; path file
    // dijadikan absolut (APP_URL) agar termuat saat dicetak/di-PDF-kan.
    $ttdSrc = function ($raw): string {
        if (empty($raw)) return '';
        if (str_starts_with($raw, 'data:') || str_starts_with($raw, 'http') || str_starts_with($raw, '//')) return $raw;
        return APP_URL . '/' . ltrim($raw, '/');
    };
    $ttdPemilikSrc   = $ttdSrc($repHeader['ttd_pemilik']   ?? '');
    $ttdPengelolaSrc = $ttdSrc($repHeader['ttd_pengelola']  ?? '');

    // Untuk konsolidasi: ambil approval (Mengetahui & Menyetujui).
    $approval = [];
    if ($isConsolidated) {
        $aps = $db->prepare("SELECT ka.*, u.nama, u.nip FROM konsolidasi_approval ka LEFT JOIN users u ON u.id=ka.approved_by WHERE ka.tahun=? LIMIT 1");
        $aps->bind_param('s', $activeTahun); $aps->execute();
        $approval = $aps->get_result()->fetch_assoc() ?: []; $aps->close();
    }
    ?>
    <div class="ttd-area">
      <div class="ttd-box">
        <div class="ttd-lbl">Pemilik Risiko</div>
        <?php if ($ttdPemilikSrc): ?>
        <img src="<?= htmlspecialchars($ttdPemilikSrc) ?>" class="ttd-img" alt="TTD Pemilik">
        <?php else: ?>
        <div class="ttd-spacer"></div>
        <?php endif; ?>
        <div class="ttd-nm"><?= htmlspecialchars($namaPemilik) ?></div>
        <div class="ttd-nip">NIP. <?= htmlspecialchars($nipPemilik) ?></div>
      </div>
      <div class="ttd-box">
        <div class="ttd-lbl">Pengelola Risiko</div>
        <?php if ($ttdPengelolaSrc): ?>
        <img src="<?= htmlspecialchars($ttdPengelolaSrc) ?>" class="ttd-img" alt="TTD Pengelola">
        <?php else: ?>
        <div class="ttd-spacer"></div>
        <?php endif; ?>
        <div class="ttd-nm"><?= htmlspecialchars($namaPengelola) ?></div>
        <div class="ttd-nip">NIP. <?= htmlspecialchars($nipPengelola) ?></div>
      </div>
    </div>
    <?php if ($isConsolidated): ?>
    <div class="ttd-approver">
      <strong>Mengetahui dan Menyetujui,</strong>
      <div style="height:60px"></div>
      <strong><?= htmlspecialchars($approval['nama'] ?? '_________________________') ?></strong><br>
      NIP. <?= htmlspecialchars($approval['nip'] ?? '_________________________') ?>
    </div>
    <?php endif; ?>
</body></html>
