<?php
/**
 * Property-Based Test (Standalone): Output Escaping Laporan Monev
 *
 * Property 14: Output HTML bebas dari karakter HTML mentah pada nilai user-input
 *   Validates: Requirements 10.3, 10.4
 *
 * Untuk setiap string input dari pengguna atau dari database yang mengandung
 * karakter <, >, ", ', atau &, representasi karakter-karakter tersebut dalam
 * output HTML SHALL berupa entity HTML (mis. &lt;, &gt;, &amp;) dan bukan
 * karakter mentah.
 *
 * Catatan encoding: fungsi xss() menggunakan ENT_QUOTES | ENT_HTML5.
 * Dengan ENT_HTML5, single quote (') di-encode sebagai &apos; (bukan &#039;).
 * Dengan ENT_QUOTES (tanpa ENT_HTML5 / dengan HTML 4.01), single quote → &#039;.
 * Kedua bentuk entity (&#039; dan &apos;) sama-sama AMAN karena bukan karakter mentah.
 *
 * Jalankan: php tests/laporan_monev_escaping_test.php
 */

declare(strict_types=1);

// ── Bootstrap: muat xss() dari includes/functions.php ────────────────────────
$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';

// ── Assertion helpers ─────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;
$errors = [];

function assertEq(string $label, mixed $expected, mixed $actual): void
{
    global $passed, $failed, $errors;
    if ($expected === $actual) {
        $passed++;
    } else {
        $failed++;
        $errors[] = sprintf(
            "  FAIL [%s]\n    Expected: %s\n    Got:      %s",
            $label,
            var_export($expected, true),
            var_export($actual, true)
        );
    }
}

function assertContains(string $label, string $needle, string $haystack): void
{
    global $passed, $failed, $errors;
    if (strpos($haystack, $needle) !== false) {
        $passed++;
    } else {
        $failed++;
        $errors[] = sprintf(
            "  FAIL [%s]\n    Output tidak mengandung entity: %s\n    Output: %s",
            $label,
            var_export($needle, true),
            var_export($haystack, true)
        );
    }
}

/**
 * Verifikasi bahwa output TIDAK mengandung pola karakter mentah yang berbahaya.
 *
 * Untuk karakter & yang lebih rumit: & hanya "mentah/berbahaya" jika tidak
 * diikuti oleh pola entity (\w+; atau #\d+; atau #x[\da-f]+;).
 * Fungsi ini menggunakan regex untuk mendeteksi & yang BUKAN bagian dari entity.
 *
 * Untuk <, >, ", ': cukup cek kehadiran langsung.
 */
function assertNoRawDangerousChars(string $label, string $output): void
{
    global $passed, $failed, $errors;
    $failures = [];

    // < dan > tidak boleh muncul sama sekali (tidak ada bentuk entity dengan < atau >)
    foreach (['<', '>'] as $c) {
        if (strpos($output, $c) !== false) {
            $failures[] = "karakter mentah '{$c}'";
        }
    }

    // " tidak boleh muncul mentah (entity: &quot;)
    if (strpos($output, '"') !== false) {
        $failures[] = 'karakter mentah \'"\'';
    }

    // ' tidak boleh muncul mentah (entity: &apos; atau &#039;)
    if (strpos($output, "'") !== false) {
        $failures[] = "karakter mentah \"'\"";
    }

    // & tidak boleh muncul mentah (hanya boleh muncul sebagai awal entity: &\w+; atau &#nnn;)
    // Cari & yang tidak diikuti pola entity yang valid
    if (preg_match('/&(?!(?:#\d+|#x[\da-fA-F]+|\w+);)/', $output)) {
        $failures[] = "karakter '&' mentah (bukan bagian dari entity HTML)";
    }

    if (empty($failures)) {
        $passed++;
    } else {
        $failed++;
        $errors[] = sprintf(
            "  FAIL [%s]\n    Ditemukan: %s\n    Output: %s",
            $label,
            implode(', ', $failures),
            var_export($output, true)
        );
    }
}

// Deteksi entity single-quote yang digunakan oleh implementasi ini
// ENT_HTML5 → &apos;, ENT_HTML401 → &#039;
$singleQuoteEntity = xss("'");  // akan menjadi &apos; atau &#039;

// ════════════════════════════════════════════════════════════════════════════
//  Bagian 1: Validasi implementasi xss()
//  Memastikan xss() = htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8')
// ════════════════════════════════════════════════════════════════════════════

echo "=== Bagian 1: Validasi implementasi xss() ===\n";

$testInputs = [
    '<script>alert(\'xss\')</script>',
    '&lt;',
    '"quoted"',
    "O'Brien",
    'AT&T',
    '<b>bold</b>',
    '"><img src=x onerror=alert(1)>',
    "'; DROP TABLE risiko; --",
    '&amp;lt;',
    '',
    'normal text without special chars',
    '<>&"\'',
    '< > & " \'',
];

