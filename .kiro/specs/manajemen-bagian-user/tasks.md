# Implementation Plan: Manajemen Bagian User

## Overview

Implementasi menambahkan entitas **Bagian** (unit organisasi) ke aplikasi manrisv3 PHP+MySQLi. Perubahan mencakup: migration SQL, perluasan handler POST di `modules/user.php`, query dengan JOIN, section baru Manajemen Bagian beserta modal CRUD, dropdown Bagian pada modal User, dan perbaikan CSS kolom Aksi agar selalu sejajar.

## Tasks

- [ ] 1. Buat migration SQL dan file skema database
  - Buat file `add_bagian.sql` di root workspace berisi: `CREATE TABLE IF NOT EXISTS bagian`, `ALTER TABLE users ADD COLUMN IF NOT EXISTS id_bagian`, dan `ADD CONSTRAINT fk_users_bagian FOREIGN KEY ... ON DELETE SET NULL ON UPDATE CASCADE`
  - Pastikan script idempotent agar aman dijalankan ulang
  - _Requirements: 1.1, 1.2, 1.3_

- [ ] 2. Perbaiki CSS kolom Aksi dan act-btn-group di `assets/css/main.css`
  - [ ] 2.1 Tambahkan/perbarui aturan CSS `.act-btn-group` dengan `display:flex`, `align-items:center`, `gap:6px`, `flex-wrap:nowrap`
    - Cari bagian "Unified Icon Action Buttons" di `main.css` dan tambahkan di bawahnya
    - _Requirements: 8.4_
  - [ ] 2.2 Tambahkan aturan CSS `.data-table td`, `.data-table th` dengan `vertical-align:middle`, dan `.data-table td.action-cell` / `.data-table th.action-cell` dengan `white-space:nowrap; width:130px; min-width:110px`
    - _Requirements: 8.1, 8.2, 8.3_

- [ ] 3. Perbarui `modules/user.php` — logika PHP (handler, query, data retrieval)
  - [ ] 3.1 Tambahkan query `$daftarBagian` mengambil semua bagian dari tabel `bagian` urut `nama_bagian ASC`, dan perbarui query `$users` dengan `LEFT JOIN bagian b ON b.id = u.id_bagian` untuk mendapatkan `nama_bagian`
    - Letakkan query `$daftarBagian` sebelum rendering HTML, bersama query user yang sudah ada
    - _Requirements: 6.2, 7.5_
  - [ ] 3.2 Tambahkan handler POST `bagian_simpan` untuk INSERT dan UPDATE tabel `bagian`, lengkap dengan validasi nama kosong, cek duplikat case-insensitive (exclude id saat edit), `setFlash`, dan redirect
    - Gunakan prepared statements, integer cast untuk `$idBagian`, dan `logAktivitas()`
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 3.1, 3.2, 3.3, 5.2, 5.4_
  - [ ] 3.3 Tambahkan handler POST `bagian_hapus` untuk DELETE tabel `bagian`, dengan guard `$idBagian > 0`, `logAktivitas()`, `setFlash`, dan redirect
    - ON DELETE SET NULL pada FK menangani `users.id_bagian` secara otomatis
    - _Requirements: 4.1, 4.2, 4.3, 5.2, 5.4_
  - [ ] 3.4 Perbarui handler POST `simpan` (User) untuk membaca `$_POST['id_bagian_user']`, cast ke `(int)` atau `null` jika kosong, dan sertakan `id_bagian` pada query INSERT dan UPDATE `users`
    - _Requirements: 7.2, 7.3_

- [ ] 4. Checkpoint — Verifikasi handler dan query
  - Ensure semua handler baru (bagian_simpan, bagian_hapus, simpan user dengan id_bagian) sudah terdaftar sebelum handler lama, CSRF guard dipanggil sekali di awal, dan tidak ada syntax error PHP. Tanyakan ke user jika ada hal yang perlu dikonfirmasi.

