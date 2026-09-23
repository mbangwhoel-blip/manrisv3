# Requirements Document

## Introduction

Perbaikan keamanan untuk aplikasi SEMAR (Sistem Informasi Manajemen Risiko) yang berjalan di atas PHP/MySQL (XAMPP). Lima temuan keamanan telah diidentifikasi melalui audit:

1. **File sensitif (maintenance/utility scripts) yang dapat diakses publik melalui HTTP** — `.htaccess` belum memblokir semua file tersebut.
2. **Akun demo aktif di lingkungan production** — tidak ada enforcement yang melarang login dengan password demo di luar mode development.
3. **IDOR (Insecure Direct Object Reference) di modul kkpmr.php, kkpr.php-subset, mitigasi.php (hapus), dan ikk.php** — operasi-operasi tertentu belum menggunakan `ownsRecord()` atau `canAccessAllRecords()` secara konsisten.
4. **Modul backup_restore.php** — perlu diverifikasi bahwa `requireRole('Admin')` dan konfirmasi password untuk restore sudah terapkan dengan benar.
5. **Bug TTD (tanda tangan base64)** — `saveTtdBase64()` mengembalikan array `['valid', 'path', ...]`; nilai TTD lama (bukan base64 baru) yang ikut terkirim di form harus dilewati pengecekan GD tanpa menyimpan ulang.

Scope perbaikan: `.htaccess`, `modules/mitigasi.php`, `modules/kkpmr.php`, `modules/login.php`, dan verifikasi `modules/profil_risiko.php` serta `modules/kkpr.php`.

---

## Glossary

- **Application**: Aplikasi SEMAR — sistem PHP/MySQL manajemen risiko di `c:\xampp\htdocs\manrisv2`.
- **htaccess**: File konfigurasi Apache `.htaccess` di root aplikasi.
- **IDOR**: Insecure Direct Object Reference — akses langsung ke sumber daya orang lain tanpa otorisasi.
- **ownsRecord()**: Fungsi PHP di `includes/functions.php` yang memverifikasi bahwa pengguna sesi saat ini adalah pemilik baris berdasarkan kolom `created_by` atau `id_user_input`; mengembalikan `true` jika peran adalah Admin atau Pimpinan.
- **canAccessAllRecords()**: Fungsi PHP yang mengembalikan `true` untuk peran Admin dan Pimpinan.
- **saveTtdBase64()**: Fungsi PHP yang memvalidasi dan menyimpan data base64 tanda tangan menjadi file PNG; mengembalikan array `['valid' => bool, 'path' => string, 'error' => string]`.
- **Demo Account**: Akun uji coba bawaan dengan password yang diketahui publik (mis. `demo123`), aktif di database.
- **APP_ENV**: Konstanta PHP yang membaca variabel lingkungan `APP_ENV`; bernilai `'development'` atau `'production'`.
- **Maintenance Script**: File PHP utilitas (mis. `check_users.php`, `dbcheck.php`, `scratch.php`, dll.) yang hanya boleh dijalankan melalui PHP CLI, bukan HTTP.
- **Risk Manager**: Peran pengguna yang hanya boleh mengelola data yang dibuatnya sendiri.
- **Admin/Pimpinan**: Peran pengguna yang boleh mengakses semua data lintas unit.
- **TTD**: Tanda tangan digital dalam format gambar base64 (PNG/JPEG).

---

## Requirements

### Requirement 1 — Blokir Akses HTTP ke Maintenance Scripts via .htaccess

**User Story:** Sebagai Administrator keamanan, saya ingin file-file maintenance dan utility PHP hanya dapat dieksekusi melalui PHP CLI, sehingga penyerang tidak dapat membaca informasi sistem atau mengeksekusi skrip berbahaya melalui browser.

#### Acceptance Criteria

1. THE Application SHALL deny HTTP access to the following files via `.htaccess` `<FilesMatch>` directive: `check_users.php`, `dbcheck.php`, `scratch.php`, `scratch2.php`, `scratch3.php`, `temp.php`.

2. THE Application SHALL deny HTTP access to the Python utility script `add_pimpinan.py` via `.htaccess` — the existing `\.(py)` pattern in the `FilesMatch` extension block SHALL cover this file.

3. WHEN a browser sends an HTTP GET or POST request to any blocked maintenance file, THE Application SHALL return HTTP status code `403 Forbidden` without rendering file contents.

