# Design Document — Laporan Monitoring dan Evaluasi Manajemen Risiko

## Overview

Modul `laporan_monev` adalah generator dokumen HTML mandiri yang menghasilkan Laporan Monitoring dan Evaluasi (Monev) Manajemen Risiko. Modul beroperasi dalam dua mode dalam satu file `modules/laporan_monev.php`:

1. **Mode Form** — menampilkan antarmuka pengisian parameter dalam layout aplikasi utama.
2. **Mode Generate** — menghasilkan dokumen HTML mandiri (bypass layout) yang dapat langsung dicetak atau disimpan sebagai PDF.

Pola ini konsisten dengan pola yang sudah ada di `laporan_konsolidasi.php`, `kkpr_pdf.php`, dan modul ekspor lainnya dalam aplikasi.

---

## Architecture

### Alur Eksekusi di Router (`index.php`)

```
GET ?page=laporan_monev
        │
        ▼
   generate=1? ──yes──▶  include modules/laporan_monev.php → exit
        │
        no
        ▼
   include header.php
   include modules/laporan_monev.php  (mode Form)
   include footer.php
```

Bypass layout ditangani di `index.php` sebelum `header.php` di-include, dengan pola:

```php
// Di index.php — tepat sebelum blok $modules
if ($page === 'laporan_monev' && ($_GET['generate'] ?? '') === '1') {
    include __DIR__ . '/modules/laporan_monev.php';
    exit;
}
```

### Registrasi Modul

Di `index.php`:
- Array `$modules`: `'laporan_monev' => 'modules/laporan_monev.php'`
- Array `$postHandlers`: `'laporan_monev' => 'modules/laporan_monev.php'`

### Registrasi Sidebar (`includes/header.php`)

Grup "Laporan" yang sudah ada diperluas dengan satu entri baru. Variabel `$isLaporanGroupActive` diperbarui untuk menyertakan `'laporan_monev'`.

---

## Components

### 1. Modul Tunggal: `modules/laporan_monev.php`

File ini menangani kedua mode. Struktur utama:

```php
<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();
requireRole('Admin', 'Risk Manager', 'Pimpinan', 'Koordinator');

// ── Sanitasi & Validasi Input ─────────────────────────────
$triwulan = (int)($_REQUEST['triwulan'] ?? 1);
if ($triwulan < 1 || $triwulan > 4) $triwulan = 1;

$tahun = trim((string)($_REQUEST['tahun'] ?? date('Y')));
if (!preg_match('/^\d{4}$/', $tahun)) $tahun = date('Y');

$koordinator = trim((string)($_REQUEST['koordinator'] ?? ''));
$penulis     = trim((string)($_REQUEST['penulis'] ?? ''));
$namaKepala  = trim((string)($_REQUEST['nama_kepala'] ?? ''));
$nipKepala   = trim((string)($_REQUEST['nip_kepala'] ?? ''));
$tanggal     = trim((string)($_REQUEST['tanggal'] ?? ''));

$isGenerate = ($_GET['generate'] ?? '') === '1';

if ($isGenerate) {
    // Mode dokumen — output HTML mandiri
    // ... (lihat bagian Output HTML)
} else {
    // Mode form — menggunakan layout aplikasi (header sudah di-include oleh index.php)
    // ... (lihat bagian Form Generator)
}
```

### 2. Fungsi Helper Internal (di awal modul)

Fungsi-fungsi berikut didefinisikan di dalam modul (bukan di `functions.php`) karena sifatnya spesifik untuk laporan ini:

```php
/**
 * Warna latar belakang sel tingkat risiko (konsisten dengan kkpr.php).
 */
function laporanRisikoBg(string $tingkat): string {
    return match($tingkat) {
        'Sangat Tinggi' => '#fee2e2',
        'Tinggi'        => '#ffedd5',
        'Sedang'        => '#fefce8',
        'Rendah'        => '#dcfce7',
        default         => '#f3f4f6',
    };
}

function laporanRisikoColor(string $tingkat): string {
    return match($tingkat) {
        'Sangat Tinggi' => '#991b1b',
        'Tinggi'        => '#9a3412',
        'Sedang'        => '#854d0e',
        'Rendah'        => '#166534',
        default         => '#374151',
    };
}

/**
 * Ambil data risiko + monev untuk satu unit kerja.
 * Mengembalikan array rows dengan semua kolom yang dibutuhkan.
 */
function laporanGetDataUnit(
    mysqli $db,
    string $unitKerja,
    string $tahun,
    int $triwulan
): array { ... }

/**
 * Ambil nilai unik non-kosong dari kolom monev untuk unit kerja tertentu.
 */
function laporanGetAggregat(
    mysqli $db,
    string $unitKerja,
    string $tahun,
    int $triwulan,
    string $kolom
): array { ... }

/**
 * Hitung statistik tren risiko (turun/tetap/naik/tinggi) untuk satu unit.
 */
function laporanHitungStatistik(array $rows): array { ... }
```

