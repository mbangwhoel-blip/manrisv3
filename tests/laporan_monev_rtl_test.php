<?php
/**
 * Property-Based Tests: Agregasi Rencana Tindak Lanjut BAB IV
 *
 * Property 10: Agregasi rencana tindak lanjut mencakup semua nilai non-kosong
 *   Validates: Requirements 9.1
 *
 *   Untuk setiap unit kerja dengan satu atau lebih rencana_tindak_lanjut
 *   yang tidak NULL dan tidak kosong untuk triwulan yang dipilih, semua nilai
 *   unik tersebut SHALL muncul dalam output BAB IV unit yang bersangkutan.
 *
 * Property 11: Fallback rencana tindak lanjut saat semua nilai kosong
 *   Validates: Requirements 9.2
 *
 *   Untuk setiap unit kerja di mana seluruh nilai rencana_tindak_lanjut adalah
 *   NULL atau kosong, output BAB IV SHALL mengandung teks
 *   "Rencana Tindak Lanjut yang akan dilakukan adalah melanjutkan upaya
 *    pengendalian yang sudah direncanakan."
 *
 * Jalankan via PHPUnit  : php vendor/bin/phpunit tests/laporan_monev_rtl_test.php
 * Jalankan standalone   : php tests/laporan_monev_rtl_test.php
 */

// ─────────────────────────────────────────────────────────────────────────────
//  Reuse filterKendalaUnik() dari laporan_monev_kendala_test.php
//  Fungsi tersebut bekerja untuk kolom apapun, termasuk rencana_tindak_lanjut.
// ─────────────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/laporan_monev_kendala_test.php';

// ─────────────────────────────────────────────────────────────────────────────
//  Fungsi murni yang mencerminkan logika BAB IV di modules/laporan_monev.php
//
//  Tidak bergantung pada DB, session, atau auth.
//  Teks fallback berbeda dari BAB III (kendala).
// ─────────────────────────────────────────────────────────────────────────────

const RTL_FALLBACK_TEXT = 'Rencana Tindak Lanjut yang akan dilakukan adalah melanjutkan upaya pengendalian yang sudah direncanakan.';

/**
 * Menghasilkan HTML untuk bagian RTL (Rencana Tindak Lanjut) satu unit kerja
 * di dalam BAB IV.
 *
 * Logika identik dengan loop BAB IV di modules/laporan_monev.php:
 *
 *   if (empty($rtlList)):
 *     <p>Rencana Tindak Lanjut yang akan dilakukan adalah melanjutkan upaya
 *        pengendalian yang sudah direncanakan.</p>
 *   else:
 *     <p>Rencana tindak lanjut yang akan dilakukan meliputi:</p>
 *     <ol>
 *       <li>nilai1</li>
 *       ...
 *     </ol>
 *   endif
 *
 * Semua nilai di-escape dengan testXss() sebelum ditulis ke HTML.
 *
 * @param string[] $rtlList   Hasil dari filterKendalaUnik(..., 'rencana_tindak_lanjut')
 * @param string   $unitKerja Nama unit kerja untuk heading (opsional, default '')
 * @return string  HTML bagian RTL BAB IV untuk unit kerja ini
 */