4. THE Application SHALL preserve existing `.htaccess` directives (security headers, gzip, browser cache, existing FilesMatch blocks) unchanged while adding the new blocked filenames.

5. WHILE the server runs PHP CLI, THE Application SHALL allow maintenance scripts to execute normally (`.htaccess` has no effect on CLI execution).

---

### Requirement 2 — Blokir Login Akun Demo di Production

**User Story:** Sebagai Administrator keamanan, saya ingin akun demo dinonaktifkan secara otomatis di lingkungan production, sehingga penyerang tidak dapat menggunakan kredensial demo yang diketahui publik untuk masuk ke sistem.

#### Acceptance Criteria

1. WHEN a login attempt is made via `index.php` POST `action=login` AND `APP_ENV` is not `'development'` AND the submitted plain-text password matches a known demo password (defined as a hardcoded list in the login handler), THEN THE Application SHALL reject the login attempt.

2. WHEN the login is rejected due to a demo password in production, THE Application SHALL set a flash message with type `'error'` and redirect to the login page, without revealing that it is specifically a "demo account" block (use a generic invalid-credentials message).

3. WHERE `APP_ENV` equals `'development'`, THE Application SHALL allow login with demo passwords as normal — no additional enforcement is applied.

4. THE Application SHALL define the known demo password list as a PHP array inside the login handler; the list SHALL include at minimum the value `'demo123'`.

5. THE Application SHALL perform the demo password check AFTER the standard username/password database lookup and `password_verify()` check, so that the behavior for invalid usernames remains unchanged.

---

### Requirement 3 — IDOR Protection: Konsistensi ownsRecord() di Modul mitigasi.php (hapus)

**User Story:** Sebagai Risk Manager, saya ingin agar hanya Admin/Pimpinan dan pemilik risiko terkait yang dapat menghapus entri mitigasi, sehingga pengguna lain tidak dapat menghapus mitigasi orang lain melalui manipulasi parameter POST.

#### Acceptance Criteria

1. WHEN aksi `hapus` pada `modules/mitigasi.php` diproses, THE Application SHALL verify ownership using the existing pattern: query `mitigasi JOIN risiko` untuk mendapatkan `r.id_user_input`, lalu periksa `canAccessAllRecords()` ATAU `(int)$row['id_user_input'] === (int)$_SESSION['user_id']`.

2. IF the ownership check fails for aksi `hapus` in `modules/mitigasi.php`, THEN THE Application SHALL set flash error `'Anda tidak memiliki hak untuk menghapus mitigasi ini.'` and redirect to `/?page=mitigasi`.

3. THE Application SHALL log the failed IDOR attempt to PHP error log using `error_log()` including the user ID and mitigasi ID attempted.

4. THE Application SHALL apply the ownership check AFTER the initial `requireRole('Admin','Risk Manager')` guard, using the same SQL join pattern that already exists in the file for the `hapus` aksi block.

---

### Requirement 4 — IDOR Protection: ownsRecord() untuk Semua Operasi di kkpmr.php

**User Story:** Sebagai Risk Manager, saya ingin agar hanya pemilik KKPR dan Admin/Pimpinan yang dapat memperbarui data pemantauan dan header pemantauan di modul KKPMR, sehingga data risiko unit lain tidak dapat dimanipulasi.

#### Acceptance Criteria

1. WHEN aksi `simpan_pemantauan` diproses di `modules/kkpmr.php`, THE Application SHALL call `ownsKkpr($db, $idKkpr)` before executing the UPDATE query.

2. IF `ownsKkpr()` returns `false` for aksi `simpan_pemantauan`, THEN THE Application SHALL set flash error `'Anda tidak memiliki hak untuk memperbarui pemantauan KKPR ini.'` and redirect to `/?page=kkpmr`.

3. WHEN aksi `update_header_pemantauan` diproses di `modules/kkpmr.php`, THE Application SHALL call `ownsKkpr($db, $idKkpr)` before executing the UPDATE query.

4. IF `ownsKkpr()` returns `false` for aksi `update_header_pemantauan`, THEN THE Application SHALL set flash error `'Anda tidak memiliki hak untuk memperbarui KKPR ini.'` and redirect to `/?page=kkpmr`.

5. THE Application SHALL call `ownsKkpr()` only after verifying the CSRF token and before any database write operation.

---

### Requirement 5 — IDOR Protection: ownsRecord() di kkpmr.php untuk Akses Data Read

