# Requirements Document

## Introduction

Fitur Laporan Monitoring dan Evaluasi (Monev) Manajemen Risiko memungkinkan pengguna berwenang untuk menghasilkan dokumen laporan resmi berbasis dokumen terstandarisasi. Laporan mencakup empat bab: Pendahuluan (statis), Pelaksanaan Pengendalian Risiko per unit kerja (enam tabel dinamis), Hambatan per unit kerja, dan Penutup dengan kesimpulan statistik dan rencana tindak lanjut. Dokumen dihasilkan sebagai halaman HTML mandiri yang dapat dicetak atau disimpan sebagai PDF melalui browser. Data bersumber dari JOIN tabel `monev_triwulan`, `kkpr_risiko`, dan `kkpr_header`.

---

## Glossary

- **Laporan Monev**: Dokumen resmi Laporan Monitoring dan Evaluasi Manajemen Risiko yang mencakup empat bab dengan format terstandarisasi.
- **Generator**: Modul PHP `modules/laporan_monev.php` yang memproses parameter form dan menghasilkan output HTML laporan.
- **Form Generator**: Antarmuka input di halaman `?page=laporan_monev` untuk memilih parameter laporan dan mengisi data manual.
- **Unit Kerja**: Enam kelompok pemilik risiko yang dikelompokkan: Sub Bag Administrasi Umum, Tim Kerja Program Layanan, Tim Kerja Mutu/SDM/Kemitraan, Tim Kerja Surveilans/Faktor Risiko/KLB, Instalasi, dan Gratifikasi.
- **Triwulan**: Periode pelaporan triwulanan bernilai 1, 2, 3, atau 4 yang dipilih pengguna.
- **Kondisi Awal TW I**: Nilai kemungkinan (P), dampak (D), nilai risiko, dan tingkat risiko dari tabel `kkpr_risiko` sebagai baseline.
- **Kondisi Akhir TW Terpilih**: Nilai P, D, nilai risiko, dan tingkat risiko dari tabel `monev_triwulan` untuk triwulan yang dipilih.
- **Tren Risiko**: Perbandingan nilai risiko Kondisi Awal TW I dengan Kondisi Akhir TW Terpilih untuk menentukan apakah risiko turun, tetap, atau naik.
- **Sidebar**: Navigasi samping aplikasi yang didefinisikan di `includes/header.php`.
- **Router**: Mekanisme routing di `index.php` menggunakan `$_GET['page']` beserta array `$modules` dan `$postHandlers`.
- **kkpr_header**: Tabel database yang menyimpan metadata KKPR termasuk kolom `unit_pemilik_risiko` dan `tahun`.
- **kkpr_risiko**: Tabel database yang menyimpan data risiko per KKPR termasuk kolom `probabilitas`, `dampak_level`, `nilai_risiko`, `tingkat_risiko`, `kode_risiko`, dan `nama_risiko`.
- **monev_triwulan**: Tabel database yang menyimpan data monev per risiko per triwulan, termasuk kolom `pantau_p`, `pantau_d`, `pantau_nilai`, `pantau_tingkat`, `upaya_pengendalian`, `kendala`, dan `rencana_tindak_lanjut`.

---

## Requirements

### Requirement 1: Pendaftaran Modul dan Navigasi Sidebar

**User Story:** Sebagai pengguna berwenang, saya ingin menemukan menu "Laporan Monev Manajemen Risiko" di dalam grup menu "Laporan" pada sidebar, sehingga saya dapat mengakses fitur laporan dengan mudah.

#### Acceptance Criteria

1. THE Router SHALL mendaftarkan entri `'laporan_monev' => 'modules/laporan_monev.php'` di dalam array `$modules` di `index.php`.
2. THE Router SHALL mendaftarkan entri `'laporan_monev' => 'modules/laporan_monev.php'` di dalam array `$postHandlers` di `index.php`.
3. THE Sidebar SHALL menampilkan tautan sub-menu "Laporan Monev Manajemen Risiko" dengan ikon `fa-file-medical-alt` di dalam grup menu "Laporan" yang sudah ada.
4. WHEN `$currentPage` bernilai `'laporan_monev'`, THE Sidebar SHALL menerapkan kelas `active` pada tautan sub-menu tersebut dan membuka grup menu "Laporan".
5. THE Sidebar SHALL membungkus tautan sub-menu dalam kondisi `hasRole('Admin','Risk Manager','Pimpinan','Koordinator')` sehingga hanya peran berwenang yang melihat menu.
6. WHEN pengguna dengan peran selain Admin, Risk Manager, Pimpinan, atau Koordinator mengakses URL `?page=laporan_monev`, THE Generator SHALL menghentikan eksekusi dan menampilkan pesan error akses ditolak menggunakan fungsi `requireRole`.

