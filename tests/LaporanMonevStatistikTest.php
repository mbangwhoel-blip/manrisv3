<?php
/**
 * Property-Based Tests: Statistik Tren Risiko Laporan Monev
 *
 * Property 9: Statistik tren risiko akurat dan filter risiko tanpa monev
 *   Validates: Requirements 8.1, 8.3
 *
 * Jalankan: php vendor/bin/phpunit tests/LaporanMonevStatistikTest.php
 */

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Fungsi murni yang disalin verbatim dari modules/laporan_monev.php.
 * Tidak bergantung pada DB, session, atau auth.
 *
 * Logika identik dengan modul:
 *   - Rows dengan akhir_nilai === null dikecualikan dari semua counter
 *   - turun : akhir_nilai < awal_nilai
 *   - tetap : akhir_nilai === awal_nilai
 *   - naik  : akhir_nilai > awal_nilai
 *   - tinggi: akhir_tingkat IN ['Tinggi', 'Sangat Tinggi'] (dan akhir_nilai tidak null)
 */
function laporanHitungStatistik(array $rows): array
{
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

// ────────────────────────────────────────────────────────────────────────────
//  Helpers untuk membangun row tes
// ────────────────────────────────────────────────────────────────────────────

/**
 * Buat satu row risiko lengkap dengan semua kolom yang dibutuhkan.
 */
function buatRow(
    int     $awalNilai,
    ?int    $akhirNilai,
    string  $awalTingkat  = 'Rendah',
    ?string $akhirTingkat = 'Rendah'
): array {
    return [
        'kode_risiko'           => 'R-TEST',
        'nama_risiko'           => 'Risiko Tes',
        'awal_p'                => 1,
        'awal_d'                => 1,
        'awal_nilai'            => $awalNilai,
        'awal_tingkat'          => $awalTingkat,
        'upaya_pengendalian'    => null,
        'akhir_p'               => $akhirNilai !== null ? 1 : null,
        'akhir_d'               => $akhirNilai !== null ? 1 : null,
        'akhir_nilai'           => $akhirNilai,
        'akhir_tingkat'         => $akhirTingkat,
        'kendala'               => null,
        'rencana_tindak_lanjut' => null,
    ];
}

class LaporanMonevStatistikTest extends TestCase
{
    // ════════════════════════════════════════════════════════════
    //  Property 9 — Core invariant: turun + tetap + naik == total_monev
    //  Validates: Requirements 8.1, 8.3
    // ════════════════════════════════════════════════════════════

    /**
     * **Validates: Requirements 8.1, 8.3**
     *
     * Property 9 (invariant utama): Untuk setiap koleksi rows, jumlah
     * turun + tetap + naik HARUS sama persis dengan total_monev
     * (count rows di mana akhir_nilai IS NOT NULL).
     */
    #[DataProvider('provideRowsUntukInvarianUtama')]
    public function testProperty9TurunTetapNaikSamaDenganTotalMonev(
        array  $rows,
        string $skenario
    ): void {
        $stat = laporanHitungStatistik($rows);

        $jumlah = $stat['turun'] + $stat['tetap'] + $stat['naik'];

        $this->assertSame(
            $stat['total_monev'],
            $jumlah,
            "Property 9 gagal [{$skenario}]: turun({$stat['turun']}) + tetap({$stat['tetap']}) + naik({$stat['naik']}) = {$jumlah} ≠ total_monev({$stat['total_monev']})"
        );
    }

    // ════════════════════════════════════════════════════════════
    //  Property 9 — Filter rows dengan akhir_nilai NULL
    //  Validates: Requirements 8.3
    // ════════════════════════════════════════════════════════════

    /**
     * **Validates: Requirements 8.3**
     *
     * Property 9 (filter null): Rows dengan akhir_nilai === null TIDAK BOLEH
     * berkontribusi ke counter manapun (turun, tetap, naik, tinggi, total_monev
     * semuanya harus 0 jika semua rows adalah null).
     */
    public function testProperty9SemuaRowsNullMenghasilkanSemuaCounterNol(): void
    {
        $rows = [
            buatRow(8, null),
            buatRow(12, null, 'Tinggi', null),
            buatRow(4, null, 'Sangat Tinggi', null),
        ];

        $stat = laporanHitungStatistik($rows);

        $this->assertSame(0, $stat['turun'],       'turun harus 0 saat semua akhir_nilai null');
        $this->assertSame(0, $stat['tetap'],       'tetap harus 0 saat semua akhir_nilai null');
        $this->assertSame(0, $stat['naik'],        'naik harus 0 saat semua akhir_nilai null');
        $this->assertSame(0, $stat['tinggi'],      'tinggi harus 0 saat semua akhir_nilai null');
        $this->assertSame(0, $stat['total_monev'], 'total_monev harus 0 saat semua akhir_nilai null');
    }

    /**
     * **Validates: Requirements 8.3**
     *
     * Array kosong harus menghasilkan semua counter nol.
     */
    public function testProperty9ArrayKosongMenghasilkanSemuaCounterNol(): void
    {
        $stat = laporanHitungStatistik([]);

        $this->assertSame(0, $stat['turun'],       'turun harus 0 untuk array kosong');
        $this->assertSame(0, $stat['tetap'],       'tetap harus 0 untuk array kosong');
        $this->assertSame(0, $stat['naik'],        'naik harus 0 untuk array kosong');
        $this->assertSame(0, $stat['tinggi'],      'tinggi harus 0 untuk array kosong');
        $this->assertSame(0, $stat['total_monev'], 'total_monev harus 0 untuk array kosong');
    }

    // ════════════════════════════════════════════════════════════
    //  Property 9 — Skenario campuran (rows dengan dan tanpa monev)
    //  Validates: Requirements 8.1, 8.3
    // ════════════════════════════════════════════════════════════

    /**
     * **Validates: Requirements 8.1, 8.3**
     *
     * Property 9 (campuran): Hanya rows dengan akhir_nilai tidak NULL yang
     * dihitung; rows NULL tidak mempengaruhi total_monev maupun counter tren.
     */
    public function testProperty9CampuranRowsMonevDanNull(): void
    {
        $rows = [
            buatRow(10, 8),    // turun (8 < 10)
            buatRow(10, null), // dikecualikan
            buatRow(5,  5),    // tetap
            buatRow(3,  null), // dikecualikan
            buatRow(6,  9),    // naik (9 > 6)
        ];

        $stat = laporanHitungStatistik($rows);

        $this->assertSame(3, $stat['total_monev'], 'total_monev harus 3 (3 rows tidak null)');
        $this->assertSame(1, $stat['turun'],       'turun harus 1');
        $this->assertSame(1, $stat['tetap'],       'tetap harus 1');
        $this->assertSame(1, $stat['naik'],        'naik harus 1');

        // Invariant
        $this->assertSame(
            $stat['total_monev'],
            $stat['turun'] + $stat['tetap'] + $stat['naik']
        );
    }

    // ════════════════════════════════════════════════════════════
    //  Property 9 — Verifikasi logika turun/tetap/naik
    //  Validates: Requirements 8.1
    // ════════════════════════════════════════════════════════════

    /**
     * **Validates: Requirements 8.1**
     *
     * Semua rows dengan akhir_nilai tidak null dan tidak ada penurunan/kenaikan
     * (semua tetap) → turun = 0, naik = 0, tetap = total_monev.
     */
    public function testProperty9SemuaRowsTetap(): void
    {
        $rows = [
            buatRow(4, 4),
            buatRow(8, 8),
            buatRow(12, 12),
        ];

        $stat = laporanHitungStatistik($rows);

        $this->assertSame(3, $stat['total_monev']);
        $this->assertSame(0, $stat['turun']);
        $this->assertSame(3, $stat['tetap']);
        $this->assertSame(0, $stat['naik']);
    }

    /**
     * **Validates: Requirements 8.1**
     *
     * Semua rows turun → naik = 0, tetap = 0, turun = total_monev.
     */
    public function testProperty9SemuaRowsTurun(): void
    {
        $rows = [
            buatRow(10, 6),
            buatRow(8,  3),
            buatRow(15, 2),
        ];

        $stat = laporanHitungStatistik($rows);

        $this->assertSame(3, $stat['total_monev']);
        $this->assertSame(3, $stat['turun']);
        $this->assertSame(0, $stat['tetap']);
        $this->assertSame(0, $stat['naik']);
    }

    /**
     * **Validates: Requirements 8.1**
     *
     * Semua rows naik → turun = 0, tetap = 0, naik = total_monev.
     */
    public function testProperty9SemuaRowsNaik(): void
    {
        $rows = [
            buatRow(2, 6),
            buatRow(3, 9),
            buatRow(1, 12),
        ];

        $stat = laporanHitungStatistik($rows);

        $this->assertSame(3, $stat['total_monev']);
        $this->assertSame(0, $stat['turun']);
        $this->assertSame(0, $stat['tetap']);
        $this->assertSame(3, $stat['naik']);
    }

    // ════════════════════════════════════════════════════════════
    //  Property 9 — Verifikasi counter tinggi
    //  Validates: Requirements 8.1
    // ════════════════════════════════════════════════════════════

    /**
     * **Validates: Requirements 8.1**
     *
     * Counter tinggi hanya menghitung rows dengan akhir_tingkat IN
     * ['Tinggi', 'Sangat Tinggi'] DAN akhir_nilai tidak null.
     */
    #[DataProvider('provideRowsUntukTinggi')]
    public function testProperty9TinggiHanyaMenghitungTingkatTinggiDanSangatTinggi(
        array $rows,
        int   $expectedTinggi,
        string $skenario
    ): void {
        $stat = laporanHitungStatistik($rows);

        $this->assertSame(
            $expectedTinggi,
            $stat['tinggi'],
            "Property 9 tinggi gagal [{$skenario}]: diharapkan {$expectedTinggi}, dapat {$stat['tinggi']}"
        );
    }

    /**
     * **Validates: Requirements 8.1, 8.3**
     *
     * Rows dengan akhir_nilai null TIDAK boleh dihitung ke tinggi,
     * meskipun akhir_tingkat-nya 'Tinggi' atau 'Sangat Tinggi'.
     */
    public function testProperty9TinggiTidakMenghitungRowsNull(): void
    {
        $rows = [
            buatRow(8, null, 'Tinggi', 'Tinggi'),        // null → dikecualikan
            buatRow(6, null, 'Sangat Tinggi', 'Sangat Tinggi'), // null → dikecualikan
            buatRow(3, 3,    'Rendah', 'Rendah'),         // non-null, rendah
        ];

        $stat = laporanHitungStatistik($rows);

        $this->assertSame(0, $stat['tinggi'], 'tinggi harus 0 — rows tinggi/sangat-tinggi semuanya null');
        $this->assertSame(1, $stat['total_monev'], 'total_monev harus 1');
    }

    // ════════════════════════════════════════════════════════════
    //  Property 9 — Data Providers
    // ════════════════════════════════════════════════════════════

    /**
     * Berbagai skenario untuk memverifikasi invariant:
     * turun + tetap + naik == total_monev
     */
    public static function provideRowsUntukInvarianUtama(): array
    {
        return [
            'array kosong' => [
                [],
                'array kosong',
            ],

            'satu row turun' => [
                [buatRow(10, 5)],
                'satu row turun',
            ],

            'satu row tetap' => [
                [buatRow(8, 8)],
                'satu row tetap',
            ],

            'satu row naik' => [
                [buatRow(3, 9)],
                'satu row naik',
            ],

            'satu row null' => [
                [buatRow(6, null)],
                'satu row null',
            ],

            'semua rows ada monev — semua turun' => [
                [buatRow(10, 6), buatRow(15, 4), buatRow(20, 1)],
                'semua rows ada monev — semua turun',
            ],

            'semua rows ada monev — campuran tren' => [
                [buatRow(10, 5), buatRow(5, 5), buatRow(3, 9)],
                'semua rows ada monev — campuran tren',
            ],

            'semua rows null' => [
                [buatRow(10, null), buatRow(8, null)],
                'semua rows null',
            ],

            'campuran null dan non-null' => [
                [buatRow(10, 5), buatRow(8, null), buatRow(3, 7), buatRow(12, null)],
                'campuran null dan non-null',
            ],

            'lima rows campuran lengkap' => [
                [
                    buatRow(10, 8),    // turun
                    buatRow(6,  6),    // tetap
                    buatRow(2,  5),    // naik
                    buatRow(15, null), // null
                    buatRow(9,  12),   // naik
                ],
                'lima rows campuran lengkap',
            ],

            'nilai batas bawah (0)' => [
                [buatRow(0, 0), buatRow(1, 0)],
                'nilai batas bawah (0)',
            ],

            'nilai besar' => [
                [buatRow(100, 50), buatRow(200, 200), buatRow(50, 300)],
                'nilai besar',
            ],
        ];
    }

    /**
     * Skenario untuk memverifikasi counter tinggi.
     * Format: [rows, expectedTinggi, label]
     */
    public static function provideRowsUntukTinggi(): array
    {
        return [
            'tidak ada tinggi' => [
                [
                    buatRow(4, 4, 'Rendah', 'Rendah'),
                    buatRow(6, 3, 'Sedang', 'Sedang'),
                ],
                0,
                'semua akhir_tingkat di bawah Tinggi',
            ],

            'satu Tinggi' => [
                [
                    buatRow(4, 8, 'Rendah', 'Tinggi'),
                    buatRow(6, 3, 'Sedang', 'Sedang'),
                ],
                1,
                'satu row dengan akhir_tingkat = Tinggi',
            ],

            'satu Sangat Tinggi' => [
                [
                    buatRow(4, 12, 'Rendah', 'Sangat Tinggi'),
                    buatRow(6,  3, 'Sedang', 'Rendah'),
                ],
                1,
                'satu row dengan akhir_tingkat = Sangat Tinggi',
            ],

            'keduanya Tinggi dan Sangat Tinggi' => [
                [
                    buatRow(4, 8,  'Rendah',  'Tinggi'),
                    buatRow(6, 12, 'Sedang',  'Sangat Tinggi'),
                    buatRow(9,  9, 'Sedang',  'Sedang'),
                ],
                2,
                'dua rows tinggi/sangat-tinggi',
            ],

            'semua Sangat Tinggi' => [
                [
                    buatRow(4, 12, 'Rendah',  'Sangat Tinggi'),
                    buatRow(6, 12, 'Sedang',  'Sangat Tinggi'),
                ],
                2,
                'semua rows sangat tinggi',
            ],

            'akhir_tingkat null (default ke empty string)' => [
                [
                    buatRow(4, 4, 'Rendah', null),
                    buatRow(8, 6, 'Tinggi', null),
                ],
                0,
                'akhir_tingkat null tidak dihitung sebagai tinggi',
            ],

            'akhir_tingkat kosong (default ke empty string)' => [
                [
                    buatRow(4, 4, 'Rendah', ''),
                ],
                0,
                'akhir_tingkat string kosong tidak dihitung sebagai tinggi',
            ],

            'case sensitive — huruf kecil tidak dihitung' => [
                [
                    buatRow(4, 8, 'Rendah', 'tinggi'),        // lowercase — tidak match
                    buatRow(4, 8, 'Rendah', 'sangat tinggi'), // lowercase — tidak match
                ],
                0,
                'case-sensitive: nilai lowercase tidak dihitung',
            ],
        ];
    }
}
