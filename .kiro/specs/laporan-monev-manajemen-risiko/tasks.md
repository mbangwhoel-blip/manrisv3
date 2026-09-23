# Implementation Plan: Laporan Monitoring dan Evaluasi Manajemen Risiko

## Overview

Implementasi modul `laporan_monev` sebagai generator dokumen HTML mandiri untuk Laporan Monitoring dan Evaluasi Manajemen Risiko. Modul beroperasi dalam dua mode: Mode Form (layout utama aplikasi) dan Mode Generate (bypass layout, output HTML siap cetak/PDF). Implementasi mengikuti pola yang sudah ada di `laporan_konsolidasi.php` dan modul ekspor lainnya.

## Tasks

- [x] 1. Registrasi modul dan sidebar
  - [x] 1.1 Tambahkan entri modul ke `index.php`
    - Daftarkan `'laporan_monev' => 'modules/laporan_monev.php'` di array `$modules`
    - Daftarkan `'laporan_monev' => 'modules/laporan_monev.php'` di array `$postHandlers`
    - Tambahkan blok bypass layout sebelum blok `$modules`: jika `$page === 'laporan_monev'` dan `$_GET['generate'] === '1'`, include modul dan `exit`
    - _Requirements: 1.1, 1.2, 3.1_

  - [x] 1.2 Tambahkan entri sidebar di `includes/header.php`
    - Perbarui variabel `$isLaporanGroupActive` agar menyertakan `'laporan_monev'`
    - Tambahkan sub-menu "Laporan Monev Manajemen Risiko" dengan ikon `fa-file-medical-alt` di dalam grup menu "Laporan" yang sudah ada
    - Bungkus dalam kondisi `hasRole('Admin','Risk Manager','Pimpinan','Koordinator')`
    - Terapkan kelas `active` saat `$currentPage === 'laporan_monev'`
    - _Requirements: 1.3, 1.4, 1.5_

- [x] 2. Buat file modul dengan auth, sanitasi input, dan helper functions
  - [x] 2.1 Buat `modules/laporan_monev.php` — struktur dasar, auth, dan sanitasi
    - `require_once` untuk `config.php` dan `functions.php`
    - Panggil `requireLogin()` dan `requireRole('Admin', 'Risk Manager', 'Pimpinan', 'Koordinator')` di paling awal
    - Sanitasi `$triwulan` (int, default 1, clamp 1–4)
    - Sanitasi `$tahun` (string, validasi regex `/^\d{4}$/`, default `date('Y')`)
    - Sanitasi field string bebas: `$koordinator`, `$penulis`, `$namaKepala`, `$nipKepala`, `$tanggal` via `trim()`
    - Tentukan `$isGenerate` dari `$_GET['generate'] === '1'`
    - Definisikan konstanta `LAPORAN_UNIT_KERJA` dengan 6 unit kerja
    - _Requirements: 1.6, 10.1, 10.2, 10.5, 10.6_

  - [x] 2.2 Tulis property test untuk sanitasi input (Property 12 dan 13)
    - **Property 12: Sanitasi triwulan di luar rentang**
    - **Validates: Requirements 10.5**
    - **Property 13: Sanitasi tahun non-format 4-digit**
    - **Validates: Requirements 10.6**

  - [x] 2.3 Implementasi helper functions di dalam modul
    - `laporanRisikoBg(string $tingkat): string` — warna background sel tingkat risiko
    - `laporanRisikoColor(string $tingkat): string` — warna teks sel tingkat risiko
    - `laporanGetDataUnit(mysqli $db, string $unitKerja, string $tahun, int $triwulan): array` — query JOIN dengan prepared statement dan `bind_param`
    - `laporanGetAggregat(mysqli $db, string $unitKerja, string $tahun, int $triwulan, string $kolom): array` — ambil nilai unik non-kosong dari kolom monev
    - `laporanHitungStatistik(array $rows): array` — hitung turun/tetap/naik/tinggi dari rows
    - _Requirements: 6.2, 6.3, 7.2, 8.1, 8.3, 10.2_

  - [x] 2.4 Tulis property test untuk helper functions (Property 9)
    - **Property 9: Statistik tren risiko akurat dan filter risiko tanpa monev**
    - **Validates: Requirements 8.1, 8.3**

- [x] 3. Checkpoint — Pastikan semua test pass
  - Pastikan semua test pass, tanyakan kepada user jika ada pertanyaan.

