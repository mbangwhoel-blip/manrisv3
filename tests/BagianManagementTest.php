<?php
use PHPUnit\Framework\TestCase;

class BagianManagementTest extends TestCase
{
    public function testBagianDataJsonEncoding(): void
    {
        $bagianList = [
            [
                'id' => 1,
                'nama' => '3. Tim Kerja Mutu, Penguatan SDM dan Kemitraan',
                'deskripsi' => 'Deskripsi "khusus" & tes',
                'aktif' => 1
            ],
            [
                'id' => 2,
                'nama' => "Sub Bagian 'Umum'",
                'deskripsi' => null,
                'aktif' => 0
            ]
        ];

        $encoded = json_encode(array_column($bagianList, null, 'id'));
        $this->assertIsString($encoded);
        $decoded = json_decode($encoded, true);

        $this->assertArrayHasKey(1, $decoded);
        $this->assertSame('3. Tim Kerja Mutu, Penguatan SDM dan Kemitraan', $decoded[1]['nama']);
        $this->assertSame('Deskripsi "khusus" & tes', $decoded[1]['deskripsi']);
        $this->assertSame(1, $decoded[1]['aktif']);

        $this->assertArrayHasKey(2, $decoded);
        $this->assertSame("Sub Bagian 'Umum'", $decoded[2]['nama']);
        $this->assertSame(0, $decoded[2]['aktif']);
    }

    public function testBagianButtonCallDoesNotContainUnescapedQuotes(): void
    {
        // Simulating the button render
        $b = [
            'id' => 1,
            'nama' => '3. Tim Kerja Mutu, Penguatan SDM dan Kemitraan',
            'deskripsi' => 'Tes',
            'aktif' => 1
        ];

        $btnEdit = '<button type="button" class="act-btn act-btn-edit" onclick="editBagian(' . (int)$b['id'] . ')" title="Edit"><i class="fas fa-edit"></i></button>';
        $btnHapus = '<button type="button" class="act-btn act-btn-delete" onclick="hapusBagian(' . (int)$b['id'] . ')" title="Hapus"><i class="fas fa-trash"></i></button>';

        $this->assertStringContainsString('onclick="editBagian(1)"', $btnEdit);
        $this->assertStringContainsString('onclick="hapusBagian(1)"', $btnHapus);
        $this->assertStringNotContainsString('jsEncode', $btnEdit);
    }
}
