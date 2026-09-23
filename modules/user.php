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

    if ($aksi === 'simpan') {
        $nama     = trim($_POST['nama'] ?? '');
        $nip      = trim($_POST['nip'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $role     = $_POST['role'] ?? 'Staff';
        $aktif    = isset($_POST['aktif']) ? 1 : 0;
        $password = $_POST['password'] ?? '';

        // Validasi role — whitelist
        $validRoles = ['Admin', 'Risk Manager', 'Staff', 'Pimpinan'];
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
                $s = $db->prepare('UPDATE users SET nama=?,nip=?,username=?,email=?,role=?,aktif=?,password=? WHERE id=?');
                // nama(s) nip(s) username(s) email(s) role(s) aktif(i) password(s) id(i)
                $s->bind_param('sssssisi',$nama,$nip,$username,$email,$role,$aktif,$hash,$id);
            } else {
                $s = $db->prepare('UPDATE users SET nama=?,nip=?,username=?,email=?,role=?,aktif=? WHERE id=?');
                $s->bind_param('sssssii',$nama,$nip,$username,$email,$role,$aktif,$id);
            }
            $s->execute(); $s->close();

            // Jika role user diubah, regenerate session ID agar sesi lama
            // (dengan privilege lama) tidak bisa dilanjutkan bila tercuri.
            $oldRoleStmt = $db->prepare('SELECT role FROM users WHERE id = ?');
            $oldRoleStmt->bind_param('i', $id);
            $oldRoleStmt->execute();
            $oldRole = (string)($oldRoleStmt->get_result()->fetch_column() ?: '');
            $oldRoleStmt->close();
            if ($oldRole !== $role) {
                session_regenerate_id(true);
                // Sinkronkan session bila yang diedit adalah akun sendiri
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
                // Jika role berubah dari RM ke non-RM, kosongkan prefix (kode_risiko lama tetap)
                $db->query("UPDATE users SET kode_prefix=NULL WHERE id=$id AND kode_prefix IS NOT NULL");
            }

            logAktivitas('UPDATE','user',$id,'Update user: '.$username);
            setFlash('success','User berhasil diperbarui');
        } else {
            // Insert
            if (empty($password)) { setFlash('error','Password wajib diisi untuk user baru'); header('Location: '.APP_URL.'/?page=user'); exit; }
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost'=>12]);
            $s = $db->prepare('INSERT INTO users (nama,nip,username,email,password,role,aktif) VALUES (?,?,?,?,?,?,?)');
            $s->bind_param('ssssssi',$nama,$nip,$username,$email,$hash,$role,$aktif);
            $s->execute(); $newId=$db->insert_id; $s->close();

            // Auto-assign kode_prefix utk Risk Manager baru
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

$users = $db->query("SELECT id, nama, nip, username, email, role, kode_prefix, aktif, created_at FROM users ORDER BY role, nama")->fetch_all(MYSQLI_ASSOC);
?>

<div class="page-header-row">
  <div class="page-header">
    <h1 class="page-title"><i class="fas fa-users-cog" style="color:var(--primary)"></i> Manajemen User</h1>
    <p class="page-sub">Kelola akun pengguna dan hak akses sistem</p>
  </div>
  <div class="page-actions">
    <button class="btn btn-primary" onclick="tambahUser()"><i class="fas fa-user-plus"></i> Tambah User</button>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="data-table">
      <thead><tr><th>#</th><th>Nama</th><th>Username</th><th>Email</th><th>Role</th><th>Kode Prefix</th><th>Status</th><th>Terdaftar</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php foreach($users as $i=>$u): ?>
      <tr style="<?= !$u['aktif']?'opacity:.55':'' ?>">
        <td style="color:var(--text-muted)"><?= $i+1 ?></td>
        <td>
          <div style="display:flex;align-items:center;gap:10px">
            <div style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--accent));color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;flex-shrink:0"><?= strtoupper(substr($u['nama'],0,1)) ?></div>
            <span style="font-weight:600"><?= xss($u['nama']) ?></span>
          </div>
        </td>
        <td><code style="font-size:.82rem"><?= xss($u['username']) ?></code></td>
        <td style="font-size:.82rem"><?= xss($u['email']) ?></td>
        <td>
          <?php
            $roleCls = ['Admin'=>'badge-danger','Risk Manager'=>'badge-warning','Staff'=>'badge-info'];
            echo '<span class="badge '.($roleCls[$u['role']]??'badge-secondary').'">'.xss($u['role']).'</span>';
          ?>
        </td>
        <td>
          <?php if (!empty($u['kode_prefix'])): ?>
            <code style="font-size:.82rem;color:var(--accent);background:var(--surface2);padding:2px 8px;border-radius:4px;font-weight:700"><?= xss($u['kode_prefix']) ?></code>
          <?php else: ?>
            <span style="color:var(--text-muted);font-size:.78rem">—</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if($u['aktif']): ?>
          <span class="badge badge-success"><i class="fas fa-check"></i> Aktif</span>
          <?php else: ?>
          <span class="badge badge-secondary"><i class="fas fa-ban"></i> Nonaktif</span>
          <?php endif; ?>
        </td>
        <td style="font-size:.78rem;color:var(--text-muted)"><?= tglIndo($u['created_at']) ?></td>
        <td>
          <div class="act-btn-group">
            <button type="button" class="act-btn act-btn-edit" onclick="editUser(usersData[<?= $u['id'] ?>])" title="Edit"><i class="fas fa-edit"></i></button>
            <?php if($u['id'] !== (int)$_SESSION['user_id']): ?>
            <form method="POST" style="display:inline"><?= csrfField() ?>
              <input type="hidden" name="aksi" value="toggle"><input type="hidden" name="id" value="<?= $u['id'] ?>">
              <button type="submit" class="act-btn act-btn-toggle" title="<?= $u['aktif']?'Nonaktifkan':'Aktifkan' ?>"><i class="fas fa-power-off"></i></button>
            </form>
            <button type="button" class="act-btn act-btn-delete" onclick="hapusUser(<?= $u['id'] ?>, '<?= xss($u['nama']) ?>')" title="Hapus"><i class="fas fa-trash"></i></button>
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