- [x] 4. Implementasi Mode Form (halaman input parameter)
  - [x] 4.1 Render Form Generator dalam layout aplikasi
    - Tambahkan blok `else` untuk Mode Form di `laporan_monev.php`
    - Tampilkan form dengan card/panel menggunakan styling yang konsisten dengan modul lain
    - Dropdown "Triwulan" dengan value I/II/III/IV → 1/2/3/4
    - Dropdown "Tahun" dari `getDaftarTahun()` dengan default tahun berjalan
    - Input text: Koordinator, Penulis, Nama Kepala BBLKL, NIP Kepala BBLKL, Tanggal Pengesahan
    - Tombol "Generate Laporan" submit via GET ke `?page=laporan_monev&generate=1`
    - Validasi HTML5 `required` pada Triwulan dan Tahun
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6_

- [x] 5. Implementasi Mode Generate — dokumen HTML mandiri (struktur & cover)
  - [x] 5.1 Output header HTML dan CSS inline
    - `<!DOCTYPE html>`, `<html lang="id">`, `<head>` dengan charset UTF-8 dan judul dinamis
    - Blok `<style>` inline: base styles (Times New Roman 12pt, A4 portrait), `.page-break`, `.no-print`, `@media print`
    - Tombol "Cetak / Simpan PDF" dengan `onclick="window.print()"` dan class `no-print`
    - _Requirements: 3.2, 3.3, 3.4_

  - [x] 5.2 Render halaman Cover dan Lembar Pengesahan
    - Halaman Cover: logo, judul "LAPORAN MONITORING DAN EVALUASI MANAJEMEN RISIKO", nama instansi, periode triwulan dan tahun
    - Lembar Pengesahan: tabel tiga kolom (Koordinator/Verifikator, Dibuat Oleh, Diketahui Oleh) dengan nama, NIP, tanggal dari input form
    - Semua nilai user-input di-escape dengan `xss()` atau `htmlspecialchars()`
    - Terapkan `page-break-after: always` via class `page-break` pada cover dan lembar pengesahan
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 10.3_

  - [x] 5.3 Tulis property test untuk cover dan lembar pengesahan (Property 2)
    - **Property 2: Cover dan lembar pengesahan mencerminkan input form**
    - **Validates: Requirements 4.1, 4.2, 4.3**

  - [x] 5.4 Tulis property test untuk output escaping (Property 14)
    - **Property 14: Output HTML bebas dari karakter HTML mentah pada nilai user-input**
    - **Validates: Requirements 10.3, 10.4**

- [x] 6. Implementasi BAB I — Pendahuluan (konten statis)
  - [x] 6.1 Render BAB I dengan tiga subbab hardcoded
    - Subbab 1.A: Latar Belakang — paragraf manajemen risiko Kemenkes dan BBLKL
    - Subbab 1.B: Dasar Hukum — 9 butir sesuai design document
    - Subbab 1.C: Tujuan — 5 butir sesuai design document
    - Gunakan tag heading yang konsisten untuk semua bab
    - _Requirements: 5.1, 5.2, 5.3, 5.4_

- [x] 7. Implementasi BAB II — Tabel Pelaksanaan Pengendalian Risiko
  - [x] 7.1 Render 6 tabel unit kerja dengan data dari query
    - Loop `LAPORAN_UNIT_KERJA`, panggil `laporanGetDataUnit()` untuk setiap unit
    - Render tabel dengan header: No, Kode Risiko, Pernyataan Risiko, Kondisi Awal TW I (colspan=4: P/D/Nilai/Tingkat), Upaya Pengendalian, Kondisi Akhir TW [n] (colspan=4: P/D/Nilai/Tingkat)
    - Jika rows kosong, tampilkan satu baris fallback "Tidak terdapat data risiko untuk unit kerja ini"
    - `upaya_pengendalian` NULL/kosong → tampilkan "-"
    - Kolom akhir NULL (LEFT JOIN) → tampilkan "-"
    - Semua nilai dari DB di-escape dengan `xss()`
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 10.4_

  - [x] 7.2 Tulis property test untuk pemetaan data awal (Property 3)
    - **Property 3: Data risiko awal dipetakan ke kolom Kondisi Awal TW I**
    - **Validates: Requirements 6.3, 6.6**

  - [x] 7.3 Terapkan warna sel tingkat risiko menggunakan helper functions
    - Sel "Tingkat" pada Kondisi Awal dan Kondisi Akhir menggunakan `laporanRisikoBg()` untuk background dan `laporanRisikoColor()` untuk warna teks
    - _Requirements: 6.8_

  - [x] 7.4 Tulis property test untuk warna tingkat risiko (Property 6)
    - **Property 6: Warna sel tingkat risiko konsisten dengan lookup**
    - **Validates: Requirements 6.8**

  - [x] 7.5 Tulis property test untuk nilai NULL/kosong (Property 4 dan 5)
    - **Property 4: Nilai NULL/kosong di-render sebagai "-"**
    - **Validates: Requirements 6.4, 6.5**
    - **Property 5: Unit kerja tanpa risiko menampilkan pesan fallback**
    - **Validates: Requirements 6.7**

