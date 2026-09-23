<?php
/**
 * Property-Based Tests: Sanitasi Input Laporan Monev
 *
 * Property 12: Sanitasi triwulan di luar rentang
 *   Validates: Requirements 10.5
 *
 * Property 13: Sanitasi tahun non-format 4-digit
 *   Validates: Requirements 10.6
 *
 * Jalankan: php vendor/bin/phpunit tests/LaporanMonevSanitasiTest.php
 */

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Fungsi sanitasi murni yang merefleksikan logika di modules/laporan_monev.php.
 * Tidak bergantung pada DB, session, atau auth.
 *
 * Logika identik dengan modul:
 *   $triwulan = (int)($_REQUEST['triwulan'] ?? 1);
 *   if ($triwulan < 1 || $triwulan > 4) { $triwulan = 1; }
 */
function sanitasiTriwulan(mixed $input): int
{
    $triwulan = (int)$input;
    if ($triwulan < 1 || $triwulan > 4) {
        $triwulan = 1;
    }
    return $triwulan;
}

/**
 * Logika identik dengan modul:
 *   $tahun = trim((string)($_REQUEST['tahun'] ?? date('Y')));
 *   if (!preg_match('/^\d{4}$/', $tahun)) { $tahun = date('Y'); }
 */
function sanitasiTahun(mixed $input): string
{
    $tahun = trim((string)$input);
    if (!preg_match('/^\d{4}$/', $tahun)) {
        $tahun = date('Y');
    }
    return $tahun;
}

class LaporanMonevSanitasiTest extends TestCase
{
    // ════════════════════════════════════════════════════════════
    //  Property 12 — Sanitasi triwulan di luar rentang
    //  Validates: Requirements 10.5
    // ════════════════════════════════════════════════════════════

    /**
     * **Validates: Requirements 10.5**
     *
     * Property 12: Untuk setiap nilai input triwulan yang TIDAK termasuk dalam
     * himpunan {1, 2, 3, 4}, nilai sanitasi HARUS sama dengan 1.
     */
    #[DataProvider('provideTriwulanDiLuarRentang')]
    public function testProperty12TriwulanDiLuarRentangDefaultKe1(mixed $input, string $label): void
    {
        $result = sanitasiTriwulan($input);
        $this->assertSame(
            1,
            $result,
            "Property 12 gagal: input {$label} → diharapkan 1, dapat {$result}"
        );
    }

    /**
     * **Validates: Requirements 10.5**
     *
     * Property 12 (pass-through): Untuk nilai dalam himpunan {1, 2, 3, 4},
     * nilai sanitasi HARUS dikembalikan tidak berubah.
     */
    #[DataProvider('provideTriwulanValid')]
    public function testProperty12TriwulanValidPassThrough(mixed $input, int $expected): void
    {
        $result = sanitasiTriwulan($input);
        $this->assertSame(
            $expected,
            $result,
            "Property 12 pass-through gagal: input '{$input}' → diharapkan {$expected}, dapat {$result}"
        );
    }

    // ════════════════════════════════════════════════════════════
    //  Property 13 — Sanitasi tahun non-format 4-digit
    //  Validates: Requirements 10.6
    // ════════════════════════════════════════════════════════════

    /**
     * **Validates: Requirements 10.6**
     *
     * Property 13: Untuk setiap nilai input tahun yang TIDAK cocok dengan
     * pola /^\d{4}$/ setelah trim(), nilai sanitasi HARUS sama dengan date('Y').
     */
    #[DataProvider('provideTahunTidakValid')]
    public function testProperty13TahunTidakValidDefaultKeTahunBerjalan(mixed $input, string $label): void
    {
        $currentYear = date('Y');
        $result = sanitasiTahun($input);
        $this->assertSame(
            $currentYear,
            $result,
            "Property 13 gagal: input {$label} → diharapkan {$currentYear}, dapat '{$result}'"
        );
    }

    /**
     * **Validates: Requirements 10.6**
     *
     * Property 13 (pass-through): Untuk nilai yang setelah trim() cocok
     * dengan /^\d{4}$/, nilai sanitasi HARUS dikembalikan tidak berubah.
     */
    #[DataProvider('provideTahunValid')]
    public function testProperty13TahunValidPassThrough(string $input, string $expected): void
    {
        $result = sanitasiTahun($input);
        $this->assertSame(
            $expected,
            $result,
            "Property 13 pass-through gagal: input '{$input}' → diharapkan '{$expected}', dapat '{$result}'"
        );
    }

    // ════════════════════════════════════════════════════════════
    //  Data Providers
    // ════════════════════════════════════════════════════════════

