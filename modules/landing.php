<?php
/**
 * LANDING PAGE - SEMAR
 * Public-facing entry point for unauthenticated users
 */

// Prevent direct access without config
if (!defined('APP_URL')) {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/functions.php';
}
// Pastikan fungsi landing tersedia (hindari OPcache stale)
if (!function_exists('getLandingStatisticsSafe')) {
    require_once __DIR__ . '/../includes/functions.php';
}

// Override CSP via PHP agar Google Maps iframe tidak diblokir di hosting
if (!headers_sent()) {
    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
        . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; "
        . "img-src 'self' data: blob: https://maps.gstatic.com https://*.googleapis.com https://api.qrserver.com; "
        . "font-src 'self' data: https://cdnjs.cloudflare.com https://fonts.gstatic.com; "
        . "connect-src 'self' https://fonts.googleapis.com https://fonts.gstatic.com https://maps.googleapis.com; "
        . "frame-src https://www.google.com https://maps.google.com; "
        . "frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'");
}


// Override CSP via PHP agar Google Maps iframe tidak diblokir di hosting
// (beberapa hosting override .htaccess CSP dengan policy mereka sendiri)
if (!headers_sent()) {
    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
        . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; "
        . "img-src 'self' data: blob: https://maps.gstatic.com https://*.googleapis.com https://api.qrserver.com; "
        . "font-src 'self' data: https://cdnjs.cloudflare.com https://fonts.gstatic.com; "
        . "connect-src 'self' https://fonts.googleapis.com https://fonts.gstatic.com https://maps.googleapis.com; "
        . "frame-src https://www.google.com https://maps.google.com https://www.google.com/maps/; "
        . "frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'");
}

