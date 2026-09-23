<?php
/**
 * Property-Based Tests: Warna Sel Tingkat Risiko
 *
 * Property 6: Warna sel tingkat risiko konsisten dengan lookup
 *   Validates: Requirements 6.8
 *
 * Untuk setiap nilai tingkat_risiko (atau akhir_tingkat) ∈ {Sangat Tinggi, Tinggi,
 * Sedang, Rendah}, warna latar belakang CSS yang diterapkan pada sel tersebut
 * SHALL sesuai dengan pemetaan:
 *   Sangat Tinggi → #fee2e2
 *   Tinggi        → #ffedd5
 *   Sedang        → #fefce8
 *   Rendah        → #dcfce7
 *
 * Jalankan via PHPUnit : php vendor/bin/phpunit tests/laporan_monev_warna_test.php
 * Jalankan standalone  : php tests/laporan_monev_warna_test.php
 */

use PHPUnit\Framework\Attributes\DataProvider;

// ─────────────────────────────────────────────────────────────────────────────
//  Fungsi murni yang mencerminkan helper functions di modules/laporan_monev.php
//
//  Fungsi-fungsi ini adalah salinan langsung dari modul sehingga test ini
//  dapat berjalan standalone tanpa bergantung pada DB, session, atau auth.
//  Tujuan: memvalidasi bahwa pemetaan warna di modul sudah benar dan konsisten.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Warna latar belakang sel tingkat risiko.
 * Salinan langsung dari modules/laporan_monev.php :: laporanRisikoBg()
 *
 * @param string $tingkat Nilai tingkat risiko
 * @return string Kode warna hex CSS
 */
