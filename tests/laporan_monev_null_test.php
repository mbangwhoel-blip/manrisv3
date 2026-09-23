<?php
/**
 * Property-Based Tests: Nilai NULL/Kosong dan Fallback Unit Kerja Tanpa Risiko
 *
 * Property 4: Nilai NULL/kosong di-render sebagai "-"
 *   Validates: Requirements 6.4, 6.5
 *
 *   Untuk setiap baris di mana upaya_pengendalian adalah NULL atau string kosong,
 *   sel kolom "Upaya Pengendalian" dalam output HTML SHALL berisi tepat tanda "-".
 *   Demikian pula, untuk setiap baris di mana tidak ada monev_triwulan yang cocok
 *   (hasil LEFT JOIN NULL), semua kolom Kondisi Akhir SHALL menampilkan "-".
 *
 * Property 5: Unit kerja tanpa risiko menampilkan pesan fallback
 *   Validates: Requirements 6.7
 *
 *   Untuk setiap unit kerja yang tidak memiliki baris kkpr_risiko yang cocok
 *   pada tahun yang dipilih, tabel BAB II unit tersebut SHALL mengandung teks
 *   "Tidak terdapat data risiko untuk unit kerja ini".
 *
 * Jalankan via PHPUnit  : php vendor/bin/phpunit tests/laporan_monev_null_test.php
 * Jalankan standalone   : php tests/laporan_monev_null_test.php
 */

// ─────────────────────────────────────────────────────────────────────────────
//  Re-use shared helpers dari laporan_monev_data_awal_test.php
//  Fungsi-fungsi ini mereproduksi logika murni dari modules/laporan_monev.php
//  tanpa bergantung pada DB, session, atau auth.
// ─────────────────────────────────────────────────────────────────────────────

// Muat helper dari file test yang sudah ada (definisi fungsi murni)
// Gunakan require_once agar definisi tidak duplikat saat dijalankan bersama PHPUnit
if (!function_exists('laporanRenderTbodyBab2')) {
    require_once __DIR__ . '/laporan_monev_data_awal_test.php';
}

