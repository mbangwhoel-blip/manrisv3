<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
$timeout = isset($_GET['timeout']) && $_GET['timeout'] == 1;
?>
<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SEMAR — Sistem Informasi Manajemen Risiko</title>
<?php $faviconVersion = @filemtime(__DIR__ . '/../favicon-mark.png') ?: 1; ?>
<link rel="icon" type="image/png" sizes="64x64" href="favicon-mark.png?v=<?= $faviconVersion ?>">
<link rel="apple-touch-icon" sizes="64x64" href="favicon-mark.png?v=<?= $faviconVersion ?>">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{
  font-family:'Inter',sans-serif;
  height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;
  background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 50%,#1e3a5f 100%);
  position:relative;overflow:hidden;
}
.orb{
  position:absolute;border-radius:50%;filter:blur(80px);pointer-events:none;opacity:.25;
}
.orb1{width:500px;height:500px;background:#3b82f6;top:-100px;left:-100px}
.orb2{width:400px;height:400px;background:#1e3a5f;bottom:-80px;right:-80px}
.orb3{width:300px;height:300px;background:#60a5fa;top:50%;right:15%;transform:translateY(-50%)}

.login-card{
  position:relative;z-index:10;
  width:100%;max-width:420px;margin:10px 20px;
  background:rgba(255,255,255,.07);
  backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
  border:1px solid rgba(255,255,255,.12);
  border-radius:20px;padding:30px 36px;
  box-shadow:0 25px 50px rgba(0,0,0,.4),0 0 0 1px rgba(255,255,255,.05);
}
.login-logo{
  width:68px;height:68px;border-radius:18px;
  background:linear-gradient(135deg,#3b82f6,#1e3a5f);
  display:flex;align-items:center;justify-content:center;
  margin:0 auto 20px;font-size:2rem;color:#fff;
  box-shadow:0 8px 24px rgba(59,130,246,.4);
}
.login-title{
  text-align:center;color:#fff;
  font-size:1.15rem;font-weight:700;letter-spacing:-.02em;
  line-height:1.2;white-space:nowrap;
}
.login-sub{
  text-align:center;color:#2dd4bf;
  font-size:.85rem;font-weight:600;
  margin-top:6px;margin-bottom:28px;
  letter-spacing:0;line-height:1.4;white-space:nowrap;
}
.lbl{display:block;font-size:.78rem;font-weight:600;color:rgba(255,255,255,.7);margin-bottom:7px}
.inp{
  width:100%;padding:12px 14px 12px 42px;
  background:rgba(255,255,255,.08);border:1.5px solid rgba(255,255,255,.1);
  border-radius:10px;color:#fff;font-size:.88rem;font-family:'Inter',sans-serif;
  outline:none;transition:all .22s;
}
.inp:focus{background:rgba(255,255,255,.12);border-color:rgba(59,130,246,.6);box-shadow:0 0 0 3px rgba(59,130,246,.2)}
.inp::placeholder{color:rgba(255,255,255,.55)}
.inp-wrap{position:relative;margin-bottom:18px}
.inp-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:rgba(255,255,255,.4);font-size:.9rem}
.pw-toggle{position:absolute;right:14px;top:50%;transform:translateY(-50%);color:rgba(255,255,255,.35);cursor:pointer;background:none;border:none;font-size:.9rem}
.btn-login{
  width:100%;padding:13px;margin-top:8px;
  background:linear-gradient(135deg,#3b82f6,#2563eb);
  border:none;border-radius:10px;color:#fff;
  font-size:.9rem;font-weight:700;cursor:pointer;
  transition:all .22s;letter-spacing:.02em;
  box-shadow:0 4px 14px rgba(59,130,246,.4);
}
.btn-login:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(59,130,246,.5)}
.btn-login:active{transform:scale(.98)}
.forgot-pw {
  display: block;
  text-align: right;
  font-size: .8rem;
  color: rgba(255,255,255,.85);
  text-decoration: none;
  margin-top: -10px;
  margin-bottom: 18px;
  transition: color .22s;
}
.forgot-pw:hover {
  color: #60a5fa;
}
.alert{
  padding:11px 14px;border-radius:10px;font-size:.82rem;
  margin-bottom:18px;display:flex;align-items:center;gap:8px;
}
.alert-error{background:rgba(220,38,38,.15);border:1px solid rgba(220,38,38,.3);color:#fca5a5}
.alert-success{background:rgba(22,163,74,.15);border:1px solid rgba(22,163,74,.3);color:#86efac}
.alert-info{background:rgba(2,132,199,.15);border:1px solid rgba(2,132,199,.3);color:#7dd3fc}

/* Perbaikan Autofill Browser agar icon tetap terlihat */
.inp:-webkit-autofill,
.inp:-webkit-autofill:hover, 
.inp:-webkit-autofill:focus, 
.inp:-webkit-autofill:active {
    -webkit-box-shadow: 0 0 0 30px #1e3a5f inset !important;
    -webkit-text-fill-color: white !important;
    transition: background-color 5000s ease-in-out 0s;
}

.login-logo-wrapper { text-align: center; margin-bottom: 22px; }
.login-logo-img { width: 100%; max-width: 340px; height: auto; object-fit: contain; display: block; margin: 0 auto; }
.login-divider { height: 1px; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent); margin-bottom: 22px; }
.login-acronym { text-align:center; font-size:1.4rem; font-weight:800; color:#CCFF00; letter-spacing:0.18em; margin:2px 0 10px; }
.login-footer-text { margin-top: 25px; text-align: center; color: rgba(255,255,255,0.4); font-size: 0.72rem; }
.back-to-home {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    width: max-content; margin: 18px auto 0; padding: 10px 20px;
    color: #e2e8f0; font-size: 0.9rem; font-weight: 700; letter-spacing: .01em;
    text-decoration: none; border: 1px solid rgba(255,255,255,0.28); border-radius: 99px;
    background: rgba(255,255,255,0.07); transition: all .2s ease;
}
.back-to-home:hover { color: #fff; border-color: #60a5fa; background: rgba(96,165,250,0.18); transform: translateY(-1px); box-shadow: 0 6px 18px rgba(59,130,246,0.28); }
.back-to-home i { font-size: 0.82rem; transition: transform .2s ease; }
.back-to-home:hover i { transform: translateX(-3px); }
</style>
</head>
<body>
<div class="orb orb1"></div>
<div class="orb orb2"></div>
<div class="orb orb3"></div>

<div class="login-card">
  <!-- Area Logo Instansi -->
  <div class="login-logo-wrapper">
    <img src="<?= APP_URL ?>/assets/img/logo.png" alt="Logo Instansi" class="login-logo-img">
  </div>
  
  <!-- Garis Pemisah Halus -->
  <div class="login-divider"></div>
  
  <!-- Area Nama Aplikasi -->
  <h1 class="login-title">Sistem Informasi Manajemen Risiko</h1>
  <p class="login-acronym">(SEMAR)</p>
  <p class="login-sub">Balai Besar Laboratorium Kesehatan Lingkungan</p>

  <?php if ($timeout): ?>
  <div class="alert alert-info"><i class="fas fa-clock"></i> Sesi Anda telah berakhir. Silakan login kembali.</div>
  <?php endif; ?>

  <?php
  // Tampilkan flash message
  if (isset($_SESSION['flash'])) {
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $cls = $f['type'] === 'error' ? 'alert-error' : ($f['type'] === 'success' ? 'alert-success' : 'alert-info');
    $icon = $f['type'] === 'error' ? 'times-circle' : ($f['type'] === 'success' ? 'check-circle' : 'info-circle');
    echo '<div class="alert '.$cls.'"><i class="fas fa-'.$icon.'"></i> '.xss($f['msg']).'</div>';
  }
  ?>

  <form method="POST" action="<?= APP_URL ?>/index.php">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="login">
    <div class="inp-wrap">
      <i class="fas fa-user inp-icon"></i>
      <input type="text" name="username" class="inp" placeholder="Username" required autocomplete="username">
    </div>
    <div class="inp-wrap">
      <i class="fas fa-lock inp-icon"></i>
      <input type="password" name="password" id="pwInput" class="inp" placeholder="Password" required autocomplete="current-password">
      <button type="button" class="pw-toggle" id="pwToggle"><i class="fas fa-eye-slash" id="pwIcon"></i></button>
    </div>
    
    <a href="#" id="forgotPwBtn" class="forgot-pw">Lupa Password?</a>

    <button type="submit" class="btn-login"><i class="fas fa-sign-in-alt"></i> &nbsp;Masuk ke Sistem</button>
    <div class="login-footer-text">
      &copy; 2026 Balai Besar Laboratorium Kesehatan Lingkungan v.2
    </div>
  </form>
  <a href="<?= APP_URL ?>/" class="back-to-home">
    <i class="fas fa-arrow-left"></i> Kembali ke Beranda
  </a>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const pwToggle = document.getElementById('pwToggle');
  if (pwToggle) {
    pwToggle.addEventListener('click', () => {
      const i = document.getElementById('pwInput');
      const ic = document.getElementById('pwIcon');
      const isPassword = i.type === 'password';
      i.type = isPassword ? 'text' : 'password';
      ic.className = isPassword ? 'fas fa-eye' : 'fas fa-eye-slash';
    });
  }

  const forgotPwBtn = document.getElementById('forgotPwBtn');
  if (forgotPwBtn) {
    forgotPwBtn.addEventListener('click', (e) => {
      e.preventDefault();
      alert('Fasilitas Lupa Password saat ini belum dikonfigurasi dengan Email Server. Silakan hubungi Administrator IT untuk melakukan reset password Anda.');
    });
  }

  // Bersihkan seluruh riwayat chat AI saat berada di halaman login (setelah logout manual atau otomatis)
  try {
    for (var i = sessionStorage.length - 1; i >= 0; i--) {
      var k = sessionStorage.key(i);
      if (k && k.indexOf('manris_ai_chat') !== -1) sessionStorage.removeItem(k);
    }
    for (var j = localStorage.length - 1; j >= 0; j--) {
      var lk = localStorage.key(j);
      if (lk && lk.indexOf('manris_ai_chat') !== -1) localStorage.removeItem(lk);
    }
  } catch(e) {}
});
</script>
</body>
</html>
