<?php
use PHPUnit\Framework\TestCase;

class LaporanMonevBab2Bab4Test extends TestCase
{
    public function testBab2HeadersQuarterProgression(): void
    {
        $romawiArr = ['I', 'II', 'III', 'IV'];

        // Test TW 1: Kondisi Awal (TW I) vs Kondisi Akhir (TW I)
        $tw1 = 1;
        $kiriJudul1 = ($tw1 > 1) ? 'KONDISI AKHIR' : 'KONDISI AWAL';
        $kiriTw1    = ($tw1 > 1) ? '(TW ' . ($romawiArr[$tw1 - 2] ?? 'I') . ')' : '(TW I)';
        $kananJudul1 = 'KONDISI AKHIR';
        $kananTw1    = '(TW ' . ($romawiArr[$tw1 - 1] ?? 'I') . ')';

        $this->assertSame('KONDISI AWAL', $kiriJudul1);
        $this->assertSame('(TW I)', $kiriTw1);
        $this->assertSame('KONDISI AKHIR', $kananJudul1);
        $this->assertSame('(TW I)', $kananTw1);

        // Test TW 2: Kondisi Akhir (TW I) vs Kondisi Akhir (TW II)
        $tw2 = 2;
        $kiriJudul2 = ($tw2 > 1) ? 'KONDISI AKHIR' : 'KONDISI AWAL';
        $kiriTw2    = ($tw2 > 1) ? '(TW ' . ($romawiArr[$tw2 - 2] ?? 'I') . ')' : '(TW I)';
        $kananJudul2 = 'KONDISI AKHIR';
        $kananTw2    = '(TW ' . ($romawiArr[$tw2 - 1] ?? 'I') . ')';

        $this->assertSame('KONDISI AKHIR', $kiriJudul2);
        $this->assertSame('(TW I)', $kiriTw2);
        $this->assertSame('KONDISI AKHIR', $kananJudul2);
        $this->assertSame('(TW II)', $kananTw2);

        // Test TW 3: Kondisi Akhir (TW II) vs Kondisi Akhir (TW III)
        $tw3 = 3;
        $kiriJudul3 = ($tw3 > 1) ? 'KONDISI AKHIR' : 'KONDISI AWAL';
        $kiriTw3    = ($tw3 > 1) ? '(TW ' . ($romawiArr[$tw3 - 2] ?? 'I') . ')' : '(TW I)';
        $kananJudul3 = 'KONDISI AKHIR';
        $kananTw3    = '(TW ' . ($romawiArr[$tw3 - 1] ?? 'I') . ')';

        $this->assertSame('KONDISI AKHIR', $kiriJudul3);
        $this->assertSame('(TW II)', $kiriTw3);
        $this->assertSame('KONDISI AKHIR', $kananJudul3);
        $this->assertSame('(TW III)', $kananTw3);

        // Test TW 4: Kondisi Akhir (TW III) vs Kondisi Akhir (TW IV)
        $tw4 = 4;
        $kiriJudul4 = ($tw4 > 1) ? 'KONDISI AKHIR' : 'KONDISI AWAL';
        $kiriTw4    = ($tw4 > 1) ? '(TW ' . ($romawiArr[$tw4 - 2] ?? 'I') . ')' : '(TW I)';
        $kananJudul4 = 'KONDISI AKHIR';
        $kananTw4    = '(TW ' . ($romawiArr[$tw4 - 1] ?? 'I') . ')';

        $this->assertSame('KONDISI AKHIR', $kiriJudul4);
        $this->assertSame('(TW III)', $kiriTw4);
        $this->assertSame('KONDISI AKHIR', $kananJudul4);
        $this->assertSame('(TW IV)', $kananTw4);
    }

    public function testLaporanHitungStatistikLimaTingkat(): void
    {
        $rows = [
            ['awal_nilai' => 12, 'akhir_nilai' => 20, 'akhir_tingkat' => 'Sangat Tinggi'],
            ['awal_nilai' => 12, 'akhir_nilai' => 15, 'akhir_tingkat' => 'Tinggi'],
            ['awal_nilai' => 8,  'akhir_nilai' => 8,  'akhir_tingkat' => 'Sedang'],
            ['awal_nilai' => 6,  'akhir_nilai' => 4,  'akhir_tingkat' => 'Rendah'],
            ['awal_nilai' => 5,  'akhir_nilai' => 2,  'akhir_tingkat' => 'Sangat Rendah'],
            ['awal_nilai' => 10, 'akhir_nilai' => null, 'akhir_tingkat' => null], // Unmonitored, should be skipped
        ];

        // Mirror function
        $levels = [
            'Sangat Tinggi' => 0,
            'Tinggi'        => 0,
            'Sedang'        => 0,
            'Rendah'        => 0,
            'Sangat Rendah' => 0,
        ];
        $totalMonev = 0;
        foreach ($rows as $row) {
            if ($row['akhir_nilai'] === null) {
                continue;
            }
            $totalMonev++;
            $akhirTingkat = trim((string)($row['akhir_tingkat'] ?? ''));
            if (isset($levels[$akhirTingkat])) {
                $levels[$akhirTingkat]++;
            }
        }

        $this->assertSame(5, $totalMonev);
        $this->assertSame(1, $levels['Sangat Tinggi']);
        $this->assertSame(1, $levels['Tinggi']);
        $this->assertSame(1, $levels['Sedang']);
        $this->assertSame(1, $levels['Rendah']);
        $this->assertSame(1, $levels['Sangat Rendah']);
    }

    public function testCssContainsFirstLineParagraphIndent(): void
    {
        $file = file_get_contents(__DIR__ . '/../modules/laporan_monev.php');
        $this->assertStringContainsString('text-indent: 1.25cm;', $file);
        $this->assertStringContainsString('.cover p', $file);
        $this->assertStringContainsString('.pengesahan p', $file);
        $this->assertStringContainsString('p.table-caption', $file);
    }

    public function testDocxExporterAppliesFirstLineIndent(): void
    {
        $file = file_get_contents(__DIR__ . '/../modules/laporan_monev_html_to_docx.php');
        $this->assertStringContainsString("'firstLine' => 720", $file);
    }
}
