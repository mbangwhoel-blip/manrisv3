# Requirements Document

## Introduction

Fitur ini menambahkan manajemen **Bagian** (unit organisasi seperti "Tim Kerja", "Sub Bagian Umum", dll.) pada aplikasi manajemen risiko manrisv3. Bagian dapat dikelola oleh Admin melalui section/card tambahan di halaman `?page=user` yang sudah ada. Setiap user dapat dikaitkan dengan satu Bagian. Selain itu, tampilan tabel user diperbaiki agar kolom Aksi selalu sejajar dan tidak naik-turun posisinya.

Aplikasi menggunakan PHP dengan MySQLi (`getDB()`), pola autentikasi/otorisasi `requireRole()`, perlindungan CSRF via `csrfField()`/`verifyCsrf()`, sanitasi output via `xss()`, pesan feedback via `setFlash()`/redirect, dan pola modal overlay yang sudah ada di `modules/user.php`.

---

## Glossary

- **Bagian**: Unit organisasi (contoh: "Tim Kerja", "Sub Bagian Umum") yang dapat dikaitkan dengan satu atau lebih user.
- **Admin**: Peran pengguna dengan hak akses penuh untuk mengelola Bagian dan User.
- **User**: Akun pengguna dalam tabel `users` yang dapat memiliki referensi ke satu Bagian.
- **Halaman User**: Halaman web yang diakses melalui `?page=user`, menampilkan manajemen User sekaligus manajemen Bagian.
- **Tabel `bagian`**: Tabel database baru dengan kolom `id`, `nama_bagian`, `created_at`.
- **Kolom `id_bagian`**: Kolom foreign key baru di tabel `users` yang mereferensikan `bagian.id`.
- **Modal**: Komponen overlay form yang sudah digunakan di halaman User untuk tambah/edit data.
- **CSRF Token**: Token keamanan yang dihasilkan oleh `csrfField()` dan diverifikasi oleh `verifyCsrf()`.
- **Flash Message**: Pesan notifikasi satu kali yang disimpan di session oleh `setFlash()` dan ditampilkan saat redirect.
- **act-btn-group**: Kelas CSS yang membungkus tombol-tombol aksi pada setiap baris tabel.

---

## Requirements

### Requirement 1: Skema Database Bagian

**User Story:** Sebagai Admin, saya ingin data Bagian tersimpan dalam tabel tersendiri di database, agar nama Bagian dapat dikelola secara terpusat dan direferensikan oleh User.

#### Acceptance Criteria

1. THE Sistem SHALL menyediakan tabel `bagian` dengan kolom `id` (INT AUTO_INCREMENT PRIMARY KEY), `nama_bagian` (VARCHAR(100) NOT NULL), dan `created_at` (DATETIME DEFAULT CURRENT_TIMESTAMP).
2. THE Sistem SHALL menyediakan kolom `id_bagian` (INT NULL, FOREIGN KEY ke `bagian.id` dengan ON DELETE SET NULL) pada tabel `users`.
3. WHEN tabel `bagian` dihapus referensinya, THE Sistem SHALL mengisi nilai `id_bagian` pada baris `users` terkait menjadi NULL (ON DELETE SET NULL).

---

### Requirement 2: Menambah Bagian Baru

**User Story:** Sebagai Admin, saya ingin menambahkan Bagian baru, agar daftar unit organisasi dalam sistem selalu mutakhir.

#### Acceptance Criteria

1. WHEN Admin mengisi nama Bagian dan menekan tombol Simpan pada form Tambah Bagian, THE Sistem SHALL menyimpan Bagian baru ke tabel `bagian` dan menampilkan Flash Message sukses.
2. IF nama Bagian dikirimkan sebagai string kosong (setelah `trim()`), THEN THE Sistem SHALL menolak penyimpanan dan menampilkan Flash Message error "Nama bagian wajib diisi".
3. IF nama Bagian yang dikirimkan sudah terdapat di tabel `bagian` (pengecekan case-insensitive), THEN THE Sistem SHALL menolak penyimpanan dan menampilkan Flash Message error "Nama bagian sudah ada".
4. WHEN Admin berhasil menambah Bagian, THE Sistem SHALL melakukan redirect ke `?page=user` menggunakan pola `setFlash()`/`header Location`.

---

### Requirement 3: Mengedit Bagian

**User Story:** Sebagai Admin, saya ingin mengedit nama Bagian yang ada, agar perubahan struktur organisasi dapat direfleksikan dalam sistem.

#### Acceptance Criteria

1. WHEN Admin mengirimkan form edit Bagian dengan nama baru yang valid, THE Sistem SHALL memperbarui kolom `nama_bagian` pada baris Bagian yang bersangkutan di tabel `bagian`.
2. IF nama Bagian hasil edit sudah dimiliki oleh Bagian lain (pengecekan case-insensitive, exclude id Bagian yang sedang diedit), THEN THE Sistem SHALL menolak perubahan dan menampilkan Flash Message error "Nama bagian sudah ada".
3. WHEN Admin berhasil mengedit Bagian, THE Sistem SHALL melakukan redirect ke `?page=user` dan menampilkan Flash Message sukses "Bagian berhasil diperbarui".

---

### Requirement 4: Menghapus Bagian

**User Story:** Sebagai Admin, saya ingin menghapus Bagian yang sudah tidak digunakan, agar daftar Bagian tetap bersih dan relevan.

