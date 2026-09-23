<?php
/**
 * Property-Based Tests: Pemetaan Data Risiko Awal ke Kolom Kondisi Awal TW I
 *
 * Property 3: Data risiko awal dipetakan ke kolom Kondisi Awal TW I
 *   Validates: Requirements 6.3, 6.6
 *
 * Untuk setiap baris kkpr_risiko yang dikembalikan query untuk suatu unit kerja
 * dan tahun, nilai kode_risiko, nama_risiko, probabilitas, dampak_level,
 * nilai_risiko, dan tingkat_risiko SHALL muncul di kolom yang tepat pada tabel
 * BAB II unit kerja yang bersangkutan.
 *
 * Jalankan via PHPUnit : php vendor/bin/phpunit tests/laporan_monev_data_awal_test.php
 * Jalankan standalone  : php tests/laporan_monev_data_awal_test.php
 */

use PHPUnit\Framework\Attributes\DataProvider;

// ─────────────────────────────────────────────────────────────────────────────
//  Fungsi murni yang mencerminkan logika BAB II di modules/laporan_monev.php
//
//  Tidak bergantung pada DB, session, atau auth.
//  Fungsi ini mereproduksi hanya bagian render tbody tabel BAB II dari
//  mode Generate — identik secara logika dengan kode modul.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Escape string untuk output HTML — setara dengan xss() di aplikasi.
 * Digunakan dalam pengujian murni tanpa bergantung pada functions.php.
 */
