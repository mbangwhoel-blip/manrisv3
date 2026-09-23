<?php
/**
 * Property-Based Tests: Cover dan Lembar Pengesahan Laporan Monev
 *
 * Property 2: Cover dan lembar pengesahan mencerminkan input form
 *   Validates: Requirements 4.1, 4.2, 4.3
 *
 * Untuk setiap kombinasi valid (triwulan ∈ {1,2,3,4}, tahun berformat 4 digit,
 * koordinator, penulis, namaKepala, nipKepala, tanggal), dokumen HTML yang
 * dihasilkan SHALL mengandung semua nilai tersebut (setelah di-escape dengan
 * htmlspecialchars) pada elemen cover dan lembar pengesahan yang sesuai.
 *
 * Jalankan via PHPUnit : php vendor/bin/phpunit tests/LaporanMonevCoverTest.php
 * Jalankan standalone  : php tests/LaporanMonevCoverTest.php
 */

// ─────────────────────────────────────────────────────────────────────────────
//  Fungsi murni yang mencerminkan logika modul modules/laporan_monev.php
//
//  Tidak bergantung pada DB, session, atau auth.
//  Fungsi ini mereproduksi hanya bagian Cover & Lembar Pengesahan dari
//  mode Generate.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Memetakan nilai triwulan integer ke label romawi yang ditampilkan pada cover.
 * Logika identik dengan modul:
 *   $triwulanLabel = ['I', 'II', 'III', 'IV'][$triwulan - 1];
 */
function laporanCoverTriwulanLabel(int $triwulan): string
{
    $map = ['I', 'II', 'III', 'IV'];
    return $map[$triwulan - 1] ?? 'I';
}

/**
 * Menghasilkan potongan HTML cover yang setara dengan bagian Cover pada
 * mode Generate di modul. Semua nilai user-input di-escape dengan
 * htmlspecialchars() persis seperti yang dilakukan modul.
 *
 * @param int    $triwulan  Nilai integer 1–4
 * @param string $tahun     Tahun 4-digit
 * @param string $logoPath  Path relatif ke logo
 */
