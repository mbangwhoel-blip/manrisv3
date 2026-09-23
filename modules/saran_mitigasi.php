<?php
/**
 * MODUL SARAN MITIGASI — Kelola saran otomatis berbasis keyword
 * Admin & Risk Manager bisa CRUD kategori, keyword, dan saran
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
requireRole('Admin');

$db = getDB();

// ── POST Handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('error', 'Token tidak valid.');
        header('Location: ' . APP_URL . '/?page=saran_mitigasi'); exit;
    }

    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'simpan') {
        $id       = (int)($_POST['id'] ?? 0);
        $kategori = trim($_POST['kategori'] ?? '');
        $keyword  = trim($_POST['keyword'] ?? '');
        $saran    = trim($_POST['saran'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (empty($kategori) || empty($keyword) || empty($saran)) {
            setFlash('error', 'Kategori, keyword, dan saran wajib diisi.');
            header('Location: ' . APP_URL . '/?page=saran_mitigasi'); exit;
        }

        if ($id > 0) {
            $s = $db->prepare('UPDATE saran_mitigasi SET kategori=?, keyword=?, saran=?, is_active=? WHERE id=?');
            $s->bind_param('sssii', $kategori, $keyword, $saran, $isActive, $id);
            $s->execute(); $s->close();
            logAktivitas('UPDATE', 'saran_mitigasi', $id, 'Update saran mitigasi: ' . $saran);
            setFlash('success', 'Saran mitigasi berhasil diperbarui.');
        } else {
            $s = $db->prepare('INSERT INTO saran_mitigasi (kategori, keyword, saran, is_active) VALUES (?, ?, ?, ?)');
            $s->bind_param('sssi', $kategori, $keyword, $saran, $isActive);
            $s->execute(); $s->close();
            logAktivitas('CREATE', 'saran_mitigasi', $db->insert_id, 'Tambah saran mitigasi: ' . $saran);
            setFlash('success', 'Saran mitigasi berhasil ditambahkan.');
        }
        header('Location: ' . APP_URL . '/?page=saran_mitigasi'); exit;

    } elseif ($aksi === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $s = $db->prepare('DELETE FROM saran_mitigasi WHERE id=?');
            $s->bind_param('i', $id);
            $s->execute(); $s->close();
            logAktivitas('DELETE', 'saran_mitigasi', $id, 'Hapus saran mitigasi ID ' . $id);
            setFlash('success', 'Saran mitigasi berhasil dihapus.');
        }
        header('Location: ' . APP_URL . '/?page=saran_mitigasi'); exit;

    } elseif ($aksi === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->query("UPDATE saran_mitigasi SET is_active = 1 - is_active WHERE id = $id");
            setFlash('success', 'Status saran mitigasi diubah.');
        }
        header('Location: ' . APP_URL . '/?page=saran_mitigasi'); exit;
    }
}

// ── Data ──────────────────────────────────────────────────────
$fQ = trim($_GET['q'] ?? '');
$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
    $s = $db->prepare('SELECT * FROM saran_mitigasi WHERE id=?');
    $s->bind_param('i', $editId); $s->execute();
    $editRow = $s->get_result()->fetch_assoc(); $s->close();
}

// Group by kategori (dengan filter pencarian bila aktif)
if ($fQ !== '') {
    $s = $db->prepare("SELECT * FROM saran_mitigasi WHERE kategori LIKE ? OR keyword LIKE ? OR saran LIKE ? ORDER BY kategori, id");
    $qParam = "%{$fQ}%";
    $s->bind_param('sss', $qParam, $qParam, $qParam);
    $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
} else {
    $rows = $db->query("SELECT * FROM saran_mitigasi ORDER BY kategori, id")->fetch_all(MYSQLI_ASSOC);
}
$grouped = [];
foreach ($rows as $r) {
    $grouped[$r['kategori']][] = $r;
}
?>


<div class="risiko-hero profil-risiko-hero" style="background:linear-gradient(115deg,#1f2937 0%,#374151 55%,#4b5563 100%); align-items: flex-start !important;">
  <div class="risiko-hero-copy">
    <div class="risiko-eyebrow"><i class="fas fa-database"></i> Data Master</div>
    <h1 class="page-title" style="color:#fff">Saran Mitigasi Otomatis</h1>
    <p class="page-sub" style="color:rgba(255,255,255,.8)">Kelola kategori, keyword, dan saran mitigasi yang muncul otomatis saat input risiko</p>
  </div>
  <div class="profil-hero-tools risiko-hero-tools-align">
    <form method="GET" action="<?= APP_URL ?>/" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0">
      <input type="hidden" name="page" value="saran_mitigasi">
      <div class="search-bar" style="margin-bottom:0">
        <i class="fas fa-search"></i>
        <input type="search" name="q" class="form-control" placeholder="Cari kategori/keyword/saran..." value="<?= xss($fQ) ?>" aria-label="Cari">
      </div>
      <button type="button" class="btn btn-hero-primary" onclick="openModal('modalSaran')"><i class="fas fa-plus"></i> Tambah Saran</button>
    </form>
  </div>

  <!-- Stat cards (glassmorphism inside hero) -->
  <div class="stats-grid cols-3" style="width:100%;margin-top:20px;margin-bottom:0">
    <div class="stat-card stat-card-glass" style="--ga:#60a5fa;--ga-tint:rgba(96,165,250,.3);--ga-line:rgba(96,165,250,.45);--ga-glow:rgba(96,165,250,.3)">
      <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= count($grouped) ?></div>
        <div class="stat-label">Kategori Saran</div>
      </div>
    </div>
    <div class="stat-card stat-card-glass" style="--ga:#fbbf24;--ga-tint:rgba(251,191,36,.3);--ga-line:rgba(251,191,36,.5);--ga-glow:rgba(251,191,36,.3)">
      <div class="stat-icon"><i class="fas fa-lightbulb"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= count($rows) ?></div>
        <div class="stat-label">Total Saran</div>
      </div>
    </div>
    <div class="stat-card stat-card-glass" style="--ga:#4ade80;--ga-tint:rgba(74,222,128,.28);--ga-line:rgba(74,222,128,.45);--ga-glow:rgba(74,222,128,.28)">
      <div class="stat-icon"><i class="fas fa-key"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= count(array_filter($rows, fn($r) => trim((string)$r['keyword']) !== '')) ?></div>
        <div class="stat-label">Saran Berkeyword</div>
      </div>
    </div>
  </div>
</div>

<!-- Info Card -->
<div class="card saran-page-content" style="margin-bottom:20px;border-left:4px solid var(--warning)">
  <div class="card-body" style="padding:14px 20px">
    <div style="display:flex;align-items:center;gap:12px">
      <i class="fas fa-info-circle" style="font-size:1.3rem;color:var(--warning)"></i>
      <div style="font-size:.82rem;color:var(--text-muted);line-height:1.5">
        <strong>Cara kerja:</strong> Saat user input risiko, sistem cek <strong>keyword</strong> di deskripsi & penyebab risiko.
        Jika cocok, saran mitigasi dari kategori tersebut muncul otomatis. Keyword pakai format <code>keyword1|keyword2|keyword3</code> (pisah dengan |).
      </div>
    </div>
  </div>
</div>

<!-- Daftar Saran per Kategori -->
<?php if (empty($grouped)): ?>
<div class="card saran-page-content">
  <div class="card-body">
    <div class="empty-state">
      <i class="fas fa-lightbulb"></i>
      <h3>Belum ada saran mitigasi</h3>
      <p>Klik "Tambah Saran" untuk membuat saran mitigasi otomatis pertama Anda.</p>
    </div>
  </div>
</div>
<?php else: ?>
<div class="saran-kategori-wrapper">
<?php foreach ($grouped as $kat => $items): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card-header" style="background:linear-gradient(135deg,rgba(245,158,11,.05),transparent)">
    <span class="card-title">
      <i class="fas fa-folder" style="color:var(--warning)"></i>
      <?= xss($kat) ?>
      <span style="color:var(--text-muted);font-weight:400;font-size:.78rem">(<?= count($items) ?> saran)</span>
    </span>
    <code style="font-size:.7rem;color:var(--text-muted);background:var(--surface2);padding:2px 8px;border-radius:4px">
      <?= xss($items[0]['keyword']) ?>
    </code>
  </div>
  <div class="card-body" style="padding:0">
    <div class="table-responsive">
      <table class="data-table no-datatable">
        <thead>
          <tr>
            <th style="width:40px">#</th>
            <th>Saran Mitigasi</th>
            <th style="width:90px;text-align:center">Status</th>
            <th style="width:140px;text-align:center">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $i => $item): ?>
          <tr>
            <td style="color:var(--text-muted)"><?= $i + 1 ?></td>
            <td style="font-size:.83rem;line-height:1.5"><?= xss($item['saran']) ?></td>
            <td style="text-align:center">
              <?php if ((int)$item['is_active'] === 1): ?>
                <span class="badge badge-success">Aktif</span>
              <?php else: ?>
                <span class="badge badge-secondary">Nonaktif</span>
              <?php endif; ?>
            </td>
            <td style="text-align:center">
              <div class="act-btn-group">
                <a href="?page=saran_mitigasi&edit=<?= $item['id'] ?>" class="act-btn act-btn-edit" title="Edit"><i class="fas fa-edit"></i></a>
                <form method="post" action="?page=saran_mitigasi" style="margin:0;display:inline">
                  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                  <input type="hidden" name="aksi" value="toggle">
                  <input type="hidden" name="id" value="<?= $item['id'] ?>">
                  <button type="submit" class="act-btn act-btn-toggle" title="<?= (int)$item['is_active']===1?'Nonaktifkan':'Aktifkan' ?>"><i class="fas fa-toggle-<?= (int)$item['is_active']===1?'on':'off' ?>"></i></button>
                </form>
                <form method="post" action="?page=saran_mitigasi" style="margin:0;display:inline" onsubmit="return confirm('Hapus saran ini?')">
                  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                  <input type="hidden" name="aksi" value="hapus">
                  <input type="hidden" name="id" value="<?= $item['id'] ?>">
                  <button type="submit" class="act-btn act-btn-delete" title="Hapus"><i class="fas fa-trash"></i></button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Modal Tambah/Edit -->
<div class="modal-overlay" id="modalSaran" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100;padding:20px;overflow-y:auto" onclick="if(event.target===this) closeModal('modalSaran')">
  <div class="card" style="max-width:650px;margin:20px auto;max-height:calc(100vh - 40px);display:flex;flex-direction:column;overflow:hidden">
    <div class="card-header" style="flex:0 0 auto">
      <span class="card-title" id="modalSaranTitle"><i class="fas fa-plus"></i> Tambah Saran Mitigasi</span>
      <button type="button" class="btn btn-sm btn-outline" onclick="closeModal('modalSaran')"><i class="fas fa-times"></i></button>
    </div>
    <div class="card-body" style="padding:24px;overflow-y:auto;flex:1 1 auto">
      <form method="post" action="?page=saran_mitigasi" id="formSaran">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="aksi" value="simpan">
        <input type="hidden" name="id" id="fId" value="<?= $editRow['id'] ?? 0 ?>">
        <div class="form-group" style="margin-bottom:14px">
          <label class="form-label">Kategori <span style="color:var(--danger)">*</span></label>
          <input type="text" name="kategori" id="fKategori" class="form-control" required
                 value="<?= xss($editRow['kategori'] ?? '') ?>"
                 placeholder="Contoh: IT & Keamanan, Keuangan, SDM, dll"
                 list="kategoriList">
          <datalist id="kategoriList">
            <?php foreach (array_keys($grouped) as $k): ?>
            <option value="<?= xss($k) ?>">
            <?php endforeach; ?>
          </datalist>
          <small style="color:var(--text-muted);font-size:.72rem">Kategori untuk mengelompokkan saran. Bisa pilih yang sudah ada atau buat baru.</small>
        </div>
        <div class="form-group" style="margin-bottom:14px">
          <label class="form-label">Keyword <span style="color:var(--danger)">*</span></label>
          <input type="text" name="keyword" id="fKeyword" class="form-control" required
                 value="<?= xss($editRow['keyword'] ?? '') ?>"
                 placeholder="Contoh: it|server|sistem|cyber|data">
          <small style="color:var(--text-muted);font-size:.72rem">Kata kunci untuk mencocokkan deskripsi risiko. Pisah dengan tanda <code>|</code>.</small>
        </div>
        <div class="form-group" style="margin-bottom:14px">
          <label class="form-label">Saran Mitigasi <span style="color:var(--danger)">*</span></label>
          <textarea name="saran" id="fSaran" class="form-control" rows="3" required
                    placeholder="Contoh: Lakukan audit keamanan sistem secara berkala"><?= xss($editRow['saran'] ?? '') ?></textarea>
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" name="is_active" id="fActive" <?= (!isset($editRow['is_active']) || (int)$editRow['is_active'] === 1) ? 'checked' : '' ?>>
            <span style="font-size:.82rem;font-weight:600">Aktif (saran tampil saat input risiko)</span>
          </label>
        </div>
      </form>
    </div>
    <div class="card-footer" style="flex:0 0 auto;padding:16px 24px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end;background:var(--surface,#fff)">
      <button type="button" class="btn btn-outline" onclick="closeModal('modalSaran')">Batal</button>
      <button type="submit" form="formSaran" class="btn btn-accent"><i class="fas fa-save"></i> Simpan</button>
    </div>
  </div>
</div>

<script>
// ── FIX: Portal modal ke <body> ────────────────────────────────
// Modal ini awalnya berada di dalam <main class="content">. Kalau
// container itu (atau .main-wrapper) punya CSS overflow/transform,
// position:fixed pada modal jadi tidak relatif ke layar penuh —
// akibatnya sidebar masih kelihatan & muncul scrollbar dobel.
// Memindahkan elemen modal langsung jadi child dari <body> membuat
// position:fixed selalu relatif ke viewport, menutup penuh layar.
(function() {
  var modal = document.getElementById('modalSaran');
  if (modal && modal.parentElement !== document.body) {
    document.body.appendChild(modal);
  }

  // Kunci scroll halaman belakang saat modal terbuka, buka lagi saat ditutup
  var observer = new MutationObserver(function() {
    var isOpen = modal.style.display !== 'none' && modal.style.display !== '';
    document.body.style.overflow = isOpen ? 'hidden' : '';
  });
  observer.observe(modal, { attributes: true, attributeFilter: ['style'] });
})();

<?php if ($editRow): ?>
// Auto-open modal untuk edit
document.addEventListener('DOMContentLoaded', function() {
  openModal('modalSaran');
  document.getElementById('modalSaranTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Saran Mitigasi';
});
<?php endif; ?>
</script>

<style>
/* Tabel saran mitigasi — pastikan tidak overflow */
.saran-table { width:100% !important; table-layout:fixed; }
.saran-table td:nth-child(2) { word-wrap:break-word; overflow-wrap:break-word; }
/* Tombol aksi langsung tanpa dropdown */
.saran-actions { display:flex; gap:4px; align-items:center; justify-content:center; }
.saran-actions form { margin:0; display:inline; }

