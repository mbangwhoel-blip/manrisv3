<?php
/**
 * MODUL KATEGORI RISIKO — CRUD
 * Hanya Admin & Risk Manager yang bisa akses
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
requireRole('Admin');
$db = getDB();

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('error', 'Token tidak valid');
        header('Location: ' . APP_URL . '/?page=kategori');
        exit;
    }

    $aksi = $_POST['aksi'] ?? '';
    $id   = (int)($_POST['id'] ?? 0);

    if ($aksi === 'simpan') {
        $nama      = trim($_POST['nama'] ?? '');
        $deskripsi = trim($_POST['deskripsi'] ?? '');
        $warna     = trim($_POST['warna'] ?? '#3B82F6');

        if (empty($nama)) {
            setFlash('error', 'Nama kategori wajib diisi');
            header('Location: ' . APP_URL . '/?page=kategori');
            exit;
        }

        // Validasi format warna hex
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $warna)) {
            $warna = '#3B82F6';
        }

        if ($id > 0) {
            $s = $db->prepare('UPDATE kategori_risiko SET nama=?, deskripsi=?, warna=? WHERE id=?');
            $s->bind_param('sssi', $nama, $deskripsi, $warna, $id);
            $s->execute(); $s->close();
            logAktivitas('UPDATE', 'kategori', $id, 'Update kategori: ' . $nama);
            setFlash('success', 'Kategori berhasil diperbarui');
        } else {
            // Cek duplikat nama
            $cek = $db->prepare('SELECT id FROM kategori_risiko WHERE nama=? LIMIT 1');
            $cek->bind_param('s', $nama); $cek->execute();
            if ($cek->get_result()->num_rows > 0) {
                setFlash('error', 'Nama kategori sudah ada');
                header('Location: ' . APP_URL . '/?page=kategori');
                exit;
            }
            $cek->close();

            $s = $db->prepare('INSERT INTO kategori_risiko (nama, deskripsi, warna) VALUES (?, ?, ?)');
            $s->bind_param('sss', $nama, $deskripsi, $warna);
            $s->execute(); $newId = $db->insert_id; $s->close();
            logAktivitas('CREATE', 'kategori', $newId, 'Tambah kategori: ' . $nama);
            setFlash('success', 'Kategori "' . $nama . '" berhasil ditambahkan');
        }
    } elseif ($aksi === 'hapus') {
        requireRole('Admin');

        // Cek apakah kategori masih dipakai
        $cek = $db->prepare('SELECT COUNT(*) FROM risiko WHERE id_kategori=?');
        $cek->bind_param('i', $id); $cek->execute();
        $jumlah = $cek->get_result()->fetch_row()[0]; $cek->close();

        if ($jumlah > 0) {
            setFlash('error', "Kategori tidak bisa dihapus karena masih digunakan oleh $jumlah risiko");
            header('Location: ' . APP_URL . '/?page=kategori');
            exit;
        }

        $s = $db->prepare('DELETE FROM kategori_risiko WHERE id=?');
        $s->bind_param('i', $id); $s->execute(); $s->close();
        logAktivitas('DELETE', 'kategori', $id, 'Hapus kategori ID ' . $id);
        setFlash('success', 'Kategori berhasil dihapus');
    }

    header('Location: ' . APP_URL . '/?page=kategori');
    exit;
}

// ── Ambil Data ─────────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$whereStr = $search ? "WHERE nama LIKE ? OR deskripsi LIKE ?" : '';

// Hitung total risiko per kategori (prepared statement)
$userJoinCondition = "";

if ($search) {
    $like = '%' . $search . '%';
    $stmt = $db->prepare("
        SELECT k.*, 0 AS jumlah_risiko
        FROM kategori_risiko k
        WHERE k.nama LIKE ? OR k.deskripsi LIKE ?
        ORDER BY k.nama
    ");
    $stmt->bind_param('ss', $like, $like);
    $stmt->execute();
    $kategoris = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $kategoris = $db->query("
        SELECT k.*, 0 AS jumlah_risiko
        FROM kategori_risiko k
        ORDER BY k.nama
    ")->fetch_all(MYSQLI_ASSOC);
}

// Warna preset untuk color picker
$warnaPreset = [
    '#3B82F6' => 'Biru',
    '#10B981' => 'Hijau Teal',
    '#8B5CF6' => 'Ungu',
    '#F59E0B' => 'Kuning Amber',
    '#EF4444' => 'Merah',
    '#EC4899' => 'Pink',
    '#14B8A6' => 'Teal',
    '#F97316' => 'Oranye',
    '#6366F1' => 'Indigo',
    '#84CC16' => 'Lime',
    '#06B6D4' => 'Cyan',
    '#A855F7' => 'Purple',
];
?>

<div class="risiko-hero profil-risiko-hero" style="background:linear-gradient(115deg,#1f2937 0%,#374151 55%,#4b5563 100%); align-items: flex-start !important;">
  <div class="risiko-hero-copy">
    <div class="risiko-eyebrow"><i class="fas fa-database"></i> Data Master</div>
    <h1 class="page-title" style="color:#fff">Kategori Risiko</h1>
    <p class="page-sub" style="color:rgba(255,255,255,.8)">Kelola kategori untuk pengelompokan risiko organisasi</p>
  </div>
  <div class="profil-hero-tools risiko-hero-tools-align">
    <form method="GET" action="<?= APP_URL ?>/" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0">
      <input type="hidden" name="page" value="kategori">
      <div class="search-bar" style="margin-bottom:0">
        <i class="fas fa-search"></i>
        <input type="search" name="q" class="form-control" placeholder="Cari kategori..." value="<?= xss($search) ?>" aria-label="Cari">
      </div>
      <button type="button" class="btn btn-hero-primary" onclick="openModal('modalKategori')"><i class="fas fa-plus"></i> Tambah Kategori</button>
    </form>
  </div>

  <!-- Stat cards (glassmorphism inside hero) -->
  <div class="stats-grid cols-4" style="width:100%;margin-top:20px;margin-bottom:0">
    <div class="stat-card stat-card-glass" style="--ga:#60a5fa;--ga-tint:rgba(96,165,250,.3);--ga-line:rgba(96,165,250,.45);--ga-glow:rgba(96,165,250,.3)">
      <div class="stat-icon"><i class="fas fa-tags"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= count($kategoris) ?></div>
        <div class="stat-label">Total Kategori</div>
      </div>
    </div>
    <div class="stat-card stat-card-glass" style="--ga:#4ade80;--ga-tint:rgba(74,222,128,.28);--ga-line:rgba(74,222,128,.45);--ga-glow:rgba(74,222,128,.28)">
      <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= array_sum(array_column($kategoris,'jumlah_risiko')) ?></div>
        <div class="stat-label">Total Risiko Terdaftar</div>
      </div>
    </div>
    <div class="stat-card stat-card-glass" style="--ga:#fbbf24;--ga-tint:rgba(251,191,36,.3);--ga-line:rgba(251,191,36,.5);--ga-glow:rgba(251,191,36,.3)">
      <div class="stat-icon"><i class="fas fa-folder-open"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= count(array_filter($kategoris, fn($k)=>$k['jumlah_risiko']>0)) ?></div>
        <div class="stat-label">Kategori Aktif</div>
      </div>
    </div>
    <div class="stat-card stat-card-glass" style="--ga:#94a3b8;--ga-tint:rgba(148,163,184,.25);--ga-line:rgba(148,163,184,.4);--ga-glow:rgba(148,163,184,.25)">
      <div class="stat-icon"><i class="fas fa-folder"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= count(array_filter($kategoris, fn($k)=>$k['jumlah_risiko']==0)) ?></div>
        <div class="stat-label">Kategori Kosong</div>
      </div>
    </div>
  </div>
</div>

<!-- Grid Kartu Kategori -->
<?php if(empty($kategoris)): ?>
<div class="card">
  <div class="card-body">
    <div class="empty-state" style="padding:60px">
      <i class="fas fa-tags" style="font-size:3rem;opacity:.15;margin-bottom:16px;display:block"></i>
      <h3>Belum ada kategori<?= $search ? ' yang cocok' : '' ?></h3>
      <p style="margin-top:6px"><?= $search ? 'Coba kata kunci lain' : 'Klik "Tambah Kategori" untuk membuat kategori baru' ?></p>
    </div>
  </div>
</div>
<?php else: ?>

<!-- Tabel + Kartu hybrid layout -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;margin-bottom:20px">
  <?php foreach($kategoris as $k): ?>
  <div class="card" style="border-top:4px solid <?= xss($k['warna']) ?>;transition:all .22s">
    <div class="card-body" style="padding:16px 18px">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px">
        <!-- Info -->
        <div style="flex:1;min-width:0">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
            <div style="width:14px;height:14px;border-radius:50%;background:<?= xss($k['warna']) ?>;flex-shrink:0"></div>
            <h3 style="font-size:.95rem;font-weight:700;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= xss($k['nama']) ?></h3>
          </div>
          <?php if($k['deskripsi']): ?>
          <p style="font-size:.8rem;color:var(--text-muted);margin-bottom:10px;line-height:1.5"><?= xss(mb_substr($k['deskripsi'],0,100)) ?><?= mb_strlen($k['deskripsi'])>100?'...':'' ?></p>
          <?php else: ?>
          <p style="font-size:.78rem;color:var(--text-muted);font-style:italic;margin-bottom:10px">Tidak ada deskripsi</p>
          <?php endif; ?>

          <!-- Badge risiko -->
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <span style="display:inline-flex;align-items:center;gap:5px;background:var(--surface2);border-radius:20px;padding:3px 10px;font-size:.75rem;font-weight:600;color:var(--text-muted)">
              <i class="fas fa-exclamation-triangle" style="color:<?= xss($k['warna']) ?>"></i>
              <?= $k['jumlah_risiko'] ?> Risiko
            </span>
            <span style="font-size:.72rem;color:var(--text-muted);font-family:monospace"><?= xss($k['warna']) ?></span>
          </div>
        </div>

        <!-- Progress bar jumlah risiko -->
        <?php
          $maxRisiko = max(1, max(array_column($kategoris,'jumlah_risiko')));
          $pct = $maxRisiko > 0 ? round($k['jumlah_risiko'] / $maxRisiko * 100) : 0;
        ?>
        <div style="text-align:center;flex-shrink:0">
          <div style="width:52px;height:52px;position:relative">
            <svg viewBox="0 0 36 36" style="width:52px;height:52px;transform:rotate(-90deg)">
              <circle cx="18" cy="18" r="15.9" fill="none" stroke="var(--border)" stroke-width="3"/>
              <circle cx="18" cy="18" r="15.9" fill="none"
                stroke="<?= xss($k['warna']) ?>" stroke-width="3"
                stroke-dasharray="<?= $pct ?> 100"
                stroke-linecap="round"/>
            </svg>
            <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:800;color:var(--text)"><?= $k['jumlah_risiko'] ?></div>
          </div>
        </div>
      </div>

      <!-- Tombol Aksi -->
      <div style="display:flex;gap:6px;margin-top:12px;padding-top:12px;border-top:1px solid var(--border)">
        <button class="btn btn-sm btn-accent" style="flex:1"
          onclick='editKategori(<?= htmlspecialchars(json_encode($k), ENT_QUOTES) ?>)'>
          <i class="fas fa-edit"></i> Edit
        </button>
        <?php if(hasRole('Admin')): ?>
        <button class="btn btn-sm btn-danger" style="flex:1"
          onclick="hapusKategori(<?= $k['id'] ?>, '<?= xss($k['nama']) ?>', <?= $k['jumlah_risiko'] ?>)"
          <?= $k['jumlah_risiko'] > 0 ? 'title="Masih ada '.$k['jumlah_risiko'].' risiko"' : '' ?>>
          <i class="fas fa-trash"></i> Hapus
        </button>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Tabel ringkas -->
<div class="card">
  <div class="card-header risiko-table-header">
    <span class="card-title"><i class="fas fa-table"></i> Tabel Kategori (<?= count($kategoris) ?>)</span>
  </div>
  <div class="table-responsive">
    <table class="data-table no-datatable">
      <thead>
        <tr>
          <th>#</th>
          <th>Warna</th>
          <th>Nama Kategori</th>
          <th>Deskripsi</th>
          <th>Jumlah Risiko</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($kategoris as $i => $k): ?>
      <tr>
        <td style="color:var(--text-muted)"><?= $i+1 ?></td>
        <td>
          <div style="display:flex;align-items:center;gap:8px">
            <div style="width:24px;height:24px;border-radius:6px;background:<?= xss($k['warna']) ?>;box-shadow:0 2px 4px rgba(0,0,0,.15)"></div>
            <code style="font-size:.75rem"><?= xss($k['warna']) ?></code>
          </div>
        </td>
        <td style="font-weight:600"><?= xss($k['nama']) ?></td>
        <td style="color:var(--text-muted);font-size:.82rem;max-width:280px">
          <?= xss(mb_substr($k['deskripsi']??'',0,80)) ?><?= mb_strlen($k['deskripsi']??'')>80?'...':'' ?>
        </td>
        <td>
          <span style="background:<?= kkprBadge($k['jumlah_risiko']) ?>;color:#fff;padding:3px 10px;border-radius:12px;font-size:.75rem;font-weight:700">
            <?= $k['jumlah_risiko'] ?> risiko
          </span>
        </td>
        <td>
          <div class="act-btn-group">
            <button class="act-btn act-btn-edit"
              onclick='editKategori(<?= htmlspecialchars(json_encode($k), ENT_QUOTES) ?>)' title="Edit">
              <i class="fas fa-edit"></i>
            </button>
            <?php if(hasRole('Admin')): ?>
            <button class="act-btn act-btn-delete"
              onclick="hapusKategori(<?= $k['id'] ?>, '<?= xss($k['nama']) ?>', <?= $k['jumlah_risiko'] ?>)" title="Hapus">
              <i class="fas fa-trash"></i>
            </button>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php
// Helper badge warna berdasarkan jumlah
function kkprBadge(int $n): string {
    if ($n === 0) return '#94a3b8';
    if ($n <= 2)  return '#22c55e';
    if ($n <= 5)  return '#f59e0b';
    return '#dc2626';
}
?>

<!-- Modal Tambah/Edit -->
<div class="modal-overlay" id="modalKategori" style="display:none">
  <div class="modal" style="max-width:520px">
    <div class="modal-header">
      <h3 class="modal-title" id="modalKatTitle"><i class="fas fa-plus-circle"></i> Tambah Kategori</h3>
      <button class="btn-close" onclick="closeModal('modalKategori')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/?page=kategori" id="formKategori">
    <?= csrfField() ?>
    <input type="hidden" name="aksi" value="simpan">
    <input type="hidden" name="id" id="katId" value="0">
    <div class="modal-body">

      <div class="form-group">
        <label class="form-label">Nama Kategori <span class="required">*</span></label>
        <input type="text" name="nama" id="katNama" class="form-control"
               required placeholder="Contoh: Operasional, Keuangan, IT...">
      </div>

      <div class="form-group">
        <label class="form-label">Deskripsi</label>
        <textarea name="deskripsi" id="katDesc" class="form-control" rows="3"
                  placeholder="Penjelasan singkat tentang kategori ini..."></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Warna Identifikasi</label>
        <!-- Preset warna -->
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px" id="warnaPreset">
          <?php foreach($warnaPreset as $hex => $nama): ?>
          <button type="button"
            class="warna-btn"
            data-hex="<?= $hex ?>"
            title="<?= $nama ?>"
            onclick="pilihWarna('<?= $hex ?>')"
            style="width:30px;height:30px;border-radius:8px;background:<?= $hex ?>;border:3px solid transparent;cursor:pointer;transition:all .15s;flex-shrink:0">
          </button>
          <?php endforeach; ?>
        </div>
        <!-- Custom color picker -->
        <div style="display:flex;align-items:center;gap:10px">
          <input type="color" id="katWarnaColor" value="#3B82F6"
                 style="width:44px;height:44px;border:2px solid var(--border);border-radius:8px;cursor:pointer;background:none;padding:2px"
                 oninput="sinkWarnaInput(this.value)">
          <div style="flex:1">
            <input type="text" name="warna" id="katWarna"
                   class="form-control" value="#3B82F6"
                   placeholder="#3B82F6"
                   pattern="^#[0-9A-Fa-f]{6}$"
                   oninput="sinkWarnaText(this.value)"
                   style="font-family:monospace;letter-spacing:.05em">
            <div class="form-hint">Format HEX: #RRGGBB</div>
          </div>
          <div id="katWarnaPrev"
               style="width:44px;height:44px;border-radius:10px;background:#3B82F6;border:2px solid var(--border);flex-shrink:0;transition:background .15s">
          </div>
        </div>
      </div>

    </div>
    <div class="modal-footer">
      <button type="button" onclick="closeModal('modalKategori')" class="btn btn-outline">Batal</button>
      <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Kategori</button>
    </div>
    </form>
  </div>
</div>

<!-- Modal Hapus -->
<div class="modal-overlay" id="modalHapusKat" style="display:none">
  <div class="modal" style="max-width:420px">
    <div class="modal-header">
      <h3 class="modal-title" style="color:var(--danger)"><i class="fas fa-trash"></i> Hapus Kategori</h3>
      <button class="btn-close" onclick="closeModal('modalHapusKat')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <p>Yakin ingin menghapus kategori <strong id="hapusKatNama"></strong>?</p>
      <div id="hapusKatWarning" style="display:none;margin-top:10px;padding:10px 12px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;font-size:.83rem;color:var(--danger)">
        <i class="fas fa-exclamation-triangle"></i>
        <span id="hapusKatMsg"></span>
      </div>
    </div>
    <div class="modal-footer">
      <form method="POST" action="<?= APP_URL ?>/?page=kategori">
        <?= csrfField() ?>
        <input type="hidden" name="aksi" value="hapus">
        <input type="hidden" name="id" id="hapusKatId">
        <button type="button" onclick="closeModal('modalHapusKat')" class="btn btn-outline">Batal</button>
        <button type="submit" id="btnHapusKat" class="btn btn-danger">
          <i class="fas fa-trash"></i> Hapus
        </button>
      </form>
    </div>
  </div>
</div>

<script>
// ── Warna ──────────────────────────────────────────────────────
function pilihWarna(hex) {
  document.getElementById('katWarna').value = hex;
  document.getElementById('katWarnaColor').value = hex;
  document.getElementById('katWarnaPrev').style.background = hex;
  // Highlight tombol terpilih
  document.querySelectorAll('.warna-btn').forEach(b => {
    b.style.borderColor = b.dataset.hex === hex ? '#fff' : 'transparent';
    b.style.transform   = b.dataset.hex === hex ? 'scale(1.2)' : '';
    b.style.boxShadow   = b.dataset.hex === hex ? '0 0 0 2px '+hex : '';
  });
}
function sinkWarnaText(val) {
  if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
    document.getElementById('katWarnaColor').value = val;
    document.getElementById('katWarnaPrev').style.background = val;
  }
}
function sinkWarnaInput(val) {
  document.getElementById('katWarna').value = val;
  document.getElementById('katWarnaPrev').style.background = val;
}

// ── Edit Kategori ──────────────────────────────────────────────
function editKategori(k) {
  document.getElementById('modalKatTitle').innerHTML =
    '<i class="fas fa-edit"></i> Edit Kategori';
  document.getElementById('katId').value    = k.id;
  document.getElementById('katNama').value  = k.nama;
  document.getElementById('katDesc').value  = k.deskripsi || '';
  pilihWarna(k.warna || '#3B82F6');
  openModal('modalKategori');
}

// ── Hapus Kategori ────────────────────────────────────────────
function hapusKategori(id, nama, jumlah) {
  document.getElementById('hapusKatId').value = id;
  document.getElementById('hapusKatNama').textContent = nama;
  const warn = document.getElementById('hapusKatWarning');
  const btn  = document.getElementById('btnHapusKat');
  if (jumlah > 0) {
    warn.style.display = '';
    document.getElementById('hapusKatMsg').textContent =
      'Kategori ini masih digunakan oleh ' + jumlah + ' risiko. Hapus atau pindahkan risiko tersebut terlebih dahulu.';
    btn.disabled = true;
    btn.style.opacity = '.5';
  } else {
    warn.style.display = 'none';
    btn.disabled = false;
    btn.style.opacity = '';
  }
  openModal('modalHapusKat');
}

// ── Reset modal saat tutup ─────────────────────────────────────
document.getElementById('modalKategori')?.addEventListener('click', function(e){
  if(e.target === this) {
    document.getElementById('katId').value = '0';
    document.getElementById('modalKatTitle').innerHTML =
      '<i class="fas fa-plus-circle"></i> Tambah Kategori';
  }
});
</script>
