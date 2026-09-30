<?php
/**
 * Test: Monev Status Pemantauan Risiko (Ambang Batas = Rendah)
 * Jalankan: vendor/bin/phpunit tests/MonevStatusTest.php
 */

use PHPUnit\Framework\TestCase;

class MonevStatusTest extends TestCase
{
    /**
     * Helper fungsi penentu status monev berdasarkan ambang batas 'Rendah'
     */
    private function hitungStatusMonev(?array $c): array
    {
        $statusLabel = '–';
        $statusDesc = '';
        if ($c && $c['pantau_nilai'] !== null) {
            $pTingkat = trim((string)($c['pantau_tingkat'] ?? ''));
            if ($pTingkat === 'Sangat Rendah') {
                $statusLabel = 'Closed (Selesai)';
                $statusDesc = 'Level risiko turun di bawah ambang batas';
            } else {
                $statusLabel = 'Open';
                $statusDesc = 'Level risiko belum di bawah ambang batas';
            }
        }
        return ['label' => $statusLabel, 'desc' => $statusDesc];
    }

    public function testStatusClosedSaatSangatRendah(): void
    {
        $monev = ['pantau_nilai' => 2.0, 'pantau_tingkat' => 'Sangat Rendah'];
        $res = $this->hitungStatusMonev($monev);
        $this->assertSame('Closed (Selesai)', $res['label']);
        $this->assertSame('Level risiko turun di bawah ambang batas', $res['desc']);
    }

    public function testStatusOpenSaatRendahAtauDiatasnya(): void
    {
        foreach (['Rendah', 'Sedang', 'Tinggi', 'Sangat Tinggi'] as $tingkat) {
            $monev = ['pantau_nilai' => 10.0, 'pantau_tingkat' => $tingkat];
            $res = $this->hitungStatusMonev($monev);
            $this->assertSame('Open', $res['label']);
            $this->assertSame('Level risiko belum di bawah ambang batas', $res['desc']);
        }
    }

    public function testStatusStripSaatBelumDipantau(): void
    {
        $res = $this->hitungStatusMonev(null);
        $this->assertSame('–', $res['label']);
        $this->assertSame('', $res['desc']);

        $resNull = $this->hitungStatusMonev(['pantau_nilai' => null, 'pantau_tingkat' => null]);
        $this->assertSame('–', $resNull['label']);
        $this->assertSame('', $resNull['desc']);
    }
}