---

### Requirement 2: Halaman Form Generator

**User Story:** Sebagai pengguna berwenang, saya ingin mengisi form untuk memilih parameter laporan sebelum dokumen dihasilkan, sehingga saya dapat menyesuaikan isi laporan dengan periode dan penanggung jawab yang benar.

#### Acceptance Criteria

1. WHEN pengguna mengakses `?page=laporan_monev` tanpa parameter generate, THE Generator SHALL menampilkan Form Generator dalam layout utama aplikasi (header + sidebar + footer).
2. THE Form Generator SHALL menyediakan dropdown "Triwulan" dengan pilihan I, II, III, IV yang masing-masing bernilai 1, 2, 3, 4.
3. THE Form Generator SHALL menyediakan dropdown "Tahun" yang diisi dari fungsi `getDaftarTahun` pada tabel `kkpr_header`, dengan nilai default tahun berjalan.
4. THE Form Generator SHALL menyediakan field teks untuk: Nama Koordinator/Verifikator, Nama Penulis, Nama Kepala BBLKL, NIP Kepala BBLKL, dan Tanggal Pengesahan.
5. THE Form Generator SHALL menampilkan tombol "Generate Laporan" yang mengirimkan form via POST atau GET ke handler yang sama.
6. WHEN pengguna menekan "Generate Laporan" dengan Triwulan dan Tahun tidak terisi, THE Form Generator SHALL menampilkan validasi wajib isi pada field tersebut.

---

### Requirement 3: Bypass Layout untuk Output Laporan HTML

**User Story:** Sebagai pengguna berwenang, saya ingin hasil laporan ditampilkan sebagai halaman HTML mandiri yang bisa langsung dicetak, sehingga saya dapat menyimpannya sebagai PDF tanpa elemen navigasi aplikasi.

#### Acceptance Criteria

1. WHEN `$_GET['generate']` bernilai `'1'` dan `$_GET['page']` bernilai `'laporan_monev'`, THE Router SHALL mengeksekusi `modules/laporan_monev.php` dan memanggil `exit` sebelum `includes/header.php` di-include, sehingga output tidak membungkus layout aplikasi.
2. THE Generator SHALL menghasilkan dokumen HTML mandiri dengan tag `<!DOCTYPE html>`, `<html>`, `<head>`, dan `<body>` lengkap tanpa bergantung pada `includes/header.php`.
3. THE Generator SHALL menyertakan blok `<style>` inline dengan `@media print` yang menyembunyikan elemen yang tidak relevan untuk cetak dan mengatur ukuran kertas A4 portrait.
4. THE Generator SHALL menyertakan tombol "Cetak / Simpan PDF" dengan handler `window.print()` yang tersembunyi saat mode cetak aktif via kelas CSS `no-print`.

---

### Requirement 4: Halaman Cover dan Lembar Pengesahan

**User Story:** Sebagai penyusun laporan, saya ingin halaman pertama laporan menampilkan cover resmi dengan variabel dinamis, sehingga dokumen terlihat sesuai format kelembagaan yang berlaku.

#### Acceptance Criteria

1. THE Generator SHALL merender halaman cover dengan logo, judul "LAPORAN MONITORING DAN EVALUASI MANAJEMEN RISIKO", nama instansi "BALAI BESAR LABORATORIUM KESEHATAN LINGKUNGAN", serta periode triwulan dan tahun yang dipilih.
2. THE Generator SHALL merender lembar pengesahan dengan kolom Koordinator/Verifikator (nama dari input form), kolom Dibuat Oleh (nama penulis dari input form), dan kolom Diketahui Oleh (nama + NIP Kepala BBLKL dari input form).
3. THE Generator SHALL merender tanggal pengesahan pada lembar pengesahan menggunakan nilai yang dimasukkan pengguna di form.
4. THE Generator SHALL menerapkan `page-break-after: always` pada elemen cover dan lembar pengesahan agar terpisah secara halaman saat dicetak.

