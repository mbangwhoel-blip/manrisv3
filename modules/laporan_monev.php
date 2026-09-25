<?php
/**
 * MODUL LAPORAN MONITORING DAN EVALUASI MANAJEMEN RISIKO
 *
 * Beroperasi dalam dua mode:
 *  - Mode Form    : tampilkan form input parameter (layout aplikasi utama)
 *  - Mode Generate: hasilkan dokumen HTML mandiri siap cetak/PDF (bypass layout)
 *
 * Akses: Admin, Risk Manager, Pimpinan, Koordinator
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// ── Auth — wajib di paling awal sebelum operasi DB apapun ─────
requireLogin();
requireRole('Admin', 'Risk Manager', 'Pimpinan', 'Koordinator');

// ── Sanitasi & Validasi Input ─────────────────────────────────

// Triwulan: integer 1–4, default 1 jika di luar rentang
$triwulan = (int)($_REQUEST['triwulan'] ?? 1);
if ($triwulan < 1 || $triwulan > 4) {
    $triwulan = 1;
}

// Tahun: harus persis 4 digit angka, default tahun berjalan
$tahun = trim((string)($_REQUEST['tahun'] ?? date('Y')));
if (!preg_match('/^\d{4}$/', $tahun)) {
    $tahun = date('Y');
}

// Field string bebas — hanya digunakan untuk output, dibersihkan via xss() saat render
$koordinator = trim((string)($_REQUEST['koordinator'] ?? ''));
$penulis     = trim((string)($_REQUEST['penulis']     ?? ''));
$namaKepala  = trim((string)($_REQUEST['nama_kepala'] ?? ''));
$nipKepala   = trim((string)($_REQUEST['nip_kepala']  ?? ''));
$tanggal     = trim((string)($_REQUEST['tanggal']     ?? ''));

// Mode generate: output HTML mandiri, bypass layout
$isGenerate = ($_GET['generate'] ?? '') === '1';

// Format output: 'html' (preview cetak), 'doc' (HTML+MIME Word), atau 'docx' (Word asli, editable di Word Online)
$rawFormat = $_GET['format'] ?? 'html';
$format    = in_array($rawFormat, ['doc', 'docx'], true) ? $rawFormat : 'html';
$isWordDoc = $isGenerate && $format === 'doc';

// Mode Edit Online: dokumen HTML dapat diedit langsung di browser,
// lalu dapat diunduh sebagai .docx dari konten hasil edit.
$isEdit = $isGenerate && $format === 'html' && ($_GET['edit'] ?? '') === '1';

// ── Daftar Unit Kerja ─────────────────────────────────────────
$unitKerjaList = [
    'Sub Bagian Administrasi Umum',
    'Tim Kerja Program Layanan',
    'Tim Kerja Mutu, Penguatan SDM dan Kemitraan',
    'Tim Kerja Surveilans Penyakit, Faktor Risiko, dan KLB',
    'Instalasi',
    'Gratifikasi',
];

// ── Helper Functions ──────────────────────────────────────────

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

/**
 * Warna teks sel tingkat risiko.
 */
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
 * Menggunakan LEFT JOIN sehingga risiko tanpa data monev tetap tampil.
 *
 * @return array[] Rows dengan semua kolom yang dibutuhkan
 */
function laporanGetDataUnit(mysqli $db, string $unitKerja, string $tahun, int $triwulan): array {
    $sql = "
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
        ORDER BY SUBSTRING_INDEX(r.kode_risiko, '.', 1) ASC, CAST(SUBSTRING_INDEX(r.kode_risiko, '.', -1) AS UNSIGNED) ASC, r.no_urut ASC
    ";

    $unitLike = '%' . $unitKerja . '%';
    $stmt = $db->prepare($sql);
    $stmt->bind_param('iss', $triwulan, $tahun, $unitLike);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

/**
 * Ambil nilai unik non-kosong dari kolom monev untuk satu unit kerja.
 * Kolom yang didukung: 'kendala', 'rencana_tindak_lanjut'.
 *
 * @return string[] Array nilai unik non-kosong
 */
function laporanGetAggregat(mysqli $db, string $unitKerja, string $tahun, int $triwulan, string $kolom): array {
    // Whitelist kolom untuk mencegah SQL injection
    $kolomDiizinkan = ['kendala', 'rencana_tindak_lanjut'];
    if (!in_array($kolom, $kolomDiizinkan, true)) {
        return [];
    }

    $sql = "
        SELECT DISTINCT m.{$kolom}
        FROM monev_triwulan m
        INNER JOIN kkpr_risiko r ON r.id = m.id_risiko
        INNER JOIN kkpr_header h ON h.id = r.id_kkpr
        WHERE h.tahun = ?
          AND h.unit_pemilik_risiko LIKE ?
          AND m.triwulan = ?
          AND m.{$kolom} IS NOT NULL
          AND m.{$kolom} <> ''
        ORDER BY m.{$kolom}
    ";

    $unitLike = '%' . $unitKerja . '%';
    $stmt = $db->prepare($sql);
    $stmt->bind_param('ssi', $tahun, $unitLike, $triwulan);
    $stmt->execute();
    $result = $stmt->get_result();
    $values = [];
    while ($row = $result->fetch_row()) {
        $values[] = (string)$row[0];
    }
    $stmt->close();
    return $values;
}

/**
 * Hitung statistik tren risiko dari rows satu unit kerja.
 * Hanya rows dengan akhir_nilai tidak NULL yang dihitung (memiliki data monev).
 *
 * @param array[] $rows
 * @return array{turun:int,tetap:int,naik:int,tinggi:int,total_monev:int}
 */
function laporanHitungStatistik(array $rows): array {
    $turun      = 0;
    $tetap      = 0;
    $naik       = 0;
    $tinggi     = 0;
    $totalMonev = 0;

    foreach ($rows as $row) {
        // Risiko tanpa data monev (LEFT JOIN menghasilkan NULL) dikecualikan
        if ($row['akhir_nilai'] === null) {
            continue;
        }

        $totalMonev++;
        $awalNilai  = (int)$row['awal_nilai'];
        $akhirNilai = (int)$row['akhir_nilai'];

        if ($akhirNilai < $awalNilai) {
            $turun++;
        } elseif ($akhirNilai === $awalNilai) {
            $tetap++;
        } else {
            $naik++;
        }

        $akhirTingkat = (string)($row['akhir_tingkat'] ?? '');
        if (in_array($akhirTingkat, ['Tinggi', 'Sangat Tinggi'], true)) {
            $tinggi++;
        }
    }

    return [
        'turun'       => $turun,
        'tetap'       => $tetap,
        'naik'        => $naik,
        'tinggi'      => $tinggi,
        'total_monev' => $totalMonev,
    ];
}

/** Nama bulan dalam bahasa Indonesia (indeks 1-12). */
function laporanBulanId(): array {
    return [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
}

/**
 * Format tanggal ISO (YYYY-MM-DD) menjadi format Indonesia, mis. "15 Juli 2026".
 * Nilai non-ISO dikembalikan apa adanya agar input lama tetap tampil.
 */
function laporanFormatTanggal(string $tgl): string {
    $tgl = trim($tgl);
    if ($tgl === '') {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $tgl);
    if (!$dt || $dt->format('Y-m-d') !== $tgl) {
        return $tgl;
    }
    return (int)$dt->format('j') . ' ' . laporanBulanId()[(int)$dt->format('n')] . ' ' . $dt->format('Y');
}

/**
 * Ubah berbagai format tanggal (ISO atau "15 Juli 2026", boleh berawalan
 * "Salatiga,") menjadi ISO (YYYY-MM-DD) untuk value <input type="date">.
 * Mengembalikan string kosong bila tidak dapat dikenali.
 */
function laporanTanggalKeIso(string $tgl): string {
    $tgl = trim($tgl);
    if ($tgl === '') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) {
        return $tgl;
    }
    $tgl     = preg_replace('/^\s*Salatiga\s*,?\s*/i', '', $tgl);
    $bulanId = array_flip(array_map('strtolower', laporanBulanId()));
    if (preg_match('/^(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})$/u', trim($tgl), $m)) {
        $key = $bulanId[strtolower($m[2])] ?? null;
        if ($key !== null) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$key, (int)$m[1]);
        }
    }
    return '';
}