- [ ] 5. Perbarui HTML di `modules/user.php` — tabel User dan modal User
  - [ ] 5.1 Tambahkan kolom `<th>Bagian</th>` di header tabel User (antara Role dan Kode Prefix), dan tambahkan `<td><?= xss($u['nama_bagian'] ?? '—') ?></td>` pada setiap baris `<tr>` user
    - _Requirements: 7.5_
  - [ ] 5.2 Terapkan `class="action-cell"` pada `<th>Aksi</th>` dan `<td>` kolom Aksi tabel User agar aturan CSS action-cell berlaku; pastikan `<div class="act-btn-group">` membungkus tombol aksi
    - _Requirements: 8.1, 8.2, 8.3, 8.4_
  - [ ] 5.3 Tambahkan dropdown `<select name="id_bagian_user" id="uBagian">` berisi opsi "— Pilih Bagian —" dan loop `$daftarBagian` ke dalam modal Tambah/Edit User, di dalam `<div class="form-row-2">` setelah select Role
    - _Requirements: 7.1_

- [ ] 6. Tambahkan section Manajemen Bagian dan modal Bagian ke HTML `modules/user.php`
  - [ ] 6.1 Tambahkan card section "Manajemen Bagian" di bawah tabel User, berisi header dengan tombol "Tambah Bagian" dan tabel daftar `$daftarBagian` dengan kolom No, Nama Bagian, dan Aksi (Edit, Hapus) dengan `class="action-cell"` dan `act-btn-group`
    - Gunakan `xss()` pada output `nama_bagian`; gunakan `jsEncode()` pada nilai yang diembed di `onclick`
    - _Requirements: 6.1, 6.2, 6.3, 6.7_
  - [ ] 6.2 Tambahkan modal overlay `id="modalBagian"` (digunakan untuk Tambah dan Edit Bagian) dengan form POST `aksi=bagian_simpan`, `csrfField()`, hidden `id_bagian_edit`, dan input `nama_bagian`
    - _Requirements: 6.4, 6.5, 5.4_
  - [ ] 6.3 Tambahkan modal overlay `id="modalHapusBagian"` dengan form POST `aksi=bagian_hapus`, `csrfField()`, dan tampilkan nama Bagian yang akan dihapus
    - _Requirements: 6.6, 5.4_

- [ ] 7. Tambahkan JavaScript untuk interaksi modal Bagian di `modules/user.php`
  - [ ] 7.1 Tambahkan fungsi `tambahBagian()`, `editBagian(id, nama)`, dan `hapusBagian(id, nama)` yang memanipulasi nilai hidden field dan teks modal, lalu memanggil `openModal()`/`closeModal()` yang sudah ada
    - _Requirements: 6.4, 6.5, 6.6_
  - [ ] 7.2 Perbarui fungsi `editUser()` yang sudah ada dengan menambahkan baris `document.getElementById('uBagian').value = u.id_bagian || ''` agar dropdown Bagian menampilkan nilai yang sudah dipilih saat edit user
    - _Requirements: 7.4_

- [ ] 8. Checkpoint Akhir — Verifikasi keseluruhan fitur
  - Ensure semua test PHP/browser pass: CRUD Bagian berfungsi, dropdown Bagian di modal User terisi, kolom Bagian di tabel User tampil, kolom Aksi tabel User sejajar dan tidak naik-turun. Tanyakan ke user jika ada hal yang perlu dikonfirmasi.

## Notes

- Tasks bertanda `*` adalah opsional dan dapat dilewati untuk MVP lebih cepat
- Setiap task mereferensikan requirement spesifik untuk traceability
- Migration SQL (`add_bagian.sql`) harus dijalankan manual oleh Admin sebelum fitur digunakan
- ON DELETE SET NULL pada FK menangani cascade NULL ke `users.id_bagian` secara otomatis di database level — tidak perlu query manual
- `jsEncode()` digunakan untuk nilai yang diembed di atribut HTML `onclick` (bukan `xss()`) guna mencegah XSS via JavaScript string
- Seluruh query menggunakan prepared statements — tidak ada interpolasi string parameter user ke query SQL

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1", "2.1", "2.2"] },
    { "id": 1, "tasks": ["3.1", "3.2", "3.3", "3.4"] },
    { "id": 2, "tasks": ["5.1", "5.2", "5.3", "6.1", "6.2", "6.3"] },
    { "id": 3, "tasks": ["7.1", "7.2"] }
  ]
}
```