    /**
     * Kasus-kasus triwulan di LUAR rentang valid {1,2,3,4}.
     *
     * Catatan perilaku PHP cast ke int:
     *   - (int)0      = 0      → di luar rentang → default 1 ✓
     *   - (int)''     = 0      → di luar rentang → default 1 ✓
     *   - (int)'abc'  = 0      → di luar rentang → default 1 ✓
     *   - (int)'  3  '= 3      → VALID (PHP trim string sebelum cast)
     *     → kasus ini TIDAK ada di sini; ada di provideTriwulanValid
     *   - (int)4.9    = 4      → VALID (truncate, 4 ∈ {1..4})
     *     → kasus ini TIDAK ada di sini; ada di provideTriwulanValid
     *   - (int)4.0    = 4      → VALID
     *   - (int)5.0    = 5      → di luar rentang → default 1 ✓
     */
    public static function provideTriwulanDiLuarRentang(): array
    {
        return [
            // Nol
            'nol integer'         => [0,          '0 (int)'],
            'nol string'          => ['0',         '"0"'],
            // Negatif
            'negatif -1'          => [-1,          '-1'],
            'negatif -100'        => [-100,        '-100'],
            'negatif besar'       => [-999999,     '-999999'],
            'float negatif -0.5'  => [-0.5,        '-0.5 → (int) = 0'],
            'float negatif -1.9'  => [-1.9,        '-1.9 → (int) = -1'],
            // Terlalu besar
            'lima'                => [5,           '5'],
            'lima string'         => ['5',         '"5"'],
            'sepuluh'             => [10,          '10'],
            'seratus'             => [100,         '100'],
            'sangat besar'        => [PHP_INT_MAX, 'PHP_INT_MAX'],
            'float 5.0'           => [5.0,         '5.0 → (int) = 5'],
            'float 5.9'           => [5.9,         '5.9 → (int) = 5'],
            // String non-numerik (semua cast ke 0)
            'string kosong'       => ['',          '""'],
            'string alpha'        => ['abc',       '"abc"'],
            'spasi saja'          => ['   ',       '"   " → (int) = 0'],
            'simbol'              => ['!@#',       '"!@#"'],
            'newline'             => ["\n",        '"\\n"'],
            // SQL injection — PHP casts numeric-prefix string to that number
            // (int)"1 OR 1=1" = 1, which is valid → NOT in this list
            // (int)"5 UNION" = 5, which is out of range → in this list
            'sql injection 5'     => ['5 UNION',    '"5 UNION" → (int) = 5'],
            'drop table'          => ['DROP TABLE','DROP TABLE → (int) = 0'],
            // null — (int)null = 0
            'null'                => [null,        'null → (int) = 0'],
            // Boolean false — (int)false = 0
            'boolean false'       => [false,       'false → (int) = 0'],
            // String numerik di luar batas
            'string -1'           => ['-1',        '"-1" → (int) = -1'],
            'string 99'           => ['99',        '"99"'],
            'string 1000'         => ['1000',      '"1000"'],
        ];
    }

    /**
     * Kasus-kasus triwulan VALID yang harus lolos tidak berubah.
     *
     * Perilaku penting:
     *   - (int)'  3  ' = 3 karena PHP cast string ke int dengan trim leading ws
     *   - (int)4.9     = 4 karena truncation (bukan rounding)
     *   - (int)true    = 1
     */
    public static function provideTriwulanValid(): array
    {
        return [
            'integer 1'          => [1,      1],
            'integer 2'          => [2,      2],
            'integer 3'          => [3,      3],
            'integer 4'          => [4,      4],
            'string "1"'         => ['1',    1],
            'string "2"'         => ['2',    2],
            'string "3"'         => ['3',    3],
            'string "4"'         => ['4',    4],
            // Float truncation ke integer valid
            'float 1.0'          => [1.0,    1],
            'float 2.0'          => [2.0,    2],
            'float 3.0'          => [3.0,    3],
            'float 4.0'          => [4.0,    4],
            'float 1.9'          => [1.9,    1],  // (int)1.9 = 1
            'float 2.7'          => [2.7,    2],  // (int)2.7 = 2
            'float 3.5'          => [3.5,    3],  // (int)3.5 = 3
            'float 4.9'          => [4.9,    4],  // (int)4.9 = 4
            // PHP string-to-int cast trims leading whitespace
            'string "  1  "'     => ['  1  ', 1],
            'string "  3  "'     => ['  3  ', 3],
            // boolean true → 1
            'boolean true'       => [true,   1],
        ];
    }