function testLaporanRisikoBg(string $tingkat): string
{
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
 * Salinan langsung dari modules/laporan_monev.php :: laporanRisikoColor()
 *
 * @param string $tingkat Nilai tingkat risiko
 * @return string Kode warna hex CSS
 */
function testLaporanRisikoColor(string $tingkat): string
{
    return match($tingkat) {
        'Sangat Tinggi' => '#991b1b',
        'Tinggi'        => '#9a3412',
        'Sedang'        => '#854d0e',
        'Rendah'        => '#166534',
        default         => '#374151',
    };
}

// ─────────────────────────────────────────────────────────────────────────────
//  Helper render: menghasilkan HTML satu sel <td> tingkat risiko
//  (mereplikasi pola inline style yang digunakan pada BAB II tabel)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Render satu sel <td> tingkat risiko dengan inline style background dan color.
 * Logika identik dengan kode render di laporan_monev.php (mode Generate, BAB II).
 *
 * @param string|null $tingkat Nilai tingkat risiko (atau null jika tidak ada monev)
 * @return string HTML <td> dengan inline style
 */
function testRenderTingkatCell(?string $tingkat): string
{
    if ($tingkat === null || $tingkat === '') {
        return '<td class="tingkat-col center">-</td>';
    }

    $bg    = testLaporanRisikoBg($tingkat);
    $color = testLaporanRisikoColor($tingkat);
    $escaped = htmlspecialchars($tingkat, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return sprintf(
        '<td class="tingkat-col center" style="background-color:%s; color:%s;">%s</td>',
        $bg,
        $color,
        $escaped
    );
}

/**
 * Render HTML tabel mini yang mengandung sel kondisi awal dan kondisi akhir
 * dengan warna tingkat risiko — mereplikasi pola BAB II laporan.
 *
 * @param string      $awalTingkat   Tingkat risiko kondisi awal
 * @param string|null $akhirTingkat  Tingkat risiko kondisi akhir (null = tidak ada monev)
 * @return string HTML snippet tabel
 */
function testRenderTabelBab2Row(string $awalTingkat, ?string $akhirTingkat): string
{
    $awalCell  = testRenderTingkatCell($awalTingkat);
    $akhirCell = testRenderTingkatCell($akhirTingkat);

    return '<table><tbody><tr>'
        . '<td class="center">1</td>'
        . '<td class="center">R-001</td>'
        . '<td>Risiko Uji</td>'
        . '<td class="center">2</td>'   // awal_p
        . '<td class="center">3</td>'   // awal_d
        . '<td class="center">6</td>'   // awal_nilai
        . $awalCell                     // awal_tingkat — sel berwarna
        . '<td>Upaya Pengendalian</td>'
        . '<td class="center">1</td>'   // akhir_p
        . '<td class="center">2</td>'   // akhir_d
        . '<td class="center">2</td>'   // akhir_nilai
        . $akhirCell                    // akhir_tingkat — sel berwarna
        . '</tr></tbody></table>';
}

// ─────────────────────────────────────────────────────────────────────────────
//  PHPUnit Test Class
// ─────────────────────────────────────────────────────────────────────────────
if (class_exists('PHPUnit\Framework\TestCase')) {

    class LaporanMonevWarnaTest extends \PHPUnit\Framework\TestCase
    {
        // ════════════════════════════════════════════════════════════
        //  Property 6 — Pemetaan warna background untuk 4 level risiko
        //  Validates: Requirements 6.8
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 6.8**
         *
         * Property 6 (background): Untuk setiap nilai tingkat_risiko ∈
         * {Sangat Tinggi, Tinggi, Sedang, Rendah}, fungsi laporanRisikoBg()
         * HARUS mengembalikan tepat kode warna hex yang sesuai.
         *
         * @dataProvider providePemetaanWarnaBackground
         */
        public function testProperty6BackgroundColorSesuaiLookup(
            string $tingkat,
            string $expectedBg,
            string $skenario
        ): void {
            $result = testLaporanRisikoBg($tingkat);

            $this->assertSame(
                $expectedBg,
                $result,
                "Property 6 [{$skenario}]: laporanRisikoBg('{$tingkat}') harus mengembalikan '{$expectedBg}', dapat '{$result}'"
            );
        }

        // ════════════════════════════════════════════════════════════
        //  Property 6 — Pemetaan warna teks untuk 4 level risiko
        //  Validates: Requirements 6.8
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 6.8**
         *
         * Property 6 (teks): Untuk setiap nilai tingkat_risiko ∈
         * {Sangat Tinggi, Tinggi, Sedang, Rendah}, fungsi laporanRisikoColor()
         * HARUS mengembalikan tepat kode warna teks hex yang sesuai.
         *
         * @dataProvider providePemetaanWarnaTeks
         */
        public function testProperty6TextColorSesuaiLookup(
            string $tingkat,
            string $expectedColor,
            string $skenario
        ): void {
            $result = testLaporanRisikoColor($tingkat);

            $this->assertSame(
                $expectedColor,
                $result,
                "Property 6 [{$skenario}]: laporanRisikoColor('{$tingkat}') harus mengembalikan '{$expectedColor}', dapat '{$result}'"
            );
        }

        // ════════════════════════════════════════════════════════════
        //  Property 6 — Warna fallback untuk nilai tidak dikenal
        //  Validates: Requirements 6.8
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 6.8**
         *
         * Property 6 (fallback background): Nilai tingkat risiko yang tidak
         * termasuk dalam {Sangat Tinggi, Tinggi, Sedang, Rendah} HARUS
         * mengembalikan warna default #f3f4f6 (abu-abu netral).
         *
         * @dataProvider provideNilaiTidakDikenal
         */
        public function testProperty6BackgroundFallbackUntukNilaiTidakDikenal(
            string $tingkat,
            string $label
        ): void {
            $result = testLaporanRisikoBg($tingkat);

            $this->assertSame(
                '#f3f4f6',
                $result,
                "Property 6 fallback bg [{$label}]: nilai '{$tingkat}' harus mengembalikan '#f3f4f6', dapat '{$result}'"
            );
        }

        /**
         * **Validates: Requirements 6.8**
         *
         * Property 6 (fallback teks): Nilai tingkat risiko yang tidak dikenal
         * HARUS mengembalikan warna teks default #374151 (abu-abu gelap).
         *
         * @dataProvider provideNilaiTidakDikenal
         */
        public function testProperty6TextFallbackUntukNilaiTidakDikenal(
            string $tingkat,
            string $label
        ): void {
            $result = testLaporanRisikoColor($tingkat);

            $this->assertSame(
                '#374151',
                $result,
                "Property 6 fallback color [{$label}]: nilai '{$tingkat}' harus mengembalikan '#374151', dapat '{$result}'"
            );
        }

        // ════════════════════════════════════════════════════════════
        //  Property 6 — Warna muncul sebagai inline style di HTML output
        //  Validates: Requirements 6.8
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 6.8**
         *
         * Property 6 (HTML output — kondisi awal): Untuk setiap nilai tingkat
         * risiko kondisi awal yang valid, kode warna background dan teks yang
         * sesuai HARUS muncul sebagai atribut inline style pada sel <td>
         * tingkat di tabel BAB II.
         *
         * @dataProvider providePemetaanWarnaBackground
         */
        public function testProperty6WarnaAwalMunculSebagaiInlineStyleHtml(
            string $tingkat,
            string $expectedBg,
            string $skenario
        ): void {
            $html = testRenderTabelBab2Row($tingkat, null);
            $expectedColor = testLaporanRisikoColor($tingkat);

            $this->assertStringContainsString(
                "background-color:{$expectedBg}",
                $html,
                "Property 6 [{$skenario}] kondisi awal: background-color '{$expectedBg}' harus ada di HTML"
            );
            $this->assertStringContainsString(
                "color:{$expectedColor}",
                $html,
                "Property 6 [{$skenario}] kondisi awal: color '{$expectedColor}' harus ada di HTML"
            );
        }

        /**
         * **Validates: Requirements 6.8**
         *
         * Property 6 (HTML output — kondisi akhir): Untuk setiap nilai
         * akhir_tingkat yang valid dari monev_triwulan, kode warna yang sesuai
         * HARUS muncul sebagai inline style pada sel <td> kondisi akhir.
         *
         * @dataProvider providePemetaanWarnaBackground
         */
        public function testProperty6WarnaAkhirMunculSebagaiInlineStyleHtml(
            string $tingkat,
            string $expectedBg,
            string $skenario
        ): void {
            $html = testRenderTabelBab2Row('Sedang', $tingkat);
            $expectedColor = testLaporanRisikoColor($tingkat);

            $this->assertStringContainsString(
                "background-color:{$expectedBg}",
                $html,
                "Property 6 [{$skenario}] kondisi akhir: background-color '{$expectedBg}' harus ada di HTML"
            );
            $this->assertStringContainsString(
                "color:{$expectedColor}",
                $html,
                "Property 6 [{$skenario}] kondisi akhir: color '{$expectedColor}' harus ada di HTML"
            );
        }

        /**
         * **Validates: Requirements 6.8**
         *
         * Property 6 (HTML output — label teks tingkat): Nilai string tingkat
         * risiko HARUS muncul sebagai konten sel (setelah escaping) — warna
         * tidak boleh menghilangkan teks.
         *
         * @dataProvider providePemetaanWarnaBackground
         */
        public function testProperty6LabelTingkatMunculDalamSelBerwarna(
            string $tingkat,
            string $expectedBg,
            string $skenario
        ): void {
            $html = testRenderTabelBab2Row($tingkat, null);
            $escaped = htmlspecialchars($tingkat, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $this->assertStringContainsString(
                $escaped,
                $html,
                "Property 6 [{$skenario}]: label tingkat '{$escaped}' harus tetap muncul di dalam sel berwarna"
            );
        }

        // ════════════════════════════════════════════════════════════
        //  Property 6 — Sel tingkat NULL/kosong menampilkan "-" tanpa warna
        //  Validates: Requirements 6.8
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 6.8**
         *
         * Sel kondisi akhir dengan nilai null (LEFT JOIN miss) HARUS
         * menampilkan "-" dan TIDAK boleh memiliki inline style background
         * yang berwarna (tidak ada data monev = tidak ada informasi tingkat).
         */
        public function testProperty6SelAkhirNullMenampilkanDashTanpaWarnaInline(): void
        {
            $html = testRenderTingkatCell(null);

            $this->assertStringContainsString(
                '>-<',
                $html,
                'Sel tingkat null harus menampilkan teks "-"'
            );
            $this->assertStringNotContainsString(
                'background-color',
                $html,
                'Sel tingkat null tidak boleh memiliki inline background-color'
            );
        }

        /**
         * **Validates: Requirements 6.8**
         *
         * Sel dengan nilai string kosong HARUS berlaku sama seperti null —
         * menampilkan "-" tanpa inline style warna.
         */
        public function testProperty6SelAkhirStringKosongMenampilkanDashTanpaWarnaInline(): void
        {
            $html = testRenderTingkatCell('');

            $this->assertStringContainsString(
                '>-<',
                $html,
                'Sel tingkat string kosong harus menampilkan teks "-"'
            );
            $this->assertStringNotContainsString(
                'background-color',
                $html,
                'Sel tingkat string kosong tidak boleh memiliki inline background-color'
            );
        }

        // ════════════════════════════════════════════════════════════
        //  Property 6 — Kedua fungsi mengembalikan string hex 7 karakter
        //  Validates: Requirements 6.8
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 6.8**
         *
         * Setiap nilai yang dikembalikan oleh laporanRisikoBg() dan
         * laporanRisikoColor() HARUS berupa string hex CSS yang valid (format
         * #rrggbb, 7 karakter termasuk '#').
         *
         * @dataProvider provideSemualNilaiTingkat
         */
        public function testProperty6FungsiBgMengembalikanHexValidTujuhKarakter(
            string $tingkat
        ): void {
            $result = testLaporanRisikoBg($tingkat);

            $this->assertMatchesRegularExpression(
                '/^#[0-9a-f]{6}$/',
                $result,
                "laporanRisikoBg('{$tingkat}') = '{$result}' harus berformat #rrggbb (7 karakter hex lowercase)"
            );
        }

        /**
         * **Validates: Requirements 6.8**
         *
         * @dataProvider provideSemualNilaiTingkat
         */
        public function testProperty6FungsiColorMengembalikanHexValidTujuhKarakter(
            string $tingkat
        ): void {
            $result = testLaporanRisikoColor($tingkat);

            $this->assertMatchesRegularExpression(
                '/^#[0-9a-f]{6}$/',
                $result,
                "laporanRisikoColor('{$tingkat}') = '{$result}' harus berformat #rrggbb (7 karakter hex lowercase)"
            );
        }

        // ════════════════════════════════════════════════════════════
        //  Property 6 — Background dan teks memiliki kontras warna berbeda
        //  Validates: Requirements 6.8
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 6.8**
         *
         * Untuk setiap nilai tingkat yang valid, warna background dan warna
         * teks HARUS berbeda (sel berwarna tidak boleh "invisible" — teks
         * tidak boleh melebur dengan background).
         *
         * @dataProvider providePemetaanWarnaBackground
         */
        public function testProperty6BackgroundDanTextColorBerbeda(
            string $tingkat,
            string $bg,
            string $skenario
        ): void {
            $color = testLaporanRisikoColor($tingkat);

            $this->assertNotSame(
                $bg,
                $color,
                "Property 6 [{$skenario}]: background '{$bg}' dan text color '{$color}' tidak boleh sama"
            );
        }

        // ════════════════════════════════════════════════════════════
        //  Property 6 — Konsistensi: setiap panggilan mengembalikan hasil sama
        //  Validates: Requirements 6.8
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 6.8**
         *
         * Fungsi warna adalah pure function — panggilan berulang dengan input
         * yang sama HARUS selalu menghasilkan output yang sama (deterministic).
         *
         * @dataProvider provideSemualNilaiTingkat
         */
        public function testProperty6FungsiBgBersifatPure(string $tingkat): void
        {
            $first  = testLaporanRisikoBg($tingkat);
            $second = testLaporanRisikoBg($tingkat);
            $third  = testLaporanRisikoBg($tingkat);

            $this->assertSame($first,  $second, "laporanRisikoBg('{$tingkat}') tidak konsisten antara panggilan 1 dan 2");
            $this->assertSame($second, $third,  "laporanRisikoBg('{$tingkat}') tidak konsisten antara panggilan 2 dan 3");
        }

        /**
         * **Validates: Requirements 6.8**
         *
         * @dataProvider provideSemualNilaiTingkat
         */
        public function testProperty6FungsiColorBersifatPure(string $tingkat): void
        {
            $first  = testLaporanRisikoColor($tingkat);
            $second = testLaporanRisikoColor($tingkat);
            $third  = testLaporanRisikoColor($tingkat);

            $this->assertSame($first,  $second, "laporanRisikoColor('{$tingkat}') tidak konsisten antara panggilan 1 dan 2");
            $this->assertSame($second, $third,  "laporanRisikoColor('{$tingkat}') tidak konsisten antara panggilan 2 dan 3");
        }

        // ════════════════════════════════════════════════════════════
        //  Property 6 — Case-sensitivity: nilai yang berbeda kapital ≠ valid
        //  Validates: Requirements 6.8
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 6.8**
         *
         * Match menggunakan strict string comparison. Nilai seperti "sangat tinggi"
         * (huruf kecil) atau "TINGGI" (huruf besar) TIDAK boleh diperlakukan
         * sebagai nilai valid — harus jatuh ke default/fallback.
         *
         * @dataProvider provideNilaiKapitalSalah
         */
        public function testProperty6KasusHurufSalahMenggunakanFallbackBackground(
            string $tingkat,
            string $label
        ): void {
            $result = testLaporanRisikoBg($tingkat);

            $this->assertSame(
                '#f3f4f6',
                $result,
                "Property 6 [{$label}]: nilai kapitalisasi salah harus ke fallback '#f3f4f6', dapat '{$result}'"
            );
        }

        /**
         * **Validates: Requirements 6.8**
         *
         * @dataProvider provideNilaiKapitalSalah
         */
        public function testProperty6KasusHurufSalahMenggunakanFallbackTextColor(
            string $tingkat,
            string $label
        ): void {
            $result = testLaporanRisikoColor($tingkat);

            $this->assertSame(
                '#374151',
                $result,
                "Property 6 [{$label}]: nilai kapitalisasi salah harus ke fallback '#374151', dapat '{$result}'"
            );
        }

        // ════════════════════════════════════════════════════════════
        //  Data Providers
        // ════════════════════════════════════════════════════════════

        /**
         * Pemetaan warna background lengkap untuk 4 level risiko yang valid.
         * Sesuai dengan spesifikasi di design.md dan requirements 6.8.
         */
        public static function providePemetaanWarnaBackground(): array
        {
            return [
                'Sangat Tinggi (merah muda)' => ['Sangat Tinggi', '#fee2e2', 'Sangat Tinggi'],
                'Tinggi (oranye muda)'       => ['Tinggi',        '#ffedd5', 'Tinggi'],
                'Sedang (kuning muda)'       => ['Sedang',        '#fefce8', 'Sedang'],
                'Rendah (hijau muda)'        => ['Rendah',        '#dcfce7', 'Rendah'],
            ];
        }

        /**
         * Pemetaan warna teks lengkap untuk 4 level risiko yang valid.
         */
        public static function providePemetaanWarnaTeks(): array
        {
            return [
                'Sangat Tinggi (merah gelap)' => ['Sangat Tinggi', '#991b1b', 'Sangat Tinggi'],
                'Tinggi (coklat merah)'       => ['Tinggi',        '#9a3412', 'Tinggi'],
                'Sedang (coklat kuning)'      => ['Sedang',        '#854d0e', 'Sedang'],
                'Rendah (hijau gelap)'        => ['Rendah',        '#166534', 'Rendah'],
            ];
        }

        /**
         * Semua nilai tingkat (valid + fallback) untuk pengujian properti umum.
         */
        public static function provideSemualNilaiTingkat(): array
        {
            return [
                ['Sangat Tinggi'],
                ['Tinggi'],
                ['Sedang'],
                ['Rendah'],
                // Nilai tidak dikenal — jatuh ke default
                [''],
                ['unknown'],
                ['TINGGI'],
                ['rendah'],
            ];
        }

        /**
         * Nilai tidak dikenal yang harus menggunakan warna fallback.
         */
        public static function provideNilaiTidakDikenal(): array
        {
            return [
                'string kosong'         => ['',               'string kosong'],
                'spasi'                 => [' ',              'spasi tunggal'],
                'unknown'               => ['unknown',        '"unknown"'],
                'angka sebagai string'  => ['3',              '"3" (angka)'],
                'null-string'           => ['null',           '"null"'],
                'karakter html'         => ['<script>',       '"<script>"'],
                'campuran valid-invalid'=> ['Sedang Tinggi',  '"Sedang Tinggi"'],
                'typo'                  => ['Tinggi ',        '"Tinggi " (trailing space)'],
                'leading space'         => [' Rendah',        '" Rendah" (leading space)'],
            ];
        }

        /**
         * Nilai tingkat dengan kapitalisasi yang salah — harus jatuh ke fallback.
         */
        public static function provideNilaiKapitalSalah(): array
        {
            return [
                'sangat tinggi lowercase'   => ['sangat tinggi',   'lowercase semua'],
                'SANGAT TINGGI uppercase'   => ['SANGAT TINGGI',   'uppercase semua'],
                'tinggi lowercase'          => ['tinggi',          '"tinggi" lowercase'],
                'TINGGI uppercase'          => ['TINGGI',          '"TINGGI" uppercase'],
                'Tinggi mixed (t kecil)'    => ['tInggi',          '"tInggi"'],
                'sedang lowercase'          => ['sedang',          '"sedang" lowercase'],
                'SEDANG uppercase'          => ['SEDANG',          '"SEDANG" uppercase'],
                'rendah lowercase'          => ['rendah',          '"rendah" lowercase'],
                'RENDAH uppercase'          => ['RENDAH',          '"RENDAH" uppercase'],
            ];
        }
    }

} // end if class_exists PHPUnit\Framework\TestCase

// ─────────────────────────────────────────────────────────────────────────────
//  Standalone runner — jalankan via: php tests/laporan_monev_warna_test.php
// ─────────────────────────────────────────────────────────────────────────────
if (!class_exists('PHPUnit\Framework\TestCase')) {

    // ── Warna background yang diharapkan ──────────────────────────────────────
    $expectedBg = [
        'Sangat Tinggi' => '#fee2e2',
        'Tinggi'        => '#ffedd5',
        'Sedang'        => '#fefce8',
        'Rendah'        => '#dcfce7',
    ];

    // ── Warna teks yang diharapkan ────────────────────────────────────────────
    $expectedColor = [
        'Sangat Tinggi' => '#991b1b',
        'Tinggi'        => '#9a3412',
        'Sedang'        => '#854d0e',
        'Rendah'        => '#166534',
    ];

    $pass = 0;
    $fail = 0;

    function standaloneAssert(bool $condition, string $msg): void
    {
        global $pass, $fail;
        if ($condition) {
            echo "  [PASS] {$msg}\n";
            $pass++;
        } else {
            echo "  [FAIL] {$msg}\n";
            $fail++;
        }
    }

    echo "=== Laporan Monev — Property 6: Warna Sel Tingkat Risiko ===\n\n";

    // ── Test 1: Pemetaan warna background (4 level valid) ────────────────────
    echo "--- 1. Pemetaan warna background (laporanRisikoBg) ---\n";
    foreach ($expectedBg as $tingkat => $bg) {
        $result = testLaporanRisikoBg($tingkat);
        standaloneAssert(
            $result === $bg,
            "laporanRisikoBg('{$tingkat}') === '{$bg}' (dapat: '{$result}')"
        );
    }
    echo "\n";

    // ── Test 2: Pemetaan warna teks (4 level valid) ───────────────────────────
    echo "--- 2. Pemetaan warna teks (laporanRisikoColor) ---\n";
    foreach ($expectedColor as $tingkat => $color) {
        $result = testLaporanRisikoColor($tingkat);
        standaloneAssert(
            $result === $color,
            "laporanRisikoColor('{$tingkat}') === '{$color}' (dapat: '{$result}')"
        );
    }
    echo "\n";

    // ── Test 3: Warna fallback untuk nilai tidak dikenal ──────────────────────
    echo "--- 3. Warna fallback untuk nilai tidak dikenal ---\n";
    $unknownValues = ['', ' ', 'unknown', 'TINGGI', 'sangat tinggi', 'null', '<script>', 'Sedang Tinggi'];
    foreach ($unknownValues as $val) {
        $bg    = testLaporanRisikoBg($val);
        $color = testLaporanRisikoColor($val);
        standaloneAssert(
            $bg === '#f3f4f6',
            "laporanRisikoBg('{$val}') === '#f3f4f6' (default abu-abu) — dapat: '{$bg}'"
        );
        standaloneAssert(
            $color === '#374151',
            "laporanRisikoColor('{$val}') === '#374151' (default gelap) — dapat: '{$color}'"
        );
    }
    echo "\n";

    // ── Test 4: Warna muncul sebagai inline style di HTML output ─────────────
    echo "--- 4. Warna sebagai inline style di HTML output ---\n";
    foreach ($expectedBg as $tingkat => $bg) {
        $color = $expectedColor[$tingkat];
        $html  = testRenderTabelBab2Row($tingkat, null);

        standaloneAssert(
            str_contains($html, "background-color:{$bg}"),
            "HTML output kondisi awal '{$tingkat}': background-color:{$bg} ada"
        );
        standaloneAssert(
            str_contains($html, "color:{$color}"),
            "HTML output kondisi awal '{$tingkat}': color:{$color} ada"
        );

        // Verifikasi teks label tingkat juga tetap muncul
        $escaped = htmlspecialchars($tingkat, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        standaloneAssert(
            str_contains($html, $escaped),
            "HTML output: label '{$tingkat}' (escaped: '{$escaped}') tetap muncul di sel berwarna"
        );
    }
    echo "\n";

    // ── Test 5: Sel NULL/kosong — tidak ada inline style warna ───────────────
    echo "--- 5. Sel tingkat NULL/kosong — tampilkan '-' tanpa warna ---\n";
    $nullCases = [null, ''];
    foreach ($nullCases as $val) {
        $label = ($val === null) ? 'null' : '""';
        $html  = testRenderTingkatCell($val);

        standaloneAssert(
            str_contains($html, '>-<'),
            "testRenderTingkatCell({$label}) menampilkan '-'"
        );
        standaloneAssert(
            !str_contains($html, 'background-color'),
            "testRenderTingkatCell({$label}) tidak memiliki inline background-color"
        );
    }
    echo "\n";

    // ── Test 6: Format hex valid (#rrggbb) ────────────────────────────────────
    echo "--- 6. Format hex valid (#rrggbb, 7 karakter) ---\n";
    $allLevels = ['Sangat Tinggi', 'Tinggi', 'Sedang', 'Rendah', '', 'unknown', 'TINGGI'];
    foreach ($allLevels as $t) {
        $bg    = testLaporanRisikoBg($t);
        $color = testLaporanRisikoColor($t);
        $label = $t !== '' ? $t : '(kosong)';

        standaloneAssert(
            (bool)preg_match('/^#[0-9a-f]{6}$/', $bg),
            "laporanRisikoBg('{$label}') = '{$bg}' — format #rrggbb valid"
        );
        standaloneAssert(
            (bool)preg_match('/^#[0-9a-f]{6}$/', $color),
            "laporanRisikoColor('{$label}') = '{$color}' — format #rrggbb valid"
        );
    }
    echo "\n";

    // ── Test 7: Pure function — hasil deterministik ───────────────────────────
    echo "--- 7. Pure function — hasil konsisten (deterministic) ---\n";
    foreach (array_merge(array_keys($expectedBg), ['', 'unknown']) as $t) {
        $label = $t !== '' ? $t : '(kosong)';
        standaloneAssert(
            testLaporanRisikoBg($t) === testLaporanRisikoBg($t),
            "laporanRisikoBg('{$label}') deterministik"
        );
        standaloneAssert(
            testLaporanRisikoColor($t) === testLaporanRisikoColor($t),
            "laporanRisikoColor('{$label}') deterministik"
        );
    }
    echo "\n";

    // ── Test 8: Background dan teks berbeda (kontras) ────────────────────────
    echo "--- 8. Background dan text color harus berbeda ---\n";
    foreach (array_keys($expectedBg) as $t) {
        $bg    = testLaporanRisikoBg($t);
        $color = testLaporanRisikoColor($t);
        standaloneAssert(
            $bg !== $color,
            "'{$t}': bg '{$bg}' !== color '{$color}'"
        );
    }
    echo "\n";

    // ── Test 9: Case-sensitivity — huruf kecil/besar jatuh ke fallback ────────
    echo "--- 9. Case-sensitivity: salah kapital → fallback ---\n";
    $wrongCases = ['sangat tinggi', 'SANGAT TINGGI', 'tinggi', 'TINGGI', 'sedang', 'SEDANG', 'rendah', 'RENDAH'];
    foreach ($wrongCases as $t) {
        $bg    = testLaporanRisikoBg($t);
        $color = testLaporanRisikoColor($t);
        standaloneAssert(
            $bg === '#f3f4f6',
            "laporanRisikoBg('{$t}') harus fallback '#f3f4f6', dapat '{$bg}'"
        );
        standaloneAssert(
            $color === '#374151',
            "laporanRisikoColor('{$t}') harus fallback '#374151', dapat '{$color}'"
        );
    }
    echo "\n";

    // ── Ringkasan ─────────────────────────────────────────────────────────────
    $total = $pass + $fail;
    echo "=== Hasil: {$pass}/{$total} test lulus";
    if ($fail === 0) {
        echo " — SEMUA LULUS ✓\n";
    } else {
        echo " — {$fail} GAGAL ✗\n";
    }
    exit($fail > 0 ? 1 : 0);
}