// ── POST: Unduh .docx dari konten hasil Edit Online ─────────────
// Konten HTML (sudah diedit di browser) dikonversi server-side ke Word.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'download_docx') {
    requireLogin();
    requireRole('Admin', 'Risk Manager', 'Pimpinan', 'Koordinator');

    header('Content-Type: application/json; charset=UTF-8');
    $jsonOut = function (int $code, string $msg) {
        http_response_code($code);
        echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    };

    if (!verifyCsrf()) {
        $jsonOut(403, 'Token keamanan CSRF tidak valid.');
    }

    $editedHtml = (string)($_POST['html'] ?? '');
    if (strlen($editedHtml) < 50) {
        $jsonOut(400, 'Konten dokumen kosong atau tidak valid.');
    }
    if (strlen($editedHtml) > 5 * 1024 * 1024) {
        $jsonOut(413, 'Konten dokumen terlalu besar (maksimal 5MB).');
    }

    require_once __DIR__ . '/laporan_monev_html_to_docx.php';

    try {
        $phpWord = laporanMonevHtmlToDocx($editedHtml);
    } catch (Throwable $e) {
        error_log('[manris] Edit-online docx convert failed: ' . $e->getMessage());
        $jsonOut(500, 'Gagal mengonversi dokumen: ' . $e->getMessage());
    }

    // Guard output binary dari deprecation notice PhpWord
    $prevDisplay = ini_set('display_errors', '0');
    $prevErrLvl  = error_reporting(E_ALL & ~E_DEPRECATED);
    ob_start();
    \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')->save('php://output');
    $binary = ob_get_clean();
    if ($prevDisplay !== false) {
        ini_set('display_errors', $prevDisplay);
    }
    error_reporting($prevErrLvl);

    $label = ['I', 'II', 'III', 'IV'][$triwulan - 1] ?? 'I';
    $namaFile = 'Laporan_Monev_TW' . $label . '_' . $tahun . '_edited.docx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $namaFile . '"');
    header('Content-Length: ' . strlen($binary));
    header('Cache-Control: max-age=0');
    header('Pragma: public');
    echo $binary;
    exit;
}

