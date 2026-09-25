<?php
/**
 * MODUL Konsolidasi Monev — Premium UX Redesign
 * Gabungan monev seluruh user dan unit kerja.
 * Fitur: insight banner + approval status,
 * tabel terstruktur dengan badge & delta, filter unit, pagination.
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
requireRole('Admin', 'Risk Manager', 'Pimpinan', 'Koordinator');
$db = getDB();

$tahun = trim((string)($_GET['tahun'] ?? date('Y')));
$tahunList = getDaftarTahun($db, 'kkpr_header', [$tahun]);
if (!in_array($tahun, $tahunList, true) && !empty($tahunList)) {
    $tahun = $tahunList[0];
}
$jenis = $_GET['jenis'] ?? 'tw1';
if (!in_array($jenis, ['tw1', 'tw2', 'tw3', 'tw4', 'tahunan'], true)) $jenis = 'tw1';
$tw = $jenis === 'tahunan' ? 4 : (int)substr($jenis, 2);
$jenisLabel = $jenis === 'tahunan' ? 'Monev 1 Tahun' : 'Monev Triwulan ' . $tw;
$fQ = trim($_GET['q'] ?? '');

$searchSql = '';
if ($fQ !== '') {
    $searchSql = ' AND (r.nama_risiko LIKE ? OR r.kode_risiko LIKE ? OR h.unit_pemilik_risiko LIKE ?)';
}
$s = $db->prepare("SELECT r.*, h.unit_pemilik_risiko FROM kkpr_risiko r JOIN kkpr_header h ON h.id=r.id_kkpr WHERE h.tahun=? $searchSql ORDER BY h.unit_pemilik_risiko, h.id, r.no_urut");
if ($fQ !== '') {
    $qParam = "%{$fQ}%";
    $s->bind_param('ssss', $tahun, $qParam, $qParam, $qParam);
} else {
    $s->bind_param('s', $tahun);
}
$s->execute(); $risks = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();

$m = $db->prepare("SELECT m.* FROM monev_triwulan m JOIN kkpr_risiko r ON r.id=m.id_risiko JOIN kkpr_header h ON h.id=r.id_kkpr WHERE h.tahun=? AND m.triwulan=?");
$m->bind_param('si', $tahun, $tw); $m->execute(); $curr = [];
foreach ($m->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $curr[$row['id_risiko']] = $row; $m->close();

// Approval via prepared statement (sebelumnya raw query)
$aps = $db->prepare("SELECT ka.*, u.nama, u.nip FROM konsolidasi_approval ka LEFT JOIN users u ON u.id=ka.approved_by WHERE ka.tahun=? LIMIT 1");
$aps->bind_param('s', $tahun); $aps->execute();
$approval = $aps->get_result()->fetch_assoc(); $aps->close();

$mkLevel = function ($v) { return $v >= 20 ? 'Sangat Tinggi' : ($v >= 15 ? 'Tinggi' : ($v >= 10 ? 'Sedang' : ($v >= 5 ? 'Rendah' : 'Sangat Rendah'))); };

// ── Statistik: eskalasi/penurunan dihitung dari delta nilai (fix bug string mismatch) ──
$statMonev = ['total' => count($risks), 'sudah_pantau' => 0, 'efektif' => 0, 'tidak_efektif' => 0, 'eskalasi' => 0, 'penurunan' => 0];
$avgBaseline = 0;
$avgCurrent = 0;
$unitStats = []; // per unit: efektif / tidak efektif / belum dipantau

foreach ($risks as $r) {
    $u = trim((string)($r['unit_pemilik_risiko'] ?? ''));
    if (!isset($unitStats[$u])) $unitStats[$u] = ['total' => 0, 'efektif' => 0, 'tidak' => 0, 'belum' => 0];
    $unitStats[$u]['total']++;

    $c = $curr[$r['id']] ?? null;
    if (!$c) { $unitStats[$u]['belum']++; continue; }

    $statMonev['sudah_pantau']++;
    // Baseline = penilaian awal (kolom awal kkpr_risiko, bukan pantau_* KKPMR)
    $vBase = (float)($r['nilai_risiko'] ?? 0);
    $vCurr = (float)($c['pantau_nilai'] ?? 0);
    $avgBaseline += $vBase;
    $avgCurrent += $vCurr;

    if (($c['efektifitas'] ?? '') === 'Efektif') { $statMonev['efektif']++; $unitStats[$u]['efektif']++; }
    else { $statMonev['tidak_efektif']++; $unitStats[$u]['tidak']++; }

    if ($vCurr > $vBase) $statMonev['eskalasi']++;
    elseif ($vCurr < $vBase) $statMonev['penurunan']++;
}
$pctEfektif = $statMonev['sudah_pantau'] > 0 ? round($statMonev['efektif'] / $statMonev['sudah_pantau'] * 100) : 0;
$pctEskalasi = $statMonev['sudah_pantau'] > 0 ? round($statMonev['eskalasi'] / $statMonev['sudah_pantau'] * 100) : 0;
$pctPenurunan = $statMonev['sudah_pantau'] > 0 ? round($statMonev['penurunan'] / $statMonev['sudah_pantau'] * 100) : 0;
$pctTerisi = $statMonev['total'] > 0 ? round($statMonev['sudah_pantau'] / $statMonev['total'] * 100) : 0;
$avgBaseFinal = $statMonev['sudah_pantau'] > 0 ? round($avgBaseline / $statMonev['sudah_pantau'], 2) : 0;
$avgCurrFinal = $statMonev['sudah_pantau'] > 0 ? round($avgCurrent / $statMonev['sudah_pantau'], 2) : 0;
$selisihAvg = round($avgBaseFinal - $avgCurrFinal, 2);

// Helper render
$levelPill = function (?string $level): string {
    if ($level === null || $level === '') return '<span style="color:var(--text-muted)">–</span>';
    $map = ['Sangat Tinggi' => 'lp-st', 'Tinggi' => 'lp-t', 'Sedang' => 'lp-s', 'Rendah' => 'lp-r', 'Sangat Rendah' => 'lp-sr'];
    return '<span class="monev-level-pill ' . ($map[$level] ?? 'lp-s') . '">' . xss($level) . '</span>';
};
$deltaBadge = function ($delta): string {
    if ($delta === null) return '<span style="color:var(--text-muted)">–</span>';
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

// Status approval untuk banner
$appStatus = $approval['status'] ?? null;
$appMeta = null;
if ($appStatus === 'disetujui') {
    $appMeta = ['cls' => 'av-ok', 'icon' => 'fa-circle-check', 'title' => 'Laporan Disetujui',
                'sub' => 'Disetujui oleh ' . ($approval['nama'] ?? '-') . ' pada ' . ($approval['approved_at'] ? date('d/m/Y H:i', strtotime($approval['approved_at'])) : '-') . ($approval['catatan'] ? ' — "' . xss($approval['catatan']) . '"' : '')];
} elseif ($appStatus === 'ditolak') {
    $appMeta = ['cls' => 'av-no', 'icon' => 'fa-circle-xmark', 'title' => 'Laporan Ditolak',
                'sub' => 'Ditolak oleh ' . ($approval['nama'] ?? '-') . ($approval['catatan'] ? ' — "' . xss($approval['catatan']) . '"' : '')];
} elseif ($appStatus === 'pending') {
    $appMeta = ['cls' => 'av-wait', 'icon' => 'fa-clock', 'title' => 'Menunggu Persetujuan', 'sub' => 'Laporan belum disetujui pimpinan'];
} else {
    $appMeta = ['cls' => 'av-neutral', 'icon' => 'fa-hourglass-half', 'title' => 'Belum Diajukan', 'sub' => 'Approval laporan konsolidasi tahun ini belum diproses'];
}
?>
<div class="risiko-hero profil-risiko-hero" style="background:linear-gradient(115deg,#0f766e 0%,#0d9488 50%,#0369a1 100%); align-items: flex-start !important;">
  <div class="risiko-hero-copy">
    <div class="risiko-eyebrow"><i class="fas fa-layer-group"></i> Monitoring &amp; Evaluasi</div>
    <h1 class="page-title" style="color:#fff">Konsolidasi Monev</h1>
    <p class="page-sub" style="color:rgba(255,255,255,.8)">Gabungan monev seluruh unit kerja — data terisi otomatis dari pengisian monev tiap unit</p>
  </div>
  <div class="profil-hero-tools risiko-hero-tools-align">
    <select class="form-control hero-year-select wide" onchange="if(this.value) window.location.href='<?= APP_URL ?>/?page=monev_konsolidasi&tahun=<?= urlencode($tahun) ?>&jenis='+this.value" aria-label="Pilih periode">
      <?php foreach (['tw1' => 'Triwulan 1', 'tw2' => 'Triwulan 2', 'tw3' => 'Triwulan 3', 'tw4' => 'Triwulan 4', 'tahunan' => 'Tahunan'] as $segKey => $segLbl): ?>
      <option value="<?= $segKey ?>" <?= $jenis === $segKey ? 'selected' : '' ?>><?= $segLbl ?></option>
      <?php endforeach; ?>
    </select>
    <select class="form-control hero-year-select" style="max-width:130px;width:auto;text-align:center;text-align-last:center;" onchange="if(this.value) window.location.href='<?= APP_URL ?>/?page=monev_konsolidasi&tahun='+encodeURIComponent(this.value)+'&jenis=<?= $jenis ?>'" aria-label="Pilih tahun">
      <?php foreach ($tahunList as $y): ?>
        <option value="<?= xss($y) ?>" <?= $tahun === $y ? 'selected' : '' ?> style="text-align:center;"><?= xss($y) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="risiko-export-actions" style="margin-top:0">
        <a class="btn btn-hero-ghost" href="<?= APP_URL ?>/?page=monev_konsolidasi&tahun=<?= urlencode($tahun) ?>&jenis=<?= urlencode($jenis) ?>&konsolidasi=1&type=excel"><i class="fas fa-file-excel"></i> Excel</a>
        <select class="form-control hero-year-select" style="width: 155px !important; max-width: 155px !important; padding: 0 24px 0 14px !important; text-align-last: center !important;" onchange="if(this.value){window.open('<?= APP_URL ?>/?page=monev_konsolidasi&tahun=<?= urlencode($tahun) ?>&konsolidasi=1&type=pdf&jenis='+encodeURIComponent(this.value),'_blank');this.selectedIndex=0;}" aria-label="Cetak Laporan" title="Cetak Laporan per triwulan / tahunan">
          <option value="">&#128196; Cetak / PDF</option>
          <option value="tw1" style="text-align: left;">Laporan Triwulan I</option>
          <option value="tw2" style="text-align: left;">Laporan Triwulan II</option>
          <option value="tw3" style="text-align: left;">Laporan Triwulan III</option>
          <option value="tw4" style="text-align: left;">Laporan Triwulan IV</option>
          <option value="tahunan" style="text-align: left;">Laporan Tahunan</option>
        </select>
      </div>
  </div>

  <!-- Stat cards: klik untuk filter -->
  <div class="stats-grid" style="width:100%;margin-top:20px;margin-bottom:0">
    <div class="stat-card stat-card-glass monev-filter-card" data-filter="all" style="--ga:#60a5fa;--ga-tint:rgba(96,165,250,.3);--ga-line:rgba(96,165,250,.45);--ga-glow:rgba(96,165,250,.3)">
      <div class="stat-icon"><i class="fas fa-clipboard-list"></i></div>
      <div class="stat-content">
        <div class="stat-value" data-count="<?= $statMonev['total'] ?>">0</div>
        <div class="stat-label">Total Risiko</div>
      </div>
    </div>
    <div class="stat-card stat-card-glass monev-filter-card" data-filter="terisi" style="--ga:#a78bfa;--ga-tint:rgba(167,139,250,.3);--ga-line:rgba(167,139,250,.45);--ga-glow:rgba(167,139,250,.3)">
      <div class="stat-icon"><i class="fas fa-search"></i></div>
      <div class="stat-content">
        <div class="stat-value" data-count="<?= $statMonev['sudah_pantau'] ?>">0</div>
        <div class="stat-label">Sudah Dipantau</div>
      </div>
    </div>
    <div class="stat-card stat-card-glass monev-filter-card" data-filter="efektif" style="--ga:#4ade80;--ga-tint:rgba(74,222,128,.28);--ga-line:rgba(74,222,128,.45);--ga-glow:rgba(74,222,128,.28)">
      <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
      <div class="stat-content">
        <div class="stat-value" data-count="<?= $pctEfektif ?>" data-suffix="%">0</div>
        <div class="stat-label">Efektif (<?= $statMonev['efektif'] ?>)</div>
      </div>
    </div>
    <div class="stat-card stat-card-glass monev-filter-card" data-filter="eskalasi" style="--ga:#f87171;--ga-tint:rgba(248,113,113,.28);--ga-line:rgba(248,113,113,.5);--ga-glow:rgba(248,113,113,.32)">
      <div class="stat-icon"><i class="fas fa-arrow-up"></i></div>
      <div class="stat-content">
        <div class="stat-value" data-count="<?= $pctEskalasi ?>" data-suffix="%">0</div>
        <div class="stat-label">Eskalasi (<?= $statMonev['eskalasi'] ?>)</div>
      </div>
    </div>
    <div class="stat-card stat-card-glass monev-filter-card" data-filter="penurunan" style="--ga:#38bdf8;--ga-tint:rgba(56,189,248,.28);--ga-line:rgba(56,189,248,.45);--ga-glow:rgba(56,189,248,.28)">
      <div class="stat-icon"><i class="fas fa-arrow-down"></i></div>
      <div class="stat-content">
        <div class="stat-value" data-count="<?= $pctPenurunan ?>" data-suffix="%">0</div>
        <div class="stat-label">Penurunan (<?= $statMonev['penurunan'] ?>)</div>
      </div>
    </div>
  </div>
</div>

<?php if ($statMonev['total'] > 0): ?>
<!-- Insight banner dinamis (di luar hero) -->
<?php
  $insCls = ''; $insIcon = 'fa-circle-info';
  if ($statMonev['eskalasi'] > 0) { $insCls = 'bad'; $insIcon = 'fa-triangle-exclamation'; }
  elseif ($statMonev['sudah_pantau'] < $statMonev['total']) { $insCls = 'warn'; $insIcon = 'fa-clock'; }
  else { $insIcon = 'fa-circle-check'; }
?>
<div class="monev-insight <?= $insCls ?>" style="margin-top:18px">
  <i class="fas <?= $insIcon ?>"></i>
  <div class="ins-text">
    <?php if ($statMonev['eskalasi'] > 0): ?>
      <strong><?= $statMonev['eskalasi'] ?> risiko mengalami eskalasi skor</strong> pada <?= xss($jenisLabel) ?> — koordinasikan evaluasi ulang pengendalian dengan unit terkait.
    <?php elseif ($statMonev['sudah_pantau'] < $statMonev['total']): ?>
      <strong><?= $statMonev['total'] - $statMonev['sudah_pantau'] ?> dari <?= $statMonev['total'] ?> risiko belum dipantau</strong> pada periode ini.
    <?php else: ?>
      <strong>Semua risiko telah dipantau</strong> — <?= $pctEfektif ?>% pengendalian efektif.
    <?php endif; ?>
    <?php if ($statMonev['sudah_pantau'] > 0): ?>
      Rata-rata skor konsolidasi: <strong><?= $avgBaseFinal ?></strong> &rarr; <strong><?= $avgCurrFinal ?></strong> (<strong><?= $selisihAvg > 0 ? '&darr; ' . abs($selisihAvg) : ($selisihAvg < 0 ? '&uarr; ' . abs($selisihAvg) : '= 0') ?></strong>).
    <?php endif; ?>
  </div>
</div>

<!-- Progress kelengkapan periode (di luar hero) -->
<div class="monev-progress-wrap">
  <div class="monev-progress-head">
    <span><i class="fas fa-list-check"></i> Kelengkapan <?= xss($jenisLabel) ?> — <?= $statMonev['sudah_pantau'] ?>/<?= $statMonev['total'] ?> risiko terisi</span>
    <span class="pct"><?= $pctTerisi ?>%</span>
  </div>
  <div class="monev-progress"><div class="monev-progress-bar <?= $pctTerisi < 40 ? 'pct-low' : ($pctTerisi < 75 ? 'pct-mid' : '') ?>" data-target="<?= $pctTerisi ?>"></div></div>
</div>
<?php endif; ?>

<!-- Status approval -->
<div class="monev-approval-banner">
  <div class="av-icon <?= $appMeta['cls'] ?>"><i class="fas <?= $appMeta['icon'] ?>"></i></div>
  <div class="av-body">
    <div class="av-title"><i class="fas fa-file-signature"></i> Status Laporan Konsolidasi Tahun <?= xss($tahun) ?> — <?= xss($appMeta['title']) ?></div>
    <div class="av-sub"><?= $appMeta['sub'] ?></div>
  </div>
  <span class="badge <?= $appStatus === 'disetujui' ? 'badge-success' : ($appStatus === 'ditolak' ? 'badge-danger' : ($appStatus === 'pending' ? 'badge-warning' : 'badge-secondary')) ?>">
    <?= xss($appMeta['title']) ?>
  </span>
</div>

<!-- Tabel konsolidasi -->
<div class="card" id="monev-table" style="scroll-margin-top:calc(var(--navbar-h,64px) + 12px)">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px">
    <div>
      <span class="card-title"><i class="fas fa-table"></i> <?= xss($jenisLabel) ?> Tahun <?= xss($tahun) ?></span>
      <span style="font-size:.72rem;color:var(--text-muted);display:block;margin-top:2px"><i class="fas fa-circle-info"></i> <?= count($risks) ?> risiko terkonsolidasi lintas unit &bull; terisi otomatis dari monev unit (tanpa input manual)</span>
    </div>
    <div class="monev-toolbar">
      <span class="monev-chip" id="monevFilterChip" style="display:none" onclick="clearMonevFilter()" title="Klik untuk hapus filter"><i class="fas fa-filter"></i> <span id="monevFilterChipText"></span> <i class="fas fa-times-circle"></i></span>
      <div class="search-bar">
        <i class="fas fa-search"></i>
        <input type="text" class="form-control" id="searchMonevKonsol" placeholder="Cari nama/unit..." style="height:38px">
      </div>
      <div class="datatable-dropdown" style="margin:0;display:flex;align-items:center">
        <select class="datatable-selector" id="limitMonevKonsol">
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
    <table class="data-table profil-detail-table monev-data-table no-datatable" id="tableMonevKonsol">
      <colgroup>
        <col style="width:36px">
        <col style="width:72px">
        <col style="min-width:160px">
        <col style="min-width:220px">
        <col style="min-width:110px">
        <col style="min-width:110px">
        <col style="width:80px">
        <col style="width:105px">
        <col style="width:105px">
      </colgroup>
      <thead>
        <tr>
          <th style="width:36px;text-align:center">No</th>
          <th style="width:72px;text-align:center">Kode</th>
          <th style="min-width:160px">Unit Kerja</th>
          <th style="min-width:220px">Nama Risiko</th>
          <th style="text-align:center;min-width:110px">Kondisi Awal</th>
          <th style="text-align:center;min-width:110px">Kondisi Saat Ini</th>
          <th style="width:80px;text-align:center">Perubahan</th>
          <th style="width:105px;text-align:center">Simpulan</th>
          <th style="width:105px;text-align:center">Efektivitas</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($risks)): ?>
        <tr><td colspan="9" style="text-align:center;padding:36px;color:var(--text-muted)">Belum ada data risiko untuk tahun <?= xss($tahun) ?>.</td></tr>
        <?php endif; ?>
        <?php foreach ($risks as $i => $r):
          $c = $curr[$r['id']] ?? null;
          // Baseline = penilaian awal (konsisten dengan simpulan/efektivitas yang tersimpan)
          $vBase = (float)($r['nilai_risiko'] ?? 0);
          $vCurr = $c ? (float)($c['pantau_nilai'] ?? 0) : null;
          $delta = ($vCurr !== null) ? $vBase - $vCurr : null;
          $baseP = $r['probabilitas'] ?? null;
          $baseD = $r['dampak_level'] ?? null;
          $baseTingkat = $r['tingkat_risiko'] ?? null;
          if ($baseTingkat === null && $vBase > 0) $baseTingkat = $mkLevel($vBase);
          $searchIdx = mb_strtolower(($r['unit_pemilik_risiko'] ?? '') . ' ' . ($r['nama_risiko'] ?? '') . ' ' . ($r['kode_risiko'] ?? ''));
        ?>
        <tr class="monev-row"
            data-unit="<?= xss($r['unit_pemilik_risiko'] ?? '') ?>"
            data-search="<?= xss($searchIdx) ?>"
            data-terisi="<?= $c ? 1 : 0 ?>"
            data-efektif="<?= ($c && ($c['efektifitas'] ?? '') === 'Efektif') ? 1 : 0 ?>"
            data-naik="<?= ($delta !== null && $delta < 0) ? 1 : 0 ?>"
            data-turun="<?= ($delta !== null && $delta > 0) ? 1 : 0 ?>">
          <td style="text-align:center;font-weight:600;color:var(--text-muted)"><?= $i + 1 ?></td>
          <td style="text-align:center;white-space:nowrap"><span class="badge-kode-risiko"><?= xss($r['kode_risiko'] ?? '-') ?></span></td>
          <td>
            <div style="font-weight:500;font-size:.74rem;display:flex;align-items:flex-start;gap:4px">
              <i class="fas fa-building" style="font-size:.68rem;color:var(--text-muted);opacity:.75;margin-top:2px"></i>
              <span><?= xss($r['unit_pemilik_risiko'] ?? '') ?></span>
            </div>
          </td>
          <td><div style="font-weight:600;color:var(--text-main);line-height:1.35"><?= xss($r['nama_risiko']) ?></div></td>
          <td>
            <div class="monev-kondisi">
              <div class="pd">P <b><?= xss($baseP ?? '-') ?></b> &middot; D <b><?= xss($baseD ?? '-') ?></b></div>
              <div class="nilai"><?= $vBase > 0 ? round($vBase) : '–' ?></div>
              <?php if ($baseTingkat): ?><div><?= $levelPill((string)$baseTingkat) ?></div><?php endif; ?>
            </div>
          </td>
          <?php if ($c): ?>
          <td>
            <div class="monev-kondisi">
              <div class="pd">P <b><?= (int)$c['pantau_p'] ?></b> &middot; D <b><?= (int)$c['pantau_d'] ?></b></div>
              <div class="nilai"><?= round((float)$c['pantau_nilai']) ?></div>
              <div><?= $levelPill($c['pantau_tingkat'] ?? null) ?></div>
            </div>
          </td>
          <?php else: ?>
          <td><span style="color:var(--text-muted)">belum dipantau</span></td>
          <?php endif; ?>
          <td style="text-align:center"><?= $deltaBadge($delta) ?></td>
          <td style="text-align:center"><?= $simpulanBadge($c['simpulan_tingkat'] ?? null) ?></td>
          <td style="text-align:center"><?= $efektifBadge($c['efektifitas'] ?? null) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="monev-pagination">
    <span class="pagination-info" id="konsolPageInfo">Memuat…</span>
    <div class="pagination" id="konsolPages" style="margin:0;gap:5px"></div>
  </div>
</div>

<!-- Kartu tanda tangan -->
<div class="monev-sign-card">
  <div style="font-weight:700;font-size:.85rem;color:var(--text)">Mengetahui dan Menyetujui,</div>
  <div style="font-size:.72rem;color:var(--text-muted);margin-top:2px"><?= xss($jenisLabel) ?> — Tahun <?= xss($tahun) ?></div>
  <div class="sign-space"></div>
  <div class="sign-name"><?= xss($approval['nama'] ?? '_________________________') ?></div>
  <div class="sign-nip">NIP. <?= xss($approval['nip'] ?? '_________________________') ?></div>
  <?php if ($appStatus === 'disetujui' && !empty($approval['approved_at'])): ?>
  <div style="margin-top:10px"><span class="badge badge-success"><i class="fas fa-circle-check"></i> Disetujui <?= date('d/m/Y', strtotime($approval['approved_at'])) ?></span></div>
  <?php endif; ?>
</div>

<script>
(() => {
  // ── Count-up stat cards + progress bar (tanpa grafik) ──────
  function initKonsol() {
    document.querySelectorAll('.stat-value[data-count]').forEach(el => {
      const target = parseFloat(el.dataset.count) || 0;
      const suffix = el.dataset.suffix || '';
      if (target === 0) { el.textContent = '0' + suffix; return; }
      const dur = 900, t0 = performance.now();
      const step = now => {
        const p = Math.min((now - t0) / dur, 1);
        el.textContent = Math.round(target * (1 - Math.pow(1 - p, 3))) + suffix;
        if (p < 1) requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
    });
    setTimeout(() => {
      document.querySelectorAll('.monev-progress-bar').forEach(bar => { bar.style.width = bar.dataset.target + '%'; });
    }, 150);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initKonsol);
  } else {
    initKonsol();
  }
})();
</script>

<script>
// ── Filter + pagination tabel konsolidasi ────────────────────
const konsolState = { q: '', unit: '', filter: 'all', page: 1, size: 10 };
const konsolFilterLabels = { terisi: 'Sudah dipantau', efektif: 'Efektif', eskalasi: 'Eskalasi', penurunan: 'Penurunan' };

function applyKonsolTable() {
  const allRows = [...document.querySelectorAll('#tableMonevKonsol tbody tr.monev-row')];
  const visible = allRows.filter(tr => {
    if (konsolState.q && !tr.dataset.search.includes(konsolState.q)) return false;
    if (konsolState.unit && tr.dataset.unit !== konsolState.unit) return false;
    switch (konsolState.filter) {
      case 'terisi': return tr.dataset.terisi === '1';
      case 'efektif': return tr.dataset.efektif === '1';
      case 'eskalasi': return tr.dataset.naik === '1';
      case 'penurunan': return tr.dataset.turun === '1';
      default: return true;
    }
  });
  const total = visible.length;
  const pages = Math.max(1, Math.ceil(total / konsolState.size));
  if (konsolState.page > pages) konsolState.page = pages;
  const start = (konsolState.page - 1) * konsolState.size;

  allRows.forEach(tr => { tr.style.display = 'none'; });
  visible.slice(start, start + konsolState.size).forEach(tr => { tr.style.display = ''; });

  document.getElementById('konsolPageInfo').textContent = total === 0
    ? 'Tidak ada data yang cocok dengan filter'
    : 'Menampilkan ' + (start + 1) + '–' + Math.min(start + konsolState.size, total) + ' dari ' + total + ' risiko';

  const pagesEl = document.getElementById('konsolPages');
  pagesEl.innerHTML = '';
  const mkBtn = (html, page, disabled, active, title) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'page-btn' + (active ? ' active' : '');
    b.innerHTML = html;
    b.disabled = disabled;
    if (title) b.title = title;
    if (!disabled) b.onclick = () => { konsolState.page = page; applyKonsolTable(); };
    pagesEl.appendChild(b);
  };
  mkBtn('<i class="fas fa-angles-left"></i>', 1, konsolState.page <= 1, false, 'Halaman Pertama');
  mkBtn('<i class="fas fa-chevron-left"></i>', konsolState.page - 1, konsolState.page <= 1, false, 'Halaman Sebelumnya');
  const winStart = Math.max(1, Math.min(konsolState.page - 2, pages - 4));
  const winEnd = Math.min(pages, winStart + 4);
  for (let p = winStart; p <= winEnd; p++) mkBtn(String(p), p, false, p === konsolState.page);
  mkBtn('<i class="fas fa-chevron-right"></i>', konsolState.page + 1, konsolState.page >= pages, false, 'Halaman Berikutnya');
  mkBtn('<i class="fas fa-angles-right"></i>', pages, konsolState.page >= pages, false, 'Halaman Terakhir');
}

function setMonevFilter(f) {
  konsolState.filter = f;
  konsolState.page = 1;
  document.querySelectorAll('.monev-filter-card').forEach(c => {
    c.classList.toggle('active', c.dataset.filter === f);
  });
  const chip = document.getElementById('monevFilterChip');
  if (f === 'all') {
    chip.style.display = 'none';
  } else {
    chip.style.display = '';
    document.getElementById('monevFilterChipText').textContent = konsolFilterLabels[f] || f;
  }
  applyKonsolTable();
}
function clearMonevFilter() { setMonevFilter('all'); }

document.addEventListener('DOMContentLoaded', () => {
  const scrollToMonevTable = () => {
    const t = document.getElementById('monev-table');
    if (t) t.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };
  document.querySelectorAll('.monev-filter-card').forEach(card => {
    // Kartu statistik = tombol filter: klik/Enter/Spasi memfilter lalu menggulir ke data.
    card.setAttribute('role', 'button');
    if (!card.hasAttribute('tabindex')) card.setAttribute('tabindex', '0');
    const activate = () => {
      setMonevFilter(card.dataset.filter === konsolState.filter ? 'all' : card.dataset.filter);
      scrollToMonevTable();
    };
    card.addEventListener('click', activate);
    card.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); activate(); }
    });
  });
  const searchEl = document.getElementById('searchMonevKonsol');
  let debounce;
  searchEl.addEventListener('input', () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
      konsolState.q = searchEl.value.trim().toLowerCase();
      konsolState.page = 1;
      applyKonsolTable();
    }, 200);
  });
  document.getElementById('limitMonevKonsol').addEventListener('change', function () {
    konsolState.size = parseInt(this.value, 10) || 10;
    konsolState.page = 1;
    applyKonsolTable();
  });
  applyKonsolTable();
});
</script>