---

### Requirement 5: BAB I Pendahuluan (Konten Statis)

**User Story:** Sebagai penyusun laporan, saya ingin BAB I berisi teks pendahuluan standar yang sudah ditentukan, sehingga setiap laporan yang dihasilkan memiliki dasar hukum dan tujuan yang konsisten.

#### Acceptance Criteria

1. THE Generator SHALL merender BAB I dengan tiga subbab: Latar Belakang, Dasar Hukum, dan Tujuan sebagai teks hardcoded.
2. THE Generator SHALL merender subbab Dasar Hukum berisi tepat 9 (sembilan) butir dasar hukum yang relevan dengan manajemen risiko instansi pemerintah.
3. THE Generator SHALL merender subbab Tujuan berisi tepat 5 (lima) butir tujuan pelaksanaan monitoring dan evaluasi manajemen risiko.
4. THE Generator SHALL menerapkan pemformatan heading BAB I secara konsisten menggunakan tag HTML dan CSS yang sama untuk semua bab.

---

### Requirement 6: BAB II Tabel Pelaksanaan Pengendalian Risiko Per Unit Kerja

**User Story:** Sebagai penyusun laporan, saya ingin BAB II menampilkan enam tabel risiko per unit kerja dengan kolom yang lengkap, sehingga laporan merekam kondisi risiko awal dan akhir periode secara terstruktur.

#### Acceptance Criteria

1. THE Generator SHALL merender BAB II dengan tepat 6 (enam) tabel, masing-masing untuk unit kerja: Sub Bag Administrasi Umum, Tim Kerja Program Layanan, Tim Kerja Mutu/SDM/Kemitraan, Tim Kerja Surveilans/Faktor Risiko/KLB, Instalasi, dan Gratifikasi.
2. THE Generator SHALL mengambil data untuk setiap tabel melalui query JOIN `kkpr_risiko`, `kkpr_header`, dan `monev_triwulan` dengan filter `tahun` dari parameter form dan `triwulan` dari parameter Triwulan yang dipilih, serta filter `unit_pemilik_risiko` menggunakan LIKE untuk mencocokkan nama unit kerja.
3. THE Generator SHALL menampilkan kolom "Kondisi Awal TW I" dengan nilai `probabilitas` (sebagai P), `dampak_level` (sebagai D), `nilai_risiko`, dan `tingkat_risiko` yang bersumber dari tabel `kkpr_risiko`.
4. THE Generator SHALL menampilkan kolom "Upaya Pengendalian" dengan nilai `upaya_pengendalian` dari tabel `monev_triwulan` untuk triwulan yang dipilih; WHEN nilai `upaya_pengendalian` NULL atau kosong, THE Generator SHALL menampilkan tanda "-".
5. THE Generator SHALL menampilkan kolom "Kondisi Akhir TW [Terpilih]" dengan nilai `pantau_p` (sebagai P), `pantau_d` (sebagai D), `pantau_nilai` (sebagai Nilai), dan `pantau_tingkat` (sebagai Tingkat) dari tabel `monev_triwulan`; WHEN baris `monev_triwulan` tidak ditemukan, THE Generator SHALL menampilkan "-" pada sel tersebut.
6. THE Generator SHALL menampilkan kolom "Kode Risiko" dari `kkpr_risiko.kode_risiko` dan kolom "Pernyataan Risiko" dari `kkpr_risiko.nama_risiko` pada setiap baris tabel.
7. WHEN unit kerja tidak memiliki risiko terdaftar di tahun yang dipilih, THE Generator SHALL menampilkan satu baris tabel dengan pesan "Tidak terdapat data risiko untuk unit kerja ini".
8. THE Generator SHALL menerapkan kode warna latar belakang sel tingkat risiko sesuai fungsi `kkprBg`/`kkprColor` yang ada di aplikasi (Sangat Tinggi: merah, Tinggi: kuning, Sedang: kuning muda, Rendah: hijau).

---

### Requirement 7: BAB III Hambatan Per Unit Kerja

**User Story:** Sebagai penyusun laporan, saya ingin BAB III mencatat kendala pelaksanaan pengendalian risiko per unit kerja, sehingga hambatan yang ditemukan terdokumentasi secara resmi.

