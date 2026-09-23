# Implementation Plan: Security Bugfix — SEMAR (Sistem Informasi Manajemen Risiko)

## Overview

Rencana implementasi ini mencakup delapan perbaikan keamanan bedah yang dibagi menjadi dua kelompok:
- **Perubahan aktif** (4 task): tambah demo-password block di `auth.php`, tambah `error_log()` di `mitigasi.php` hapus, perluas prefix TTD di `mitigasi.php` simpan, dan dokumentasi komentar `.htaccess`.
- **Verifikasi non-regresi** (4 task): konfirmasi `ownsKkpr()` di `kkpmr.php`, TTD handling di `profil_risiko.php` + `kkpr.php`, `ownsRecord()` di `ikk.php`, dan semua guard di `backup_restore.php`.

Semua perubahan bersifat minimal dan independen — tidak ada yang bergantung pada task lain.

---

## Tasks

- [x] 1. Verifikasi dan dokumentasi `.htaccess` FilesMatch
  - Baca `.htaccess` dan konfirmasi bahwa FilesMatch block ke-3 sudah mencakup semua nama: `check_users`, `dbcheck`, `scratch`, `scratch2`, `scratch3`, `temp`.
  - Tambahkan komentar inline di blok FilesMatch yang menjelaskan tujuan masing-masing kelompok nama (maintenance scripts sekali-jalan).
  - Pastikan tidak ada nama dari daftar Requirement 1.1 yang hilang; jika ada, tambahkan.
  - _Requirements: 1.1, 1.3, 1.4_

- [x] 2. Tambahkan demo-password block di `includes/auth.php`
  - [x] 2.1 Implementasi demo password enforcement
    - Di dalam blok `if ($user && $user['aktif'] == 1 && password_verify(...))` di `includes/auth.php`, tambahkan pengecekan sebelum `session_regenerate_id()`:
      ```php
      $demoPwList = ['demo123', 'password'];
      if (APP_ENV !== 'development' && in_array($password, $demoPwList, true)) {
          logAktivitas('LOGIN_GAGAL', 'auth', null,
              'Percobaan login dengan demo password di production, username: ' . $username);
          setFlash('error', 'Username atau password salah.');
          header('Location: ' . APP_URL . '/index.php?page=login');
          exit;
      }
      ```
    - Pertahankan blok lama (`$password === 'password'` sebelum DB lookup) — jangan hapus.
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5_

  - [ ]* 2.2 Tulis property test untuk demo password block
    - **Property 1: Demo password selalu ditolak di production**
    - Generate kombinasi acak: username valid/invalid × password dari `$demoPwList` × `APP_ENV` production/development.
    - Assert: jika `APP_ENV !== 'development'` dan password ada di `$demoPwList` → tidak ada session yang dibuat, flash error di-set, redirect ke halaman login.
    - Assert: jika `APP_ENV === 'development'` → blok tidak aktif (login dilanjutkan normal).
    - **Validates: Requirements 2.1, 2.3**

- [x] 3. Tambahkan `error_log()` di `modules/mitigasi.php` aksi hapus
  - [x] 3.1 Tambahkan logging IDOR ke ownership check yang sudah ada
    - Di dalam blok aksi `hapus` di `modules/mitigasi.php`, modifikasi kondisi gagal ownership:
      ```php
      if (!$row || (!canAccessAllRecords() && (int)$row['id_user_input'] !== (int)$_SESSION['user_id'])) {
          error_log('[manris] IDOR attempt blocked: User ID ' . (int)$_SESSION['user_id']
              . ' tried to delete mitigasi ID ' . $id);
          setFlash('error', 'Anda tidak memiliki hak untuk menghapus mitigasi ini.');
          header('Location: ' . APP_URL . '/?page=mitigasi'); exit;
      }
      ```
    - Ownership check (JOIN + `canAccessAllRecords()`) sudah ada — hanya tambahkan `error_log()` satu baris.
    - _Requirements: 3.1, 3.2, 3.3, 3.4_

  - [ ]* 3.2 Tulis property test untuk IDOR mitigasi hapus
    - **Property 2: Otorisasi hapus mitigasi mencakup semua pengguna non-pemilik**
    - Generate pasangan acak: user B (bukan Admin/Pimpinan, bukan pemilik risiko) mencoba hapus mitigasi milik user A.
    - Assert: request ditolak (redirect + flash error), DELETE query tidak dieksekusi, `error_log()` dipanggil.
    - **Validates: Requirements 3.1, 3.2, 3.3**