---

## Data Models

### Unit Kerja (konstanta internal modul)

```php
const LAPORAN_UNIT_KERJA = [
    'Sub Bagian Administrasi Umum',
    'Tim Kerja Program Layanan',
    'Tim Kerja Mutu, Penguatan SDM dan Kemitraan',
    'Tim Kerja Surveilans Penyakit, Faktor Risiko, dan KLB',
    'Instalasi',
    'Gratifikasi',
];
```

### Query Utama per Unit Kerja

```sql
SELECT
    r.kode_risiko,
    r.nama_risiko,
    r.probabilitas        AS awal_p,
    r.dampak_level        AS awal_d,
    r.nilai_risiko        AS awal_nilai,
    r.tingkat_risiko      AS awal_tingkat,
    m.upaya_pengendalian,
    m.pantau_p            AS akhir_p,
    m.pantau_d            AS akhir_d,
    m.pantau_nilai        AS akhir_nilai,
    m.pantau_tingkat      AS akhir_tingkat,
    m.kendala,
    m.rencana_tindak_lanjut
FROM kkpr_risiko r
INNER JOIN kkpr_header h ON h.id = r.id_kkpr
LEFT JOIN monev_triwulan m ON m.id_risiko = r.id AND m.triwulan = ?
WHERE h.tahun = ?
  AND h.unit_pemilik_risiko LIKE ?
ORDER BY r.kode_risiko
```

Parameter bind: `(i, s, s)` → `($triwulan, $tahun, "%{$unitKerja}%")`

> Penggunaan `LEFT JOIN` memastikan risiko tanpa data monev tetap tampil (kolom akhir menampilkan "-").

### Struktur Row Data

```php
// Setiap row yang dikembalikan laporanGetDataUnit():
[
    'kode_risiko'          => string,
    'nama_risiko'          => string,
    'awal_p'               => int|null,
    'awal_d'               => int|null,
    'awal_nilai'           => int|null,
    'awal_tingkat'         => string|null,
    'upaya_pengendalian'   => string|null,
    'akhir_p'              => int|null,    // NULL jika tidak ada monev
    'akhir_d'              => int|null,
    'akhir_nilai'          => int|null,
    'akhir_tingkat'        => string|null,
    'kendala'              => string|null,
    'rencana_tindak_lanjut' => string|null,
]
```

### Struktur Statistik

```php
// Dikembalikan oleh laporanHitungStatistik():
[
    'turun'  => int,   // akhir_nilai < awal_nilai (dan akhir_nilai != null)
    'tetap'  => int,   // akhir_nilai == awal_nilai (dan akhir_nilai != null)
    'naik'   => int,   // akhir_nilai > awal_nilai (dan akhir_nilai != null)
    'tinggi' => int,   // akhir_tingkat IN ['Tinggi','Sangat Tinggi'] (dan akhir_nilai != null)
    'total_monev' => int,  // rows dengan data monev (akhir_nilai != null)
]
```

---

## Interfaces

### Form Generator (Mode Normal)

**URL:** `?page=laporan_monev`  
**Method:** GET (form submission via GET ke `?page=laporan_monev&generate=1&...`)

Field form:

| Field            | Tipe     | Name HTML         | Wajib | Validasi                      |
|------------------|----------|-------------------|-------|-------------------------------|
| Triwulan         | select   | `triwulan`        | Ya    | value in {1,2,3,4}            |
| Tahun            | select   | `tahun`           | Ya    | dari `getDaftarTahun()`       |
| Koordinator      | text     | `koordinator`     | Tidak | max 100 char                  |
| Nama Penulis     | text     | `penulis`         | Tidak | max 100 char                  |
| Nama Kepala BBLKL| text     | `nama_kepala`     | Tidak | max 100 char                  |
| NIP Kepala       | text     | `nip_kepala`      | Tidak | max 20 char                   |
| Tanggal Pengesahan| text    | `tanggal`         | Tidak | format bebas, max 50 char     |

Form mengirimkan via GET sehingga parameter tersedia di `$_GET` saat generate. Ini konsisten dengan pola yang ada di `laporan_konsolidasi.php`.

### Output HTML Mandiri (Mode Generate)