foreach ($testInputs as $input) {
    $expected = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $actual   = xss($input);
    assertEq(
        "xss() identik htmlspecialchars(ENT_QUOTES|ENT_HTML5): " . json_encode($input),
        $expected,
        $actual
    );
}

// xss() harus menangani null dengan aman (tidak error)
$actualNull = xss(null);
assertEq('xss(null) mengembalikan string kosong', '', $actualNull);

// ════════════════════════════════════════════════════════════════════════════
//  Bagian 2: Property 14 — Karakter berbahaya tidak muncul mentah dalam output
//  Validates: Requirements 10.3, 10.4
// ════════════════════════════════════════════════════════════════════════════

echo "\n=== Bagian 2: Property 14 — Karakter mentah tidak muncul dalam output ===\n";

// Pemetaan: karakter mentah → entity HTML yang diharapkan (sesuai ENT_HTML5)
$dangerousMappings = [
    '<'  => '&lt;',
    '>'  => '&gt;',
    '"'  => '&quot;',
    "'"  => $singleQuoteEntity,  // &apos; (ENT_HTML5) atau &#039; (ENT_HTML401)
    '&'  => '&amp;',
];

foreach ($dangerousMappings as $rawChar => $expectedEntity) {
    $input  = "prefix{$rawChar}suffix";
    $output = xss($input);

    // Entity yang sesuai harus ada dalam output
    assertContains(
        "xss('prefix{$rawChar}suffix'): entity '{$expectedEntity}' muncul dalam output",
        $expectedEntity,
        $output
    );

    // Tidak boleh ada karakter mentah berbahaya dalam output keseluruhan
    assertNoRawDangerousChars(
        "xss('prefix{$rawChar}suffix'): tidak ada karakter mentah berbahaya",
        $output
    );
}

// ════════════════════════════════════════════════════════════════════════════
//  Bagian 3: Input spesifik dari task — vektor XSS dan injeksi umum
// ════════════════════════════════════════════════════════════════════════════

echo "\n=== Bagian 3: Input spesifik — vektor XSS dan injeksi ===\n";

// Vektor 1: <script>alert('xss')</script>
$v1 = xss("<script>alert('xss')</script>");
assertNoRawDangerousChars("v1: <script>alert('xss')</script> — tidak ada karakter mentah", $v1);
assertContains("v1: ada &lt;",   '&lt;',  $v1);
assertContains("v1: ada &gt;",   '&gt;',  $v1);
assertContains("v1: ada entity single-quote ({$singleQuoteEntity})", $singleQuoteEntity, $v1);

// Verifikasi output lengkap (menggunakan entity yang sebenarnya dipakai implementasi)
$expectedV1 = "&lt;script&gt;alert({$singleQuoteEntity}xss{$singleQuoteEntity})&lt;/script&gt;";
assertEq("v1: output lengkap", $expectedV1, $v1);

// Vektor 2: &lt; (ampersand harus di-escape → &amp;lt;)
$v2 = xss('&lt;');
assertNoRawDangerousChars("v2: &lt; — tidak ada karakter mentah", $v2);
assertContains("v2: ada &amp;", '&amp;', $v2);
assertEq("v2: output lengkap &lt; → &amp;lt;", '&amp;lt;', $v2);

// Vektor 3: "quoted"
$v3 = xss('"quoted"');
assertNoRawDangerousChars('v3: "quoted" — tidak ada karakter mentah', $v3);
assertContains("v3: ada &quot;", '&quot;', $v3);
assertEq('v3: output lengkap "quoted" → &quot;quoted&quot;', '&quot;quoted&quot;', $v3);

// Vektor 4: O'Brien
$v4 = xss("O'Brien");
assertNoRawDangerousChars("v4: O'Brien — tidak ada karakter mentah", $v4);
assertContains("v4: ada entity single-quote ({$singleQuoteEntity})", $singleQuoteEntity, $v4);
assertEq("v4: output lengkap O'Brien → O{$singleQuoteEntity}Brien", "O{$singleQuoteEntity}Brien", $v4);

// Vektor 5: AT&T
$v5 = xss('AT&T');
assertNoRawDangerousChars("v5: AT&T — tidak ada karakter mentah", $v5);
assertContains("v5: ada &amp;", '&amp;', $v5);
assertEq("v5: output lengkap AT&T → AT&amp;T", 'AT&amp;T', $v5);

