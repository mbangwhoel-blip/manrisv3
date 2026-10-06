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

// ── Daftar Unit Kerja Standar ─────────────────────────────────
$defaultUnitKerjaList = [
    'Sub Bagian Administrasi Umum',
    'Tim Kerja Program Layanan',
    'Tim Kerja Mutu, Penguatan SDM dan Kemitraan',
    'Tim Kerja Surveilans Penyakit, Faktor Risiko, dan KLB',
    'Instalasi',
    'Gratifikasi',
];
$unitKerjaList = $defaultUnitKerjaList;

// ── Helper Functions ──────────────────────────────────────────

/**
 * Bersihkan nama unit kerja dari nomor urut/bullet di depan (misal: "3. Tim Kerja..." -> "Tim Kerja...").
 */
if (!function_exists('laporanCleanUnitName')) {
    function laporanCleanUnitName(string $name): string {
        $clean = preg_replace('/^\s*(\d+|[a-zA-Z])[\.\)]\s*/u', '', $name);
        return trim($clean);
    }
}

/**
 * Resolusi prefix kode risiko (A, L, M, S, I, G) dari nama unit kerja.
 * Tangguh terhadap variasi penamaan, nomor di depan, atau penamaan khusus di tabel bagian/users.
 */
if (!function_exists('laporanResolveUnitPrefix')) {
    function laporanResolveUnitPrefix(?mysqli $db, string $unitKerja): string {
        $clean = laporanCleanUnitName($unitKerja);

        $prefixMap = [
            'Sub Bagian Administrasi Umum'                          => 'A',
            'Tim Kerja Program Layanan'                             => 'L',
            'Tim Kerja Mutu, Penguatan SDM dan Kemitraan'          => 'M',
            'Tim Kerja Surveilans Penyakit, Faktor Risiko, dan KLB' => 'S',
            'Instalasi'                                             => 'I',
            'Gratifikasi'                                           => 'G',
        ];

        if (isset($prefixMap[$unitKerja])) {
            return $prefixMap[$unitKerja];
        }
        if (isset($prefixMap[$clean])) {
            return $prefixMap[$clean];
        }

        // Heuristik kata kunci (case-insensitive)
        $lower = strtolower($unitKerja);
        if (strpos($lower, 'administrasi') !== false || strpos($lower, 'adum') !== false) {
            return 'A';
        }
        if (strpos($lower, 'layanan') !== false || strpos($lower, 'program') !== false) {
            return 'L';
        }
        if (strpos($lower, 'mutu') !== false || strpos($lower, 'kemitraan') !== false || strpos($lower, 'sdm') !== false) {
            return 'M';
        }
        if (strpos($lower, 'surveilans') !== false || strpos($lower, 'klb') !== false || strpos($lower, 'faktor risiko') !== false) {
            return 'S';
        }
        if (strpos($lower, 'instalasi') !== false) {
            return 'I';
        }
        if (strpos($lower, 'gratifikasi') !== false || strpos($lower, 'upg') !== false) {
            return 'G';
        }

        // Cek prefix huruf langsung di depan nama (misal: "A. ..." atau "M - ...")
        if (preg_match('/^([ALMSIG])[\.\s\-]/i', $unitKerja, $m)) {
            return strtoupper($m[1]);
        }

        // Cek referensi user di tabel users jika koneksi DB tersedia
        if ($db) {
            $stmt = $db->prepare("
                SELECT u.kode_prefix 
                FROM users u 
                INNER JOIN bagian b ON b.id = u.bagian_id 
                WHERE (b.nama = ? OR b.nama = ?) AND u.kode_prefix IS NOT NULL AND u.kode_prefix <> '' 
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('ss', $unitKerja, $clean);
                $stmt->execute();
                $p = $stmt->get_result()->fetch_column();
                $stmt->close();
                if ($p && in_array(strtoupper((string)$p), ['A', 'L', 'M', 'S', 'I', 'G'], true)) {
                    return strtoupper((string)$p);
                }
            }
        }

        return '';
    }
}

/**
 * Ambil daftar unit kerja secara dinamis dari tabel bagian database.
 * Jika tabel belum ada atau kosong, fallback ke 6 unit kerja standar.
 */
if (!function_exists('laporanGetUnitKerjaList')) {
    function laporanGetUnitKerjaList(?mysqli $db): array {
        global $defaultUnitKerjaList;
        $defaultUnits = $defaultUnitKerjaList ?? [
            'Sub Bagian Administrasi Umum',
            'Tim Kerja Program Layanan',
            'Tim Kerja Mutu, Penguatan SDM dan Kemitraan',
            'Tim Kerja Surveilans Penyakit, Faktor Risiko, dan KLB',
            'Instalasi',
            'Gratifikasi',
        ];

        if (!$db) {
            return $defaultUnits;
        }

        $res = $db->query("SELECT nama FROM bagian WHERE aktif = 1 ORDER BY id ASC");
        if (!$res || $res->num_rows === 0) {
            return $defaultUnits;
        }

        $dbUnits = [];
        while ($row = $res->fetch_assoc()) {
            $nama = trim((string)$row['nama']);
            if ($nama === '') {
                continue;
            }
            // Filter unit non-risiko (misal Koordinator Manajemen Risiko atau Pimpinan)
            $prefix = laporanResolveUnitPrefix($db, $nama);
            if ($prefix !== '') {
                $dbUnits[] = $nama;
            }
        }

        if (empty($dbUnits)) {
            return $defaultUnits;
        }

        // Pastikan seluruh 6 unit standar tetap terwakili jika user baru menginput sebagian
        $coveredPrefixes = [];
        foreach ($dbUnits as $u) {
            $p = laporanResolveUnitPrefix($db, $u);
            if ($p !== '') {
                $coveredPrefixes[$p] = true;
            }
        }

        $prefixOrder = ['A' => 1, 'L' => 2, 'M' => 3, 'S' => 4, 'I' => 5, 'G' => 6];

        $finalUnits = $dbUnits;
        foreach ($defaultUnits as $def) {
            $p = laporanResolveUnitPrefix($db, $def);
            if (!isset($coveredPrefixes[$p])) {
                $finalUnits[] = $def;
                $coveredPrefixes[$p] = true;
            }
        }

        // Urutkan finalUnits berdasarkan nomor eksplisit (1., 2.) atau urutan standar prefix
        usort($finalUnits, function ($a, $b) use ($db, $prefixOrder) {
            preg_match('/^(\d+)[\.\)]/u', $a, $ma);
            preg_match('/^(\d+)[\.\)]/u', $b, $mb);
            if (!empty($ma[1]) && !empty($mb[1])) {
                return (int)$ma[1] <=> (int)$mb[1];
            }

            $pa = laporanResolveUnitPrefix($db, $a);
            $pb = laporanResolveUnitPrefix($db, $b);
            $oa = $prefixOrder[$pa] ?? 99;
            $ob = $prefixOrder[$pb] ?? 99;
            return $oa <=> $ob;
        });

        return $finalUnits;
    }
}

/**
 * Warna latar belakang sel tingkat risiko (konsisten dengan kkpr.php).
 */
if (!function_exists('laporanRisikoBg')) {
    function laporanRisikoBg(string $tingkat): string {
        return match($tingkat) {
            'Sangat Tinggi' => '#fee2e2',
            'Tinggi'        => '#ffedd5',
            'Sedang'        => '#fefce8',
            'Rendah'        => '#dcfce7',
            default         => '#f3f4f6',
        };
    }
}

if (!function_exists('laporanRisikoColor')) {
    function laporanRisikoColor(string $tingkat): string {
        return match($tingkat) {
            'Sangat Tinggi' => '#991b1b',
            'Tinggi'        => '#9a3412',
            'Sedang'        => '#854d0e',
            'Rendah'        => '#166534',
            default         => '#374151',
        };
    }
}

/**
 * Format teks upaya pengendalian di sel tabel:
 * Jika ada lebih dari 1 butir (baris baru atau penomoran 1., 2.),
 * pisahkan tiap butir ke baris baru (<br>).
 */
if (!function_exists('laporanFormatUpayaHtml')) {
    function laporanFormatUpayaHtml(?string $upayaRaw): string {
        if ($upayaRaw === null || trim($upayaRaw) === '' || trim($upayaRaw) === '-') {
            return '-';
        }
        $raw = trim($upayaRaw);
        $normalized = preg_replace('/(?<!\n|^)\s+(\d+\.\s+)/u', "\n$1", $raw);
        $normalized = preg_replace('/(?<!\n|^)\s+([a-zA-Z]\.\s+)/u', "\n$1", $normalized);
        $lines = preg_split('/\r\n|\r|\n/', $normalized);
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = xss($line);
            }
        }
        return empty($out) ? '-' : implode('<br>', $out);
    }
}

if (!function_exists('laporanFormatUpayaLines')) {
    function laporanFormatUpayaLines(?string $upayaRaw): array {
        if ($upayaRaw === null || trim($upayaRaw) === '' || trim($upayaRaw) === '-') {
            return ['-'];
        }
        $raw = trim($upayaRaw);
        $normalized = preg_replace('/(?<!\n|^)\s+(\d+\.\s+)/u', "\n$1", $raw);
        $normalized = preg_replace('/(?<!\n|^)\s+([a-zA-Z]\.\s+)/u', "\n$1", $normalized);
        $lines = preg_split('/\r\n|\r|\n/', $normalized);
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return empty($out) ? ['-'] : $out;
    }
}

if (!function_exists('laporanPecahItemDaftar')) {
    function laporanPecahItemDaftar(array $rawList): array {
        $items = [];
        foreach ($rawList as $raw) {
            $raw = trim((string)$raw);
            if ($raw === '' || $raw === '-') {
                continue;
            }

            $normalized = preg_replace('/(?<!\n|^)\s+(\d+\.\s+)/u', "\n$1", $raw);
            $normalized = preg_replace('/(?<!\n|^)\s+([a-zA-Z]\.\s+)/u', "\n$1", $normalized);

            $lines = preg_split('/\r\n|\r|\n/', $normalized);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line === '-') {
                    continue;
                }
                $clean = preg_replace('/^(\d+[\.\)]\s*|[a-zA-Z][\.\)]\s*|[-•*]\s*)/u', '', $line);
                $clean = trim($clean);
                if ($clean !== '' && !in_array($clean, $items, true)) {
                    $items[] = $clean;
                }
            }
        }
        return $items;
    }
}

if (!function_exists('laporanFormatItemPrefix')) {
    function laporanFormatItemPrefix(int $unitNo, int $index): string {
        $letters = '';
        $n = $index;
        do {
            $letters = chr(97 + ($n % 26)) . $letters;
            $n = intdiv($n, 26) - 1;
        } while ($n >= 0);
        return "{$unitNo}.{$letters}. ";
    }
}

if (!function_exists('laporanFormatLetterPrefix')) {
    function laporanFormatLetterPrefix(int $index): string {
        $letters = '';
        $n = $index;
        do {
            $letters = chr(97 + ($n % 26)) . $letters;
            $n = intdiv($n, 26) - 1;
        } while ($n >= 0);
        return "{$letters}. ";
    }
}

/**
 * Pastikan tabel laporan_monev_draft tersedia di database.
 */