**User Story:** Sebagai Risk Manager, saya ingin bahwa tampilan data KKPMR sudah membatasi data berdasarkan kepemilikan (sudah menggunakan `$kkpr_cond`), sehingga kondisi ini dipertahankan dan tidak di-bypass.

#### Acceptance Criteria

1. WHILE fetching `kkpr_header` list di `modules/kkpmr.php`, THE Application SHALL apply `$kkpr_cond` (WHERE `created_by = ?` for non-Admin/Pimpinan roles) as it currently does — this existing behavior SHALL be preserved and not regressed.

2. WHEN `$activeId > 0` and fetching a single KKPR row, THE Application SHALL apply `AND created_by = ?` scope for non-Admin/Pimpinan users as it currently does — this existing behavior SHALL be preserved.

---

### Requirement 6 — Verifikasi dan Konfirmasi backup_restore.php

**User Story:** Sebagai Administrator, saya ingin memastikan bahwa operasi restore database memerlukan konfirmasi password Admin yang valid dan menggunakan `requireRole('Admin')`, sehingga tidak ada pengguna non-Admin yang dapat memulihkan database.

#### Acceptance Criteria

1. THE Application SHALL call `requireRole('Admin')` at the top of `modules/backup_restore.php` before any request is processed — this existing behavior SHALL be verified as present and not removed.

2. WHEN a restore POST request is received, THE Application SHALL verify the CSRF token via `verifyCsrf()` before processing the file upload — this existing behavior SHALL be verified as present.

3. WHEN a restore POST request is received, THE Application SHALL require the submitting Admin's current password via `password_verify()` against the database record before executing any SQL — this existing behavior SHALL be verified as present.

4. IF password verification fails during restore, THEN THE Application SHALL reject the restore with flash error and redirect without executing any database modification — this existing behavior SHALL be verified as present.

---

### Requirement 7 — Bug TTD: Lewati Validasi GD untuk Nilai TTD yang Sudah Tersimpan

**User Story:** Sebagai Risk Manager, saya ingin bahwa saat menyimpan formulir tanpa menggambar ulang tanda tangan, nilai TTD lama (path relatif, bukan base64) tidak dicoba divalidasi ulang sebagai gambar baru, sehingga tidak terjadi error atau penimpaan file TTD lama yang valid.

#### Acceptance Criteria

1. WHEN the TTD field value in a POST request does NOT start with `'data:image'` (i.e., it is an existing relative path like `'uploads/ttd/xxx.png'`), THE Application SHALL skip the `saveTtdBase64()` call and retain the existing TTD path value as-is.

2. WHEN the TTD field value starts with `'data:image'` (new signature drawn by the user), THE Application SHALL call `saveTtdBase64()` and use `$validated['path']` from the returned array as the new TTD value.

3. IF `saveTtdBase64()` returns `['valid' => false]`, THEN THE Application SHALL set a flash error and redirect without saving any data.

4. THE Application SHALL apply this TTD handling pattern consistently in `modules/profil_risiko.php` (fields `ttd_pemilik` and `ttd_pengelola`), `modules/kkpr.php` (fields `ttd_pemilik` and `ttd_pengelola`), and `modules/mitigasi.php` (field `ttd_data`).

5. THE Application SHALL NOT store raw base64 data in the database; only relative file paths (e.g., `'uploads/ttd/filename.png'`) SHALL be stored after `saveTtdBase64()` succeeds.

---

### Requirement 8 — IDOR Protection: ownsRecord() di ikk.php sudah ada — Verifikasi Tidak Regress

**User Story:** Sebagai Risk Manager, saya ingin memastikan bahwa `ownsRecord()` yang sudah terpasang di `modules/ikk.php` untuk aksi `simpan` (update) dan `hapus` tetap berjalan dan tidak di-bypass.

#### Acceptance Criteria

1. WHEN aksi `simpan` dengan `$id > 0` diproses di `modules/ikk.php`, THE Application SHALL call `ownsRecord($db, 'ikk', $id)` — this existing behavior SHALL be verified as present and not regressed.

2. WHEN aksi `hapus` diproses di `modules/ikk.php`, THE Application SHALL call `ownsRecord($db, 'ikk', $id)` — this existing behavior SHALL be verified as present and not regressed.

3. IF `ownsRecord()` returns `false` in either case, THEN THE Application SHALL set a flash error and redirect to `/?page=ikk` without executing the database modification.
