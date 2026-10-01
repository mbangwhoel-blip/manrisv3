<?php
/**
 * MODUL MANAJEMEN USER (Admin Only)
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
requireRole('Admin');
$db = getDB();

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { setFlash('error','Token tidak valid'); header('Location: '.APP_URL.'/?page=user'); exit; }

    $aksi = $_POST['aksi'] ?? '';
    $id   = (int)($_POST['id'] ?? 0);

    // ── CRUD Bagian ──────────────────────────────────────────
    if ($aksi === 'simpan_bagian') {
        $nama      = trim($_POST['nama_bagian'] ?? '');
        $deskripsi = trim($_POST['deskripsi_bagian'] ?? '');
        $aktif     = isset($_POST['aktif_bagian']) ? 1 : 0;

        if (empty($nama)) {
            setFlash('error', 'Nama bagian wajib diisi.');
            header('Location: '.APP_URL.'/?page=user'); exit;
        }

        if ($id > 0) {
            $s = $db->prepare('UPDATE bagian SET nama=?, deskripsi=?, aktif=? WHERE id=?');
            $s->bind_param('ssii', $nama, $deskripsi, $aktif, $id);
            $s->execute(); $s->close();
            logAktivitas('UPDATE', 'bagian', $id, 'Update bagian: '.$nama);
            setFlash('success', 'Bagian berhasil diperbarui.');
        } else {
            $s = $db->prepare('INSERT INTO bagian (nama, deskripsi, aktif) VALUES (?,?,?)');
            $s->bind_param('ssi', $nama, $deskripsi, $aktif);
            $s->execute(); $newId = $db->insert_id; $s->close();
            logAktivitas('CREATE', 'bagian', $newId, 'Tambah bagian: '.$nama);
            setFlash('success', 'Bagian baru berhasil ditambahkan.');
        }
        header('Location: '.APP_URL.'/?page=user&bagian=1'); exit;
    }

    if ($aksi === 'hapus_bagian') {
        // Cek apakah masih ada user yang memakai bagian ini
        $cek = $db->prepare('SELECT COUNT(*) FROM users WHERE bagian_id=?');
        $cek->bind_param('i', $id); $cek->execute();
        $jumlah = (int)$cek->get_result()->fetch_row()[0]; $cek->close();
        if ($jumlah > 0) {
            setFlash('error', 'Bagian tidak dapat dihapus karena masih digunakan oleh '.$jumlah.' user. Pindahkan user tersebut terlebih dahulu.');
        } else {
            $s = $db->prepare('DELETE FROM bagian WHERE id=?');
            $s->bind_param('i', $id); $s->execute(); $s->close();
            logAktivitas('DELETE', 'bagian', $id, 'Hapus bagian ID '.$id);
            setFlash('success', 'Bagian berhasil dihapus.');
        }
        header('Location: '.APP_URL.'/?page=user&bagian=1'); exit;
    }

    // ── CRUD User ────────────────────────────────────────────
    if ($aksi === 'simpan') {
        $nama      = trim($_POST['nama'] ?? '');
        $nip       = trim($_POST['nip'] ?? '');
        $username  = trim($_POST['username'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $role      = $_POST['role'] ?? 'Staff';
        $aktif     = isset($_POST['aktif']) ? 1 : 0;
        $password  = $_POST['password'] ?? '';
        $bagian_id = !empty($_POST['bagian_id']) ? (int)$_POST['bagian_id'] : null;

        // Validasi role — whitelist
        $validRoles = ['Admin', 'Risk Manager', 'Staff', 'Pimpinan', 'Koordinator'];
        if (!in_array($role, $validRoles, true)) {
            setFlash('error', 'Role tidak valid.'); header('Location: '.APP_URL.'/?page=user'); exit;
        }
        // Validasi email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', 'Format email tidak valid.'); header('Location: '.APP_URL.'/?page=user'); exit;
        }
        // Password policy: min 8, huruf + angka (jika diisi)
        if ($password !== '') {
            if (strlen($password) < 8) {
                setFlash('error', 'Password minimal 8 karakter.'); header('Location: '.APP_URL.'/?page=user'); exit;
            }
            if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
                setFlash('error', 'Password harus mengandung huruf dan angka.'); header('Location: '.APP_URL.'/?page=user'); exit;
            }
        }

        if (empty($nama) || empty($username) || empty($email)) {
            setFlash('error','Nama, username, dan email wajib diisi'); header('Location: '.APP_URL.'/?page=user'); exit;
        }
        if ($id > 0) {
            // Update
            if (!empty($password)) {
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost'=>12]);
                $s = $db->prepare('UPDATE users SET nama=?,nip=?,username=?,email=?,role=?,aktif=?,password=?,bagian_id=? WHERE id=?');
                $s->bind_param('sssssiisi',$nama,$nip,$username,$email,$role,$aktif,$hash,$bagian_id,$id);
            } else {
                $s = $db->prepare('UPDATE users SET nama=?,nip=?,username=?,email=?,role=?,aktif=?,bagian_id=? WHERE id=?');
                $s->bind_param('sssssiii',$nama,$nip,$username,$email,$role,$aktif,$bagian_id,$id);
            }
            $s->execute(); $s->close();

            // Jika role user diubah, regenerate session ID
            $oldRoleStmt = $db->prepare('SELECT role FROM users WHERE id = ?');
            $oldRoleStmt->bind_param('i', $id);
            $oldRoleStmt->execute();
            $oldRole = (string)($oldRoleStmt->get_result()->fetch_column() ?: '');
            $oldRoleStmt->close();
            if ($oldRole !== $role) {
                session_regenerate_id(true);
                if ($id === (int)$_SESSION['user_id']) {
                    $_SESSION['user_role'] = $role;
                }
            }

            // Jika role diubah menjadi Risk Manager dan belum punya kode_prefix → assign otomatis
            if ($role === 'Risk Manager') {
                $chk = $db->prepare('SELECT kode_prefix FROM users WHERE id=?');
                $chk->bind_param('i', $id); $chk->execute();
                $cur = $chk->get_result()->fetch_assoc(); $chk->close();
                if (empty($cur['kode_prefix'])) {
                    assignRiskManagerPrefix($db, $id);
                }
            } elseif ($role !== 'Risk Manager') {
                $db->query("UPDATE users SET kode_prefix=NULL WHERE id=$id AND kode_prefix IS NOT NULL");
            }

            logAktivitas('UPDATE','user',$id,'Update user: '.$username);
            setFlash('success','User berhasil diperbarui');
        } else {
            // Insert
            if (empty($password)) { setFlash('error','Password wajib diisi untuk user baru'); header('Location: '.APP_URL.'/?page=user'); exit; }
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost'=>12]);
            $s = $db->prepare('INSERT INTO users (nama,nip,username,email,password,role,aktif,bagian_id) VALUES (?,?,?,?,?,?,?,?)');
            $s->bind_param('ssssssii',$nama,$nip,$username,$email,$hash,$role,$aktif,$bagian_id);
            $s->execute(); $newId=$db->insert_id; $s->close();

            if ($role === 'Risk Manager') {
                assignRiskManagerPrefix($db, $newId);
            }

            logAktivitas('CREATE','user',$newId,'Tambah user: '.$username);
            setFlash('success','User baru berhasil ditambahkan');
        }
    } elseif ($aksi === 'hapus') {
        if ($id === (int)$_SESSION['user_id']) { setFlash('error','Tidak bisa menghapus akun sendiri'); header('Location: '.APP_URL.'/?page=user'); exit; }
        if ($db->query("DELETE FROM users WHERE id=$id")) {
            logAktivitas('DELETE','user',$id,'Hapus user ID '.$id);
            setFlash('success','User berhasil dihapus');
        } else {
            setFlash('error','Gagal menghapus: User ini memiliki riwayat aktivitas atau risiko yang terikat di sistem. Nonaktifkan saja user ini.');
        }
    } elseif ($aksi === 'toggle') {
        $db->query("UPDATE users SET aktif = NOT aktif WHERE id=$id AND id != ".(int)$_SESSION['user_id']);
        setFlash('success','Status user diubah');
    }
    header('Location: '.APP_URL.'/?page=user'); exit;
}

// ── Auto-create tabel bagian jika belum ada ─────────────────
$db->query("CREATE TABLE IF NOT EXISTS `bagian` (
    `id`         INT          NOT NULL AUTO_INCREMENT,
    `nama`       VARCHAR(100) NOT NULL,
    `deskripsi`  VARCHAR(255) NULL DEFAULT NULL,
    `aktif`      TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Tambah kolom bagian_id di users jika belum ada
$chkCol = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'bagian_id'");
if ($chkCol && $chkCol->num_rows === 0) {
    $db->query("ALTER TABLE users ADD COLUMN bagian_id INT NULL DEFAULT NULL");
}

// ── Data ─────────────────────────────────────────────────────
$usersResult = $db->query("
    SELECT u.id, u.nama, u.nip, u.username, u.email, u.role, u.kode_prefix, u.aktif, u.created_at,
           u.bagian_id, b.nama AS bagian_nama
    FROM users u
    LEFT JOIN bagian b ON b.id = u.bagian_id
    ORDER BY u.role, u.nama
");
$users = $usersResult ? $usersResult->fetch_all(MYSQLI_ASSOC) : [];

$bagianResult = $db->query("SELECT id, nama, deskripsi, aktif FROM bagian ORDER BY nama");
$bagianList   = $bagianResult ? $bagianResult->fetch_all(MYSQLI_ASSOC) : [];
?>

<div class="page-header-row">
  <div class="page-header">
    <h1 class="page-title"><i class="fas fa-users-cog" style="color:var(--primary)"></i> Manajemen User</h1>
    <p class="page-sub">Kelola akun pengguna, hak akses, dan unit bagian</p>
  </div>
  <div class="page-actions">
    <button class="btn btn-outline btn-sm" onclick="openModal('modalBagian')"><i class="fas fa-sitemap"></i> Kelola Bagian</button>
    <button class="btn btn-primary" onclick="tambahUser()"><i class="fas fa-user-plus"></i> Tambah User</button>
  </div>
</div>

<!-- Tabel User -->
<div class="card">
  <div class="table-responsive">
    <table class="data-table">
      <thead>
        <tr>
          <th style="width:36px">#</th>
          <th>Nama</th>
          <th>Username</th>
          <th>Email</th>
          <th>Role</th>
          <th>Bagian</th>
          <th>Status</th>
          <th style="width:100px;text-align:center">Aksi</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($users as $i=>$u): ?>
      <tr style="<?= !$u['aktif']?'opacity:.55':'' ?>">
        <td style="color:var(--text-muted)"><?= $i+1 ?></td>
        <td>
          <div style="display:flex;align-items:center;gap:8px">
            <div style="width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--accent));color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.8rem;flex-shrink:0"><?= strtoupper(substr($u['nama'],0,1)) ?></div>
            <span style="font-weight:600;font-size:.88rem"><?= xss($u['nama']) ?></span>
          </div>
        </td>
        <td><code style="font-size:.8rem"><?= xss($u['username']) ?></code></td>
        <td style="font-size:.8rem"><?= xss($u['email']) ?></td>
        <td>
          <?php
            $roleCls = ['Admin'=>'badge-danger','Risk Manager'=>'badge-warning','Staff'=>'badge-info','Koordinator'=>'badge-primary','Pimpinan'=>'badge-success'];
            echo '<span class="badge '.($roleCls[$u['role']]??'badge-secondary').'">'.xss($u['role']).'</span>';
          ?>
        </td>
        <td style="font-size:.82rem">
          <?php if (!empty($u['bagian_nama'])): ?>
            <span><?= xss($u['bagian_nama']) ?></span>
          <?php else: ?>
            <span style="color:var(--text-muted);font-size:.78rem">&#8212;</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if($u['aktif']): ?>
          <span class="badge badge-success"><i class="fas fa-check"></i> Aktif</span>
          <?php else: ?>
          <span class="badge badge-secondary"><i class="fas fa-ban"></i> Nonaktif</span>
          <?php endif; ?>
        </td>
        <td>
          <div class="user-act-group">
            <button type="button" class="act-btn act-btn-edit" onclick="editUser(usersData[<?= $u['id'] ?>])" title="Edit"><i class="fas fa-edit"></i></button>
            <?php if($u['id'] !== (int)$_SESSION['user_id']): ?>
            <form method="POST" style="display:contents"><?= csrfField() ?>
              <input type="hidden" name="aksi" value="toggle"><input type="hidden" name="id" value="<?= $u['id'] ?>">
              <button type="submit" class="act-btn act-btn-toggle" title="<?= $u['aktif']?'Nonaktifkan':'Aktifkan' ?>"><i class="fas fa-power-off"></i></button>
            </form>
            <button type="button" class="act-btn act-btn-delete" onclick="hapusUser(<?= (int)$u['id'] ?>)" title="Hapus"><i class="fas fa-trash"></i></button>
            <?php else: ?>
            <button type="button" class="act-btn act-btn-toggle" disabled title="Tidak bisa dinonaktifkan"><i class="fas fa-power-off"></i></button>
            <button type="button" class="act-btn act-btn-delete" disabled title="Tidak bisa dihapus"><i class="fas fa-trash"></i></button>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal Kelola Bagian -->
<div class="modal-overlay" id="modalBagian" style="display:none">
  <div class="modal" style="max-width:600px">
    <div class="modal-header">
      <h3 class="modal-title"><i class="fas fa-sitemap" style="color:var(--primary)"></i> Kelola Bagian</h3>
      <button class="btn-close" onclick="closeModal('modalBagian')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" style="padding-bottom:0">
      <form method="POST" action="<?= APP_URL ?>/?page=user" id="formBagian">
        <?= csrfField() ?>
        <input type="hidden" name="aksi" value="simpan_bagian">
        <input type="hidden" name="id" id="bId" value="0">
        <div style="background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px 16px;margin-bottom:12px">
          <p style="font-size:.75rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px" id="formBagianLabel">Tambah Bagian Baru</p>
          <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
            <div class="form-group" style="flex:1;min-width:160px;margin-bottom:0">
              <label class="form-label" style="font-size:.8rem">Nama Bagian <span class="required">*</span></label>
              <input type="text" name="nama_bagian" id="bNama" class="form-control" placeholder="contoh: Tim Kerja, Sub Bagian Umum" required>
            </div>
            <div class="form-group" style="flex:1;min-width:140px;margin-bottom:0">
              <label class="form-label" style="font-size:.8rem">Deskripsi <small style="color:var(--text-muted)">(opsional)</small></label>
              <input type="text" name="deskripsi_bagian" id="bDeskripsi" class="form-control" placeholder="Deskripsi singkat">
            </div>
            <div style="display:flex;align-items:center;gap:6px;padding-bottom:4px;white-space:nowrap">
              <input type="checkbox" name="aktif_bagian" id="bAktif" checked style="width:16px;height:16px;accent-color:var(--accent)">
              <label for="bAktif" style="cursor:pointer;font-size:.82rem">Aktif</label>
            </div>
            <div style="display:flex;gap:6px;padding-bottom:4px">
              <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Simpan</button>
              <button type="button" class="btn btn-outline btn-sm" onclick="resetFormBagian()" title="Batal edit"><i class="fas fa-times"></i></button>
            </div>
          </div>
        </div>
      </form>
      <div style="max-height:300px;overflow-y:auto">
        <?php if (empty($bagianList)): ?>
          <p style="text-align:center;color:var(--text-muted);padding:20px 0;font-size:.88rem">Belum ada bagian. Tambahkan bagian pertama di atas.</p>
        <?php else: ?>
        <table class="data-table" style="font-size:.83rem">
          <thead><tr><th style="width:36px">#</th><th>Nama Bagian</th><th>Deskripsi</th><th style="width:70px">Status</th><th style="width:80px;text-align:center">Aksi</th></tr></thead>
          <tbody>
          <?php foreach($bagianList as $bi=>$b): ?>
          <tr>
            <td style="color:var(--text-muted)"><?= $bi+1 ?></td>
            <td style="font-weight:600"><?= xss($b['nama']) ?></td>
            <td style="color:var(--text-muted);font-size:.8rem"><?= !empty($b['deskripsi']) ? xss($b['deskripsi']) : '&mdash;' ?></td>
            <td>
              <?php if($b['aktif']): ?>
                <span class="badge badge-success" style="font-size:.7rem">Aktif</span>
              <?php else: ?>
                <span class="badge badge-secondary" style="font-size:.7rem">Nonaktif</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="user-act-group" style="justify-content:center">
                <button type="button" class="act-btn act-btn-edit" onclick="editBagian(<?= (int)$b['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                <button type="button" class="act-btn act-btn-delete" onclick="hapusBagian(<?= (int)$b['id'] ?>)" title="Hapus"><i class="fas fa-trash"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" onclick="closeModal('modalBagian')" class="btn btn-outline">Tutup</button>
    </div>
  </div>
</div>

<!-- Modal Hapus Bagian -->
<div class="modal-overlay" id="modalHapusBagian" style="display:none;z-index:450">
  <div class="modal" style="max-width:400px">
    <div class="modal-header"><h3 class="modal-title" style="color:var(--danger)"><i class="fas fa-trash"></i> Hapus Bagian</h3><button class="btn-close" onclick="closeModal('modalHapusBagian')"><i class="fas fa-times"></i></button></div>
    <div class="modal-body"><p>Yakin hapus bagian <strong id="hapusBagianNama"></strong>?<br><small style="color:var(--text-muted)">Bagian hanya dapat dihapus jika tidak ada user yang menggunakannya.</small></p></div>
    <div class="modal-footer">
      <form method="POST"><?= csrfField() ?><input type="hidden" name="aksi" value="hapus_bagian"><input type="hidden" name="id" id="hapusBagianId">
      <button type="button" onclick="closeModal('modalHapusBagian')" class="btn btn-outline">Batal</button>
      <button type="submit" class="btn btn-danger">Hapus</button></form>
    </div>
  </div>
</div>

<!-- Modal User -->
<div class="modal-overlay" id="modalUser" style="display:none">
  <div class="modal" style="max-width:540px">
    <div class="modal-header">
      <h3 class="modal-title" id="modalUserTitle"><i class="fas fa-user-plus"></i> Tambah User</h3>
      <button class="btn-close" onclick="closeModal('modalUser')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/?page=user">
    <?= csrfField() ?>
    <input type="hidden" name="aksi" value="simpan">
    <input type="hidden" name="id" id="uId" value="0">
    <div class="modal-body" style="max-height:72vh;overflow-y:auto">
      <div class="form-row-2" style="gap:10px">
        <div class="form-group" style="margin-bottom:10px"><label class="form-label">Nama Lengkap <span class="required">*</span></label><input type="text" name="nama" id="uNama" class="form-control" required></div>
        <div class="form-group" style="margin-bottom:10px"><label class="form-label">NIP</label><input type="text" name="nip" id="uNip" class="form-control" placeholder="Kosongkan jika tidak ada"></div>
        <div class="form-group" style="margin-bottom:10px"><label class="form-label">Username <span class="required">*</span></label><input type="text" name="username" id="uUsername" class="form-control" required autocomplete="off"></div>
        <div class="form-group" style="margin-bottom:10px"><label class="form-label">Email <span class="required">*</span></label><input type="email" name="email" id="uEmail" class="form-control" required></div>
        <div class="form-group" style="margin-bottom:10px"><label class="form-label">Role</label>
          <select name="role" id="uRole" class="form-control">
            <option>Admin</option><option>Risk Manager</option><option>Pimpinan</option><option>Koordinator</option><option>Staff</option>
          </select>
        </div>
        <div class="form-group" style="margin-bottom:10px"><label class="form-label">Bagian</label>
          <select name="bagian_id" id="uBagianId" class="form-control">
            <option value="">— Tidak ada —</option>
            <?php foreach($bagianList as $b): ?>
              <?php if($b['aktif']): ?>
              <option value="<?= $b['id'] ?>"><?= xss($b['nama']) ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin-bottom:10px;position:relative">
          <label class="form-label">Password <span id="pwHint" style="color:var(--text-muted);font-weight:400;font-size:.78rem">(wajib untuk user baru)</span></label>
          <div style="position:relative">
            <input type="password" name="password" id="uPassword" class="form-control" autocomplete="new-password" placeholder="Kosongkan jika tidak diubah" style="padding-right:40px">
            <button type="button" onclick="togglePw()" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);padding:4px" title="Tampilkan/Sembunyikan" tabindex="-1">
              <i class="fas fa-eye" id="pwIcon"></i>
            </button>
          </div>
        </div>
        <div class="form-group" style="margin-bottom:10px;display:flex;align-items:center;gap:8px;padding-top:26px">
          <input type="checkbox" name="aktif" id="uAktif" checked style="width:16px;height:16px;accent-color:var(--accent)">
          <label for="uAktif" style="cursor:pointer;font-size:.88rem">Akun Aktif</label>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" onclick="closeModal('modalUser')" class="btn btn-outline">Batal</button>
      <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan</button>
    </div>
    </form>
  </div>
</div>

<!-- Modal Hapus User -->
<div class="modal-overlay" id="modalHapusUser" style="display:none">
  <div class="modal" style="max-width:400px">
    <div class="modal-header"><h3 class="modal-title" style="color:var(--danger)"><i class="fas fa-user-times"></i> Hapus User</h3><button class="btn-close" onclick="closeModal('modalHapusUser')"><i class="fas fa-times"></i></button></div>
    <div class="modal-body"><p>Yakin hapus user <strong id="hapusUserNama"></strong>?<br><small style="color:var(--text-muted)">Log aktivitas user ini akan tetap tersimpan.</small></p></div>
    <div class="modal-footer">
      <form method="POST"><?= csrfField() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" id="hapusUserId">
      <button type="button" onclick="closeModal('modalHapusUser')" class="btn btn-outline">Batal</button>
      <button type="submit" class="btn btn-danger">Hapus</button></form>
    </div>
  </div>
</div>

<script>
const usersData = <?= json_encode(array_column($users, null, 'id')) ?>;
const bagianData = <?= json_encode(array_column($bagianList, null, 'id')) ?>;

<?php if (!empty($_GET['bagian'])): ?>
document.addEventListener('DOMContentLoaded', function() {
  openModal('modalBagian');
});
<?php endif; ?>

function tambahUser() {
  document.getElementById('modalUserTitle').innerHTML='<i class="fas fa-user-plus"></i> Tambah User';
  document.getElementById('uId').value=0;
  document.getElementById('uNama').value='';
  document.getElementById('uNip').value='';
  document.getElementById('uUsername').value='';
  document.getElementById('uEmail').value='';
  document.getElementById('uRole').value='Staff';
  document.getElementById('uBagianId').value='';
  document.getElementById('uAktif').checked=true;
  document.getElementById('uPassword').value='';
  document.getElementById('uPassword').placeholder='Password Baru';
  document.getElementById('uPassword').setAttribute('required','required');
  document.getElementById('pwHint').textContent='(wajib untuk user baru)';
  openModal('modalUser');
}
function editUser(u){
  document.getElementById('modalUserTitle').innerHTML='<i class="fas fa-user-edit"></i> Edit User';
  document.getElementById('uId').value=u.id;
  document.getElementById('uNama').value=u.nama;
  document.getElementById('uNip').value=u.nip||'';
  document.getElementById('uUsername').value=u.username;
  document.getElementById('uEmail').value=u.email;
  document.getElementById('uRole').value=u.role;
  document.getElementById('uBagianId').value=u.bagian_id||'';
  document.getElementById('uAktif').checked=u.aktif==1;
  document.getElementById('uPassword').placeholder='Kosongkan jika tidak diubah';
  document.getElementById('uPassword').removeAttribute('required');
  document.getElementById('pwHint').textContent='(kosongkan jika tidak diubah)';
  openModal('modalUser');
}
function hapusUser(id){
  const u = usersData[id];
  if (!u) return;
  document.getElementById('hapusUserId').value=u.id;
  document.getElementById('hapusUserNama').textContent=u.nama;
  openModal('modalHapusUser');
}
function togglePw(){
  var inp=document.getElementById('uPassword');
  var icon=document.getElementById('pwIcon');
  if(inp.type==='password'){inp.type='text';icon.classList.replace('fa-eye','fa-eye-slash');}
  else{inp.type='password';icon.classList.replace('fa-eye-slash','fa-eye');}
}
function editBagian(id){
  const b = bagianData[id];
  if (!b) return;
  document.getElementById('bId').value=b.id;
  document.getElementById('bNama').value=b.nama;
  document.getElementById('bDeskripsi').value=b.deskripsi||'';
  document.getElementById('bAktif').checked=(b.aktif==1 || b.aktif==='1');
  document.getElementById('formBagianLabel').textContent='Edit Bagian';
  document.getElementById('bNama').focus();
}
function resetFormBagian(){
  document.getElementById('bId').value=0;
  document.getElementById('bNama').value='';
  document.getElementById('bDeskripsi').value='';
  document.getElementById('bAktif').checked=true;
  document.getElementById('formBagianLabel').textContent='Tambah Bagian Baru';
}
function hapusBagian(id){
  const b = bagianData[id];
  if (!b) return;
  document.getElementById('hapusBagianId').value=b.id;
  document.getElementById('hapusBagianNama').textContent=b.nama;
  openModal('modalHapusBagian');
}
</script>