if (!function_exists('laporanEnsureDraftTable')) {
    function laporanEnsureDraftTable(mysqli $db): void {
        $db->query("CREATE TABLE IF NOT EXISTS laporan_monev_draft (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tahun VARCHAR(10) NOT NULL,
            triwulan INT NOT NULL,
            konten_html MEDIUMTEXT NOT NULL,
            user_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_laporan_monev_periode (tahun, triwulan)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('laporanSanitizeDraftHtml')) {
    function laporanSanitizeDraftHtml(string $html): string {
        if (trim($html) === '') {
            return '';
        }
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);
        foreach (iterator_to_array($xpath->query('//script')) as $node) {
            $node->parentNode?->removeChild($node);
        }
        foreach ($xpath->query('//*') as $el) {
            if ($el instanceof DOMElement) {
                foreach (iterator_to_array($el->attributes ?? []) as $attr) {
                    $name = strtolower($attr->name);
                    $val  = strtolower(trim($attr->value));
                    if (str_starts_with($name, 'on') || str_starts_with($val, 'javascript:')) {
                        $el->removeAttribute($attr->name);
                    }
                }
            }
        }

        $container = $doc->getElementsByTagName('div')->item(0);
        if (!$container) {
            return $html;
        }
        $output = '';
        foreach ($container->childNodes as $child) {
            $output .= $doc->saveHTML($child);
        }
        return trim($output);
    }
}

/**
 * Ambil data risiko + monev untuk satu unit kerja.
 * Menggunakan LEFT JOIN sehingga risiko tanpa data monev tetap tampil.
 *
 * @return array[] Rows dengan semua kolom yang dibutuhkan
 */
if (!function_exists('laporanGetDataUnit')) {
    function laporanGetDataUnit(mysqli $db, string $unitKerja, string $tahun, int $triwulan): array {
        $prefix = laporanResolveUnitPrefix($db, $unitKerja);
        $cleanUnit = laporanCleanUnitName($unitKerja);
        $unitLike = '%' . $cleanUnit . '%';
        $rawUnitLike = '%' . $unitKerja . '%';

        $prevTriwulan = $triwulan - 1;
        if ($prefix !== '') {
            $prefixPattern = $prefix . '.%';
            $prefixDash    = $prefix . '-%';
            if ($triwulan > 1) {
                $sql = "
                    SELECT
                        r.kode_risiko,
                        r.nama_risiko,
                        COALESCE(mp.pantau_p, r.probabilitas)         AS awal_p,
                        COALESCE(mp.pantau_d, r.dampak_level)         AS awal_d,
                        COALESCE(mp.pantau_nilai, r.nilai_risiko)     AS awal_nilai,
                        COALESCE(mp.pantau_tingkat, r.tingkat_risiko) AS awal_tingkat,
                        CASE
                            WHEN TRIM(m.upaya_pengendalian) IS NOT NULL AND TRIM(m.upaya_pengendalian) != '' AND TRIM(m.upaya_pengendalian) != '-' THEN TRIM(m.upaya_pengendalian)
                            WHEN TRIM(r.pengendalian_uraian) IS NOT NULL AND TRIM(r.pengendalian_uraian) != '' AND TRIM(r.pengendalian_uraian) != '-' THEN TRIM(r.pengendalian_uraian)
                            WHEN TRIM(r.rpti_uraian) IS NOT NULL AND TRIM(r.rpti_uraian) != '' AND TRIM(r.rpti_uraian) != '-' THEN TRIM(r.rpti_uraian)
                            ELSE ''
                        END AS upaya_pengendalian,
                        COALESCE(m.pantau_p, r.pantau_p, r.target_p, r.probabilitas)            AS akhir_p,
                        COALESCE(m.pantau_d, r.pantau_d, r.target_d, r.dampak_level)            AS akhir_d,
                        COALESCE(m.pantau_nilai, r.pantau_nilai, r.target_nilai, r.nilai_risiko) AS akhir_nilai,
                        COALESCE(m.pantau_tingkat, r.pantau_tingkat, r.target_tingkat, r.tingkat_risiko) AS akhir_tingkat,
                        CASE
                            WHEN TRIM(m.kendala) IS NOT NULL AND TRIM(m.kendala) != '' AND TRIM(m.kendala) != '-' THEN TRIM(m.kendala)
                            ELSE ''
                        END AS kendala,
                        CASE
                            WHEN TRIM(m.rencana_tindak_lanjut) IS NOT NULL AND TRIM(m.rencana_tindak_lanjut) != '' AND TRIM(m.rencana_tindak_lanjut) != '-' THEN TRIM(m.rencana_tindak_lanjut)
                            WHEN TRIM(mp.rencana_tindak_lanjut) IS NOT NULL AND TRIM(mp.rencana_tindak_lanjut) != '' AND TRIM(mp.rencana_tindak_lanjut) != '-' THEN TRIM(mp.rencana_tindak_lanjut)
                            WHEN TRIM(r.rpti_uraian) IS NOT NULL AND TRIM(r.rpti_uraian) != '' AND TRIM(r.rpti_uraian) != '-' THEN TRIM(r.rpti_uraian)
                            ELSE ''
                        END AS rencana_tindak_lanjut
                    FROM kkpr_risiko r
                    INNER JOIN kkpr_header h ON h.id = r.id_kkpr
                    LEFT JOIN monev_triwulan mp ON mp.id_risiko = r.id AND mp.triwulan = ?
                    LEFT JOIN monev_triwulan m  ON m.id_risiko  = r.id AND m.triwulan = ?
                    WHERE (TRIM(h.tahun) = ? OR h.tahun = ?)
                      AND (r.kode_risiko LIKE ? OR r.kode_risiko LIKE ? OR r.kode_risiko = ? OR h.unit_pemilik_risiko LIKE ? OR h.unit_pemilik_risiko LIKE ?)
                    ORDER BY SUBSTRING_INDEX(r.kode_risiko, '.', 1) ASC, CAST(SUBSTRING_INDEX(r.kode_risiko, '.', -1) AS UNSIGNED) ASC, r.no_urut ASC
                ";
                $stmt = $db->prepare($sql);
                $stmt->bind_param('iisssssss', $prevTriwulan, $triwulan, $tahun, $tahun, $prefixPattern, $prefixDash, $prefix, $unitLike, $rawUnitLike);
            } else {
                $sql = "
                    SELECT
                        r.kode_risiko,
                        r.nama_risiko,
                        r.probabilitas        AS awal_p,
                        r.dampak_level        AS awal_d,
                        r.nilai_risiko        AS awal_nilai,
                        r.tingkat_risiko      AS awal_tingkat,
                        CASE
                            WHEN TRIM(m.upaya_pengendalian) IS NOT NULL AND TRIM(m.upaya_pengendalian) != '' AND TRIM(m.upaya_pengendalian) != '-' THEN TRIM(m.upaya_pengendalian)
                            WHEN TRIM(r.pengendalian_uraian) IS NOT NULL AND TRIM(r.pengendalian_uraian) != '' AND TRIM(r.pengendalian_uraian) != '-' THEN TRIM(r.pengendalian_uraian)
                            WHEN TRIM(r.rpti_uraian) IS NOT NULL AND TRIM(r.rpti_uraian) != '' AND TRIM(r.rpti_uraian) != '-' THEN TRIM(r.rpti_uraian)
                            ELSE ''
                        END AS upaya_pengendalian,
                        COALESCE(m.pantau_p, r.pantau_p, r.target_p, r.probabilitas)            AS akhir_p,
                        COALESCE(m.pantau_d, r.pantau_d, r.target_d, r.dampak_level)            AS akhir_d,
                        COALESCE(m.pantau_nilai, r.pantau_nilai, r.target_nilai, r.nilai_risiko) AS akhir_nilai,
                        COALESCE(m.pantau_tingkat, r.pantau_tingkat, r.target_tingkat, r.tingkat_risiko) AS akhir_tingkat,
                        CASE
                            WHEN TRIM(m.kendala) IS NOT NULL AND TRIM(m.kendala) != '' AND TRIM(m.kendala) != '-' THEN TRIM(m.kendala)
                            ELSE ''
                        END AS kendala,
                        CASE
                            WHEN TRIM(m.rencana_tindak_lanjut) IS NOT NULL AND TRIM(m.rencana_tindak_lanjut) != '' AND TRIM(m.rencana_tindak_lanjut) != '-' THEN TRIM(m.rencana_tindak_lanjut)
                            WHEN TRIM(r.rpti_uraian) IS NOT NULL AND TRIM(r.rpti_uraian) != '' AND TRIM(r.rpti_uraian) != '-' THEN TRIM(r.rpti_uraian)
                            ELSE ''
                        END AS rencana_tindak_lanjut
                    FROM kkpr_risiko r
                    INNER JOIN kkpr_header h ON h.id = r.id_kkpr
                    LEFT JOIN monev_triwulan m ON m.id_risiko = r.id AND m.triwulan = ?
                    WHERE (TRIM(h.tahun) = ? OR h.tahun = ?)
                      AND (r.kode_risiko LIKE ? OR r.kode_risiko LIKE ? OR r.kode_risiko = ? OR h.unit_pemilik_risiko LIKE ? OR h.unit_pemilik_risiko LIKE ?)
                    ORDER BY SUBSTRING_INDEX(r.kode_risiko, '.', 1) ASC, CAST(SUBSTRING_INDEX(r.kode_risiko, '.', -1) AS UNSIGNED) ASC, r.no_urut ASC
                ";
                $stmt = $db->prepare($sql);
                $stmt->bind_param('isssssss', $triwulan, $tahun, $tahun, $prefixPattern, $prefixDash, $prefix, $unitLike, $rawUnitLike);
            }
        } else {
            if ($triwulan > 1) {
                $sql = "
                    SELECT
                        r.kode_risiko,
                        r.nama_risiko,
                        COALESCE(mp.pantau_p, r.probabilitas)         AS awal_p,
                        COALESCE(mp.pantau_d, r.dampak_level)         AS awal_d,
                        COALESCE(mp.pantau_nilai, r.nilai_risiko)     AS awal_nilai,
                        COALESCE(mp.pantau_tingkat, r.tingkat_risiko) AS awal_tingkat,
                        CASE
                            WHEN TRIM(m.upaya_pengendalian) IS NOT NULL AND TRIM(m.upaya_pengendalian) != '' AND TRIM(m.upaya_pengendalian) != '-' THEN TRIM(m.upaya_pengendalian)
                            WHEN TRIM(r.pengendalian_uraian) IS NOT NULL AND TRIM(r.pengendalian_uraian) != '' AND TRIM(r.pengendalian_uraian) != '-' THEN TRIM(r.pengendalian_uraian)
                            WHEN TRIM(r.rpti_uraian) IS NOT NULL AND TRIM(r.rpti_uraian) != '' AND TRIM(r.rpti_uraian) != '-' THEN TRIM(r.rpti_uraian)
                            ELSE ''
                        END AS upaya_pengendalian,
                        COALESCE(m.pantau_p, r.pantau_p, r.target_p, r.probabilitas)            AS akhir_p,
                        COALESCE(m.pantau_d, r.pantau_d, r.target_d, r.dampak_level)            AS akhir_d,
                        COALESCE(m.pantau_nilai, r.pantau_nilai, r.target_nilai, r.nilai_risiko) AS akhir_nilai,
                        COALESCE(m.pantau_tingkat, r.pantau_tingkat, r.target_tingkat, r.tingkat_risiko) AS akhir_tingkat,
                        CASE
                            WHEN TRIM(m.kendala) IS NOT NULL AND TRIM(m.kendala) != '' AND TRIM(m.kendala) != '-' THEN TRIM(m.kendala)
                            ELSE ''
                        END AS kendala,
                        CASE
                            WHEN TRIM(m.rencana_tindak_lanjut) IS NOT NULL AND TRIM(m.rencana_tindak_lanjut) != '' AND TRIM(m.rencana_tindak_lanjut) != '-' THEN TRIM(m.rencana_tindak_lanjut)
                            WHEN TRIM(mp.rencana_tindak_lanjut) IS NOT NULL AND TRIM(mp.rencana_tindak_lanjut) != '' AND TRIM(mp.rencana_tindak_lanjut) != '-' THEN TRIM(mp.rencana_tindak_lanjut)
                            WHEN TRIM(r.rpti_uraian) IS NOT NULL AND TRIM(r.rpti_uraian) != '' AND TRIM(r.rpti_uraian) != '-' THEN TRIM(r.rpti_uraian)
                            ELSE ''
                        END AS rencana_tindak_lanjut
                    FROM kkpr_risiko r
                    INNER JOIN kkpr_header h ON h.id = r.id_kkpr
                    LEFT JOIN monev_triwulan mp ON mp.id_risiko = r.id AND mp.triwulan = ?
                    LEFT JOIN monev_triwulan m  ON m.id_risiko  = r.id AND m.triwulan = ?
                    WHERE (TRIM(h.tahun) = ? OR h.tahun = ?)
                      AND (h.unit_pemilik_risiko LIKE ? OR h.unit_pemilik_risiko LIKE ?)
                    ORDER BY SUBSTRING_INDEX(r.kode_risiko, '.', 1) ASC, CAST(SUBSTRING_INDEX(r.kode_risiko, '.', -1) AS UNSIGNED) ASC, r.no_urut ASC
                ";
                $stmt = $db->prepare($sql);
                $stmt->bind_param('iissss', $prevTriwulan, $triwulan, $tahun, $tahun, $unitLike, $rawUnitLike);
            } else {
                $sql = "
                    SELECT
                        r.kode_risiko,
                        r.nama_risiko,
                        r.probabilitas        AS awal_p,
                        r.dampak_level        AS awal_d,
                        r.nilai_risiko        AS awal_nilai,
                        r.tingkat_risiko      AS awal_tingkat,
                        CASE
                            WHEN TRIM(m.upaya_pengendalian) IS NOT NULL AND TRIM(m.upaya_pengendalian) != '' AND TRIM(m.upaya_pengendalian) != '-' THEN TRIM(m.upaya_pengendalian)
                            WHEN TRIM(r.pengendalian_uraian) IS NOT NULL AND TRIM(r.pengendalian_uraian) != '' AND TRIM(r.pengendalian_uraian) != '-' THEN TRIM(r.pengendalian_uraian)
                            WHEN TRIM(r.rpti_uraian) IS NOT NULL AND TRIM(r.rpti_uraian) != '' AND TRIM(r.rpti_uraian) != '-' THEN TRIM(r.rpti_uraian)
                            ELSE ''
                        END AS upaya_pengendalian,
                        COALESCE(m.pantau_p, r.pantau_p, r.target_p, r.probabilitas)            AS akhir_p,
                        COALESCE(m.pantau_d, r.pantau_d, r.target_d, r.dampak_level)            AS akhir_d,
                        COALESCE(m.pantau_nilai, r.pantau_nilai, r.target_nilai, r.nilai_risiko) AS akhir_nilai,
                        COALESCE(m.pantau_tingkat, r.pantau_tingkat, r.target_tingkat, r.tingkat_risiko) AS akhir_tingkat,
                        CASE
                            WHEN TRIM(m.kendala) IS NOT NULL AND TRIM(m.kendala) != '' AND TRIM(m.kendala) != '-' THEN TRIM(m.kendala)
                            ELSE ''
                        END AS kendala,
                        CASE
                            WHEN TRIM(m.rencana_tindak_lanjut) IS NOT NULL AND TRIM(m.rencana_tindak_lanjut) != '' AND TRIM(m.rencana_tindak_lanjut) != '-' THEN TRIM(m.rencana_tindak_lanjut)
                            WHEN TRIM(r.rpti_uraian) IS NOT NULL AND TRIM(r.rpti_uraian) != '' AND TRIM(r.rpti_uraian) != '-' THEN TRIM(r.rpti_uraian)
                            ELSE ''
                        END AS rencana_tindak_lanjut
                    FROM kkpr_risiko r
                    INNER JOIN kkpr_header h ON h.id = r.id_kkpr
                    LEFT JOIN monev_triwulan m ON m.id_risiko = r.id AND m.triwulan = ?
                    WHERE (TRIM(h.tahun) = ? OR h.tahun = ?)
                      AND (h.unit_pemilik_risiko LIKE ? OR h.unit_pemilik_risiko LIKE ?)
                    ORDER BY SUBSTRING_INDEX(r.kode_risiko, '.', 1) ASC, CAST(SUBSTRING_INDEX(r.kode_risiko, '.', -1) AS UNSIGNED) ASC, r.no_urut ASC
                ";
                $stmt = $db->prepare($sql);
                $stmt->bind_param('issss', $triwulan, $tahun, $tahun, $unitLike, $rawUnitLike);
            }
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('laporanGetAggregat')) {
    function laporanGetAggregat(mysqli $db, string $unitKerja, string $tahun, int $triwulan, string $kolom): array {
        $kolomDiizinkan = ['kendala', 'rencana_tindak_lanjut'];
        if (!in_array($kolom, $kolomDiizinkan, true)) {
            return [];
        }

        $prefix = laporanResolveUnitPrefix($db, $unitKerja);
        $cleanUnit = laporanCleanUnitName($unitKerja);
        $unitLike = '%' . $cleanUnit . '%';
        $rawUnitLike = '%' . $unitKerja . '%';

        if ($prefix !== '') {
            $prefixDot  = $prefix . '.%';
            $prefixDash = $prefix . '-%';
            $sql = "
                SELECT DISTINCT 
                    CASE 
                        WHEN '{$kolom}' = 'rencana_tindak_lanjut' THEN
                            CASE
                                WHEN TRIM(m.rencana_tindak_lanjut) IS NOT NULL AND TRIM(m.rencana_tindak_lanjut) != '' AND TRIM(m.rencana_tindak_lanjut) != '-' THEN TRIM(m.rencana_tindak_lanjut)
                                WHEN TRIM(r.rpti_uraian) IS NOT NULL AND TRIM(r.rpti_uraian) != '' AND TRIM(r.rpti_uraian) != '-' THEN TRIM(r.rpti_uraian)
                                ELSE ''
                            END
                        ELSE
                            CASE
                                WHEN TRIM(m.kendala) IS NOT NULL AND TRIM(m.kendala) != '' AND TRIM(m.kendala) != '-' THEN TRIM(m.kendala)
                                ELSE ''
                            END
                    END AS val
                FROM kkpr_risiko r
                INNER JOIN kkpr_header h ON h.id = r.id_kkpr
                LEFT JOIN monev_triwulan m ON m.id_risiko = r.id AND m.triwulan = ?
                WHERE (TRIM(h.tahun) = ? OR h.tahun = ?)
                  AND (r.kode_risiko LIKE ? OR r.kode_risiko LIKE ? OR r.kode_risiko = ? OR h.unit_pemilik_risiko LIKE ? OR h.unit_pemilik_risiko LIKE ?)
                ORDER BY val ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->bind_param('isssssss', $triwulan, $tahun, $tahun, $prefixDot, $prefixDash, $prefix, $unitLike, $rawUnitLike);
        } else {
            $sql = "
                SELECT DISTINCT 
                    CASE 
                        WHEN '{$kolom}' = 'rencana_tindak_lanjut' THEN
                            CASE
                                WHEN TRIM(m.rencana_tindak_lanjut) IS NOT NULL AND TRIM(m.rencana_tindak_lanjut) != '' AND TRIM(m.rencana_tindak_lanjut) != '-' THEN TRIM(m.rencana_tindak_lanjut)
                                WHEN TRIM(r.rpti_uraian) IS NOT NULL AND TRIM(r.rpti_uraian) != '' AND TRIM(r.rpti_uraian) != '-' THEN TRIM(r.rpti_uraian)
                                ELSE ''
                            END
                        ELSE
                            CASE
                                WHEN TRIM(m.kendala) IS NOT NULL AND TRIM(m.kendala) != '' AND TRIM(m.kendala) != '-' THEN TRIM(m.kendala)
                                ELSE ''
                            END
                    END AS val
                FROM kkpr_risiko r
                INNER JOIN kkpr_header h ON h.id = r.id_kkpr
                LEFT JOIN monev_triwulan m ON m.id_risiko = r.id AND m.triwulan = ?
                WHERE (TRIM(h.tahun) = ? OR h.tahun = ?)
                  AND (h.unit_pemilik_risiko LIKE ? OR h.unit_pemilik_risiko LIKE ?)
                ORDER BY val ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->bind_param('issss', $triwulan, $tahun, $tahun, $unitLike, $rawUnitLike);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $values = [];
        while ($row = $result->fetch_row()) {
            $val = trim((string)$row[0]);
            if ($val !== '' && $val !== '-') {
                $values[] = $val;
            }
        }
        $stmt->close();
        return $values;
    }
}


if (!function_exists('laporanHitungStatistik')) {
    function laporanHitungStatistik(array $rows): array {
        $turun      = 0;
        $tetap      = 0;
        $naik       = 0;
        $tinggi     = 0;
        $totalMonev = 0;
        $levels     = [
            'Sangat Tinggi' => 0,
            'Tinggi'        => 0,
            'Sedang'        => 0,
            'Rendah'        => 0,
            'Sangat Rendah' => 0,
        ];

        foreach ($rows as $row) {
            if ($row['akhir_nilai'] === null) {
                continue;
            }

            $totalMonev++;
            $awalNilai  = (int)round((float)$row['awal_nilai']);
            $akhirNilai = (int)round((float)$row['akhir_nilai']);

            if ($akhirNilai < $awalNilai) {
                $turun++;
            } elseif ($akhirNilai === $awalNilai) {
                $tetap++;
            } else {
                $naik++;
            }

            $akhirTingkat = trim((string)($row['akhir_tingkat'] ?? ''));
            if (isset($levels[$akhirTingkat])) {
                $levels[$akhirTingkat]++;
            }
            if (in_array($akhirTingkat, ['Tinggi', 'Sangat Tinggi'], true)) {
                $tinggi++;
            }
        }

        return [
            'turun'         => $turun,
            'tetap'         => $tetap,
            'naik'          => $naik,
            'tinggi'        => $tinggi,
            'total_monev'   => $totalMonev,
            'levels'        => $levels,
            'sangat_tinggi' => $levels['Sangat Tinggi'],
            'tinggi_level'  => $levels['Tinggi'],
            'sedang'        => $levels['Sedang'],
            'rendah'        => $levels['Rendah'],
            'sangat_rendah' => $levels['Sangat Rendah'],
        ];
    }
}

if (!function_exists('laporanBulanId')) {
    function laporanBulanId(): array {
        return [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    }
}

if (!function_exists('laporanFormatTanggal')) {
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
}

if (!function_exists('laporanTanggalKeIso')) {
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
}

if (!function_exists('laporanRenderPerbandinganMatriks5x5')) {
    /**
     * Render Peta Matriks Risiko 5x5 per unit kerja dengan perbandingan kondisi awal dan kondisi akhir.
     */
    function laporanRenderPerbandinganMatriks5x5(array $rows, int $triwulan, string $tahun, string $cleanUnitKerja, int $unitNo): string {
        $romawiArr = ['I', 'II', 'III', 'IV'];
        $kolomKiriJudul  = ($triwulan > 1) ? 'KONDISI AKHIR' : 'KONDISI AWAL';
        $kolomKiriTw     = ($triwulan > 1) ? '(TW ' . ($romawiArr[$triwulan - 2] ?? 'I') . ')' : '(TW I)';
        $kolomKananJudul = 'KONDISI AKHIR';
        $kolomKananTw    = '(TW ' . ($romawiArr[$triwulan - 1] ?? 'I') . ')';

        $pLabels = [1 => 'Jarang', 2 => 'Kecil', 3 => 'Sedang', 4 => 'Besar', 5 => 'Hampir Pasti'];
        $dLabels = [1 => 'Tidak Signifikan', 2 => 'Kecil', 3 => 'Sedang', 4 => 'Besar', 5 => 'Katastropik'];

        // Inisialisasi sel matriks 5x5
        $matrixAwal = [];
        $matrixAkhir = [];
        for ($p = 1; $p <= 5; $p++) {
            for ($d = 1; $d <= 5; $d++) {
                $matrixAwal[$p][$d] = [];
                $matrixAkhir[$p][$d] = [];
            }
        }

        $statAwal = ['Sangat Tinggi' => 0, 'Tinggi' => 0, 'Sedang' => 0, 'Rendah' => 0, 'Sangat Rendah' => 0];
        $statAkhir = ['Sangat Tinggi' => 0, 'Tinggi' => 0, 'Sedang' => 0, 'Rendah' => 0, 'Sangat Rendah' => 0];
        $totalAwal = 0;
        $totalAkhir = 0;

        foreach ($rows as $row) {
            $kode = trim((string)($row['kode_risiko'] ?? ''));
            if ($kode === '') {
                $kode = 'R';
            }

            // 1. Kondisi Awal
            $ap = (int)($row['awal_p'] ?? 0);
            $ad = (int)($row['awal_d'] ?? 0);
            if ($ap >= 1 && $ap <= 5 && $ad >= 1 && $ad <= 5) {
                $matrixAwal[$ap][$ad][] = $kode;
                $totalAwal++;

                $tAwal = trim((string)($row['awal_tingkat'] ?? ''));
                if (isset($statAwal[$tAwal])) {
                    $statAwal[$tAwal]++;
                } else {
                    $skorAwal = (int)round($ap * $ad * getBobot($ap, $ad));
                    $tFall = ($skorAwal >= 20) ? 'Sangat Tinggi' : (($skorAwal >= 15) ? 'Tinggi' : (($skorAwal >= 10) ? 'Sedang' : (($skorAwal >= 5) ? 'Rendah' : 'Sangat Rendah')));
                    if (isset($statAwal[$tFall])) {
                        $statAwal[$tFall]++;
                    }
                }
            }

            // 2. Kondisi Akhir (hanya yang terpantau / akhir_nilai !== null)
            if ($row['akhir_nilai'] !== null && $row['akhir_p'] !== null && $row['akhir_d'] !== null) {
                $kp = (int)$row['akhir_p'];
                $kd = (int)$row['akhir_d'];
                if ($kp >= 1 && $kp <= 5 && $kd >= 1 && $kd <= 5) {
                    $matrixAkhir[$kp][$kd][] = $kode;
                    $totalAkhir++;

                    $tAkhir = trim((string)($row['akhir_tingkat'] ?? ''));
                    if (isset($statAkhir[$tAkhir])) {
                        $statAkhir[$tAkhir]++;
                    } else {
                        $skorAkhir = (int)round($kp * $kd * getBobot($kp, $kd));
                        $tFall = ($skorAkhir >= 20) ? 'Sangat Tinggi' : (($skorAkhir >= 15) ? 'Tinggi' : (($skorAkhir >= 10) ? 'Sedang' : (($skorAkhir >= 5) ? 'Rendah' : 'Sangat Rendah')));
                        if (isset($statAkhir[$tFall])) {
                            $statAkhir[$tFall]++;
                        }
                    }
                }
            }
        }

        $renderSingleTable = function(array $cellMap, string $judul, string $subJudul, int $totalRisiko) use ($pLabels, $dLabels): string {
            $html = '<div class="matriks-col" style="flex:1; min-width:0;">';
            $html .= '<div style="background:#1e3a8a; color:#ffffff; font-weight:bold; font-size:8.5pt; text-align:center; padding:5px 8px; border:1px solid #1e3a8a; border-radius:4px 4px 0 0; letter-spacing:0.02em;">';
            $html .= xss($judul) . ' ' . xss($subJudul) . ' <span style="font-size:7.5pt; font-weight:normal; opacity:0.9;">(' . $totalRisiko . ' Risiko)</span>';
            $html .= '</div>';

            $html .= '<table class="matriks-5x5-table" style="width:100%; border-collapse:collapse; table-layout:fixed; font-size:7.5pt; border:1px solid #000; background:#fff;">';
            $html .= '<thead><tr style="background:#d9e1f2;">';
            $html .= '<th style="border:1px solid #000; padding:3px 2px; font-size:7pt; width:15%; text-align:center; color:#1e293b; font-weight:bold;">P \ D</th>';
            for ($d = 1; $d <= 5; $d++) {
                $html .= '<th style="border:1px solid #000; padding:3px 2px; font-size:7pt; width:17%; text-align:center; color:#1e293b; font-weight:bold;" title="D' . $d . ': ' . $dLabels[$d] . '">D' . $d . '</th>';
            }
            $html .= '</tr></thead>';
            $html .= '<tbody>';

            for ($p = 5; $p >= 1; $p--) {
                $html .= '<tr>';
                $html .= '<th style="border:1px solid #000; background:#d9e1f2; font-size:7pt; padding:2px; text-align:center; color:#1e293b; font-weight:bold;" title="P' . $p . ': ' . $pLabels[$p] . '">P' . $p . '</th>';

                for ($d = 1; $d <= 5; $d++) {
                    $skor = (int)round($p * $d * getBobot($p, $d));
                    $bg = heatmapColor($p, $d);
                    $fg = ($skor >= 10 && $skor <= 14) ? '#000000' : '#ffffff';
                    $codes = $cellMap[$p][$d] ?? [];
                    $cnt = count($codes);

                    $html .= '<td class="heatmap-cell" style="border:1px solid #000; background-color:' . $bg . '; color:' . $fg . '; text-align:center; vertical-align:middle; padding:2px; height:42px; line-height:1.15; box-sizing:border-box;">';
                    if ($cnt > 0) {
                        $badgeBg = ($skor >= 10 && $skor <= 14) ? 'rgba(0,0,0,0.15)' : 'rgba(255,255,255,0.28)';
                        $html .= '<div style="font-size:6.5pt; font-weight:800;">';
                        if ($cnt >= 3) {
                            $html .= '<span style="display:inline-block; background:' . $badgeBg . '; color:' . $fg . '; border-radius:2px; padding:0 2px; font-weight:900; margin-bottom:1px;">(' . $cnt . ')</span> ';
                        }
                        $html .= '<span style="word-break:normal;">' . xss(implode(', ', $codes)) . '</span>';
                        $html .= '</div>';
                    }

                    $html .= '</td>';
                }
                $html .= '</tr>';
            }

            $html .= '</tbody>';
            $html .= '</table>';
            $html .= '</div>';
            return $html;
        };

        ob_start();
?>
  <div class="matriks-perbandingan-wrap" style="margin: 14px 0 24px 0; page-break-inside: avoid; break-inside: avoid;">
    <p class="table-caption" style="font-style:italic; margin-bottom:8px; font-size:10pt; text-indent:0; font-weight:bold; color:#1e3a8a;">
      Gambar <?= $unitNo ?>. Peta Matriks Risiko 5×5 <?= xss($cleanUnitKerja) ?> — Perbandingan <?= xss($kolomKiriJudul) ?> <?= xss($kolomKiriTw) ?> vs <?= xss($kolomKananJudul) ?> <?= xss($kolomKananTw) ?>
    </p>

    <!-- Grid 2 Matriks Berdampingan -->
    <div class="matriks-grid-container" style="display:flex; gap:14px; justify-content:space-between; margin-bottom:10px;">
      <?= $renderSingleTable($matrixAwal, $kolomKiriJudul, $kolomKiriTw, $totalAwal) ?>
      <?= $renderSingleTable($matrixAkhir, $kolomKananJudul, $kolomKananTw, $totalAkhir) ?>
    </div>

    <!-- Legend Warna Tingkat Risiko -->
    <div class="matriks-legend-box" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px; padding:6px 10px; margin-bottom:8px;">
      <div style="display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:8px; font-size:7.5pt; line-height:1.4;">
        <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
          <span style="font-weight:bold; color:#374151;">Tingkat Risiko:</span>
          <span style="display:inline-flex; align-items:center; gap:4px;"><span style="display:inline-block; width:10px; height:10px; background:#dc2626; border-radius:50%;"></span><strong>Sangat Tinggi</strong> (&ge;20)</span>
          <span style="display:inline-flex; align-items:center; gap:4px;"><span style="display:inline-block; width:10px; height:10px; background:#f97316; border-radius:50%;"></span><strong>Tinggi</strong> (15–19)</span>
          <span style="display:inline-flex; align-items:center; gap:4px;"><span style="display:inline-block; width:10px; height:10px; background:#FFFF00; border:1px solid #cbd5e1; border-radius:50%;"></span><strong>Sedang</strong> (10–14)</span>
          <span style="display:inline-flex; align-items:center; gap:4px;"><span style="display:inline-block; width:10px; height:10px; background:#22c55e; border-radius:50%;"></span><strong>Rendah</strong> (5–9)</span>
          <span style="display:inline-flex; align-items:center; gap:4px;"><span style="display:inline-block; width:10px; height:10px; background:#3b82f6; border-radius:50%;"></span><strong>Sangat Rendah</strong> (1–4)</span>
        </div>
        <div style="font-size:7pt; color:#64748b; font-style:italic;">
          P = Probabilitas (1–5) &bull; D = Dampak (1–5) &bull; Skor = P &times; D &times; Bobot
        </div>
      </div>
    </div>

    <!-- Tabel Ringkasan Pergeseran Tingkat Risiko -->
    <table class="matriks-summary-table" style="width:100%; border-collapse:collapse; font-size:8pt; table-layout:fixed; border:1px solid #cbd5e1;">
      <thead>
        <tr style="background:#d9e1f2;">
          <th style="border:1px solid #94a3b8; padding:3px 6px; text-align:left; width:34%; font-weight:bold;">Tingkat Risiko</th>
          <th style="border:1px solid #94a3b8; padding:3px 6px; text-align:center; width:22%; font-weight:bold;"><?= xss($kolomKiriJudul) ?> <?= xss($kolomKiriTw) ?></th>
          <th style="border:1px solid #94a3b8; padding:3px 6px; text-align:center; width:22%; font-weight:bold;"><?= xss($kolomKananJudul) ?> <?= xss($kolomKananTw) ?></th>
          <th style="border:1px solid #94a3b8; padding:3px 6px; text-align:center; width:22%; font-weight:bold;">Perubahan (Delta)</th>
        </tr>
      </thead>
      <tbody>
        <?php
          $levelsConfig = [
            'Sangat Tinggi' => ['bg' => '#fee2e2', 'color' => '#dc2626'],
            'Tinggi'        => ['bg' => '#ffedd5', 'color' => '#f97316'],
            'Sedang'        => ['bg' => '#fef9c3', 'color' => '#854d0e'],
            'Rendah'        => ['bg' => '#dcfce7', 'color' => '#16a34a'],
            'Sangat Rendah' => ['bg' => '#dbeafe', 'color' => '#2563eb'],
          ];
          foreach ($levelsConfig as $lvlName => $lvlStyle):
            $cAw = $statAwal[$lvlName] ?? 0;
            $cAk = $statAkhir[$lvlName] ?? 0;
            $delta = $cAk - $cAw;
            $deltaLabel = ($delta > 0) ? '+' . $delta : ($delta < 0 ? (string)$delta : '0 (Tetap)');
            $deltaColor = ($delta < 0) ? '#16a34a' : ($delta > 0 ? '#dc2626' : '#6b7280');
        ?>
        <tr>
          <td style="border:1px solid #cbd5e1; padding:2px 6px; font-weight:600; background:<?= $lvlStyle['bg'] ?>; color:<?= $lvlStyle['color'] ?>;">
            <?= $lvlName ?>
          </td>
          <td style="border:1px solid #cbd5e1; padding:2px 6px; text-align:center; font-weight:bold;"><?= $cAw ?></td>
          <td style="border:1px solid #cbd5e1; padding:2px 6px; text-align:center; font-weight:bold;"><?= $cAk ?></td>
          <td style="border:1px solid #cbd5e1; padding:2px 6px; text-align:center; font-weight:bold; color:<?= $deltaColor ?>;">
            <?= $deltaLabel ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <tr style="background:#f8fafc; font-weight:bold;">
          <td style="border:1px solid #cbd5e1; padding:3px 6px; text-align:left;">TOTAL RISIKO</td>
          <td style="border:1px solid #cbd5e1; padding:3px 6px; text-align:center;"><?= $totalAwal ?></td>
          <td style="border:1px solid #cbd5e1; padding:3px 6px; text-align:center;"><?= $totalAkhir ?></td>
          <td style="border:1px solid #cbd5e1; padding:3px 6px; text-align:center;"></td>
        </tr>
      </tbody>
    </table>
  </div>
<?php
        return ob_get_clean();
    }
}

if (!function_exists('laporanUpgradeDraftHtmlWithMatriks')) {
    /**
     * Otomatis melengkapi draft lama yang belum memiliki Peta Matriks Risiko 5x5 pada Bab II,
     * serta memastikan kolom perubahan pada baris TOTAL RISIKO dikosongkan.
     */
    function laporanUpgradeDraftHtmlWithMatriks(mysqli $db, string $draftHtml, int $triwulan, string $tahun, array $unitKerjaList): string {
        $cleanDraft = preg_replace(
            '/(<tr[^>]*>\s*<td[^>]*>\s*TOTAL RISIKO\s*<\/td>\s*<td[^>]*>.*?<\/td>\s*<td[^>]*>.*?<\/td>\s*)<td[^>]*>.*?<\/td>/is',
            '$1<td style="border:1px solid #cbd5e1; padding:3px 6px; text-align:center;"></td>',
            $draftHtml
        );

        // Hapus angka skor (1-25) pada sel heatmap matriks jika ada pada draft yang tersimpan
        $cleanDraft = preg_replace(
            '/(<td[^>]*class="[^"]*heatmap-cell[^"]*"[^>]*>\s*)<div[^>]*style="[^"]*font-size:\s*7\.5pt[^"]*"[^>]*>\s*\d+\s*<\/div>\s*(<div[^>]*style=")(margin-top:\s*2px;\s*)/i',
            '$1$2',
            $cleanDraft
        );
        $cleanDraft = preg_replace(
            '/<div[^>]*style="[^"]*font-size:\s*7\.5pt[^"]*"[^>]*>\s*\d+\s*<\/div>/i',
            '',
            $cleanDraft
        );

        if (strpos($cleanDraft, 'matriks-perbandingan-wrap') !== false) {
            return $cleanDraft;
        }

        $parts = preg_split('/(<\/table>)/i', $cleanDraft, -1, PREG_SPLIT_DELIM_CAPTURE);
        $newHtml = '';
        $unitIdx = 0;
        $totalUnits = count($unitKerjaList);

        for ($i = 0; $i < count($parts); $i++) {
            $chunk = $parts[$i];
            $newHtml .= $chunk;

            if (strtolower($chunk) === '</table>') {
                $prevChunk = $parts[$i - 1] ?? '';
                // Pastikan penutup </table> ini adalah milik tabel risiko unit (laporan-table)
                if (stripos($prevChunk, 'laporan-table') !== false && $unitIdx < $totalUnits) {
                    $unitKerja = $unitKerjaList[$unitIdx];
                    $cleanUnitKerja = laporanCleanUnitName($unitKerja);
                    $unitNo = $unitIdx + 1;
                    $rows = laporanGetDataUnit($db, $unitKerja, $tahun, $triwulan);
                    $matriksHtml = laporanRenderPerbandinganMatriks5x5($rows, $triwulan, $tahun, $cleanUnitKerja, $unitNo);
                    $newHtml .= "\n" . $matriksHtml . "\n";
                    $unitIdx++;
                }
            }
        }
        return $newHtml;
    }
}

if (!function_exists('laporanAddPerbandinganMatriksDocx')) {
    /**
     * Tambahkan Peta Matriks Risiko 5x5 ke dokumen PhpWord (.docx).
     */
    function laporanAddPerbandinganMatriksDocx($section, array $rows, int $triwulan, string $cleanUnitKerja, int $unitNo): void {
        $romawiArr = ['I', 'II', 'III', 'IV'];
        $kolomKiriJudul  = ($triwulan > 1) ? 'KONDISI AKHIR' : 'KONDISI AWAL';
        $kolomKiriTw     = ($triwulan > 1) ? '(TW ' . ($romawiArr[$triwulan - 2] ?? 'I') . ')' : '(TW I)';
        $kolomKananJudul = 'KONDISI AKHIR';
        $kolomKananTw    = '(TW ' . ($romawiArr[$triwulan - 1] ?? 'I') . ')';

        $matrixAwal = [];
        $matrixAkhir = [];
        for ($p = 1; $p <= 5; $p++) {
            for ($d = 1; $d <= 5; $d++) {
                $matrixAwal[$p][$d] = [];
                $matrixAkhir[$p][$d] = [];
            }
        }
        $statAwal = ['Sangat Tinggi' => 0, 'Tinggi' => 0, 'Sedang' => 0, 'Rendah' => 0, 'Sangat Rendah' => 0];
        $statAkhir = ['Sangat Tinggi' => 0, 'Tinggi' => 0, 'Sedang' => 0, 'Rendah' => 0, 'Sangat Rendah' => 0];
        $totalAwal = 0;
        $totalAkhir = 0;

        foreach ($rows as $row) {
            $kode = trim((string)($row['kode_risiko'] ?? ''));
            if ($kode === '') $kode = 'R';
            $ap = (int)($row['awal_p'] ?? 0);
            $ad = (int)($row['awal_d'] ?? 0);
            if ($ap >= 1 && $ap <= 5 && $ad >= 1 && $ad <= 5) {
                $matrixAwal[$ap][$ad][] = $kode;
                $totalAwal++;
                $tAwal = trim((string)($row['awal_tingkat'] ?? ''));
                if (isset($statAwal[$tAwal])) $statAwal[$tAwal]++;
            }
            if ($row['akhir_nilai'] !== null && $row['akhir_p'] !== null && $row['akhir_d'] !== null) {
                $kp = (int)$row['akhir_p'];
                $kd = (int)$row['akhir_d'];
                if ($kp >= 1 && $kp <= 5 && $kd >= 1 && $kd <= 5) {
                    $matrixAkhir[$kp][$kd][] = $kode;
                    $totalAkhir++;
                    $tAkhir = trim((string)($row['akhir_tingkat'] ?? ''));
                    if (isset($statAkhir[$tAkhir])) $statAkhir[$tAkhir]++;
                }
            }
        }

        $fCaption = ['italic' => true, 'size' => 10, 'bold' => true, 'color' => '1E3A8A'];
        $fTableB  = ['bold' => true, 'size' => 8];
        $fTableS  = ['size' => 7.5];
        $pCenter  = ['alignment' => 'center', 'spaceBefore' => 0, 'spaceAfter' => 0];

        $section->addText('Gambar ' . $unitNo . '. Peta Matriks Risiko 5×5 ' . $cleanUnitKerja . ' (Perbandingan ' . $kolomKiriJudul . ' ' . $kolomKiriTw . ' vs ' . $kolomKananJudul . ' ' . $kolomKananTw . ')', $fCaption, ['spaceBefore' => 120, 'spaceAfter' => 60]);

        $addDocxMatrix = function($sec, array $cellMap, string $judul, int $tot) use ($fTableB, $fTableS, $pCenter): void {
            $sec->addText($judul . ' (' . $tot . ' Risiko)', ['bold' => true, 'size' => 9, 'color' => '1E3A8A'], ['spaceBefore' => 60, 'spaceAfter' => 40]);
            $t = $sec->addTable(['borderSize' => 4, 'borderColor' => '000000', 'width' => 100, 'unit' => 'pct', 'cellMargin' => 20]);
            $t->addRow();
            $t->addCell(1000, ['bgColor' => 'D9E1F2'])->addText('P \ D', $fTableB, $pCenter);
            for ($d = 1; $d <= 5; $d++) {
                $t->addCell(1600, ['bgColor' => 'D9E1F2'])->addText('D' . $d, $fTableB, $pCenter);
            }
            for ($p = 5; $p >= 1; $p--) {
                $t->addRow();
                $t->addCell(1000, ['bgColor' => 'D9E1F2'])->addText('P' . $p, $fTableB, $pCenter);
                for ($d = 1; $d <= 5; $d++) {
                    $skor = (int)round($p * $d * getBobot($p, $d));
                    $bgHex = ltrim(heatmapColor($p, $d), '#');
                    $fgHex = ($skor >= 10 && $skor <= 14) ? '000000' : 'FFFFFF';
                    $codes = $cellMap[$p][$d] ?? [];
                    $cnt = count($codes);

                    $c = $t->addCell(1600, ['bgColor' => $bgHex]);
                    if ($cnt > 0) {
                        $txt = ($cnt >= 3 ? '(' . $cnt . ') ' : '') . implode(', ', $codes);
                        $c->addText($txt, ['bold' => true, 'size' => 7, 'color' => $fgHex], $pCenter);
                    }
                }
            }
        };

        $addDocxMatrix($section, $matrixAwal, $kolomKiriJudul . ' ' . $kolomKiriTw, $totalAwal);
        $section->addTextBreak(1);
        $addDocxMatrix($section, $matrixAkhir, $kolomKananJudul . ' ' . $kolomKananTw, $totalAkhir);
        $section->addTextBreak(1);

        $secSum = $section->addTable(['borderSize' => 4, 'borderColor' => 'CBD5E1', 'width' => 100, 'unit' => 'pct', 'cellMargin' => 20]);
        $secSum->addRow();
        $secSum->addCell(3400, ['bgColor' => 'D9E1F2'])->addText('Tingkat Risiko', $fTableB);
        $secSum->addCell(2200, ['bgColor' => 'D9E1F2'])->addText($kolomKiriJudul . ' ' . $kolomKiriTw, $fTableB, $pCenter);
        $secSum->addCell(2200, ['bgColor' => 'D9E1F2'])->addText($kolomKananJudul . ' ' . $kolomKananTw, $fTableB, $pCenter);
        $secSum->addCell(2200, ['bgColor' => 'D9E1F2'])->addText('Perubahan (Delta)', $fTableB, $pCenter);

        $levelsConfig = [
            'Sangat Tinggi' => 'fee2e2',
            'Tinggi'        => 'ffedd5',
            'Sedang'        => 'fef9c3',
            'Rendah'        => 'dcfce7',
            'Sangat Rendah' => 'dbeafe',
        ];
        foreach ($levelsConfig as $lvlName => $bgH) {
            $cAw = $statAwal[$lvlName] ?? 0;
            $cAk = $statAkhir[$lvlName] ?? 0;
            $delta = $cAk - $cAw;
            $dLabel = ($delta > 0) ? '+' . $delta : ($delta < 0 ? (string)$delta : '0 (Tetap)');
            $secSum->addRow();
            $secSum->addCell(3400, ['bgColor' => $bgH])->addText($lvlName, ['bold' => true, 'size' => 8]);
            $secSum->addCell(2200)->addText((string)$cAw, $fTableS, $pCenter);
            $secSum->addCell(2200)->addText((string)$cAk, $fTableS, $pCenter);
            $secSum->addCell(2200)->addText($dLabel, ['bold' => true, 'size' => 8], $pCenter);
        }
        $secSum->addRow();
        $secSum->addCell(3400, ['bgColor' => 'F8FAFC'])->addText('TOTAL RISIKO', ['bold' => true, 'size' => 8]);
        $secSum->addCell(2200, ['bgColor' => 'F8FAFC'])->addText((string)$totalAwal, $fTableB, $pCenter);
        $secSum->addCell(2200, ['bgColor' => 'F8FAFC'])->addText((string)$totalAkhir, $fTableB, $pCenter);
        $secSum->addCell(2200, ['bgColor' => 'F8FAFC'])->addText('', $fTableB, $pCenter);
        $section->addTextBreak(1);
    }
}

// ── POST: Simpan Draft Laporan (Edit Online) ───────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'save_draft') {
    requireLogin();
    requireRole('Admin', 'Risk Manager', 'Pimpinan', 'Koordinator');

    header('Content-Type: application/json; charset=UTF-8');
    $jsonOut = function (int $code, string $msg, array $extra = []) {
        http_response_code($code);
        echo json_encode(array_merge(['ok' => ($code === 200), 'message' => $msg], $extra), JSON_UNESCAPED_UNICODE);
        exit;
    };

    if (!verifyCsrf()) {
        $jsonOut(403, 'Token keamanan CSRF tidak valid.');
    }

    $rawHtml = (string)($_POST['html'] ?? '');
    if (strlen(trim($rawHtml)) < 50) {
        $jsonOut(400, 'Konten laporan kosong atau tidak valid.');
    }
    if (strlen($rawHtml) > 10 * 1024 * 1024) {
        $jsonOut(413, 'Konten laporan terlalu besar (maksimal 10MB).');
    }

    $postTahun    = trim((string)($_POST['tahun'] ?? $tahun));
    $postTriwulan = (int)($_POST['triwulan'] ?? $triwulan);
    if (!preg_match('/^\d{4}$/', $postTahun) || $postTriwulan < 1 || $postTriwulan > 4) {
        $jsonOut(400, 'Parameter tahun atau triwulan tidak valid.');
    }

    $cleanHtml = laporanSanitizeDraftHtml($rawHtml);

    $db = getDB();
    laporanEnsureDraftTable($db);

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $userIdVal = $userId > 0 ? $userId : null;

    $stmt = $db->prepare("INSERT INTO laporan_monev_draft (tahun, triwulan, konten_html, user_id, updated_at) 
                          VALUES (?, ?, ?, ?, NOW()) 
                          ON DUPLICATE KEY UPDATE konten_html = VALUES(konten_html), user_id = VALUES(user_id), updated_at = NOW()");
    if (!$stmt) {
        error_log('[manris] Prepare save_draft failed: ' . $db->error);
        $jsonOut(500, 'Gagal menyiapkan penyimpanan data: ' . $db->error);
    }
    $stmt->bind_param('sisi', $postTahun, $postTriwulan, $cleanHtml, $userIdVal);
    if (!$stmt->execute()) {
        error_log('[manris] Execute save_draft failed: ' . $stmt->error);
        $stmt->close();
        $jsonOut(500, 'Gagal menyimpan laporan ke database: ' . $stmt->error);
    }
    $stmt->close();

    $waktuSimpan = date('d/m/Y H:i:s');
    $jsonOut(200, 'Laporan berhasil disimpan ke database.', [
        'updated_at' => $waktuSimpan,
        'tahun'      => $postTahun,
        'triwulan'   => $postTriwulan,
    ]);
}

// ── POST: Reset Draft Laporan ke Data Awal ─────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'reset_draft') {
    requireLogin();
    requireRole('Admin', 'Risk Manager', 'Pimpinan', 'Koordinator');

    header('Content-Type: application/json; charset=UTF-8');
    $jsonOut = function (int $code, string $msg) {
        http_response_code($code);
        echo json_encode(['ok' => ($code === 200), 'message' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    };

    if (!verifyCsrf()) {
        $jsonOut(403, 'Token keamanan CSRF tidak valid.');
    }

    $postTahun    = trim((string)($_POST['tahun'] ?? $tahun));
    $postTriwulan = (int)($_POST['triwulan'] ?? $triwulan);

    $db = getDB();
    laporanEnsureDraftTable($db);

    $stmt = $db->prepare("DELETE FROM laporan_monev_draft WHERE (TRIM(tahun) = ? OR tahun = ?) AND (triwulan = ? OR ? = 0)");
    if ($stmt) {
        $stmt->bind_param('ssii', $postTahun, $postTahun, $postTriwulan, $postTriwulan);
        $stmt->execute();
        $stmt->close();
    }

    $jsonOut(200, 'Laporan berhasil dikembalikan ke data awal sistem.');
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
    if (strlen($editedHtml) > 10 * 1024 * 1024) {
        $jsonOut(413, 'Konten dokumen terlalu besar (maksimal 10MB).');
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

    // Cek apakah ada draft yang tersimpan di DB
    $db = getDB();
    laporanEnsureDraftTable($db);
    $draftRow = null;
    $useDraft = (($_GET['draft'] ?? '') === '1' || ($_GET['mode'] ?? '') === 'draft');
    if ($useDraft) {
        $stmt = $db->prepare("SELECT konten_html FROM laporan_monev_draft WHERE (TRIM(tahun) = ? OR tahun = ?) AND triwulan = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ssi', $tahun, $tahun, $triwulan);
            $stmt->execute();
            $draftRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    }

    if (!empty($draftRow['konten_html'])) {
        $unitKerjaList = laporanGetUnitKerjaList($db);
        $draftRow['konten_html'] = laporanUpgradeDraftHtmlWithMatriks($db, (string)$draftRow['konten_html'], $triwulan, $tahun, $unitKerjaList);
        // Konversi dari draft tersimpan via laporanMonevHtmlToDocx
        require_once __DIR__ . '/laporan_monev_html_to_docx.php';
        $phpWord = laporanMonevHtmlToDocx($draftRow['konten_html']);

        $prevDisplay = ini_set('display_errors', '0');
        $prevErrLvl  = error_reporting(E_ALL & ~E_DEPRECATED);
        ob_start();
        \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')->save('php://output');
        $docxBinary = ob_get_clean();
        if ($prevDisplay !== false) {
            ini_set('display_errors', $prevDisplay);
        }
        error_reporting($prevErrLvl);

        $triwulanLabel = ['I', 'II', 'III', 'IV'][$triwulan - 1];
        $namaFile = 'Laporan_Monev_TW' . $triwulanLabel . '_' . $tahun . '.docx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $namaFile . '"');
        header('Content-Length: ' . strlen($docxBinary));
        header('Cache-Control: max-age=0');
        header('Pragma: public');
        echo $docxBinary;
        exit;
    }

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
    $pJustify  = ['alignment' => 'both', 'indent' => ['firstLine' => 720], 'spaceAfter' => 120];
    $pNoIndent = ['alignment' => 'both', 'spaceAfter' => 120];
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
    $section->addTextBreak(3);
    $section->addText('LAPORAN MONITORING DAN EVALUASI MANAJEMEN RISIKO', $fTitle, $pCenter);
    $section->addText('BALAI BESAR LABORATORIUM KESEHATAN LINGKUNGAN', $fTitle, $pCenter);
    $section->addTextBreak(2);
    $section->addText('TRIWULAN ' . $triwulanLabel . ' TAHUN ' . $tahun, null, $pCenter);
    $section->addTextBreak(3);
    $logoPath = __DIR__ . '/../assets/img/logo.png';
    if (is_file($logoPath)) {
        $section->addImage($logoPath, ['width' => 100, 'height' => 100, 'alignment' => 'center']);
    }
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
    $tabelPetugas->addCell(5500)->addText($koordinator !== '' ? $koordinator : 'M. Edi Royandi, SKM, MPH', null, ['spaceAfter' => 60]);

    $tabelPetugas->addRow();
    $tabelPetugas->addCell(3000)->addText('Penulis', null, ['spaceAfter' => 60]);
    $tabelPetugas->addCell(400)->addText(':', null, ['spaceAfter' => 60]);
    $tabelPetugas->addCell(5500)->addText($penulis !== '' ? $penulis : 'Bramadita Kunni Fauziyyah', null, ['spaceAfter' => 60]);

    $section->addTextBreak(3);

    $tglWord = '';
    if ($tanggal !== '') {
        $tglFmt  = laporanFormatTanggal($tanggal);
        $tglWord = (stripos($tglFmt, 'Salatiga') === 0) ? $tglFmt : ('Salatiga, ' . $tglFmt);
    } else {
        $tglWord = 'Salatiga, ' . laporanFormatTanggal(date('Y-m-d'));
    }

    $section->addText($tglWord, null, $pCenter);
    $section->addText('Disahkan oleh', null, $pCenter);
    $section->addText('Kepala Balai Besar Laboratorium Kesehatan Lingkungan', null, $pCenter);

    $section->addTextBreak(4);

    $section->addText($namaKepala !== '' ? $namaKepala : 'Akhmad Saikhu, SKM, M.Sc.PH', null, $pCenter);
    $section->addText($nipKepala !== '' ? 'NIP. ' . $nipKepala : 'NIP. 196805251992031004', $fSmallNip, $pCenter);

    $section->addPageBreak();

    // ══ BAB I: PENDAHULUAN ════════════════════════════════════
    $section->addText('BAB I', $fTitle, $pHeading);
    $section->addText('PENDAHULUAN', $fTitle, $pHeading);

    $section->addText('A. Latar Belakang', $fBold, ['spaceBefore' => 160, 'spaceAfter' => 80]);
    $section->addText('Risiko adalah kemungkinan terjadinya suatu peristiwa yang berdampak negatif terhadap pencapaian sasaran organisasi. Manajemen Risiko merupakan proses yang proaktif dan berkelanjutan meliputi identifikasi, analisis, evaluasi, pengendalian, informasi komunikasi, pemantauan, dan pelaporan risiko, termasuk berbagai strategi yang dijalankan untuk mengelola Risiko dan potensinya. mengantisipasi dan menangani segala bentuk Risiko secara efektif dan efisien.', null, $pJustify);
    $section->addText('Penerapan manajemen risiko bertujuan untuk mengidentifikasi dan memitigasi sumber-sumber risiko yang berpotensi menghambat pencapaian tujuan organisasi. Selain itu, manajemen risiko juga menjadi dasar dalam pengambilan keputusan dan perencanaan strategis, serta berkontribusi dalam peningkatan kinerja organisasi. Melalui penerapan manajemen risiko yang efektif, diharapkan organisasi mampu menjaga kesinambungan pelayanan kepada pemangku kepentingan, meningkatkan efisiensi dan efektivitas pelaksanaan kegiatan, serta menghindari terjadinya pemborosan sumber daya.', null, $pJustify);
    $section->addText('Sebagai bagian dari Sistem Pengendalian Intern Pemerintah (SPIP), penerapan manajemen risiko di lingkungan instansi pemerintah telah diatur dalam Peraturan Pemerintah Nomor 60 Tahun 2008. Dalam peraturan tersebut, penilaian risiko merupakan salah satu unsur penting yang wajib dilaksanakan oleh pimpinan instansi pemerintah, yang meliputi tahapan identifikasi risiko dan analisis risiko. Selain itu, Kementerian Kesehatan Republik Indonesia juga telah menetapkan Peraturan Menteri Kesehatan Nomor 25 Tahun 2019 tentang Penerapan Manajemen Risiko Terintegrasi di Lingkungan Kementerian Kesehatan sebagai pedoman dalam pelaksanaan manajemen risiko.', null, $pJustify);
    $section->addText('Sehubungan dengan hal tersebut, diperlukan kegiatan monitoring dan evaluasi (Monev) pada pelaksanaan manajemen risiko di Balai Besar Laboratorium Kesehatan Lingkungan sebagai upaya untuk menilai efektivitas penerapan manajemen risiko yang telah dilaksanakan, memastikan kesesuaian dengan ketentuan yang berlaku, serta mengidentifikasi perbaikan yang diperlukan guna mendukung pencapaian tujuan organisasi secara optimal.', null, $pJustify);

    $section->addText('B. Dasar Hukum', $fBold, ['spaceBefore' => 160, 'spaceAfter' => 80]);
    $dasarHukum = [
        'Undang-Undang Nomor 1 Tahun 2004 tentang Perbendaharaan Negara.',
        'Peraturan Pemerintah Nomor 60 tahun 2008 tentang Sistem Pengendalian Intern Pemerintah.',
        'Peraturan Presiden Nomor 140 Tahun 2024 tentang Organisasi Kementerian Negara.',
        'Peraturan Menteri Keuangan Nomor 17/PMK.09/2019 tentang Pedoman Penerapan, Penilaian dan Reviu Pengendalian Intern Atas Pelaporan Keuangan Pemerintah Pusat (PIPK).',
        'Peraturan Kepala BPKP Nomor 5 tahun 2021 tentang Penilaian Maturitas Penyelenggaraan Sistem Pengendalian Intern Pemerintah Terintegrasi pada Kementerian/Lembaga /Pemerintah Daerah.',
        'Peraturan Menteri Kesehatan Nomor 84 tahun 2019 tentang Tata Kelola Pengawasan Intern di Lingkungan Kementerian Kesehatan.',
        'Keputusan Menteri Kesehatan Nomor HK.01.07/MENKES/1354/2024 tentang Penerapan Manajemen Risiko Terintegrasi di Lingkungan Kementerian Kesehatan.',
        'Peraturan Menteri Kesehatan Nomor 21 Tahun 2024 tentang Organisasi dan Tata Kerja Kementerian Kesehatan.',
        'Peraturan Menteri Kesehatan Nomor 27 Tahun 2023 tentang Organisasi dan tata Kerja Balai Besar Laboratorium Kesehatan Lingkungan.',
    ];
    foreach ($dasarHukum as $i => $butir) {
        $section->addText(($i + 1) . '. ' . $butir, null, ['alignment' => 'both', 'indent' => 360, 'hanging' => 360, 'spaceAfter' => 40]);
    }

    $section->addText('C. Tujuan', $fBold, ['spaceBefore' => 160, 'spaceAfter' => 80]);
    $section->addText('Monitoring dan evaluasi manajemen risiko di Balai Besar Laboratorium Kesehatan Lingkungan (BBLKL) Salatiga bertujuan:', null, $pJustify);
    $tujuan = [
        'Menilai efektivitas penerapan manajemen risiko di lingkungan organisasi.',
        'Memastikan pelaksanaan manajemen risiko telah sesuai dengan ketentuan dan peraturan yang berlaku.',
        'Mengidentifikasi risiko-risiko yang muncul serta mengevaluasi upaya mitigasi yang telah dilakukan.',
        'Mengidentifikasi kendala dan permasalahan dalam penerapan manajemen risiko sebagai bahan perbaikan berkelanjutan.',
        'Memberikan rekomendasi perbaikan guna meningkatkan kualitas penerapan manajemen risiko pada periode selanjutnya.',
    ];
    foreach ($tujuan as $i => $butir) {
        $section->addText(($i + 1) . '. ' . $butir, null, ['alignment' => 'both', 'indent' => 360, 'hanging' => 360, 'spaceAfter' => 40]);
    }
    $section->addPageBreak();

    // ══ BAB II: PELAKSANAAN PENGENDALIAN RISIKO ═══════════════
    $db = getDB();
    $allUnitData = [];
    $unitKerjaList = laporanGetUnitKerjaList($db);

    $section->addText('BAB II', $fTitle, $pHeading);
    $section->addText('PELAKSANAAN PENGENDALIAN RISIKO', $fTitle, $pHeading);

    // Lebar kolom (twips): No, Kode, Pernyataan, P, D, Nilai, Tingkat, Upaya, P, D, Nilai, Tingkat
    $wNo = 400; $wKode = 950; $wNama = 2000; $wP = 240; $wD = 240; $wNilai = 460; $wTingkat = 950; $wUpaya = 1750;
    $unitHuruf = ['A', 'B', 'C', 'D', 'E', 'F'];

    foreach ($unitKerjaList as $unitIdx => $unitKerja) {
        $rows = laporanGetDataUnit($db, $unitKerja, $tahun, $triwulan);
        $allUnitData[$unitIdx] = $rows;
        $unitNo = $unitIdx + 1;
        $huruf  = $unitHuruf[$unitIdx] ?? (string)$unitNo;
        $cleanUnitKerja = laporanCleanUnitName($unitKerja);

        $section->addText($huruf . '. ' . $cleanUnitKerja, $fBold, ['spaceBefore' => 200, 'spaceAfter' => 40]);
        $section->addText('Tabel ' . $unitNo . '. Hasil Monev Risiko ' . $cleanUnitKerja . ' Triwulan ' . $triwulan . ' Tahun ' . $tahun, ['italic' => true, 'size' => 10], ['spaceBefore' => 20, 'spaceAfter' => 80]);

        $table = $section->addTable(['borderSize' => 4, 'borderColor' => '000000', 'width' => 100, 'unit' => 'pct', 'cellMargin' => 40]);

        // Header baris 1
        $table->addRow();
        $table->addCell($wNo,    ['vMerge' => 'restart', 'bgColor' => 'D9E1F2'])->addText('No', $fTableB, $pCenter);
        $table->addCell($wKode,  ['vMerge' => 'restart', 'bgColor' => 'D9E1F2'])->addText('Kode Risiko', $fTableB, $pCenter);
        $romawiArr = ['I', 'II', 'III', 'IV'];
        $kolomKiriJudul  = ($triwulan > 1) ? 'KONDISI AKHIR' : 'KONDISI AWAL';
        $kolomKiriTw     = ($triwulan > 1) ? '(TW ' . ($romawiArr[$triwulan - 2] ?? 'I') . ')' : '(TW I)';
        $kolomKananJudul = 'KONDISI AKHIR';
        $kolomKananTw    = '(TW ' . ($romawiArr[$triwulan - 1] ?? 'I') . ')';

        $table->addCell($wNama,  ['vMerge' => 'restart', 'bgColor' => 'D9E1F2'])->addText('Pernyataan Risiko', $fTableB, $pCenter);
        $cellAwal = $table->addCell($wP + $wD + $wNilai + $wTingkat, ['gridSpan' => 4, 'bgColor' => 'D9E1F2']);
        $cellAwal->addText($kolomKiriJudul, $fTableB, $pCenter);
        $cellAwal->addText($kolomKiriTw, $fTableB, $pCenter);
        $table->addCell($wUpaya, ['vMerge' => 'restart', 'bgColor' => 'D9E1F2'])->addText('Upaya Pengendalian', $fTableB, $pCenter);
        $cellAkhir = $table->addCell($wP + $wD + $wNilai + $wTingkat, ['gridSpan' => 4, 'bgColor' => 'D9E1F2']);
        $cellAkhir->addText($kolomKananJudul, $fTableB, $pCenter);
        $cellAkhir->addText($kolomKananTw, $fTableB, $pCenter);

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
                $upayaLines = laporanFormatUpayaLines($row['upaya_pengendalian'] ?? null);

                $table->addRow();
                $table->addCell($wNo)->addText((string)($rowNo + 1), $fTable, $pCenter);
                $table->addCell($wKode)->addText((string)($row['kode_risiko'] ?? ''), $fTable, $pCenter);
                $table->addCell($wNama)->addText((string)($row['nama_risiko'] ?? ''), $fTable);
                $table->addCell($wP)->addText($row['awal_p']     !== null ? (string)$row['awal_p']     : '-', $fTable, $pCenter);
                $table->addCell($wD)->addText($row['awal_d']     !== null ? (string)$row['awal_d']     : '-', $fTable, $pCenter);
                $table->addCell($wNilai)->addText($row['awal_nilai'] !== null ? (string)round((float)$row['awal_nilai']) : '-', $fTable, $pCenter);
                $table->addCell($wTingkat, ['bgColor' => $awalBg])->addText($awalTingkat !== '' ? $awalTingkat : '-', ['size' => 9, 'bold' => true, 'color' => $awalFg], $pCenter);
                $cellUpaya = $table->addCell($wUpaya);
                foreach ($upayaLines as $upayaLine) {
                    $cellUpaya->addText($upayaLine, $fTable, ['spaceBefore' => 0, 'spaceAfter' => 20]);
                }
                $table->addCell($wP)->addText($row['akhir_p']     !== null ? (string)$row['akhir_p']     : '-', $fTable, $pCenter);
                $table->addCell($wD)->addText($row['akhir_d']     !== null ? (string)$row['akhir_d']     : '-', $fTable, $pCenter);
                $table->addCell($wNilai)->addText($row['akhir_nilai'] !== null ? (string)round((float)$row['akhir_nilai']) : '-', $fTable, $pCenter);
                $table->addCell($wTingkat, ['bgColor' => $akhirBg])->addText(($row['akhir_nilai'] !== null && $akhirTingkat !== '') ? $akhirTingkat : '-', ['size' => 9, 'bold' => true, 'color' => $akhirFg], $pCenter);
            }
        }
        $section->addTextBreak(1);
        laporanAddPerbandinganMatriksDocx($section, $rows, $triwulan, $cleanUnitKerja, $unitNo);
    }
    $section->addPageBreak();

    // ══ BAB III: HAMBATAN YANG DITEMUI ════════════════════════
    $section->addText('BAB III', $fTitle, $pHeading);
    $section->addText('HAMBATAN YANG DITEMUI', $fTitle, $pHeading);
    $section->addText('Dalam pelaksanaan pengendalian risiko pada Triwulan ' . $triwulanLabel . ' Tahun ' . $tahun . ', terdapat beberapa hambatan yang masih dihadapi oleh unit kerja, antara lain:', null, $pJustify);

    foreach ($unitKerjaList as $unitIdx => $unitKerja) {
        $unitNo      = $unitIdx + 1;
        $cleanUnitKerja = laporanCleanUnitName($unitKerja);
        $rawKendala  = laporanGetAggregat($db, $unitKerja, $tahun, $triwulan, 'kendala');
        $kendalaList = laporanPecahItemDaftar($rawKendala);

        $section->addText($unitNo . '. ' . $cleanUnitKerja, $fBold, ['spaceBefore' => 160, 'spaceAfter' => 80]);
        if (empty($kendalaList)) {
            $section->addText('Tidak ditemukan kendala dalam pelaksanaan kegiatan.', null, $pJustify);
        } else {
            $section->addText('Hambatan yang ditemui meliputi:', null, $pJustify);
            foreach ($kendalaList as $i => $kendala) {
                $prefix = laporanFormatLetterPrefix($i);
                $section->addText($prefix . $kendala, null, ['alignment' => 'both', 'indent' => 280, 'hanging' => 280, 'spaceAfter' => 40]);
            }
        }
    }
    $section->addPageBreak();

    // ══ BAB IV: PENUTUP ═══════════════════════════════════════
    $section->addText('BAB IV', $fTitle, $pHeading);
    $section->addText('PENUTUP', $fTitle, $pHeading);

    $section->addText('A. KESIMPULAN', $fBold, ['spaceBefore' => 160, 'spaceAfter' => 80]);
    foreach ($unitKerjaList as $unitIdx => $unitKerja) {
        $unitNo = $unitIdx + 1;
        $cleanUnitKerja = laporanCleanUnitName($unitKerja);
        $stat   = laporanHitungStatistik($allUnitData[$unitIdx] ?? []);

        $section->addText($unitNo . '. ' . $cleanUnitKerja, $fBold, ['spaceBefore' => 120, 'spaceAfter' => 80]);
        if ($stat['total_monev'] === 0) {
            $section->addText('Belum terdapat data monitoring dan evaluasi untuk unit kerja ini pada periode yang dipilih.', null, $pJustify);
        } else {
            $butirHuruf = ['a', 'b', 'c', 'd', 'e'];
            $butirIsi = [
                'Jumlah risiko dengan tingkat risiko “Sangat Tinggi” sebanyak ' . (int)$stat['sangat_tinggi'] . ' risiko',
                'Jumlah risiko dengan tingkat risiko “Tinggi” sebanyak ' . (int)$stat['tinggi_level'] . ' risiko',
                'Jumlah risiko dengan tingkat risiko “Sedang” sebanyak ' . (int)$stat['sedang'] . ' risiko',
                'Jumlah risiko dengan tingkat risiko “Rendah” sebanyak ' . (int)$stat['rendah'] . ' risiko',
                'Jumlah risiko dengan tingkat risiko “Sangat Rendah” sebanyak ' . (int)$stat['sangat_rendah'] . ' risiko',
            ];
            foreach ($butirIsi as $i => $butir) {
                $section->addText($butirHuruf[$i] . '. ' . $butir, null, ['alignment' => 'both', 'indent' => 360, 'hanging' => 360, 'spaceAfter' => 40]);
            }
        }
    }

    $section->addText('B. RENCANA TINDAK LANJUT', $fBold, ['spaceBefore' => 200, 'spaceAfter' => 80]);
    $section->addText('Dalam pelaksanaan pengendalian risiko pada Triwulan ' . $triwulanLabel . ' Tahun ' . $tahun . ', telah ditetapkan beberapa rencana tindak lanjut, antara lain:', null, $pJustify);

    foreach ($unitKerjaList as $unitIdx => $unitKerja) {
        $unitNo  = $unitIdx + 1;
        $cleanUnitKerja = laporanCleanUnitName($unitKerja);
        $rawRtl  = laporanGetAggregat($db, $unitKerja, $tahun, $triwulan, 'rencana_tindak_lanjut');
        $rtlList = laporanPecahItemDaftar($rawRtl);

        $section->addText($unitNo . '. ' . $cleanUnitKerja, $fBold, ['spaceBefore' => 120, 'spaceAfter' => 80]);
        if (empty($rtlList)) {
            $section->addText('Rencana Tindak Lanjut yang akan dilakukan adalah melanjutkan upaya pengendalian yang sudah direncanakan.', null, $pJustify);
        } else {
            $section->addText('Rencana tindak lanjut yang akan dilakukan meliputi:', null, $pJustify);
            foreach ($rtlList as $i => $rtl) {
                $prefix = laporanFormatLetterPrefix($i);
                $section->addText($prefix . $rtl, null, ['alignment' => 'both', 'indent' => 280, 'hanging' => 280, 'spaceAfter' => 40]);
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

    // Cek apakah ada draft laporan yang tersimpan di database
    $db = getDB();
    laporanEnsureDraftTable($db);
    $unitKerjaList = laporanGetUnitKerjaList($db);

    $draftRow = null;
    $ignoreDraft = ($_GET['ignore_draft'] ?? '') === '1';
    $explicitDraft = (($_GET['draft'] ?? '') === '1' || ($_GET['mode'] ?? '') === 'draft');

    // Draft hanya dimuat jika user secara eksplisit meminta draft (&draft=1/&mode=draft)
    // ATAU sedang dalam Mode Edit Online (&edit=1) untuk melanjutkan pengeditan manual.
    // Untuk tampilan standar / klik "Generate Laporan", SELALU render data LIVE TERBARU dari database.
    $shouldLoadDraft = ($explicitDraft || $isEdit) && !$ignoreDraft;

    $stmt = $db->prepare("SELECT konten_html, updated_at FROM laporan_monev_draft WHERE (TRIM(tahun) = ? OR tahun = ?) AND triwulan = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ssi', $tahun, $tahun, $triwulan);
        $stmt->execute();
        $draftRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    $hasSavedDraftInDb = !empty($draftRow['konten_html']);
    if ($hasSavedDraftInDb) {
        $draftRow['konten_html'] = laporanUpgradeDraftHtmlWithMatriks($db, (string)$draftRow['konten_html'], $triwulan, $tahun, $unitKerjaList);
    }
    $draftUpdatedAt = $hasSavedDraftInDb ? $draftRow['updated_at'] : null;

    // Konten yang di-render adalah draft HANYA jika shouldLoadDraft dan ada draft di DB:
    $hasSavedDraft = $shouldLoadDraft && $hasSavedDraftInDb;

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
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
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

    /* ── No-Print Toolbar & Action Buttons ──────────────────── */
    .no-print.report-toolbar {
        background: #1e3a8a;
        color: #fff;
        padding: 10px 16px;
        text-align: center;
        position: sticky;
        top: 0;
        z-index: 1000;
        box-shadow: 0 2px 10px rgba(0,0,0,.25);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-wrap: wrap;
        gap: 8px;
    }

    .no-print .btn-print {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: #fff;
        color: #1e40af;
        border: 1px solid rgba(255,255,255,.3);
        border-radius: 6px;
        padding: 8px 16px;
        font-family: Arial, sans-serif;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        text-decoration: none;
        transition: all .15s ease;
    }

    .no-print .btn-print:hover {
        background: #f1f5f9;
        transform: translateY(-1px);
        box-shadow: 0 2px 4px rgba(0,0,0,.15);
    }

    .no-print .btn-print.btn-primary-edit {
        background: #2563eb;
        color: #fff;
        border-color: #3b82f6;
    }
    .no-print .btn-print.btn-primary-edit:hover {
        background: #1d4ed8;
    }

    .no-print .btn-print.btn-save {
        background: #16a34a;
        color: #fff;
        border-color: #22c55e;
    }
    .no-print .btn-print.btn-save:hover {
        background: #15803d;
    }

    .no-print .btn-print.btn-done {
        background: #0d9488;
        color: #fff;
        border-color: #14b8a6;
    }
    .no-print .btn-print.btn-done:hover {
        background: #0f766e;
    }

    .no-print .btn-print.btn-reset {
        background: #fee2e2;
        color: #991b1b;
        border-color: #fca5a5;
    }
    .no-print .btn-print.btn-reset:hover {
        background: #fecaca;
    }

    .no-print .btn-print.btn-exit {
        background: #fff;
        color: #dc2626;
        border-color: #fca5a5;
    }
    .no-print .btn-print.btn-exit:hover {
        background: #fee2e2;
        color: #991b1b;
        transform: translateY(-1px);
        box-shadow: 0 2px 4px rgba(0,0,0,.15);
    }

    .no-print .btn-print.btn-pdf {
        background: #fff;
        color: #dc2626;
        border-color: #fca5a5;
    }
    .no-print .btn-print.btn-pdf:hover {
        background: #fee2e2;
        color: #991b1b;
        transform: translateY(-1px);
        box-shadow: 0 2px 4px rgba(0,0,0,.15);
    }

    .no-print .status-text {
        font-family: Arial, sans-serif;
        font-size: 12px;
        color: rgba(255,255,255,.95);
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-left: 10px;
    }

    /* ── Edit Mode Banner & Visual Indicator ─────────────────── */
    .edit-mode-banner {
        background: #eff6ff;
        border-bottom: 1px solid #bfdbfe;
        color: #1e40af;
        padding: 9px 20px;
        font-family: Arial, sans-serif;
        font-size: 13px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        position: sticky;
        top: 52px;
        z-index: 999;
        box-shadow: 0 1px 4px rgba(0,0,0,.08);
    }

    #editableWrapper.is-editing {
        outline: 3px dashed #3b82f6;
        outline-offset: 8px;
    }
    #editableWrapper.is-editing td,
    #editableWrapper.is-editing th,
    #editableWrapper.is-editing p,
    #editableWrapper.is-editing li,
    #editableWrapper.is-editing h1,
    #editableWrapper.is-editing h2,
    #editableWrapper.is-editing h3,
    #editableWrapper.is-editing h4 {
        cursor: text;
    }
    #editableWrapper.is-editing table td:hover {
        background-color: rgba(59, 130, 246, 0.08) !important;
    }

    /* ── Toast Notification ──────────────────────────────────── */
    .laporan-toast {
        position: fixed;
        bottom: 24px;
        right: 24px;
        background: #0f172a;
        color: #fff;
        padding: 12px 20px;
        border-radius: 8px;
        box-shadow: 0 4px 14px rgba(0,0,0,.25);
        font-family: Arial, sans-serif;
        font-size: 13px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
        opacity: 0;
        transform: translateY(20px);
        transition: opacity .25s ease, transform .25s ease;
        z-index: 99999;
        pointer-events: none;
    }
    .laporan-toast.show {
        opacity: 1;
        transform: translateY(0);
    }
    .laporan-toast i {
        color: #4ade80;
        font-size: 16px;
    }
    .laporan-toast.error i {
        color: #f87171;
    }

    @media print {
        .no-print,
        .edit-mode-banner,
        .laporan-toast {
            display: none !important;
        }
        #editableWrapper.is-editing {
            outline: none !important;
        }
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
        text-indent: 1.25cm;
    }

    .cover p,
    .pengesahan p,
    .pengesahan-subjudul p,
    .tabel-petugas-pengesahan p,
    p.table-caption,
    p.no-indent,
    .no-indent {
        text-indent: 0;
    }

    ol, ul {
        margin: 6px 0 10px 0;
        padding-left: 24px;
    }

    li {
        margin-bottom: 4px;
        text-align: justify;
    }

    ol.laporan-sublist,
    ul.laporan-sublist {
        list-style: none;
        padding-left: 0;
        margin: 6px 0 10px 0;
    }

    ol.laporan-sublist > li,
    ul.laporan-sublist > li {
        list-style: none;
        margin-bottom: 4px;
        text-align: justify;
        padding-left: 36px;
        text-indent: -36px;
    }

    ol.laporan-sublist > li .sublist-prefix,
    ul.laporan-sublist > li .sublist-prefix {
        display: inline-block;
        width: 36px;
        text-indent: 0;
        font-weight: normal;
    }

    ol.laporan-sublist.laporan-sublist-letter > li,
    ul.laporan-sublist.laporan-sublist-letter > li {
        padding-left: 24px;
        text-indent: -24px;
    }

    ol.laporan-sublist.laporan-sublist-letter > li .sublist-prefix,
    ul.laporan-sublist.laporan-sublist-letter > li .sublist-prefix {
        width: 24px;
    }

    /* ── Tables ──────────────────────────────────────────────── */
    table.laporan-table {
        border-collapse: collapse;
        width: 100%;
        max-width: 100%;
        table-layout: fixed;
        margin-bottom: 16px;
        font-size: 8.5pt;
        line-height: 1.25;
    }

    table.laporan-table th,
    table.laporan-table td {
        border: 1px solid #000;
        padding: 4px 3px;
        vertical-align: middle;
        word-wrap: break-word;
        overflow-wrap: break-word;
        word-break: normal;
        box-sizing: border-box;
    }

    table.laporan-table th {
        background-color: #d9e1f2;
        font-weight: bold;
        text-align: center;
        font-size: 8.5pt;
        line-height: 1.2;
    }

    table.laporan-table td.center {
        text-align: center;
    }

    table.laporan-table td.right {
        text-align: right;
    }

    table.laporan-table .no-col {
        width: 4%;
        text-align: center;
        white-space: nowrap;
        padding-left: 1px;
        padding-right: 1px;
    }

    table.laporan-table .kode-col {
        width: 7.5%;
        text-align: center;
        white-space: nowrap;
        padding-left: 2px;
        padding-right: 2px;
    }

    table.laporan-table .pernyataan-col {
        width: 23.5%;
    }

    table.laporan-table .upaya-col {
        width: 24%;
    }

    table.laporan-table th.pernyataan-col,
    table.laporan-table th.upaya-col {
        text-align: center;
    }

    table.laporan-table td.pernyataan-col,
    table.laporan-table td.upaya-col {
        text-align: left;
    }

    table.laporan-table .small-col {
        width: 3%;
        text-align: center;
        white-space: nowrap;
        padding-left: 1px;
        padding-right: 1px;
    }

    table.laporan-table .nilai-col {
        width: 5.5%;
        text-align: center;
        white-space: nowrap;
        padding-left: 2px;
        padding-right: 2px;
    }

    table.laporan-table th.nilai-col,
    table.laporan-table th.small-col,
    table.laporan-table th.no-col,
    table.laporan-table th.kode-col {
        white-space: nowrap;
    }

    table.laporan-table col:nth-child(1) {
        width: 4%;
    }

    table.laporan-table col:nth-child(2) {
        width: 7.5%;
    }

    table.laporan-table col:nth-child(3) {
        width: 23.5%;
    }

    table.laporan-table col:nth-child(4),
    table.laporan-table col:nth-child(5),
    table.laporan-table col:nth-child(9),
    table.laporan-table col:nth-child(10) {
        width: 3%;
    }

    table.laporan-table col:nth-child(6),
    table.laporan-table col:nth-child(11) {
        width: 5.5%;
    }

    table.laporan-table col:nth-child(7),
    table.laporan-table col:nth-child(12) {
        width: 9%;
    }

    table.laporan-table col:nth-child(8) {
        width: 24%;
    }

    table.laporan-table .tingkat-col {
        width: 9%;
        text-align: center;
        font-weight: 600;
        font-size: 8pt;
        line-height: 1.15;
    }

    /* ── Peta Matriks Risiko 5x5 Bab II ─────────────────────── */
    .matriks-perbandingan-wrap {
        margin: 16px 0 28px 0;
        page-break-inside: avoid;
        break-inside: avoid;
    }

    .matriks-grid-container {
        display: flex;
        gap: 14px;
        justify-content: space-between;
        margin-bottom: 10px;
    }

    .matriks-col {
        flex: 1;
        min-width: 0;
    }

    table.matriks-5x5-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        font-size: 7.5pt;
        border: 1px solid #000;
        background: #fff;
        margin-bottom: 0;
    }

    table.matriks-5x5-table th,
    table.matriks-5x5-table td {
        border: 1px solid #000;
        text-align: center;
        vertical-align: middle;
        padding: 2px;
        box-sizing: border-box;
    }

    table.matriks-5x5-table th {
        background: #d9e1f2;
        color: #1e293b;
        font-size: 7pt;
        font-weight: bold;
    }

    table.matriks-5x5-table td.heatmap-cell {
        height: 40px;
        line-height: 1.15;
    }

    table.matriks-5x5-table td.heatmap-cell > div[style*="font-size:7.5pt"],
    table.matriks-5x5-table td.heatmap-cell > div[style*="font-size: 7.5pt"] {
        display: none !important;
    }

    .matriks-legend-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        padding: 6px 10px;
        margin-bottom: 8px;
        font-size: 7.5pt;
        line-height: 1.4;
    }

    table.matriks-summary-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 8pt;
        table-layout: fixed;
        border: 1px solid #cbd5e1;
        margin-bottom: 8px;
    }

    table.matriks-summary-table th,
    table.matriks-summary-table td {
        border: 1px solid #cbd5e1;
        padding: 3px 6px;
        box-sizing: border-box;
    }

    table.matriks-summary-table th {
        background: #d9e1f2;
        font-weight: bold;
    }

    @media (max-width: 680px) {
        .matriks-grid-container {
            flex-direction: column;
        }
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

    .cover .logo-wrap {
        text-align: center;
        margin-top: 10px;
    }

    .cover .logo-wrap img {
        height: 100px;
        width: auto;
        display: inline-block;
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

        .matriks-perbandingan-wrap {
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }

        .matriks-grid-container {
            display: flex !important;
            flex-direction: row !important;
            gap: 12px !important;
        }

        .matriks-col {
            flex: 1 !important;
        }

        table.matriks-5x5-table th,
        table.matriks-5x5-table td {
            font-size: 7pt !important;
            padding: 2px 1px !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        table.matriks-5x5-table td.heatmap-cell {
            height: 34px !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        table.matriks-5x5-table td.heatmap-cell > div[style*="font-size:7.5pt"],
        table.matriks-5x5-table td.heatmap-cell > div[style*="font-size: 7.5pt"] {
            display: none !important;
        }

        table.matriks-summary-table th,
        table.matriks-summary-table td {
            font-size: 7.5pt !important;
            padding: 2px 4px !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        table.laporan-table .no-col {
            width: 4% !important;
            white-space: nowrap !important;
            padding-left: 1px !important;
            padding-right: 1px !important;
        }

        table.laporan-table .kode-col {
            width: 7.5% !important;
            white-space: nowrap !important;
            padding-left: 2px !important;
            padding-right: 2px !important;
        }

        table.laporan-table .small-col {
            width: 3% !important;
            white-space: nowrap !important;
            padding-left: 1px !important;
            padding-right: 1px !important;
        }

        table.laporan-table .nilai-col {
            width: 5.5% !important;
            white-space: nowrap !important;
            padding-left: 2px !important;
            padding-right: 2px !important;
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
  <div class="no-print report-toolbar">
    <button type="button" class="btn-print" id="btnCetak" onclick="window.print()" title="Cetak Dokumen">
      <i class="fas fa-print" aria-hidden="true"></i>
      Cetak
    </button>
    <button type="button" class="btn-print btn-pdf" id="btnUnduhPdfReport" onclick="window.print()" title="Download / Simpan sebagai file PDF">
      <i class="fas fa-file-pdf" aria-hidden="true"></i>
      Unduh PDF (.pdf)
    </button>
    <button type="button" class="btn-print" id="btnUnduhWord" title="Unduh Dokumen Word">
      <i class="fas fa-file-word" aria-hidden="true"></i>
      Unduh Word (.docx)
    </button>
    <button type="button" class="btn-print btn-primary-edit" id="btnToggleEdit">
      <i class="fas fa-pen-to-square" aria-hidden="true"></i>
      <span id="textToggleEdit">Edit Online</span>
    </button>
    <button type="button" class="btn-print btn-save" id="btnSimpanDraft" style="display:none;">
      <i class="fas fa-floppy-disk" aria-hidden="true"></i>
      Simpan
    </button>
    <?php if ($hasSavedDraft): ?>
      <a href="<?= APP_URL ?>/?page=laporan_monev&generate=1&tahun=<?= urlencode($tahun) ?>&triwulan=<?= $triwulan ?>&ignore_draft=1" class="btn-print btn-outline" style="border-color:#38bdf8; color:#0284c7;" title="Tampilkan data aplikasi saat ini">
        <i class="fas fa-arrows-rotate"></i> Beralih ke Data Asli
      </a>
      <button type="button" class="btn-print btn-reset" id="btnResetDraft" title="Hapus draft dari database dan kembali ke data asli">
        <i class="fas fa-rotate-left"></i> Reset Draft
      </button>
    <?php elseif ($hasSavedDraftInDb): ?>
      <a href="<?= APP_URL ?>/?page=laporan_monev&generate=1&tahun=<?= urlencode($tahun) ?>&triwulan=<?= $triwulan ?>&draft=1" class="btn-print btn-outline" style="border-color:#fbbf24; color:#d97706;" title="Buka versi draft yang pernah diedit online">
        <i class="fas fa-file-pen"></i> Buka Draft Tersimpan
      </a>
      <button type="button" class="btn-print btn-reset" id="btnHapusDraftDb" title="Hapus draft agar tidak menyimpan versi lama">
        <i class="fas fa-trash-can"></i> Hapus Draft
      </button>
    <?php endif; ?>

    <button type="button" class="btn-print btn-exit" id="btnKeluar" title="Keluar / Tutup Laporan">
      <i class="fas fa-right-from-bracket" aria-hidden="true"></i>
      Keluar
    </button>
    <span class="status-text" id="statusDraft">
      <?php if ($hasSavedDraft): ?>
        <i class="fas fa-pen-to-square" style="color:#fbbf24;"></i> Menampilkan Draft Tersimpan (<?= date('d/m/Y H:i', strtotime($draftUpdatedAt)) ?>)
      <?php else: ?>
        <i class="fas fa-circle-check" style="color:#4ade80;"></i> Data Terbaru Aplikasi (Sinkron Otomatis)
      <?php endif; ?>
    </span>
  </div>

  <div class="edit-mode-banner no-print" id="editModeBanner" style="display:none;">
    <i class="fas fa-pen-to-square"></i>
    <span><strong>Mode Edit Online Aktif:</strong> Anda dapat mengklik dan mengubah teks atau tabel langsung pada laporan. Klik tombol <strong>Simpan</strong> di toolbar atas setelah selesai mengedit.</span>
  </div>
<?php endif; ?>

  <div class="page-wrapper" id="editableWrapper">
<?php if ($hasSavedDraft): ?>
    <?= $draftRow['konten_html'] ?>
<?php else: ?>

    <!-- ── Halaman 1: Cover ──────────────────────────────────── -->
    <div class="cover doc-page page-break">
      <div class="doc-label">LAPORAN MONITORING DAN EVALUASI MANAJEMEN RISIKO</div>
      <div class="instansi-name">BALAI BESAR LABORATORIUM KESEHATAN LINGKUNGAN</div>
      <hr class="cover-divider">
      <div class="periode-label">TRIWULAN <?= xss($triwulanLabel) ?> TAHUN <?= xss($tahun) ?></div>
      <div class="logo-wrap">
        <img src="<?= APP_URL ?>/assets/img/logo.png" alt="Logo">
      </div>
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
          <td class="col-val"><?= xss($koordinator !== '' ? $koordinator : 'M. Edi Royandi, SKM, MPH') ?></td>
        </tr>
        <tr>
          <td class="col-label">Penulis</td>
          <td class="col-sep">:</td>
          <td class="col-val"><?= xss($penulis !== '' ? $penulis : 'Bramadita Kunni Fauziyyah') ?></td>
        </tr>
      </table>

      <?php
        if ($tanggal !== '') {
            $tglFmt         = laporanFormatTanggal($tanggal);
            $displayTanggal = (stripos($tglFmt, 'Salatiga') === 0) ? $tglFmt : ('Salatiga, ' . $tglFmt);
            $tanggalHtml    = xss($displayTanggal);
        } else {
            $tanggalHtml    = xss('Salatiga, ' . laporanFormatTanggal(date('Y-m-d')));
        }
      ?>
      <div class="pengesahan-ttd-center">
        <p><?= $tanggalHtml ?></p>
        <p>Disahkan oleh</p>
        <p>Kepala Balai Besar Laboratorium Kesehatan Lingkungan</p>
        <div class="ttd-space"></div>
        <p class="ttd-nama"><?= xss($namaKepala !== '' ? $namaKepala : 'Akhmad Saikhu, SKM, M.Sc.PH') ?></p>
        <p class="ttd-nip"><?= xss($nipKepala !== '' ? ('NIP. ' . $nipKepala) : 'NIP. 196805251992031004') ?></p>
      </div>
    </div>

    <!-- ── BAB I: Pendahuluan ────────────────────────────────── -->
    <div class="bab doc-page" id="bab1">
      <h2 class="bab-heading">BAB I<br>PENDAHULUAN</h2>

      <h3 class="subbab-heading">A. Latar Belakang</h3>
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
        dan efektivitas pelaksanaan kegiatan, serta menghindari terjadinya pemborosan sumber daya.
      </p>
      <p>
        Sebagai bagian dari Sistem Pengendalian Intern Pemerintah (SPIP), penerapan manajemen risiko di lingkungan instansi pemerintah telah diatur dalam Peraturan Pemerintah Nomor 60 Tahun 2008. Dalam peraturan tersebut, penilaian risiko merupakan salah satu unsur penting yang wajib dilaksanakan oleh pimpinan instansi pemerintah, yang meliputi tahapan identifikasi risiko dan analisis risiko. Selain itu, Kementerian Kesehatan Republik Indonesia juga telah menetapkan Peraturan Menteri Kesehatan Nomor 25 Tahun 2019 tentang Penerapan Manajemen Risiko Terintegrasi di Lingkungan Kementerian Kesehatan sebagai pedoman dalam pelaksanaan manajemen risiko.
      </p>
      <p>
        Sehubungan dengan hal tersebut, diperlukan kegiatan monitoring dan evaluasi (Monev) pada pelaksanaan manajemen risiko di Balai Besar Laboratorium Kesehatan Lingkungan sebagai upaya untuk menilai efektivitas penerapan manajemen risiko yang telah dilaksanakan, memastikan kesesuaian dengan ketentuan yang berlaku, serta mengidentifikasi perbaikan yang diperlukan guna mendukung pencapaian tujuan organisasi secara optimal.
      </p>

      <h3 class="subbab-heading">B. Dasar Hukum</h3>
      <ol>
        <li>Undang-Undang Nomor 1 Tahun 2004 tentang Perbendaharaan Negara</li>
        <li>Peraturan Pemerintah Nomor 60 tahun 2008 tentang Sistem Pengendalian Intern Pemerintah.</li>
        <li>Peraturan Presiden Nomor 140 Tahun 2024 tentang Organisasi Kementerian Negara.</li>
        <li>Peraturan Menteri Keuangan Nomor 17/PMK.09/2019 tentang Pedoman Penerapan, Penilaian dan Reviu Pengendalian Intern Atas Pelaporan Keuangan Pemerintah Pusat (PIPK).</li>
        <li>Peraturan Kepala BPKP Nomor 5 tahun 2021 tentang Penilaian Maturitas Penyelenggaraan Sistem Pengendalian Intern Pemerintah Terintegrasi pada Kementerian/Lembaga /Pemerintah Daerah.</li>
        <li>Peraturan Menteri Kesehatan Nomor 84 tahun 2019 tentang Tata Kelola Pengawasan Intern di Lingkungan Kementerian Kesehatan</li>
        <li>Keputusan Menteri Kesehatan Nomor HK.01.07/MENKES/1354/2024 tentang Penerapan Manajemen Risiko Terintegrasi di Lingkungan Kementerian Kesehatan.</li>
        <li>Peraturan Menteri Kesehatan Nomor 21 Tahun 2024 tentang Organisasi dan Tata Kerja Kementerian Kesehatan.</li>
        <li>Peraturan Menteri Kesehatan Nomor 27 Tahun 2023 tentang Organisasi dan tata Kerja Balai Besar Laboratorium Kesehatan Lingkungan</li>
      </ol>

      <h3 class="subbab-heading">C. Tujuan</h3>
      <p>Monitoring dan evaluasi manajemen risiko di Balai Besar Laboratorium Kesehatan Lingkungan (BBLKL) Salatiga bertujuan:</p>
      <ol>
        <li>Menilai efektivitas penerapan manajemen risiko di lingkungan organisasi.</li>
        <li>Memastikan pelaksanaan manajemen risiko telah sesuai dengan ketentuan dan peraturan yang berlaku.</li>
        <li>Mengidentifikasi risiko-risiko yang muncul serta mengevaluasi upaya mitigasi yang telah dilakukan</li>
        <li>Mengidentifikasi kendala dan permasalahan dalam penerapan manajemen risiko sebagai bahan perbaikan berkelanjutan.</li>
        <li>Memberikan rekomendasi perbaikan guna meningkatkan kualitas penerapan manajemen risiko pada periode selanjutnya</li>
      </ol>
    </div><!-- /#bab1 -->

<?php
    // ── BAB II: Pelaksanaan Pengendalian Risiko Per Unit Kerja ──
    $db = getDB();
    $unitKerjaList = laporanGetUnitKerjaList($db);
    ?>

    <!-- ── BAB II ─────────────────────────────────────────────── -->
    <div class="bab doc-page" id="bab2">
      <h2 class="bab-heading">BAB II<br>PELAKSANAAN PENGENDALIAN RISIKO</h2>

<?php
    $allUnitData = [];
    $unitLetters = ['A', 'B', 'C', 'D', 'E', 'F'];
    foreach ($unitKerjaList as $unitIdx => $unitKerja):
        $rows = laporanGetDataUnit($db, $unitKerja, $tahun, $triwulan);
        $allUnitData[$unitIdx] = $rows;
        $unitNo = $unitIdx + 1;
        $huruf  = $unitLetters[$unitIdx] ?? (string)$unitNo;
        $cleanUnitKerja = laporanCleanUnitName($unitKerja);
?>
      <h3 class="subbab-heading"><?= $huruf ?>. <?= xss($cleanUnitKerja) ?></h3>
      <p class="table-caption" style="font-style:italic; margin-bottom:8px; font-size:10pt; text-indent:0;">Tabel <?= $unitNo ?>. Hasil Monev Risiko <?= xss($cleanUnitKerja) ?> Triwulan <?= $triwulan ?> Tahun <?= xss($tahun) ?></p>

      <table class="laporan-table">
        <colgroup>
          <col style="width:4%;">
          <col style="width:7.5%;">
          <col style="width:23.5%;">
          <col style="width:3%;">
          <col style="width:3%;">
          <col style="width:5.5%;">
          <col style="width:9%;">
          <col style="width:24%;">
          <col style="width:3%;">
          <col style="width:3%;">
          <col style="width:5.5%;">
          <col style="width:9%;">
        </colgroup>
        <thead>
<?php
    $romawiArr = ['I', 'II', 'III', 'IV'];
    $kolomKiriJudul  = ($triwulan > 1) ? 'KONDISI AKHIR' : 'KONDISI AWAL';
    $kolomKiriTw     = ($triwulan > 1) ? '(TW ' . ($romawiArr[$triwulan - 2] ?? 'I') . ')' : '(TW I)';
    $kolomKananJudul = 'KONDISI AKHIR';
    $kolomKananTw    = '(TW ' . ($romawiArr[$triwulan - 1] ?? 'I') . ')';
?>
          <tr>
            <th rowspan="2" class="no-col">No</th>
            <th rowspan="2" class="kode-col">Kode<br>Risiko</th>
            <th rowspan="2" class="pernyataan-col">Pernyataan Risiko</th>
            <th colspan="4"><?= $kolomKiriJudul ?><br><?= $kolomKiriTw ?></th>
            <th rowspan="2" class="upaya-col">Upaya Pengendalian</th>
            <th colspan="4"><?= $kolomKananJudul ?><br><?= $kolomKananTw ?></th>
          </tr>
          <tr>
            <th class="small-col">P</th>
            <th class="small-col">D</th>
            <th class="nilai-col" style="white-space:nowrap;">Nilai</th>
            <th class="tingkat-col">Tingkat<br>Risiko</th>
            <th class="small-col">P</th>
            <th class="small-col">D</th>
            <th class="nilai-col" style="white-space:nowrap;">Nilai</th>
            <th class="tingkat-col">Tingkat<br>Risiko</th>
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
                $awalNilai = ($row['awal_nilai'] !== null) ? xss((string)round((float)$row['awal_nilai'])) : '-';

                // Kondisi akhir — dari monev_triwulan (LEFT JOIN → NULL jika tidak ada)
                $akhirP     = ($row['akhir_p']     !== null) ? xss((string)$row['akhir_p'])     : '-';
                $akhirD     = ($row['akhir_d']     !== null) ? xss((string)$row['akhir_d'])     : '-';
                $akhirNilai = ($row['akhir_nilai'] !== null) ? xss((string)round((float)$row['akhir_nilai'])) : '-';
                $akhirTingkatDisplay = ($row['akhir_nilai'] !== null && $akhirTingkat !== '')
                    ? xss($akhirTingkat)
                    : '-';

                // Upaya pengendalian — pisahkan tiap butir ke baris baru (<br>)
                $upayaHtml = laporanFormatUpayaHtml($row['upaya_pengendalian'] ?? null);
?>
          <tr>
            <td class="center"><?= $rowNo + 1 ?></td>
            <td class="center"><?= xss((string)($row['kode_risiko'] ?? '')) ?></td>
            <td><?= xss((string)($row['nama_risiko'] ?? '')) ?></td>
            <td class="small-col center"><?= $awalP ?></td>
            <td class="small-col center"><?= $awalD ?></td>
            <td class="nilai-col center"><?= $awalNilai ?></td>
            <td class="tingkat-col center" style="background-color:<?= laporanRisikoBg($awalTingkat) ?>; color:<?= laporanRisikoColor($awalTingkat) ?>;">
              <?= ($awalTingkat !== '') ? xss($awalTingkat) : '-' ?>
            </td>
            <td><?= $upayaHtml ?></td>
            <td class="small-col center"><?= $akhirP ?></td>
            <td class="small-col center"><?= $akhirD ?></td>
            <td class="nilai-col center"><?= $akhirNilai ?></td>
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

      <?= laporanRenderPerbandinganMatriks5x5($rows, $triwulan, $tahun, $cleanUnitKerja, $unitNo) ?>

<?php
    endforeach;
?>
    </div><!-- /#bab2 -->


    <!-- ── BAB III ────────────────────────────────────────────── -->
    <div class="bab doc-page" id="bab3">
      <h2 class="bab-heading">BAB III<br>HAMBATAN YANG DITEMUI</h2>

      <p>Dalam pelaksanaan pengendalian risiko pada Triwulan <?= xss($triwulanLabel) ?> Tahun <?= xss($tahun) ?>, terdapat beberapa hambatan yang masih dihadapi oleh unit kerja, antara lain:</p>

<?php
    foreach ($unitKerjaList as $unitIdx => $unitKerja):
        $unitNo      = $unitIdx + 1;
        $cleanUnitKerja = laporanCleanUnitName($unitKerja);
        $rawKendala  = laporanGetAggregat($db, $unitKerja, $tahun, $triwulan, 'kendala');
        $kendalaList = laporanPecahItemDaftar($rawKendala);
?>
      <h3 class="subbab-heading"><?= $unitNo ?>. <?= xss($cleanUnitKerja) ?></h3>

<?php if (empty($kendalaList)): ?>
      <p>Tidak ditemukan kendala dalam pelaksanaan kegiatan.</p>
<?php else: ?>
      <p>Hambatan yang ditemui meliputi:</p>
      <ol class="laporan-sublist laporan-sublist-letter">
<?php   foreach ($kendalaList as $i => $kendala):
            $prefix = laporanFormatLetterPrefix($i);
?>
        <li><span class="sublist-prefix"><?= xss($prefix) ?></span><span class="sublist-text"><?= xss($kendala) ?></span></li>
<?php   endforeach; ?>
      </ol>
<?php endif; ?>

<?php endforeach; ?>
    </div><!-- /#bab3 -->

    <!-- ── BAB IV ────────────────────────────────────────────── -->
    <div class="bab doc-page" id="bab4">
      <h2 class="bab-heading">BAB IV<br>PENUTUP</h2>

      <h3 class="subbab-heading">A. KESIMPULAN</h3>

<?php
    foreach ($unitKerjaList as $unitIdx => $unitKerja):
        $unitNo   = $unitIdx + 1;
        $cleanUnitKerja = laporanCleanUnitName($unitKerja);
        $rowsBab4 = $allUnitData[$unitIdx] ?? [];
        $stat     = laporanHitungStatistik($rowsBab4);
?>
      <h4 class="unit-heading"><?= $unitNo ?>. <?= xss($cleanUnitKerja) ?></h4>

<?php if ($stat['total_monev'] === 0): ?>
      <p>Belum terdapat data monitoring dan evaluasi untuk unit kerja ini pada periode yang dipilih.</p>
<?php else: ?>
      <ol type="a">
        <li>Jumlah risiko dengan tingkat risiko &ldquo;Sangat Tinggi&rdquo; sebanyak <strong><?= (int)$stat['sangat_tinggi'] ?></strong> risiko</li>
        <li>Jumlah risiko dengan tingkat risiko &ldquo;Tinggi&rdquo; sebanyak <strong><?= (int)$stat['tinggi_level'] ?></strong> risiko</li>
        <li>Jumlah risiko dengan tingkat risiko &ldquo;Sedang&rdquo; sebanyak <strong><?= (int)$stat['sedang'] ?></strong> risiko</li>
        <li>Jumlah risiko dengan tingkat risiko &ldquo;Rendah&rdquo; sebanyak <strong><?= (int)$stat['rendah'] ?></strong> risiko</li>
        <li>Jumlah risiko dengan tingkat risiko &ldquo;Sangat Rendah&rdquo; sebanyak <strong><?= (int)$stat['sangat_rendah'] ?></strong> risiko</li>
      </ol>
<?php endif; ?>

<?php endforeach; ?>

      <h3 class="subbab-heading">B. RENCANA TINDAK LANJUT</h3>
      <p>Dalam pelaksanaan pengendalian risiko pada Triwulan <?= xss($triwulanLabel) ?> Tahun <?= xss($tahun) ?>, telah ditetapkan beberapa rencana tindak lanjut, antara lain:</p>

<?php
    foreach ($unitKerjaList as $unitIdx => $unitKerja):
        $unitNo  = $unitIdx + 1;
        $cleanUnitKerja = laporanCleanUnitName($unitKerja);
        $rawRtl  = laporanGetAggregat($db, $unitKerja, $tahun, $triwulan, 'rencana_tindak_lanjut');
        $rtlList = laporanPecahItemDaftar($rawRtl);
?>
      <h4 class="unit-heading"><?= $unitNo ?>. <?= xss($cleanUnitKerja) ?></h4>

<?php if (empty($rtlList)): ?>
      <p>Rencana Tindak Lanjut yang akan dilakukan adalah melanjutkan upaya pengendalian yang sudah direncanakan.</p>
<?php else: ?>
      <p>Rencana tindak lanjut yang akan dilakukan meliputi:</p>
      <ol class="laporan-sublist laporan-sublist-letter">
<?php   foreach ($rtlList as $i => $rtl):
            $prefix = laporanFormatLetterPrefix($i);
?>
        <li><span class="sublist-prefix"><?= xss($prefix) ?></span><span class="sublist-text"><?= xss($rtl) ?></span></li>
<?php   endforeach; ?>
      </ol>
<?php endif; ?>

<?php endforeach; ?>
    </div><!-- /#bab4 -->
<?php endif; ?>

  </div><!-- /.page-wrapper -->

<?php if (!$isWordDoc): ?>
  <script>
  (function () {
      var wrapper = document.getElementById('editableWrapper');
      if (!wrapper) return;

      var btnToggle = document.getElementById('btnToggleEdit');
      var btnSimpan = document.getElementById('btnSimpanDraft');
      var btnReset  = document.getElementById('btnResetDraft');
      var btnUnduh  = document.getElementById('btnUnduhWord');
      var statusText = document.getElementById('statusDraft');
      var editBanner = document.getElementById('editModeBanner');

      var tahun     = <?= json_encode((string)$tahun) ?>;
      var triwulan  = <?= json_encode((int)$triwulan) ?>;
      var csrfToken = <?= json_encode(csrfToken()) ?>;
      var draftKey  = 'laporan_monev_draft_' + tahun + '_' + triwulan;

      var isEditMode = <?= $isEdit ? 'true' : 'false' ?>;
      var hasUnsavedChanges = false;
      var autoSaveTimer = null;

      function showToast(msg, isError) {
          var toast = document.createElement('div');
          toast.className = 'laporan-toast' + (isError ? ' error' : '');
          toast.innerHTML = (isError ? '<i class="fas fa-circle-exclamation"></i> ' : '<i class="fas fa-check-circle"></i> ') + msg;
          document.body.appendChild(toast);
          setTimeout(function () { toast.classList.add('show'); }, 20);
          setTimeout(function () {
              toast.classList.remove('show');
              setTimeout(function () { toast.remove(); }, 300);
          }, 3200);
      }

      function setEditMode(active) {
          isEditMode = active;
          if (active) {
              wrapper.setAttribute('contenteditable', 'true');
              wrapper.setAttribute('spellcheck', 'false');
              wrapper.classList.add('is-editing');
              if (editBanner) editBanner.style.display = 'flex';
              if (btnSimpan) btnSimpan.style.display = 'inline-flex';
              if (btnReset) btnReset.style.display = 'inline-flex';
              btnToggle.classList.remove('btn-primary-edit');
              btnToggle.classList.add('btn-done');
              btnToggle.innerHTML = '<i class="fas fa-check"></i> Selesai Edit';
              statusText.innerHTML = hasUnsavedChanges
                  ? '<i class="fas fa-circle-exclamation" style="color:#fbbf24;"></i> Ada perubahan belum disimpan'
                  : '<i class="fas fa-pen" style="color:#60a5fa;"></i> Mode edit aktif (siap diedit)';
          } else {
              wrapper.removeAttribute('contenteditable');
              wrapper.classList.remove('is-editing');
              if (editBanner) editBanner.style.display = 'none';
              if (btnSimpan) btnSimpan.style.display = 'none';
              btnToggle.classList.remove('btn-done');
              btnToggle.classList.add('btn-primary-edit');
              btnToggle.innerHTML = '<i class="fas fa-pen-to-square"></i> <span id="textToggleEdit">Edit Online</span>';
          }
      }

      // Tombol Edit Online / Selesai Edit
      if (btnToggle) {
          btnToggle.addEventListener('click', function () {
              if (!isEditMode) {
                  setEditMode(true);
              } else {
                  if (hasUnsavedChanges) {
                      saveDraft(function () {
                          setEditMode(false);
                      });
                  } else {
                      setEditMode(false);
                  }
              }
          });
      }

      // Deteksi perubahan isi dokumen
      wrapper.addEventListener('input', function () {
          hasUnsavedChanges = true;
          statusText.innerHTML = '<i class="fas fa-circle-exclamation" style="color:#fbbf24;"></i> Ada perubahan belum disimpan';

          // Autosave ke localStorage sebagai cadangan instan (debounce 1.5s)
          clearTimeout(autoSaveTimer);
          autoSaveTimer = setTimeout(function () {
              try { localStorage.setItem(draftKey, wrapper.innerHTML); } catch (e) {}
          }, 1500);
      });

      // Fungsi simpan draft ke database server
      function saveDraft(callback) {
          if (btnSimpan) {
              btnSimpan.disabled = true;
              btnSimpan.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';
          }
          statusText.innerHTML = '<i class="fas fa-spinner fa-spin" style="color:#93c5fd;"></i> Menyimpan perubahan...';

          var fd = new FormData();
          fd.append('action', 'save_draft');
          fd.append('csrf_token', csrfToken);
          fd.append('tahun', tahun);
          fd.append('triwulan', triwulan);
          fd.append('html', wrapper.innerHTML);

          fetch('<?= APP_URL ?>/?page=laporan_monev', {
              method: 'POST',
              body: fd,
              credentials: 'same-origin'
          }).then(function (res) {
              return res.json().then(function (j) {
                  if (!res.ok || !j.ok) {
                      throw new Error(j.message || j.error || 'Gagal menyimpan.');
                  }
                  return j;
              });
          }).then(function (res) {
              hasUnsavedChanges = false;
              try { localStorage.setItem(draftKey, wrapper.innerHTML); } catch (e) {}
              statusText.innerHTML = '<i class="fas fa-check-circle" style="color:#4ade80;"></i> Tersimpan (' + res.updated_at + ')';
              if (btnReset) btnReset.style.display = 'inline-flex';
              showToast('Laporan berhasil disimpan ke sistem!');
              if (typeof callback === 'function') callback();
          }).catch(function (err) {
              statusText.innerHTML = '<i class="fas fa-triangle-exclamation" style="color:#f87171;"></i> Gagal menyimpan';
              showToast('Gagal menyimpan: ' + err.message, true);
          }).finally(function () {
              if (btnSimpan) {
                  btnSimpan.disabled = false;
                  btnSimpan.innerHTML = '<i class="fas fa-floppy-disk"></i> Simpan';
              }
          });
      }

      if (btnSimpan) {
          btnSimpan.addEventListener('click', function () {
              saveDraft();
          });
      }

      // Tombol Unduh Word (.docx)
      if (btnUnduh) {
          btnUnduh.addEventListener('click', function () {
              btnUnduh.disabled = true;
              btnUnduh.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Membuat Word...';

              var fd = new FormData();
              fd.append('action', 'download_docx');
              fd.append('csrf_token', csrfToken);
              fd.append('triwulan', triwulan);
              fd.append('tahun', tahun);
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
                  a.download = <?= json_encode('Laporan_Monev_TW' . ['I','II','III','IV'][$triwulan-1] . '_' . $tahun . '.docx') ?>;
                  document.body.appendChild(a);
                  a.click();
                  setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 2000);
              }).catch(function (err) {
                  alert('Gagal mengunduh: ' + err.message);
              }).finally(function () {
                  btnUnduh.disabled = false;
                  btnUnduh.innerHTML = '<i class="fas fa-file-word"></i> Unduh Word (.docx)';
              });
          });
      }

      // Tombol Reset ke Data Asli / Hapus Draft
      var handleResetDraft = function (btnElement) {
          if (!confirm('Apakah Anda yakin ingin menghapus draft tersimpan dan kembali ke data asli sistem? Data draft yang sudah disimpan akan dihapus.')) return;
          if (btnElement) {
              btnElement.disabled = true;
              btnElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menghapus...';
          }

          var fd = new FormData();
          fd.append('action', 'reset_draft');
          fd.append('csrf_token', csrfToken);
          fd.append('tahun', tahun);
          fd.append('triwulan', triwulan);

          fetch('<?= APP_URL ?>/?page=laporan_monev', {
              method: 'POST',
              body: fd,
              credentials: 'same-origin'
          }).then(function (res) {
              return res.json().then(function (j) {
                  if (!res.ok || !j.ok) throw new Error(j.message || 'Gagal reset');
                  return j;
              });
          }).then(function () {
              try { localStorage.removeItem(draftKey); } catch (e) {}
              var url = new URL(window.location.href);
              url.searchParams.delete('edit');
              url.searchParams.delete('draft');
              url.searchParams.delete('mode');
              window.location.href = url.toString();
          }).catch(function (err) {
              alert('Gagal mengembalikan data: ' + err.message);
              if (btnElement) {
                  btnElement.disabled = false;
                  btnElement.innerHTML = '<i class="fas fa-rotate-left"></i> Reset Draft';
              }
          });
      };

      if (btnReset) {
          btnReset.addEventListener('click', function () {
              handleResetDraft(btnReset);
          });
      }
      var btnHapusDb = document.getElementById('btnHapusDraftDb');
      if (btnHapusDb) {
          btnHapusDb.addEventListener('click', function () {
              handleResetDraft(btnHapusDb);
          });
      }

      // Tombol Keluar / Exit
      var btnKeluar = document.getElementById('btnKeluar');
      if (btnKeluar) {
          btnKeluar.addEventListener('click', function () {
              if (hasUnsavedChanges) {
                  if (!confirm('Ada perubahan yang belum disimpan. Yakin ingin keluar?')) {
                      return;
                  }
              }
              // Coba tutup tab jika dibuka via window.open (tab baru)
              window.close();
              // Jika tidak menutup otomatis (karena pembatasan browser atau dibuka di tab yang sama),
              // navigasikan kembali ke halaman form Laporan Monev
              setTimeout(function () {
                  window.location.href = '<?= APP_URL ?>/?page=laporan_monev';
              }, 150);
          });
      }

      window.addEventListener('beforeunload', function (e) {
          if (hasUnsavedChanges) {
              e.preventDefault();
              e.returnValue = '';
          }
      });

      // Auto start edit mode jika dibuka dengan parameter edit=1
      if (isEditMode) {
          setEditMode(true);
      }

      // Auto trigger print/simpan PDF jika dibuka dengan autoprint=1
      if (new URLSearchParams(window.location.search).get('autoprint') === '1') {
          setTimeout(function () {
              window.print();
          }, 600);
      }
  })();
  </script>
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
    <div class="risiko-hero profil-risiko-hero" style="background:linear-gradient(115deg,#1e3a5f 0%,#1e40af 55%,#0891b2 100%);">
      <div class="risiko-hero-copy">
        <div class="risiko-eyebrow"><i class="fas fa-file-medical-alt"></i> Laporan</div>
        <h1 class="page-title" style="color:#fff">Laporan Monev Manajemen Risiko</h1>
        <p class="page-sub" style="color:rgba(255,255,255,.85)">Generate dokumen laporan monitoring dan evaluasi manajemen risiko siap cetak/PDF.</p>
      </div>
      <div class="profil-hero-tools risiko-hero-tools-align">
        <div style="display:flex; align-items:center; gap:8px; background:rgba(255,255,255,.12); padding:8px 16px; border-radius:var(--radius-sm); border:1px solid rgba(255,255,255,.2); color:#fff; font-size:.85rem;">
          <i class="fas fa-calendar-check" style="font-size:1.1rem; color:#93c5fd;"></i>
          <span>Periode Triwulanan &amp; Tahunan</span>
        </div>
      </div>
    </div>

    <!-- ── Kartu Form Generator ── -->
    <div class="card" style="margin-top:20px;">
      <div class="card-header" style="display:flex; justify-content:flex-start; align-items:center;">
        <div class="card-title" style="display:inline-flex; align-items:center; gap:10px; margin:0;">
          <i class="fas fa-file-alt" style="color:var(--primary);font-size:1.1rem;"></i>
          <span>Parameter Laporan</span>
        </div>
      </div>
      <div class="card-body">
        <form method="get" action="<?= APP_URL ?>/" id="formLaporanMonev"
              onsubmit="this.action='<?= APP_URL ?>/?page=laporan_monev&amp;generate=1';">
          <input type="hidden" name="page"     value="laporan_monev">
          <input type="hidden" name="generate" value="1">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

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
                     placeholder="contoh: M. Edi Royandi, SKM, MPH"
                     value="<?= xss($koordinator !== '' ? $koordinator : 'M. Edi Royandi, SKM, MPH') ?>">
            </div>
            <div class="form-group">
              <label class="form-label" for="fPenulis">Penulis</label>
              <input type="text" name="penulis" id="fPenulis" class="form-control"
                     maxlength="100"
                     placeholder="contoh: Bramadita Kunni Fauziyyah"
                     value="<?= xss($penulis !== '' ? $penulis : 'Bramadita Kunni Fauziyyah') ?>">
            </div>
          </div>

          <!-- Baris 3: Nama Kepala + NIP Kepala -->
          <div class="form-row-2" style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:0;">
            <div class="form-group">
              <label class="form-label" for="fNamaKepala">Nama Kepala BBLKL</label>
              <input type="text" name="nama_kepala" id="fNamaKepala" class="form-control"
                     maxlength="100"
                     placeholder="contoh: Akhmad Saikhu, SKM, M.Sc.PH"
                     value="<?= xss($namaKepala !== '' ? $namaKepala : 'Akhmad Saikhu, SKM, M.Sc.PH') ?>">
            </div>
            <div class="form-group">
              <label class="form-label" for="fNipKepala">NIP Kepala BBLKL</label>
              <input type="text" name="nip_kepala" id="fNipKepala" class="form-control"
                     maxlength="20"
                     placeholder="contoh: 196805251992031004"
                     value="<?= xss($nipKepala !== '' ? $nipKepala : '196805251992031004') ?>">
            </div>
          </div>

          <!-- Baris 4: Tanggal Pengesahan -->
          <div class="form-row-2" style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:0;">
            <div class="form-group">
              <label class="form-label" for="fTanggal">Tanggal Pengesahan</label>
              <input type="date" name="tanggal" id="fTanggal" class="form-control"
                     value="<?= xss(laporanTanggalKeIso($tanggal) ?: date('Y-m-d')) ?>">
            </div>
            <div></div>
          </div>

          <!-- Tombol Submit -->
          <div style="display:flex; align-items:center; gap:12px; margin-top:16px; padding-top:16px; border-top:1px solid var(--border); flex-wrap:wrap;">
            <button type="submit" class="btn btn-primary" id="btnGenerateLaporan"
                    style="display:inline-flex; align-items:center; gap:8px; padding:10px 22px;">
              <i class="fas fa-eye"></i>
              <span>Generate Laporan</span>
            </button>
            <button type="button" class="btn btn-outline" id="btnSyncFresh"
                    style="display:inline-flex; align-items:center; gap:8px; padding:10px 22px; border-color:#0284c7; color:#0284c7;"
                    title="Generate langsung dari data aplikasi terbaru (abaikan draft lama)">
              <i class="fas fa-arrows-rotate"></i>
              <span>Data Terbaru Aplikasi</span>
            </button>
            <button type="button" class="btn btn-outline" id="btnUnduhPdf"
                    style="display:inline-flex; align-items:center; gap:8px; padding:10px 22px; border-color:#dc2626; color:#dc2626;">
              <i class="fas fa-file-pdf"></i>
              <span>Unduh PDF (.pdf)</span>
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
            <button type="button" class="btn btn-outline" id="btnResetDraftForm"
                    style="display:inline-flex; align-items:center; gap:8px; padding:10px 22px; border-color:#94a3b8; color:#64748b;"
                    title="Hapus draft online tersimpan untuk periode ini agar laporan selalu bersih dari data terbaru">
              <i class="fas fa-trash-can"></i>
              <span>Hapus Draft Periode</span>
            </button>
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

        // Tombol Data Terbaru Aplikasi — buka laporan langsung dari data DB tanpa draft lama
        var btnFresh = document.getElementById('btnSyncFresh');
        if (btnFresh) {
            btnFresh.addEventListener('click', function () {
                var triwulan = document.getElementById('fTriwulan');
                var tahun    = document.getElementById('fTahun');
                if (!triwulan.value || !tahun.value) {
                    form.reportValidity();
                    return;
                }
                window.open('<?= APP_URL ?>/?' + buildParams({ ignore_draft: '1' }).toString(), '_blank');
            });
        }

        // Tombol Unduh PDF — buka laporan dan otomatis buka dialog simpan PDF
        var btnPdf = document.getElementById('btnUnduhPdf');
        if (btnPdf) {
            btnPdf.addEventListener('click', function () {
                var triwulan = document.getElementById('fTriwulan');
                var tahun    = document.getElementById('fTahun');

                if (!triwulan.value || !tahun.value) {
                    form.reportValidity();
                    return;
                }

                window.open('<?= APP_URL ?>/?' + buildParams({ autoprint: '1' }).toString(), '_blank');
            });
        }

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

        // Tombol Hapus Draft Periode dari Form
        var btnResetForm = document.getElementById('btnResetDraftForm');
        if (btnResetForm) {
            btnResetForm.addEventListener('click', function () {
                var triwulan = document.getElementById('fTriwulan');
                var tahun    = document.getElementById('fTahun');

                if (!triwulan.value || !tahun.value) {
                    form.reportValidity();
                    return;
                }

                if (!confirm('Hapus draft tersimpan untuk Triwulan ' + triwulan.value + ' Tahun ' + tahun.value + ' agar laporan menampilkan data asli terbaru dari aplikasi?')) {
                    return;
                }

                btnResetForm.disabled = true;
                btnResetForm.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Menghapus...</span>';

                var csrfTokenInput = form.querySelector('input[name="csrf_token"]');
                var csrfVal = csrfTokenInput ? csrfTokenInput.value : '';

                var fd = new FormData();
                fd.append('action', 'reset_draft');
                fd.append('csrf_token', csrfVal);
                fd.append('tahun', tahun.value);
                fd.append('triwulan', triwulan.value);

                fetch('<?= APP_URL ?>/?page=laporan_monev', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                }).then(function (res) {
                    return res.json().then(function (j) {
                        if (!res.ok || !j.ok) throw new Error(j.message || 'Gagal menghapus draft');
                        return j;
                    });
                }).then(function () {
                    alert('Draft tersimpan untuk periode ini berhasil dihapus. Laporan akan menggunakan data sistem terbaru.');
                    btnResetForm.disabled = false;
                    btnResetForm.innerHTML = '<i class="fas fa-trash-can"></i> <span>Hapus Draft Periode</span>';
                    form.submit();
                }).catch(function (err) {
                    alert('Gagal menghapus draft: ' + err.message);
                    btnResetForm.disabled = false;
                    btnResetForm.innerHTML = '<i class="fas fa-trash-can"></i> <span>Hapus Draft Periode</span>';
                });
            });
        }
    })();
    </script>
    <?php
}