**URL:** `?page=laporan_monev&generate=1&triwulan=X&tahun=YYYY&...`

Struktur dokumen HTML yang dihasilkan:

```
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Laporan Monev Manajemen Risiko TW {n} Tahun {yyyy}</title>
  <style>
    /* Base styles — font, margin, ukuran kertas */
    /* @media print — sembunyikan no-print, set margin A4 portrait */
  </style>
</head>
<body>

  [TOMBOL CETAK — class="no-print"]

  <!-- Halaman 1: Cover -->
  <div class="cover page-break">
    Logo + Judul + Nama Instansi + Periode
  </div>

  <!-- Halaman 2: Lembar Pengesahan -->
  <div class="pengesahan page-break">
    Tabel kolom tanda tangan
  </div>

  <!-- BAB I: Pendahuluan (konten statis) -->
  <div class="bab" id="bab1">...</div>

  <!-- BAB II: Pelaksanaan Pengendalian Risiko (6 tabel dinamis) -->
  <div class="bab" id="bab2">
    [loop 6 unit kerja → tabel data]
  </div>

  <!-- BAB III: Hambatan (6 subbab dinamis) -->
  <div class="bab" id="bab3">
    [loop 6 unit kerja → teks kendala]
  </div>

  <!-- BAB IV: Penutup (kesimpulan statistik + RTL) -->
  <div class="bab" id="bab4">
    [loop 6 unit kerja → statistik + RTL]
  </div>

</body>
</html>
```

---

## Konten Statis BAB I

### 1.A Latar Belakang

Paragraf tentang kewajiban manajemen risiko di lingkungan Kementerian Kesehatan berdasarkan Peraturan Pemerintah dan Peraturan Menteri, pentingnya monitoring dan evaluasi berkala, serta ruang lingkup laporan (Balai Besar Laboratorium Kesehatan Lingkungan).

### 1.B Dasar Hukum (9 butir)

1. Peraturan Pemerintah Nomor 60 Tahun 2008 tentang Sistem Pengendalian Intern Pemerintah (SPIP).
2. Peraturan Kepala BPKP Nomor 4 Tahun 2016 tentang Pedoman Penilaian dan Strategi Peningkatan Maturitas Sistem Pengendalian Intern Pemerintah.
3. Peraturan Menteri Kesehatan Nomor 25 Tahun 2019 tentang Penerapan Manajemen Risiko Terintegrasi di Lingkungan Kementerian Kesehatan.
4. Peraturan Menteri Pendayagunaan Aparatur Negara dan Reformasi Birokrasi Nomor 5 Tahun 2020 tentang Road Map Reformasi Birokrasi 2020–2024.
5. Keputusan Menteri Kesehatan Nomor HK.01.07/MENKES/1160/2022 tentang Pedoman Manajemen Risiko Kementerian Kesehatan.
6. ISO 31000:2018 — Risk Management Guidelines.
7. Standar Nasional Indonesia SNI ISO 31000:2018 tentang Manajemen Risiko — Panduan.
8. Peraturan Pemerintah Nomor 12 Tahun 2019 tentang Pengelolaan Keuangan Daerah (relevan sebagai referensi pengendalian).
9. Rencana Strategis Balai Besar Laboratorium Kesehatan Lingkungan tahun berjalan.

### 1.C Tujuan (5 butir)

1. Memantau pelaksanaan pengendalian risiko yang telah direncanakan pada setiap unit kerja.
2. Mengevaluasi efektivitas upaya pengendalian risiko berdasarkan perubahan nilai dan tingkat risiko.
3. Mengidentifikasi hambatan dan kendala dalam pelaksanaan pengendalian risiko.
4. Merumuskan rencana tindak lanjut untuk meningkatkan efektivitas pengendalian risiko pada periode berikutnya.
5. Menyediakan bahan pengambilan keputusan bagi pimpinan terkait pengelolaan risiko organisasi.

---

## BAB II — Struktur Tabel Per Unit Kerja

Setiap tabel memiliki kolom berikut (urutan kiri ke kanan):

| No | Kode Risiko | Pernyataan Risiko | Kondisi Awal TW I (P / D / Nilai / Tingkat) | Upaya Pengendalian | Kondisi Akhir TW [n] (P / D / Nilai / Tingkat) |
|----|-------------|-------------------|---------------------------------------------|--------------------|------------------------------------------------|

Kolom "Kondisi Awal TW I" dan "Kondisi Akhir TW [n]" menggunakan header kolom merged (colspan=4) untuk mengelompokkan sub-kolom P, D, Nilai, Tingkat.

