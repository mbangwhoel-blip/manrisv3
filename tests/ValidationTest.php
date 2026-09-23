<?php
/**
 * Test: Validasi server-side terpusat (Rekomendasi #2)
 * Jalankan: php vendor/bin/phpunit tests/ValidationTest.php
 */

use PHPUnit\Framework\TestCase;

class ValidationTest extends TestCase
{
    // ── validateRiskScales ────────────────────────────────────

    public function testRiskScaleValidValues(): void
    {
        foreach ([1, 2, 3, 4, 5] as $v) {
            $result = validateRiskScales(['probabilitas' => $v]);
            $this->assertTrue($result['valid'], "Nilai $v seharusnya valid");
        }
    }

    public function testRiskScaleInvalidValues(): void
    {
        foreach ([0, 6, -1, 10] as $v) {
            $result = validateRiskScales(['probabilitas' => $v]);
            $this->assertFalse($result['valid'], "Nilai $v seharusnya tidak valid");
            $this->assertNotEmpty($result['errors']);
        }
    }

    public function testRiskScaleBatchValidation(): void
    {
        $result = validateRiskScales(['probabilitas' => 3, 'dampak' => 6]);
        $this->assertFalse($result['valid']);
        $this->assertCount(1, $result['errors']); // hanya dampak yang salah
    }

    // ── validateDate ─────────────────────────────────────────

    public function testValidDate(): void
    {
        $this->assertTrue(validateDate('2024-01-15'));
        $this->assertTrue(validateDate('2025-12-31'));
    }

    public function testInvalidDate(): void
    {
        $this->assertFalse(validateDate('2024-13-01')); // bulan tidak ada
        $this->assertFalse(validateDate('31-01-2024')); // format salah
        $this->assertFalse(validateDate(''));
        $this->assertFalse(validateDate(null));
        $this->assertFalse(validateDate('not-a-date'));
    }

    public function testOptionalDate(): void
    {
        $this->assertTrue(validateDateOptional(''));
        $this->assertTrue(validateDateOptional(null));
        $this->assertTrue(validateDateOptional('2024-06-15'));
        $this->assertFalse(validateDateOptional('invalid'));
    }

    // ── validateEnum ─────────────────────────────────────────

    public function testValidStatusRisiko(): void
    {
        foreach (STATUS_RISIKO as $s) {
            $this->assertTrue(validateEnum($s, STATUS_RISIKO), "Status '$s' seharusnya valid");
        }
    }

    public function testInvalidStatusRisiko(): void
    {
        $this->assertFalse(validateEnum('Tidak Ada', STATUS_RISIKO));
        $this->assertFalse(validateEnum('', STATUS_RISIKO));
        $this->assertFalse(validateEnum('teridentifikasi', STATUS_RISIKO)); // case-sensitive
    }

    public function testValidSumberRisiko(): void
    {
        $this->assertTrue(validateEnum('Internal', SUMBER_RISIKO));
        $this->assertTrue(validateEnum('Eksternal', SUMBER_RISIKO));
        $this->assertFalse(validateEnum('Campuran', SUMBER_RISIKO));
    }

    // ── validateBobot ─────────────────────────────────────────

    public function testValidBobot(): void
    {
        $this->assertTrue(validateBobot(1.0));
        $this->assertTrue(validateBobot(1.47));
        $this->assertTrue(validateBobot(4.0));
    }

    public function testInvalidBobot(): void
    {
        $this->assertFalse(validateBobot(0.0));
        $this->assertFalse(validateBobot(-1.0));
        $this->assertFalse(validateBobot(10.1));
    }

    // ── sanitizeStr ───────────────────────────────────────────

    public function testSanitizeStr(): void
    {
        $this->assertSame('hello', sanitizeStr('  hello  '));
        $this->assertSame('test', sanitizeStr("test\0")); // null byte dihapus
        $this->assertSame('ab', sanitizeStr('abcde', 2)); // truncate
        $this->assertSame('', sanitizeStr(null));
    }

    // ── validRiskScale (existing) ─────────────────────────────

    public function testExistingValidRiskScale(): void
    {
        $this->assertTrue(validRiskScale(1));
        $this->assertTrue(validRiskScale(5));
        $this->assertFalse(validRiskScale(0));
        $this->assertFalse(validRiskScale(6));
    }

    // ── getLevelRisiko ────────────────────────────────────────

    public function testGetLevelRisiko(): void
    {
        $this->assertSame('Sangat Rendah', getLevelRisiko(1));
        $this->assertSame('Rendah',        getLevelRisiko(5));
        $this->assertSame('Sedang',        getLevelRisiko(10));
        $this->assertSame('Tinggi',        getLevelRisiko(15));
        $this->assertSame('Sangat Tinggi', getLevelRisiko(20));
        $this->assertSame('Sangat Tinggi', getLevelRisiko(25));
    }

    // ── getBobot — matriks tidak boleh keluar dari range ──────

    public function testGetBobotAllCombinations(): void
    {
        for ($p = 1; $p <= 5; $p++) {
            for ($d = 1; $d <= 5; $d++) {
                $b = getBobot($p, $d);
                $this->assertGreaterThan(0, $b, "Bobot P=$p D=$d harus > 0");
                $this->assertLessThanOrEqual(10.0, $b, "Bobot P=$p D=$d harus <= 10");
                $this->assertTrue(validateBobot($b), "Bobot P=$p D=$d harus lolos validateBobot()");
            }
        }
    }

    // ── Pengurutan Kode Risiko (Natural Sort: A.1, A.2, ..., A.10) ───

    public function testRisikoKodeNaturalSorting(): void
    {
        $db = getDB();
        $testCodes = ['A.10', 'A.1', 'A.12', 'A.2', 'A.11', 'A.3'];
        $union = [];
        foreach ($testCodes as $c) {
            $union[] = "SELECT '$c' AS kode";
        }
        $unionSql = implode(' UNION ALL ', $union);
        $sql = "SELECT kode FROM ($unionSql) t 
                ORDER BY UPPER(SUBSTRING_INDEX(SUBSTRING_INDEX(kode, '-', 1), '.', 1)) ASC, 
                         CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(kode, '-', '.'), '.', 2), '.', -1) AS UNSIGNED) ASC, 
                         kode ASC";
        $res = $db->query($sql);
        $ordered = [];
        while ($r = $res->fetch_assoc()) {
            $ordered[] = $r['kode'];
        }

        $this->assertSame(['A.1', 'A.2', 'A.3', 'A.10', 'A.11', 'A.12'], $ordered);
    }
}
