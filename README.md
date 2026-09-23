# Sistem Manajemen Risiko Premium

Aplikasi web berbasis PHP Murni + MySQL untuk manajemen risiko organisasi dengan desain glassmorphism premium.

## Cara Instalasi

### 1. Persiapan
- XAMPP (Apache + MySQL + PHP 8.1+)
- Letakkan folder `manris` di `htdocs/manris`

### 2. Import Database
```
1. Buka phpMyAdmin
2. Klik "New" → buat database `manris_db`
3. Import file `database.sql`
```

### 3. Konfigurasi
Buat file `.env` dari nilai environment server. Jangan menyimpan kredensial di source code.
```ini
APP_ENV=development
APP_URL=http://localhost/manrisv2
DB_HOST=localhost
DB_USER=root
DB_PASS=
DB_NAME=manris_db
APP_KEY=<kunci-acak-minimal-32-karakter>
```

### 4. Akses Aplikasi
Buka browser: `http://localhost/manrisv2`

## Akun awal

Jika menggunakan data contoh, **ganti seluruh password contoh sebelum deployment**. Aplikasi menolak password demo `password` ketika `APP_ENV` bukan `development`.

Untuk produksi, gunakan HTTPS, akun database terbatas, dan letakkan backup, dump database, log, serta skrip migrasi di luar document root.

## Fitur Utama
- Dashboard dengan statistik, grafik donat, bar chart, heatmap 5×5
- CRUD Manajemen Risiko dengan kalkulasi skor otomatis
- Aksi Mitigasi + upload bukti + tanda tangan digital canvas
- Saran mitigasi otomatis berdasarkan AI keyword matching
- Laporan dengan filter + export Excel & PDF
- Log aktivitas lengkap
- Manajemen user (Admin only)
- Dark mode elegan + Light mode corporate blue
- Keamanan: CSRF token, XSS protection, prepared statements