#### Acceptance Criteria

1. WHEN Admin mengonfirmasi hapus Bagian, THE Sistem SHALL menghapus baris Bagian dari tabel `bagian`.
2. WHEN sebuah Bagian dihapus, THE Sistem SHALL mengisi nilai `id_bagian` pada seluruh User yang memiliki referensi ke Bagian tersebut menjadi NULL (ditangani oleh constraint ON DELETE SET NULL di database).
3. WHEN Admin berhasil menghapus Bagian, THE Sistem SHALL menampilkan Flash Message sukses "Bagian berhasil dihapus" dan melakukan redirect ke `?page=user`.

---

### Requirement 5: Keamanan Akses dan CSRF

**User Story:** Sebagai Admin, saya ingin setiap operasi Bagian dilindungi agar tidak dapat dieksploitasi oleh pengguna tidak berwenang atau serangan CSRF.

#### Acceptance Criteria

1. WHILE pengguna mengakses halaman `?page=user`, THE Sistem SHALL memanggil `requireRole('Admin')` sebelum memproses request apapun terkait Bagian.
2. WHEN form Tambah, Edit, atau Hapus Bagian dikirimkan via POST, THE Sistem SHALL memverifikasi CSRF Token menggunakan `verifyCsrf()`.
3. IF CSRF Token tidak valid atau tidak ada, THEN THE Sistem SHALL menolak request, menampilkan Flash Message error "Token tidak valid", dan melakukan redirect ke `?page=user`.
4. THE Sistem SHALL menyertakan `csrfField()` pada setiap form Tambah, Edit, dan Hapus Bagian.

---

### Requirement 6: Tampilan Section Manajemen Bagian

**User Story:** Sebagai Admin, saya ingin melihat dan mengelola daftar Bagian dalam section/card tersendiri di halaman `?page=user`, agar pengelolaan Bagian mudah diakses tanpa harus berpindah halaman.

#### Acceptance Criteria

1. THE Sistem SHALL menampilkan section/card "Manajemen Bagian" di bawah tabel User pada halaman `?page=user`, berisi tabel daftar Bagian beserta tombol tambah, edit, dan hapus.
2. WHEN daftar Bagian ditampilkan, THE Sistem SHALL mengurutkan Bagian berdasarkan `nama_bagian` secara ascending.
3. THE Sistem SHALL menampilkan kolom No, Nama Bagian, dan Aksi (Edit, Hapus) pada tabel daftar Bagian.
4. WHEN Admin menekan tombol "Tambah Bagian", THE Sistem SHALL membuka modal overlay form Tambah Bagian.
5. WHEN Admin menekan tombol Edit pada baris Bagian, THE Sistem SHALL membuka modal overlay form Edit Bagian dengan nilai `nama_bagian` yang sudah terisi.
6. WHEN Admin menekan tombol Hapus pada baris Bagian, THE Sistem SHALL membuka modal overlay konfirmasi Hapus Bagian yang menampilkan nama Bagian yang akan dihapus.
7. THE Sistem SHALL menggunakan fungsi `xss()` pada seluruh output nama Bagian di halaman tersebut.

---

### Requirement 7: Integrasi Bagian pada Form User

**User Story:** Sebagai Admin, saya ingin dapat memilih Bagian saat menambah atau mengedit User, agar setiap User dapat dikaitkan dengan unit organisasinya.

#### Acceptance Criteria

1. THE Sistem SHALL menampilkan dropdown select "Bagian" pada form Tambah User dan Edit User di modal User, berisi seluruh Bagian yang tersedia beserta pilihan kosong/tidak ada ("— Pilih Bagian —").
2. WHEN Admin memilih Bagian pada form User dan menyimpan, THE Sistem SHALL menyimpan nilai `id_bagian` yang dipilih ke kolom `id_bagian` pada tabel `users`.
3. WHEN Admin mengosongkan pilihan Bagian pada form User dan menyimpan, THE Sistem SHALL menyimpan nilai NULL pada kolom `id_bagian` tabel `users`.
4. WHEN Admin membuka modal Edit User, THE Sistem SHALL menampilkan Bagian yang sudah dipilih sebelumnya sebagai nilai terpilih pada dropdown Bagian.
5. THE Sistem SHALL menampilkan nama Bagian (bukan id) pada kolom tabel daftar User.

---

### Requirement 8: Perbaikan Tampilan Kolom Aksi Tabel User

**User Story:** Sebagai Admin, saya ingin kolom Aksi pada tabel User selalu rapi dan sejajar, agar tampilan tabel mudah dibaca dan digunakan.

#### Acceptance Criteria

1. THE Sistem SHALL menerapkan `white-space: nowrap` pada sel (`<td>`) kolom Aksi di tabel User, sehingga tombol-tombol aksi tidak berpindah baris.
2. THE Sistem SHALL menerapkan lebar tetap (fixed width) pada kolom Aksi di tabel User menggunakan atribut CSS atau `<col>` agar kolom tidak meregang atau menyempit secara tidak konsisten.
3. THE Sistem SHALL menerapkan `vertical-align: middle` pada seluruh sel (`<td>`) tabel User agar semua konten baris selalu sejajar secara vertikal.
4. THE Sistem SHALL memastikan elemen `.act-btn-group` menggunakan `display: flex`, `align-items: center`, dan `gap` yang konsisten agar tombol aksi tidak bergeser posisinya antar-baris.
