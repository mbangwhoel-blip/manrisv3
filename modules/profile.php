<?php
/**
 * MODUL PROFIL USER
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { setFlash('error','Token tidak valid'); header('Location: '.APP_URL.'/?page=profile'); exit; }

    $nama     = trim($_POST['nama'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $pwLama   = $_POST['pw_lama'] ?? '';
    $pwBaru   = $_POST['pw_baru'] ?? '';
    $pwKonfirm= $_POST['pw_konfirm'] ?? '';
    $uid      = (int)$_SESSION['user_id'];

    if (empty($nama) || empty($email)) { setFlash('error','Nama dan email wajib diisi'); header('Location: '.APP_URL.'/?page=profile'); exit; }

    $s = $db->prepare('SELECT password FROM users WHERE id=?');
    $s->bind_param('i',$uid); $s->execute();
    $current = $s->get_result()->fetch_assoc(); $s->close();

    if (!empty($pwBaru)) {
        if (!password_verify($pwLama, $current['password'])) { setFlash('error','Password lama salah'); header('Location: '.APP_URL.'/?page=profile'); exit; }
        if ($pwBaru !== $pwKonfirm) { setFlash('error','Konfirmasi password tidak cocok'); header('Location: '.APP_URL.'/?page=profile'); exit; }
        if (strlen($pwBaru) < 8) { setFlash('error','Password minimal 8 karakter'); header('Location: '.APP_URL.'/?page=profile'); exit; }
        if (!preg_match('/[A-Za-z]/', $pwBaru) || !preg_match('/[0-9]/', $pwBaru)) {
            setFlash('error','Password harus mengandung huruf dan angka'); header('Location: '.APP_URL.'/?page=profile'); exit;
        }
        $hash = password_hash($pwBaru, PASSWORD_BCRYPT, ['cost'=>12]);
        $s2 = $db->prepare('UPDATE users SET nama=?,email=?,password=? WHERE id=?');
        $s2->bind_param('sssi',$nama,$email,$hash,$uid); $s2->execute(); $s2->close();
        setFlash('success','Profil dan password berhasil diperbarui');
    } else {
        $s2 = $db->prepare('UPDATE users SET nama=?,email=? WHERE id=?');
        $s2->bind_param('ssi',$nama,$email,$uid); $s2->execute(); $s2->close();
        setFlash('success','Profil berhasil diperbarui');
    }
    $_SESSION['user_nama'] = $nama;
    header('Location: '.APP_URL.'/?page=profile'); exit;
}

$uid = (int)$_SESSION['user_id'];
$me  = $db->query("SELECT * FROM users WHERE id=$uid")->fetch_assoc();

// Statistik user
$totalRisiko = $db->query("SELECT COUNT(*) FROM risiko WHERE id_user_input=$uid")->fetch_row()[0] ?? 0;
$totalLog    = $db->query("SELECT COUNT(*) FROM log_aktivitas WHERE id_user=$uid")->fetch_row()[0] ?? 0;
?>

<div class="page-header-row">
  <div class="page-header">
    <h1 class="page-title"><i class="fas fa-user-circle" style="color:var(--accent)"></i> Profil Saya</h1>
    <p class="page-sub">Kelola informasi akun dan keamanan Anda</p>
  </div>
</div>

<div style="display:grid;grid-template-columns:280px 1fr;gap:20px;align-items:start" class="profile-grid">
  <!-- Kartu profil -->
  <div class="card" style="text-align:center">
    <div class="card-body">
      <div style="width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--accent));color:#fff;display:flex;align-items:center;justify-content:center;font-size:2rem;font-weight:800;margin:0 auto 14px"><?= strtoupper(substr($me['nama'],0,1)) ?></div>
      <div style="font-size:1.1rem;font-weight:700"><?= xss($me['nama']) ?></div>
      <div style="color:var(--text-muted);font-size:.82rem;margin:4px 0"><?= xss($me['email']) ?></div>
      <div style="margin-top:8px">
        <?php $roleCls=['Admin'=>'badge-danger','Risk Manager'=>'badge-warning','Staff'=>'badge-info'];
        echo '<span class="badge '.($roleCls[$me['role']]??'badge-secondary').'">'.xss($me['role']).'</span>'; ?>
      </div>
      <div style="display:flex;justify-content:center;gap:24px;margin-top:20px;padding-top:16px;border-top:1px solid var(--border)">
        <div style="text-align:center"><div style="font-size:1.4rem;font-weight:800;color:var(--accent)"><?= $totalRisiko ?></div><div style="font-size:.72rem;color:var(--text-muted)">Risiko Diinput</div></div>
        <div style="text-align:center"><div style="font-size:1.4rem;font-weight:800;color:var(--primary)"><?= $totalLog ?></div><div style="font-size:.72rem;color:var(--text-muted)">Total Aktivitas</div></div>
      </div>
      <div style="margin-top:14px;font-size:.75rem;color:var(--text-muted)">Bergabung: <?= tglIndo($me['created_at']) ?></div>
    </div>
  </div>

  <!-- Form edit -->
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fas fa-edit"></i> Edit Profil</span></div>
    <form method="POST" action="<?= APP_URL ?>/?page=profile">
    <?= csrfField() ?>
    <div class="card-body">
      <div class="tabs">
        <button type="button" class="tab-btn active" onclick="switchTab('tabInfo',this)">Informasi Umum</button>
        <button type="button" class="tab-btn" onclick="switchTab('tabPw',this)">Ubah Password</button>
      </div>
      <div id="tabInfo" class="tab-content active">
        <div class="form-row-2">
          <div class="form-group"><label class="form-label">Nama Lengkap <span class="required">*</span></label><input type="text" name="nama" class="form-control" required value="<?= xss($me['nama']) ?>"></div>
          <div class="form-group"><label class="form-label">Email <span class="required">*</span></label><input type="email" name="email" class="form-control" required value="<?= xss($me['email']) ?>"></div>
          <div class="form-group"><label class="form-label">Username</label><input type="text" class="form-control" value="<?= xss($me['username']) ?>" readonly style="background:var(--surface2);cursor:not-allowed;opacity:.7"><div class="form-hint">Username tidak dapat diubah</div></div>
          <div class="form-group"><label class="form-label">Role</label><input type="text" class="form-control" value="<?= xss($me['role']) ?>" readonly style="background:var(--surface2);cursor:not-allowed;opacity:.7"></div>
        </div>
      </div>
      <div id="tabPw" class="tab-content">
        <div style="max-width:400px">
          <div class="form-group"><label class="form-label">Password Lama</label><input type="password" name="pw_lama" class="form-control" placeholder="Password saat ini"></div>
          <div class="form-group"><label class="form-label">Password Baru</label><input type="password" name="pw_baru" id="pwBaru" class="form-control" placeholder="Min. 8 karakter (huruf + angka)"></div>
          <div class="form-group"><label class="form-label">Konfirmasi Password Baru</label><input type="password" name="pw_konfirm" id="pwKonfirm" class="form-control" placeholder="Ulangi password baru" oninput="cekKonfirm()"></div>
          <div id="konfirmMsg" style="font-size:.78rem;margin-top:-8px"></div>
        </div>
      </div>
    </div>
    <div class="card-footer">
      <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Perubahan</button>
    </div>
    </form>
  </div>
</div>

<script>
function switchTab(id, btn){
  document.querySelectorAll('.tab-content').forEach(t=>t.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  document.getElementById(id).classList.add('active');
  btn.classList.add('active');
}
function cekKonfirm(){
  const a=document.getElementById('pwBaru').value;
  const b=document.getElementById('pwKonfirm').value;
  const el=document.getElementById('konfirmMsg');
  if(!b) { el.textContent=''; return; }
  if(a===b){ el.textContent='✓ Password cocok'; el.style.color='var(--success)'; }
  else     { el.textContent='✗ Password tidak cocok'; el.style.color='var(--danger)'; }
}
</script>
<style>
@media(max-width:768px){ .profile-grid{ grid-template-columns:1fr!important } }
</style>
