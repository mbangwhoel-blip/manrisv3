<?php
/**
 * HEADER & SIDEBAR — Layout Utama
 */
$currentPage = $_GET['page'] ?? 'dashboard';
$theme = $_SESSION['theme'] ?? 'light';
if (isset($_COOKIE['theme']) && in_array($_COOKIE['theme'], ['light', 'dark'])) {
    $theme = $_COOKIE['theme'];
}
$_SESSION['theme'] = $theme; // Sinkronkan
$userRole = $_SESSION['user_role'] ?? 'Staff';
$userName = $_SESSION['user_nama'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="id" data-theme="<?= xss($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= APP_NAME ?> — <?= ucfirst(xss($currentPage)) ?></title>
<?php $faviconVersion = @filemtime(__DIR__ . '/../favicon-mark.png') ?: 1; ?>
<link rel="icon" type="image/png" sizes="64x64" href="favicon-mark.png?v=<?= $faviconVersion ?>">
<link rel="apple-touch-icon" sizes="64x64" href="favicon-mark.png?v=<?= $faviconVersion ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
<!-- Custom CSS -->
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= @filemtime(__DIR__ . '/../assets/css/main.css') ?: 0 ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/dark.css?v=<?= @filemtime(__DIR__ . '/../assets/css/dark.css') ?: 0 ?>" id="dark-stylesheet" <?= $theme !== 'dark' ? 'disabled' : '' ?>>
<!-- JS non-kritis: defer agar tidak blocking render halaman -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js" defer></script>
<!-- Chart.js tetap di head (defer) agar siap sebelum DOMContentLoaded -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
<script>
window.APP_URL = <?= jsEncode(APP_URL) ?>;
window.CSRF_TOKEN = <?= jsEncode(csrfToken()) ?>;
</script>
</head>
<body class="<?= $theme === 'dark' ? 'dark-mode' : '' ?>">

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand sidebar-brand-wrapper">
    <img src="<?= APP_URL ?>/assets/img/logo.png" alt="Logo Kemenkes" class="brand-logo">
    <div class="brand-text brand-text-wrapper">
      <span class="brand-title brand-title-text">Sistem Informasi Manajemen Risiko</span>
      <span class="brand-sub brand-sub-text">Balai Besar Laboratorium Kesehatan Lingkungan</span>
    </div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section-title nav-section-header">MENU UTAMA</div>
    <a href="<?= APP_URL ?>/?page=dashboard" class="nav-item <?= $currentPage==='dashboard'?'active':'' ?>">
      <i class="fas fa-chart-pie nav-icon-main"></i><span class="nav-text-main">Dashboard</span>
    </a>

    <?php if (hasRole('Pimpinan')): $pendAppr = function_exists('countPendingApprovals') ? countPendingApprovals() : 0; ?>
    <a href="<?= APP_URL ?>/?page=persetujuan" class="nav-item <?= $currentPage==='persetujuan'?'active':'' ?>" style="position:relative">
      <i class="fas fa-gavel nav-icon-main"></i><span class="nav-text-main">Persetujuan</span>
      <?php if ($pendAppr > 0): ?>
      <span class="notif-badge" style="position:absolute;right:14px;top:50%;transform:translateY(-50%);background:var(--danger);color:#fff;font-size:0.72rem;font-weight:800;padding:3px 7px;border-radius:99px;box-shadow:0 2px 6px rgba(220,38,38,0.4);min-width:20px;text-align:center;line-height:1.2;"><?= $pendAppr > 99 ? '99+' : $pendAppr ?></span>
      <?php endif; ?>
    </a>
    <?php endif; ?>

    <?php $isRiskGroupActive = in_array($currentPage, ['risiko', 'mitigasi', 'profil_risiko', 'kkpr', 'kkpmr', 'ikk']); ?>
    <div class="nav-group <?= $isRiskGroupActive ? 'is-active' : '' ?>">
      <button type="button" class="nav-group-title js-nav-group-toggle <?= $isRiskGroupActive ? 'expanded' : '' ?>" aria-expanded="<?= $isRiskGroupActive ? 'true' : 'false' ?>">
        <span class="nav-group-label">
          <i class="fas fa-shield-alt main-icon nav-icon-main"></i><span class="nav-text-main">Manajemen Risiko</span>
        </span>
        <i class="fas fa-chevron-down chevron"></i>
      </button>
      <div class="nav-group-items <?= $isRiskGroupActive ? 'show' : '' ?>">
        <a href="<?= APP_URL ?>/?page=risiko" class="nav-item <?= $currentPage==='risiko'?'active':'' ?>">
          <i class="fas fa-exclamation-triangle"></i><span>Identifikasi Risiko</span>
        </a>

        <a href="<?= APP_URL ?>/?page=profil_risiko" class="nav-item <?= $currentPage==='profil_risiko'?'active':'' ?>">
          <i class="fas fa-file-contract"></i><span>Profil Risiko</span>
        </a>
        <a href="<?= APP_URL ?>/?page=kkpr" class="nav-item <?= $currentPage==='kkpr'?'active':'' ?>">
          <i class="fas fa-clipboard-list"></i><span>Kertas Kerja Penilaian Risiko</span>
        </a>
         <?php if (hasRole('Admin','Risk Manager','Pimpinan')): ?>
          <a href="<?= APP_URL ?>/?page=kkpmr" class="nav-item <?= $currentPage==='kkpmr'?'active':'' ?>">
            <i class="fas fa-search-plus"></i><span>Kertas Kerja Pemantauan &amp; Reviu</span>
          </a>
         <?php endif; ?>
         <?php if (hasRole('Admin','Risk Manager')): ?>
        <a href="<?= APP_URL ?>/?page=ikk" class="nav-item <?= $currentPage==='ikk'?'active':'' ?>">
          <i class="fas fa-clipboard-check"></i><span>Kertas Kerja IKK</span>
        </a>
        <a href="<?= APP_URL ?>/?page=mitigasi" class="nav-item <?= $currentPage==='mitigasi'?'active':'' ?>">
          <i class="fas fa-tasks"></i><span>Aksi Mitigasi</span>
        </a>
         <?php endif; ?>
      </div>
    </div>
    
    <?php $isMonevGroupActive = in_array($currentPage, ['monev_triwulan', 'monev_tahunan', 'monev_konsolidasi']); ?>
    <div class="nav-group <?= $isMonevGroupActive ? 'is-active' : '' ?>">
      <button type="button" class="nav-group-title js-nav-group-toggle <?= $isMonevGroupActive ? 'expanded' : '' ?>" aria-expanded="<?= $isMonevGroupActive ? 'true' : 'false' ?>">
        <span class="nav-group-label">
          <i class="fas fa-calendar-check main-icon nav-icon-main"></i><span class="nav-text-main">Monitoring dan Evaluasi</span>
        </span>
        <i class="fas fa-chevron-down chevron"></i>
      </button>
      <div class="nav-group-items <?= $isMonevGroupActive ? 'show' : '' ?>">

        <?php if (hasRole('Admin','Risk Manager','Pimpinan','Staff')): ?>
        <a href="<?= APP_URL ?>/?page=monev_tahunan" class="nav-item <?= in_array($currentPage, ['monev_triwulan', 'monev_tahunan'])?'active':'' ?>">
          <i class="fas fa-calendar-check"></i><span>Monev Triwulan &amp; Tahunan</span>
        </a>
        <?php endif; ?>
        <?php if (hasRole('Admin','Risk Manager','Pimpinan','Koordinator')): ?>
        <a href="<?= APP_URL ?>/?page=monev_konsolidasi" class="nav-item <?= $currentPage==='monev_konsolidasi'?'active':'' ?>">
          <i class="fas fa-layer-group"></i><span>Laporan Konsolidasi Monev</span>
        </a>
        <?php endif; ?>
      </div>
    </div>
    
    <?php $isLaporanGroupActive = in_array($currentPage, ['laporan', 'laporan_konsolidasi', 'laporan_monev']); ?>
    <div class="nav-group <?= $isLaporanGroupActive ? 'is-active' : '' ?>">
      <button type="button" class="nav-group-title js-nav-group-toggle <?= $isLaporanGroupActive ? 'expanded' : '' ?>" aria-expanded="<?= $isLaporanGroupActive ? 'true' : 'false' ?>">
        <span class="nav-group-label">
          <i class="fas fa-file-alt main-icon nav-icon-main"></i><span class="nav-text-main">Laporan</span>
        </span>
        <i class="fas fa-chevron-down chevron"></i>
      </button>
      <div class="nav-group-items <?= $isLaporanGroupActive ? 'show' : '' ?>">
        <a href="<?= APP_URL ?>/?page=laporan" class="nav-item <?= $currentPage==='laporan'?'active':'' ?>">
          <i class="fas fa-file-alt"></i><span>Laporan Identifikasi Risiko</span>
        </a>
         <?php if (hasRole('Admin','Risk Manager','Pimpinan','Koordinator')): ?>
          <a href="<?= APP_URL ?>/?page=laporan_konsolidasi" class="nav-item <?= $currentPage==='laporan_konsolidasi'?'active':'' ?>">
            <i class="fas fa-layer-group"></i><span>Laporan Konsolidasi</span>
          </a>
          <a href="<?= APP_URL ?>/?page=laporan_monev" class="nav-item <?= $currentPage==='laporan_monev'?'active':'' ?>">
            <i class="fas fa-file-medical-alt"></i><span>Laporan Monev Manajemen Risiko</span>
          </a>
         <?php endif; ?>
      </div>
    </div>
    
    <?php if (hasRole('Admin')): ?>
    <?php $isMasterGroupActive = in_array($currentPage, ['master_indikator', 'kategori', 'saran_mitigasi']); ?>
    <div class="nav-group <?= $isMasterGroupActive ? 'is-active' : '' ?>">
      <button type="button" class="nav-group-title js-nav-group-toggle <?= $isMasterGroupActive ? 'expanded' : '' ?>" aria-expanded="<?= $isMasterGroupActive ? 'true' : 'false' ?>">
        <span class="nav-group-label">
          <i class="fas fa-database main-icon nav-icon-main"></i><span class="nav-text-main">Data Master</span>
        </span>
        <i class="fas fa-chevron-down chevron"></i>
      </button>
      <div class="nav-group-items <?= $isMasterGroupActive ? 'show' : '' ?>">
        <?php if (hasRole('Admin','Risk Manager')): ?>
        <a href="<?= APP_URL ?>/?page=master_indikator" class="nav-item <?= $currentPage==='master_indikator'?'active':'' ?>">
          <i class="fas fa-list-check"></i><span>Master Indikator &amp; Target</span>
        </a>
        <?php endif; ?>
        <?php if (hasRole('Admin','Risk Manager','Pimpinan')): ?>
        <a href="<?= APP_URL ?>/?page=kategori" class="nav-item <?= $currentPage==='kategori'?'active':'' ?>">
          <i class="fas fa-tags"></i><span>Master Kategori Risiko</span>
        </a>
        <a href="<?= APP_URL ?>/?page=saran_mitigasi" class="nav-item <?= $currentPage==='saran_mitigasi'?'active':'' ?>">
          <i class="fas fa-lightbulb"></i><span>Master Saran Mitigasi</span>
        </a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if (hasRole('Admin')): ?>
    <?php $isAdminGroupActive = in_array($currentPage, ['log', 'user', 'backup', 'bot_settings']); ?>
    <div class="nav-group <?= $isAdminGroupActive ? 'is-active' : '' ?>">
      <button type="button" class="nav-group-title js-nav-group-toggle <?= $isAdminGroupActive ? 'expanded' : '' ?>" aria-expanded="<?= $isAdminGroupActive ? 'true' : 'false' ?>">
        <span class="nav-group-label">
          <i class="fas fa-cog main-icon nav-icon-main"></i><span class="nav-text-main">Administrasi</span>
        </span>
        <i class="fas fa-chevron-down chevron"></i>
      </button>
      <div class="nav-group-items <?= $isAdminGroupActive ? 'show' : '' ?>">
        <a href="<?= APP_URL ?>/?page=bot_settings" class="nav-item <?= $currentPage==='bot_settings'?'active':'' ?>">
          <i class="fas fa-robot"></i><span>Integrasi Bot</span>
        </a>
        <a href="<?= APP_URL ?>/?page=log" class="nav-item <?= $currentPage==='log'?'active':'' ?>">
          <i class="fas fa-history"></i><span>Log Aktivitas</span>
        </a>
        <a href="<?= APP_URL ?>/?page=user" class="nav-item <?= $currentPage==='user'?'active':'' ?>">
          <i class="fas fa-users-cog"></i><span>Manajemen User</span>
        </a>
        <a href="<?= APP_URL ?>/?page=backup" class="nav-item <?= $currentPage==='backup'?'active':'' ?>">
          <i class="fas fa-database"></i><span>Backup &amp; Restore</span>
        </a>
      </div>
    </div>
    <?php endif; ?>

    <?php 
    $canBackupNav = false;
    if (function_exists('canManageBackup')) {
        $canBackupNav = canManageBackup();
    } elseif (function_exists('hasRole')) {
        $canBackupNav = hasRole('Admin', 'Risk Manager', 'Pimpinan', 'Kepala');
    }
    if (!hasRole('Admin') && $canBackupNav): ?>
    <a href="<?= APP_URL ?>/?page=backup" class="nav-item <?= $currentPage==='backup'?'active':'' ?>">
      <i class="fas fa-database nav-icon-main"></i><span class="nav-text-main">Backup &amp; Restore</span>
    </a>
    <?php endif; ?>
  </nav>
</aside>

<!-- Overlay mobile -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Main Wrapper -->
<div class="main-wrapper" id="mainWrapper">

  <!-- Navbar -->
  <header class="navbar">
    <div class="navbar-left">
    <?php if (($currentPage ?? '') === 'dashboard'): ?>
      <style>
        .navbar-left{flex:1;min-width:0}
        .navbar-search{position:relative;flex:0 1 560px;max-width:560px;min-width:180px;margin-right:16px}
        .navbar-search form{position:relative;margin:0}
        .navbar-search i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:.9rem;pointer-events:none}
        .navbar-search input{width:100%;height:40px;border-radius:99px;border:1px solid var(--border);background:var(--surface2);color:var(--text);padding:0 16px 0 40px;font-size:.85rem;outline:none;transition:border-color .2s,box-shadow .2s}
        .navbar-search input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-glow,rgba(59,130,246,.25))}
        .navbar-left .nav-clock #liveClock{text-align:left}
      </style>
      <div class="nav-clock d-none-mobile nav-clock-wrapper"><span id="liveClock">Memuat waktu...</span></div>
      <div class="navbar-search">
        <form id="heroSearchForm" method="GET" action="<?= APP_URL ?>/">
          <input type="hidden" name="page" value="dashboard">
          <?php foreach (['tahun', 'unit', 'level'] as $fk): if (!empty($_GET[$fk])): ?>
          <input type="hidden" name="<?= $fk ?>" value="<?= xss($_GET[$fk]) ?>">
          <?php endif; endforeach; ?>
          <i class="fas fa-search" id="heroSearchIcon"></i>
          <input type="text" name="q" id="heroSearchInput" placeholder="Cari nama, kode, atau PIC..." value="<?= xss($_GET['q'] ?? '') ?>" autocomplete="off">
        </form>
      </div>
    <?php else: ?>
      <button type="button" class="btn-icon" id="sidebarToggleBtn" title="Toggle Sidebar" aria-label="Buka atau tutup navigasi">
        <i class="fas fa-bars"></i>
      </button>
      <nav class="breadcrumb-nav">
        <span class="breadcrumb-home"><i class="fas fa-home"></i></span>
        <i class="fas fa-chevron-right breadcrumb-sep"></i>
        <span class="breadcrumb-current"><?= ucfirst(xss($currentPage)) ?></span>
      </nav>
    <?php endif; ?>
    </div>
    <div class="navbar-right">
      <?php if (($currentPage ?? '') !== 'dashboard'): ?>
      <!-- Live Clock -->
      <div class="nav-clock d-none-mobile nav-clock-wrapper">
        <span id="liveClock">Memuat waktu...</span>
      </div>
      <?php endif; ?>
      <!-- Theme Toggle -->
      <button type="button" class="btn-icon theme-toggle" id="themeToggle" title="Toggle Theme" aria-label="Ganti tema warna">
        <i class="fas <?= $theme==='dark'?'fa-sun':'fa-moon' ?>"></i>
      </button>
      <!-- Notifikasi Bell -->
      <div class="notif-dropdown" id="notifDropdown" class="notif-btn-wrapper">
        <button type="button" class="btn-icon notif-btn-wrapper" id="notifToggleBtn" title="Notifikasi" aria-label="Buka notifikasi">
          <i class="fas fa-bell"></i>
          <?php $unread = countUnreadNotif((int)($_SESSION['user_id'] ?? 0)); if ($unread > 0): ?>
          <span id="notifBadge" class="notif-badge"><?= $unread > 99 ? '99+' : $unread ?></span>
          <?php endif; ?>
        </button>
        <div id="notifMenu" class="notif-dropdown-menu">
          <div class="notif-header">
            <span class="notif-header-title"><i class="fas fa-bell"></i> Notifikasi</span>
            <button type="button" id="markAllReadBtn" class="notif-mark-read">Tandai dibaca</button>
          </div>
          <div id="notifList" class="notif-list-wrapper">
            <?php
            $notifs = getNotifikasi((int)($_SESSION['user_id'] ?? 0), 10);
            if (empty($notifs)) {
                echo '<div class="notif-list-empty"><i class="fas fa-inbox"></i>Belum ada notifikasi</div>';
            } else {
                foreach ($notifs as $n):
                    $iconMap = ['risiko_baru'=>'fa-exclamation-circle','mitigasi_deadline'=>'fa-clock','approval'=>'fa-check-circle','status_change'=>'fa-sync'];
                    $colorMap = ['risiko_baru'=>'var(--info)','mitigasi_deadline'=>'var(--warning)','approval'=>'var(--success)','status_change'=>'var(--accent)'];
                    $icon = $iconMap[$n['tipe']] ?? 'fa-bell';
                    $color = $colorMap[$n['tipe']] ?? 'var(--accent)';
                ?>
                <a href="<?= xss(safeInternalUrl($n['link'] ?? '')) ?>" class="notif-item <?= $n['is_read'] ? 'read' : 'unread' ?>" data-id="<?= $n['id'] ?>">
                  <div class="notif-icon-wrapper" style="background:<?= $color ?>20;">
                    <i class="fas <?= $icon ?>" style="color:<?= $color ?>;font-size:.8rem"></i>
                  </div>
                  <div class="notif-content-wrapper">
                    <div class="notif-title"><?= xss($n['judul']) ?></div>
                    <div class="notif-desc"><?= xss($n['pesan']) ?></div>
                    <div class="notif-time"><?= date('d/m H:i', strtotime($n['created_at'])) ?></div>
                  </div>
                </a>
                <?php endforeach;
            }
            ?>
          </div>
        </div>
      </div>
      <!-- User Dropdown -->
        <div class="user-dropdown" id="userDropdown">
          <button type="button" class="user-btn" id="userDropdownBtn">
            <div class="user-avatar"><?= strtoupper(substr($userName,0,1)) ?></div>
            <span class="user-name-nav"><?= xss($userName) ?></span>
            <i class="fas fa-chevron-down"></i>
          </button>
        <div class="dropdown-menu" id="dropdownMenu">
          <div class="dropdown-header">
            <strong><?= xss($userName) ?></strong>
            <small><?= xss($userRole) ?></small>
          </div>
          <div class="dropdown-divider"></div>
          <a href="<?= APP_URL ?>/?page=profile" class="dropdown-item"><i class="fas fa-user"></i> Profil Saya</a>
          <div class="dropdown-divider"></div>
          <form action="<?= APP_URL ?>/index.php" method="POST" style="margin:0;" class="logout-form" onsubmit="try{for(var i=sessionStorage.length-1;i>=0;i--){var k=sessionStorage.key(i);if(k&&k.indexOf('manris_ai_chat')!==-1)sessionStorage.removeItem(k);}for(var j=localStorage.length-1;j>=0;j--){var lk=localStorage.key(j);if(lk&&lk.indexOf('manris_ai_chat')!==-1)localStorage.removeItem(lk);}}catch(e){}">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="logout">
            <button type="submit" class="dropdown-item text-danger" style="width:100%; text-align:left; border:none; background:none; cursor:pointer;">
              <i class="fas fa-sign-out-alt"></i> Keluar
            </button>
          </form>
        </div>
      </div>
    </div>
  </header>

  <!-- Flash Message Container -->
  <div id="flashContainer">
    <?= showFlash() ?>
  </div>

  <!-- Page Content -->
  <main class="content">