- [x] 8. Implementasi BAB III — Hambatan Per Unit Kerja
  - [x] 8.1 Render subbab kendala untuk 6 unit kerja
    - Loop `LAPORAN_UNIT_KERJA`, panggil `laporanGetAggregat()` dengan kolom `'kendala'`
    - Tampilkan setiap nilai unik non-kosong sebagai paragraf atau list item
    - Jika array kosong, tampilkan teks fallback "Tidak ditemukan kendala dalam pelaksanaan kegiatan"
    - Semua nilai di-escape dengan `xss()`
    - _Requirements: 7.1, 7.2, 7.3, 10.4_

  - [x] 8.2 Tulis property test untuk agregasi kendala (Property 7 dan 8)
    - **Property 7: Agregasi kendala mencakup semua nilai non-kosong unik**
    - **Validates: Requirements 7.2**
    - **Property 8: Fallback kendala saat semua nilai kosong**
    - **Validates: Requirements 7.3**

- [x] 9. Implementasi BAB IV — Penutup (Kesimpulan dan RTL)
  - [x] 9.1 Render subbab Kesimpulan Statistik untuk 6 unit kerja
    - Loop `LAPORAN_UNIT_KERJA`, ambil rows dari `laporanGetDataUnit()` (bisa di-cache dari BAB II)
    - Panggil `laporanHitungStatistik()` untuk mendapatkan turun/tetap/naik/tinggi/total_monev
    - Tampilkan ringkasan statistik per unit kerja (format teks naratif atau tabel ringkas)
    - Risiko tanpa data monev dikecualikan dari perhitungan
    - _Requirements: 8.1, 8.2, 8.3_

  - [x] 9.2 Render subbab Rencana Tindak Lanjut untuk 6 unit kerja
    - Loop `LAPORAN_UNIT_KERJA`, panggil `laporanGetAggregat()` dengan kolom `'rencana_tindak_lanjut'`
    - Tampilkan setiap nilai unik non-kosong
    - Jika array kosong, tampilkan teks fallback "Rencana Tindak Lanjut yang akan dilakukan adalah melanjutkan upaya pengendalian yang sudah direncanakan"
    - Semua nilai di-escape dengan `xss()`
    - _Requirements: 9.1, 9.2, 10.4_

  - [x] 9.3 Tulis property test untuk agregasi RTL (Property 10 dan 11)
    - **Property 10: Agregasi rencana tindak lanjut mencakup semua nilai non-kosong**
    - **Validates: Requirements 9.1**
    - **Property 11: Fallback rencana tindak lanjut saat semua nilai kosong**
    - **Validates: Requirements 9.2**

  - [x] 9.4 Tutup tag `</body>` dan `</html>` pada dokumen output
    - Pastikan dokumen HTML mandiri ditutup dengan benar
    - _Requirements: 3.2_

- [x] 10. Checkpoint — Pastikan semua test pass
  - Pastikan semua test pass, tanyakan kepada user jika ada pertanyaan.

- [x] 11. Checkpoint akhir — Verifikasi property access control (Property 1)
  - [x] 11.1 Tulis property test untuk akses kontrol (Property 1)
    - **Property 1: Akses ditolak untuk peran tidak berwenang**
    - **Validates: Requirements 1.6**

## Notes

- Tasks dengan tanda `*` adalah opsional dan dapat dilewati untuk MVP yang lebih cepat
- Setiap task mereferensikan requirement spesifik untuk keterlacakan
- Checkpoint memastikan validasi bertahap selama implementasi
- Property tests memvalidasi kebenaran universal dari sistem
- Unit tests memvalidasi contoh spesifik dan edge cases
- Implementasi menggunakan PHP murni, konsisten dengan pola yang ada di aplikasi

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.2"] },
    { "id": 1, "tasks": ["2.1"] },
    { "id": 2, "tasks": ["2.2", "2.3"] },
    { "id": 3, "tasks": ["2.4", "4.1"] },
    { "id": 4, "tasks": ["5.1"] },
    { "id": 5, "tasks": ["5.2", "6.1"] },
    { "id": 6, "tasks": ["5.3", "5.4", "7.1"] },
    { "id": 7, "tasks": ["7.2", "7.3"] },
    { "id": 8, "tasks": ["7.4", "7.5", "8.1"] },
    { "id": 9, "tasks": ["8.2", "9.1", "9.2"] },
    { "id": 10, "tasks": ["9.3", "9.4"] },
    { "id": 11, "tasks": ["11.1"] }
  ]
}
```
