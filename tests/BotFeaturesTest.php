<?php
/**
 * Unit Test untuk Fitur Integrasi Bot MANRIS
 */

use PHPUnit\Framework\TestCase;

final class BotFeaturesTest extends TestCase {

    public function testKirimTelegramFailsWithoutCredentials(): void {
        // Tanpa token dan chat id
        putenv('TELEGRAM_BOT_TOKEN=');
        $res = kirimTelegram('', 'Test message');
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('TELEGRAM_BOT_TOKEN', $res['error']);
    }

    public function testKirimWhatsAppFailsWithoutCredentials(): void {
        putenv('WA_GATEWAY_TOKEN=');
        $res = kirimWhatsApp('', 'Test message');
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('WA_GATEWAY_TOKEN', $res['error']);
    }

    public function testTelegramCommandHandlerHelp(): void {
        require_once dirname(__DIR__) . '/bot_telegram.php';

        $helpResponse = handleTelegramCommand(12345, '/help', 'Budi');
        $this->assertStringContainsString('Halo, Budi!', $helpResponse);
        $this->assertStringContainsString('/ringkasan', $helpResponse);
        $this->assertStringContainsString('/deadline', $helpResponse);
        $this->assertStringContainsString('/risiko_tinggi', $helpResponse);
        $this->assertStringContainsString('/cek', $helpResponse);
        $this->assertStringContainsString('/ai', $helpResponse);
    }

    public function testTelegramCommandHandlerUnknown(): void {
        require_once dirname(__DIR__) . '/bot_telegram.php';

        $res = handleTelegramCommand(12345, '/perintah_aneh', 'Budi');
        $this->assertStringContainsString('tidak dikenali', $res);
    }

    public function testTelegramCommandHandlerAiEmpty(): void {
        require_once dirname(__DIR__) . '/bot_telegram.php';

        $res = handleTelegramCommand(12345, '/ai', 'Budi');
        $this->assertStringContainsString('Silakan tulis pertanyaan Anda', $res);
    }

    public function testKnowledgeBaseFallbackResponses(): void {
        require_once dirname(__DIR__) . '/api_ai_chat.php';

        $resRumus = getKnowledgeBaseFallback('Bagaimana rumus perumusan risiko?');
        $this->assertStringContainsString('Penyebab', $resRumus);
        $this->assertStringContainsString('Peristiwa', $resRumus);
        $this->assertStringContainsString('Dampak', $resRumus);

        $resSkor = getKnowledgeBaseFallback('Bagaimana cara hitung skor risiko 5x5?');
        $this->assertStringContainsString('Probabilitas', $resSkor);
        $this->assertStringContainsString('Besaran', $resSkor);

        $resMitigasi = getKnowledgeBaseFallback('Apa saja strategi aksi mitigasi?');
        $this->assertStringContainsString('Menghindari', $resMitigasi);
        $this->assertStringContainsString('Mengurangi', $resMitigasi);
    }

    public function testAiChatFilesHaveHighTokenLimitAndCompletionInstruction(): void {
        $chatContent = file_get_contents(dirname(__DIR__) . '/api_ai_chat.php');
        $botContent  = file_get_contents(dirname(__DIR__) . '/bot_telegram.php');

        // Pastikan api_ai_chat memiliki token limit 8192 dan instruksi tuntas
        $this->assertStringContainsString("'maxOutputTokens' => 8192", $chatContent);
        $this->assertStringContainsString('JANGAN PERNAH memotong', $chatContent);

        // Pastikan bot_telegram memiliki token limit minimal 2048
        $this->assertStringContainsString("'maxOutputTokens' => 2048", $botContent);
        $this->assertStringContainsString('JANGAN PERNAH terputus', $botContent);
    }