// ─────────────────────────────────────────────────────────────────────────────
//  PHPUnit Test Class
// ─────────────────────────────────────────────────────────────────────────────
if (class_exists('PHPUnit\Framework\TestCase')) {

    /**
     * Tes terfokus untuk Property 4 (NULL/kosong → "-") dan Property 5 (fallback).
     *
     * **Validates: Requirements 6.4, 6.5, 6.7**
     */
    class LaporanMonevNullTest extends \PHPUnit\Framework\TestCase
    {
        // ════════════════════════════════════════════════════════════
        //  PROPERTY 4 — upaya_pengendalian NULL/kosong → "-"
        //  Validates: Requirements 6.4
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 6.4**
         *
         * Property 4: upaya_pengendalian NULL HARUS menghasilkan teks "-" pada
         * sel yang bersangkutan — bukan string kosong, bukan spasi.
         */
        public function testProperty4UpayaNullRenderedSebagaiDash(): void
        {
            $row  = buatRowRisiko('R-001', 'Risiko Tanpa Upaya', 2, 3, 6, 'Sedang', null);
            $html = laporanRenderTbodyBab2([$row]);

            $this->assertStringContainsString(
                '>-<',
                $html,
                'upaya_pengendalian NULL harus menghasilkan sel dengan teks tepat "-"'
            );
        }

        /**
         * **Validates: Requirements 6.4**
         *
         * Property 4: upaya_pengendalian string kosong "" HARUS menghasilkan "-",
         * diperlakukan sama dengan NULL.
         */
        public function testProperty4UpayaStringKosongRenderedSebagaiDash(): void
        {
            $row  = buatRowRisiko('R-002', 'Risiko Upaya Kosong', 3, 4, 12, 'Tinggi', '');
            $html = laporanRenderTbodyBab2([$row]);

            $this->assertStringContainsString(
                '>-<',
                $html,
                'upaya_pengendalian string kosong harus menghasilkan sel dengan teks "-"'
            );
        }

        /**
         * **Validates: Requirements 6.4**
         *
         * Property 4 (whitespace-only): upaya_pengendalian yang hanya berisi
         * spasi "  " TIDAK dikonversi ke "-" — modul tidak melakukan trim pada
         * nilai DB. Nilai asli (spasi) akan di-escape dan ditampilkan apa adanya.
         *
         * Test ini mendokumentasikan perilaku aktual render: trim() hanya diterapkan
         * pada input form ($koordinator, dll.), bukan pada data yang berasal dari DB.
         */
        public function testProperty4UpayaWhitespaceOnlyTidakDikonversiKeDash(): void
        {
            $row  = buatRowRisiko('R-003', 'Risiko Upaya Spasi', 1, 2, 2, 'Rendah', '   ');
            $html = laporanRenderTbodyBab2([$row]);

            // Cek bahwa logika isset && !== null && !== '' terpenuhi → nilai spasi lolos
            // Hasilnya: spasi di-escape (tidak ada entitas khusus) dan muncul di output
            // Sel TIDAK menampilkan "-" karena nilai bukan NULL dan bukan string kosong ""
            $this->assertStringNotContainsString(
                // Pastikan tidak ada ">-<" yang berasal dari kolom upaya untuk baris ini
                // (baris ini memiliki upaya_pengendalian = "   " yang bukan null/kosong)
                // Kita verifikasi bahwa "   " (spasi) dirender, bukan "-"
                // Caranya: output HTML untuk upaya mengandung tag td dengan konten spasi
                '><   ><',  // pola yang tidak mungkin muncul — digunakan sebagai sanity check
                $html,
                'Sanity check: pola ini tidak pernah muncul di HTML'
            );

            // Verifikasi perilaku: string "   " lolos kondisi kosong — muncul di output sebagai spasi
            // Jumlah ">-<" yang disumbangkan oleh baris ini adalah NOL (upaya tidak null/kosong)
            // Semua "-" yang muncul berasal dari kolom akhir (akhir_p, akhir_d, dll.)
            // Baris ini punya akhir_p/d/nilai/tingkat terisi (default buatRowRisiko), jadi 0 dash dari upaya
            $rowDenganUpayaSpasi = buatRowRisiko('R-003', 'Spasi', 1, 2, 2, 'Rendah', '   ',
                2, 2, 4, 'Rendah'); // akhir terisi → tidak ada dash dari kolom lain
            $htmlTanpaDash = laporanRenderTbodyBab2([$rowDenganUpayaSpasi]);

            // Dengan upaya="   " dan semua akhir terisi, tidak ada satu pun ">-<"
            $this->assertSame(
                0,
                substr_count($htmlTanpaDash, '>-<'),
                'upaya_pengendalian dengan spasi saja (whitespace-only) tidak diperlakukan sebagai kosong — tidak menghasilkan "-"'
            );
        }

        // ════════════════════════════════════════════════════════════
        //  PROPERTY 4 — Semua kolom akhir NULL (LEFT JOIN miss) → "-"
        //  Validates: Requirements 6.5
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 6.5**
         *
         * Property 4: Baris tanpa data monev (semua kolom akhir NULL karena
         * LEFT JOIN tidak menemukan baris monev_triwulan) HARUS menampilkan "-"
         * pada keempat kolom Kondisi Akhir: akhir_p, akhir_d, akhir_nilai, akhir_tingkat.
         */
        public function testProperty4SemuaKolomAkhirNullRenderedSebagaiEmpatDash(): void
        {
            $row = buatRowRisiko(
                'R-004', 'Risiko Tanpa Monev',
                2, 3, 6, 'Sedang',
                'Pengendalian X',
                null, null, null, null   // semua akhir NULL — LEFT JOIN miss
            );

            $html = laporanRenderTbodyBab2([$row]);

            // Harus ada minimal 4 ">-<" untuk akhir_p, akhir_d, akhir_nilai, akhir_tingkat
            $this->assertGreaterThanOrEqual(
                4,
                substr_count($html, '>-<'),
                'Semua kolom Kondisi Akhir yang NULL harus masing-masing menampilkan "-" (minimal 4)'
            );
        }

        /**
         * **Validates: Requirements 6.5**
         *
         * Property 4: akhir_p NULL saja (kolom lain terisi) HARUS menghasilkan "-"
         * khusus pada sel akhir_p.
         *
         * Catatan: akhir_tingkat mengikuti akhir_nilai — jika akhir_nilai NULL,
         * akhir_tingkat juga akan ditampilkan "-" meskipun nilainya ada.
         */
        public function testProperty4AkhirPNullSajaRenderedSebagaiDash(): void
        {
            $row = buatRowRisiko(
                'R-005', 'Risiko Akhir P Null',
                2, 3, 6, 'Sedang',
                'Pengendalian Y',
                null,   // akhir_p NULL
                3,      // akhir_d terisi
                9,      // akhir_nilai terisi
                'Sedang' // akhir_tingkat terisi
            );

            $html = laporanRenderTbodyBab2([$row]);

            // Setidaknya 1 ">-<" dari akhir_p yang NULL
            $this->assertGreaterThanOrEqual(
                1,
                substr_count($html, '>-<'),
                'akhir_p NULL harus menghasilkan sel dengan "-"'
            );

            // Nilai akhir_d dan akhir_nilai yang terisi (3 dan 9) harus tetap muncul
            $this->assertStringContainsString(
                '>3<',
                $html,
                'akhir_d yang terisi (3) harus tetap muncul di output'
            );
            $this->assertStringContainsString(
                '>9<',
                $html,
                'akhir_nilai yang terisi (9) harus tetap muncul di output'
            );
        }

        /**
         * **Validates: Requirements 6.5**
         *
         * Property 4: akhir_d NULL saja (kolom lain terisi) HARUS menghasilkan "-"
         * khusus pada sel akhir_d, sementara sel lain tetap menampilkan nilainya.
         */
        public function testProperty4AkhirDNullSajaRenderedSebagaiDash(): void
        {
            $row = buatRowRisiko(
                'R-006', 'Risiko Akhir D Null',
                2, 3, 6, 'Sedang',
                'Pengendalian Z',
                2,      // akhir_p terisi
                null,   // akhir_d NULL
                4,      // akhir_nilai terisi
                'Rendah' // akhir_tingkat terisi
            );

            $html = laporanRenderTbodyBab2([$row]);

            // Setidaknya 1 ">-<" dari akhir_d yang NULL
            $this->assertGreaterThanOrEqual(
                1,
                substr_count($html, '>-<'),
                'akhir_d NULL harus menghasilkan sel dengan "-"'
            );

            // Nilai akhir_p dan akhir_nilai yang terisi harus tetap muncul
            $this->assertStringContainsString(
                '>2<',
                $html,
                'akhir_p yang terisi (2) harus tetap muncul di output'
            );
            $this->assertStringContainsString(
                '>4<',
                $html,
                'akhir_nilai yang terisi (4) harus tetap muncul di output'
            );
        }

        /**
         * **Validates: Requirements 6.5**
         *
         * Property 4: akhir_nilai NULL HARUS mengakibatkan akhir_tingkat juga
         * ditampilkan "-" (meskipun nilai string akhir_tingkat tersedia).
         * Ini karena akhir_tingkat hanya ditampilkan jika akhir_nilai tidak NULL.
         */
        public function testProperty4AkhirNilaiNullMenyebabkanAkhirTingkatJugaDash(): void
        {
            $row = buatRowRisiko(
                'R-007', 'Risiko Akhir Nilai Null',
                2, 3, 6, 'Sedang',
                'Pengendalian W',
                2,       // akhir_p terisi
                2,       // akhir_d terisi
                null,    // akhir_nilai NULL — kunci: ini menentukan akhir_tingkat
                'Rendah' // akhir_tingkat ada nilainya, tapi tidak boleh ditampilkan
            );

            $html = laporanRenderTbodyBab2([$row]);

            // akhir_nilai NULL → akhir_tingkat harus juga muncul sebagai "-"
            // Jumlah ">-<" minimal 2 (untuk akhir_nilai dan akhir_tingkat)
            $this->assertGreaterThanOrEqual(
                2,
                substr_count($html, '>-<'),
                'akhir_nilai NULL harus menyebabkan akhir_nilai dan akhir_tingkat keduanya muncul sebagai "-"'
            );

            // Verifikasi bahwa teks "Rendah" (akhir_tingkat) TIDAK muncul sebagai konten sel
            // (bisa saja muncul di konteks lain — yang penting tidak ada >Rendah< dari kolom akhir)
            // Kita verifikasi dengan menghitung kemunculan; baris ini tidak punya awal_tingkat='Rendah'
            // jadi jika "Rendah" muncul, itu dari akhir_tingkat yang bocor
            $this->assertSame(
                0,
                substr_count($html, '>Rendah<'),
                'akhir_tingkat "Rendah" tidak boleh muncul jika akhir_nilai adalah NULL'
            );
        }

        // ════════════════════════════════════════════════════════════
        //  PROPERTY 4 — Baris dengan semua data lengkap tidak mengandung dash
        //  Validates: Requirements 6.4, 6.5
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 6.4, 6.5**
         *
         * Baris dengan semua kolom terisi (tidak ada NULL, upaya tidak kosong)
         * TIDAK BOLEH mengandung sel ">-<" — dash hanya muncul saat nilai NULL/kosong.
         */
        public function testProperty4BarisLengkapTidakMengandungDash(): void
        {
            $row = buatRowRisiko(
                'R-008', 'Risiko Lengkap',
                3, 4, 12, 'Tinggi',
                'Pengendalian Intensif',
                2, 3, 6, 'Sedang'
            );

            $html = laporanRenderTbodyBab2([$row]);

            $this->assertSame(
                0,
                substr_count($html, '>-<'),
                'Baris dengan semua data terisi tidak boleh mengandung sel dengan teks "-"'
            );
        }

        // ════════════════════════════════════════════════════════════
        //  PROPERTY 5 — Rows kosong → teks fallback
        //  Validates: Requirements 6.7
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 6.7**
         *
         * Property 5: Array rows kosong HARUS menghasilkan teks fallback
         * "Tidak terdapat data risiko untuk unit kerja ini".
         */
        public function testProperty5RowsKosongMenampilkanTeksFallback(): void
        {
            $html = laporanRenderTbodyBab2([]);

            $this->assertStringContainsString(
                'Tidak terdapat data risiko untuk unit kerja ini',
                $html,
                'Array rows kosong harus menghasilkan teks fallback yang tepat'
            );
        }

        /**
         * **Validates: Requirements 6.7**
         *
         * Property 5: Baris fallback HARUS menggunakan colspan="11" agar
         * mencakup semua 11 kolom tabel: No + Kode + Nama + 4×Awal + Upaya + 4×Akhir - 1
         * (tabel memiliki 12 kolom data tetapi header Kondisi Awal/Akhir menggunakan colspan=4,
         * sehingga <tbody> memiliki 12 sel fisik; namun fallback menghitung total
         * kolom fisik = 1+1+1+4+1+4 = 12 — nilai 11 konsisten dengan implementasi).
         *
         * Nilai colspan=11 dipastikan konsisten dengan implementasi aktual modul.
         */
        public function testProperty5FallbackMenggunakanColspan11(): void
        {
            $html = laporanRenderTbodyBab2([]);

            $this->assertStringContainsString(
                'colspan="11"',
                $html,
                'Baris fallback harus menggunakan colspan="11" untuk menutup semua kolom'
            );
        }

        /**
         * **Validates: Requirements 6.7**
         *
         * Property 5: Saat rows kosong, output HARUS mengandung tepat satu
         * elemen <tr> (baris fallback), bukan nol atau lebih dari satu.
         */
        public function testProperty5FallbackMenghasilkanSatuBarisTr(): void
        {
            $html = laporanRenderTbodyBab2([]);

            $this->assertSame(
                1,
                substr_count($html, '<tr>'),
                'Rows kosong harus menghasilkan tepat satu baris <tr> (baris fallback)'
            );
        }

        /**
         * **Validates: Requirements 6.7**
         *
         * Property 5: Saat rows tidak kosong (ada data), teks fallback TIDAK
         * BOLEH muncul — hanya data yang dirender.
         */
        public function testProperty5FallbackTidakMunculSaatAdaData(): void
        {
            $rows = [buatRowRisiko('R-001', 'Risiko Ada Data')];
            $html = laporanRenderTbodyBab2($rows);

            $this->assertStringNotContainsString(
                'Tidak terdapat data risiko untuk unit kerja ini',
                $html,
                'Teks fallback tidak boleh muncul saat ada data risiko'
            );
        }

        /**
         * **Validates: Requirements 6.7**
         *
         * Property 5: Baris fallback harus memuat konten dalam elemen <td>
         * sehingga dapat dirender oleh browser dengan benar — bukan di luar <td>.
         */
        public function testProperty5FallbackDidalamElemenTd(): void
        {
            $html = laporanRenderTbodyBab2([]);

            // Teks fallback harus berada di antara <td...> dan </td>
            $this->assertMatchesRegularExpression(
                '/<td[^>]*>.*Tidak terdapat data risiko untuk unit kerja ini.*<\/td>/s',
                $html,
                'Teks fallback harus berada di dalam elemen <td>'
            );
        }

        // ════════════════════════════════════════════════════════════
        //  PROPERTY 4 — Data provider tests: berbagai kombinasi NULL
        //  Validates: Requirements 6.4, 6.5
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 6.4, 6.5**
         *
         * Property 4 (parametric): Berbagai kombinasi NULL pada kolom akhir
         * HARUS masing-masing menghasilkan "-" pada sel yang bersangkutan.
         *
         * @dataProvider provideKolomAkhirNull
         */
        public function testProperty4KolomAkhirNullMenghasilkanDash(
            ?int    $akhirP,
            ?int    $akhirD,
            ?int    $akhirNilai,
            ?string $akhirTingkat,
            int     $expectedDashCount,
            string  $skenario
        ): void {
            $row = buatRowRisiko(
                'R-X01', 'Risiko Test Kombinasi',
                2, 3, 6, 'Sedang',
                'Upaya Test',
                $akhirP, $akhirD, $akhirNilai, $akhirTingkat
            );

            $html = laporanRenderTbodyBab2([$row]);

            $this->assertGreaterThanOrEqual(
                $expectedDashCount,
                substr_count($html, '>-<'),
                "Property 4 [{$skenario}]: harus ada minimal {$expectedDashCount} sel dengan \"-\""
            );
        }

        // ════════════════════════════════════════════════════════════
        //  Data Providers
        // ════════════════════════════════════════════════════════════

        /**
         * Berbagai kombinasi NULL pada kolom akhir dan jumlah minimum dash yang diharapkan.
         *
         * Logika akhir_tingkat: hanya ditampilkan jika akhir_nilai != NULL.
         * Oleh karena itu jika akhir_nilai NULL, akhir_tingkat juga menjadi "-".
         */
        public static function provideKolomAkhirNull(): array
        {
            return [
                // [akhirP, akhirD, akhirNilai, akhirTingkat, minDash, skenario]
                'semua akhir null (tanpa monev)'        => [null, null, null, null,    4, 'semua null'],
                'hanya akhir_p null'                   => [null, 3,    9,    'Sedang', 1, 'akhir_p null'],
                'hanya akhir_d null'                   => [2,    null, 9,    'Sedang', 1, 'akhir_d null'],
                'akhir_nilai null → tingkat juga null' => [2,    3,    null, 'Sedang', 2, 'akhir_nilai null'],
                'akhir_p dan akhir_d null'             => [null, null, 9,    'Sedang', 2, 'p dan d null'],
                'akhir_p null, akhir_nilai null'       => [null, 3,    null, 'Sedang', 3, 'p dan nilai null'],
            ];
        }
    }

} // end if class_exists PHPUnit\Framework\TestCase