// ── Mode Word .docx — dokumen Word asli (OOXML), editable di Word Online ────
if ($isGenerate && $format === 'docx') {
    require_once __DIR__ . '/../vendor/autoload.php';

    $triwulanLabel = ['I', 'II', 'III', 'IV'][$triwulan - 1];

    // Warna sel tingkat risiko (tanpa "#", format hex Word)
    $bgMap = [
        'Sangat Tinggi' => 'fee2e2',
        'Tinggi'        => 'ffedd5',
        'Sedang'        => 'fefce8',
        'Rendah'        => 'dcfce7',
    ];
    $fgMap = [
        'Sangat Tinggi' => '991b1b',
        'Tinggi'        => '9a3412',
        'Sedang'        => '854d0e',
        'Rendah'        => '166534',
    ];

    $phpWord = new \PhpOffice\PhpWord\PhpWord();
    $phpWord->setDefaultFontName('Arial');
    $phpWord->setDefaultFontSize(11);

    // Guard: PhpWord 1.4 memicu deprecation notice di PHP 8.5 yang akan
    // mengotori stream binary docx jika tercetak. Matikan display + buffer.
    $prevDisplay = ini_set('display_errors', '0');
    $prevErrLvl  = error_reporting(E_ALL & ~E_DEPRECATED);
    ob_start();

    // Gaya paragraf yang dipakai berulang
    // Font: isi Arial 11, judul/heading Arial 12 bold
    $pCenter   = ['alignment' => 'center'];
    $pJustify  = ['alignment' => 'both', 'spaceAfter' => 120];
    $pHeading  = ['alignment' => 'center', 'spaceBefore' => 240, 'spaceAfter' => 240];
    $fBold     = ['bold' => true];
    $fTitle    = ['bold' => true, 'size' => 12];   // judul BAB, cover, pengesahan
    $fUnderline = ['bold' => true, 'underline' => 'single'];
    $fTable    = ['size' => 9];
    $fTableB   = ['size' => 9, 'bold' => true];
    $fSmallNip = ['size' => 10];

    $section = $phpWord->addSection([
        'pageSize'      => ['width' => 11906, 'height' => 16838], // A4 portrait (twips)
        'margin_top'    => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2.5),
        'margin_right'  => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2),
        'margin_bottom' => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2),
        'margin_left'   => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2.5),
    ]);

    // ══ HALAMAN 1: COVER ══════════════════════════════════════
    $logoPath = __DIR__ . '/../assets/img/logo.png';
    if (is_file($logoPath)) {
        $section->addImage($logoPath, ['width' => 90, 'height' => 90, 'alignment' => 'center']);
    }
    $section->addTextBreak(4);
    $section->addText('LAPORAN MONITORING DAN EVALUASI MANAJEMEN RISIKO', $fTitle, $pCenter);
    $section->addText('BALAI BESAR LABORATORIUM KESEHATAN LINGKUNGAN', $fTitle, $pCenter);
    $section->addTextBreak(2);
    $section->addText('TRIWULAN ' . $triwulanLabel . ' TAHUN ' . $tahun, null, $pCenter);
    $section->addPageBreak();

    // ══ HALAMAN 2: LEMBAR PENGESAHAN ══════════════════════════
    $section->addText('LEMBAR PENGESAHAN', $fTitle, $pHeading);
    $section->addTextBreak(1);

    $section->addText('LAPORAN MONITORING DAN EVALUASI', null, $pCenter);
    $section->addText('MANAJEMEN RISIKO', null, $pCenter);
    $section->addText('BALAI BESAR LABORATORIUM KESEHATAN LINGKUNGAN', null, $pCenter);
    $section->addText('TRIWULAN ' . $triwulanLabel . ' TAHUN ' . $tahun, null, $pCenter);

    $section->addTextBreak(3);

    // Tabel Petugas (borderless)
    $tabelPetugas = $section->addTable(['borderSize' => 0, 'borderColor' => 'FFFFFF', 'cellMargin' => 40]);
    $tabelPetugas->addRow();
    $tabelPetugas->addCell(3000)->addText('Koordinator dan Verifikator', null, ['spaceAfter' => 60]);
    $tabelPetugas->addCell(400)->addText(':', null, ['spaceAfter' => 60]);
    $tabelPetugas->addCell(5500)->addText($koordinator !== '' ? $koordinator : '_________________________', null, ['spaceAfter' => 60]);

    $tabelPetugas->addRow();
    $tabelPetugas->addCell(3000)->addText('Penulis', null, ['spaceAfter' => 60]);
    $tabelPetugas->addCell(400)->addText(':', null, ['spaceAfter' => 60]);
    $tabelPetugas->addCell(5500)->addText($penulis !== '' ? $penulis : '_________________________', null, ['spaceAfter' => 60]);

    $section->addTextBreak(3);

    $tglWord = '';
    if ($tanggal !== '') {
        $tglFmt  = laporanFormatTanggal($tanggal);
        $tglWord = (stripos($tglFmt, 'Salatiga') === 0) ? $tglFmt : ('Salatiga, ' . $tglFmt);
    } else {
        $tglWord = 'Salatiga, _________________________';
    }

    $section->addText($tglWord, null, $pCenter);
    $section->addText('Disahkan oleh', null, $pCenter);
    $section->addText('Kepala Balai Besar Laboratorium Kesehatan Lingkungan', null, $pCenter);

    $section->addTextBreak(4);

    $section->addText($namaKepala !== '' ? $namaKepala : '_________________________', null, $pCenter);
    $section->addText($nipKepala !== '' ? 'NIP. ' . $nipKepala : 'NIP. _________________________', $fSmallNip, $pCenter);

    $section->addPageBreak();

    // ══ BAB I: PENDAHULUAN ════════════════════════════════════
    $section->addText('BAB I', $fTitle, $pHeading);
    $section->addText('PENDAHULUAN', $fTitle, $pHeading);

    $section->addText('1.A. Latar Belakang', $fBold, ['spaceBefore' => 160, 'spaceAfter' => 80]);
    $section->addText('Manajemen risiko merupakan bagian integral dari Sistem Pengendalian Intern Pemerintah (SPIP) yang wajib diterapkan di seluruh lingkungan pemerintah, termasuk Kementerian Kesehatan. Penerapan manajemen risiko diarahkan untuk mengidentifikasi, menganalisis, mengevaluasi, dan menangani risiko yang dapat mengganggu pencapaian tujuan organisasi. Agar penerapan manajemen risiko berjalan efektif, diperlukan kegiatan monitoring dan evaluasi (monev) yang dilaksanakan secara berkala untuk memastikan bahwa upaya pengendalian risiko yang telah direncanakan benar-benar dijalankan dan memberikan hasil yang diharapkan.', null, $pJustify);
    $section->addText('Laporan ini menyajikan hasil monitoring dan evaluasi pelaksanaan pengendalian risiko pada Balai Besar Laboratorium Kesehatan Lingkungan untuk periode Triwulan ' . $triwulanLabel . ' Tahun ' . $tahun . '. Laporan mencakup kondisi risiko awal, upaya pengendalian yang dilaksanakan, kondisi risiko akhir periode, hambatan dan kendala yang dihadapi, serta rencana tindak lanjut dari seluruh unit kerja di lingkungan Balai Besar Laboratorium Kesehatan Lingkungan.', null, $pJustify);

    $section->addText('1.B. Dasar Hukum', $fBold, ['spaceBefore' => 160, 'spaceAfter' => 80]);
    $dasarHukum = [
        'Peraturan Pemerintah Nomor 60 Tahun 2008 tentang Sistem Pengendalian Intern Pemerintah (SPIP).',
        'Peraturan Kepala BPKP Nomor 4 Tahun 2016 tentang Pedoman Penilaian dan Strategi Peningkatan Maturitas Sistem Pengendalian Intern Pemerintah.',
        'Peraturan Menteri Kesehatan Nomor 25 Tahun 2019 tentang Penerapan Manajemen Risiko Terintegrasi di Lingkungan Kementerian Kesehatan.',
        'Peraturan Menteri Pendayagunaan Aparatur Negara dan Reformasi Birokrasi Nomor 5 Tahun 2020 tentang Road Map Reformasi Birokrasi 2020-2024.',
        'Keputusan Menteri Kesehatan Nomor HK.01.07/MENKES/1160/2022 tentang Pedoman Manajemen Risiko Kementerian Kesehatan.',
        'ISO 31000:2018 - Risk Management Guidelines.',
        'Standar Nasional Indonesia SNI ISO 31000:2018 tentang Manajemen Risiko - Panduan.',
        'Peraturan Pemerintah Nomor 12 Tahun 2019 tentang Pengelolaan Keuangan Daerah.',
        'Rencana Strategis Balai Besar Laboratorium Kesehatan Lingkungan tahun ' . $tahun . '.',
    ];
    foreach ($dasarHukum as $i => $butir) {
        $section->addText(($i + 1) . '. ' . $butir, null, ['alignment' => 'both', 'indent' => 360, 'hanging' => 360, 'spaceAfter' => 40]);
    }

    $section->addText('1.C. Tujuan', $fBold, ['spaceBefore' => 160, 'spaceAfter' => 80]);
    $tujuan = [
        'Memantau pelaksanaan pengendalian risiko yang telah direncanakan pada setiap unit kerja.',
        'Mengevaluasi efektivitas upaya pengendalian risiko berdasarkan perubahan nilai dan tingkat risiko.',
        'Mengidentifikasi hambatan dan kendala dalam pelaksanaan pengendalian risiko.',
        'Merumuskan rencana tindak lanjut untuk meningkatkan efektivitas pengendalian risiko pada periode berikutnya.',
        'Menyediakan bahan pengambilan keputusan bagi pimpinan terkait pengelolaan risiko organisasi.',
    ];
    foreach ($tujuan as $i => $butir) {
        $section->addText(($i + 1) . '. ' . $butir, null, ['alignment' => 'both', 'indent' => 360, 'hanging' => 360, 'spaceAfter' => 40]);
    }
    $section->addPageBreak();

    // ══ BAB II: PELAKSANAAN PENGENDALIAN RISIKO ═══════════════
    $db = getDB();
    $allUnitData = [];

    $section->addText('BAB II', $fTitle, $pHeading);
    $section->addText('PELAKSANAAN PENGENDALIAN RISIKO', $fTitle, $pHeading);

    // Lebar kolom (twips): No, Kode, Pernyataan, P, D, Nilai, Tingkat, Upaya, P, D, Nilai, Tingkat
    $wNo = 350; $wKode = 850; $wNama = 2000; $wP = 280; $wD = 280; $wNilai = 400; $wTingkat = 950; $wUpaya = 1700;

    foreach ($unitKerjaList as $unitIdx => $unitKerja) {
        $rows = laporanGetDataUnit($db, $unitKerja, $tahun, $triwulan);
        $allUnitData[$unitIdx] = $rows;
        $unitNo = $unitIdx + 1;

        $section->addText('2.' . $unitNo . '. ' . $unitKerja, $fBold, ['spaceBefore' => 200, 'spaceAfter' => 80]);

        $table = $section->addTable(['borderSize' => 4, 'borderColor' => '000000', 'width' => 100, 'unit' => 'pct', 'cellMargin' => 40]);

        // Header baris 1
        $table->addRow();
        $table->addCell($wNo,    ['vMerge' => 'restart', 'bgColor' => 'D9E1F2'])->addText('No', $fTableB, $pCenter);
        $table->addCell($wKode,  ['vMerge' => 'restart', 'bgColor' => 'D9E1F2'])->addText('Kode Risiko', $fTableB, $pCenter);
        $table->addCell($wNama,  ['vMerge' => 'restart', 'bgColor' => 'D9E1F2'])->addText('Pernyataan Risiko', $fTableB, $pCenter);
        $table->addCell($wP + $wD + $wNilai + $wTingkat, ['gridSpan' => 4, 'bgColor' => 'D9E1F2'])->addText('KONDISI AKHIR TRIWULAN I', $fTableB, $pCenter);
        $table->addCell($wUpaya, ['vMerge' => 'restart', 'bgColor' => 'D9E1F2'])->addText('Upaya Pengendalian', $fTableB, $pCenter);
        $table->addCell($wP + $wD + $wNilai + $wTingkat, ['gridSpan' => 4, 'bgColor' => 'D9E1F2'])->addText('KONDISI AKHIR TRIWULAN ' . $triwulanLabel, $fTableB, $pCenter);

        // Header baris 2 (kelanjutan vMerge)
        $table->addRow();
        $table->addCell($wNo,   ['vMerge' => 'continue']);
        $table->addCell($wKode, ['vMerge' => 'continue']);
        $table->addCell($wNama, ['vMerge' => 'continue']);
        foreach (['P', 'D', 'Nilai', 'Tingkat Risiko'] as $i => $labelSub) {
            $wSub = [$wP, $wD, $wNilai, $wTingkat][$i];
            $table->addCell($wSub, ['bgColor' => 'D9E1F2'])->addText($labelSub, $fTableB, $pCenter);
        }
        $table->addCell($wUpaya, ['vMerge' => 'continue']);
        foreach (['P', 'D', 'Nilai', 'Tingkat Risiko'] as $i => $labelSub) {
            $wSub = [$wP, $wD, $wNilai, $wTingkat][$i];
            $table->addCell($wSub, ['bgColor' => 'D9E1F2'])->addText($labelSub, $fTableB, $pCenter);
        }

        if (empty($rows)) {
            $table->addRow();
            $cell = $table->addCell($wNo + $wKode + $wNama + (2 * ($wP + $wD + $wNilai + $wTingkat)) + $wUpaya, ['gridSpan' => 12]);
            $cell->addText('Tidak terdapat data risiko untuk unit kerja ini', ['italic' => true, 'color' => '6B7280'], $pCenter);
        } else {
            foreach ($rows as $rowNo => $row) {
                $awalTingkat  = (string)($row['awal_tingkat'] ?? '');
                $akhirTingkat = (string)($row['akhir_tingkat'] ?? '');
                $awalBg   = $bgMap[$awalTingkat]  ?? 'F3F4F6';
                $awalFg   = $fgMap[$awalTingkat]  ?? '374151';
                $akhirBg  = ($row['akhir_nilai'] !== null) ? ($bgMap[$akhirTingkat] ?? 'F3F4F6') : 'F3F4F6';
                $akhirFg  = ($row['akhir_nilai'] !== null) ? ($fgMap[$akhirTingkat] ?? '374151') : '374151';
                $upayaTxt = (!empty($row['upaya_pengendalian'])) ? (string)$row['upaya_pengendalian'] : '-';

                $table->addRow();
                $table->addCell($wNo)->addText((string)($rowNo + 1), $fTable, $pCenter);
                $table->addCell($wKode)->addText((string)($row['kode_risiko'] ?? ''), $fTable, $pCenter);
                $table->addCell($wNama)->addText((string)($row['nama_risiko'] ?? ''), $fTable);
                $table->addCell($wP)->addText($row['awal_p']     !== null ? (string)$row['awal_p']     : '-', $fTable, $pCenter);
                $table->addCell($wD)->addText($row['awal_d']     !== null ? (string)$row['awal_d']     : '-', $fTable, $pCenter);
                $table->addCell($wNilai)->addText($row['awal_nilai'] !== null ? (string)$row['awal_nilai'] : '-', $fTable, $pCenter);
                $table->addCell($wTingkat, ['bgColor' => $awalBg])->addText($awalTingkat !== '' ? $awalTingkat : '-', ['size' => 9, 'bold' => true, 'color' => $awalFg], $pCenter);
                $table->addCell($wUpaya)->addText($upayaTxt, $fTable);
                $table->addCell($wP)->addText($row['akhir_p']     !== null ? (string)$row['akhir_p']     : '-', $fTable, $pCenter);
                $table->addCell($wD)->addText($row['akhir_d']     !== null ? (string)$row['akhir_d']     : '-', $fTable, $pCenter);
                $table->addCell($wNilai)->addText($row['akhir_nilai'] !== null ? (string)$row['akhir_nilai'] : '-', $fTable, $pCenter);
                $table->addCell($wTingkat, ['bgColor' => $akhirBg])->addText(($row['akhir_nilai'] !== null && $akhirTingkat !== '') ? $akhirTingkat : '-', ['size' => 9, 'bold' => true, 'color' => $akhirFg], $pCenter);
            }
        }
        $section->addTextBreak(1);
    }
    $section->addPageBreak();

    // ══ BAB III: HAMBATAN DAN KENDALA ═════════════════════════
    $section->addText('BAB III', $fTitle, $pHeading);
    $section->addText('HAMBATAN DAN KENDALA', $fTitle, $pHeading);

    foreach ($unitKerjaList as $unitIdx => $unitKerja) {
        $unitNo = $unitIdx + 1;
        $kendalaList = laporanGetAggregat($db, $unitKerja, $tahun, $triwulan, 'kendala');

        $section->addText('3.' . $unitNo . '. ' . $unitKerja, $fBold, ['spaceBefore' => 160, 'spaceAfter' => 80]);
        if (empty($kendalaList)) {
            $section->addText('Tidak ditemukan kendala dalam pelaksanaan kegiatan.', null, $pJustify);
        } else {
            $section->addText('Hambatan yang ditemui meliputi:', null, $pJustify);
            foreach ($kendalaList as $i => $kendala) {
                $section->addText(($i + 1) . '. ' . $kendala, null, ['alignment' => 'both', 'indent' => 360, 'hanging' => 360, 'spaceAfter' => 40]);
            }
        }
    }
    $section->addPageBreak();

    // ══ BAB IV: PENUTUP ═══════════════════════════════════════
    $section->addText('BAB IV', $fTitle, $pHeading);
    $section->addText('PENUTUP', $fTitle, $pHeading);

    $section->addText('A. Kesimpulan', $fBold, ['spaceBefore' => 160, 'spaceAfter' => 80]);
    foreach ($unitKerjaList as $unitIdx => $unitKerja) {
        $unitNo = $unitIdx + 1;
        $stat   = laporanHitungStatistik($allUnitData[$unitIdx] ?? []);

        $section->addText('4.A.' . $unitNo . '. ' . $unitKerja, $fBold, ['spaceBefore' => 120, 'spaceAfter' => 80]);
        if ($stat['total_monev'] === 0) {
            $section->addText('Belum terdapat data monitoring dan evaluasi untuk unit kerja ini pada periode yang dipilih.', null, $pJustify);
        } else {
            $section->addText('Berdasarkan hasil monitoring dan evaluasi triwulan ' . $triwulanLabel . ' tahun ' . $tahun . ', diperoleh kesimpulan sebagai berikut:', null, $pJustify);
            $butirHuruf = ['a', 'b', 'c', 'd'];
            $butirIsi = [
                'Jumlah risiko yang mengalami penurunan tingkat risiko sebanyak ' . (int)$stat['turun'] . ' risiko.',
                'Jumlah risiko dengan tingkat risiko tetap sebanyak ' . (int)$stat['tetap'] . ' risiko.',
                'Jumlah risiko yang mengalami peningkatan tingkat risiko sebanyak ' . (int)$stat['naik'] . ' risiko.',
                'Jumlah risiko dengan tingkat risiko tinggi dan sangat tinggi sebanyak ' . (int)$stat['tinggi'] . ' risiko.',
            ];
            foreach ($butirIsi as $i => $butir) {
                $section->addText($butirHuruf[$i] . '. ' . $butir, null, ['alignment' => 'both', 'indent' => 360, 'hanging' => 360, 'spaceAfter' => 40]);
            }
        }
    }

    $section->addText('B. Rencana Tindak Lanjut', $fBold, ['spaceBefore' => 200, 'spaceAfter' => 80]);
    foreach ($unitKerjaList as $unitIdx => $unitKerja) {
        $unitNo  = $unitIdx + 1;
        $rtlList = laporanGetAggregat($db, $unitKerja, $tahun, $triwulan, 'rencana_tindak_lanjut');

        $section->addText('4.B.' . $unitNo . '. ' . $unitKerja, $fBold, ['spaceBefore' => 120, 'spaceAfter' => 80]);
        if (empty($rtlList)) {
            $section->addText('Rencana Tindak Lanjut yang akan dilakukan adalah melanjutkan upaya pengendalian yang sudah direncanakan.', null, $pJustify);
        } else {
            $section->addText('Rencana tindak lanjut yang akan dilakukan meliputi:', null, $pJustify);
            foreach ($rtlList as $i => $rtl) {
                $section->addText(($i + 1) . '. ' . $rtl, null, ['alignment' => 'both', 'indent' => 360, 'hanging' => 360, 'spaceAfter' => 40]);
            }
        }
    }

    // ── Kirim file .docx sebagai unduhan (binary bersih dari notice) ──
    $objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
    $objWriter->save('php://output');
    $docxBinary = ob_get_clean();

    // Pulihkan setting error
    if ($prevDisplay !== false) {
        ini_set('display_errors', $prevDisplay);
    }
    error_reporting($prevErrLvl);

    $namaFile = 'Laporan_Monev_TW' . $triwulanLabel . '_' . $tahun . '.docx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $namaFile . '"');
    header('Content-Length: ' . strlen($docxBinary));
    header('Cache-Control: max-age=0');
    header('Pragma: public');

    echo $docxBinary;
    exit;
}