#### Acceptance Criteria

1. THE Generator SHALL merender BAB III dengan satu subbab per unit kerja untuk keenam unit kerja yang sama dengan BAB II.
2. THE Generator SHALL mengambil nilai `kendala` dari tabel `monev_triwulan` dengan filter `id_risiko` yang berasal dari unit kerja yang bersangkutan dan `triwulan` yang dipilih, lalu menggabungkan nilai unik non-kosong menggunakan `GROUP_CONCAT` atau iterasi PHP.
3. WHEN seluruh baris `monev_triwulan` untuk unit kerja tertentu memiliki `kendala` NULL atau string kosong, THE Generator SHALL menampilkan teks fallback "Tidak ditemukan kendala dalam pelaksanaan kegiatan".

---

### Requirement 8: BAB IV Penutup — Kesimpulan Statistik

**User Story:** Sebagai penyusun laporan, saya ingin BAB IV menampilkan kesimpulan statistik pergerakan risiko per unit kerja, sehingga pembaca dapat memahami tren pengendalian risiko secara cepat.

#### Acceptance Criteria

1. THE Generator SHALL menghitung per unit kerja: jumlah risiko yang nilai akhirnya lebih rendah dari nilai awal (turun), jumlah risiko yang nilai akhirnya sama dengan nilai awal (tetap), jumlah risiko yang nilai akhirnya lebih tinggi dari nilai awal (naik), dan jumlah risiko dengan tingkat akhir "Tinggi" atau "Sangat Tinggi".
2. THE Generator SHALL menampilkan hasil perhitungan tersebut dalam format ringkasan teks atau tabel per unit kerja di subbab Kesimpulan.
3. WHEN risiko tidak memiliki data `monev_triwulan` untuk triwulan yang dipilih, THE Generator SHALL mengecualikan risiko tersebut dari perhitungan statistik pergerakan.

---

### Requirement 9: BAB IV Penutup — Rencana Tindak Lanjut

**User Story:** Sebagai penyusun laporan, saya ingin BAB IV juga mencatat rencana tindak lanjut per unit kerja, sehingga langkah ke depan terdokumentasi dalam laporan resmi.

#### Acceptance Criteria

1. THE Generator SHALL mengambil nilai `rencana_tindak_lanjut` dari tabel `monev_triwulan` untuk setiap unit kerja dengan filter triwulan yang dipilih, lalu menggabungkan nilai unik non-kosong.
2. WHEN seluruh nilai `rencana_tindak_lanjut` untuk unit kerja tertentu NULL atau kosong, THE Generator SHALL menampilkan teks fallback "Rencana Tindak Lanjut yang akan dilakukan adalah melanjutkan upaya pengendalian yang sudah direncanakan".

---

### Requirement 10: Keamanan dan Validasi Input

**User Story:** Sebagai administrator sistem, saya ingin semua input pengguna pada form dan parameter URL divalidasi dan di-escape dengan benar, sehingga laporan tidak rentan terhadap serangan XSS atau injeksi SQL.

#### Acceptance Criteria

1. THE Generator SHALL memanggil fungsi `requireLogin()` dan `requireRole('Admin','Risk Manager','Pimpinan','Koordinator')` di awal eksekusi modul sebelum operasi database apapun.
2. THE Generator SHALL menggunakan prepared statements dengan `bind_param` untuk semua query database yang menyertakan parameter dari input pengguna (`tahun`, `triwulan`, `unit_pemilik_risiko`).
3. THE Generator SHALL menggunakan fungsi `xss()` atau `htmlspecialchars()` untuk semua nilai yang dicetak dari input pengguna (nama koordinator, nama penulis, nama kepala, NIP, tanggal) ke dalam output HTML.
4. THE Generator SHALL menggunakan fungsi `xss()` atau `htmlspecialchars()` untuk semua nilai yang dicetak dari hasil query database ke dalam output HTML.
5. IF parameter `triwulan` dari input pengguna bernilai di luar rentang 1–4, THEN THE Generator SHALL menggantinya dengan nilai default 1 sebelum digunakan dalam query.
6. IF parameter `tahun` dari input pengguna tidak sesuai format empat digit angka, THEN THE Generator SHALL menggantinya dengan tahun berjalan (`date('Y')`) sebelum digunakan dalam query.