// Ambil flash message dari session (mis. setelah logout)
$landingFlash = null;
if (isset($_SESSION['flash'])) {
    $landingFlash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// Get statistics safely
$stats = getLandingStatisticsSafe();
// Features data
$features = [
    [
        'icon' => 'fa-chart-pie',
        'title' => 'Dashboard Interaktif',
        'description' => 'Visualisasi data risiko secara real-time dengan statistik dan grafik interaktif untuk pengambilan keputusan cepat.'
    ],
    [
        'icon' => 'fa-shield-alt',
        'title' => 'Manajemen Risiko',
        'description' => 'Identifikasi dan kalkulasi skor risiko secara otomatis dengan matriks standar 5x5 yang terintegrasi.'
    ],
    [
        'icon' => 'fa-tasks',
        'title' => 'Aksi Mitigasi',
        'description' => 'Pantau pelaksanaan mitigasi dengan upload bukti dan tanda tangan digital untuk akuntabilitas penuh.'
    ],
    [
        'icon' => 'fa-robot',
        'title' => 'Saran Mitigasi AI',
        'description' => 'Rekomendasi mitigasi otomatis berbasis kecerdasan buatan sesuai jenis dan level risiko.'
    ],
    [
        'icon' => 'fa-file-export',
        'title' => 'Export Laporan',
        'description' => 'Buat laporan profesional dalam format Excel dan PDF dengan berbagai template yang tersedia.'
    ],
    [
        'icon' => 'fa-users-cog',
        'title' => 'Manajemen Pengguna',
        'description' => 'Kontrol akses berbasis role dengan permission granular untuk keamanan data optimal.'
    ]
];

// Benefits data
$benefits = [
    [
        'icon' => 'fa-clipboard-list',
        'title' => 'Proses Terstruktur',
        'description' => 'Identifikasi risiko dengan metode standar yang terdokumentasi rapi dan dapat diaudit kapan saja.'
    ],
    [
        'icon' => 'fa-eye',
        'title' => 'Monitoring Real-time',
        'description' => 'Pantau status penanganan risiko secara langsung dengan notifikasi otomatis untuk update terkini.'
    ],
    [
        'icon' => 'fa-file-alt',
        'title' => 'Laporan Otomatis',
        'description' => 'Generate laporan audit dan pelaporan kepatuhan dengan satu klik.'
    ],
    [
        'icon' => 'fa-lock',
        'title' => 'Keamanan Data',
        'description' => 'Enkripsi end-to-end dan kontrol akses ketat memastikan data risiko organisasi tetap aman dan rahasia.'
    ]
];

// Handle contact form submission
$flashMessage = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_submit'])) {
    // CSRF validation
    if (!verifyCsrf()) {
        $flashMessage = ['type' => 'error', 'message' => 'Sesi tidak valid. Silakan refresh halaman.'];
    }
    // Rate limiting
    elseif (!checkRateLimit('contact_form', 3, 300)) { // 3 requests per 5 minutes
        logRateLimitViolation('contact_form', 'Landing page contact form');
        $flashMessage = ['type' => 'error', 'message' => 'Terlalu banyak permintaan. Silakan coba lagi dalam 5 menit.'];
    }
    else {
        // Validate input
        $validation = validateContactForm($_POST);
        if (!$validation['valid']) {
            $flashMessage = ['type' => 'error', 'message' => implode(' ', $validation['errors'])];
        } else {
            // Save submission
            if (saveContactSubmission($_POST)) {
                $flashMessage = ['type' => 'success', 'message' => 'Pesan Anda telah terkirim. Tim kami akan segera menghubungi Anda.'];
            } else {
                $flashMessage = ['type' => 'error', 'message' => 'Terjadi kesalahan. Silakan coba lagi.'];
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SEMAR - Sistem Informasi Manajemen Risiko | BBLKL Kemenkes</title>
    
    <!-- SEO Meta Tags -->
    <meta name="description" content="SEMAR adalah sistem informasi manajemen risiko komprehensif untuk identifikasi, analisis, dan mitigasi risiko di Balai Besar Laboratorium Kesehatan Lingkungan.">
    <meta name="keywords" content="manajemen risiko, identifikasi risiko, mitigasi, kemenkes, laboratorium kesehatan lingkungan, SPIP">
    <meta name="author" content="Balai Besar Laboratorium Kesehatan Lingkungan">
    
    <!-- Open Graph -->
    <meta property="og:title" content="SEMAR - Sistem Informasi Manajemen Risiko">
    <meta property="og:description" content="Sistem komprehensif untuk identifikasi, analisis, dan mitigasi risiko di BBLKL Kemenkes">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= xss(APP_URL) ?>/">
    <meta property="og:image" content="<?= xss(APP_URL) ?>/assets/img/og-image.png">
    
    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="SEMAR - Sistem Informasi Manajemen Risiko">
    <meta name="twitter:description" content="Sistem komprehensif untuk identifikasi, analisis, dan mitigasi risiko">
    
    <!-- Favicon -->
    <?php $faviconVersion = @filemtime(__DIR__ . '/../favicon-mark.png') ?: 1; ?>
    <link rel="icon" type="image/png" sizes="64x64" href="favicon-mark.png?v=<?= $faviconVersion ?>">
    <link rel="apple-touch-icon" sizes="64x64" href="favicon-mark.png?v=<?= $faviconVersion ?>">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
    <!-- Landing Page CSS -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/landing.css?v=<?= filemtime(__DIR__ . '/../assets/css/landing.css') ?>">
    
    <!-- JSON-LD Structured Data -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "WebApplication",
        "name": "SEMAR",
        "description": "Sistem Informasi Manajemen Risiko - Balai Besar Laboratorium Kesehatan Lingkungan",
        "applicationCategory": "BusinessApplication",
        "operatingSystem": "Web Browser",
        "provider": {
            "@type": "Organization",
            "name": "Balai Besar Laboratorium Kesehatan Lingkungan",
            "url": "https://labkesling.kemkes.go.id"
        }
    }
    </script>
</head>
<body>
    <!-- Top Bar: Jam & Tanggal -->
    <div class="landing-topbar" id="landingTopbar">
        <div class="topbar-inner">
            <span class="topbar-datetime" id="topbarDatetime">
                <i class="fas fa-clock"></i>
                <span id="topbarTime">--:--:--</span>
                &nbsp;|&nbsp;
                <i class="fas fa-calendar-alt"></i>
                <span id="topbarDate">-- --- ----</span>
            </span>
        </div>
    </div>

    <!-- Navigation Bar -->
    <nav class="landing-nav" id="landingNav">
        <div class="landing-nav-inner">
            <a href="<?= APP_URL ?>/" class="landing-nav-brand">
                <img src="<?= APP_URL ?>/assets/img/logo.png" alt="SEMAR Logo" class="landing-nav-logo">
                <span class="landing-nav-title">SEMAR</span>
            </a>
            <div class="landing-nav-links">
                <a href="#features" class="landing-nav-link">Fitur</a>
                <a href="#statistics" class="landing-nav-link">Statistik</a>
                <a href="#benefits" class="landing-nav-link">Keunggulan</a>
                <a href="#contact" class="landing-nav-link">Kontak</a>
            </div>
            <div class="landing-nav-actions">
                <a href="<?= APP_URL ?>/?page=login" class="btn btn-landing-login">
                    <i class="fas fa-sign-in-alt"></i> Masuk
                </a>
            </div>
            <button class="landing-nav-toggle" id="navToggle" aria-label="Toggle navigation">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </nav>

    <!-- Mobile Navigation Menu -->
    <div class="landing-mobile-menu" id="mobileMenu">
        <a href="#features" class="mobile-menu-link">Fitur</a>
        <a href="#statistics" class="mobile-menu-link">Statistik</a>
        <a href="#benefits" class="mobile-menu-link">Keunggulan</a>
        <a href="#contact" class="mobile-menu-link">Kontak</a>
        <a href="<?= APP_URL ?>/?page=login" class="btn btn-landing-login">
            <i class="fas fa-sign-in-alt"></i> Masuk
        </a>
    </div>

    <!-- Hero Section -->
    <section class="landing-hero" id="hero">
        <div class="orb orb1"></div>
        <div class="orb orb2"></div>
        <div class="orb orb3"></div>
        
        <div class="landing-hero-content">
            <div class="landing-hero-badge">
                <span class="live-dot"></span>
                Sistem Informasi Manajemen Risiko
            </div>
            
            <h1 class="landing-hero-title">
                SEMAR
                <span class="landing-hero-acronym">Balai Besar Laboratorium Kesehatan Lingkungan</span>
            </h1>
            
            <p class="landing-hero-subtitle">
                Solusi komprehensif untuk identifikasi, analisis, dan mitigasi risiko 
                di <strong>Balai Besar Laboratorium Kesehatan Lingkungan</strong>
            </p>
            
            <div class="landing-hero-actions">
                <a href="<?= APP_URL ?>/?page=login" class="btn btn-hero-primary" data-track="cta-hero-masuk">
                    <i class="fas fa-sign-in-alt"></i> Masuk ke Sistem
                </a>
                <a href="#features" class="btn btn-hero-ghost" data-track="cta-hero-learn">
                    <i class="fas fa-arrow-down"></i> Pelajari Lebih Lanjut
                </a>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="landing-section landing-features" id="features">
        <div class="landing-container">
            <div class="section-header">
                <span class="section-badge">Fitur</span>
                <h2 class="section-title">Fitur Utama</h2>
                <p class="section-subtitle">Solusi lengkap untuk manajemen risiko organisasi Anda</p>
            </div>
            
            <div class="features-grid">
                <?php foreach ($features as $index => $feature): ?>
                <div class="feature-card" data-aos="fade-up" data-aos-delay="<?= $index * 100 ?>">
                    <div class="feature-icon">
                        <i class="fas <?= $feature['icon'] ?>"></i>
                    </div>
                    <h3 class="feature-title"><?= xss($feature['title']) ?></h3>
                    <p class="feature-description"><?= xss($feature['description']) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Statistics Section -->
    <section class="landing-section landing-statistics" id="statistics">
        <div class="landing-container">
            <div class="section-header">
                <span class="section-badge">Statistik</span>
                <h2 class="section-title">Statistik Sistem</h2>
                <p class="section-subtitle">Data real-time penggunaan sistem SEMAR</p>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-value" data-target="<?= $stats['total_risiko'] ?>">0</div>
                    <div class="stat-label">Risiko Teridentifikasi</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-value" data-target="<?= $stats['total_mitigasi'] ?>">0</div>
                    <div class="stat-label">Aksi Mitigasi</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-value" data-target="<?= $stats['total_pengguna'] ?>">0</div>
                    <div class="stat-label">Pengguna Aktif</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="stat-value" data-target="<?= $stats['total_unit_kerja'] ?>">0</div>
                    <div class="stat-label">Pengelola Risiko</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Benefits Section -->
    <section class="landing-section landing-benefits" id="benefits">
        <div class="landing-container">
            <div class="section-header">
                <span class="section-badge">Keunggulan</span>
                <h2 class="section-title">Mengapa Memilih SEMAR?</h2>
                <p class="section-subtitle">Keunggulan yang membedakan kami dari sistem lain</p>
            </div>
            
            <div class="benefits-list">
                <?php foreach ($benefits as $index => $benefit): ?>
                <div class="benefit-item">
                    <div class="benefit-icon">
                        <i class="fas <?= $benefit['icon'] ?>"></i>
                    </div>
                    <div class="benefit-content">
                        <h3 class="benefit-title"><?= xss($benefit['title']) ?></h3>
                        <p class="benefit-description"><?= xss($benefit['description']) ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="landing-section landing-cta" id="cta">
        <div class="landing-container">
            <div class="cta-content">
                <h2 class="cta-title">Siap Mengelola Risiko dengan Lebih Baik?</h2>
                <p class="cta-subtitle">Bergabung dengan ratusan pengguna yang telah mempercayai SEMAR untuk manajemen risiko organisasi mereka.</p>
                <a href="<?= APP_URL ?>/?page=login" class="btn btn-cta-primary" data-track="cta-footer-masuk">
                    <i class="fas fa-rocket"></i> Mulai Sekarang
                </a>
            </div>
        </div>
    </section>


    <!-- Contact & Map Section -->
    <section class="landing-section landing-contact" id="contact">
        <div class="landing-container">
            <div class="section-header">
                <span class="section-badge">Lokasi</span>
                <h2 class="section-title">Temukan Kami</h2>
                <p class="section-subtitle">Kunjungi atau hubungi kami untuk informasi lebih lanjut</p>
            </div>

            <div class="contact-grid">
                <!-- Info Kontak -->
                <div class="contact-info">
                    <div class="contact-card">
                        <div class="contact-item">
                            <div class="contact-icon"><i class="fas fa-map-marker-alt"></i></div>
                            <div class="contact-detail">
                                <h4>Alamat</h4>
                                <p>Jl. Hasanudin No. 123<br>Salatiga, Jawa Tengah 50721</p>
                            </div>
                        </div>
                        <div class="contact-item">
                            <div class="contact-icon"><i class="fas fa-phone-alt"></i></div>
                            <div class="contact-detail">
                                <h4>Telepon</h4>
                                <p>(0298) 327096</p>
                            </div>
                        </div>
                        <div class="contact-item">
                            <div class="contact-icon"><i class="fas fa-envelope"></i></div>
                            <div class="contact-detail">
                                <h4>Email</h4>
                                <p>bblkl.salatiga@kemkes.go.id</p>
                            </div>
                        </div>
                        <div class="contact-item">
                            <div class="contact-icon"><i class="fas fa-clock"></i></div>
                            <div class="contact-detail">
                                <h4>Jam Operasional</h4>
                                <p>Senin – Jumat: 08.00 – 16.00 WIB<br>Sabtu & Minggu: Tutup</p>
                            </div>
                        </div>
                        <a href="https://maps.google.com/?q=Jl.+Hasanudin+No.+123+Salatiga+Jawa+Tengah" target="_blank" rel="noopener noreferrer" class="btn-directions">
                            <i class="fas fa-directions"></i> Petunjuk Arah
                        </a>
                    </div>
                </div>

                <!-- Google Maps Embed -->
                <div class="contact-map">
                    <div class="map-wrapper">
                        <iframe
                            src="https://maps.google.com/maps?q=Balai+Besar+Laboratorium+Kesehatan+Lingkungan+Salatiga&output=embed&hl=id"
                            width="100%"
                            height="100%"
                            style="border:0;"
                            allowfullscreen=""
                            loading="lazy"
                            referrerpolicy="no-referrer-when-downgrade"
                            title="Lokasi Balai Besar Laboratorium Kesehatan Lingkungan">
                        </iframe>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- Footer -->
    <footer class="landing-footer">
        <div class="landing-container">
            <div class="footer-grid">
                <div class="footer-brand">
                    <img src="<?= APP_URL ?>/assets/img/logo.png" alt="SEMAR Logo" class="footer-logo">
                    <h3 class="footer-title">SEMAR</h3>
                    <p class="footer-tagline">Sistem Informasi Manajemen Risiko</p>
                    <p class="footer-institution">Balai Besar Laboratorium Kesehatan Lingkungan</p>
                </div>
                
                <div class="footer-links">
                    <h4 class="footer-heading">Tautan Cepat</h4>
                    <ul>
                        <li><a href="#features">Fitur</a></li>
                        <li><a href="#statistics">Statistik</a></li>
                        <li><a href="#benefits">Keunggulan</a></li>
                    </ul>
                </div>
                
                <div class="footer-contact">
                    <h4 class="footer-heading">Kontak</h4>
                    <ul>
                        <li><i class="fas fa-map-marker-alt"></i> Jl. Hasanudin No. 123, Salatiga, Jawa Tengah</li>
                        <li><i class="fas fa-phone"></i> (0298) 327096</li>
                        <li><i class="fas fa-envelope"></i> bblkl.salatiga@kemkes.go.id</li>
                    </ul>
                </div>
            </div>
            
            <div class="footer-bottom">
                <p>&copy; <?= date('Y') ?> Balai Besar Laboratorium Kesehatan Lingkungan. Hak Cipta Dilindungi.</p>
                <p class="footer-version">SEMAR v2.0</p>
            </div>
        </div>
    </footer>

    <!-- Flash Message Toast (logout / system messages) -->
    <?php if ($landingFlash): ?>
    <div class="toast toast-<?= $landingFlash['type'] === 'success' ? 'success' : 'error' ?>" id="landingFlashToast">
        <i class="fas <?= $landingFlash['type'] === 'success' ? 'fa-check-circle' : 'fa-info-circle' ?>"></i>
        <span><?= xss($landingFlash['msg']) ?></span>
    </div>
    <?php endif; ?>

    <!-- Flash Message Toast -->
    <?php if ($flashMessage): ?>
    <div class="toast toast-<?= $flashMessage['type'] ?>" id="flashToast">
        <i class="fas <?= $flashMessage['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
        <span><?= xss($flashMessage['message']) ?></span>
    </div>
    <?php endif; ?>

    <!-- JavaScript -->
    <script src="<?= APP_URL ?>/assets/js/landing.js" defer></script>
    <?php if (!isLoggedIn()): ?>
    <script>
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
    </script>
    <?php endif; ?>
</body>
</html>
