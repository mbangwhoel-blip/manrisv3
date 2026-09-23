# Landing Page Premium - Setup Guide

## File yang Sudah Dibuat

### 1. Module Landing Page
- modules/landing.php - Halaman landing utama

### 2. Assets
- assets/css/landing.css - Styling glassmorphism premium
- assets/js/landing.js - JavaScript interaksi

### 3. Database Migrations
- lib/migrations/20250115_001_create_landing_testimonials.sql
- lib/migrations/20250115_002_create_landing_statistics_cache.sql
- lib/migrations/20250115_003_create_contact_submissions.sql

### 4. Router Modified
- index.php - Sudah dimodifikasi untuk support landing page

## Cara Testing di XAMPP

### Step 1: Import Database
1. Buka phpMyAdmin: http://localhost/phpmyadmin
2. Pilih database manris_db
3. Klik tab "SQL"
4. Copy paste isi dari file migrations:
   - lib/migrations/20250115_001_create_landing_testimonials.sql
   - lib/migrations/20250115_002_create_landing_statistics_cache.sql
   - lib/migrations/20250115_003_create_contact_submissions.sql (optional)
5. Klik "Go" untuk execute

### Step 2: Akses Landing Page
1. Buka browser
2. Akses: http://localhost/manrisv2
3. Landing page akan tampil untuk user yang belum login

### Step 3: Test Fitur
- Hero section dengan glassmorphism design
- Features section (6 fitur)
- Statistics section (data real-time dari database)
- Benefits section
- Testimonials carousel
- Footer dengan contact info

### Step 4: Test Login
- Klik tombol "Masuk" untuk ke halaman login
- Setelah login, akan redirect ke dashboard
- User yang sudah login tidak akan melihat landing page

## Troubleshooting

### Jika ada error "Table doesn't exist"
- Pastikan sudah import SQL migrations di phpMyAdmin

### Jika CSS/JS tidak load
- Clear browser cache (Ctrl+F5)
- Periksa APP_URL di .env sudah benar

### Jika statistics tidak muncul
- Pastikan ada data di tabel risiko, mitigasi, users
- Cek error log di XAMPP/apache/logs/error.log

## Keamanan yang Diterapkan

1. CSRF Protection - Form contact dilindungi
2. XSS Prevention - Semua output di-escape dengan xss()
3. Rate Limiting - 3 request per 5 menit untuk contact form
4. Session Security - Redirect authenticated users

## Customization

### Ganti Testimonial
Edit tabel landing_testimonials di database:
- user_name: Nama pengguna
- user_role: Jabatan
- unit_kerja: Unit kerja
- testimonial_text: Isi testimoni
- is_active: 1 = aktif, 0 = nonaktif
- display_order: Urutan tampil

### Ganti Contact Info
Edit file modules/landing.php bagian footer

### Ganti Warna/Style
Edit file assets/css/landing.css bagian CSS Custom Properties