    /**
     * Kasus-kasus tahun TIDAK VALID yang harus di-default ke date('Y').
     *
     * Logika: trim((string)$input) lalu preg_match('/^\d{4}$/', ...)
     *
     * Catatan:
     *   - "\t2024" → trim() → "2024" → COCOK → VALID (bukan invalid)
     *     → kasus ini ada di provideTahunValid, bukan di sini
     *   - " 2024 " → trim() → "2024" → COCOK → VALID
     *   - "2024.0" → trim() → "2024.0" → tidak cocok → default ✓
     *   - (float)2024.5 → (string) → "2024.5" → trim → "2024.5" → tidak cocok ✓
     *   - (bool)true → (string) → "1" → trim → "1" → tidak cocok ✓
     */
    public static function provideTahunTidakValid(): array
    {
        return [
            // Terlalu pendek
            'kosong'               => ['',          '""'],
            '1 digit'              => ['1',          '"1"'],
            '2 digit'              => ['23',         '"23"'],
            '3 digit'              => ['202',        '"202"'],
            // Terlalu panjang
            '5 digit'              => ['20245',      '"20245"'],
            '8 digit'              => ['20240101',   '"20240101"'],
            // Mengandung huruf
            'alpha-only'           => ['abcd',       '"abcd"'],
            'alphanumeric'         => ['20a4',       '"20a4"'],
            'alpha-suffix'         => ['2024x',      '"2024x"'],
            'tahun dengan teks'    => ['Tahun2024',  '"Tahun2024"'],
            // Tanda negatif → bukan 4 digit angka murni
            'negatif 3 digit'      => ['-202',       '"-202"'],
            'negatif 4 digit'      => ['-2024',      '"-2024"'],
            // Float string — "2024.0" tidak cocok /^\d{4}$/
            'float string 2024.0'  => ['2024.0',     '"2024.0"'],
            'float string 2024.5'  => ['2024.5',     '"2024.5"'],
            // Tipe PHP non-string yang trim(string) menghasilkan bukan 4-digit
            'float 2024.5'         => [2024.5,       '(float)2024.5 → "2024.5"'],
            'boolean true'         => [true,          'true → "1"'],
            'boolean false'        => [false,         'false → ""'],
            'null'                 => [null,          'null → ""'],
            // SQL injection
            'sql union'            => ['2024 UNION SELECT', '"2024 UNION SELECT"'],
            'drop table'           => ['DROP TABLE risiko',  '"DROP TABLE risiko"'],
            'sql quote'            => ["'2024'",      "\"'2024'\""],
            // HTML/XSS
            'script tag'           => ['<script>',    '"<script>"'],
            'html entity'          => ['&amp;',       '"&amp;"'],
            // Spasi saja
            'spasi saja'           => ['    ',        '"    " → trim → "" → tidak cocok'],
        ];
    }

    /**
     * Kasus-kasus tahun VALID yang harus lolos tidak berubah.
     * Format setelah trim() harus persis /^\d{4}$/.
     *
     * Catatan: sanitasiTahun() mengembalikan hasil SETELAH trim(),
     * sehingga input "\t2024" menghasilkan "2024" (bukan "\t2024").
     */
    public static function provideTahunValid(): array
    {
        return [
            // Tahun 4 digit murni — pass-through identik
            'tahun 2000'           => ['2000', '2000'],
            'tahun 2001'           => ['2001', '2001'],
            'tahun 2010'           => ['2010', '2010'],
            'tahun 2019'           => ['2019', '2019'],
            'tahun 2020'           => ['2020', '2020'],
            'tahun 2021'           => ['2021', '2021'],
            'tahun 2022'           => ['2022', '2022'],
            'tahun 2023'           => ['2023', '2023'],
            'tahun 2024'           => ['2024', '2024'],
            'tahun 2025'           => ['2025', '2025'],
            'tahun 2026'           => ['2026', '2026'],
            'tahun 2030'           => ['2030', '2030'],
            'tahun 2050'           => ['2050', '2050'],
            'tahun 1999'           => ['1999', '1999'],
            'tahun 1900'           => ['1900', '1900'],
            'tahun 9999'           => ['9999', '9999'],
            'tahun 0001'           => ['0001', '0001'],
            // Input dengan whitespace di luar → trim() → string 4 digit valid
            // Fungsi mengembalikan hasil SETELAH trim, jadi expected = trimmed
            'spasi sebelum 2024'   => [' 2024', '2024'],
            'spasi sesudah 2024'   => ['2024 ', '2024'],
            'spasi kiri-kanan'     => [' 2024 ', '2024'],
            'tab sebelum 2024'     => ["\t2024", '2024'],
            'newline sesudah'      => ["2024\n", '2024'],
        ];
    }
}
