# Requirements Document

## Introduction

Dokumen ini mendefinisikan persyaratan untuk refaktor arsitektur aplikasi **Sistem Informasi Manajemen Risiko (SIMRS/manrisv2)** yang saat ini berbasis flat PHP modules tanpa autoloader, Composer, framework MVC, test suite, atau sistem migrasi formal. Refaktor mencakup enam area utama: (1) pemisahan arsitektur MVC berlapis, (2) validasi server-side terpusat, (3) penghapusan ketergantungan CDN ke aset lokal, (4) pengujian minimal dengan PHPUnit, (5) penghapusan file sementara dan penggantian dengan sistem migrasi formal, serta (6) streaming backup via `mysqldump`. Semua perubahan dilakukan secara inkremental tanpa merusak fungsionalitas aplikasi yang berjalan.

---

## Glossary

- **Application**: Sistem Informasi Manajemen Risiko (manrisv2) berbasis PHP yang berjalan di XAMPP/Apache.
- **Controller**: Kelas PHP di `app/Controllers/` yang menerima request, memanggil Service/Repository, dan memilih View untuk dirender.
- **Service**: Kelas PHP di `app/Services/` yang mengandung logika bisnis (kalkulasi, orkestrasi) tanpa ketergantungan langsung ke HTTP atau database.
- **Repository**: Kelas PHP di `app/Repositories/` yang bertanggung jawab atas seluruh akses database (query, prepare, bind) untuk satu entitas.
- **View**: File template PHP di `app/Views/` yang hanya mengandung presentasi HTML; tidak boleh mengandung logika bisnis atau query database.
- **Validator**: Kelas PHP di `app/Validators/` yang berisi metode validasi input terpusat dan mengembalikan array error.
- **Router**: Mekanisme routing sederhana di `index.php` yang memetakan parameter `?page=` ke Controller yang sesuai.
- **CDN Asset**: File JavaScript atau CSS yang saat ini dimuat dari layanan eksternal (cdn.jsdelivr.net, cdnjs.cloudflare.com).
- **Local Asset**: File JavaScript atau CSS yang disimpan di direktori `assets/vendors/` di dalam proyek.
- **PHPUnit**: Framework pengujian PHP resmi yang dipasang via Composer.
- **Migration File**: File SQL bernomor urut (mis. `001_initial.sql`) di `database/migrations/` yang mendefinisikan perubahan skema database secara reproduktibel.
- **Temporary File**: File PHP, Python, HTML, atau teks yang dibuat untuk keperluan debugging/utilitas sekali-jalan dan tidak lagi diperlukan dalam operasional aplikasi.
- **mysqldump**: Utilitas bawaan MySQL/MariaDB yang menghasilkan dump SQL lengkap sebuah database melalui baris perintah.
- **HMAC Signature**: Tanda tangan kriptografis berbasis `hash_hmac('sha256', $content, APP_KEY)` yang digunakan untuk memverifikasi integritas file backup sebelum restore.
- **Streaming Backup**: Teknik di mana output backup ditulis ke file sementara di server terlebih dahulu, kemudian dikirim ke browser menggunakan `readfile()` agar tidak membebani memori PHP.
- **Pilot Module**: Modul `modules/risiko.php` yang menjadi modul pertama yang dimigrasi ke arsitektur MVC sebelum modul lainnya.
- **APP_KEY**: Konstanta konfigurasi aplikasi yang digunakan sebagai kunci rahasia untuk operasi HMAC.
- **RiskModule**: Subsistem yang mengelola identifikasi risiko, mencakup `RisikoController`, `RisikoService`, dan `RisikoRepository`.
- **BackupModule**: Subsistem yang mengelola backup dan restore database, mencakup `BackupController` dan `BackupService`.

---

## Requirements

### Requirement 1 — Pemisahan Arsitektur MVC Berlapis (Pilot: Modul Risiko)

**User Story:** Sebagai pengembang, saya ingin memisahkan logika bisnis, akses data, dan presentasi ke dalam lapisan MVC yang terstruktur, dimulai dari modul risiko sebagai pilot, sehingga kode lebih mudah dipelihara, diuji secara unit, dan dikembangkan oleh tim.