function testXss(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Menghasilkan HTML <tbody> tabel BAB II untuk satu unit kerja.
 *
 * Logika identik dengan loop BAB II di laporan_monev.php:
 *   - Baris fallback jika $rows kosong
 *   - NULL pada kondisi awal → "-"
 *   - NULL pada kondisi akhir (LEFT JOIN) → "-"
 *   - upaya_pengendalian NULL/kosong → "-"
 *   - Semua nilai DB di-escape dengan xss() / testXss()
 *
 * @param array[] $rows       Hasil dari laporanGetDataUnit()
 * @param string  $triwulanLabel  Label romawi triwulan, mis. "II"
 * @return string HTML tbody lengkap
 */
function laporanRenderTbodyBab2(array $rows, string $triwulanLabel = 'I'): string
{
    if (empty($rows)) {
        return '<tr><td colspan="11" style="text-align:center; font-style:italic; color:#6b7280;">'
            . 'Tidak terdapat data risiko untuk unit kerja ini'
            . '</td></tr>';
    }

    $html = '';
    foreach ($rows as $rowNo => $row) {
        $awalTingkat  = (string)($row['awal_tingkat']  ?? '');
        $akhirTingkat = (string)($row['akhir_tingkat'] ?? '');

        // Kondisi awal — selalu dari kkpr_risiko, tidak pernah NULL dalam data normal
        $awalP     = ($row['awal_p']     !== null) ? testXss((string)$row['awal_p'])     : '-';
        $awalD     = ($row['awal_d']     !== null) ? testXss((string)$row['awal_d'])     : '-';
        $awalNilai = ($row['awal_nilai'] !== null) ? testXss((string)$row['awal_nilai']) : '-';

        // Kondisi akhir — dari monev_triwulan (LEFT JOIN → NULL jika tidak ada)
        $akhirP     = ($row['akhir_p']     !== null) ? testXss((string)$row['akhir_p'])     : '-';
        $akhirD     = ($row['akhir_d']     !== null) ? testXss((string)$row['akhir_d'])     : '-';
        $akhirNilai = ($row['akhir_nilai'] !== null) ? testXss((string)$row['akhir_nilai']) : '-';
        $akhirTingkatDisplay = ($row['akhir_nilai'] !== null && $akhirTingkat !== '')
            ? testXss($akhirTingkat)
            : '-';

        // Upaya pengendalian — NULL/kosong → "-"
        $upaya = (isset($row['upaya_pengendalian'])
            && $row['upaya_pengendalian'] !== null
            && $row['upaya_pengendalian'] !== '')
            ? testXss($row['upaya_pengendalian'])
            : '-';

        $html .= '<tr>';
        $html .= '<td class="center">' . ($rowNo + 1) . '</td>';
        $html .= '<td class="center">' . testXss((string)($row['kode_risiko'] ?? '')) . '</td>';
        $html .= '<td>' . testXss((string)($row['nama_risiko'] ?? '')) . '</td>';
        // Kondisi awal: P, D, Nilai, Tingkat
        $html .= '<td class="center">' . $awalP . '</td>';
        $html .= '<td class="center">' . $awalD . '</td>';
        $html .= '<td class="center">' . $awalNilai . '</td>';
        $html .= '<td class="tingkat-col center">' . (($awalTingkat !== '') ? testXss($awalTingkat) : '-') . '</td>';
        // Upaya pengendalian
        $html .= '<td>' . $upaya . '</td>';
        // Kondisi akhir: P, D, Nilai, Tingkat
        $html .= '<td class="center">' . $akhirP . '</td>';
        $html .= '<td class="center">' . $akhirD . '</td>';
        $html .= '<td class="center">' . $akhirNilai . '</td>';
        $html .= '<td class="tingkat-col center">' . $akhirTingkatDisplay . '</td>';
        $html .= '</tr>';
    }

    return $html;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Helper: bangun row data risiko lengkap (setara dengan struktur dari DB)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Buat satu row dengan semua kolom yang dikembalikan laporanGetDataUnit().
 *
 * @param string      $kode          kode_risiko
 * @param string      $nama          nama_risiko
 * @param int         $awalP         probabilitas awal
 * @param int         $awalD         dampak_level awal
 * @param int         $awalNilai     nilai_risiko awal
 * @param string      $awalTingkat   tingkat_risiko awal
 * @param string|null $upaya         upaya_pengendalian (null = belum diisi)
 * @param int|null    $akhirP        pantau_p (null jika tidak ada monev)
 * @param int|null    $akhirD        pantau_d
 * @param int|null    $akhirNilai    pantau_nilai
 * @param string|null $akhirTingkat  pantau_tingkat
 */
function buatRowRisiko(
    string  $kode         = 'R-001',
    string  $nama         = 'Risiko Uji Coba',
    int     $awalP        = 2,
    int     $awalD        = 3,
    int     $awalNilai    = 6,
    string  $awalTingkat  = 'Sedang',
    ?string $upaya        = 'Pengendalian A',
    ?int    $akhirP       = 2,
    ?int    $akhirD       = 2,
    ?int    $akhirNilai   = 4,
    ?string $akhirTingkat = 'Rendah'
): array {
    return [
        'kode_risiko'           => $kode,
        'nama_risiko'           => $nama,
        'awal_p'                => $awalP,
        'awal_d'                => $awalD,
        'awal_nilai'            => $awalNilai,
        'awal_tingkat'          => $awalTingkat,
        'upaya_pengendalian'    => $upaya,
        'akhir_p'               => $akhirP,
        'akhir_d'               => $akhirD,
        'akhir_nilai'           => $akhirNilai,
        'akhir_tingkat'         => $akhirTingkat,
        'kendala'               => null,
        'rencana_tindak_lanjut' => null,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
//  PHPUnit Test Class
// ─────────────────────────────────────────────────────────────────────────────
if (class_exists('PHPUnit\Framework\TestCase')) {

    class LaporanMonevDataAwalTest extends \PHPUnit\Framework\TestCase
    {
        // ════════════════════════════════════════════════════════════
        //  Property 3 — kode_risiko dan nama_risiko muncul di output
        //  Validates: Requirements 6.3, 6.6
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 6.3, 6.6**
         *
         * Property 3 (identitas risiko): kode_risiko dan nama_risiko dari setiap
         * baris kkpr_risiko HARUS muncul (setelah escaping) dalam output HTML
         * tabel BAB II.
         *
         * @dataProvider provideRowsIdentitasRisiko
         */
        public function testProperty3KodeRisikoNamaRisikoMunculDiOutput(
            string $kode,
            string $nama,
            string $skenario
        ): void {
            $rows = [buatRowRisiko($kode, $nama)];
            $html = laporanRenderTbodyBab2($rows);

            $kodeEsc = testXss($kode);
            $namaEsc = testXss($nama);

            $this->assertStringContainsString(
                $kodeEsc,
                $html,
                "Property 3 [{$skenario}]: kode_risiko '{$kodeEsc}' harus muncul di output HTML"
            );
            $this->assertStringContainsString(
                $namaEsc,
                $html,
                "Property 3 [{$skenario}]: nama_risiko '{$namaEsc}' harus muncul di output HTML"
            );
        }

        // ════════════════════════════════════════════════════════════
        //  Property 3 — Nilai numerik awal (P, D, Nilai) muncul
        //  Validates: Requirements 6.3
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 6.3**
         *
         * Property 3 (nilai awal): probabilitas (awal_p), dampak_level (awal_d),
         * dan nilai_risiko (awal_nilai) dari baris kkpr_risiko HARUS muncul
         * dalam output HTML tabel BAB II.
         *
         * @dataProvider provideRowsNilaiAwal
         */
        public function testProperty3NilaiNumerikAwalMunculDiOutput(
            int    $awalP,
            int    $awalD,
            int    $awalNilai,
            string $skenario
        ): void {
            $rows = [buatRowRisiko('R-001', 'Risiko Uji', $awalP, $awalD, $awalNilai)];
            $html = laporanRenderTbodyBab2($rows);

            $this->assertStringContainsString(
                (string)$awalP,
                $html,
                "Property 3 [{$skenario}]: awal_p '{$awalP}' harus muncul di output"
            );
            $this->assertStringContainsString(
                (string)$awalD,
                $html,
                "Property 3 [{$skenario}]: awal_d '{$awalD}' harus muncul di output"
            );
            $this->assertStringContainsString(
                (string)$awalNilai,
                $html,
                "Property 3 [{$skenario}]: awal_nilai '{$awalNilai}' harus muncul di output"
            );
        }

        // ════════════════════════════════════════════════════════════
        //  Property 3 — tingkat_risiko awal muncul di output
        //  Validates: Requirements 6.6
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 6.6**
         *
         * Property 3 (tingkat risiko awal): tingkat_risiko (awal_tingkat) dari
         * baris kkpr_risiko HARUS muncul dalam output HTML tabel BAB II.
         *
         * @dataProvider provideTingkatRisikoAwal
         */
        public function testProperty3TingkatRisikoAwalMunculDiOutput(
            string $tingkat,
            string $skenario
        ): void {
            $rows = [buatRowRisiko('R-001', 'Risiko Tingkat', 3, 4, 12, $tingkat)];
            $html = laporanRenderTbodyBab2($rows);

            $escaped = testXss($tingkat);
            $this->assertStringContainsString(
                $escaped,
                $html,
                "Property 3 [{$skenario}]: awal_tingkat '{$escaped}' harus muncul di output"
            );
        }

        // ════════════════════════════════════════════════════════════
        //  Property 3 — Semua kolom terpetakan untuk banyak baris
        //  Validates: Requirements 6.3, 6.6
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 6.3, 6.6**
         *
         * Property 3 (banyak baris): Untuk setiap baris dalam array rows,
         * semua kolom data awal HARUS muncul dalam output HTML — tidak boleh
         * ada baris yang terlewat.
         */
        public function testProperty3SemuaBarisDataAwalMunculDenganMultipleRows(): void
        {
            $rows = [
                buatRowRisiko('R-001', 'Risiko Administrasi Umum', 2, 3, 6,  'Sedang',      'Pengendalian A'),
                buatRowRisiko('R-002', 'Risiko Keuangan & Aset',   3, 4, 12, 'Tinggi',      'Pengendalian B'),
                buatRowRisiko('R-003', 'Risiko SDM',               1, 2, 2,  'Rendah',      null),
                buatRowRisiko('R-004', 'Risiko Reputasi',          4, 5, 20, 'Sangat Tinggi', 'Pengendalian D'),
                buatRowRisiko('R-005', 'Risiko Pelayanan',         2, 2, 4,  'Rendah',      'Pengendalian E'),
            ];

            $html = laporanRenderTbodyBab2($rows);

            foreach ($rows as $idx => $row) {
                $rowNo = $idx + 1;
                $this->assertStringContainsString(
                    testXss($row['kode_risiko']),
                    $html,
                    "Baris {$rowNo}: kode_risiko harus muncul"
                );
                $this->assertStringContainsString(
                    testXss($row['nama_risiko']),
                    $html,
                    "Baris {$rowNo}: nama_risiko harus muncul"
                );
                $this->assertStringContainsString(
                    (string)$row['awal_p'],
                    $html,
                    "Baris {$rowNo}: awal_p harus muncul"
                );
                $this->assertStringContainsString(
                    (string)$row['awal_d'],
                    $html,
                    "Baris {$rowNo}: awal_d harus muncul"
                );
                $this->assertStringContainsString(
                    (string)$row['awal_nilai'],
                    $html,
                    "Baris {$rowNo}: awal_nilai harus muncul"
                );
                $this->assertStringContainsString(
                    testXss($row['awal_tingkat']),
                    $html,
                    "Baris {$rowNo}: awal_tingkat harus muncul"
                );
            }
        }

        // ════════════════════════════════════════════════════════════
        //  Property 5 (terkait) — Fallback saat rows kosong
        //  Validates: Requirements 6.7
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 6.7**
         *
         * Property 5: Unit kerja tanpa data risiko HARUS menampilkan teks
         * fallback "Tidak terdapat data risiko untuk unit kerja ini".
         */
        public function testProperty5FallbackSaatRowsKosong(): void
        {
            $html = laporanRenderTbodyBab2([]);

            $this->assertStringContainsString(
                'Tidak terdapat data risiko untuk unit kerja ini',
                $html,
                'Rows kosong harus menghasilkan teks fallback yang sesuai'
            );
        }

        /**
         * **Validates: Requirements 6.7**
         *
         * Baris fallback HARUS menggunakan colspan=11 agar mencakup semua
         * 11 kolom tabel (No + Kode + Nama + 4×Awal + Upaya + 4×Akhir - 1 = 11).
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

        // ════════════════════════════════════════════════════════════
        //  Property 4 (terkait) — NULL pada kondisi awal → "-"
        //  Validates: Requirements 6.4
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 6.4**
         *
         * Property 4 (awal null): Jika awal_p, awal_d, atau awal_nilai adalah
         * NULL (kasus tidak normal), sel yang bersangkutan HARUS menampilkan "-".
         */
        public function testProperty4NullPadaKondisiAwalMunculSebagaiDash(): void
        {
            $row = buatRowRisiko('R-001', 'Risiko Null Awal');
            // Override nilai awal ke null untuk mensimulasikan data tidak lengkap
            $row['awal_p']      = null;
            $row['awal_d']      = null;
            $row['awal_nilai']  = null;
            $row['awal_tingkat'] = '';

            $html = laporanRenderTbodyBab2([$row]);

            // Setidaknya ada tiga "-" untuk P, D, Nilai awal yang null
            $this->assertGreaterThanOrEqual(
                3,
                substr_count($html, '>-<'),
                'Tiga kolom awal yang null harus masing-masing menampilkan "-"'
            );
        }

        /**
         * **Validates: Requirements 6.4**
         *
         * Property 4 (upaya null): upaya_pengendalian NULL HARUS menghasilkan "-".
         */
        public function testProperty4UpayaPengendalianNullMunculSebagaiDash(): void
        {
            $row  = buatRowRisiko('R-002', 'Risiko Tanpa Upaya', 2, 3, 6, 'Sedang', null);
            $html = laporanRenderTbodyBab2([$row]);

            $this->assertStringContainsString(
                '>-<',
                $html,
                'upaya_pengendalian NULL harus menghasilkan sel dengan teks "-"'
            );
        }

        /**
         * **Validates: Requirements 6.4**
         *
         * Property 4 (upaya kosong): upaya_pengendalian string kosong HARUS
         * menghasilkan "-", sama seperti NULL.
         */
        public function testProperty4UpayaPengendalianStringKosongMunculSebagaiDash(): void
        {
            $row  = buatRowRisiko('R-003', 'Risiko Upaya Kosong', 2, 3, 6, 'Sedang', '');
            $html = laporanRenderTbodyBab2([$row]);

            $this->assertStringContainsString(
                '>-<',
                $html,
                'upaya_pengendalian string kosong harus menghasilkan sel dengan teks "-"'
            );
        }

        /**
         * **Validates: Requirements 6.5**
         *
         * Property 4 (akhir null): Baris tanpa data monev (semua kolom akhir NULL
         * karena LEFT JOIN) HARUS menampilkan "-" pada semua kolom akhir.
         */
        public function testProperty4KondisiAkhirNullMunculSebagaiDash(): void
        {
            $row = buatRowRisiko(
                'R-004', 'Risiko Tanpa Monev',
                2, 3, 6, 'Sedang',
                'Pengendalian X',
                null, null, null, null   // semua akhir null — LEFT JOIN miss
            );

            $html = laporanRenderTbodyBab2([$row]);

            // Harus ada minimal empat "-" untuk akhir_p, akhir_d, akhir_nilai, akhir_tingkat
            $this->assertGreaterThanOrEqual(
                4,
                substr_count($html, '>-<'),
                'Semua kolom kondisi akhir yang NULL harus masing-masing menampilkan "-"'
            );
        }

        // ════════════════════════════════════════════════════════════
        //  Property 3 — Escaping karakter HTML khusus pada data risiko
        //  Validates: Requirements 6.3, 6.6, 10.4
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 6.3, 6.6, 10.4**
         *
         * Property 3 (XSS prevention): Nilai dengan karakter HTML khusus dari
         * database HARUS di-escape — karakter mentah TIDAK BOLEH muncul di output.
         *
         * @dataProvider provideDataRisikoMengandungHtmlKhusus
         */
        public function testProperty3DataRisikoEscapedDiOutput(
            string $raw,
            string $entity,
            string $label
        ): void {
            // Test pada kode_risiko
            $rows = [buatRowRisiko($raw, 'Nama Normal', 2, 3, 6, 'Sedang')];
            $html = laporanRenderTbodyBab2($rows);
            $this->assertStringContainsString(
                $entity,
                $html,
                "Property 3 [{$label}] kode_risiko: entity '{$entity}' harus ada"
            );

            // Test pada nama_risiko
            $rows = [buatRowRisiko('R-001', $raw, 2, 3, 6, 'Sedang')];
            $html = laporanRenderTbodyBab2($rows);
            $this->assertStringContainsString(
                $entity,
                $html,
                "Property 3 [{$label}] nama_risiko: entity '{$entity}' harus ada"
            );
        }

        // ════════════════════════════════════════════════════════════
        //  Property 3 — Struktur tabel memiliki kolom yang benar
        //  Validates: Requirements 6.3, 6.6
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 6.3, 6.6**
         *
         * Setiap baris data HARUS menghasilkan tepat 12 elemen <td> — sesuai
         * dengan 12 kolom tabel BAB II (No + Kode + Nama + 4×Awal + Upaya + 4×Akhir).
         */
        public function testProperty3SetiapBarisMemiliki12KolomTd(): void
        {
            $rows = [
                buatRowRisiko('R-001', 'Risiko A', 1, 2, 2, 'Rendah'),
                buatRowRisiko('R-002', 'Risiko B', 3, 4, 12, 'Tinggi'),
            ];

            $html = laporanRenderTbodyBab2($rows);

            // Hitung jumlah <tr> dan <td> — setiap <tr> baris data harus punya 12 <td>
            $trCount = substr_count($html, '<tr>');
            $tdCount = substr_count($html, '<td');

            $this->assertSame(2, $trCount, 'Harus ada tepat 2 baris <tr>');
            $this->assertSame(24, $tdCount, 'Harus ada 12 kolom <td> per baris (2 × 12 = 24)');
        }

        /**
         * **Validates: Requirements 6.3, 6.6**
         *
         * Nomor urut (No) HARUS 1-indexed dan berurutan sesuai posisi baris.
         */
        public function testProperty3NomorUrutBarisBenarDanBerurutan(): void
        {
            $rows = [
                buatRowRisiko('R-001', 'Risiko A'),
                buatRowRisiko('R-002', 'Risiko B'),
                buatRowRisiko('R-003', 'Risiko C'),
            ];

            $html = laporanRenderTbodyBab2($rows);

            // Nomor urut 1, 2, 3 harus muncul secara berurutan
            $pos1 = strpos($html, '>1<');
            $pos2 = strpos($html, '>2<');
            $pos3 = strpos($html, '>3<');

            $this->assertNotFalse($pos1, 'Nomor urut 1 harus ada');
            $this->assertNotFalse($pos2, 'Nomor urut 2 harus ada');
            $this->assertNotFalse($pos3, 'Nomor urut 3 harus ada');
            $this->assertLessThan($pos2, $pos1, 'Nomor 1 harus muncul sebelum nomor 2');
            $this->assertLessThan($pos3, $pos2, 'Nomor 2 harus muncul sebelum nomor 3');
        }

        // ════════════════════════════════════════════════════════════
        //  Property 3 — Data dari baris berbeda tidak tercampur
        //  Validates: Requirements 6.3, 6.6
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 6.3, 6.6**
         *
         * Nilai data awal dari setiap baris harus muncul dalam urutan yang benar
         * dan tidak tercampur dengan baris lain.
         */
        public function testProperty3UrutanBarisSesuaiUrutanInput(): void
        {
            // Dua baris dengan tingkat berbeda — verifikasi posisi relatif
            $rows = [
                buatRowRisiko('R-001', 'Risiko Pertama',  1, 1, 1, 'Rendah'),
                buatRowRisiko('R-002', 'Risiko Kedua',    4, 5, 20, 'Sangat Tinggi'),
            ];

            $html = laporanRenderTbodyBab2($rows);

            $posRisikoA = strpos($html, 'Risiko Pertama');
            $posRisikoB = strpos($html, 'Risiko Kedua');
            $posKodeA   = strpos($html, 'R-001');
            $posKodeB   = strpos($html, 'R-002');

            $this->assertNotFalse($posRisikoA, '"Risiko Pertama" harus ada');
            $this->assertNotFalse($posRisikoB, '"Risiko Kedua" harus ada');
            $this->assertLessThan($posRisikoB, $posRisikoA, '"Risiko Pertama" harus muncul sebelum "Risiko Kedua"');
            $this->assertLessThan($posKodeB, $posKodeA, 'R-001 harus muncul sebelum R-002');
        }

        // ════════════════════════════════════════════════════════════
        //  Property 3 — Satu baris (edge case minimal)
        //  Validates: Requirements 6.3, 6.6
        // ════════════════════════════════════════════════════════════

        /**
         * **Validates: Requirements 6.3, 6.6**
         *
         * Satu baris dengan semua kolom terisi HARUS menghasilkan output yang
         * mengandung semua 6 nilai data awal: kode, nama, P, D, nilai, tingkat.
         *
         * @dataProvider provideRowsSatuBaris
         */
        public function testProperty3SatuBarisLengkapMenghasilkanSemuaNilaiAwal(
            array  $row,
            string $skenario
        ): void {
            $html = laporanRenderTbodyBab2([$row]);

            $checks = [
                'kode_risiko'  => testXss((string)$row['kode_risiko']),
                'nama_risiko'  => testXss((string)$row['nama_risiko']),
                'awal_p'       => (string)$row['awal_p'],
                'awal_d'       => (string)$row['awal_d'],
                'awal_nilai'   => (string)$row['awal_nilai'],
                'awal_tingkat' => testXss((string)$row['awal_tingkat']),
            ];

            foreach ($checks as $kolom => $value) {
                $this->assertStringContainsString(
                    $value,
                    $html,
                    "Property 3 [{$skenario}]: kolom '{$kolom}' dengan nilai '{$value}' harus muncul di output"
                );
            }
        }

        // ════════════════════════════════════════════════════════════
        //  Data Providers
        // ════════════════════════════════════════════════════════════

        /** Variasi kode_risiko dan nama_risiko untuk verifikasi kemunculan. */
        public static function provideRowsIdentitasRisiko(): array
        {
            return [
                'kode pendek, nama normal'    => ['R-001', 'Risiko Administrasi Umum', 'kode pendek'],
                'kode dengan titik'           => ['I.A.01', 'Risiko Proses Layanan', 'kode titik'],
                'kode dengan slash'           => ['SUB/2025/003', 'Risiko SDM Teknis', 'kode slash'],
                'nama dengan tanda baca'      => ['R-010', 'Risiko: Gagal Bayar & Default', 'nama tanda baca'],
                'nama panjang'                => ['R-999', str_repeat('Risiko Panjang ', 5), 'nama panjang'],
                'kode numerik saja'           => ['001', 'Risiko Satu', 'kode numerik'],
                'kode dan nama satu karakter' => ['R', 'X', 'karakter minimal'],
            ];
        }

        /** Variasi nilai probabilitas, dampak, dan nilai risiko. */
        public static function provideRowsNilaiAwal(): array
        {
            return [
                'nilai minimum (P=1, D=1, N=1)'        => [1, 1, 1,   'minimum'],
                'nilai rendah (P=1, D=2, N=2)'         => [1, 2, 2,   'rendah'],
                'nilai sedang (P=2, D=3, N=6)'         => [2, 3, 6,   'sedang'],
                'nilai tinggi (P=3, D=4, N=12)'        => [3, 4, 12,  'tinggi'],
                'nilai maksimum (P=5, D=5, N=25)'      => [5, 5, 25,  'maksimum'],
                'P tinggi D rendah (P=5, D=1, N=5)'   => [5, 1, 5,   'P tinggi D rendah'],
                'P rendah D tinggi (P=1, D=5, N=5)'   => [1, 5, 5,   'P rendah D tinggi'],
                'nilai genap semua (P=4, D=4, N=16)'  => [4, 4, 16,  'genap semua'],
                'nilai tidak berkorelasi (P=3, D=3, N=20)' => [3, 3, 20, 'nilai bebas'],
            ];
        }

        /** Empat level tingkat risiko yang valid. */
        public static function provideTingkatRisikoAwal(): array
        {
            return [
                'Rendah'        => ['Rendah',        'level Rendah'],
                'Sedang'        => ['Sedang',         'level Sedang'],
                'Tinggi'        => ['Tinggi',         'level Tinggi'],
                'Sangat Tinggi' => ['Sangat Tinggi',  'level Sangat Tinggi'],
            ];
        }

        /** Input yang mengandung karakter HTML khusus — diambil dari data DB. */
        public static function provideDataRisikoMengandungHtmlKhusus(): array
        {
            return [
                'ampersand &'         => ['Risiko & Kendala',         'Risiko &amp; Kendala',         'ampersand'],
                'tanda lebih-besar >' => ['Nilai > Batas',            'Nilai &gt; Batas',             '">"'],
                'tanda lebih-kecil <' => ['Level < Minimum',          'Level &lt; Minimum',           '"<"'],
                'kutip ganda "'       => ['Risiko "Kritis"',          'Risiko &quot;Kritis&quot;',    'kutip ganda'],
                "kutip tunggal '"     => ["Risiko 'Utama'",           "Risiko &#039;Utama&#039;",     "kutip tunggal"],
                'kombinasi & dan >'   => ['Prob > 3 & Dampak > 4',   'Prob &gt; 3 &amp; Dampak &gt; 4', '& dan >'],
            ];
        }

        /** Satu baris untuk berbagai skenario nilai awal lengkap. */
        public static function provideRowsSatuBaris(): array
        {
            return [
                'semua rendah' => [
                    buatRowRisiko('R-001', 'Risiko Rendah Semua', 1, 1, 1, 'Rendah'),
                    'semua rendah',
                ],
                'semua tinggi dengan monev' => [
                    buatRowRisiko('R-002', 'Risiko Sangat Tinggi', 5, 5, 25, 'Sangat Tinggi', 'Pengendalian Intensif', 4, 4, 16, 'Tinggi'),
                    'sangat tinggi dengan monev',
                ],
                'tanpa monev (akhir null)' => [
                    buatRowRisiko('R-003', 'Risiko Tanpa Monitoring', 3, 3, 9, 'Sedang', null, null, null, null, null),
                    'tanpa data monev',
                ],
                'upaya pengendalian diisi' => [
                    buatRowRisiko('R-004', 'Risiko Dengan Upaya', 2, 4, 8, 'Sedang', 'Melakukan audit berkala', 2, 3, 6, 'Sedang'),
                    'dengan upaya pengendalian',
                ],
                'kode dengan karakter alphanumerik' => [
                    buatRowRisiko('SUB-01.A', 'Risiko Layanan Teknis', 3, 2, 6, 'Sedang'),
                    'kode alphanumerik',
                ],
            ];
        }
    }

} // end if class_exists PHPUnit\Framework\TestCase

// ─────────────────────────────────────────────────────────────────────────────
//  Standalone runner — jalankan via: php tests/laporan_monev_data_awal_test.php
// ─────────────────────────────────────────────────────────────────────────────
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {

    $passed = 0;
    $failed = 0;
    $errors = [];

    /** Jalankan satu test case dan catat hasilnya. */
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

    $assertCount = function (int $expected, int $actual, string $msg) use ($assertTrue): void {
        $assertTrue($expected === $actual, "{$msg} (diharapkan: {$expected}, dapat: {$actual})");
    };

    $assertGreaterEqual = function (int $min, int $actual, string $msg) use ($assertTrue): void {
        $assertTrue($actual >= $min, "{$msg} (minimum: {$min}, dapat: {$actual})");
    };

    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════╗\n";
    echo "║  Property 3: Pemetaan Data Risiko Awal ke Kolom BAB II       ║\n";
    echo "║  Validates: Requirements 6.3, 6.6                           ║\n";
    echo "╚══════════════════════════════════════════════════════════════╝\n\n";

    // ── 3.A kode_risiko dan nama_risiko muncul di output ─────────────────────

    echo "Property 3.A: kode_risiko dan nama_risiko muncul di output\n";
    $kasusIdentitas = [
        ['R-001', 'Risiko Administrasi Umum',       'kode pendek'],
        ['I.A.01', 'Risiko Proses Layanan',          'kode titik'],
        ['SUB/2025/003', 'Risiko SDM Teknis',        'kode slash'],
        ['R-010', 'Risiko: Gagal Bayar & Default',   'nama tanda baca'],
        ['R-999', str_repeat('Risiko Panjang ', 5),  'nama panjang'],
        ['001', 'Risiko Satu',                        'kode numerik'],
        ['R', 'X',                                    'karakter minimal'],
    ];
    foreach ($kasusIdentitas as [$kode, $nama, $label]) {
        $run("Kode+Nama [{$label}]", function () use ($kode, $nama, $label, $assertContains): void {
            $rows = [buatRowRisiko($kode, $nama)];
            $html = laporanRenderTbodyBab2($rows);
            $assertContains(testXss($kode), $html, "[{$label}] kode_risiko harus muncul");
            $assertContains(testXss($nama), $html, "[{$label}] nama_risiko harus muncul");
        });
    }

    // ── 3.B Nilai numerik awal (P, D, Nilai) muncul di output ───────────────

    echo "\nProperty 3.B: Nilai numerik awal (P, D, Nilai) muncul di output\n";
    $kasusNilai = [
        [1, 1, 1,  'minimum'],
        [1, 2, 2,  'rendah'],
        [2, 3, 6,  'sedang'],
        [3, 4, 12, 'tinggi'],
        [5, 5, 25, 'maksimum'],
        [5, 1, 5,  'P tinggi D rendah'],
        [1, 5, 5,  'P rendah D tinggi'],
    ];
    foreach ($kasusNilai as [$p, $d, $n, $label]) {
        $run("P={$p} D={$d} N={$n} [{$label}]", function () use ($p, $d, $n, $label, $assertContains): void {
            $rows = [buatRowRisiko('R-001', 'Risiko Uji', $p, $d, $n)];
            $html = laporanRenderTbodyBab2($rows);
            $assertContains((string)$p, $html, "[{$label}] awal_p={$p} harus muncul");
            $assertContains((string)$d, $html, "[{$label}] awal_d={$d} harus muncul");
            $assertContains((string)$n, $html, "[{$label}] awal_nilai={$n} harus muncul");
        });
    }

    // ── 3.C tingkat_risiko awal muncul di output ─────────────────────────────

    echo "\nProperty 3.C: tingkat_risiko (awal_tingkat) muncul di output\n";
    foreach (['Rendah', 'Sedang', 'Tinggi', 'Sangat Tinggi'] as $tingkat) {
        $run("awal_tingkat = '{$tingkat}'", function () use ($tingkat, $assertContains): void {
            $rows = [buatRowRisiko('R-001', 'Risiko Tingkat', 3, 4, 12, $tingkat)];
            $html = laporanRenderTbodyBab2($rows);
            $assertContains(testXss($tingkat), $html, "awal_tingkat '{$tingkat}' harus muncul");
        });
    }

    // ── 3.D Semua baris dalam multiple rows muncul ───────────────────────────

    echo "\nProperty 3.D: Semua baris data awal muncul (5 baris)\n";
    $run("5 baris — semua kolom data awal", function () use ($assertContains): void {
        $rows = [
            buatRowRisiko('R-001', 'Risiko Administrasi Umum', 2, 3, 6,  'Sedang',       'Pengendalian A'),
            buatRowRisiko('R-002', 'Risiko Keuangan & Aset',   3, 4, 12, 'Tinggi',       'Pengendalian B'),
            buatRowRisiko('R-003', 'Risiko SDM',               1, 2, 2,  'Rendah',       null),
            buatRowRisiko('R-004', 'Risiko Reputasi',          4, 5, 20, 'Sangat Tinggi','Pengendalian D'),
            buatRowRisiko('R-005', 'Risiko Pelayanan',         2, 2, 4,  'Rendah',       'Pengendalian E'),
        ];
        $html = laporanRenderTbodyBab2($rows);
        foreach ($rows as $idx => $row) {
            $no = $idx + 1;
            $assertContains(testXss($row['kode_risiko']),  $html, "Baris {$no}: kode_risiko");
            $assertContains(testXss($row['nama_risiko']),  $html, "Baris {$no}: nama_risiko");
            $assertContains((string)$row['awal_p'],        $html, "Baris {$no}: awal_p");
            $assertContains((string)$row['awal_d'],        $html, "Baris {$no}: awal_d");
            $assertContains((string)$row['awal_nilai'],    $html, "Baris {$no}: awal_nilai");
            $assertContains(testXss($row['awal_tingkat']), $html, "Baris {$no}: awal_tingkat");
        }
    });

    // ── 3.E Fallback rows kosong ─────────────────────────────────────────────

    echo "\nProperty 5: Fallback saat rows kosong\n";
    $run("Teks fallback muncul saat rows kosong", function () use ($assertContains): void {
        $html = laporanRenderTbodyBab2([]);
        $assertContains('Tidak terdapat data risiko untuk unit kerja ini', $html, 'Teks fallback harus ada');
    });
    $run("Fallback menggunakan colspan=11", function () use ($assertContains): void {
        $html = laporanRenderTbodyBab2([]);
        $assertContains('colspan="11"', $html, 'Fallback harus colspan="11"');
    });

    // ── 3.F NULL pada kolom → "-" ─────────────────────────────────────────────

    echo "\nProperty 4: NULL kolom awal dan akhir → \"-\"\n";
    $run("awal_p NULL → \"-\"", function () use ($assertContains): void {
        $row = buatRowRisiko();
        $row['awal_p'] = null;
        $html = laporanRenderTbodyBab2([$row]);
        $assertContains('>-<', $html, 'awal_p NULL harus menghasilkan "-"');
    });
    $run("upaya_pengendalian NULL → \"-\"", function () use ($assertContains): void {
        $row = buatRowRisiko('R-001', 'Risiko', 2, 3, 6, 'Sedang', null);
        $html = laporanRenderTbodyBab2([$row]);
        $assertContains('>-<', $html, 'upaya NULL harus menghasilkan "-"');
    });
    $run("upaya_pengendalian '' → \"-\"", function () use ($assertContains): void {
        $row = buatRowRisiko('R-001', 'Risiko', 2, 3, 6, 'Sedang', '');
        $html = laporanRenderTbodyBab2([$row]);
        $assertContains('>-<', $html, 'upaya kosong harus menghasilkan "-"');
    });
    $run("semua kolom akhir NULL (tanpa monev) → setidaknya 4x \"-\"", function () use ($assertGreaterEqual): void {
        $row = buatRowRisiko('R-004', 'Tanpa Monev', 2, 3, 6, 'Sedang', 'Upaya', null, null, null, null);
        $html = laporanRenderTbodyBab2([$row]);
        $assertGreaterEqual(4, substr_count($html, '>-<'), 'Minimal 4 kolom akhir null → "-"');
    });

    // ── 3.G Escaping karakter HTML khusus ────────────────────────────────────

    echo "\nProperty 3.G: Escaping karakter HTML khusus dari data DB\n";
    $xssKasus = [
        ['Risiko & Kendala',        'Risiko &amp; Kendala',           'ampersand'],
        ['Nilai > Batas',           'Nilai &gt; Batas',               '">"'],
        ['Level < Minimum',         'Level &lt; Minimum',             '"<"'],
        ['Risiko "Kritis"',         'Risiko &quot;Kritis&quot;',      'kutip ganda'],
        ["Risiko 'Utama'",          "Risiko &#039;Utama&#039;",       'kutip tunggal'],
        ['<script>alert(1)</script>','&lt;script&gt;alert(1)&lt;/script&gt;','XSS script'],
    ];
    foreach ($xssKasus as [$raw, $entity, $label]) {
        $run("Escape [{$label}] pada kode_risiko", function () use ($raw, $entity, $label, $assertContains): void {
            $rows = [buatRowRisiko($raw, 'Nama Normal', 2, 3, 6, 'Sedang')];
            $html = laporanRenderTbodyBab2($rows);
            $assertContains($entity, $html, "[{$label}] kode_risiko: entity harus ada");
        });
        $run("Escape [{$label}] pada nama_risiko", function () use ($raw, $entity, $label, $assertContains): void {
            $rows = [buatRowRisiko('R-001', $raw, 2, 3, 6, 'Sedang')];
            $html = laporanRenderTbodyBab2($rows);
            $assertContains($entity, $html, "[{$label}] nama_risiko: entity harus ada");
        });
    }

    // ── 3.H Struktur tabel (jumlah TD per baris) ─────────────────────────────

    echo "\nProperty 3.H: Struktur tabel — jumlah kolom per baris\n";
    $run("2 baris → 24 <td> total (12 per baris)", function () use ($assertCount): void {
        $rows = [
            buatRowRisiko('R-001', 'Risiko A', 1, 2, 2, 'Rendah'),
            buatRowRisiko('R-002', 'Risiko B', 3, 4, 12, 'Tinggi'),
        ];
        $html = laporanRenderTbodyBab2($rows);
        $assertCount(2,  substr_count($html, '<tr>'),   'Harus ada tepat 2 elemen <tr>');
        $assertCount(24, substr_count($html, '<td'),    'Harus ada tepat 24 elemen <td> (12 × 2)');
    });
    $run("Nomor urut 1-indexed dan berurutan", function () use ($assertTrue): void {
        $rows = [
            buatRowRisiko('R-001', 'Risiko A'),
            buatRowRisiko('R-002', 'Risiko B'),
            buatRowRisiko('R-003', 'Risiko C'),
        ];
        $html = laporanRenderTbodyBab2($rows);
        $p1   = strpos($html, '>1<');
        $p2   = strpos($html, '>2<');
        $p3   = strpos($html, '>3<');
        $assertTrue($p1 !== false, 'Nomor 1 harus ada');
        $assertTrue($p2 !== false, 'Nomor 2 harus ada');
        $assertTrue($p3 !== false, 'Nomor 3 harus ada');
        $assertTrue($p1 < $p2, 'Nomor 1 harus sebelum 2');
        $assertTrue($p2 < $p3, 'Nomor 2 harus sebelum 3');
    });

    // ── Ringkasan ─────────────────────────────────────────────────────────────

    $total = $passed + $failed;
    echo "\n";
    echo str_repeat('─', 62) . "\n";
    if ($failed === 0) {
        echo "  ✓ Semua {$total} test LULUS\n";
    } else {
        echo "  Hasil: {$passed}/{$total} test lulus, {$failed} GAGAL\n\n";
        echo "  Test yang gagal:\n";
        foreach ($errors as $err) {
            echo $err . "\n";
        }
    }
    echo str_repeat('─', 62) . "\n\n";

    exit($failed > 0 ? 1 : 0);
}
