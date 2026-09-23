<?php
/**
 * Test: Saran Mitigasi AI (Gemini) — parsing, orchestrasi dengan mock transport,
 * dan edge cases (tanpa API key, konteks kosong, respons error, respons blocked).
 *
 * Jalankan: php vendor/bin/phpunit tests/AiSaranTest.php
 *
 * Catatan: test ini TIDAK memanggil layanan Gemini nyata. Transport HTTP
 * di-mock via callable yang diinjeksi ke generateAiSaran(). Karena itu test
 * aman dijalankan tanpa koneksi internet maupun API key asli (kecuali untuk
 * memverifikasi pesan "key belum dikonfigurasi").
 */

use PHPUnit\Framework\TestCase;

class AiSaranTest extends TestCase
{
    /**
     * Helper: bangun respons JSON Gemini yang valid dari teks keluaran model.
     */
    private function geminiResponse(string $text): string
    {
        return json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => $text]]],
                'finishReason' => 'STOP',
            ]],
            'promptFeedback' => ['blockReason' => null],
        ], JSON_UNESCAPED_UNICODE);
    }

    /** Mock transport yang selalu mengembalikan respons tertentu. */
    private function mockTransport(string $resp, int $code = 200): callable
    {
        return static function (string $url, string $body) use ($resp, $code): array {
            return ['resp' => $resp, 'err' => null, 'code' => $code];
        };
    }

    /** Mock transport yang selalu gagal (simulasi network error). */
    private function failingTransport(): callable
    {
        return static function (string $url, string $body): array {
            return ['resp' => null, 'err' => 'Connection timed out', 'code' => 0];
        };
    }

    /**
     * Set konfigurasi Gemini di BAIDUA tempat env() membaca: $_ENV (snapshot
     * startup) DAN getenv() (override runtime via putenv). Hanya putenv() tidak
     * cukup bila OS sudah punya var GEMINI_* ter-set — $_ENV akan menimpa putenv.
     */
    private function setGemini(string $key, string $model = 'gemini-1.5-flash', string $max = '1000'): void
    {
        $_ENV['GEMINI_API_KEY']           = $key; putenv("GEMINI_API_KEY=$key");
        $_ENV['GEMINI_MODEL']             = $model; putenv("GEMINI_MODEL=$model");
        $_ENV['GEMINI_MAX_REQ_USER_HOUR'] = $max; putenv("GEMINI_MAX_REQ_USER_HOUR=$max");
    }

    /** Bersihkan GEMINI_API_KEY dari $_ENV dan getenv agar simulasi "tidak dikonfigurasi". */
    private function clearGeminiKey(): void
    {
        unset($_ENV['GEMINI_API_KEY'], $_ENV['GEMINI_MODEL'], $_ENV['GEMINI_MAX_REQ_USER_HOUR']);
        // putenv dengan '=' kosong men-set var ke string kosong sehingga
        // env() memakai nilai default (bukan nilai OS startup).
        putenv('GEMINI_API_KEY=');
        putenv('GEMINI_MODEL=');
        putenv('GEMINI_MAX_REQ_USER_HOUR=');
    }

    protected function setUp(): void
    {
        // Baseline bersih tiap test — test yang butuh key akan panggil setGemini().
        $this->clearGeminiKey();
    }

    protected function tearDown(): void
    {
        $this->clearGeminiKey();
    }

    // ── parseAiSaranText (fungsi murni) ──────────────────────────

    public function testParsePureJsonArray(): void
    {
        $saran = parseAiSaranText('["Audit sistem","Backup harian","Aktifkan MFA"]');
        $this->assertSame(['Audit sistem', 'Backup harian', 'Aktifkan MFA'], $saran);
    }

    public function testParseMarkdownFencedJson(): void
    {
        $text = "```json\n[\"Rekomendasi A\",\"Rekomendasi B\"]\n```";
        $saran = parseAiSaranText($text);
        $this->assertSame(['Rekomendasi A', 'Rekomendasi B'], $saran);
    }

    public function testParseJsonWithPreamble(): void
    {
        $text = 'Tentu, berikut sarannya: ["Langkah 1","Langkah 2"] Semoga membantu.';
        $saran = parseAiSaranText($text);
        $this->assertSame(['Langkah 1', 'Langkah 2'], $saran);
    }

    public function testParseNumberedListFallback(): void
    {
        $text = "1. Pertama lakukan audit\n2. Kedua update firewall\n3. Ketiga training staf";
        $saran = parseAiSaranText($text);
        $this->assertCount(3, $saran);
        $this->assertSame('Pertama lakukan audit', $saran[0]);
        $this->assertSame('Kedua update firewall', $saran[1]);
        $this->assertSame('Ketiga training staf', $saran[2]);
    }

    public function testParseBulletListFallback(): void
    {
        $text = "Berikut sarannya:\n- Saran A\n- Saran B";
        $saran = parseAiSaranText($text);
        $this->assertSame(['Saran A', 'Saran B'], $saran);
    }

    public function testParseEmptyReturnsEmptyArray(): void
    {
        $this->assertSame([], parseAiSaranText(''));
        $this->assertSame([], parseAiSaranText('   '));
    }

    public function testParseDeduplicatesAndTrims(): void
    {
        $text = '["  duplikat  ","duplikat","unik"]';
        $saran = parseAiSaranText($text);
        $this->assertSame(['duplikat', 'unik'], $saran);
    }

    public function testParseSingleItemJson(): void
    {
        $saran = parseAiSaranText('["Hanya satu saran"]');
        $this->assertSame(['Hanya satu saran'], $saran);
    }

    // ── generateAiSaran dengan mock transport ────────────────────

    public function testGenerateSucceedsWithMockTransport(): void
    {
        $this->setGemini('test-key-123');

        $resp = $this->geminiResponse('["Audit keamanan berkala","Backup offsite harian","Aktifkan MFA"]');
        $ctx  = [
            'kode_risiko'  => 'RSK-001',
            'nama_risiko'   => 'Risiko keamanan sistem',
            'deskripsi'     => 'Potensi brute force pada server',
            'penyebab'      => 'Password lemah',
            'dampak'        => 'Kebocoran data',
            'level_risiko' => 'Tinggi',
            'probabilitas' => 4,
            'dampak_level' => 5,
        ];
        $result = generateAiSaran($ctx, $this->mockTransport($resp));

        $this->assertTrue($result['ok'], 'Harus sukses. Pesan: ' . ($result['error'] ?? ''));
        $this->assertArrayHasKey('saran', $result);
        $this->assertCount(3, $result['saran']);
        $this->assertSame('Audit keamanan berkala', $result['saran'][0]);
        $this->assertSame('gemini-1.5-flash', $result['model']);
    }

    public function testGenerateFailsWithoutApiKey(): void
    {
        $this->clearGeminiKey();
        $result = generateAiSaran(['nama_risiko' => 'X', 'deskripsi' => 'Y'], $this->mockTransport('{}'));
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('GEMINI_API_KEY', $result['error']);
    }

    public function testGenerateFailsWithEmptyContext(): void
    {
        $this->setGemini('test-key');
        // Semua field kosong → konteks kosong.
        $result = generateAiSaran([], $this->mockTransport('{}'));
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Konteks risiko kosong', $result['error']);
    }

    public function testGenerateFailsOnTransportError(): void
    {
        $this->setGemini('test-key');
        $ctx = ['nama_risiko' => 'Risiko', 'deskripsi' => 'Deskripsi lengkap'];
        $result = generateAiSaran($ctx, $this->failingTransport());
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Gagal menghubungi layanan AI', $result['error']);
        $this->assertStringContainsString('Connection timed out', $result['error']);
    }

    public function testGenerateFailsOnHttpError(): void
    {
        $this->setGemini('test-key');
        $errBody = json_encode(['error' => ['message' => 'API key not valid. Please pass a valid API key.']]);
        $ctx = ['nama_risiko' => 'Risiko', 'deskripsi' => 'Deskripsi'];
        $result = generateAiSaran($ctx, $this->mockTransport($errBody, 400));
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('API key not valid', $result['error']);
    }

    public function testGenerateFailsOnEmptyResponse(): void
    {
        $this->setGemini('test-key');
        // Respons valid tapi tidak ada candidate (simulasi content blocked).
        $resp = json_encode(['candidates' => [], 'promptFeedback' => ['blockReason' => 'SAFETY']]);
        $ctx = ['nama_risiko' => 'Risiko', 'deskripsi' => 'Deskripsi'];
        $result = generateAiSaran($ctx, $this->mockTransport($resp));
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('SAFETY', $result['error']);
    }

    public function testGenerateFailsOnUnparseableText(): void
    {
        $this->setGemini('test-key');
        // Teks tanpa array JSON dan tanpa baris bermakna.
        $resp = $this->geminiResponse('Maaf, saya tidak dapat membantu.');
        $ctx = ['nama_risiko' => 'Risiko', 'deskripsi' => 'Deskripsi'];
        $result = generateAiSaran($ctx, $this->mockTransport($resp));
        // Fallback parser akan ambil seluruh teks sebagai 1 item → tetap ok.
        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['saran']);
        $this->assertStringContainsString('Maaf', $result['saran'][0]);
    }

    public function testGenerateReturnsMaxSixSuggestions(): void
    {
        $this->setGemini('test-key');
        $arr = [];
        for ($i = 1; $i <= 8; $i++) $arr[] = "Saran $i";
        $resp = $this->geminiResponse(json_encode($arr, JSON_UNESCAPED_UNICODE));
        $ctx = ['nama_risiko' => 'Risiko', 'deskripsi' => 'Deskripsi'];
        $result = generateAiSaran($ctx, $this->mockTransport($resp));
        $this->assertTrue($result['ok']);
        $this->assertCount(6, $result['saran'], 'generateAiSaran harus membatasi ke 6 saran');
    }

    public function testGeneratePromptContainsRiskContext(): void
    {
        $this->setGemini('test-key');
        $captured = null;
        $transport = function (string $url, string $body) use (&$captured): array {
            $captured = $body;
            return ['resp' => $this->geminiResponse('["ok"]'), 'err' => null, 'code' => 200];
        };
        $ctx = ['kode_risiko' => 'RSK-99', 'nama_risiko' => 'Kebocoran data pasien'];
        generateAiSaran($ctx, $transport);
        $this->assertNotNull($captured);
        $this->assertStringContainsString('RSK-99', $captured);
        $this->assertStringContainsString('Kebocoran data pasien', $captured);
        $this->assertStringContainsString('manajemen risiko', $captured);
        // API key tidak boleh bocor ke body prompt.
        $this->assertStringNotContainsString('test-key', $captured);
    }
}