    public function testAiChatLogoutAndSessionPurgeFeatures(): void {
        $apiChat = file_get_contents(dirname(__DIR__) . '/api_ai_chat.php');
        $footer  = file_get_contents(dirname(__DIR__) . '/includes/footer.php');
        $login   = file_get_contents(dirname(__DIR__) . '/modules/login.php');
        $widget  = file_get_contents(dirname(__DIR__) . '/assets/js/ai_chat_widget.js');

        // api_ai_chat harus memvalidasi hasValidSession() agar sesi kedaluwarsa diblokir
        $this->assertStringContainsString('hasValidSession()', $apiChat);

        // footer harus menginjeksi token sesi unik dan script pembersih storage saat logged out
        $this->assertStringContainsString('MANRIS_SESSION_TOKEN', $footer);
        $this->assertStringContainsString('sessionStorage.removeItem', $footer);

        // Halaman login harus memiliki script pembersih storage otomatis
        $this->assertStringContainsString('manris_ai_chat', $login);

        // Widget JS harus menggunakan kunci terikat sesi dan menyediakan tombol bersihkan chat
        $this->assertStringContainsString('STORAGE_KEY', $widget);
        $this->assertStringContainsString('clearChat', $widget);
        $this->assertStringContainsString('manrisAiClearBtn', $widget);
    }

    public function testComprehensiveRiskManagementKnowledge(): void {
        require_once dirname(__DIR__) . '/api_ai_chat.php';

        // 1. 8 Prinsip ISO 31000
        $resPrinsip = getKnowledgeBaseFallback('Apa saja 8 prinsip manajemen risiko ISO 31000?');
        $this->assertStringContainsString('ISO 31000:2018', $resPrinsip);
        $this->assertStringContainsString('Terintegrasi', $resPrinsip);
        $this->assertStringContainsString('Dinamis', $resPrinsip);

        // 2. Risiko Inheren vs Residual vs Sekunder
        $resInheren = getKnowledgeBaseFallback('Apa perbedaan risiko inheren dan residual?');
        $this->assertStringContainsString('Inherent Risk', $resInheren);
        $this->assertStringContainsString('Residual Risk', $resInheren);
        $this->assertStringContainsString('Secondary Risk', $resInheren);

        // 3. Risiko Laboratorium Lingkungan & ISO 17025
        $resLab = getKnowledgeBaseFallback('Apa saja risiko laboratorium kesehatan lingkungan?');
        $this->assertStringContainsString('Biosafety', $resLab);
        $this->assertStringContainsString('ISO/IEC 17025', $resLab);
        $this->assertStringContainsString('Limbah B3', $resLab);

        // 4. Three Lines Model
        $resThreeLines = getKnowledgeBaseFallback('Jelaskan konsep Three Lines of Defense!');
        $this->assertStringContainsString('Lini Pertama', $resThreeLines);
        $this->assertStringContainsString('Lini Kedua', $resThreeLines);
        $this->assertStringContainsString('Lini Ketiga', $resThreeLines);

        // 5. Kaitan SPIP
        $resSpip = getKnowledgeBaseFallback('Apa kaitan SPIP dengan manajemen risiko?');
        $this->assertStringContainsString('PP No. 60 Tahun 2008', $resSpip);
        $this->assertStringContainsString('Penilaian Risiko', $resSpip);

        // 6. Hierarki Pengendalian
        $resHierarki = getKnowledgeBaseFallback('Bagaimana hierarki pengendalian risiko?');
        $this->assertStringContainsString('Eliminasi', $resHierarki);
        $this->assertStringContainsString('Substitusi', $resHierarki);
        $this->assertStringContainsString('Rekayasa Teknis', $resHierarki);
        $this->assertStringContainsString('APD', $resHierarki);

        // 7. KRI (Key Risk Indicators)
        $resKri = getKnowledgeBaseFallback('Apa itu KRI key risk indicator?');
        $this->assertStringContainsString('Key Risk Indicators', $resKri);
        $this->assertStringContainsString('early warning', $resKri);

        // 8. Root Cause Analysis
        $resRca = getKnowledgeBaseFallback('Bagaimana cara analisis akar masalah fishbone?');
        $this->assertStringContainsString('5 Whys', $resRca);
        $this->assertStringContainsString('Ishikawa', $resRca);

        // 9. Mitigasi vs Kontinjensi
        $resKontinjensi = getKnowledgeBaseFallback('Apa perbedaan rencana mitigasi dan rencana kontinjensi?');
        $this->assertStringContainsString('Tindakan Preventif', $resKontinjensi);
        $this->assertStringContainsString('Contingency Plan', $resKontinjensi);
    }