// Vektor 6: kombinasi semua karakter berbahaya sekaligus
$v6Input  = '<>"\'&';
$v6Output = xss($v6Input);
assertNoRawDangerousChars("v6: '<>\"'&' — tidak ada karakter mentah", $v6Output);
assertEq(
    "v6: semua karakter berbahaya di-escape",
    "&lt;&gt;&quot;{$singleQuoteEntity}&amp;",
    $v6Output
);

// Vektor 7: script tag dengan atribut
$v7 = xss('<img src="x" onerror=\'alert(1)\'>');
assertNoRawDangerousChars("v7: <img> tag dengan event handler", $v7);
assertContains("v7: ada &lt;",   '&lt;',  $v7);
assertContains("v7: ada &quot;", '&quot;', $v7);
assertContains("v7: ada entity single-quote", $singleQuoteEntity, $v7);

// Vektor 8: SQL injection dengan quote
$v8 = xss("'; DROP TABLE risiko; --");
assertNoRawDangerousChars("v8: SQL injection — tidak ada karakter mentah", $v8);
assertContains("v8: ada entity single-quote", $singleQuoteEntity, $v8);

// ════════════════════════════════════════════════════════════════════════════
//  Bagian 4: Property 14 — Input dari "database" (simulasi nilai query result)
//  Validates: Requirements 10.4
// ════════════════════════════════════════════════════════════════════════════

echo "\n=== Bagian 4: Property 14 — Nilai dari database ===\n";

// Simulasi nilai yang mungkin tersimpan dalam DB dengan karakter berbahaya
$dbValues = [
    'Risiko <kehilangan> data',
    'Upaya "perlindungan" data',
    "Nama O'Brien di unit kerja",
    'Vendor AT&T untuk layanan',
    '<b>Kendala</b> teknis',
    'RTL: lakukan > 3 kali review',
    "NULL-injection'; DROP TABLE--",
    'Normal text tanpa karakter berbahaya',
    'Nilai risiko: 5 (Sangat Tinggi)',
    '',
    '<script>document.cookie</script>',
    '<<double angle>>',
    '"double" & \'single\' quotes together',
];

foreach ($dbValues as $dbValue) {
    $output = xss($dbValue);

    assertNoRawDangerousChars(
        "DB nilai " . json_encode($dbValue) . " — tidak ada karakter mentah",
        $output
    );

    // Output harus selalu string
    assertEq(
        "DB nilai " . json_encode($dbValue) . " — output adalah string",
        true,
        is_string($output)
    );
}

// ════════════════════════════════════════════════════════════════════════════
//  Bagian 5: Property 14 — Input user-input dari form (simulasi $_REQUEST)
//  Validates: Requirements 10.3
// ════════════════════════════════════════════════════════════════════════════

echo "\n=== Bagian 5: Property 14 — Nilai dari input form user ===\n";

// Simulasi nilai yang dimasukkan pengguna lewat form
$formValues = [
    'koordinator' => 'Dr. <John> "Doe" & Associates',
    'penulis'     => "O'Brien, M.Sc.",
    'nama_kepala' => '<b>Kepala</b> Besar & Kuat',
    'nip_kepala'  => "197001011990031001' OR '1'='1",
    'tanggal'     => 'Surabaya, 1 Januari <2024>',
];

foreach ($formValues as $field => $value) {
    $output = xss($value);
    assertNoRawDangerousChars(
        "Form field '{$field}' = " . json_encode($value) . " — tidak ada karakter mentah",
        $output
    );
}

// ════════════════════════════════════════════════════════════════════════════
//  Bagian 6: Konsistensi encoding — string aman tidak berubah
// ════════════════════════════════════════════════════════════════════════════

echo "\n=== Bagian 6: Konsistensi encoding — string aman tidak berubah ===\n";

$safeInputs = [
    'Teks biasa',
    'Manajemen Risiko 2024',
    'Sub Bagian Administrasi Umum',
    'Tim Kerja Program Layanan',
    '123456789',
    'nilai_risiko = 15',
    'Balai Besar Laboratorium Kesehatan Lingkungan',
    'Triwulan I Tahun 2024',
    '',
];

foreach ($safeInputs as $safe) {
    assertEq(
        "String aman tidak berubah: " . json_encode($safe),
        $safe,
        xss($safe)
    );
}

// ════════════════════════════════════════════════════════════════════════════
//  Hasil akhir
// ════════════════════════════════════════════════════════════════════════════

echo "\n" . str_repeat('=', 60) . "\n";

if ($failed === 0) {
    echo "✓ SEMUA TEST LULUS: {$passed} assertions berhasil\n";
    echo str_repeat('=', 60) . "\n";
    exit(0);
} else {
    echo "✗ {$failed} TEST GAGAL, {$passed} berhasil\n";
    echo str_repeat('=', 60) . "\n";
    foreach ($errors as $err) {
        echo $err . "\n";
    }
    exit(1);
}