<!-- Modal User -->
<div class="modal-overlay" id="modalUser" style="display:none">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title" id="modalUserTitle"><i class="fas fa-user-plus"></i> Tambah User</h3>
      <button class="btn-close" onclick="closeModal('modalUser')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/?page=user">
    <?= csrfField() ?>
    <input type="hidden" name="aksi" value="simpan">
    <input type="hidden" name="id" id="uId" value="0">
    <div class="modal-body">
      <div class="form-row-2">
        <div class="form-group"><label class="form-label">Nama Lengkap <span class="required">*</span></label><input type="text" name="nama" id="uNama" class="form-control" required></div>
        <div class="form-group"><label class="form-label">NIP</label><input type="text" name="nip" id="uNip" class="form-control" placeholder="Kosongkan jika tidak ada"></div>
        <div class="form-group"><label class="form-label">Username <span class="required">*</span></label><input type="text" name="username" id="uUsername" class="form-control" required autocomplete="off"></div>
        <div class="form-group"><label class="form-label">Email <span class="required">*</span></label><input type="email" name="email" id="uEmail" class="form-control" required></div>
        <div class="form-group"><label class="form-label">Role</label>
          <select name="role" id="uRole" class="form-control">
            <option>Admin</option><option>Risk Manager</option><option>Pimpinan</option><option>Staff</option>
          </select>
        </div>
        <div class="form-group" style="position:relative">
          <label class="form-label">Password <span id="pwHint" style="color:var(--text-muted);font-weight:400">(wajib untuk user baru)</span></label>
          <div style="position:relative">
            <input type="password" name="password" id="uPassword" class="form-control" autocomplete="new-password" placeholder="Kosongkan jika tidak diubah" style="padding-right:42px">
            <button type="button" onclick="togglePw()" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);padding:4px" title="Tampilkan/Sembunyikan password" tabindex="-1">
              <i class="fas fa-eye" id="pwIcon"></i>
            </button>
          </div>
        </div>
        <div class="form-group" style="display:flex;align-items:center;gap:10px;padding-top:28px">
          <input type="checkbox" name="aktif" id="uAktif" checked style="width:18px;height:18px;accent-color:var(--accent)">
          <label for="uAktif" style="cursor:pointer">Akun Aktif</label>
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

function tambahUser() {
  document.getElementById('modalUserTitle').innerHTML='<i class="fas fa-user-plus"></i> Tambah User';
  document.getElementById('uId').value=0;
  document.getElementById('uNama').value='';
  document.getElementById('uNip').value='';
  document.getElementById('uUsername').value='';
  document.getElementById('uEmail').value='';
  document.getElementById('uRole').value='Staff';
  document.getElementById('uAktif').checked=true;
  document.getElementById('uPassword').value='';
  document.getElementById('uPassword').placeholder='Password Baru';
  document.getElementById('uPassword').setAttribute('required', 'required');
  document.getElementById('pwHint').textContent='(wajib untuk user baru)';
  openModal('modalUser');
}
function editUser(u){
  document.getElementById('modalUserTitle').innerHTML='<i class="fas fa-user-edit"></i> Edit User';
  document.getElementById('uId').value=u.id;
  document.getElementById('uNama').value=u.nama;
  document.getElementById('uNip').value=u.nip || '';
  document.getElementById('uUsername').value=u.username;
  document.getElementById('uEmail').value=u.email;
  document.getElementById('uRole').value=u.role;
  document.getElementById('uAktif').checked=u.aktif==1;
  document.getElementById('uPassword').placeholder='Kosongkan jika tidak diubah';
  document.getElementById('uPassword').removeAttribute('required');
  document.getElementById('pwHint').textContent='(kosongkan jika tidak diubah)';
  openModal('modalUser');
}
function hapusUser(id,nama){document.getElementById('hapusUserId').value=id;document.getElementById('hapusUserNama').textContent=nama;openModal('modalHapusUser')}
function togglePw(){
  var inp=document.getElementById('uPassword');
  var icon=document.getElementById('pwIcon');
  if(inp.type==='password'){inp.type='text';icon.classList.replace('fa-eye','fa-eye-slash');}
  else{inp.type='password';icon.classList.replace('fa-eye-slash','fa-eye');}
}
</script>