function laporanRenderCoverHtml(
    int    $triwulan,
    string $tahun,
    string $logoPath = 'assets/img/logo.png'
): string {
    $label    = laporanCoverTriwulanLabel($triwulan);
    $tahunEsc = htmlspecialchars($tahun, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $logoEsc  = htmlspecialchars($logoPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return implode("\n", [
        '<div class="cover page-break">',
        '  <div class="logo-wrap">',
        '    <img src="' . $logoEsc . '" alt="Logo">',
        '  </div>',
        '  <div class="doc-label">LAPORAN MONITORING DAN EVALUASI MANAJEMEN RISIKO</div>',
        '  <div class="instansi-name">BALAI BESAR LABORATORIUM KESEHATAN LINGKUNGAN</div>',
        '  <hr class="cover-divider">',
        '  <div class="periode-label">TRIWULAN ' . $label . ' TAHUN ' . $tahunEsc . '</div>',
        '</div>',
    ]);
}

/**
 * Menghasilkan potongan HTML lembar pengesahan yang setara dengan bagian
 * Lembar Pengesahan pada mode Generate di modul.
 * Semua nilai user-input di-escape dengan htmlspecialchars().
 */
function laporanRenderPengesahanHtml(
    string $koordinator,
    string $penulis,
    string $namaKepala,
    string $nipKepala,
    string $tanggal,
    int    $triwulan = 1,
    string $tahun = '2026'
): string {
    $e = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $triwulanLabel = ['I', 'II', 'III', 'IV'][$triwulan - 1] ?? 'I';

    $displayTanggal = '';
    if ($tanggal !== '') {
        $displayTanggal = (stripos($tanggal, 'Salatiga') === 0) ? $tanggal : ('Salatiga, ' . $tanggal);
    } else {
        $displayTanggal = 'Salatiga, &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
    }

    return implode("\n", [
        '<div class="pengesahan doc-page page-break">',
        '  <h2>LEMBAR PENGESAHAN</h2>',
        '',
        '  <div class="pengesahan-subjudul">',
        '    <p>LAPORAN MONITORING DAN EVALUASI</p>',
        '    <p>MANAJEMEN RISIKO</p>',
        '    <p>BALAI BESAR LABORATORIUM KESEHATAN LINGKUNGAN</p>',
        '    <p>TRIWULAN ' . $triwulanLabel . ' TAHUN ' . $e($tahun) . '</p>',
        '  </div>',
        '',
        '  <table class="tabel-petugas-pengesahan">',
        '    <tr>',
        '      <td class="col-label">Koordinator dan Verifikator</td>',
        '      <td class="col-sep">:</td>',
        '      <td class="col-val">' . ($koordinator !== '' ? $e($koordinator) : '_________________________') . '</td>',
        '    </tr>',
        '    <tr>',
        '      <td class="col-label">Penulis</td>',
        '      <td class="col-sep">:</td>',
        '      <td class="col-val">' . ($penulis !== '' ? $e($penulis) : '_________________________') . '</td>',
        '    </tr>',
        '  </table>',
        '',
        '  <div class="pengesahan-ttd-center">',
        '    <p>' . $e($displayTanggal) . '</p>',
        '    <p>Disahkan oleh</p>',
        '    <p>Kepala Balai Besar Laboratorium Kesehatan Lingkungan</p>',
        '    <div class="ttd-space"></div>',
        '    <p class="ttd-nama">' . ($namaKepala !== '' ? $e($namaKepala) : '_________________________') . '</p>',
        '    <p class="ttd-nip">' . ($nipKepala !== '' ? 'NIP. ' . $e($nipKepala) : 'NIP. _________________________') . '</p>',
        '  </div>',
        '</div>',
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
//  PHPUnit Test Class — only loaded when PHPUnit is available
// ─────────────────────────────────────────────────────────────────────────────
if (class_exists('PHPUnit\Framework\TestCase')) {

    class LaporanMonevCoverTest extends \PHPUnit\Framework\TestCase
    {
        // ══════════════════════════════════════════════════════════════
        //  Property 2 — Triwulan mapping: integer → romawi
        //  Validates: Requirements 4.1
        // ══════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 4.1**
         *
         * Property 2 (triwulan label): Setiap nilai triwulan dalam {1,2,3,4}
         * HARUS dipetakan ke label romawi yang benar pada cover.
         *
         * @dataProvider provideTriwulanRomawi
         */
        public function testProperty2TriwulanLabelRomawi(int $triwulan, string $expectedLabel): void
        {
            $html = laporanRenderCoverHtml($triwulan, '2025');

            $this->assertStringContainsString(
                'TRIWULAN ' . $expectedLabel . ' TAHUN',
                $html,
                "Property 2 gagal: triwulan {$triwulan} harus menghasilkan label '{$expectedLabel}' pada cover"
            );
        }

        // ══════════════════════════════════════════════════════════════
        //  Property 2 — Tahun muncul di cover
        //  Validates: Requirements 4.1
        // ══════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 4.1**
         *
         * Property 2 (tahun di cover): Nilai tahun HARUS muncul (setelah
         * escaping) dalam output HTML cover, sesuai periode yang dipilih.
         *
         * @dataProvider provideTahunValid
         */
        public function testProperty2TahunMunculDiCover(string $tahun): void
        {
            $html     = laporanRenderCoverHtml(1, $tahun);
            $tahunEsc = htmlspecialchars($tahun, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $this->assertStringContainsString(
                $tahunEsc,
                $html,
                "Property 2: tahun '{$tahun}' (escaped: '{$tahunEsc}') harus muncul di cover"
            );
        }

        // ══════════════════════════════════════════════════════════════
        //  Property 2 — Judul dan nama instansi selalu hadir di cover
        //  Validates: Requirements 4.1
        // ══════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 4.1**
         *
         * Cover HARUS selalu mengandung judul dokumen dan nama instansi
         * yang benar, terlepas dari parameter yang dipilih pengguna.
         *
         * @dataProvider provideKombinasiTriwulanTahun
         */
        public function testProperty2CoverSelaluMengandungJudulDanInstansi(
            int    $triwulan,
            string $tahun
        ): void {
            $html = laporanRenderCoverHtml($triwulan, $tahun);

            $this->assertStringContainsString(
                'LAPORAN MONITORING DAN EVALUASI MANAJEMEN RISIKO',
                $html,
                'Cover harus mengandung judul dokumen'
            );
            $this->assertStringContainsString(
                'BALAI BESAR LABORATORIUM KESEHATAN LINGKUNGAN',
                $html,
                'Cover harus mengandung nama instansi'
            );
        }

        // ══════════════════════════════════════════════════════════════
        //  Property 2 — Input form muncul di lembar pengesahan
        //  Validates: Requirements 4.2, 4.3
        // ══════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 4.2, 4.3**
         *
         * Property 2 (pengesahan): Untuk setiap kombinasi nilai koordinator,
         * penulis, namaKepala, nipKepala, tanggal — versi escaped dari
         * nilai-nilai tersebut HARUS muncul di HTML lembar pengesahan.
         *
         * @dataProvider provideInputPengesahan
         */
        public function testProperty2InputFormMunculDiLembarPengesahan(
            string $koordinator,
            string $penulis,
            string $namaKepala,
            string $nipKepala,
            string $tanggal,
            string $skenario
        ): void {
            $html = laporanRenderPengesahanHtml($koordinator, $penulis, $namaKepala, $nipKepala, $tanggal);

            $fields = [
                'koordinator' => $koordinator,
                'penulis'     => $penulis,
                'namaKepala'  => $namaKepala,
                'nipKepala'   => $nipKepala,
                'tanggal'     => $tanggal,
            ];

            // Lembar pengesahan selalu harus ter-render (bahkan saat semua field kosong)
            $this->assertStringContainsString('LEMBAR PENGESAHAN', $html, "Heading LEMBAR PENGESAHAN harus selalu ada [{$skenario}]");

            foreach ($fields as $fieldName => $value) {
                if ($value === '') {
                    continue; // string kosong valid, tidak perlu assert kemunculan spesifik
                }
                $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $this->assertStringContainsString(
                    $escaped,
                    $html,
                    "Property 2 [{$skenario}]: field '{$fieldName}' → escaped '{$escaped}' harus muncul di lembar pengesahan"
                );
            }
        }

        // ══════════════════════════════════════════════════════════════
        //  Property 2 — Escaping HTML special characters
        //  Validates: Requirements 4.2, 4.3
        // ══════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 4.2, 4.3**
         *
         * Property 2 (XSS prevention): Nilai yang mengandung karakter HTML
         * khusus (<, >, ", ', &) TIDAK BOLEH muncul sebagai karakter mentah.
         * Karakter-karakter tersebut HARUS direpresentasikan sebagai HTML entity.
         *
         * @dataProvider provideInputMengandungHtmlKhusus
         */
        public function testProperty2KarakterHtmlKhususDiEscape(
            string $inputMentah,
            string $entityYangDiharapkan,
            string $label
        ): void {
            $escapedInput = htmlspecialchars($inputMentah, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            // Test semua field lembar pengesahan
            foreach ([
                'koordinator' => laporanRenderPengesahanHtml($inputMentah, '', '', '', ''),
                'penulis'     => laporanRenderPengesahanHtml('', $inputMentah, '', '', ''),
                'namaKepala'  => laporanRenderPengesahanHtml('', '', $inputMentah, '', ''),
                'nipKepala'   => laporanRenderPengesahanHtml('', '', '', $inputMentah, ''),
                'tanggal'     => laporanRenderPengesahanHtml('', '', '', '', $inputMentah),
            ] as $fieldName => $html) {
                $this->assertStringContainsString(
                    $entityYangDiharapkan,
                    $html,
                    "Property 2 [{$label}] field '{$fieldName}': entity '{$entityYangDiharapkan}' harus ada dalam output"
                );
                $this->assertStringContainsString(
                    $escapedInput,
                    $html,
                    "Property 2 [{$label}] field '{$fieldName}': escaped value '{$escapedInput}' harus ada dalam output"
                );
            }

            // Test juga cover (nilai tahun mengandung karakter khusus)
            $htmlCover = laporanRenderCoverHtml(1, $inputMentah);
            $this->assertStringContainsString(
                $escapedInput,
                $htmlCover,
                "Property 2 [{$label}] cover (tahun): escaped value '{$escapedInput}' harus ada dalam output"
            );
        }

        /**
         * **Validates: Requirements 4.2**
         *
         * Kolom-kolom standar Lembar Pengesahan HARUS selalu hadir.
         */
        public function testProperty2LembarPengesahanMengandungKolomStandar(): void
        {
            $html = laporanRenderPengesahanHtml('Alice', 'Bob', 'Dr. Charlie', '198001012010011001', '1 Januari 2025');

            $this->assertStringContainsString('Koordinator dan Verifikator', $html);
            $this->assertStringContainsString('Penulis', $html);
            $this->assertStringContainsString('Disahkan oleh', $html);
            $this->assertStringContainsString('Kepala Balai Besar Laboratorium Kesehatan Lingkungan', $html);
            $this->assertStringContainsString('LEMBAR PENGESAHAN', $html);
        }

        /**
         * **Validates: Requirements 4.3**
         *
         * Tanggal dari input form HARUS muncul di lembar pengesahan.
         *
         * @dataProvider provideTanggalPengesahan
         */
        public function testProperty2TanggalPengesahanMunculDiLembar(string $tanggal): void
        {
            $html    = laporanRenderPengesahanHtml('', '', '', '', $tanggal);
            $escaped = htmlspecialchars($tanggal, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            if ($tanggal !== '') {
                $this->assertStringContainsString(
                    $escaped,
                    $html,
                    "Tanggal '{$tanggal}' (escaped: '{$escaped}') harus muncul di lembar pengesahan"
                );
            } else {
                $this->assertStringContainsString('LEMBAR PENGESAHAN', $html);
            }
        }

        /**
         * **Validates: Requirements 4.2**
         *
         * NIP Kepala HARUS muncul dengan prefiks "NIP." di kolom "Diketahui Oleh".
         *
         * @dataProvider provideNipKepala
         */
        public function testProperty2NipKepalaAdaDiLembar(string $nip): void
        {
            $html    = laporanRenderPengesahanHtml('', '', 'Dr. Test', $nip, '');
            $escaped = htmlspecialchars($nip, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            if ($nip !== '') {
                $this->assertStringContainsString(
                    'NIP. ' . $escaped,
                    $html,
                    "NIP '{$nip}' harus muncul dengan prefiks 'NIP.' di lembar pengesahan"
                );
            } else {
                // NIP kosong valid — lembar pengesahan tetap harus ter-render
                $this->assertStringContainsString('LEMBAR PENGESAHAN', $html, 'Heading LEMBAR PENGESAHAN harus selalu ada');
            }
        }

        // ══════════════════════════════════════════════════════════════
        //  Property 2 — page-break class
        //  Validates: Requirements 4.4
        // ══════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 4.4**
         *
         * Cover HARUS memiliki class "page-break" untuk pemisahan halaman cetak.
         */
        public function testProperty2CoverMemilikiClassPageBreak(): void
        {
            $html = laporanRenderCoverHtml(1, '2025');
            $this->assertStringContainsString(
                'class="cover page-break"',
                $html,
                'Cover harus memiliki class "page-break"'
            );
        }

        /**
         * **Validates: Requirements 4.4**
         *
         * Lembar Pengesahan HARUS memiliki class "page-break".
         */
        public function testProperty2PengesahanMemilikiClassPageBreak(): void
        {
            $html = laporanRenderPengesahanHtml('', '', '', '', '');
            $this->assertStringContainsString(
                'page-break',
                $html,
                'Lembar pengesahan harus memiliki class "page-break"'
            );
            $this->assertStringContainsString(
                'pengesahan',
                $html,
                'Lembar pengesahan harus memiliki class "pengesahan"'
            );
        }

        // ══════════════════════════════════════════════════════════════
        //  Property 2 — Tabel silang triwulan × tahun
        //  Validates: Requirements 4.1
        // ══════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 4.1**
         *
         * Property 2 (tabel silang): Untuk setiap kombinasi (triwulan, tahun),
         * cover HARUS mengandung label romawi yang tepat DAN tahun yang tepat
         * secara bersamaan.
         *
         * @dataProvider provideKombinasiTriwulanTahun
         */
        public function testProperty2TabelSilangTriwulanTahun(int $triwulan, string $tahun): void
        {
            $romanMap = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV'];
            $html     = laporanRenderCoverHtml($triwulan, $tahun);
            $expected = 'TRIWULAN ' . $romanMap[$triwulan] . ' TAHUN ' . htmlspecialchars($tahun, ENT_QUOTES, 'UTF-8');

            $this->assertStringContainsString(
                $expected,
                $html,
                "Cover harus mengandung periode '{$expected}'"
            );
        }

        // ══════════════════════════════════════════════════════════════
        //  Data Providers
        // ══════════════════════════════════════════════════════════════

        /** Pemetaan triwulan integer → label romawi. */
        public static function provideTriwulanRomawi(): array
        {
            return [
                'TW 1 → I'   => [1, 'I'],
                'TW 2 → II'  => [2, 'II'],
                'TW 3 → III' => [3, 'III'],
                'TW 4 → IV'  => [4, 'IV'],
            ];
        }

        /** Tahun 4-digit valid untuk test cover. */
        public static function provideTahunValid(): array
        {
            return [
                'tahun 2020' => ['2020'],
                'tahun 2023' => ['2023'],
                'tahun 2024' => ['2024'],
                'tahun 2025' => ['2025'],
                'tahun 2026' => ['2026'],
                'tahun 2030' => ['2030'],
            ];
        }

        /** Kombinasi triwulan × tahun untuk test tabel silang. */
        public static function provideKombinasiTriwulanTahun(): array
        {
            $rows = [];
            foreach ([1, 2, 3, 4] as $tw) {
                foreach (['2023', '2024', '2025'] as $tahun) {
                    $rows["TW{$tw}-{$tahun}"] = [$tw, $tahun];
                }
            }
            return $rows;
        }

        /** Kombinasi nilai input pengesahan — tipe normal dan edge case. */
        public static function provideInputPengesahan(): array
        {
            return [
                'input normal standar' => [
                    'dr. Budi Santoso, M.Kes',
                    'Siti Rahayu, S.KM',
                    'Prof. Dr. Ahmad Fauzi, Sp.PK(K)',
                    '196504281994031003',
                    '31 Maret 2025',
                    'input normal standar',
                ],
                'input dengan tanda baca' => [
                    'Koordinator, M.Kes.',
                    'Penulis (Staf)',
                    'Dr. Kepala [BBLKL]',
                    '197001011990031004',
                    'Jakarta, 1 April 2025',
                    'input dengan tanda baca umum',
                ],
                'semua field kosong' => [
                    '',
                    '',
                    '',
                    '',
                    '',
                    'semua field kosong',
                ],
                'input dengan spasi' => [
                    '  Koordinator Spasi  ',
                    'Penulis  Ganda',
                    'Kepala   Instansi',
                    '19800101 200701 1 001',
                    '  15 Juli 2025  ',
                    'spasi ekstra dalam nilai',
                ],
                'input panjang maksimal' => [
                    str_repeat('A', 100),
                    str_repeat('B', 100),
                    str_repeat('C', 100),
                    str_repeat('1', 20),
                    str_repeat('D', 50),
                    'input panjang maksimal',
                ],
                'input dengan angka' => [
                    'Koordinator 123',
                    'Penulis 456',
                    'Kepala 789',
                    '199912312024011001',
                    '01-01-2025',
                    'input dengan angka',
                ],
            ];
        }

        /** Input yang mengandung karakter HTML khusus. */
        public static function provideInputMengandungHtmlKhusus(): array
        {
            return [
                'tanda lebih-besar >'    => ['A > B',                   'A &gt; B',                              '">"'],
                'tanda lebih-kecil <'    => ['A < B',                   'A &lt; B',                              '"<"'],
                'ampersand &'            => ['A & B',                   'A &amp; B',                             '"&"'],
                'tanda kutip ganda "'    => ['Nama "Budi"',             'Nama &quot;Budi&quot;',                 '"\\""'],
                "tanda kutip tunggal '"  => ["Nama 'Budi'",             "Nama &#039;Budi&#039;",                 "\"'\""],
                'XSS script tag'         => ['<script>alert(1)</script>','&lt;script&gt;alert(1)&lt;/script&gt;','script XSS'],
                'kombinasi & < >'        => ['Risiko < 5 & nilai > 3', 'Risiko &lt; 5 &amp; nilai &gt; 3',      '& < >'],
                'attribute injection'    => ['" onclick="evil()"',      '&quot; onclick=&quot;evil()&quot;',     'attr injection'],
            ];
        }

        /** Format tanggal pengesahan. */
        public static function provideTanggalPengesahan(): array
        {
            return [
                'format panjang'   => ['31 Desember 2025'],
                'dengan kota'      => ['Jakarta, 15 Juli 2025'],
                'format ISO'       => ['2025-03-31'],
                'format slash'     => ['31/03/2025'],
                'string kosong'    => [''],
                'dengan titik'     => ['31.03.2025'],
            ];
        }

        /** Format NIP kepala. */
        public static function provideNipKepala(): array
        {
            return [
                '18 digit'         => ['196504281994031003'],
                'dengan spasi'     => ['19650428 199403 1 003'],
                'kosong'           => [''],
                'pendek'           => ['123456'],
            ];
        }
    }

} // end if class_exists PHPUnit\Framework\TestCase

// ─────────────────────────────────────────────────────────────────────────────
//  Standalone runner — jalankan via: php tests/LaporanMonevCoverTest.php
//  Hanya aktif saat file ini dijalankan langsung sebagai script CLI.
// ─────────────────────────────────────────────────────────────────────────────
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {

    $passed = 0;
    $failed = 0;
    $errors = [];

    /** Helper: jalankan satu test case dan catat hasilnya. */
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

    /** Assert bahwa kondisi true; lempar RuntimeException jika tidak. */
    $assertTrue = function (bool $cond, string $msg): void {
        if (!$cond) {
            throw new RuntimeException($msg);
        }
    };

    /** Assert bahwa $needle ada di $haystack. */
    $assertContains = function (string $needle, string $haystack, string $msg) use ($assertTrue): void {
        $assertTrue(str_contains($haystack, $needle), $msg . " (mencari: '{$needle}')");
    };

    echo "\n";
    echo "╔══════════════════════════════════════════════════════════╗\n";
    echo "║  Property 2: Cover dan Lembar Pengesahan — Standalone    ║\n";
    echo "║  Validates: Requirements 4.1, 4.2, 4.3                  ║\n";
    echo "╚══════════════════════════════════════════════════════════╝\n\n";

    // ── 2.A Triwulan → Romawi ────────────────────────────────────────────────

    echo "Property 2.A: Pemetaan triwulan → label romawi (4 kasus)\n";
    foreach ([1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV'] as $tw => $expected) {
        $run("TW {$tw} → '{$expected}'", function () use ($tw, $expected, $assertContains): void {
            $html = laporanRenderCoverHtml($tw, '2025');
            $assertContains("TRIWULAN {$expected} TAHUN", $html, "Cover harus mengandung label '{$expected}'");
        });
    }

    // ── 2.B Tahun di cover ───────────────────────────────────────────────────

    echo "\nProperty 2.B: Tahun muncul di cover\n";
    foreach (['2020', '2023', '2024', '2025', '2026'] as $tahun) {
        $run("Tahun {$tahun} di cover", function () use ($tahun, $assertContains): void {
            $html = laporanRenderCoverHtml(1, $tahun);
            $assertContains($tahun, $html, "Tahun '{$tahun}' harus muncul di cover");
        });
    }

    // ── 2.C Judul dan instansi selalu ada ────────────────────────────────────

    echo "\nProperty 2.C: Judul dan nama instansi selalu hadir\n";
    foreach ([1, 2, 3, 4] as $tw) {
        $run("TW{$tw}: judul & instansi ada", function () use ($tw, $assertContains): void {
            $html = laporanRenderCoverHtml($tw, '2024');
            $assertContains('LAPORAN MONITORING DAN EVALUASI MANAJEMEN RISIKO', $html, 'Judul harus ada');
            $assertContains('BALAI BESAR LABORATORIUM KESEHATAN LINGKUNGAN', $html, 'Instansi harus ada');
        });
    }

    // ── 2.D Input form muncul di lembar pengesahan ───────────────────────────

    echo "\nProperty 2.D: Input form muncul di lembar pengesahan\n";
    $skenarioPengesahan = [
        ['dr. Budi Santoso, M.Kes', 'Siti Rahayu, S.KM', 'Prof. Ahmad', '196504281994031003', '31 Maret 2025', 'normal'],
        ['Koordinator (Spesial)', 'Penulis [Staf]', 'Kepala {Instansi}', '19800101200701001', 'Jakarta, 1 April 2025', 'tanda baca'],
        [str_repeat('A', 100), str_repeat('B', 100), str_repeat('C', 100), str_repeat('1', 20), str_repeat('D', 50), 'panjang maks'],
    ];
    foreach ($skenarioPengesahan as [$k, $p, $nk, $nip, $tgl, $label]) {
        $run("Input [{$label}] muncul di pengesahan", function () use ($k, $p, $nk, $nip, $tgl, $label, $assertContains): void {
            $html   = laporanRenderPengesahanHtml($k, $p, $nk, $nip, $tgl);
            $fields = ['koordinator' => $k, 'penulis' => $p, 'namaKepala' => $nk, 'nipKepala' => $nip, 'tanggal' => $tgl];
            foreach ($fields as $fn => $val) {
                if ($val === '') continue;
                $esc = htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $assertContains($esc, $html, "[{$label}] Field '{$fn}' escaped harus ada");
            }
        });
    }

    // ── 2.E Escaping karakter HTML khusus ────────────────────────────────────

    echo "\nProperty 2.E: Escaping karakter HTML khusus (XSS prevention)\n";
    $xssCases = [
        ['<script>alert(1)</script>', '&lt;script&gt;',                 'XSS script tag'],
        ['A > B',                     'A &gt; B',                       'tanda >'],
        ['A < B',                     'A &lt; B',                       'tanda <'],
        ['A & B',                     'A &amp; B',                      'ampersand &'],
        ['Nama "Budi"',               'Nama &quot;Budi&quot;',          'kutip ganda "'],
        ["Nama 'Budi'",               "Nama &#039;Budi&#039;",          "kutip tunggal '"],
        ['" onclick="evil()"',        '&quot; onclick=&quot;evil()&quot;', 'attribute injection'],
    ];
    foreach ($xssCases as [$raw, $entity, $desc]) {
        $run("Escape [{$desc}]", function () use ($raw, $entity, $assertContains): void {
            $html       = laporanRenderPengesahanHtml($raw, $raw, $raw, $raw, $raw);
            $escapedVal = htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $assertContains($entity, $html, "Entity '{$entity}' harus ada di output");
            $assertContains($escapedVal, $html, "Escaped value harus ada di output");
            // Juga test di cover (sebagai tahun)
            $htmlCover = laporanRenderCoverHtml(1, $raw);
            $assertContains($escapedVal, $htmlCover, "Escaped value harus ada di cover");
        });
    }

    // ── 2.F NIP dengan prefiks ───────────────────────────────────────────────

    echo "\nProperty 2.F: NIP dengan prefiks 'NIP.' di pengesahan\n";
    foreach (['196504281994031003', '19800101 200701 1 001', '123456'] as $nip) {
        $run("NIP '{$nip}'", function () use ($nip, $assertContains): void {
            $html    = laporanRenderPengesahanHtml('', '', 'Kepala', $nip, '');
            $escaped = htmlspecialchars($nip, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $assertContains("NIP. {$escaped}", $html, "NIP harus muncul dengan prefiks 'NIP.'");
        });
    }

    // ── 2.G Kolom standar lembar pengesahan ──────────────────────────────────

    echo "\nProperty 2.G: Kolom standar lembar pengesahan\n";
    $run("Label 'Koordinator dan Verifikator' ada", function () use ($assertContains): void {
        $html = laporanRenderPengesahanHtml('', '', '', '', '');
        $assertContains('Koordinator dan Verifikator', $html, 'Label koordinator harus ada');
    });
    $run("Label 'Penulis' ada", function () use ($assertContains): void {
        $html = laporanRenderPengesahanHtml('', '', '', '', '');
        $assertContains('Penulis', $html, 'Label penulis harus ada');
    });
    $run("Label 'Disahkan oleh' ada", function () use ($assertContains): void {
        $html = laporanRenderPengesahanHtml('', '', '', '', '');
        $assertContains('Disahkan oleh', $html, 'Label disahkan oleh harus ada');
    });
    $run("Heading 'LEMBAR PENGESAHAN' ada", function () use ($assertContains): void {
        $html = laporanRenderPengesahanHtml('', '', '', '', '');
        $assertContains('LEMBAR PENGESAHAN', $html, 'Heading lembar pengesahan harus ada');
    });

    // ── 2.H class page-break ─────────────────────────────────────────────────

    echo "\nProperty 2.H: class 'page-break' pada cover dan pengesahan\n";
    $run("Cover memiliki class 'page-break'", function () use ($assertContains): void {
        $html = laporanRenderCoverHtml(1, '2025');
        $assertContains('class="cover page-break"', $html, 'Cover harus punya class page-break');
    });
    $run("Pengesahan memiliki class 'page-break'", function () use ($assertContains): void {
        $html = laporanRenderPengesahanHtml('', '', '', '', '');
        $assertContains('page-break', $html, 'Pengesahan harus punya class page-break');
        $assertContains('pengesahan', $html, 'Pengesahan harus punya class pengesahan');
    });

    // ── 2.I Tabel silang triwulan × tahun ────────────────────────────────────

    echo "\nProperty 2.I: Tabel silang semua triwulan × beberapa tahun (12 kombinasi)\n";
    $romanMap = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV'];
    foreach ([1, 2, 3, 4] as $tw) {
        foreach (['2023', '2024', '2025'] as $tahun) {
            $run("TW{$tw} × {$tahun}", function () use ($tw, $tahun, $romanMap, $assertContains): void {
                $html     = laporanRenderCoverHtml($tw, $tahun);
                $expected = 'TRIWULAN ' . $romanMap[$tw] . ' TAHUN ' . $tahun;
                $assertContains($expected, $html, "Cover harus mengandung '{$expected}'");
            });
        }
    }

    // ── Ringkasan ─────────────────────────────────────────────────────────────

    echo "\n" . str_repeat('─', 60) . "\n";
    $status = $failed === 0 ? '✓ SEMUA PASS' : "✗ {$failed} GAGAL";
    echo "Hasil: {$passed} passed, {$failed} failed  [{$status}]\n";

    if ($failed > 0) {
        echo "\nTest yang gagal:\n";
        foreach ($errors as $err) {
            echo $err . "\n";
        }
        exit(1);
    }

    echo "\nSemua test Property 2 berhasil.\n\n";
    exit(0);
}