function laporanRenderBab4RtlUnit(array $rtlList, string $unitKerja = ''): string
{
    $html = '';

    if ($unitKerja !== '') {
        $html .= '<h3 class="subbab-heading">' . testXss($unitKerja) . '</h3>' . "\n";
    }

    if (empty($rtlList)) {
        $html .= '<p>' . RTL_FALLBACK_TEXT . '</p>' . "\n";
    } else {
        $html .= '<p>Rencana tindak lanjut yang akan dilakukan meliputi:</p>' . "\n";
        $html .= '<ol>' . "\n";
        foreach ($rtlList as $rtl) {
            $html .= '<li>' . testXss($rtl) . '</li>' . "\n";
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
     * Tes untuk Property 10 (agregasi RTL non-kosong unik) dan
     * Property 11 (fallback ketika semua nilai kosong/NULL).
     *
     * **Validates: Requirements 9.1, 9.2**
     */
    class LaporanMonevRtlTest extends \PHPUnit\Framework\TestCase
    {
        // ════════════════════════════════════════════════════════════
        //  HELPER: filterKendalaUnik untuk kolom rencana_tindak_lanjut
        //  Menguji bahwa fungsi yang sama bekerja untuk RTL
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 9.1**
         *
         * Nilai unik non-kosong harus dikembalikan; nilai NULL diabaikan.
         */
        public function testFilterRtlMengembalikanNilaiNonNull(): void
        {
            $rows = [
                ['rencana_tindak_lanjut' => 'Meningkatkan anggaran'],
                ['rencana_tindak_lanjut' => null],
                ['rencana_tindak_lanjut' => 'Menambah SDM'],
            ];

            $result = filterKendalaUnik($rows, 'rencana_tindak_lanjut');

            $this->assertContains('Meningkatkan anggaran', $result);
            $this->assertContains('Menambah SDM', $result);
            $this->assertCount(2, $result, 'Hanya 2 nilai non-NULL yang harus dikembalikan');
        }

        /**
         * **Validates: Requirements 9.1**
         *
         * String kosong "" harus diabaikan.
         */
        public function testFilterRtlMengabaikanStringKosong(): void
        {
            $rows = [
                ['rencana_tindak_lanjut' => 'RTL pertama'],
                ['rencana_tindak_lanjut' => ''],
                ['rencana_tindak_lanjut' => 'RTL kedua'],
            ];

            $result = filterKendalaUnik($rows, 'rencana_tindak_lanjut');

            $this->assertContains('RTL pertama', $result);
            $this->assertContains('RTL kedua', $result);
            $this->assertCount(2, $result, 'String kosong harus diabaikan');
        }

        /**
         * **Validates: Requirements 9.1**
         *
         * Nilai duplikat harus dijadikan satu (DISTINCT).
         */
        public function testFilterRtlMenghapusDuplikat(): void
        {
            $rows = [
                ['rencana_tindak_lanjut' => 'Koordinasi lintas unit'],
                ['rencana_tindak_lanjut' => 'Koordinasi lintas unit'],
                ['rencana_tindak_lanjut' => 'Koordinasi lintas unit'],
                ['rencana_tindak_lanjut' => 'Pelatihan SDM'],
            ];

            $result = filterKendalaUnik($rows, 'rencana_tindak_lanjut');

            $this->assertCount(2, $result, 'Duplikat harus dihapus — hanya 2 nilai unik');
            $this->assertContains('Koordinasi lintas unit', $result);
            $this->assertContains('Pelatihan SDM', $result);
        }

        /**
         * **Validates: Requirements 9.2**
         *
         * Jika semua nilai NULL, hasil harus array kosong.
         */
        public function testFilterRtlSemuaNullMengembalikanArrayKosong(): void
        {
            $rows = [
                ['rencana_tindak_lanjut' => null],
                ['rencana_tindak_lanjut' => null],
            ];

            $result = filterKendalaUnik($rows, 'rencana_tindak_lanjut');

            $this->assertEmpty($result, 'Semua NULL harus menghasilkan array kosong');
        }

        /**
         * **Validates: Requirements 9.2**
         *
         * Jika semua nilai string kosong, hasil harus array kosong.
         */
        public function testFilterRtlSemuaStringKosongMengembalikanArrayKosong(): void
        {
            $rows = [
                ['rencana_tindak_lanjut' => ''],
                ['rencana_tindak_lanjut' => ''],
            ];

            $result = filterKendalaUnik($rows, 'rencana_tindak_lanjut');

            $this->assertEmpty($result, 'Semua string kosong harus menghasilkan array kosong');
        }

        // ════════════════════════════════════════════════════════════
        //  PROPERTY 10 — Render BAB IV RTL: semua nilai non-kosong muncul
        //  Validates: Requirements 9.1
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 9.1**
         *
         * Property 10: Semua nilai RTL non-kosong harus muncul dalam output HTML.
         */
        public function testProperty10SemuaNilaiNonKosongMunculDiOutput(): void
        {
            $rtlList = ['Meningkatkan anggaran', 'Menambah SDM', 'Koordinasi lintas unit'];
            $html    = laporanRenderBab4RtlUnit($rtlList);

            foreach ($rtlList as $rtl) {
                $this->assertStringContainsString(
                    $rtl,
                    $html,
                    "Nilai RTL '{$rtl}' harus muncul dalam output BAB IV"
                );
            }
        }

        /**
         * **Validates: Requirements 9.1**
         *
         * Property 10: Setiap nilai RTL harus dibungkus dalam elemen <li>.
         */
        public function testProperty10NilaiRtlDibungkusDalamLi(): void
        {
            $rtlList = ['RTL A', 'RTL B'];
            $html    = laporanRenderBab4RtlUnit($rtlList);

            $this->assertStringContainsString('<li>RTL A</li>', $html,
                'RTL A harus berada di dalam elemen <li>');
            $this->assertStringContainsString('<li>RTL B</li>', $html,
                'RTL B harus berada di dalam elemen <li>');
        }

        /**
         * **Validates: Requirements 9.1**
         *
         * Property 10: Jumlah elemen <li> harus sama dengan jumlah nilai RTL.
         */
        public function testProperty10JumlahLiSesuaiJumlahRtl(): void
        {
            $rtlList = ['RTL 1', 'RTL 2', 'RTL 3'];
            $html    = laporanRenderBab4RtlUnit($rtlList);

            $this->assertSame(
                3,
                substr_count($html, '<li>'),
                'Jumlah elemen <li> harus sama dengan jumlah nilai RTL unik'
            );
        }

        /**
         * **Validates: Requirements 9.1**
         *
         * Property 10: Daftar RTL harus dibungkus dalam elemen <ol>.
         */
        public function testProperty10RtlDibungkusDalamOl(): void
        {
            $rtlList = ['RTL A'];
            $html    = laporanRenderBab4RtlUnit($rtlList);

            $this->assertStringContainsString('<ol>', $html,
                'Daftar RTL harus dibungkus dalam elemen <ol>');
            $this->assertStringContainsString('</ol>', $html,
                'Elemen <ol> harus ditutup');
        }

        /**
         * **Validates: Requirements 9.1**
         *
         * Property 10: Ketika ada RTL, teks pengantar harus muncul —
         * bukan teks fallback.
         */
        public function testProperty10TeksPermulaan(): void
        {
            $rtlList = ['RTL A'];
            $html    = laporanRenderBab4RtlUnit($rtlList);

            $this->assertStringContainsString(
                'Rencana tindak lanjut yang akan dilakukan meliputi',
                $html,
                'Teks pengantar daftar RTL harus muncul'
            );
            $this->assertStringNotContainsString(
                RTL_FALLBACK_TEXT,
                $html,
                'Teks fallback tidak boleh muncul ketika ada nilai RTL'
            );
        }

        // ════════════════════════════════════════════════════════════
        //  PROPERTY 10 — XSS escaping pada nilai RTL
        //  Validates: Requirements 9.1, 10.4
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 9.1, 10.4**
         *
         * Property 10: Nilai RTL yang mengandung karakter HTML harus
         * di-escape sebelum dimasukkan ke output.
         */
        public function testProperty10NilaiRtlDeganKarakterHtmlDiEscape(): void
        {
            $rtlList = ['Lanjutkan program <b>prioritas</b> & "anggaran"'];
            $html    = laporanRenderBab4RtlUnit($rtlList);

            // Nilai mentah tidak boleh muncul
            $this->assertStringNotContainsString(
                '<b>prioritas</b>',
                $html,
                'Tag HTML mentah dalam nilai RTL tidak boleh muncul di output'
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
         * **Validates: Requirements 9.1, 10.4**
         *
         * Property 10: Tanda kutip dalam nilai RTL harus di-escape.
         */
        public function testProperty10TandaKutipDiEscape(): void
        {
            $rtlList = ['RTL "prioritas utama"'];
            $html    = laporanRenderBab4RtlUnit($rtlList);

            $this->assertStringContainsString(
                '&quot;prioritas utama&quot;',
                $html,
                'Tanda kutip ganda harus di-escape menjadi &quot;'
            );
        }

        // ════════════════════════════════════════════════════════════
        //  PROPERTY 11 — Fallback ketika semua nilai kosong/NULL
        //  Validates: Requirements 9.2
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 9.2**
         *
         * Property 11: Daftar RTL kosong HARUS menghasilkan teks fallback.
         */
        public function testProperty11DaftarKosongMenampilkanTeksFallback(): void
        {
            $html = laporanRenderBab4RtlUnit([]);

            $this->assertStringContainsString(
                RTL_FALLBACK_TEXT,
                $html,
                'Daftar kosong harus menghasilkan teks fallback RTL'
            );
        }

        /**
         * **Validates: Requirements 9.2**
         *
         * Property 11: Teks fallback harus berada di dalam elemen <p>.
         */
        public function testProperty11TeksFallbackDidalamElemenP(): void
        {
            $html = laporanRenderBab4RtlUnit([]);

            $this->assertMatchesRegularExpression(
                '/<p>.*melanjutkan upaya pengendalian.*<\/p>/s',
                $html,
                'Teks fallback harus berada di dalam elemen <p>'
            );
        }

        /**
         * **Validates: Requirements 9.2**
         *
         * Property 11: Ketika daftar kosong, elemen <ol> TIDAK BOLEH muncul.
         */
        public function testProperty11TidakAdaOlSaatFallback(): void
        {
            $html = laporanRenderBab4RtlUnit([]);

            $this->assertStringNotContainsString(
                '<ol>',
                $html,
                'Elemen <ol> tidak boleh muncul saat daftar kosong'
            );
        }

        /**
         * **Validates: Requirements 9.2**
         *
         * Property 11: Ketika daftar kosong, elemen <li> TIDAK BOLEH muncul.
         */
        public function testProperty11TidakAdaLiSaatFallback(): void
        {
            $html = laporanRenderBab4RtlUnit([]);

            $this->assertStringNotContainsString(
                '<li>',
                $html,
                'Elemen <li> tidak boleh muncul saat daftar kosong'
            );
        }

        /**
         * **Validates: Requirements 9.2**
         *
         * Property 11: Ketika ada setidaknya satu nilai non-kosong,
         * teks fallback TIDAK BOLEH muncul.
         */
        public function testProperty11FallbackTidakMunculSaatAdaNilai(): void
        {
            $rtlList = ['Satu rencana tindak lanjut'];
            $html    = laporanRenderBab4RtlUnit($rtlList);

            $this->assertStringNotContainsString(
                RTL_FALLBACK_TEXT,
                $html,
                'Teks fallback tidak boleh muncul saat ada nilai RTL'
            );
        }

        /**
         * **Validates: Requirements 9.2**
         *
         * Property 11: Bahkan dengan satu nilai saja, fallback tidak muncul
         * dan nilai tersebut muncul di output.
         */
        public function testProperty11SatuNilaiSajaTidakMemicuFallback(): void
        {
            $rtlList = ['RTL tunggal'];
            $html    = laporanRenderBab4RtlUnit($rtlList);

            $this->assertStringContainsString('RTL tunggal', $html,
                'Nilai tunggal harus muncul di output');
            $this->assertStringNotContainsString(
                RTL_FALLBACK_TEXT,
                $html,
                'Fallback tidak boleh muncul saat ada nilai RTL'
            );
        }

        // ════════════════════════════════════════════════════════════
        //  INTEGRASI filterKendalaUnik + laporanRenderBab4RtlUnit
        //  Validates: Requirements 9.1, 9.2
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 9.1**
         *
         * Integrasi: rows dengan campuran NULL/kosong/valid dan duplikat →
         * hanya nilai unik non-kosong muncul di output HTML.
         */
        public function testIntegrasiFilterDanRenderSemuaNilaiUnikMunculDiHtml(): void
        {
            $rows = [
                ['rencana_tindak_lanjut' => 'RTL A'],
                ['rencana_tindak_lanjut' => null],
                ['rencana_tindak_lanjut' => 'RTL B'],
                ['rencana_tindak_lanjut' => ''],
                ['rencana_tindak_lanjut' => 'RTL A'],   // duplikat
                ['rencana_tindak_lanjut' => 'RTL C'],
            ];

            $rtlList = filterKendalaUnik($rows, 'rencana_tindak_lanjut');
            $html    = laporanRenderBab4RtlUnit($rtlList);

            // Semua nilai unik harus muncul
            $this->assertStringContainsString('RTL A', $html);
            $this->assertStringContainsString('RTL B', $html);
            $this->assertStringContainsString('RTL C', $html);

            // Tepat 3 elemen <li>
            $this->assertSame(3, substr_count($html, '<li>'),
                'Harus tepat 3 elemen <li> — satu per nilai unik');

            // Teks fallback tidak boleh muncul
            $this->assertStringNotContainsString(
                RTL_FALLBACK_TEXT,
                $html,
                'Fallback tidak boleh muncul saat ada nilai'
            );
        }

        /**
         * **Validates: Requirements 9.2**
         *
         * Integrasi: rows dengan semua NULL dan kosong → array kosong →
         * teks fallback muncul di output HTML.
         */
        public function testIntegrasiFilterDanRenderSemuaKosongMenampilkanFallback(): void
        {
            $rows = [
                ['rencana_tindak_lanjut' => null],
                ['rencana_tindak_lanjut' => ''],
                ['rencana_tindak_lanjut' => null],
            ];

            $rtlList = filterKendalaUnik($rows, 'rencana_tindak_lanjut');
            $html    = laporanRenderBab4RtlUnit($rtlList);

            $this->assertEmpty($rtlList, 'filterKendalaUnik harus mengembalikan array kosong');
            $this->assertStringContainsString(
                RTL_FALLBACK_TEXT,
                $html,
                'Fallback harus muncul saat semua nilai NULL/kosong'
            );
        }

        /**
         * **Validates: Requirements 9.2**
         *
         * Integrasi: rows kosong [] → array kosong → teks fallback muncul.
         */
        public function testIntegrasiFilterDanRenderRowsKosongMenampilkanFallback(): void
        {
            $rows = [];

            $rtlList = filterKendalaUnik($rows, 'rencana_tindak_lanjut');
            $html    = laporanRenderBab4RtlUnit($rtlList);

            $this->assertStringContainsString(
                RTL_FALLBACK_TEXT,
                $html,
                'Rows kosong harus menghasilkan teks fallback RTL'
            );
        }

        // ════════════════════════════════════════════════════════════
        //  PROPERTY 10 — Data provider: berbagai jumlah nilai RTL
        //  Validates: Requirements 9.1
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 9.1**
         *
         * Property 10 (parametrik): Berbagai jumlah nilai RTL — semua harus
         * muncul di output dan <li> count sesuai.
         *
         * @dataProvider provideJumlahRtl
         */
        public function testProperty10JumlahBervariasi(array $rtlList, int $expectedLiCount): void
        {
            $html = laporanRenderBab4RtlUnit($rtlList);

            $this->assertSame(
                $expectedLiCount,
                substr_count($html, '<li>'),
                "Harus ada tepat {$expectedLiCount} elemen <li>"
            );

            foreach ($rtlList as $rtl) {
                $this->assertStringContainsString($rtl, $html,
                    "Nilai '{$rtl}' harus muncul di output");
            }
        }

        public static function provideJumlahRtl(): array
        {
            return [
                '1 RTL'  => [['RTL A'], 1],
                '2 RTL'  => [['RTL A', 'RTL B'], 2],
                '3 RTL'  => [['RTL A', 'RTL B', 'RTL C'], 3],
                '5 RTL'  => [
                    ['RTL 1', 'RTL 2', 'RTL 3', 'RTL 4', 'RTL 5'], 5
                ],
            ];
        }
    }

} // end if class_exists PHPUnit\Framework\TestCase

// ─────────────────────────────────────────────────────────────────────────────
//  Standalone runner — jalankan via: php tests/laporan_monev_rtl_test.php
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
    echo "║  Property 10 & 11: Agregasi Rencana Tindak Lanjut BAB IV            ║\n";
    echo "║  Validates: Requirements 9.1, 9.2                                   ║\n";
    echo "╚══════════════════════════════════════════════════════════════════════╝\n\n";

    // ── BAGIAN 1: filterKendalaUnik untuk rencana_tindak_lanjut ──────────────

    echo "1. filterKendalaUnik — logika filter untuk kolom rencana_tindak_lanjut\n";

    $run('Nilai non-NULL dikembalikan; NULL diabaikan', function () use ($assertCount, $assertContains): void {
        $rows = [
            ['rencana_tindak_lanjut' => 'Meningkatkan anggaran'],
            ['rencana_tindak_lanjut' => null],
            ['rencana_tindak_lanjut' => 'Menambah SDM'],
        ];
        $result = filterKendalaUnik($rows, 'rencana_tindak_lanjut');
        $assertCount(2, $result, 'Harus mengembalikan 2 nilai non-NULL');
        $assertContains('Meningkatkan anggaran', $result, 'Nilai pertama harus ada');
        $assertContains('Menambah SDM', $result, 'Nilai ketiga harus ada');
    });

    $run('String kosong "" diabaikan', function () use ($assertCount, $assertContains): void {
        $rows = [
            ['rencana_tindak_lanjut' => 'RTL pertama'],
            ['rencana_tindak_lanjut' => ''],
            ['rencana_tindak_lanjut' => 'RTL kedua'],
        ];
        $result = filterKendalaUnik($rows, 'rencana_tindak_lanjut');
        $assertCount(2, $result, 'String kosong harus diabaikan');
        $assertContains('RTL pertama', $result, 'Nilai 1 harus ada');
        $assertContains('RTL kedua', $result, 'Nilai 3 harus ada');
    });

    $run('Duplikat dihapus (DISTINCT)', function () use ($assertCount, $assertContains): void {
        $rows = [
            ['rencana_tindak_lanjut' => 'Koordinasi lintas unit'],
            ['rencana_tindak_lanjut' => 'Koordinasi lintas unit'],
            ['rencana_tindak_lanjut' => 'Pelatihan SDM'],
        ];
        $result = filterKendalaUnik($rows, 'rencana_tindak_lanjut');
        $assertCount(2, $result, 'Duplikat harus dihapus');
        $assertContains('Koordinasi lintas unit', $result, 'Nilai unik 1 harus ada');
        $assertContains('Pelatihan SDM', $result, 'Nilai unik 2 harus ada');
    });

    $run('Semua NULL → array kosong', function () use ($assertEmpty): void {
        $rows = [['rencana_tindak_lanjut' => null], ['rencana_tindak_lanjut' => null]];
        $result = filterKendalaUnik($rows, 'rencana_tindak_lanjut');
        $assertEmpty($result, 'Semua NULL harus menghasilkan array kosong');
    });

    $run('Semua string kosong → array kosong', function () use ($assertEmpty): void {
        $rows = [['rencana_tindak_lanjut' => ''], ['rencana_tindak_lanjut' => '']];
        $result = filterKendalaUnik($rows, 'rencana_tindak_lanjut');
        $assertEmpty($result, 'Semua string kosong harus menghasilkan array kosong');
    });

    // ── BAGIAN 2: laporanRenderBab4RtlUnit — Property 10 ─────────────────────

    echo "\n2. laporanRenderBab4RtlUnit — Property 10: semua nilai non-kosong unik muncul\n";

    $run('Semua nilai dalam daftar muncul di output', function () use ($assertContains): void {
        $rtlList = ['Meningkatkan anggaran', 'Menambah SDM', 'Koordinasi lintas unit'];
        $html    = laporanRenderBab4RtlUnit($rtlList);
        foreach ($rtlList as $rtl) {
            $assertContains($rtl, $html, "Nilai '{$rtl}' harus muncul di output");
        }
    });

    $run('Setiap nilai dibungkus dalam elemen <li>', function () use ($assertContains): void {
        $html = laporanRenderBab4RtlUnit(['RTL A', 'RTL B']);
        $assertContains('<li>RTL A</li>', $html, 'RTL A harus dalam <li>');
        $assertContains('<li>RTL B</li>', $html, 'RTL B harus dalam <li>');
    });

    $run('Jumlah <li> sesuai jumlah nilai dalam daftar', function () use ($assertSame): void {
        $html = laporanRenderBab4RtlUnit(['R1', 'R2', 'R3']);
        $assertSame(3, substr_count($html, '<li>'), 'Harus ada tepat 3 elemen <li>');
    });

    $run('Daftar RTL dibungkus dalam <ol>', function () use ($assertContains): void {
        $html = laporanRenderBab4RtlUnit(['RTL A']);
        $assertContains('<ol>', $html, '<ol> harus ada');
        $assertContains('</ol>', $html, '</ol> harus ada');
    });

    $run('Teks pengantar muncul saat ada nilai', function () use ($assertContains, $assertNotContains): void {
        $html = laporanRenderBab4RtlUnit(['RTL A']);
        $assertContains('Rencana tindak lanjut yang akan dilakukan meliputi', $html,
            'Teks pengantar harus ada');
        $assertNotContains(RTL_FALLBACK_TEXT, $html, 'Fallback tidak boleh muncul saat ada nilai');
    });

    $run('Karakter HTML di-escape (< > & ")', function () use ($assertNotContains, $assertContains): void {
        $html = laporanRenderBab4RtlUnit(['Lanjutkan <b>program</b> & "prioritas"']);
        $assertNotContains('<b>program</b>', $html, 'Tag <b> mentah tidak boleh muncul');
        $assertContains('&lt;b&gt;', $html, 'Karakter < > harus di-escape');
        $assertContains('&amp;', $html, 'Karakter & harus di-escape');
    });

    $run('Tanda kutip di-escape menjadi &quot;', function () use ($assertContains): void {
        $html = laporanRenderBab4RtlUnit(['RTL "prioritas"']);
        $assertContains('&quot;prioritas&quot;', $html, 'Tanda kutip harus di-escape');
    });

    // ── BAGIAN 3: laporanRenderBab4RtlUnit — Property 11 ─────────────────────

    echo "\n3. laporanRenderBab4RtlUnit — Property 11: fallback saat semua nilai kosong\n";

    $run('Daftar kosong [] → teks fallback muncul', function () use ($assertContains): void {
        $html = laporanRenderBab4RtlUnit([]);
        $assertContains(RTL_FALLBACK_TEXT, $html, 'Daftar kosong harus memunculkan teks fallback');
    });

    $run('Teks fallback berada di dalam elemen <p>', function () use ($assertRegex): void {
        $html = laporanRenderBab4RtlUnit([]);
        $assertRegex(
            '/<p>.*melanjutkan upaya pengendalian.*<\/p>/s',
            $html,
            'Teks fallback harus berada di dalam elemen <p>'
        );
    });

    $run('Elemen <ol> tidak muncul saat daftar kosong', function () use ($assertNotContains): void {
        $html = laporanRenderBab4RtlUnit([]);
        $assertNotContains('<ol>', $html, '<ol> tidak boleh muncul saat fallback');
    });

    $run('Elemen <li> tidak muncul saat daftar kosong', function () use ($assertNotContains): void {
        $html = laporanRenderBab4RtlUnit([]);
        $assertNotContains('<li>', $html, '<li> tidak boleh muncul saat fallback');
    });

    $run('Satu nilai saja → fallback tidak muncul, nilai muncul', function () use ($assertContains, $assertNotContains): void {
        $html = laporanRenderBab4RtlUnit(['RTL tunggal']);
        $assertContains('RTL tunggal', $html, 'Nilai harus muncul');
        $assertNotContains(RTL_FALLBACK_TEXT, $html, 'Fallback tidak boleh muncul saat ada nilai');
    });

    // ── BAGIAN 4: Integrasi filter + render ──────────────────────────────────

    echo "\n4. Integrasi filter + render\n";

    $run('Mix NULL/kosong/valid + duplikat → hanya unik muncul di HTML', function () use ($assertContains, $assertNotContains, $assertSame): void {
        $rows = [
            ['rencana_tindak_lanjut' => 'RTL A'],
            ['rencana_tindak_lanjut' => null],
            ['rencana_tindak_lanjut' => 'RTL B'],
            ['rencana_tindak_lanjut' => ''],
            ['rencana_tindak_lanjut' => 'RTL A'],
            ['rencana_tindak_lanjut' => 'RTL C'],
        ];
        $list = filterKendalaUnik($rows, 'rencana_tindak_lanjut');
        $html = laporanRenderBab4RtlUnit($list);

        $assertContains('RTL A', $html, 'RTL A harus muncul');
        $assertContains('RTL B', $html, 'RTL B harus muncul');
        $assertContains('RTL C', $html, 'RTL C harus muncul');
        $assertSame(3, substr_count($html, '<li>'), 'Tepat 3 <li>');
        $assertNotContains(RTL_FALLBACK_TEXT, $html, 'Fallback tidak boleh muncul saat ada nilai');
    });

    $run('Semua NULL + kosong → array kosong → fallback di HTML', function () use ($assertEmpty, $assertContains): void {
        $rows = [
            ['rencana_tindak_lanjut' => null],
            ['rencana_tindak_lanjut' => ''],
            ['rencana_tindak_lanjut' => null],
        ];
        $list = filterKendalaUnik($rows, 'rencana_tindak_lanjut');
        $html = laporanRenderBab4RtlUnit($list);

        $assertEmpty($list, 'filterKendalaUnik harus mengembalikan array kosong');
        $assertContains(RTL_FALLBACK_TEXT, $html, 'Fallback harus muncul saat semua NULL/kosong');
    });

    $run('Rows kosong [] → array kosong → fallback di HTML', function () use ($assertEmpty, $assertContains): void {
        $list = filterKendalaUnik([], 'rencana_tindak_lanjut');
        $html = laporanRenderBab4RtlUnit($list);

        $assertEmpty($list, 'Rows kosong harus menghasilkan array kosong');
        $assertContains(RTL_FALLBACK_TEXT, $html, 'Fallback harus muncul');
    });

    // ── BAGIAN 5: Parametrik jumlah RTL ──────────────────────────────────────

    echo "\n5. Parametrik: berbagai jumlah nilai RTL\n";

    $kasusJumlah = [
        ['1 RTL',  ['RTL A'], 1],
        ['2 RTL',  ['RTL A', 'RTL B'], 2],
        ['3 RTL',  ['RTL A', 'RTL B', 'RTL C'], 3],
        ['5 RTL',  ['R1', 'R2', 'R3', 'R4', 'R5'], 5],
    ];

    foreach ($kasusJumlah as [$label, $list, $expectedLi]) {
        $run("jumlah={$label}: tepat {$expectedLi} elemen <li> dan semua nilai muncul",
            function () use ($assertSame, $assertContains, $list, $expectedLi): void {
                $html = laporanRenderBab4RtlUnit($list);
                $assertSame($expectedLi, substr_count($html, '<li>'),
                    "Harus ada tepat {$expectedLi} elemen <li>");
                foreach ($list as $rtl) {
                    $assertContains($rtl, $html, "Nilai '{$rtl}' harus muncul");
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