/* ── FIX MODAL: paksa display block (bukan flex) supaya scroll & footer tombol
   selalu bekerja normal, terlepas dari bagaimana openModal()/closeModal() JS
   menyetel inline style-nya. Ini mengatasi bug flexbox "content clipped saat
   di-center + overflow", penyebab tombol Simpan tidak kelihatan. ── */
#modalSaran[style*="display: flex"],
#modalSaran[style*="display:flex"] {
  display: block !important;
}

/* Modal responsive */
@media (max-width:768px) {
  #modalSaran .card { max-width:100% !important; margin:0 !important; max-height:100vh !important; border-radius:0 !important; }
  #modalSaran { padding:0 !important; }
}

/* ── Konten halaman (info card, daftar kategori) dibuat center dengan lebar
   maksimal, supaya tidak melebar penuh mentok ke kanan-kiri layar. Hapus/
   ubah max-width sesuai selebar apa yang Anda inginkan. ── */
.saran-page-content,
.saran-kategori-wrapper {
  max-width: 900px;
  margin-left: auto;
  margin-right: auto;
}

/* Agar daftar kategori muat 1 layar (tidak bikin halaman scroll panjang) */
.saran-kategori-wrapper {
  max-height: calc(100vh - 300px); /* sesuaikan 300px dg tinggi navbar+header+info card di layout Anda */
  overflow-y: auto;
  padding-right: 4px;
}
.saran-kategori-wrapper .card { margin-bottom: 12px; }
.saran-kategori-wrapper::-webkit-scrollbar { width: 6px; }
.saran-kategori-wrapper::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
</style>

<?php // NOTE: footer.php TIDAK di-include di sini — sudah dihandle oleh index.php (router) setelah modul selesai. ?>