#### Acceptance Criteria

1. THE Application SHALL menyediakan struktur direktori `app/Controllers/`, `app/Services/`, `app/Repositories/`, dan `app/Views/` di root workspace.

2. THE Application SHALL menyertakan file autoloader (`app/autoload.php` atau integrasi Composer autoload PSR-4) sehingga kelas di direktori `app/` dapat dimuat secara otomatis tanpa `require_once` manual per file.

3. THE Router SHALL memetakan setiap nilai parameter `?page=` yang valid ke kelas Controller yang sesuai di `app/Controllers/`, dengan `index.php` tetap menjadi satu-satunya entry point HTTP.

4. WHEN parameter `?page=` tidak dikenali oleh Router, THE Router SHALL memuat halaman 404 atau redirect ke halaman dashboard tanpa menampilkan pesan error PHP mentah kepada pengguna.

5. THE `RisikoController` SHALL menangani seluruh request HTTP (GET dan POST) untuk modul risiko, termasuk operasi tampil daftar, tambah, ubah, dan hapus, sebelum memanggil `RisikoService`.

6. THE `RisikoService` SHALL mengandung logika bisnis modul risiko, termasuk kalkulasi `getBobot()`, `getLevelRisiko()`, dan `generateKodeRisiko()`, tanpa akses langsung ke superglobal `$_POST`, `$_GET`, atau fungsi database.

7. THE `RisikoRepository` SHALL mengandung seluruh query database untuk entitas `risiko`, menggunakan prepared statements dengan parameter binding, dan tidak mengandung logika bisnis.

8. WHILE modul risiko sedang dimigrasi ke MVC, THE Application SHALL tetap melayani semua modul lain (`modules/*.php`) yang belum dimigrasi melalui mekanisme routing lama berbasis include, tanpa gangguan pada pengguna.

9. THE `app/Views/` SHALL menyimpan template HTML sebagai file partial (mis. `risiko/index.php`, `risiko/form.php`) yang menerima data dari Controller melalui variabel yang di-`extract()` atau di-pass sebagai parameter, dan tidak mengandung query database.

10. WHEN refaktor Pilot Module selesai, THE Application SHALL menghasilkan respons HTTP yang identik secara fungsional dengan modul `modules/risiko.php` sebelumnya, divalidasi melalui test suite PHPUnit.

---

### Requirement 2 — Validasi Server-Side Terpusat

**User Story:** Sebagai pengembang, saya ingin memusatkan semua logika validasi input ke dalam kelas `Validator` yang dapat digunakan ulang, sehingga aturan validasi konsisten di seluruh modul, mudah diuji unit, dan tidak tersebar secara ad-hoc di setiap file modul.

#### Acceptance Criteria

1. THE Application SHALL menyediakan kelas `Validator` di `app/Validators/Validator.php` dengan metode statis atau instance yang menerima array data input dan array aturan, lalu mengembalikan array asosiatif `['field' => 'pesan error']`.

2. WHEN metode validasi dipanggil dengan nilai tanggal yang tidak sesuai format `Y-m-d`, THE Validator SHALL mengembalikan entri error untuk field tersebut dengan pesan yang menyebutkan format yang diharapkan.

3. IF field tanggal yang dideklarasikan wajib bernilai `null`, kosong, atau tidak ada dalam input, THEN THE Validator SHALL mengembalikan entri error yang menyatakan field tersebut wajib diisi.

4. WHEN metode validasi dipanggil dengan nilai field `status` yang bukan salah satu dari `Teridentifikasi`, `Ditangani`, `Dimonitor`, atau `Ditutup`, THE Validator SHALL mengembalikan entri error untuk field `status`.

5. WHEN metode validasi dipanggil dengan nilai `probabilitas` atau `dampak_level` yang bukan integer dalam rentang 1 sampai 5 (inklusif), THE Validator SHALL mengembalikan entri error untuk field yang bersangkutan.

6. WHEN metode validasi dipanggil dengan nilai `bobot` yang bukan numerik atau bernilai kurang dari atau sama dengan nol, THE Validator SHALL mengembalikan entri error untuk field `bobot`.

