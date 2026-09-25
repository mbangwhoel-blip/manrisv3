<?php
/**
 * MODUL KERTAS KERJA VERIFIKASI CAPAIAN TARGET TAHUNAN RENSTRA & PERMASALAHAN (IKK)
 * Format: V6 KK-identifikasi Risiko IKK Kemenkes
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$db = getDB();

// ── Export Excel ──────────────────────────────────────────────────────────
$exportType = $_GET['export'] ?? '';
$exportTahun = trim($_GET['tahun'] ?? date('Y'));

if ($exportType === 'excel') {
    $sExp = $db->prepare("SELECT * FROM ikk WHERE tahun=? ORDER BY no_urut, id");
    $sExp->bind_param('s', $exportTahun); $sExp->execute();
    $expRows = $sExp->get_result()->fetch_all(MYSQLI_ASSOC); $sExp->close();
    $headers = ['No', 'Tahun', 'Tujuan', 'Sasaran', 'Indikator Kinerja', 'Target', 'Penanggung Jawab', 'Keterangan'];
    $excelData = [];
    foreach ($expRows as $i => $r) {
        $excelData[] = [
            $i + 1,
            $r['tahun'] ?? '',
            $r['tujuan'] ?? '',
            $r['sasaran'] ?? '',
            $r['indikator_kinerja'] ?? '',
            $r['target'] ?? '',
            $r['penanggung_jawab'] ?? '',
            $r['keterangan'] ?? '',
        ];
    }
    exportExcel('IKK_' . $exportTahun . '_' . date('Ymd_His'), $headers, $excelData);
}

// ── Export PDF ────────────────────────────────────────────────────────────
if ($exportType === 'pdf') {
    $sExp = $db->prepare("SELECT * FROM ikk WHERE tahun=? ORDER BY no_urut, id");
    $sExp->bind_param('s', $exportTahun); $sExp->execute();
    $expRows = $sExp->get_result()->fetch_all(MYSQLI_ASSOC); $sExp->close();
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Kertas Kerja IKK <?= xss($exportTahun) ?></title>
<style>
  body{font-family:Arial,sans-serif;font-size:9px;color:#1e293b;margin:10mm}
  h2{font-size:12px;text-align:center;margin-bottom:4px;color:#1e3a5f}
  p.sub{text-align:center;font-size:9px;color:#64748b;margin-bottom:12px}
  table{width:100%;border-collapse:collapse;font-size:8.5px}
  th{background:#1e3a5f;color:#fff;font-weight:700;padding:5px 6px;text-align:center;border:1px solid #cbd5e1}
  td{border:1px solid #cbd5e1;padding:4px 6px;vertical-align:top}
  tr:nth-child(even) td{background:#f8fafc}
  td.center{text-align:center}
  .footer{margin-top:12px;font-size:8px;color:#64748b;text-align:center;border-top:1px solid #e2e8f0;padding-top:6px}
  .print-bar{display:flex;gap:10px;align-items:center;padding:8px 14px;background:#eff6ff;border:1px dashed #93c5fd;border-radius:6px;margin-bottom:14px;font-size:12px}
  .btn-print{padding:7px 16px;background:#1e3a5f;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:12px;font-weight:700}
  @page{size:A4 landscape;margin:10mm}
  @media print{.print-bar{display:none!important}}
</style>
</head>
<body>
  <div class="print-bar">
    <button class="btn-print" onclick="window.print()">&#128196; Cetak / PDF</button>
    <span style="color:#1d4ed8"><strong>Ctrl+P</strong> → Ukuran kertas: <strong>A4 Landscape</strong></span>
  </div>
  <h2>KERTAS KERJA VERIFIKASI CAPAIAN TARGET TAHUNAN RENSTRA</h2>
  <p class="sub">Tahun <?= xss($exportTahun) ?> &nbsp;|&nbsp; Dicetak: <?= date('d M Y H:i') ?></p>
  <table>
    <thead>
      <tr>
        <th style="width:30px">No</th>
        <th style="width:40px">Tahun</th>
        <th>Tujuan</th>
        <th>Sasaran</th>
        <th>Indikator Kinerja</th>
        <th>Target</th>
        <th style="width:80px">Penanggung Jawab</th>
        <th>Keterangan</th>
      </tr>
    </thead>
    <tbody>
    <?php if(empty($expRows)): ?>
      <tr><td colspan="8" class="center" style="color:#64748b;padding:16px">Belum ada data IKK tahun <?= xss($exportTahun) ?></td></tr>
    <?php else: ?>
      <?php foreach($expRows as $i => $r): ?>
      <tr>
        <td class="center"><?= $i+1 ?></td>
        <td class="center"><?= xss($r['tahun']??'') ?></td>
        <td><?= xss($r['tujuan']??'-') ?></td>
        <td><?= xss($r['sasaran']??'-') ?></td>
        <td><?= xss($r['indikator_kinerja']??'-') ?></td>
        <td><?= xss($r['target']??'-') ?></td>
        <td><?= xss($r['penanggung_jawab']??'-') ?></td>
        <td><?= xss($r['keterangan']??'-') ?></td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
  <div class="footer">Sistem Informasi Manajemen Risiko (SEMAR) &nbsp;|&nbsp; <?= APP_NAME ?></div>
</body>
</html>
    <?php exit;
}

// ── Handle POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hasRole('Admin', 'Pimpinan', 'Risk Manager')) {
        setFlash('error', 'Anda tidak memiliki akses untuk mengelola data IKK.');
        header('Location: ' . APP_URL . '/?page=ikk'); exit;
    }
    if (!verifyCsrf()) { setFlash('error', 'Token tidak valid'); header('Location: ' . APP_URL . '/?page=ikk'); exit; }

    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'simpan') {
        $id   = (int)($_POST['id'] ?? 0);
        $uid  = (int)$_SESSION['user_id'];
        $f = [
            'tahun'              => trim($_POST['tahun'] ?? date('Y')),
            'no_urut'            => (int)($_POST['no_urut'] ?? 1),
            'tujuan'             => trim($_POST['tujuan'] ?? ''),
            'sasaran'            => trim($_POST['sasaran'] ?? ''),
            'indikator_kinerja'  => trim($_POST['indikator_kinerja'] ?? ''),
            'target'             => trim($_POST['target'] ?? ''),
            'penanggung_jawab'   => trim($_POST['penanggung_jawab'] ?? ''),
            'keterangan'         => trim($_POST['keterangan'] ?? ''),
        ];

        if (empty($f['tujuan']) && empty($f['sasaran']) && empty($f['indikator_kinerja'])) {
            setFlash('error', 'Minimal tujuan/sasaran/indikator wajib diisi');
            header('Location: ' . APP_URL . '/?page=ikk&tahun=' . urlencode($f['tahun']));
            exit;
        }

        if ($id > 0) {
            $s = $db->prepare('UPDATE ikk SET tahun=?,no_urut=?,tujuan=?,sasaran=?,indikator_kinerja=?,target=?,penanggung_jawab=?,keterangan=? WHERE id=?');
            $s->bind_param('sissssssi', $f['tahun'], $f['no_urut'], $f['tujuan'], $f['sasaran'], $f['indikator_kinerja'], $f['target'], $f['penanggung_jawab'], $f['keterangan'], $id);
            $s->execute(); $s->close();
            logAktivitas('UPDATE', 'ikk', $id, 'Update IKK tahun ' . $f['tahun']);
            setFlash('success', 'IKK berhasil diperbarui');
        } else {
            $s = $db->prepare('INSERT INTO ikk (tahun,no_urut,tujuan,sasaran,indikator_kinerja,target,penanggung_jawab,keterangan,created_by) VALUES (?,?,?,?,?,?,?,?,?)');
            $s->bind_param('sissssssi', $f['tahun'], $f['no_urut'], $f['tujuan'], $f['sasaran'], $f['indikator_kinerja'], $f['target'], $f['penanggung_jawab'], $f['keterangan'], $uid);
            $s->execute(); $newId = $db->insert_id; $s->close();
            logAktivitas('CREATE', 'ikk', $newId, 'Tambah IKK tahun ' . $f['tahun']);
            setFlash('success', 'IKK berhasil ditambahkan');
        }
        header('Location: ' . APP_URL . '/?page=ikk&tahun=' . urlencode($f['tahun']));
        exit;
    }

    if ($aksi === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        $db->query("DELETE FROM ikk WHERE id=$id");
        logAktivitas('DELETE', 'ikk', $id, 'Hapus IKK ID ' . $id);
        setFlash('success', 'Data IKK dihapus');
        header('Location: ' . APP_URL . '/?page=ikk' . (isset($_POST['tahun']) ? '&tahun=' . urlencode($_POST['tahun']) : ''));
        exit;
    }
}

// ── Data ─────────────────────────────────────────────────────────────────
$tahunAktif = trim((string)($_GET['tahun'] ?? date('Y')));
$tahunList  = getDaftarTahun($db, 'ikk', [$tahunAktif]);
if (!in_array($tahunAktif, $tahunList, true) && !empty($tahunList)) {
    $tahunAktif = $tahunList[0];
}

$rows = [];
if ($tahunAktif) {
    $s = $db->prepare("SELECT * FROM ikk WHERE tahun=? ORDER BY no_urut, id");
    $s->bind_param('s', $tahunAktif); $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
}

$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
    $se = $db->prepare('SELECT * FROM ikk WHERE id=?');
    $se->bind_param('i', $editId);
    $se->execute();
    $editRow = $se->get_result()->fetch_assoc();
    $se->close();
}
?>

<!-- Halaman Utama IKK -->
<?php
$ikTotal = count($rows);
$ikTahun = count($tahunList);
$ikPj = count(array_unique(array_filter(array_column($rows, 'penanggung_jawab'))));
?>
<div class="risiko-hero profil-risiko-hero" style="background:linear-gradient(115deg,#312e81 0%,#4338ca 55%,#4f46e5 100%); align-items: flex-start !important;">
  <div class="risiko-hero-copy">
    <div class="risiko-eyebrow"><i class="fas fa-clipboard-check"></i> Referensi Capaian Target</div>
    <h1 class="page-title">Kertas Kerja IKK</h1>
    <p class="page-sub">Verifikasi capaian target tahunan Renstra &amp; permasalahan — Indikator Kinerja Kegiatan.</p>
  </div>
  <div class="profil-hero-tools risiko-hero-tools-align">
    <div style="display:flex;align-items:flex-start;gap:8px;flex-wrap:wrap">
      <?php if(hasRole('Admin','Risk Manager','Pimpinan')): ?>
      <select class="form-control hero-year-select" style="max-width:130px;width:auto;text-align:center;text-align-last:center;" onchange="if(this.value) window.location.href='<?= APP_URL ?>/?page=ikk&tahun='+encodeURIComponent(this.value)" aria-label="Pilih tahun">
        <?php foreach($tahunList as $y): ?>
        <option value="<?= xss($y) ?>" <?= (string)$tahunAktif === (string)$y ? 'selected' : '' ?> style="text-align:center;"><?= xss($y) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <div class="risiko-export-actions" style="margin-top:0">
        <a href="<?= APP_URL ?>/?page=ikk&tahun=<?= urlencode($tahunAktif) ?>&export=excel" class="btn btn-hero-ghost"><i class="fas fa-file-excel"></i> Excel</a>
        <a href="<?= APP_URL ?>/?page=ikk&tahun=<?= urlencode($tahunAktif) ?>&export=pdf" target="_blank" class="btn btn-hero-ghost">&#128196; Cetak / PDF</a>
      </div>
      <?php if(hasRole('Admin','Risk Manager')): ?>
      <div class="risiko-hero-actions" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
        <button class="btn btn-hero-primary" onclick="openModal('modalIkk')" style="white-space:nowrap; margin:0;"><i class="fas fa-plus"></i> Tambah IKK</button>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Stat cards (glassmorphism inside hero) -->
  <div class="stats-grid cols-3" style="width:100%;margin-top:20px;margin-bottom:0">
    <div class="stat-card stat-card-glass" style="--ga:#60a5fa;--ga-tint:rgba(96,165,250,.3);--ga-line:rgba(96,165,250,.45);--ga-glow:rgba(96,165,250,.3)">
      <div class="stat-icon"><i class="fas fa-clipboard-check"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $ikTotal ?></div>
        <div class="stat-label">IKK Tahun <?= xss($tahunAktif) ?></div>
      </div>
    </div>
    <div class="stat-card stat-card-glass" style="--ga:#a78bfa;--ga-tint:rgba(167,139,250,.3);--ga-line:rgba(167,139,250,.45);--ga-glow:rgba(167,139,250,.3)">
      <div class="stat-icon"><i class="fas fa-calendar-days"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $ikTahun ?></div>
        <div class="stat-label">Jumlah Tahun</div>
      </div>
    </div>
    <div class="stat-card stat-card-glass" style="--ga:#38bdf8;--ga-tint:rgba(56,189,248,.28);--ga-line:rgba(56,189,248,.45);--ga-glow:rgba(56,189,248,.28)">
      <div class="stat-icon"><i class="fas fa-user-check"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $ikPj ?></div>
        <div class="stat-label">Penanggung Jawab</div>
      </div>
    </div>
  </div>
</div>

<div class="risiko-flow">
  <span class="risiko-flow-item"><span class="rf-num"><i class="fas fa-info" style="font-size:.7rem"></i></span><div><strong>IKK — Indikator Kinerja Kegiatan</strong><small>Catat tujuan, sasaran, indikator kinerja, dan target capaian tahunan berdasarkan Renstra</small></div></span>
</div>

<div class="card">
  <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <span class="card-title" style="margin:0;"><i class="fas fa-table"></i> Daftar IKK Tahun <?= xss($tahunAktif) ?> <span style="color:var(--text-muted);font-weight:400">(<?= count($rows) ?> data)</span></span>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;flex:1;justify-content:flex-end">
      <div class="search-bar" style="max-width:250px;width:100%">
        <i class="fas fa-search"></i>
        <input type="text" class="form-control" id="searchIkk" placeholder="Cari data IKK..." onkeyup="filterTableIkk()" style="height:38px">
      </div>
      <div class="datatable-dropdown" style="margin:0; display:flex; align-items:center;">
        <select class="datatable-selector" id="limitIkk" onchange="filterTableIkk()">
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
    <table class="data-table profil-detail-table ikk-data-table no-datatable" id="tableIkk">
      <colgroup><col class="col-no"><col class="col-wide"><col class="col-wide"><col class="col-indicator"><col class="col-target"><col class="col-person"><col class="col-note"><?php if(hasRole('Admin','Risk Manager')): ?><col class="col-action"><?php endif; ?></colgroup>
      <thead>
        <tr>
          <th class="col-no" style="text-align:center">No</th>
          <th class="col-wide">Tujuan</th>
          <th class="col-wide">Sasaran</th>
          <th class="col-indicator">Indikator Kinerja</th>
          <th class="col-target">Target</th>
          <th class="col-person">Penanggung Jawab</th>
          <th class="col-note">Keterangan</th>
          <?php if(hasRole('Admin','Risk Manager')): ?><th class="col-action" style="text-align:center">Aksi</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php if(empty($rows)): ?>
        <tr><td colspan="<?= hasRole('Admin','Risk Manager') ? 8 : 7 ?>">
          <div class="empty-state" style="padding:32px">
            <i class="fas fa-clipboard-check" style="font-size:2rem;opacity:.35;margin-bottom:8px;display:block"></i>
            <h3 style="font-size:1rem;margin:0 0 4px">Belum ada data IKK tahun <?= xss($tahunAktif) ?></h3>
            <?php if(hasRole('Admin','Risk Manager')): ?>
            <p style="margin:4px 0 12px;color:var(--text-muted);font-size:.75rem">Klik "Tambah IKK" untuk menambahkan data.</p>
            <button class="btn btn-primary" onclick="openModal('modalIkk')"><i class="fas fa-plus"></i> Tambah IKK</button>
            <?php endif; ?>
          </div>
        </td></tr>
      <?php else: ?>
        <?php foreach($rows as $i => $r): ?>
        <tr>
          <td style="text-align:center;font-weight:600;color:var(--text-muted)"><?= $i+1 ?></td>
          <td><?= xss($r['tujuan']??'-') ?></td>
          <td><?= xss($r['sasaran']??'-') ?></td>
          <td><?= xss($r['indikator_kinerja']??'-') ?></td>
          <td><div style="font-weight:600;color:var(--text-main)"><?= xss($r['target']??'-') ?></div></td>
          <td>
            <div style="font-weight:500;font-size:.74rem;display:flex;align-items:flex-start;gap:4px">
              <i class="fas fa-user-tie" style="font-size:.68rem;color:var(--primary);opacity:.8;margin-top:2px;flex-shrink:0"></i>
              <span><?= xss($r['penanggung_jawab']??'-') ?></span>
            </div>
          </td>
          <td style="color:var(--text-muted)"><?= xss($r['keterangan']??'-') ?></td>
          <?php if(hasRole('Admin','Risk Manager')): ?>
          <td class="ikk-action-cell" style="text-align:center;white-space:nowrap">
            <div class="act-btn-group">
              <button class="act-btn act-btn-edit" onclick='editIkk(<?= htmlspecialchars(json_encode($r),ENT_QUOTES) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
              <form method="POST" style="display:inline"><?= csrfField() ?>
                <input type="hidden" name="aksi" value="hapus">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <input type="hidden" name="tahun" value="<?= xss($tahunAktif) ?>">
                <button type="submit" class="act-btn act-btn-delete" onclick="return confirm('Hapus IKK ini?')" title="Hapus"><i class="fas fa-trash"></i></button>
              </form>
            </div>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer" id="ikkPagination" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;padding:14px 20px;">
    <span class="pagination-info" id="ikkPageInfo" style="font-size:.76rem;color:var(--text-muted);font-weight:600;">Memuat...</span>
    <div class="pagination" id="ikkPages" style="margin:0;gap:5px;"></div>
  </div>
</div>

<!-- Modal Tambah/Edit -->
<div class="modal-overlay" id="modalIkk" style="display:none">
  <div class="modal modal-lg">
    <div class="modal-header">
      <h3 class="modal-title" id="modalIkkTitle"><i class="fas fa-plus-circle"></i> Tambah IKK Baru</h3>
      <button class="btn-close" onclick="closeModal('modalIkk')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/?page=ikk">
      <?= csrfField() ?>
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="id" id="fId" value="0">
      <div class="modal-body">
        <div class="form-row-2">
          <div class="form-group">
            <label class="form-label">Tahun <span class="required">*</span></label>
            <input type="text" name="tahun" id="fTahun" class="form-control" required value="<?= xss($tahunAktif) ?>" pattern="\d{4}" placeholder="mis. 2026" style="text-align: center;">
          </div>
          <div class="form-group">
            <label class="form-label">No. Urut</label>
            <input type="number" name="no_urut" id="fNo" class="form-control" min="1" value="<?= count($rows) + 1 ?>">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Tujuan</label>
          <textarea name="tujuan" id="fTujuan" class="form-control" rows="2" placeholder="Tujuan organisasi"></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Sasaran</label>
          <textarea name="sasaran" id="fSasaran" class="form-control" rows="2" placeholder="Sasaran strategis"></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Indikator Kinerja</label>
          <textarea name="indikator_kinerja" id="fIndikator" class="form-control" rows="2" placeholder="Indikator kinerja utama (IKU)"></textarea>
        </div>
        <div class="form-row-2">
          <div class="form-group">
            <label class="form-label">Target</label>
            <textarea name="target" id="fTarget" class="form-control" rows="2" placeholder="Target capaian"></textarea>
          </div>
          <div class="form-group">
            <label class="form-label">Penanggung Jawab</label>
            <input type="text" name="penanggung_jawab" id="fPj" class="form-control" placeholder="mis. ADUM, Timker 1, BKPK">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Keterangan</label>
          <textarea name="keterangan" id="fKet" class="form-control" rows="2" placeholder="Catatan / permasalahan"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" onclick="closeModal('modalIkk')" class="btn btn-outline">Batal</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan IKK</button>
      </div>
    </form>
  </div>
</div>

<script>
function editIkk(r) {
  document.getElementById('modalIkkTitle').innerHTML = '<i class="fas fa-edit"></i> Edit IKK — No. ' + r.no_urut;
  document.getElementById('fId').value = r.id;
  document.getElementById('fTahun').value = r.tahun || '';
  document.getElementById('fNo').value = r.no_urut || 1;
  document.getElementById('fTujuan').value = r.tujuan || '';
  document.getElementById('fSasaran').value = r.sasaran || '';
  document.getElementById('fIndikator').value = r.indikator_kinerja || '';
  document.getElementById('fTarget').value = r.target || '';
  document.getElementById('fPj').value = r.penanggung_jawab || '';
  document.getElementById('fKet').value = r.keterangan || '';
  openModal('modalIkk');
}

const ikkState = { page: 1 };
function filterTableIkk() {
  const query = (document.getElementById('searchIkk')?.value || '').toLowerCase();
  const limit = parseInt(document.getElementById('limitIkk')?.value || 10, 10);
  const allRows = [...document.querySelectorAll('#tableIkk tbody tr')];
  
  const visible = allRows.filter(row => {
    if(row.querySelector('.empty-state')) return false;
    const text = row.textContent.toLowerCase();
    if (query && !text.includes(query)) return false;
    return true;
  });

  const total = visible.length;
  const pages = Math.max(1, Math.ceil(total / limit));
  if (ikkState.page > pages) ikkState.page = pages;
  const start = (ikkState.page - 1) * limit;

  allRows.forEach(row => { row.style.display = 'none'; });
  visible.slice(start, start + limit).forEach(row => { row.style.display = ''; });

  const infoEl = document.getElementById('ikkPageInfo');
  if(infoEl) {
    infoEl.textContent = total === 0 ? 'Tidak ada data' : 'Menampilkan ' + (start + 1) + '–' + Math.min(start + limit, total) + ' dari ' + total + ' data';
  }

  const pagesEl = document.getElementById('ikkPages');
  if(pagesEl) {
    pagesEl.innerHTML = '';
    if (pages > 1) {
      pagesEl.style.display = 'flex';
      const mkBtn = (html, page, disabled, active, title) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'page-btn' + (active ? ' active' : '') + (disabled ? ' disabled' : '');
        b.innerHTML = html;
        b.disabled = disabled;
        if (title) b.title = title;
        if (!disabled && !active) {
          b.onclick = () => {
            ikkState.page = page;
            filterTableIkk();
            document.getElementById('tableIkk')?.scrollIntoView({behavior:'smooth', block:'nearest'});
          };
        }
        pagesEl.appendChild(b);
      };
      mkBtn('<i class="fas fa-angles-left"></i>', 1, ikkState.page <= 1, false, 'Halaman Pertama');
      mkBtn('<i class="fas fa-chevron-left"></i>', ikkState.page - 1, ikkState.page <= 1, false, 'Halaman Sebelumnya');
      for (let p = Math.max(1, ikkState.page - 2); p <= Math.min(pages, ikkState.page + 2); p++) {
        mkBtn(String(p), p, false, p === ikkState.page);
      }
      mkBtn('<i class="fas fa-chevron-right"></i>', ikkState.page + 1, ikkState.page >= pages, false, 'Halaman Berikutnya');
      mkBtn('<i class="fas fa-angles-right"></i>', pages, ikkState.page >= pages, false, 'Halaman Terakhir');
    } else {
      pagesEl.style.display = 'none';
    }
  }
  
  if (count === 0 && allRows.length > 0) {
    if (!emptyRow) {
      emptyRow = document.createElement('tr');
      emptyRow.id = 'emptySearchIkk';
      emptyRow.innerHTML = `<td colspan="8"><div class="empty-state"><i class="fas fa-search"></i><p>Pencarian "<b>${query}</b>" tidak ditemukan.</p></div></td>`;
      document.querySelector('#tableIkk tbody').appendChild(emptyRow);
    } else {
      emptyRow.style.display = '';
      emptyRow.innerHTML = `<td colspan="8"><div class="empty-state"><i class="fas fa-search"></i><p>Pencarian "<b>${query}</b>" tidak ditemukan.</p></div></td>`;
    }
  } else if (emptyRow) {
    emptyRow.style.display = 'none';
  }
}
// Init limit
setTimeout(() => filterTableIkk(), 100);
</script>