Sel "Tingkat" (awal dan akhir) mendapatkan warna latar belakang dari `laporanRisikoBg()` dan warna teks dari `laporanRisikoColor()`.

---

## Error Handling

### Input Tidak Valid

| Kondisi | Penanganan |
|---------|------------|
| `triwulan` di luar 1–4 | Default ke `1` |
| `tahun` bukan 4 digit numerik | Default ke `date('Y')` |
| Unit kerja tanpa data risiko | Tampilkan baris tunggal dengan pesan fallback |
| `upaya_pengendalian` NULL/kosong | Tampilkan "-" |
| Baris `monev_triwulan` tidak ada (LEFT JOIN = NULL) | Semua kolom akhir tampilkan "-" |
| Semua `kendala` unit kosong/NULL | Tampilkan teks: "Tidak ditemukan kendala dalam pelaksanaan kegiatan" |
| Semua `rencana_tindak_lanjut` unit kosong/NULL | Tampilkan teks: "Rencana Tindak Lanjut yang akan dilakukan adalah melanjutkan upaya pengendalian yang sudah direncanakan" |

### Akses Tidak Berwenang

`requireRole()` ditempatkan di paling awal modul, sebelum operasi DB apapun. Jika role tidak sesuai, eksekusi berhenti dengan redirect ke index (perilaku bawaan `requireRole()`).

### Exception Database

Query menggunakan MySQLi prepared statements. Exception dikembalikan ke handler fatal bawaan aplikasi (`manrisHandleFatal()`).

---

## Security

### Input Sanitization

```php
// Triwulan — integer dengan batas
$triwulan = (int)($_REQUEST['triwulan'] ?? 1);
if ($triwulan < 1 || $triwulan > 4) $triwulan = 1;

// Tahun — harus persis 4 digit angka
$tahun = trim((string)($_REQUEST['tahun'] ?? date('Y')));
if (!preg_match('/^\d{4}$/', $tahun)) $tahun = date('Y');

// String bebas — hanya digunakan untuk output, dibersihkan via xss()
$koordinator = trim((string)($_REQUEST['koordinator'] ?? ''));
```

### Output Escaping

Semua variabel yang berasal dari input pengguna dan dari query DB di-escape menggunakan `xss()` sebelum dimasukkan ke dalam HTML output:

```php
echo xss($row['nama_risiko']);
echo xss($koordinator);
echo xss($row['akhir_tingkat'] ?? '-');
```

### Prepared Statements

Semua query menggunakan `bind_param` — tidak ada string concatenation langsung ke SQL:

```php
$stmt = $db->prepare($sql);
$stmt->bind_param('iss', $triwulan, $tahun, $unitLike);
$stmt->execute();
```

---

## CSS & Print Styling

```css
@page {
    size: A4 portrait;
    margin: 2.5cm 2cm 2cm 2.5cm;
}

body {
    font-family: 'Times New Roman', serif;
    font-size: 12pt;
    color: #000;
    line-height: 1.5;
}

.page-break {
    page-break-after: always;
}

.no-print {
    /* tombol cetak, navigasi */
}

@media print {
    .no-print { display: none !important; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #000; padding: 4px 6px; font-size: 10pt; }
}
```

---

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system — essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: Akses ditolak untuk peran tidak berwenang

*Untuk setiap* request ke `?page=laporan_monev` oleh pengguna dengan peran di luar {Admin, Risk Manager, Pimpinan, Koordinator}, sistem SHALL menghentikan eksekusi modul dan tidak menghasilkan konten laporan.

**Validates: Requirements 1.6**

---

### Property 2: Cover dan lembar pengesahan mencerminkan input form

*Untuk setiap* kombinasi valid (triwulan ∈ {1,2,3,4}, tahun berformat 4 digit, koordinator, penulis, namaKepala, nipKepala, tanggal), dokumen HTML yang dihasilkan SHALL mengandung semua nilai tersebut (setelah di-escape dengan `htmlspecialchars`) pada elemen cover dan lembar pengesahan yang sesuai.

**Validates: Requirements 4.1, 4.2, 4.3**

---

### Property 3: Data risiko awal dipetakan ke kolom Kondisi Awal TW I

*Untuk setiap* baris `kkpr_risiko` yang dikembalikan query untuk suatu unit kerja dan tahun, nilai `kode_risiko`, `nama_risiko`, `probabilitas`, `dampak_level`, `nilai_risiko`, dan `tingkat_risiko` SHALL muncul di kolom yang tepat pada tabel BAB II unit kerja yang bersangkutan.

**Validates: Requirements 6.3, 6.6**