7. THE `RisikoController` SHALL memanggil `Validator` sebelum memproses data POST untuk operasi tambah dan ubah risiko, dan IF `Validator` mengembalikan satu atau lebih entri error, THEN THE `RisikoController` SHALL menghentikan pemrosesan dan mengembalikan respons error tanpa memanggil `RisikoService` atau `RisikoRepository`.

8. THE Validator SHALL dapat digunakan oleh modul-modul lain secara bertahap tanpa modifikasi pada kelas `Validator` itu sendiri, cukup dengan memanggil metode yang tersedia dengan aturan yang berbeda.

9. THE Application SHALL menyertakan minimal satu test unit PHPUnit untuk setiap metode validasi di `Validator`, mencakup kasus valid dan kasus tidak valid.

---

### Requirement 3 — Pin Versi CDN ke Aset Lokal

**User Story:** Sebagai administrator sistem, saya ingin semua aset JavaScript dan CSS library dimuat dari direktori lokal, bukan dari CDN eksternal, sehingga aplikasi tetap berfungsi tanpa koneksi internet, versi library terkontrol, dan risiko supply-chain attack dari CDN berkurang.

#### Acceptance Criteria

1. THE Application SHALL menyimpan file distribusi library berikut di direktori `assets/vendors/` dengan sub-direktori per library:
   - `assets/vendors/chart.js/chart.umd.min.js` (Chart.js versi 4.4.3)
   - `assets/vendors/font-awesome/css/all.min.css` dan direktori `webfonts/` (Font Awesome versi 6.5.2)
   - `assets/vendors/datatables/` dengan file CSS dan JS (DataTables/simple-datatables versi 2.1.8)
   - `assets/vendors/sweetalert2/sweetalert2.min.js` dan `sweetalert2.min.css` (SweetAlert2 versi 11.12.4)

2. THE `includes/header.php` SHALL memuat semua library dari path lokal `assets/vendors/` menggunakan tag `<link>` dan `<script>` dengan path relatif dari `APP_URL`, dan tidak lagi mereferensikan URL `cdn.jsdelivr.net` atau `cdnjs.cloudflare.com` untuk library-library tersebut.

3. WHEN `includes/header.php` dirender, THE Application SHALL memuat versi library yang tepat sesuai daftar berikut: Chart.js 4.4.3, Font Awesome 6.5.2, SweetAlert2 11.12.4, DataTables (simple-datatables) 2.1.8.

4. THE `.htaccess` SHALL diperbarui untuk menyesuaikan `Content-Security-Policy` header sehingga tidak lagi mengizinkan `script-src` atau `style-src` dari `cdn.jsdelivr.net` dan `cdnjs.cloudflare.com` untuk library yang sudah dilokalisasi.

5. IF Google Fonts (`fonts.googleapis.com`) tetap digunakan untuk font teks (Inter, Outfit), THEN THE `.htaccess` SHALL tetap mengizinkan `style-src` dari `fonts.googleapis.com` dan `font-src` dari `fonts.gstatic.com` dalam CSP.

6. WHERE Font Awesome digunakan sebagai ikon di seluruh halaman aplikasi, THE Application SHALL menampilkan semua ikon dengan benar setelah migrasi ke aset lokal, tanpa memerlukan koneksi ke CDN eksternal.

---

### Requirement 4 — Setup Test Minimal dengan PHPUnit

**User Story:** Sebagai pengembang, saya ingin memiliki test suite PHPUnit yang mencakup skenario kritis keamanan dan CRUD, sehingga regresi terhadap fungsi-fungsi inti dapat dideteksi secara otomatis sebelum deployment.

#### Acceptance Criteria