    public function testTelegramBotRiskManagementKnowledge(): void {
        require_once dirname(__DIR__) . '/bot_telegram.php';

        putenv('GEMINI_API_KEY='); // Paksa mode fallback

        $resPrinsip = getBotAiResponse('prinsip manajemen risiko');
        $this->assertStringContainsString('8 Prinsip', $resPrinsip);
        $this->assertStringContainsString('ISO 31000', $resPrinsip);

        $resInheren = getBotAiResponse('risiko inheren dan residual');
        $this->assertStringContainsString('Risiko Inheren', $resInheren);
        $this->assertStringContainsString('Risiko Residual', $resInheren);

        $resLab = getBotAiResponse('risiko laboratorium lingkungan');
        $this->assertStringContainsString('Biosafety', $resLab);
        $this->assertStringContainsString('17025', $resLab);

        $resHierarki = getBotAiResponse('hierarki pengendalian risiko');
        $this->assertStringContainsString('Eliminasi', $resHierarki);
        $this->assertStringContainsString('Substitusi', $resHierarki);

        $resSaran = getBotAiResponse('5 saran mitigasi identifikasi risiko');
        $this->assertStringContainsString('5 Rekomendasi Saran Mitigasi', $resSaran);
        $this->assertStringContainsString('1.', $resSaran);
        $this->assertStringContainsString('5.', $resSaran);
    }

    public function testFiveMitigationSuggestionsPerRiskIdentification(): void {
        require_once dirname(__DIR__) . '/api_ai_chat.php';

        // 1. Kasus Reagen
        $resReagen = getKnowledgeBaseFallback('Berikan saran mitigasi keterlambatan reagen laboratorium!');
        $this->assertStringContainsString('5 Rekomendasi Saran Mitigasi', $resReagen);
        $this->assertStringContainsString('1.', $resReagen);
        $this->assertStringContainsString('2.', $resReagen);
        $this->assertStringContainsString('3.', $resReagen);
        $this->assertStringContainsString('4.', $resReagen);
        $this->assertStringContainsString('5.', $resReagen);
        $this->assertStringContainsString('buffer stock', $resReagen);

        // 2. Kasus Kalibrasi Alat
        $resAlat = getKnowledgeBaseFallback('saran mitigasi kerusakan alat kalibrasi lab');
        $this->assertStringContainsString('5 Rekomendasi Saran Mitigasi', $resAlat);
        $this->assertStringContainsString('1.', $resAlat);
        $this->assertStringContainsString('5.', $resAlat);
        $this->assertStringContainsString('preventive maintenance', $resAlat);

        // 3. Kasus Umum 5 Saran Mitigasi
        $resUmum = getKnowledgeBaseFallback('5 saran mitigasi untuk identifikasi risiko');
        $this->assertStringContainsString('5 Saran Mitigasi Standar', $resUmum);
        $this->assertStringContainsString('1.', $resUmum);
        $this->assertStringContainsString('5.', $resUmum);
        $this->assertStringContainsString('Tindakan Preventif', $resUmum);
        $this->assertStringContainsString('Rencana Kontinjensi', $resUmum);
    }