// ── Mode Dispatch ─────────────────────────────────────────────

if ($isGenerate) {
    // Mode Generate — output HTML mandiri siap cetak/PDF
    $triwulanLabel = ['I', 'II', 'III', 'IV'][$triwulan - 1];

    // ── Mode Word (.doc) — kirim header unduhan Word ──────────
    if ($isWordDoc) {
        $namaFile = 'Laporan_Monev_TW' . $triwulanLabel . '_' . $tahun . '.doc';
        header('Content-Type: application/msword; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $namaFile . '"');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
    }
    ?>
<!DOCTYPE html>
<html lang="id"<?= $isWordDoc ? ' xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40"' : '' ?>>
<head>
  <meta charset="UTF-8">
<?php if (!$isWordDoc): ?>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php else: ?>
  <!--[if gte mso 9]>
  <xml>
    <w:WordDocument>
      <w:View>Print</w:View>
      <w:Zoom>100</w:Zoom>
      <w:DoNotOptimizeForBrowser/>
    </w:WordDocument>
  </xml>
  <![endif]-->
<?php endif; ?>
  <title>Laporan Monev Manajemen Risiko TW <?= $triwulanLabel ?> Tahun <?= xss($tahun) ?></title>
  <style>
    /* ── Base Styles ─────────────────────────────────────────── */
    *, *::before, *::after {
        box-sizing: border-box;
    }

    html, body {
        margin: 0;
        padding: 0;
    }

    body {
        font-family: Arial, Helvetica, sans-serif;
        font-size: 11pt;
        color: #000;
        line-height: 1.5;
        background: #f0f0f0;
    }

    /* ── Page Layout (screen preview) ───────────────────────── */
    /* Container polos — tiap bagian menjadi "kertas" sendiri */
    .page-wrapper {
        max-width: 21cm;
        margin: 0 auto;
        padding: 24px 0;
    }

    /* Satu "kertas A4" per bagian (cover, pengesahan, tiap BAB) */
    .doc-page {
        background: #fff;
        padding: 2.5cm 2cm 2cm 2.5cm;
        box-shadow: 0 2px 12px rgba(0,0,0,.15);
        min-height: 29.7cm;
        margin-bottom: 32px;
    }

    .doc-page:last-child {
        margin-bottom: 0;
    }

    /* ── Print Page Setup ────────────────────────────────────── */
    @page {
        size: A4 portrait;
        margin: 2.5cm 2cm 2cm 2.5cm;
    }

    /* ── Page Breaks ─────────────────────────────────────────── */
    .page-break {
        page-break-after: always;
        break-after: page;
    }

    /* Setiap BAB mulai di halaman baru dan tidak bersambung */
    .bab {
        page-break-before: always;
        break-before: page;
    }

    /* ── No-Print Elements ───────────────────────────────────── */
    .no-print {
        background: #1e40af;
        color: #fff;
        padding: 12px 0;
        text-align: center;
        position: sticky;
        top: 0;
        z-index: 100;
        box-shadow: 0 2px 8px rgba(0,0,0,.2);
    }

    .no-print .btn-print {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: #fff;
        color: #1e40af;
        border: none;
        border-radius: 6px;
        padding: 9px 22px;
        font-family: Arial, sans-serif;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        transition: background .15s, color .15s;
    }

    .no-print .btn-print:hover {
        background: #dbeafe;
        color: #1e3a8a;
    }

    .no-print .btn-print svg,
    .no-print .btn-print::before {
        content: '';
    }

    .no-print .hint {
        display: inline-block;
        margin-left: 16px;
        font-family: Arial, sans-serif;
        font-size: 12px;
        color: rgba(255,255,255,.8);
    }

    /* ── Headings ────────────────────────────────────────────── */
    /* Judul/heading: Arial 12 bold; isi teks: Arial 11 (body) */
    h1.doc-title {
        font-size: 12pt;
        font-weight: bold;
        text-align: center;
        text-transform: uppercase;
        margin: 0 0 8px 0;
        line-height: 1.3;
    }

    h2.bab-heading {
        font-size: 12pt;
        font-weight: bold;
        text-align: center;
        text-transform: uppercase;
        margin: 24px 0 16px 0;
        line-height: 1.3;
    }

    h3.subbab-heading {
        font-size: 12pt;
        font-weight: bold;
        margin: 18px 0 10px 0;
        line-height: 1.4;
    }

    h4.unit-heading {
        font-size: 12pt;
        font-weight: bold;
        margin: 14px 0 8px 0;
        line-height: 1.4;
    }

    /* ── Paragraphs ──────────────────────────────────────────── */
    p {
        margin: 0 0 10px 0;
        text-align: justify;
    }

    ol, ul {
        margin: 6px 0 10px 0;
        padding-left: 24px;
    }

    li {
        margin-bottom: 4px;
        text-align: justify;
    }

    /* ── Tables ──────────────────────────────────────────────── */
    table.laporan-table {
        border-collapse: collapse;
        width: 100%;
        margin-bottom: 16px;
        font-size: 10pt;
    }

    table.laporan-table th,
    table.laporan-table td {
        border: 1px solid #000;
        padding: 5px 7px;
        vertical-align: middle;
    }

    table.laporan-table th {
        background-color: #d9e1f2;
        font-weight: bold;
        text-align: center;
    }

    table.laporan-table td.center {
        text-align: center;
    }

    table.laporan-table td.right {
        text-align: right;
    }

    table.laporan-table .no-col {
        width: 28px;
        text-align: center;
    }

    table.laporan-table .kode-col {
        width: 90px;
        text-align: center;
    }

    table.laporan-table .small-col {
        width: 36px;
        text-align: center;
    }

    table.laporan-table .nilai-col {
        width: 42px;
        text-align: center;
    }

    table.laporan-table .tingkat-col {
        width: 80px;
        text-align: center;
        font-weight: 600;
    }

    /* ── Cover Page ──────────────────────────────────────────── */
    .cover {
        text-align: center;
        /* padding-bottom ekstra 10cm menggeser konten yang ter-center ke atas ~5cm */
        padding: 60px 20px calc(40px + 10cm);
        min-height: 25cm;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 20px;
    }

    .cover .logo-wrap img {
        height: 100px;
        width: auto;
    }

    .cover .doc-label {
        font-size: 12pt;
        font-weight: bold;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: #374151;
    }

    .cover .instansi-name {
        font-size: 12pt;
        font-weight: bold;
        text-transform: uppercase;
        margin-top: 4px;
    }

    .cover .cover-divider {
        width: 70%;
        border: none;
        border-top: 2px solid #000;
        margin: 4px auto;
    }

    .cover .periode-label {
        font-size: 12pt;
        margin-top: 8px;
    }

    /* ── Lembar Pengesahan ───────────────────────────────────── */
    .pengesahan {
        padding: 24px 0 40px;
    }

    .pengesahan h2 {
        text-align: center;
        font-size: 12pt;
        text-transform: uppercase;
        font-weight: bold;
        margin-bottom: 48px;
    }

    .pengesahan-subjudul {
        text-align: center;
        font-weight: normal;
        font-size: 11pt;
        line-height: 1.45;
        margin-bottom: 64px;
    }

    .pengesahan-subjudul p {
        margin: 0;
        padding: 0;
        line-height: 1.45;
        text-align: center;
    }

    table.tabel-petugas-pengesahan {
        border-collapse: collapse;
        border: none;
        width: auto;
        margin: 0 0 240px 3cm;
        font-size: 11pt;
    }

    table.tabel-petugas-pengesahan td {
        border: none !important;
        padding: 4px 0;
        vertical-align: top;
    }

    table.tabel-petugas-pengesahan td.col-label {
        width: 200px;
        white-space: nowrap;
    }

    table.tabel-petugas-pengesahan td.col-sep {
        width: 18px;
        text-align: center;
    }

    table.tabel-petugas-pengesahan td.col-val {
        text-align: left;
    }

    .pengesahan-ttd-center {
        text-align: center;
        margin-top: 35px;
        font-size: 11pt;
        line-height: 1.45;
    }

    .pengesahan-ttd-center p {
        margin: 0;
        padding: 0;
        line-height: 1.45;
        text-align: center;
    }

    .pengesahan-ttd-center .ttd-space {
        height: 75px;
    }

    .pengesahan-ttd-center .ttd-nama {
        font-size: 11pt;
    }

    .pengesahan-ttd-center .ttd-nip {
        font-size: 10pt;
        margin-top: 2px;
    }

    /* ── BAB Content Area ────────────────────────────────────── */
    .bab {
        padding-bottom: 16px;
    }

    .bab:last-child {
        padding-bottom: 0;
    }

    /* ── Stats Summary (BAB IV) ──────────────────────────────── */
    .stats-box {
        background: #f8f9fa;
        border: 1px solid #ccc;
        border-radius: 4px;
        padding: 10px 14px;
        margin-bottom: 12px;
        font-size: 11pt;
    }

    .stats-box ul {
        margin: 4px 0 0;
        padding-left: 20px;
    }

    /* ── Print Media Overrides ───────────────────────────────── */
    @media print {
        .no-print {
            display: none !important;
        }

        body {
            background: #fff;
        }

        .page-wrapper {
            max-width: none;
            margin: 0;
            padding: 0;
        }

        /* Hilangkan efek "kertas" di layar — @page yang mengatur margin */
        .doc-page {
            background: none;
            padding: 0;
            box-shadow: none;
            min-height: auto;
            margin-bottom: 0;
        }

        /* Bagian terakhir tidak boleh membuat halaman kosong ekstra */
        .doc-page:last-child,
        .bab:last-child {
            page-break-after: auto;
            break-after: auto;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        th, td {
            border: 1px solid #000;
            padding: 4px 6px;
            font-size: 10pt;
        }

        h2.bab-heading {
            page-break-after: avoid;
        }

        h3.subbab-heading,
        h4.unit-heading {
            page-break-after: avoid;
        }

        tr {
            page-break-inside: avoid;
        }
    }
  </style>
<?php if ($isWordDoc): ?>
  <style>
    /* Override khusus Word — hilangkan efek "kertas" layar.
       Word mengabaikan @media print, jadi override ini diperlukan. */
    body { background: #fff; }
    .page-wrapper { max-width: none; margin: 0; padding: 0; }
    .doc-page {
        background: none;
        padding: 0;
        box-shadow: none;
        min-height: auto;
        margin-bottom: 0;
    }
    /* Bagian terakhir tidak membuat halaman kosong ekstra di Word */
    .doc-page:last-child,
    .bab:last-child {
        page-break-after: auto;
    }
  </style>
<?php endif; ?>
</head>
<body>

<?php if (!$isWordDoc): ?>
  <!-- ── Toolbar (hanya tampil di layar) ────────────────────── -->
  <div class="no-print">
    <?php if (!$isEdit): ?>
    <button class="btn-print" onclick="window.print()">
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
        <path d="M2.5 8a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1z"/>
        <path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2H5zM4 3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2H4V3zm1 5a2 2 0 0 0-2 2v1H2a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v-1a2 2 0 0 0-2-2H5zm7 2v3a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1z"/>
      </svg>
      Cetak / Simpan PDF
    </button>
    <?php else: ?>
    <button class="btn-print" onclick="window.print()">
      <i class="fas fa-print" aria-hidden="true"></i>
      Cetak / Simpan PDF
    </button>
    <?php endif; ?>
    <?php if (!$isEdit): ?>
    <a class="btn-print" style="text-decoration:none;" href="<?= APP_URL ?>/?page=laporan_monev&amp;generate=1&amp;format=docx&amp;triwulan=<?= $triwulan ?>&amp;tahun=<?= urlencode($tahun) ?>&amp;koordinator=<?= urlencode($koordinator) ?>&amp;penulis=<?= urlencode($penulis) ?>&amp;nama_kepala=<?= urlencode($namaKepala) ?>&amp;nip_kepala=<?= urlencode($nipKepala) ?>&amp;tanggal=<?= urlencode($tanggal) ?>">
      <i class="fas fa-file-word" aria-hidden="true"></i>
      Unduh Word (.docx)
    </a>
    <a class="btn-print" style="text-decoration:none;" href="<?= APP_URL ?>/?page=laporan_monev&amp;generate=1&amp;edit=1&amp;triwulan=<?= $triwulan ?>&amp;tahun=<?= urlencode($tahun) ?>&amp;koordinator=<?= urlencode($koordinator) ?>&amp;penulis=<?= urlencode($penulis) ?>&amp;nama_kepala=<?= urlencode($namaKepala) ?>&amp;nip_kepala=<?= urlencode($nipKepala) ?>&amp;tanggal=<?= urlencode($tanggal) ?>">
      <i class="fas fa-pen-to-square" aria-hidden="true"></i>
      Edit Online
    </a>
    <span class="hint">Gunakan "Save as PDF" pada dialog cetak browser, unduh Word, atau edit langsung di browser.</span>
    <?php else: ?>
    <button type="button" class="btn-print" id="btnUnduhWordEdit">
      <i class="fas fa-file-word" aria-hidden="true"></i>
      Unduh Hasil Edit (.docx)
    </button>
    <button type="button" class="btn-print" id="btnResetDraft" style="background:#fee2e2;color:#991b1b;">
      <i class="fas fa-rotate-left" aria-hidden="true"></i>
      Batalkan Perubahan
    </button>
    <span class="hint"><i class="fas fa-pen-to-square"></i> Mode Edit Online &mdash; klik dokumen untuk mengubah teks. Perubahan tersimpan otomatis di browser.</span>
    <?php endif; ?>
  </div>
<?php endif; ?>

  <div class="page-wrapper"<?= $isEdit ? ' id="editableWrapper"' : '' ?>>

    <!-- ── Halaman 1: Cover ──────────────────────────────────── -->
    <div class="cover doc-page page-break">
      <div class="logo-wrap">
        <img src="<?= APP_URL ?>/assets/img/logo.png" alt="Logo">
      </div>
      <div class="doc-label">LAPORAN MONITORING DAN EVALUASI MANAJEMEN RISIKO</div>
      <div class="instansi-name">BALAI BESAR LABORATORIUM KESEHATAN LINGKUNGAN</div>
      <hr class="cover-divider">
      <div class="periode-label">TRIWULAN <?= xss($triwulanLabel) ?> TAHUN <?= xss($tahun) ?></div>
    </div>

    <!-- ── Halaman 2: Lembar Pengesahan ──────────────────────── -->
    <div class="pengesahan doc-page page-break">
      <h2>LEMBAR PENGESAHAN</h2>

      <div class="pengesahan-subjudul">
        <p>LAPORAN MONITORING DAN EVALUASI</p>
        <p>MANAJEMEN RISIKO</p>
        <p>BALAI BESAR LABORATORIUM KESEHATAN LINGKUNGAN</p>
        <p>TRIWULAN <?= xss($triwulanLabel) ?> TAHUN <?= xss($tahun) ?></p>
      </div>

      <table class="tabel-petugas-pengesahan">
        <tr>
          <td class="col-label">Koordinator dan Verifikator</td>
          <td class="col-sep">:</td>
          <td class="col-val"><?= $koordinator !== '' ? xss($koordinator) : '_________________________' ?></td>
        </tr>
        <tr>
          <td class="col-label">Penulis</td>
          <td class="col-sep">:</td>
          <td class="col-val"><?= $penulis !== '' ? xss($penulis) : '_________________________' ?></td>
        </tr>
      </table>

      <?php
        if ($tanggal !== '') {
            $tglFmt         = laporanFormatTanggal($tanggal);
            $displayTanggal = (stripos($tglFmt, 'Salatiga') === 0) ? $tglFmt : ('Salatiga, ' . $tglFmt);
            $tanggalHtml    = xss($displayTanggal);
        } else {
            $tanggalHtml    = 'Salatiga, _________________________';
        }
      ?>
      <div class="pengesahan-ttd-center">
        <p><?= $tanggalHtml ?></p>
        <p>Disahkan oleh</p>
        <p>Kepala Balai Besar Laboratorium Kesehatan Lingkungan</p>
        <div class="ttd-space"></div>
        <p class="ttd-nama"><?= $namaKepala !== '' ? xss($namaKepala) : '_________________________' ?></p>
        <p class="ttd-nip"><?= $nipKepala !== '' ? 'NIP. ' . xss($nipKepala) : 'NIP. _________________________' ?></p>
      </div>
    </div>

    <!-- ── BAB I: Pendahuluan ────────────────────────────────── -->
    <div class="bab doc-page" id="bab1">
      <h2 class="bab-heading">BAB I<br>PENDAHULUAN</h2>

      <h3 class="subbab-heading">1.A. Latar Belakang</h3>
      <p>
        Risiko adalah kemungkinan terjadinya suatu peristiwa yang berdampak negatif terhadap pencapaian sasaran organisasi. 
        Manajemen Risiko merupakan proses yang proaktif dan berkelanjutan meliputi identifikasi, analisis, evaluasi, pengendalian, 
        informasi komunikasi, pemantauan, dan pelaporan risiko, termasuk berbagai strategi yang dijalankan untuk mengelola Risiko 
        dan potensinya. mengantisipasi dan menangani segala bentuk Risiko secara efektif dan efisien.
      </p>
      <p>
        Penerapan manajemen risiko bertujuan untuk mengidentifikasi dan memitigasi sumber-sumber risiko yang berpotensi menghambat pencapaian tujuan organisasi. Selain itu, 
        manajemen risiko juga menjadi dasar dalam pengambilan keputusan dan perencanaan strategis, serta berkontribusi dalam peningkatan kinerja organisasi. 
        Melalui penerapan manajemen risiko yang efektif, diharapkan organisasi mampu menjaga kesinambungan pelayanan kepada pemangku kepentingan, meningkatkan efisiensi 
        dan efektivitas pelaksanaan kegiatan, serta menghindari terjadinya pemborosan sumber daya.Laporan ini menyajikan hasil monitoring dan evaluasi pelaksanaan pengendalian risiko
        pada Balai Besar Laboratorium Kesehatan Lingkungan untuk periode Triwulan
        <?= xss($triwulanLabel) ?> Tahun <?= xss($tahun) ?>. Laporan mencakup kondisi risiko awal,
        upaya pengendalian yang dilaksanakan, kondisi risiko akhir periode, hambatan dan kendala
        yang dihadapi, serta rencana tindak lanjut dari seluruh unit kerja di lingkungan Balai
        Besar Laboratorium Kesehatan Lingkungan.
      </p>

      <h3 class="subbab-heading">1.B. Dasar Hukum</h3>
      <ol>
        <li>Peraturan Pemerintah Nomor 60 Tahun 2008 tentang Sistem Pengendalian Intern Pemerintah (SPIP).</li>
        <li>Peraturan Kepala BPKP Nomor 4 Tahun 2016 tentang Pedoman Penilaian dan Strategi Peningkatan Maturitas Sistem Pengendalian Intern Pemerintah.</li>
        <li>Peraturan Menteri Kesehatan Nomor 25 Tahun 2019 tentang Penerapan Manajemen Risiko Terintegrasi di Lingkungan Kementerian Kesehatan.</li>
        <li>Peraturan Menteri Pendayagunaan Aparatur Negara dan Reformasi Birokrasi Nomor 5 Tahun 2020 tentang Road Map Reformasi Birokrasi 2020&ndash;2024.</li>
        <li>Keputusan Menteri Kesehatan Nomor HK.01.07/MENKES/1160/2022 tentang Pedoman Manajemen Risiko Kementerian Kesehatan.</li>
        <li>ISO 31000:2018 &mdash; Risk Management Guidelines.</li>
        <li>Standar Nasional Indonesia SNI ISO 31000:2018 tentang Manajemen Risiko &mdash; Panduan.</li>
        <li>Peraturan Pemerintah Nomor 12 Tahun 2019 tentang Pengelolaan Keuangan Daerah.</li>
        <li>Rencana Strategis Balai Besar Laboratorium Kesehatan Lingkungan tahun <?= xss($tahun) ?>.</li>
      </ol>

      <h3 class="subbab-heading">1.C. Tujuan</h3>
      <ol>
        <li>Memantau pelaksanaan pengendalian risiko yang telah direncanakan pada setiap unit kerja.</li>
        <li>Mengevaluasi efektivitas upaya pengendalian risiko berdasarkan perubahan nilai dan tingkat risiko.</li>
        <li>Mengidentifikasi hambatan dan kendala dalam pelaksanaan pengendalian risiko.</li>
        <li>Merumuskan rencana tindak lanjut untuk meningkatkan efektivitas pengendalian risiko pada periode berikutnya.</li>
        <li>Menyediakan bahan pengambilan keputusan bagi pimpinan terkait pengelolaan risiko organisasi.</li>
      </ol>
    </div><!-- /#bab1 -->

<?php
    // ── BAB II: Pelaksanaan Pengendalian Risiko Per Unit Kerja ──
    $db = getDB();
    ?>

    <!-- ── BAB II ─────────────────────────────────────────────── -->
    <div class="bab doc-page" id="bab2">
      <h2 class="bab-heading">BAB II<br>PELAKSANAAN PENGENDALIAN RISIKO</h2>

<?php
    $allUnitData = [];
    foreach ($unitKerjaList as $unitIdx => $unitKerja):
        $rows = laporanGetDataUnit($db, $unitKerja, $tahun, $triwulan);
        $allUnitData[$unitIdx] = $rows;
        $unitNo = $unitIdx + 1;
?>
      <h3 class="subbab-heading">2.<?= $unitNo ?>. <?= xss($unitKerja) ?></h3>

      <table class="laporan-table">
        <thead>
          <tr>
            <th rowspan="2" class="no-col">No</th>
            <th rowspan="2" class="kode-col">Kode<br>Risiko</th>
            <th rowspan="2" style="min-width:160px;">Pernyataan Risiko</th>
            <th colspan="4">KONDISI AKHIR TRIWULAN I</th>
            <th rowspan="2" style="min-width:140px;">Upaya Pengendalian</th>
            <th colspan="4">KONDISI AKHIR TRIWULAN <?= xss($triwulanLabel) ?></th>
          </tr>
          <tr>
            <th class="small-col">P</th>
            <th class="small-col">D</th>
            <th class="nilai-col">Nilai</th>
            <th class="tingkat-col">Tingkat Risiko</th>
            <th class="small-col">P</th>
            <th class="small-col">D</th>
            <th class="nilai-col">Nilai</th>
            <th class="tingkat-col">Tingkat Risiko</th>
          </tr>
        </thead>
        <tbody>
<?php
        if (empty($rows)):
?>
          <tr>
            <td colspan="11" style="text-align:center; font-style:italic; color:#6b7280;">
              Tidak terdapat data risiko untuk unit kerja ini
            </td>
          </tr>
<?php
        else:
            foreach ($rows as $rowNo => $row):
                $awalTingkat  = (string)($row['awal_tingkat']  ?? '');
                $akhirTingkat = (string)($row['akhir_tingkat'] ?? '');

                // Kondisi awal — selalu dari kkpr_risiko, tidak pernah NULL
                $awalP     = ($row['awal_p']     !== null) ? xss((string)$row['awal_p'])     : '-';
                $awalD     = ($row['awal_d']     !== null) ? xss((string)$row['awal_d'])     : '-';
                $awalNilai = ($row['awal_nilai'] !== null) ? xss((string)$row['awal_nilai']) : '-';

                // Kondisi akhir — dari monev_triwulan (LEFT JOIN → NULL jika tidak ada)
                $akhirP     = ($row['akhir_p']     !== null) ? xss((string)$row['akhir_p'])     : '-';
                $akhirD     = ($row['akhir_d']     !== null) ? xss((string)$row['akhir_d'])     : '-';
                $akhirNilai = ($row['akhir_nilai'] !== null) ? xss((string)$row['akhir_nilai']) : '-';
                $akhirTingkatDisplay = ($row['akhir_nilai'] !== null && $akhirTingkat !== '')
                    ? xss($akhirTingkat)
                    : '-';

                // Upaya pengendalian — NULL/kosong → "-"
                $upaya = (isset($row['upaya_pengendalian']) && $row['upaya_pengendalian'] !== null && $row['upaya_pengendalian'] !== '')
                    ? xss($row['upaya_pengendalian'])
                    : '-';
?>
          <tr>
            <td class="center"><?= $rowNo + 1 ?></td>
            <td class="center"><?= xss((string)($row['kode_risiko'] ?? '')) ?></td>
            <td><?= xss((string)($row['nama_risiko'] ?? '')) ?></td>
            <td class="center"><?= $awalP ?></td>
            <td class="center"><?= $awalD ?></td>
            <td class="center"><?= $awalNilai ?></td>
            <td class="tingkat-col center" style="background-color:<?= laporanRisikoBg($awalTingkat) ?>; color:<?= laporanRisikoColor($awalTingkat) ?>;">
              <?= ($awalTingkat !== '') ? xss($awalTingkat) : '-' ?>
            </td>
            <td><?= $upaya ?></td>
            <td class="center"><?= $akhirP ?></td>
            <td class="center"><?= $akhirD ?></td>
            <td class="center"><?= $akhirNilai ?></td>
            <td class="tingkat-col center" style="background-color:<?= ($row['akhir_nilai'] !== null) ? laporanRisikoBg($akhirTingkat) : '#f3f4f6' ?>; color:<?= ($row['akhir_nilai'] !== null) ? laporanRisikoColor($akhirTingkat) : '#374151' ?>;">
              <?= $akhirTingkatDisplay ?>
            </td>
          </tr>
<?php
            endforeach;
        endif;
?>
        </tbody>
      </table>

<?php
    endforeach;
?>
    </div><!-- /#bab2 -->


    <!-- ── BAB III ────────────────────────────────────────────── -->
    <div class="bab doc-page" id="bab3">
      <h2 class="bab-heading">BAB III<br>HAMBATAN DAN KENDALA</h2>

<?php
    $unitHuruf = ['A', 'B', 'C', 'D', 'E', 'F'];
    foreach ($unitKerjaList as $unitIdx => $unitKerja):
        $huruf    = $unitHuruf[$unitIdx];
        $unitNo   = $unitIdx + 1;
        $kendalaList = laporanGetAggregat($db, $unitKerja, $tahun, $triwulan, 'kendala');
?>
      <h3 class="subbab-heading">3.<?= $unitNo ?>. <?= xss($unitKerja) ?></h3>

<?php if (empty($kendalaList)): ?>
      <p>Tidak ditemukan kendala dalam pelaksanaan kegiatan.</p>
<?php else: ?>
      <p>Hambatan yang ditemui meliputi:</p>
      <ol>
<?php   foreach ($kendalaList as $kendala): ?>
        <li><?= xss($kendala) ?></li>
<?php   endforeach; ?>
      </ol>
<?php endif; ?>

<?php endforeach; ?>
    </div><!-- /#bab3 -->

    <!-- ── BAB IV ────────────────────────────────────────────── -->
    <div class="bab doc-page" id="bab4">
      <h2 class="bab-heading">BAB IV<br>PENUTUP</h2>

      <h3 class="subbab-heading">A. Kesimpulan</h3>

<?php
    foreach ($unitKerjaList as $unitIdx => $unitKerja):
        $unitNo   = $unitIdx + 1;
        $rowsBab4 = $allUnitData[$unitIdx] ?? [];
        $stat     = laporanHitungStatistik($rowsBab4);
?>
      <h4 class="unit-heading">4.A.<?= $unitNo ?>. <?= xss($unitKerja) ?></h4>

<?php if ($stat['total_monev'] === 0): ?>
      <p>Belum terdapat data monitoring dan evaluasi untuk unit kerja ini pada periode yang dipilih.</p>
<?php else: ?>
      <p>Berdasarkan hasil monitoring dan evaluasi triwulan <?= xss($triwulanLabel) ?> tahun <?= xss($tahun) ?>, diperoleh kesimpulan sebagai berikut:</p>
      <ol type="a">
        <li>Jumlah risiko yang mengalami penurunan tingkat risiko sebanyak <strong><?= (int)$stat['turun'] ?></strong> risiko.</li>
        <li>Jumlah risiko dengan tingkat risiko tetap sebanyak <strong><?= (int)$stat['tetap'] ?></strong> risiko.</li>
        <li>Jumlah risiko yang mengalami peningkatan tingkat risiko sebanyak <strong><?= (int)$stat['naik'] ?></strong> risiko.</li>
        <li>Jumlah risiko dengan tingkat risiko tinggi dan sangat tinggi sebanyak <strong><?= (int)$stat['tinggi'] ?></strong> risiko.</li>
      </ol>
<?php endif; ?>

<?php endforeach; ?>

      <h3 class="subbab-heading">B. Rencana Tindak Lanjut</h3>

<?php
    foreach ($unitKerjaList as $unitIdx => $unitKerja):
        $unitNo  = $unitIdx + 1;
        $rtlList = laporanGetAggregat($db, $unitKerja, $tahun, $triwulan, 'rencana_tindak_lanjut');
?>
      <h4 class="unit-heading">4.B.<?= $unitNo ?>. <?= xss($unitKerja) ?></h4>

<?php if (empty($rtlList)): ?>
      <p>Rencana Tindak Lanjut yang akan dilakukan adalah melanjutkan upaya pengendalian yang sudah direncanakan.</p>
<?php else: ?>
      <p>Rencana tindak lanjut yang akan dilakukan meliputi:</p>
      <ol>
<?php   foreach ($rtlList as $rtl): ?>
        <li><?= xss($rtl) ?></li>
<?php   endforeach; ?>
      </ol>
<?php endif; ?>

<?php endforeach; ?>
    </div><!-- /#bab4 -->

  </div><!-- /.page-wrapper -->

<?php if ($isEdit): ?>
  <script>
  (function () {
      var wrapper = document.getElementById('editableWrapper');
      if (!wrapper) return;

      // Aktifkan editing
      wrapper.setAttribute('contenteditable', 'true');
      wrapper.setAttribute('spellcheck', 'false');

      // Draft tersimpan otomatis di localStorage (per kombinasi parameter)
      var draftKey = 'laporan_monev_draft_' + location.search;

      // Pulihkan draft sebelumnya jika ada
      try {
          var draft = localStorage.getItem(draftKey);
          if (draft) {
              wrapper.innerHTML = draft;
          }
      } catch (e) { /* localStorage tidak tersedia — abaikan */ }

      // Autosave (debounce 800ms)
      var saveTimer = null;
      wrapper.addEventListener('input', function () {
          clearTimeout(saveTimer);
          saveTimer = setTimeout(function () {
              try { localStorage.setItem(draftKey, wrapper.innerHTML); } catch (e) {}
          }, 800);
      });

      // Tombol: unduh .docx dari konten hasil edit
      var btnUnduh = document.getElementById('btnUnduhWordEdit');
      if (btnUnduh) {
          btnUnduh.addEventListener('click', function () {
              btnUnduh.disabled = true;
              btnUnduh.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Membuat Word...';

              var fd = new FormData();
              fd.append('action', 'download_docx');
              fd.append('csrf_token', <?= json_encode(csrfToken()) ?>);
              fd.append('triwulan', <?= json_encode((string)$triwulan) ?>);
              fd.append('tahun', <?= json_encode($tahun) ?>);
              fd.append('html', wrapper.outerHTML);

              fetch('<?= APP_URL ?>/?page=laporan_monev', {
                  method: 'POST',
                  body: fd,
                  credentials: 'same-origin'
              }).then(function (res) {
                  if (!res.ok) {
                      return res.json().then(function (j) {
                          throw new Error(j.error || 'Gagal membuat dokumen Word.');
                      });
                  }
                  return res.blob();
              }).then(function (blob) {
                  var a = document.createElement('a');
                  a.href = URL.createObjectURL(blob);
                  a.download = <?= json_encode('Laporan_Monev_TW' . ['I','II','III','IV'][$triwulan-1] . '_' . $tahun . '_edited.docx') ?>;
                  document.body.appendChild(a);
                  a.click();
                  setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 2000);
              }).catch(function (err) {
                  alert('Gagal mengunduh: ' + err.message);
              }).finally(function () {
                  btnUnduh.disabled = false;
                  btnUnduh.innerHTML = '<i class="fas fa-file-word"></i> Unduh Hasil Edit (.docx)';
              });
          });
      }

      // Tombol: hapus draft & muat ulang dokumen asli
      var btnReset = document.getElementById('btnResetDraft');
      if (btnReset) {
          btnReset.addEventListener('click', function () {
              if (!confirm('Batalkan semua perubahan dan kembali ke dokumen asli?')) return;
              try { localStorage.removeItem(draftKey); } catch (e) {}
              window.location.reload();
          });
      }
  })();
  </script>
  <style>
  /* Fokus editing terlihat jelas, tapi tidak ikut tercetak */
  #editableWrapper[contenteditable]:focus {
      outline: 2px dashed #60a5fa;
      outline-offset: 6px;
  }
  @media print {
      #editableWrapper[contenteditable]:focus {
          outline: none;
      }
  }
  </style>
<?php endif; ?>

</body>
</html>
<?php
} else {
    // ── Mode Form — tampilkan form input parameter dalam layout aplikasi ──
    $db        = getDB();
    $tahunList = getDaftarTahun($db, 'kkpr_header', [$tahun]);
    if (empty($tahunList)) {
        $tahunList = [(string)date('Y')];
    }
    $triwulanOptions = [
        1 => 'I',
        2 => 'II',
        3 => 'III',
        4 => 'IV',
    ];
    ?>
    <!-- ── Hero Banner ── -->
    <div class="risiko-hero profil-risiko-hero" style="background:linear-gradient(115deg,#1e3a5f 0%,#1e40af 55%,#0891b2 100%); align-items: flex-start !important;">
      <div class="risiko-hero-copy">
        <div class="risiko-eyebrow"><i class="fas fa-file-medical-alt"></i> Laporan</div>
        <h1 class="page-title" style="color:#fff">Laporan Monev Manajemen Risiko</h1>
        <p class="page-sub" style="color:rgba(255,255,255,.82)">Generate dokumen laporan monitoring dan evaluasi manajemen risiko siap cetak/PDF.</p>
      </div>
    </div>

    <!-- ── Kartu Form Generator ── -->
    <div class="card" style="margin-top:24px; max-width:780px;">
      <div class="card-header" style="display:flex; align-items:center; gap:10px;">
        <i class="fas fa-file-alt" style="color:var(--accent);font-size:1.1rem;"></i>
        <strong>Parameter Laporan</strong>
      </div>
      <div class="card-body">
        <form method="get" action="<?= APP_URL ?>/" id="formLaporanMonev"
              onsubmit="this.action='<?= APP_URL ?>/?page=laporan_monev&amp;generate=1';">
          <input type="hidden" name="page"     value="laporan_monev">
          <input type="hidden" name="generate" value="1">

          <!-- Baris 1: Triwulan + Tahun -->
          <div class="form-row-2" style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:0;">
            <div class="form-group">
              <label class="form-label" for="fTriwulan">
                Triwulan <span class="required" style="color:var(--danger)">*</span>
              </label>
              <select name="triwulan" id="fTriwulan" class="form-control" required>
                <?php foreach ($triwulanOptions as $val => $label): ?>
                  <option value="<?= $val ?>" <?= $triwulan === $val ? 'selected' : '' ?>>
                    Triwulan <?= $label ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label" for="fTahun">
                Tahun <span class="required" style="color:var(--danger)">*</span>
              </label>
              <select name="tahun" id="fTahun" class="form-control" required>
                <?php foreach ($tahunList as $y): ?>
                  <option value="<?= xss($y) ?>" <?= $tahun === $y ? 'selected' : '' ?>><?= xss($y) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Divider -->
          <div style="border-top:1px solid var(--border); margin:20px 0 16px; padding-top:4px;">
            <span style="font-size:.78rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em;">
              Data Pengesahan <span style="font-weight:400;">(opsional)</span>
            </span>
          </div>

          <!-- Baris 2: Koordinator + Penulis -->
          <div class="form-row-2" style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:0;">
            <div class="form-group">
              <label class="form-label" for="fKoordinator">Koordinator dan Verifikator</label>
              <input type="text" name="koordinator" id="fKoordinator" class="form-control"
                     maxlength="100"
                     placeholder="Nama koordinator dan verifikator"
                     value="<?= xss($koordinator) ?>">
            </div>
            <div class="form-group">
              <label class="form-label" for="fPenulis">Penulis</label>
              <input type="text" name="penulis" id="fPenulis" class="form-control"
                     maxlength="100"
                     placeholder="Nama penulis / penyusun"
                     value="<?= xss($penulis) ?>">
            </div>
          </div>

          <!-- Baris 3: Nama Kepala + NIP Kepala -->
          <div class="form-row-2" style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:0;">
            <div class="form-group">
              <label class="form-label" for="fNamaKepala">Nama Kepala BBLKL</label>
              <input type="text" name="nama_kepala" id="fNamaKepala" class="form-control"
                     maxlength="100"
                     placeholder="Nama lengkap kepala instansi"
                     value="<?= xss($namaKepala) ?>">
            </div>
            <div class="form-group">
              <label class="form-label" for="fNipKepala">NIP Kepala BBLKL</label>
              <input type="text" name="nip_kepala" id="fNipKepala" class="form-control"
                     maxlength="20"
                     placeholder="NIP kepala instansi"
                     value="<?= xss($nipKepala) ?>">
            </div>
          </div>

          <!-- Baris 4: Tanggal Pengesahan -->
          <div class="form-group">
            <label class="form-label" for="fTanggal">Tanggal Pengesahan</label>
            <input type="date" name="tanggal" id="fTanggal" class="form-control"
                   value="<?= xss(laporanTanggalKeIso($tanggal)) ?>"
                   style="max-width:320px;">
          </div>

          <!-- Tombol Submit -->
          <div style="display:flex; align-items:center; gap:12px; margin-top:8px; padding-top:8px; border-top:1px solid var(--border); flex-wrap:wrap;">
            <button type="submit" class="btn btn-primary" id="btnGenerateLaporan"
                    style="display:inline-flex; align-items:center; gap:8px; padding:10px 22px;">
              <i class="fas fa-file-pdf"></i>
              <span>Generate Laporan</span>
            </button>
            <button type="button" class="btn btn-outline" id="btnUnduhWord"
                    style="display:inline-flex; align-items:center; gap:8px; padding:10px 22px;">
              <i class="fas fa-file-word"></i>
              <span>Unduh Word (.docx)</span>
            </button>
            <button type="button" class="btn btn-outline" id="btnEditOnline"
                    style="display:inline-flex; align-items:center; gap:8px; padding:10px 22px; border-color:var(--accent); color:var(--accent);">
              <i class="fas fa-pen-to-square"></i>
              <span>Edit Online</span>
            </button>
            <span style="font-size:.8rem; color:var(--text-muted);">
              <i class="fas fa-info-circle"></i>
              Preview (cetak/PDF), unduh .docx, atau edit langsung di browser lalu unduh hasilnya.
            </span>
          </div>
        </form>
      </div>
    </div>

    <script>
    (function () {
        var form = document.getElementById('formLaporanMonev');
        if (!form) return;

        /** Kumpulkan semua parameter form ke objek URLSearchParams. */
        function buildParams(extra) {
            var params = new URLSearchParams({
                page:        'laporan_monev',
                generate:    '1',
                triwulan:    document.getElementById('fTriwulan').value,
                tahun:       document.getElementById('fTahun').value,
                koordinator: document.getElementById('fKoordinator').value,
                penulis:     document.getElementById('fPenulis').value,
                nama_kepala: document.getElementById('fNamaKepala').value,
                nip_kepala:  document.getElementById('fNipKepala').value,
                tanggal:     document.getElementById('fTanggal').value,
            });
            Object.keys(extra || {}).forEach(function (k) { params.set(k, extra[k]); });
            return params;
        }

        // Override default submit — buka laporan di tab baru
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var triwulan = document.getElementById('fTriwulan');
            var tahun    = document.getElementById('fTahun');

            if (!triwulan.value || !tahun.value) {
                // Biarkan validasi bawaan browser bekerja
                form.reportValidity();
                return;
            }

            window.open('<?= APP_URL ?>/?' + buildParams().toString(), '_blank');
        });

        // Tombol Unduh Word — navigasi biasa agar download terpicu
        var btnWord = document.getElementById('btnUnduhWord');
        if (btnWord) {
            btnWord.addEventListener('click', function () {
                var triwulan = document.getElementById('fTriwulan');
                var tahun    = document.getElementById('fTahun');

                if (!triwulan.value || !tahun.value) {
                    form.reportValidity();
                    return;
                }

                window.location.href = '<?= APP_URL ?>/?' + buildParams({ format: 'docx' }).toString();
            });
        }

        // Tombol Edit Online — buka dokumen editable di tab baru
        var btnEdit = document.getElementById('btnEditOnline');
        if (btnEdit) {
            btnEdit.addEventListener('click', function () {
                var triwulan = document.getElementById('fTriwulan');
                var tahun    = document.getElementById('fTahun');

                if (!triwulan.value || !tahun.value) {
                    form.reportValidity();
                    return;
                }

                window.open('<?= APP_URL ?>/?' + buildParams({ edit: '1' }).toString(), '_blank');
            });
        }
    })();
    </script>
    <?php
}