1. THE Application SHALL menyertakan file `composer.json` di root workspace yang mendefinisikan `phpunit/phpunit` versi `^11.0` sebagai `require-dev` dependency, beserta konfigurasi autoload PSR-4 untuk namespace `App\` yang memetakan ke direktori `app/`.

2. THE Application SHALL menyertakan file `phpunit.xml` (atau `phpunit.xml.dist`) di root workspace yang mengkonfigurasi direktori `tests/` sebagai test suite, dengan bootstrap yang memuat autoloader Composer.

3. THE Application SHALL menyertakan test kasus untuk login di `tests/Auth/LoginTest.php`, yang mencakup:
   - Login berhasil dengan kredensial valid mengembalikan sesi pengguna yang valid.
   - Login gagal dengan password salah tidak membuat sesi pengguna.
   - Login dengan akun demo diblokir jika fitur demo dinonaktifkan.

4. THE Application SHALL menyertakan test kasus untuk CSRF di `tests/Security/CsrfTest.php`, yang mencakup:
   - Request POST dengan token CSRF valid melewati validasi `verifyCsrf()`.
   - Request POST tanpa token CSRF atau dengan token yang tidak cocok gagal validasi `verifyCsrf()`.

5. THE Application SHALL menyertakan test kasus untuk kontrol akses role dan kepemilikan di `tests/Security/AccessControlTest.php`, yang mencakup:
   - User dengan role `Risk Manager` tidak dapat menghapus risiko milik user lain.
   - User dengan role `Admin` dapat mengakses semua record tanpa batasan kepemilikan.

6. THE Application SHALL menyertakan test kasus untuk operasi CRUD risiko di `tests/Risiko/RisikoCrudTest.php`, yang mencakup:
   - Membuat risiko baru dengan data valid menyimpan record ke database dengan kode risiko yang di-generate.
   - Mengubah risiko yang ada memperbarui field yang berubah tanpa mengubah field lain.
   - Soft-delete risiko yang sudah digunakan dokumen menyetel `deleted_at` tanpa menghapus record secara permanen.

7. THE Application SHALL menyertakan test kasus untuk validasi upload file di `tests/Security/FileUploadTest.php`, yang mencakup:
   - File dengan ekstensi `.php` ditolak oleh fungsi `uploadFile()`.
   - File dengan MIME type `image/jpeg` dan ukuran di bawah `MAX_FILE_SIZE` diterima.

8. THE Application SHALL menyertakan test kasus untuk verifikasi password restore di `tests/Backup/RestoreTest.php`, yang mencakup:
   - Restore dibatalkan jika password konfirmasi tidak cocok dengan password Admin yang tersimpan.
   - Restore dibatalkan jika HMAC signature file backup tidak valid.

9. WHEN perintah `./vendor/bin/phpunit` dijalankan dari root workspace, THE Application SHALL mengeksekusi seluruh test suite dan melaporkan hasil pass/fail per test case tanpa error fatal PHP.

---

### Requirement 5 — Hapus File Sementara dan Migrasi Formal

**User Story:** Sebagai pengembang, saya ingin menghapus semua file utilitas sekali-jalan dan menggantikan skrip migrasi ad-hoc dengan sistem migrasi database bernomor urut, sehingga root direktori bersih, skema database dapat direproduksi, dan tidak ada skrip berbahaya yang terekspos secara tidak sengaja.

#### Acceptance Criteria

1. THE Application SHALL menghapus secara permanen file-file PHP sementara berikut dari root direktori:
   `scratch.php`, `scratch2.php`, `scratch3.php`, `temp.php`, `alter_users.php`, `check_users.php`, `check_users_schema.php`, `check_user_cols.php`, `dbcheck.php`, `get_schema.php`, `check.php`, `check_schema.php`, `check_schema2.php`, `check_schema3.php`, `clear_locks.php`, `update_dashboard.php`, `update_db.php`, `update_kkpmr_1.php`, `update_kkpmr_2.php`, `update_kkpmr_combined.php`, `update_kkpr.php`, `update_risiko.php`, `fix_risiko.php`, `migrate_risiko.php`, `schema.php`.

2. THE Application SHALL menghapus secara permanen file-file Python (`*.py`), file output HTML (`output.html`, `output2.html`), file teks (`target.txt`), dan file backup CSS (`*.bak`) dari root direktori dan subdirektori `assets/`.

3. THE Application SHALL menyediakan direktori `database/migrations/` yang berisi minimal satu file migrasi awal bernama `001_initial.sql` yang mendefinisikan skema database lengkap (CREATE TABLE untuk semua tabel yang ada) dengan pernyataan `CREATE TABLE IF NOT EXISTS`.

4. THE `database/migrations/` SHALL menggunakan konvensi penamaan file berformat `NNN_deskripsi_singkat.sql` di mana `NNN` adalah nomor urut tiga digit (001, 002, 003, dst.) dan `deskripsi_singkat` menggunakan huruf kecil dengan underscore.

5. THE Application SHALL menyertakan file `database/migrations/README.md` yang menjelaskan konvensi penamaan, cara menjalankan migrasi secara manual, dan panduan membuat file migrasi baru.

6. WHEN file-file sementara dihapus, THE `.htaccess` SHALL tetap mempertahankan aturan `FilesMatch` yang memblokir akses web ke nama-nama file yang sudah dihapus tersebut, sebagai lapisan pertahanan jika file serupa dibuat ulang secara tidak sengaja di masa mendatang.

7. IF skema database mengalami perubahan di masa mendatang (penambahan kolom, tabel, atau index), THEN perubahan tersebut SHALL didefinisikan dalam file migrasi baru bernomor urut berikutnya, bukan dengan memodifikasi file migrasi yang sudah ada.

---

### Requirement 6 — Streaming Backup via mysqldump

**User Story:** Sebagai Admin, saya ingin proses backup database menggunakan `mysqldump` yang dieksekusi via `exec()` dengan parameter aman, kemudian file disajikan ke browser melalui `readfile()`, sehingga backup database besar tidak menyebabkan PHP kehabisan memori, dan file backup tetap terverifikasi integritasnya dengan HMAC sebelum restore.

#### Acceptance Criteria

1. THE `BackupService` SHALL memeriksa ketersediaan `mysqldump` pada sistem dengan memanggil `exec('which mysqldump')` (Linux/macOS) atau pengecekan path ekuivalen (Windows) sebelum memutuskan metode backup yang akan digunakan.

2. WHEN `mysqldump` tersedia, THE `BackupService` SHALL menjalankan `mysqldump` menggunakan `exec()` dengan argumen yang dibangun melalui `escapeshellarg()` untuk setiap parameter (host, username, password, nama database), menghasilkan file SQL di path temporary yang ditentukan oleh `sys_get_temp_dir()`.

3. THE `BackupService` SHALL TIDAK menyisipkan nilai kredensial database langsung ke dalam string perintah shell tanpa `escapeshellarg()`, guna mencegah shell injection.

4. WHEN file SQL temporary berhasil dibuat oleh `mysqldump`, THE `BackupService` SHALL menghitung HMAC-SHA256 dari konten file tersebut menggunakan `APP_KEY`, kemudian menulis baris `-- HMAC-SHA256: <signature>` sebagai baris pertama dari file backup akhir sebelum konten SQL.

5. WHEN file backup akhir siap disajikan, THE `BackupController` SHALL mengirimkan header HTTP `Content-Type: application/sql`, `Content-Disposition: attachment`, dan `Content-Length` berdasarkan ukuran file, kemudian mengalirkan konten ke browser menggunakan `readfile()`.

6. WHEN `readfile()` selesai mengalirkan file, THE `BackupService` SHALL menghapus file temporary dari `sys_get_temp_dir()` menggunakan `unlink()` untuk mencegah akumulasi file sementara di server.

7. IF `mysqldump` tidak tersedia pada sistem, THEN THE `BackupService` SHALL menggunakan metode fallback berbasis PHP in-memory (logika `SHOW TABLES` + `SHOW CREATE TABLE` + `SELECT *` yang sudah ada) untuk menghasilkan konten SQL, dan HMAC-SHA256 tetap dihitung serta disertakan.

8. WHEN proses restore menerima file backup, THE `BackupService` SHALL memverifikasi HMAC-SHA256 dari konten file menggunakan `hash_equals()` sebelum mengeksekusi SQL, dan IF verifikasi gagal, THEN restore SHALL dihentikan dan pesan error dikembalikan tanpa mengubah database.

9. THE `BackupController` SHALL memverifikasi CSRF token dan mengautentikasi ulang password Admin sebelum memproses request restore, sebagaimana sudah diimplementasikan di `modules/backup_restore.php`.

10. WHEN ukuran file backup melebihi batas ukuran upload PHP (`upload_max_filesize`), THE Application SHALL menampilkan pesan error yang informatif kepada pengguna Admin tanpa menampilkan pesan error PHP mentah.