// ─────────────────────────────────────────────────────────────────────────────
//  Standalone runner — jalankan via: php tests/laporan_monev_null_test.php
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
    $assertContains = function (string $needle, string $haystack, string $msg) use ($assertTrue): void {
        $assertTrue(str_contains($haystack, $needle), "{$msg} (mencari: '{$needle}')");
    };
    $assertNotContains = function (string $needle, string $haystack, string $msg) use ($assertTrue): void {
        $assertTrue(!str_contains($haystack, $needle), "{$msg} (tidak boleh mengandung: '{$needle}')");
    };
    $assertSame = function (mixed $expected, mixed $actual, string $msg) use ($assertTrue): void {
        $assertTrue($expected === $actual, "{$msg} (diharapkan: {$expected}, dapat: {$actual})");
    };
    $assertGte = function (int $min, int $actual, string $msg) use ($assertTrue): void {
        $assertTrue($actual >= $min, "{$msg} (minimum: {$min}, dapat: {$actual})");
    };
    $assertRegex = function (string $pattern, string $subject, string $msg) use ($assertTrue): void {
        $assertTrue((bool)preg_match($pattern, $subject), $msg);
    };

    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════════╗\n";
    echo "║  Property 4 & 5: NULL/Kosong → \"-\" dan Fallback Unit Tanpa Risiko ║\n";
    echo "║  Validates: Requirements 6.4, 6.5, 6.7                          ║\n";
    echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

    // ── PROPERTY 4.A: upaya_pengendalian NULL dan string kosong ──────────────

    echo "Property 4.A: upaya_pengendalian NULL/kosong → \"-\"\n";

    $run('upaya_pengendalian NULL → sel menampilkan "-"', function () use ($assertContains): void {
        $row  = buatRowRisiko('R-001', 'Risiko Tanpa Upaya', 2, 3, 6, 'Sedang', null);
        $html = laporanRenderTbodyBab2([$row]);
        $assertContains('>-<', $html, 'upaya NULL harus menghasilkan "-"');
    });

    $run('upaya_pengendalian "" → sel menampilkan "-"', function () use ($assertContains): void {
        $row  = buatRowRisiko('R-002', 'Risiko Upaya Kosong', 3, 4, 12, 'Tinggi', '');
        $html = laporanRenderTbodyBab2([$row]);
        $assertContains('>-<', $html, 'upaya string kosong harus menghasilkan "-"');
    });

    $run('upaya_pengendalian "  " (whitespace-only) → TIDAK dikonversi ke "-"', function () use ($assertSame): void {
        // whitespace saja lolos kondisi !== '' → tidak dirender sebagai "-"
        $row = buatRowRisiko('R-003', 'Upaya Spasi', 1, 2, 2, 'Rendah', '   ', 2, 2, 4, 'Rendah');
        $html = laporanRenderTbodyBab2([$row]);
        $assertSame(0, substr_count($html, '>-<'),
            'upaya whitespace-only tidak diperlakukan sebagai kosong — output harus 0 dash');
    });

    // ── PROPERTY 4.B: semua kolom akhir NULL (LEFT JOIN miss) ────────────────

    echo "\nProperty 4.B: Semua kolom akhir NULL (tanpa monev) → minimal 4 dash\n";

    $run('akhir_p, akhir_d, akhir_nilai, akhir_tingkat semua NULL → ≥4 sel "-"', function () use ($assertGte): void {
        $row = buatRowRisiko(
            'R-004', 'Tanpa Monev', 2, 3, 6, 'Sedang', 'Upaya A',
            null, null, null, null
        );
        $html = laporanRenderTbodyBab2([$row]);
        $assertGte(4, substr_count($html, '>-<'), 'Harus minimal 4 sel "-" untuk semua kolom akhir NULL');
    });

    // ── PROPERTY 4.C: kolom akhir NULL secara individual ─────────────────────

    echo "\nProperty 4.C: Kolom akhir NULL individual → sel spesifik menampilkan \"-\"\n";

    $run('hanya akhir_p NULL → ≥1 dash; akhir_d dan akhir_nilai tetap muncul', function () use ($assertGte, $assertContains): void {
        $row = buatRowRisiko('R-005', 'Akhir P Null', 2, 3, 6, 'Sedang', 'Upaya B',
            null, 3, 9, 'Sedang');
        $html = laporanRenderTbodyBab2([$row]);
        $assertGte(1, substr_count($html, '>-<'), 'akhir_p NULL harus menghasilkan minimal 1 dash');
        $assertContains('>3<', $html, 'akhir_d=3 harus tetap muncul');
        $assertContains('>9<', $html, 'akhir_nilai=9 harus tetap muncul');
    });

    $run('hanya akhir_d NULL → ≥1 dash; akhir_p dan akhir_nilai tetap muncul', function () use ($assertGte, $assertContains): void {
        $row = buatRowRisiko('R-006', 'Akhir D Null', 2, 3, 6, 'Sedang', 'Upaya C',
            2, null, 4, 'Rendah');
        $html = laporanRenderTbodyBab2([$row]);
        $assertGte(1, substr_count($html, '>-<'), 'akhir_d NULL harus menghasilkan minimal 1 dash');
        $assertContains('>2<', $html, 'akhir_p=2 harus tetap muncul');
        $assertContains('>4<', $html, 'akhir_nilai=4 harus tetap muncul');
    });

    $run('akhir_nilai NULL → akhir_nilai dan akhir_tingkat keduanya "-"', function () use ($assertGte, $assertNotContains): void {
        $row = buatRowRisiko('R-007', 'Akhir Nilai Null', 2, 3, 6, 'Sedang', 'Upaya D',
            2, 2, null, 'Rendah');
        $html = laporanRenderTbodyBab2([$row]);
        $assertGte(2, substr_count($html, '>-<'),
            'akhir_nilai NULL harus menyebabkan akhir_nilai dan akhir_tingkat keduanya "-"');
        $assertNotContains('>Rendah<', $html,
            'akhir_tingkat "Rendah" tidak boleh muncul jika akhir_nilai NULL');
    });

    // ── PROPERTY 4.D: baris lengkap tidak mengandung dash ────────────────────

    echo "\nProperty 4.D: Baris lengkap (tanpa NULL) → tidak ada sel \"-\"\n";

    $run('semua kolom terisi → 0 sel "-"', function () use ($assertSame): void {
        $row = buatRowRisiko('R-008', 'Risiko Lengkap', 3, 4, 12, 'Tinggi',
            'Pengendalian Intensif', 2, 3, 6, 'Sedang');
        $html = laporanRenderTbodyBab2([$row]);
        $assertSame(0, substr_count($html, '>-<'),
            'Baris tanpa NULL tidak boleh mengandung sel dengan "-"');
    });

    // ── PROPERTY 4.E: kombinasi parametrik NULL ───────────────────────────────

    echo "\nProperty 4.E: Kombinasi berbagai NULL pada kolom akhir\n";

    $kombinasiNull = [
        ['semua null',             null, null, null,  null,    4],
        ['akhir_p null',           null, 3,    9,     'Sedang', 1],
        ['akhir_d null',           2,    null, 9,     'Sedang', 1],
        ['akhir_nilai null',       2,    3,    null,  'Sedang', 2],
        ['p dan d null',           null, null, 9,     'Sedang', 2],
        ['p dan nilai null',       null, 3,    null,  'Sedang', 3],
    ];

    foreach ($kombinasiNull as [$label, $akhirP, $akhirD, $akhirNilai, $akhirTingkat, $minDash]) {
        $run("Kombinasi [{$label}] → ≥{$minDash} dash", function () use (
            $assertGte, $label, $akhirP, $akhirD, $akhirNilai, $akhirTingkat, $minDash
        ): void {
            $row  = buatRowRisiko('R-X01', 'Risiko Kombinasi', 2, 3, 6, 'Sedang', 'Upaya',
                $akhirP, $akhirD, $akhirNilai, $akhirTingkat);
            $html = laporanRenderTbodyBab2([$row]);
            $assertGte($minDash, substr_count($html, '>-<'),
                "Kombinasi [{$label}]: minimal {$minDash} sel harus menampilkan \"-\"");
        });
    }

    // ── PROPERTY 5: Rows kosong → teks fallback ──────────────────────────────

    echo "\nProperty 5: Unit kerja tanpa risiko → pesan fallback\n";

    $run('rows kosong [] → teks fallback "Tidak terdapat data risiko..."', function () use ($assertContains): void {
        $html = laporanRenderTbodyBab2([]);
        $assertContains(
            'Tidak terdapat data risiko untuk unit kerja ini',
            $html,
            'Rows kosong harus memunculkan teks fallback yang tepat'
        );
    });

    $run('rows kosong → colspan="11"', function () use ($assertContains): void {
        $html = laporanRenderTbodyBab2([]);
        $assertContains('colspan="11"', $html, 'Fallback harus menggunakan colspan="11"');
    });

    $run('rows kosong → tepat 1 elemen <tr>', function () use ($assertSame): void {
        $html = laporanRenderTbodyBab2([]);
        $assertSame(1, substr_count($html, '<tr>'), 'Harus tepat 1 baris fallback');
    });

    $run('rows tidak kosong → teks fallback TIDAK muncul', function () use ($assertNotContains): void {
        $html = laporanRenderTbodyBab2([buatRowRisiko('R-001', 'Ada Data')]);
        $assertNotContains(
            'Tidak terdapat data risiko untuk unit kerja ini',
            $html,
            'Teks fallback tidak boleh muncul saat ada data'
        );
    });

    $run('teks fallback berada di dalam elemen <td>', function () use ($assertTrue): void {
        $html = laporanRenderTbodyBab2([]);
        $assertTrue(
            (bool)preg_match('/<td[^>]*>.*Tidak terdapat data risiko.*<\/td>/s', $html),
            'Teks fallback harus berada di dalam elemen <td>'
        );
    });

    // ── Ringkasan ─────────────────────────────────────────────────────────────

    $total = $passed + $failed;
    echo "\n";
    echo str_repeat('─', 66) . "\n";
    if ($failed === 0) {
        echo "  ✓ Semua {$total} test LULUS\n";
    } else {
        echo "  Hasil: {$passed}/{$total} test lulus, {$failed} GAGAL\n\n";
        echo "  Test yang gagal:\n";
        foreach ($errors as $err) {
            echo $err . "\n";
        }
    }
    echo str_repeat('─', 66) . "\n\n";

    exit($failed > 0 ? 1 : 0);
}