---

### Property 4: Nilai NULL/kosong di-render sebagai "-"

*Untuk setiap* baris di mana `upaya_pengendalian` adalah NULL atau string kosong, sel kolom "Upaya Pengendalian" dalam output HTML SHALL berisi tepat tanda "-". Demikian pula, *untuk setiap* baris di mana tidak ada `monev_triwulan` yang cocok (hasil LEFT JOIN NULL), semua kolom Kondisi Akhir SHALL menampilkan "-".

**Validates: Requirements 6.4, 6.5**

---

### Property 5: Unit kerja tanpa risiko menampilkan pesan fallback

*Untuk setiap* unit kerja yang tidak memiliki baris `kkpr_risiko` yang cocok pada tahun yang dipilih, tabel BAB II unit tersebut SHALL mengandung teks "Tidak terdapat data risiko untuk unit kerja ini".

**Validates: Requirements 6.7**

---

### Property 6: Warna sel tingkat risiko konsisten dengan lookup

*Untuk setiap* nilai `tingkat_risiko` (atau `akhir_tingkat`) ∈ {Sangat Tinggi, Tinggi, Sedang, Rendah}, warna latar belakang CSS yang diterapkan pada sel tersebut SHALL sesuai dengan pemetaan: Sangat Tinggi → #fee2e2, Tinggi → #ffedd5, Sedang → #fefce8, Rendah → #dcfce7.

**Validates: Requirements 6.8**

---

### Property 7: Agregasi kendala mencakup semua nilai non-kosong unik

*Untuk setiap* unit kerja dengan satu atau lebih baris `monev_triwulan.kendala` yang tidak NULL dan tidak kosong untuk triwulan yang dipilih, semua nilai unik tersebut SHALL muncul dalam output BAB III unit yang bersangkutan.

**Validates: Requirements 7.2**

---

### Property 8: Fallback kendala saat semua nilai kosong

*Untuk setiap* unit kerja di mana seluruh nilai `kendala` pada triwulan yang dipilih adalah NULL atau string kosong, output BAB III SHALL mengandung teks "Tidak ditemukan kendala dalam pelaksanaan kegiatan".

**Validates: Requirements 7.3**

---

### Property 9: Statistik tren risiko akurat dan filter risiko tanpa monev

*Untuk setiap* koleksi rows suatu unit kerja, jumlah `turun + tetap + naik` SHALL sama persis dengan jumlah rows yang memiliki `akhir_nilai` tidak NULL. Risiko dengan `akhir_nilai` NULL SHALL tidak berkontribusi ke counter manapun.

**Validates: Requirements 8.1, 8.3**

---

### Property 10: Agregasi rencana tindak lanjut mencakup semua nilai non-kosong

*Untuk setiap* unit kerja dengan satu atau lebih `rencana_tindak_lanjut` tidak NULL dan tidak kosong, semua nilai unik tersebut SHALL muncul dalam output BAB IV unit yang bersangkutan.

**Validates: Requirements 9.1**

---

### Property 11: Fallback rencana tindak lanjut saat semua nilai kosong

*Untuk setiap* unit kerja di mana seluruh nilai `rencana_tindak_lanjut` adalah NULL atau kosong, output BAB IV SHALL mengandung teks "Rencana Tindak Lanjut yang akan dilakukan adalah melanjutkan upaya pengendalian yang sudah direncanakan".

**Validates: Requirements 9.2**

---

### Property 12: Sanitasi triwulan di luar rentang

*Untuk setiap* nilai input `triwulan` yang tidak termasuk dalam himpunan {1, 2, 3, 4} (termasuk string, angka negatif, atau nol), nilai yang digunakan dalam semua query database SHALL sama dengan `1`.

**Validates: Requirements 10.5**

---

### Property 13: Sanitasi tahun non-format 4-digit

*Untuk setiap* nilai input `tahun` yang tidak sesuai format 4 digit numerik (tidak cocok dengan `/^\d{4}$/`), nilai yang digunakan dalam semua query database SHALL sama dengan `date('Y')` (tahun berjalan saat eksekusi).

**Validates: Requirements 10.6**

---

### Property 14: Output HTML bebas dari karakter HTML mentah pada nilai user-input

*Untuk setiap* string input dari pengguna atau dari database yang mengandung karakter `<`, `>`, `"`, `'`, atau `&`, representasi karakter-karakter tersebut dalam output HTML SHALL berupa entity HTML (mis. `&lt;`, `&gt;`, `&amp;`) dan bukan karakter mentah.

**Validates: Requirements 10.3, 10.4**