- [x] 4. Perbaiki TTD prefix check di `modules/mitigasi.php` aksi simpan
  - [x] 4.1 Perluas prefix TTD dari `'data:image/png;base64,'` ke `'data:image'`
    - Di `modules/mitigasi.php` aksi `simpan`, cari baris:
      ```php
      if (!empty($ttdData) && str_starts_with($ttdData, 'data:image/png;base64,')) {
      ```
    - Ganti dengan:
      ```php
      if (!empty($ttdData) && str_starts_with($ttdData, 'data:image')) {
      ```
    - Perubahan ini mendukung JPEG dan format image lain, konsisten dengan pola di `profil_risiko.php` dan `kkpr.php`.
    - Pastikan cabang `elseif (!empty($ttdData))` tidak ada/tidak diperlukan — jika nilai bukan `data:image` dan tidak kosong, itu sudah menjadi path lama; nilai `$ttdData` langsung dipakai untuk UPDATE.
    - _Requirements: 7.1, 7.2, 7.4, 7.5_

  - [ ]* 4.2 Tulis property test untuk TTD conditional logic
    - **Property 6: Nilai TTD di database selalu berupa path relatif, bukan base64**
    - Generate TTD input: (a) string `data:image/...` valid, (b) string `data:image/jpeg;base64,...`, (c) path relatif `uploads/ttd/xxx.png`, (d) string kosong.
    - Assert untuk (a) dan (b): `saveTtdBase64()` dipanggil; nilai yang disimpan ke DB adalah path relatif.
    - Assert untuk (c): `saveTtdBase64()` tidak dipanggil; nilai lama digunakan langsung.
    - Assert untuk (d): TTD field tidak diupdate.
    - **Validates: Requirements 7.2, 7.5**
    - **Property 7: TTD lama tidak pernah dikirim ke saveTtdBase64()**
    - Assert: untuk semua input yang tidak diawali `'data:image'`, `saveTtdBase64()` tidak dipanggil.
    - **Validates: Requirements 7.1, 7.4**

- [x] 5. Checkpoint — Verifikasi semua perubahan aktif
  - Pastikan semua test pass, lakukan smoke-check manual di setiap file yang dimodifikasi, tanyakan ke user jika ada pertanyaan.

- [x] 6. Verifikasi non-regresi `modules/kkpmr.php` (ownsKkpr)
  - [x] 6.1 Baca dan konfirmasi keberadaan ownsKkpr() di kedua aksi
    - Baca `modules/kkpmr.php` dan konfirmasi:
      - `ownsKkpr($db, $idKkpr)` dipanggil di aksi `simpan_pemantauan` sebelum UPDATE query.
      - `ownsKkpr($db, $idKkpr)` dipanggil di aksi `update_header_pemantauan` sebelum UPDATE query.
      - Kedua blok sudah ada persis setelah `verifyCsrf()` dan sebelum `$db->prepare(UPDATE ...)`.
    - Jika salah satu blok tidak ada, tambahkan sesuai pola dari design Component 4.
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5_

  - [ ]* 6.2 Tulis property test untuk ownsKkpr KKPMR
    - **Property 3: ownsKkpr() memblokir semua write KKPMR oleh non-pemilik**
    - Generate pasangan user acak: user B (bukan Admin/Pimpinan, bukan pemilik) mencoba aksi `simpan_pemantauan` dan `update_header_pemantauan` terhadap KKPR milik user A.
    - Assert: keduanya ditolak sebelum UPDATE dieksekusi.
    - **Validates: Requirements 4.1, 4.2, 4.3, 4.4**
    - **Property 4: Data KKPMR yang ditampilkan selalu terisolasi per kepemilikan**
    - Generate user dengan role Risk Manager, assert daftar KKPR hanya berisi `created_by = user_id`.
    - **Validates: Requirements 5.1, 5.2**