    public function testAbsensiAndAksiVsSaranKnowledge(): void {
        require_once dirname(__DIR__) . '/api_ai_chat.php';
        require_once dirname(__DIR__) . '/bot_telegram.php';

        // 1. Uji penanganan risiko absensi pegawai
        $resAbsen = getKnowledgeBaseFallback('Pegawai lupa melakukan rekam absensi kehadiran dan pulang');
        $this->assertStringContainsString('5 Rekomendasi Aksi/Saran Mitigasi', $resAbsen);
        $this->assertStringContainsString('Auto-Reminder', $resAbsen);
        $this->assertStringContainsString('Dispensasi Lupa Absensi', $resAbsen);
        $this->assertStringContainsString('PP 94/2021', $resAbsen);

        // 2. Uji perbedaan aksi vs saran mitigasi
        $resBeda = getKnowledgeBaseFallback('apa bedanya aksi dengan saran mitigasi');
        $this->assertStringContainsString('Perbedaan Mendasar: Aksi Mitigasi vs Saran Mitigasi', $resBeda);
        $this->assertStringContainsString('Saran Mitigasi', $resBeda);
        $this->assertStringContainsString('Aksi Mitigasi', $resBeda);

        // 3. Uji pada Bot Telegram
        $resBotAbsen = getBotAiResponse('Pegawai lupa rekam absensi kehadiran');
        $this->assertStringContainsString('Mitigasi Risiko Pegawai Lupa Rekam Absensi', $resBotAbsen);

        $resBotBeda = getBotAiResponse('apa bedanya aksi dan saran mitigasi');
        $this->assertStringContainsString('Perbedaan Aksi Mitigasi vs Saran Mitigasi', $resBotBeda);

        // 4. Uji helper fallback cerdas getSmartMitigasiFallback()
        $fallbackAbsen = getSmartMitigasiFallback(['nama_risiko' => 'Pegawai lupa absensi']);
        $this->assertCount(5, $fallbackAbsen);
        $this->assertStringContainsString('Auto-Reminder', $fallbackAbsen[0]);
    }

    public function testAllUnitsAndCodesGroundingInAiAssistant(): void {
        require_once dirname(__DIR__) . '/api_ai_chat.php';

        // 1. Uji query kode risiko spesifik (A.14, L.1, M.1, I.3, G.1)
        $resA14 = getKnowledgeBaseFallback('A.14');
        $this->assertStringContainsString('Identitas Risiko & Konteks Organisasi', $resA14);
        $this->assertStringContainsString('5 Rencana Aksi Mitigasi Terdaftar', $resA14);
        $this->assertStringContainsString('5 Rekomendasi Saran Mitigasi', $resA14);
        $this->assertStringContainsString('Auto-Reminder', $resA14);

        $resL1 = getKnowledgeBaseFallback('saran mitigasi L.1');
        $this->assertStringContainsString('L.1', $resL1);
        $this->assertStringContainsString('5 Rencana Aksi Mitigasi Terdaftar', $resL1);
        $this->assertStringContainsString('5 Rekomendasi Saran Mitigasi', $resL1);

        $resM1 = getKnowledgeBaseFallback('M.1');
        $this->assertStringContainsString('M.1', $resM1);
        $this->assertStringContainsString('5 Rencana Aksi Mitigasi Terdaftar', $resM1);

        $resI3 = getKnowledgeBaseFallback('I.3');
        $this->assertStringContainsString('I.3', $resI3);
        $this->assertStringContainsString('5 Rencana Aksi Mitigasi Terdaftar', $resI3);

        $resG1 = getKnowledgeBaseFallback('G.1');
        $this->assertStringContainsString('G.1', $resG1);
        $this->assertStringContainsString('5 Rencana Aksi Mitigasi Terdaftar', $resG1);

        // 2. Uji query daftar risiko per unit
        $resAdum = getKnowledgeBaseFallback('risiko adum');
        $this->assertStringContainsString('Bagian Tata Usaha (ADUM)', $resAdum);
        $this->assertStringContainsString('25 Risiko', $resAdum);

        $resTimker1 = getKnowledgeBaseFallback('risiko timker1');
        $this->assertStringContainsString('Tim Kerja 1', $resTimker1);
        $this->assertStringContainsString('23 Risiko', $resTimker1);

        $resTimker2 = getKnowledgeBaseFallback('risiko timker2');
        $this->assertStringContainsString('Tim Kerja 2', $resTimker2);
        $this->assertStringContainsString('10 Risiko', $resTimker2);

        $resLab = getKnowledgeBaseFallback('risiko lab');
        $this->assertStringContainsString('Koordinator Laboratorium', $resLab);
        $this->assertStringContainsString('7 Risiko', $resLab);

        $resUpg = getKnowledgeBaseFallback('risiko upg');
        $this->assertStringContainsString('Unit Pengendalian Gratifikasi (UPG)', $resUpg);
        $this->assertStringContainsString('9 Risiko', $resUpg);
    }
}

