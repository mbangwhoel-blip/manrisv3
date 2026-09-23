<?php
/**
 * Property-Based Tests: Agregasi Kendala BAB III
 *
 * Property 7: Agregasi kendala mencakup semua nilai non-kosong unik
 *   Validates: Requirements 7.2
 *
 *   Untuk setiap unit kerja dengan satu atau lebih baris monev_triwulan.kendala
 *   yang tidak NULL dan tidak kosong untuk triwulan yang dipilih, semua nilai unik
 *   tersebut SHALL muncul dalam output BAB III unit yang bersangkutan.
 *
 * Property 8: Fallback kendala saat semua nilai kosong
 *   Validates: Requirements 7.3
 *
 *   Untuk setiap unit kerja di mana seluruh nilai kendala pada triwulan yang
 *   dipilih adalah NULL atau string kosong, output BAB III SHALL mengandung teks
 *   "Tidak ditemukan kendala dalam pelaksanaan kegiatan".
 *
 * Jalankan via PHPUnit  : php vendor/bin/phpunit tests/laporan_monev_kendala_test.php
 * Jalankan standalone   : php tests/laporan_monev_kendala_test.php
 */

// ─────────────────────────────────────────────────────────────────────────────
//  Fungsi murni yang mencerminkan logika dalam modules/laporan_monev.php
//
//  Tidak bergantung pada DB, session, atau auth.
//  Fungsi-fungsi ini mereproduksi logika aggregasi dan render BAB III.
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('testXss')) {
    /**
     * Escape string untuk output HTML — setara dengan xss() di aplikasi.
     */
    function testXss(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * Menyaring nilai unik, non-NULL, dan non-kosong dari array rows pada kolom tertentu.
 *
 * Fungsi ini mencerminkan logika query laporanGetAggregat() di modules/laporan_monev.php:
 * query tersebut menggunakan SELECT DISTINCT ... WHERE m.kolom IS NOT NULL AND m.kolom <> ''
 *
 * Berguna untuk menguji logika filter itu sendiri dalam konteks pengujian murni
 * (tanpa koneksi DB).
 *
 * @param array[] $rows  Array row dari monev_triwulan, tiap row adalah array asosiatif
 * @param string  $kolom Nama kolom yang akan diekstrak (mis. 'kendala')
 * @return string[]      Array string unik, non-NULL, non-kosong — terurut (konsisten dengan ORDER BY)
 */
function filterKendalaUnik(array $rows, string $kolom): array
{
    $seen   = [];
    $result = [];

    foreach ($rows as $row) {
        $val = $row[$kolom] ?? null;

        // Replika kondisi SQL: IS NOT NULL AND <> ''
        if ($val === null || $val === '') {
            continue;
        }

        $val = (string)$val;

        // DISTINCT: lewati duplikat
        if (isset($seen[$val])) {
            continue;
        }

        $seen[$val] = true;
        $result[]   = $val;
    }

    // Urutkan sesuai ORDER BY pada query (alfabetis)
    sort($result);

    return $result;
}

/**
 * Menghasilkan HTML untuk satu subbab BAB III (hambatan) satu unit kerja.
 *
 * Logika identik dengan loop BAB III di modules/laporan_monev.php:
 *
 *   if (empty($kendalaList)):
 *     <p>Tidak ditemukan kendala dalam pelaksanaan kegiatan.</p>
 *   else:
 *     <p>Hambatan yang ditemui meliputi:</p>
 *     <ol>
 *       <li>nilai1</li>
 *       ...
 *     </ol>
 *   endif
 *
 * Semua nilai di-escape dengan xss() / testXss() sebelum ditulis ke HTML.
 *
 * @param string[] $kendalaList  Hasil dari filterKendalaUnik() atau laporanGetAggregat()
 * @param string   $unitKerja   Nama unit kerja untuk heading (opsional, default '')
 * @return string  HTML subbab BAB III untuk unit kerja ini
 */
function laporanRenderBab3Unit(array $kendalaList, string $unitKerja = ''): string
{
    $html = '';

    if ($unitKerja !== '') {
        $html .= '<h3 class="subbab-heading">' . testXss($unitKerja) . '</h3>' . "\n";
    }

    if (empty($kendalaList)) {
        $html .= '<p>Tidak ditemukan kendala dalam pelaksanaan kegiatan.</p>' . "\n";
    } else {
        $html .= '<p>Hambatan yang ditemui meliputi:</p>' . "\n";
        $html .= '<ol>' . "\n";
        foreach ($kendalaList as $kendala) {
            $html .= '<li>' . testXss($kendala) . '</li>' . "\n";
        }
        $html .= '</ol>' . "\n";
    }

    return $html;
}

// ─────────────────────────────────────────────────────────────────────────────
//  PHPUnit Test Class
// ─────────────────────────────────────────────────────────────────────────────
if (class_exists('PHPUnit\Framework\TestCase')) {

    /**
     * Tes untuk Property 7 (agregasi kendala non-kosong unik) dan
     * Property 8 (fallback ketika semua nilai kosong/NULL).
     *
     * **Validates: Requirements 7.2, 7.3**
     */
    class LaporanMonevKendalaTest extends \PHPUnit\Framework\TestCase
    {
        // ════════════════════════════════════════════════════════════
        //  HELPER: filterKendalaUnik
        //  Menguji logika filter yang mencerminkan SELECT DISTINCT ... IS NOT NULL AND <> ''
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 7.2**
         *
         * Nilai unik non-kosong harus dikembalikan; nilai NULL diabaikan.
         */
        public function testFilterKendalaUnikMengembalikanNilaiNonNull(): void
        {
            $rows = [
                ['kendala' => 'Keterbatasan anggaran'],
                ['kendala' => null],
                ['kendala' => 'Kurangnya SDM'],
            ];

            $result = filterKendalaUnik($rows, 'kendala');

            $this->assertContains('Keterbatasan anggaran', $result);
            $this->assertContains('Kurangnya SDM', $result);
            $this->assertCount(2, $result, 'Hanya 2 nilai non-NULL yang harus dikembalikan');
        }

        /**
         * **Validates: Requirements 7.2**
         *
         * String kosong "" harus diabaikan seperti NULL.
         */
        public function testFilterKendalaUnikMengabaikanStringKosong(): void
        {
            $rows = [
                ['kendala' => 'Hambatan koordinasi'],
                ['kendala' => ''],
                ['kendala' => 'Hambatan anggaran'],
            ];

            $result = filterKendalaUnik($rows, 'kendala');

            $this->assertContains('Hambatan koordinasi', $result);
            $this->assertContains('Hambatan anggaran', $result);
            $this->assertCount(2, $result, 'String kosong harus diabaikan');
        }

        /**
         * **Validates: Requirements 7.2**
         *
         * Nilai duplikat harus dijadikan satu (DISTINCT).
         */
        public function testFilterKendalaUnikMenghapusDuplikat(): void
        {
            $rows = [
                ['kendala' => 'Kurangnya SDM'],
                ['kendala' => 'Kurangnya SDM'],
                ['kendala' => 'Kurangnya SDM'],
                ['kendala' => 'Keterbatasan alat'],
            ];

            $result = filterKendalaUnik($rows, 'kendala');

            $this->assertCount(2, $result, 'Duplikat harus dihapus — hanya 2 nilai unik');
            $this->assertContains('Kurangnya SDM', $result);
            $this->assertContains('Keterbatasan alat', $result);
        }

        /**
         * **Validates: Requirements 7.2**
         *
         * Mix dari NULL, kosong, dan valid — hanya valid yang dikembalikan.
         */
        public function testFilterKendalaUnikDenganCampuranNullKosongDanValid(): void
        {
            $rows = [
                ['kendala' => null],
                ['kendala' => ''],
                ['kendala' => 'Hambatan A'],
                ['kendala' => null],
                ['kendala' => 'Hambatan B'],
                ['kendala' => ''],
                ['kendala' => 'Hambatan A'],  // duplikat
            ];

            $result = filterKendalaUnik($rows, 'kendala');

            $this->assertCount(2, $result, 'Hanya 2 nilai unik non-NULL non-kosong');
            $this->assertContains('Hambatan A', $result);
            $this->assertContains('Hambatan B', $result);
        }

        /**
         * **Validates: Requirements 7.3**
         *
         * Jika semua nilai NULL, hasil harus array kosong.
         */
        public function testFilterKendalaUnikSemuaNullMengembalikanArrayKosong(): void
        {
            $rows = [
                ['kendala' => null],
                ['kendala' => null],
                ['kendala' => null],
            ];

            $result = filterKendalaUnik($rows, 'kendala');

            $this->assertEmpty($result, 'Semua NULL harus menghasilkan array kosong');
        }

        /**
         * **Validates: Requirements 7.3**
         *
         * Jika semua nilai string kosong, hasil harus array kosong.
         */
        public function testFilterKendalaUnikSemuaStringKosongMengembalikanArrayKosong(): void
        {
            $rows = [
                ['kendala' => ''],
                ['kendala' => ''],
            ];

            $result = filterKendalaUnik($rows, 'kendala');

            $this->assertEmpty($result, 'Semua string kosong harus menghasilkan array kosong');
        }

        /**
         * **Validates: Requirements 7.3**
         *
         * Array rows kosong harus menghasilkan array kosong.
         */
        public function testFilterKendalaUnikRowsKosongMengembalikanArrayKosong(): void
        {
            $result = filterKendalaUnik([], 'kendala');

            $this->assertEmpty($result, 'Rows kosong harus menghasilkan array kosong');
        }

        /**
         * **Validates: Requirements 7.2**
         *
         * Kolom berbeda ('rencana_tindak_lanjut') juga harus bekerja dengan logika yang sama.
         */
        public function testFilterKendalaUnikBekerjaDenganKolomLain(): void
        {
            $rows = [
                ['rencana_tindak_lanjut' => 'RTL A'],
                ['rencana_tindak_lanjut' => null],
                ['rencana_tindak_lanjut' => 'RTL B'],
                ['rencana_tindak_lanjut' => 'RTL A'],  // duplikat
            ];

            $result = filterKendalaUnik($rows, 'rencana_tindak_lanjut');

            $this->assertCount(2, $result, 'Hanya 2 nilai RTL unik non-NULL');
            $this->assertContains('RTL A', $result);
            $this->assertContains('RTL B', $result);
        }

        // ════════════════════════════════════════════════════════════
        //  PROPERTY 7 — Render BAB III: semua nilai non-kosong unik muncul
        //  Validates: Requirements 7.2
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 7.2**
         *
         * Property 7: Ketika ada nilai kendala non-kosong, semuanya harus
         * muncul dalam output HTML BAB III unit.
         */
        public function testProperty7SemuaNilaiNonKosongMunculDiOutput(): void
        {
            $kendalaList = ['Keterbatasan anggaran', 'Kurangnya SDM', 'Hambatan koordinasi'];
            $html        = laporanRenderBab3Unit($kendalaList);

            foreach ($kendalaList as $kendala) {
                $this->assertStringContainsString(
                    $kendala,
                    $html,
                    "Nilai kendala '{$kendala}' harus muncul dalam output BAB III"
                );
            }
        }

        /**
         * **Validates: Requirements 7.2**
         *
         * Property 7: Setiap nilai kendala harus dibungkus dalam elemen <li>.
         */
        public function testProperty7NilaiKendalaDibungkusDalamLi(): void
        {
            $kendalaList = ['Hambatan A', 'Hambatan B'];
            $html        = laporanRenderBab3Unit($kendalaList);

            $this->assertStringContainsString('<li>Hambatan A</li>', $html,
                'Hambatan A harus berada di dalam elemen <li>');
            $this->assertStringContainsString('<li>Hambatan B</li>', $html,
                'Hambatan B harus berada di dalam elemen <li>');
        }

        /**
         * **Validates: Requirements 7.2**
         *
         * Property 7: Jumlah elemen <li> harus sama dengan jumlah nilai unik
         * dalam daftar yang diberikan.
         */
        public function testProperty7JumlahLiSesuaiJumlahKendala(): void
        {
            $kendalaList = ['Kendala 1', 'Kendala 2', 'Kendala 3'];
            $html        = laporanRenderBab3Unit($kendalaList);

            $this->assertSame(
                3,
                substr_count($html, '<li>'),
                'Jumlah elemen <li> harus sama dengan jumlah kendala unik'
            );
        }

        /**
         * **Validates: Requirements 7.2**
         *
         * Property 7: Daftar kendala harus dibungkus dalam elemen <ol> (ordered list).
         */
        public function testProperty7KendalaDibungkusDalamOl(): void
        {
            $kendalaList = ['Hambatan A'];
            $html        = laporanRenderBab3Unit($kendalaList);

            $this->assertStringContainsString('<ol>', $html,
                'Daftar kendala harus dibungkus dalam elemen <ol>');
            $this->assertStringContainsString('</ol>', $html,
                'Elemen <ol> harus ditutup');
        }

        /**
         * **Validates: Requirements 7.2**
         *
         * Property 7: Ketika ada kendala, teks pengantar harus muncul —
         * bukan teks fallback.
         */
        public function testProperty7TeksPermulaan(): void
        {
            $kendalaList = ['Hambatan A'];
            $html        = laporanRenderBab3Unit($kendalaList);

            $this->assertStringContainsString(
                'Hambatan yang ditemui meliputi',
                $html,
                'Teks pengantar daftar kendala harus muncul'
            );
            $this->assertStringNotContainsString(
                'Tidak ditemukan kendala dalam pelaksanaan kegiatan',
                $html,
                'Teks fallback tidak boleh muncul ketika ada kendala'
            );
        }

        // ════════════════════════════════════════════════════════════
        //  PROPERTY 7 — XSS escaping pada nilai kendala
        //  Validates: Requirements 7.2, 10.4
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 7.2, 10.4**
         *
         * Property 7: Nilai kendala yang mengandung karakter HTML harus
         * di-escape sebelum dimasukkan ke output.
         */
        public function testProperty7NilaiKendalaDeganKarakterHtmlDiEscape(): void
        {
            $kendalaList = ['Hambatan <b>anggaran</b> & "SDM"'];
            $html        = laporanRenderBab3Unit($kendalaList);

            // Nilai mentah tidak boleh muncul
            $this->assertStringNotContainsString(
                '<b>anggaran</b>',
                $html,
                'Tag HTML mentah dalam nilai kendala tidak boleh muncul di output'
            );

            // Entity HTML harus muncul sebagai pengganti
            $this->assertStringContainsString(
                '&lt;b&gt;',
                $html,
                'Karakter < dan > harus di-escape menjadi &lt; &gt;'
            );
            $this->assertStringContainsString(
                '&amp;',
                $html,
                'Karakter & harus di-escape menjadi &amp;'
            );
        }

        /**
         * **Validates: Requirements 7.2, 10.4**
         *
         * Property 7: Tanda kutip dalam nilai kendala harus di-escape.
         */
        public function testProperty7TandaKutipDiEscape(): void
        {
            $kendalaList = ['Kendala "kritis"'];
            $html        = laporanRenderBab3Unit($kendalaList);

            $this->assertStringContainsString(
                '&quot;kritis&quot;',
                $html,
                'Tanda kutip ganda harus di-escape menjadi &quot;'
            );
        }

        // ════════════════════════════════════════════════════════════
        //  PROPERTY 8 — Fallback ketika semua nilai kosong/NULL
        //  Validates: Requirements 7.3
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 7.3**
         *
         * Property 8: Daftar kendala kosong HARUS menghasilkan teks fallback.
         */
        public function testProperty8DaftarKosongMenampilkanTeksFallback(): void
        {
            $html = laporanRenderBab3Unit([]);

            $this->assertStringContainsString(
                'Tidak ditemukan kendala dalam pelaksanaan kegiatan',
                $html,
                'Daftar kosong harus menghasilkan teks fallback'
            );
        }

        /**
         * **Validates: Requirements 7.3**
         *
         * Property 8: Teks fallback harus berada di dalam elemen <p>.
         */
        public function testProperty8TeksFallbackDidalamElemenP(): void
        {
            $html = laporanRenderBab3Unit([]);

            $this->assertMatchesRegularExpression(
                '/<p>.*Tidak ditemukan kendala dalam pelaksanaan kegiatan.*<\/p>/s',
                $html,
                'Teks fallback harus berada di dalam elemen <p>'
            );
        }

        /**
         * **Validates: Requirements 7.3**
         *
         * Property 8: Ketika daftar kosong, elemen <ol> TIDAK BOLEH muncul.
         */
        public function testProperty8TidakAdaOlSaatFallback(): void
        {
            $html = laporanRenderBab3Unit([]);

            $this->assertStringNotContainsString(
                '<ol>',
                $html,
                'Elemen <ol> tidak boleh muncul saat daftar kosong'
            );
        }

        /**
         * **Validates: Requirements 7.3**
         *
         * Property 8: Ketika daftar kosong, elemen <li> TIDAK BOLEH muncul.
         */
        public function testProperty8TidakAdaLiSaatFallback(): void
        {
            $html = laporanRenderBab3Unit([]);

            $this->assertStringNotContainsString(
                '<li>',
                $html,
                'Elemen <li> tidak boleh muncul saat daftar kosong'
            );
        }

        /**
         * **Validates: Requirements 7.3**
         *
         * Property 8: Ketika ada setidaknya satu nilai non-kosong,
         * teks fallback TIDAK BOLEH muncul.
         */
        public function testProperty8FallbackTidakMunculSaatAdaNilai(): void
        {
            $kendalaList = ['Satu hambatan'];
            $html        = laporanRenderBab3Unit($kendalaList);

            $this->assertStringNotContainsString(
                'Tidak ditemukan kendala dalam pelaksanaan kegiatan',
                $html,
                'Teks fallback tidak boleh muncul saat ada nilai kendala'
            );
        }

        /**
         * **Validates: Requirements 7.3**
         *
         * Property 8: Bahkan dengan satu nilai saja, fallback tidak muncul
         * dan nilai tersebut muncul di output.
         */
        public function testProperty8SatuNilaiSajaTidakMemicuFallback(): void
        {
            $kendalaList = ['Hambatan tunggal'];
            $html        = laporanRenderBab3Unit($kendalaList);

            $this->assertStringContainsString('Hambatan tunggal', $html,
                'Nilai tunggal harus muncul di output');
            $this->assertStringNotContainsString(
                'Tidak ditemukan kendala dalam pelaksanaan kegiatan',
                $html,
                'Fallback tidak boleh muncul saat ada nilai kendala'
            );
        }

        // ════════════════════════════════════════════════════════════
        //  INTEGRASI filterKendalaUnik + laporanRenderBab3Unit
        //  Validates: Requirements 7.2, 7.3
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 7.2**
         *
         * Integrasi: rows dengan campuran NULL/kosong/valid dan duplikat →
         * hanya nilai unik non-kosong muncul di output HTML.
         */
        public function testIntegrasiFiltirDanRenderSemuaNilaiUnikMunculDiHtml(): void
        {
            $rows = [
                ['kendala' => 'Hambatan A'],
                ['kendala' => null],
                ['kendala' => 'Hambatan B'],
                ['kendala' => ''],
                ['kendala' => 'Hambatan A'],   // duplikat
                ['kendala' => 'Hambatan C'],
            ];

            $kendalaList = filterKendalaUnik($rows, 'kendala');
            $html        = laporanRenderBab3Unit($kendalaList);

            // Semua nilai unik harus muncul
            $this->assertStringContainsString('Hambatan A', $html);
            $this->assertStringContainsString('Hambatan B', $html);
            $this->assertStringContainsString('Hambatan C', $html);

            // Tepat 3 elemen <li>
            $this->assertSame(3, substr_count($html, '<li>'),
                'Harus tepat 3 elemen <li> — satu per nilai unik');

            // Teks fallback tidak boleh muncul
            $this->assertStringNotContainsString(
                'Tidak ditemukan kendala dalam pelaksanaan kegiatan', $html,
                'Fallback tidak boleh muncul saat ada nilai'
            );
        }

        /**
         * **Validates: Requirements 7.3**
         *
         * Integrasi: rows dengan semua NULL dan kosong → array kosong →
         * teks fallback muncul di output HTML.
         */
        public function testIntegrasiFiltirDanRenderSemuaKosongMenampilkanFallback(): void
        {
            $rows = [
                ['kendala' => null],
                ['kendala' => ''],
                ['kendala' => null],
            ];

            $kendalaList = filterKendalaUnik($rows, 'kendala');
            $html        = laporanRenderBab3Unit($kendalaList);

            $this->assertEmpty($kendalaList, 'filterKendalaUnik harus mengembalikan array kosong');
            $this->assertStringContainsString(
                'Tidak ditemukan kendala dalam pelaksanaan kegiatan',
                $html,
                'Fallback harus muncul saat semua nilai NULL/kosong'
            );
        }

        /**
         * **Validates: Requirements 7.3**
         *
         * Integrasi: rows kosong [] → array kosong → teks fallback muncul.
         */
        public function testIntegrasiFiltirDanRenderRowsKosongMenampilkanFallback(): void
        {
            $rows = [];

            $kendalaList = filterKendalaUnik($rows, 'kendala');
            $html        = laporanRenderBab3Unit($kendalaList);

            $this->assertStringContainsString(
                'Tidak ditemukan kendala dalam pelaksanaan kegiatan',
                $html,
                'Rows kosong harus menghasilkan teks fallback'
            );
        }

        // ════════════════════════════════════════════════════════════
        //  PROPERTY 7 — Data provider: berbagai jumlah nilai kendala
        //  Validates: Requirements 7.2
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 7.2**
         *
         * Property 7 (parametrik): Berbagai jumlah nilai kendala — semua harus
         * muncul di output dan <li> count sesuai.
         *
         * @dataProvider provideJumlahKendala
         */
        public function testProperty7JumlahBervariasi(array $kendalaList, int $expectedLiCount): void
        {
            $html = laporanRenderBab3Unit($kendalaList);

            $this->assertSame(
                $expectedLiCount,
                substr_count($html, '<li>'),
                "Harus ada tepat {$expectedLiCount} elemen <li>"
            );

            foreach ($kendalaList as $k) {
                $this->assertStringContainsString($k, $html,
                    "Nilai '{$k}' harus muncul di output");
            }
        }

        public static function provideJumlahKendala(): array
        {
            return [
                '1 kendala'  => [['Hambatan A'], 1],
                '2 kendala'  => [['Hambatan A', 'Hambatan B'], 2],
                '3 kendala'  => [['Hambatan A', 'Hambatan B', 'Hambatan C'], 3],
                '5 kendala'  => [
                    ['Kendala 1', 'Kendala 2', 'Kendala 3', 'Kendala 4', 'Kendala 5'], 5
                ],
            ];
        }
    }

} // end if class_exists PHPUnit\Framework\TestCase

// ─────────────────────────────────────────────────────────────────────────────
//  Standalone runner — jalankan via: php tests/laporan_monev_kendala_test.php
// ─────────────────────────────────────────────────────────────────────────────
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {

    $passed = 0;
    $failed = 0;
    $errors = [];

    $run = function (string $name, callable $fn) use (&$passed, &$failed, &$errors): void {
        try {
            $fn();
            $passed++;
            echo "  ✓ {$name}\n";
        } catch (Throwable $e) {
            $failed++;
            $msg = "  ✗ {$name}: " . $e->getMessage();
            $errors[] = $msg;
            echo $msg . "\n";
        }
    };

    $assertTrue = function (bool $cond, string $msg): void {
        if (!$cond) throw new RuntimeException($msg);
    };
    $assertFalse = function (bool $cond, string $msg) use ($assertTrue): void {
        $assertTrue(!$cond, $msg);
    };
    $assertSame = function (mixed $expected, mixed $actual, string $msg): void {
        if ($expected !== $actual) {
            throw new RuntimeException("{$msg} (diharapkan: " . var_export($expected, true) . ", dapat: " . var_export($actual, true) . ")");
        }
    };
    $assertEmpty = function (array $arr, string $msg) use ($assertTrue): void {
        $assertTrue(empty($arr), "{$msg} (array tidak kosong, berisi " . count($arr) . " item)");
    };
    $assertCount = function (int $expected, array $arr, string $msg): void {
        $actual = count($arr);
        if ($expected !== $actual) {
            throw new RuntimeException("{$msg} (diharapkan: {$expected}, dapat: {$actual})");
        }
    };
    $assertContains = function (string $needle, array|string $haystack, string $msg): void {
        $found = is_array($haystack) ? in_array($needle, $haystack, true) : str_contains($haystack, $needle);
        if (!$found) {
            throw new RuntimeException("{$msg} (mencari: '{$needle}')");
        }
    };
    $assertNotContains = function (string $needle, array|string $haystack, string $msg): void {
        $found = is_array($haystack) ? in_array($needle, $haystack, true) : str_contains($haystack, $needle);
        if ($found) {
            throw new RuntimeException("{$msg} (tidak boleh mengandung: '{$needle}')");
        }
    };
    $assertRegex = function (string $pattern, string $subject, string $msg) use ($assertTrue): void {
        $assertTrue((bool)preg_match($pattern, $subject), $msg);
    };

    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════════════╗\n";
    echo "║  Property 7 & 8: Agregasi Kendala BAB III                           ║\n";
    echo "║  Validates: Requirements 7.2, 7.3                                   ║\n";
    echo "╚══════════════════════════════════════════════════════════════════════╝\n\n";

    // ── BAGIAN 1: filterKendalaUnik ───────────────────────────────────────────

    echo "1. filterKendalaUnik — logika filter (IS NOT NULL AND <> '' AND DISTINCT)\n";

    $run('Nilai non-NULL dikembalikan; NULL diabaikan', function () use ($assertCount, $assertContains): void {
        $rows = [
            ['kendala' => 'Keterbatasan anggaran'],
            ['kendala' => null],
            ['kendala' => 'Kurangnya SDM'],
        ];
        $result = filterKendalaUnik($rows, 'kendala');
        $assertCount(2, $result, 'Harus mengembalikan 2 nilai non-NULL');
        $assertContains('Keterbatasan anggaran', $result, 'Nilai pertama harus ada');
        $assertContains('Kurangnya SDM', $result, 'Nilai ketiga harus ada');
    });

    $run('String kosong "" diabaikan', function () use ($assertCount, $assertContains): void {
        $rows = [
            ['kendala' => 'Hambatan koordinasi'],
            ['kendala' => ''],
            ['kendala' => 'Hambatan anggaran'],
        ];
        $result = filterKendalaUnik($rows, 'kendala');
        $assertCount(2, $result, 'String kosong harus diabaikan');
        $assertContains('Hambatan koordinasi', $result, 'Nilai 1 harus ada');
        $assertContains('Hambatan anggaran', $result, 'Nilai 3 harus ada');
    });

    $run('Duplikat dihapus (DISTINCT)', function () use ($assertCount, $assertContains): void {
        $rows = [
            ['kendala' => 'Kurangnya SDM'],
            ['kendala' => 'Kurangnya SDM'],
            ['kendala' => 'Kurangnya SDM'],
            ['kendala' => 'Keterbatasan alat'],
        ];
        $result = filterKendalaUnik($rows, 'kendala');
        $assertCount(2, $result, 'Duplikat harus dihapus');
        $assertContains('Kurangnya SDM', $result, 'Nilai unik 1 harus ada');
        $assertContains('Keterbatasan alat', $result, 'Nilai unik 2 harus ada');
    });

    $run('Mix NULL + kosong + valid + duplikat → hanya nilai unik valid', function () use ($assertCount, $assertContains): void {
        $rows = [
            ['kendala' => null],
            ['kendala' => ''],
            ['kendala' => 'Hambatan A'],
            ['kendala' => null],
            ['kendala' => 'Hambatan B'],
            ['kendala' => ''],
            ['kendala' => 'Hambatan A'],
        ];
        $result = filterKendalaUnik($rows, 'kendala');
        $assertCount(2, $result, 'Hanya 2 nilai unik non-NULL non-kosong');
        $assertContains('Hambatan A', $result, 'Hambatan A harus ada');
        $assertContains('Hambatan B', $result, 'Hambatan B harus ada');
    });

    $run('Semua NULL → array kosong', function () use ($assertEmpty): void {
        $rows = [['kendala' => null], ['kendala' => null]];
        $result = filterKendalaUnik($rows, 'kendala');
        $assertEmpty($result, 'Semua NULL harus menghasilkan array kosong');
    });

    $run('Semua string kosong → array kosong', function () use ($assertEmpty): void {
        $rows = [['kendala' => ''], ['kendala' => '']];
        $result = filterKendalaUnik($rows, 'kendala');
        $assertEmpty($result, 'Semua string kosong harus menghasilkan array kosong');
    });

    $run('Rows kosong [] → array kosong', function () use ($assertEmpty): void {
        $result = filterKendalaUnik([], 'kendala');
        $assertEmpty($result, 'Rows kosong harus menghasilkan array kosong');
    });

    $run('Bekerja untuk kolom rencana_tindak_lanjut', function () use ($assertCount, $assertContains): void {
        $rows = [
            ['rencana_tindak_lanjut' => 'RTL A'],
            ['rencana_tindak_lanjut' => null],
            ['rencana_tindak_lanjut' => 'RTL B'],
            ['rencana_tindak_lanjut' => 'RTL A'],
        ];
        $result = filterKendalaUnik($rows, 'rencana_tindak_lanjut');
        $assertCount(2, $result, 'Hanya 2 nilai RTL unik');
        $assertContains('RTL A', $result, 'RTL A harus ada');
        $assertContains('RTL B', $result, 'RTL B harus ada');
    });

    // ── BAGIAN 2: laporanRenderBab3Unit — Property 7 ─────────────────────────

    echo "\n2. laporanRenderBab3Unit — Property 7: semua nilai non-kosong unik muncul\n";

    $run('Semua nilai dalam daftar muncul di output', function () use ($assertContains): void {
        $kendalaList = ['Keterbatasan anggaran', 'Kurangnya SDM', 'Hambatan koordinasi'];
        $html        = laporanRenderBab3Unit($kendalaList);
        foreach ($kendalaList as $k) {
            $assertContains($k, $html, "Nilai '{$k}' harus muncul di output");
        }
    });

    $run('Setiap nilai dibungkus dalam elemen <li>', function () use ($assertContains): void {
        $html = laporanRenderBab3Unit(['Hambatan A', 'Hambatan B']);
        $assertContains('<li>Hambatan A</li>', $html, 'Hambatan A harus dalam <li>');
        $assertContains('<li>Hambatan B</li>', $html, 'Hambatan B harus dalam <li>');
    });

    $run('Jumlah <li> sesuai jumlah nilai dalam daftar', function () use ($assertSame): void {
        $html = laporanRenderBab3Unit(['K1', 'K2', 'K3']);
        $assertSame(3, substr_count($html, '<li>'), 'Harus ada tepat 3 elemen <li>');
    });

    $run('Daftar kendala dibungkus dalam <ol>', function () use ($assertContains): void {
        $html = laporanRenderBab3Unit(['Hambatan A']);
        $assertContains('<ol>', $html, '<ol> harus ada');
        $assertContains('</ol>', $html, '</ol> harus ada');
    });

    $run('Teks pengantar muncul saat ada nilai', function () use ($assertContains, $assertNotContains): void {
        $html = laporanRenderBab3Unit(['Hambatan A']);
        $assertContains('Hambatan yang ditemui meliputi', $html, 'Teks pengantar harus ada');
        $assertNotContains('Tidak ditemukan kendala dalam pelaksanaan kegiatan', $html,
            'Fallback tidak boleh muncul saat ada nilai');
    });

    $run('Karakter HTML di-escape (< > & ")', function () use ($assertNotContains, $assertContains): void {
        $html = laporanRenderBab3Unit(['Hambatan <b>anggaran</b> & "SDM"']);
        $assertNotContains('<b>anggaran</b>', $html, 'Tag <b> mentah tidak boleh muncul');
        $assertContains('&lt;b&gt;', $html, 'Karakter < > harus di-escape');
        $assertContains('&amp;', $html, 'Karakter & harus di-escape');
    });

    $run('Tanda kutip di-escape menjadi &quot;', function () use ($assertContains): void {
        $html = laporanRenderBab3Unit(['Kendala "kritis"']);
        $assertContains('&quot;kritis&quot;', $html, 'Tanda kutip harus di-escape menjadi &quot;');
    });

    // ── BAGIAN 3: laporanRenderBab3Unit — Property 8 ─────────────────────────

    echo "\n3. laporanRenderBab3Unit — Property 8: fallback saat semua nilai kosong\n";

    $run('Daftar kosong [] → teks fallback muncul', function () use ($assertContains): void {
        $html = laporanRenderBab3Unit([]);
        $assertContains('Tidak ditemukan kendala dalam pelaksanaan kegiatan', $html,
            'Daftar kosong harus memunculkan teks fallback');
    });

    $run('Teks fallback berada di dalam elemen <p>', function () use ($assertRegex): void {
        $html = laporanRenderBab3Unit([]);
        $assertRegex(
            '/<p>.*Tidak ditemukan kendala dalam pelaksanaan kegiatan.*<\/p>/s',
            $html,
            'Teks fallback harus berada di dalam elemen <p>'
        );
    });

    $run('Elemen <ol> tidak muncul saat daftar kosong', function () use ($assertNotContains): void {
        $html = laporanRenderBab3Unit([]);
        $assertNotContains('<ol>', $html, '<ol> tidak boleh muncul saat fallback');
    });

    $run('Elemen <li> tidak muncul saat daftar kosong', function () use ($assertNotContains): void {
        $html = laporanRenderBab3Unit([]);
        $assertNotContains('<li>', $html, '<li> tidak boleh muncul saat fallback');
    });

    $run('Satu nilai saja → fallback tidak muncul, nilai muncul', function () use ($assertContains, $assertNotContains): void {
        $html = laporanRenderBab3Unit(['Hambatan tunggal']);
        $assertContains('Hambatan tunggal', $html, 'Nilai harus muncul');
        $assertNotContains('Tidak ditemukan kendala dalam pelaksanaan kegiatan', $html,
            'Fallback tidak boleh muncul saat ada nilai');
    });

    // ── BAGIAN 4: Tes Integrasi filterKendalaUnik + laporanRenderBab3Unit ─────

    echo "\n4. Integrasi filter + render\n";

    $run('Mix NULL/kosong/valid + duplikat → hanya unik muncul di HTML', function () use ($assertContains, $assertNotContains, $assertSame): void {
        $rows = [
            ['kendala' => 'Hambatan A'],
            ['kendala' => null],
            ['kendala' => 'Hambatan B'],
            ['kendala' => ''],
            ['kendala' => 'Hambatan A'],
            ['kendala' => 'Hambatan C'],
        ];
        $list = filterKendalaUnik($rows, 'kendala');
        $html = laporanRenderBab3Unit($list);

        $assertContains('Hambatan A', $html, 'Hambatan A harus muncul');
        $assertContains('Hambatan B', $html, 'Hambatan B harus muncul');
        $assertContains('Hambatan C', $html, 'Hambatan C harus muncul');
        $assertSame(3, substr_count($html, '<li>'), 'Tepat 3 <li>');
        $assertNotContains('Tidak ditemukan kendala dalam pelaksanaan kegiatan', $html,
            'Fallback tidak boleh muncul saat ada nilai');
    });

    $run('Semua NULL + kosong → array kosong → fallback di HTML', function () use ($assertEmpty, $assertContains): void {
        $rows = [['kendala' => null], ['kendala' => ''], ['kendala' => null]];
        $list = filterKendalaUnik($rows, 'kendala');
        $html = laporanRenderBab3Unit($list);

        $assertEmpty($list, 'filterKendalaUnik harus mengembalikan array kosong');
        $assertContains('Tidak ditemukan kendala dalam pelaksanaan kegiatan', $html,
            'Fallback harus muncul saat semua NULL/kosong');
    });

    $run('Rows kosong [] → array kosong → fallback di HTML', function () use ($assertEmpty, $assertContains): void {
        $list = filterKendalaUnik([], 'kendala');
        $html = laporanRenderBab3Unit($list);

        $assertEmpty($list, 'Rows kosong harus menghasilkan array kosong');
        $assertContains('Tidak ditemukan kendala dalam pelaksanaan kegiatan', $html,
            'Fallback harus muncul');
    });

    // ── BAGIAN 5: Parametrik jumlah kendala ──────────────────────────────────

    echo "\n5. Parametrik: berbagai jumlah nilai kendala\n";

    $kasusJumlah = [
        ['1 kendala',  ['Hambatan A'], 1],
        ['2 kendala',  ['Hambatan A', 'Hambatan B'], 2],
        ['3 kendala',  ['Hambatan A', 'Hambatan B', 'Hambatan C'], 3],
        ['5 kendala',  ['K1', 'K2', 'K3', 'K4', 'K5'], 5],
    ];

    foreach ($kasusJumlah as [$label, $list, $expectedLi]) {
        $run("jumlah={$label}: tepat {$expectedLi} elemen <li> dan semua nilai muncul",
            function () use ($assertSame, $assertContains, $list, $expectedLi): void {
                $html = laporanRenderBab3Unit($list);
                $assertSame($expectedLi, substr_count($html, '<li>'),
                    "Harus ada tepat {$expectedLi} elemen <li>");
                foreach ($list as $k) {
                    $assertContains($k, $html, "Nilai '{$k}' harus muncul");
                }
            }
        );
    }

    // ── Ringkasan ─────────────────────────────────────────────────────────────

    $total = $passed + $failed;
    echo "\n";
    echo str_repeat('─', 72) . "\n";
    if ($failed === 0) {
        echo "  ✓ Semua {$total} test LULUS\n";
    } else {
        echo "  Hasil: {$passed}/{$total} test lulus, {$failed} GAGAL\n\n";
        echo "  Test yang gagal:\n";
        foreach ($errors as $err) {
            echo $err . "\n";
        }
    }
    echo str_repeat('─', 72) . "\n\n";

    exit($failed > 0 ? 1 : 0);
}