- [x] 7. Verifikasi non-regresi `modules/profil_risiko.php` dan `modules/kkpr.php` (TTD)
  - [x] 7.1 Konfirmasi str_starts_with pattern di profil_risiko.php dan kkpr.php
    - Baca `modules/profil_risiko.php`: konfirmasi `ttd_pemilik` dan `ttd_pengelola` menggunakan `str_starts_with($f['ttd_pemilik'], 'data:image')` (bukan prefix PNG spesifik).
    - Baca `modules/kkpr.php`: konfirmasi `ttd_pemilik` dan `ttd_pengelola` menggunakan pola yang sama.
    - Jika ditemukan prefix yang terlalu spesifik (e.g. `data:image/png;base64,`), perluas ke `data:image` — sama seperti task 4.1.
    - _Requirements: 7.1, 7.2, 7.4_

- [x] 8. Verifikasi non-regresi `modules/backup_restore.php`
  - [x] 8.1 Konfirmasi semua security controls di backup_restore.php
    - Baca `modules/backup_restore.php` dan konfirmasi ketiga guard berikut ada dan tidak bisa di-bypass:
      1. `requireRole('Admin')` di baris paling atas (setelah `requireLogin()`).
      2. `verifyCsrf()` di awal blok POST restore.
      3. `password_verify()` untuk autentikasi ulang Admin sebelum eksekusi SQL.
    - Jika salah satu hilang, tambahkan sesuai pola dari design Component 6.
    - _Requirements: 6.1, 6.2, 6.3, 6.4_

  - [ ]* 8.2 Tulis property test untuk restore password verification
    - **Property 5: Restore database selalu gagal untuk password yang salah**
    - Generate POST restore request dengan `confirm_password` acak (bukan password Admin yang valid).
    - Assert: tidak ada query SQL yang dieksekusi, flash error di-set, redirect dilakukan.
    - **Validates: Requirements 6.3, 6.4**

- [x] 9. Verifikasi non-regresi `modules/ikk.php` (ownsRecord)
  - [x] 9.1 Konfirmasi ownsRecord() di aksi simpan dan hapus
    - Baca `modules/ikk.php` dan konfirmasi:
      - `ownsRecord($db, 'ikk', $id)` dipanggil di aksi `simpan` ketika `$id > 0`.
      - `ownsRecord($db, 'ikk', $id)` dipanggil di aksi `hapus`.
      - Kedua blok menghasilkan flash error + redirect jika false.
    - Jika salah satu hilang, tambahkan sesuai pola dari design Component 8.
    - _Requirements: 8.1, 8.2, 8.3_

  - [ ]* 9.2 Tulis property test untuk ownsRecord IKK
    - **Property 8: ownsRecord() di ikk.php memblokir semua write oleh non-pemilik**
    - Generate pasangan user acak: user B (bukan Admin/Pimpinan) mencoba `simpan` (update) dan `hapus` terhadap IKK milik user A.
    - Assert: kedua aksi ditolak, DB tidak dimodifikasi, flash error di-set.
    - **Validates: Requirements 8.1, 8.2, 8.3**

- [x] 10. Final checkpoint — Semua test pass
  - Pastikan semua test pass, ask the user if questions arise.

---

## Notes

- Task 1 dan 5–9 adalah verifikasi/non-regresi — baca file, konfirmasi guard ada, tambahkan hanya jika tidak ada.
- Task 2–4 adalah perubahan aktif — modifikasi kode sesuai snippet di design document.
- Task bertanda `*` adalah opsional dan dapat dilewati untuk MVP lebih cepat.
- Setiap task referensikan requirement spesifik (granular sub-requirement, bukan hanya user story).
- Checkpoints memastikan validasi inkremental setelah semua perubahan aktif selesai.
- Property tests memvalidasi invariant universal; unit tests memvalidasi contoh spesifik dan edge case.
- Semua file PHP menggunakan pola yang sudah ada di kodebase — tidak ada library eksternal baru.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1", "6.1", "7.1", "8.1", "9.1"] },
    { "id": 1, "tasks": ["2.1", "3.1", "4.1"] },
    { "id": 2, "tasks": ["2.2", "3.2", "4.2", "6.2", "8.2", "9.2"] }
  ]
}
```
