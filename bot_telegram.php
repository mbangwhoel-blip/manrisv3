<?php
/**
 * TELEGRAM BOT WEBHOOK & INTERACTIVE HANDLER — MANRIS v2
 *
 * Menerima interaksi dua arah dari Telegram Bot.
 * Mendukung perintah:
 *   /start, /help        — Panduan & daftar perintah
 *   /ringkasan           — Ringkasan profil & level risiko organisasi
 *   /deadline            — 5 aksi mitigasi terdekat yang belum selesai
 *   /risiko_tinggi       — Daftar risiko berlevel Tinggi / Sangat Tinggi
 *   /cek <kode>          — Detail risiko berdasarkan kode (contoh: /cek R-01)
 *   /ai <pertanyaan>     — Tanya jawab langsung dengan AI Asisten Risiko
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

function handleTelegramRequest(): void {
    // Validasi Webhook Secret Token jika dikonfigurasi di env
    $secretToken = env('TELEGRAM_WEBHOOK_SECRET', '');
    if (!empty($secretToken)) {
        $headerSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
        if (!hash_equals($secretToken, $headerSecret)) {
            http_response_code(403);
            exit('Forbidden — invalid secret token.');
        }
    }

    // ── Penanganan Browser / Test Mode (GET) ─────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        header('Content-Type: application/json; charset=UTF-8');
        $token = env('TELEGRAM_BOT_TOKEN', '');
        $hasToken = !empty($token);
        
        // Mode testing simulasi via browser: ?test=1&cmd=/ringkasan
        if (isset($_GET['test']) && (APP_ENV === 'development' || isLoggedIn())) {
            $testCmd = trim($_GET['cmd'] ?? '/ringkasan');
            $resp = handleTelegramCommand(0, $testCmd, 'Tester');
            echo json_encode([
                'mode' => 'test_simulation',
                'command' => $testCmd,
                'response_text' => $resp
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode([
            'status' => 'online',
            'service' => 'MANRIS Telegram Bot Webhook',
            'bot_configured' => $hasToken,
            'app_url' => APP_URL,
            'time' => date('Y-m-d H:i:s')
        ]);
        exit;
    }

    // ── Ambil Payload JSON dari Telegram (POST) ─────────────────────
    $raw = file_get_contents('php://input');
    $update = json_decode($raw, true);

    if (!is_array($update) || !isset($update['message'])) {
        http_response_code(200); // Telegram mewajibkan HTTP 200 agar tidak retry terus
        exit('OK - No message');
    }

    $message = $update['message'];
    $chatId  = $message['chat']['id'] ?? 0;
    $text    = trim((string)($message['text'] ?? ''));
    $from    = $message['from']['first_name'] ?? 'Rekan';

    if ($chatId === 0 || $text === '') {
        http_response_code(200);
        exit('OK - Empty text');
    }

    // Proses perintah dan kirim balasan
    $replyText = handleTelegramCommand($chatId, $text, $from);
    if (!empty($replyText)) {
        if (mb_strlen($replyText) > 4000) {
            $chunks = str_split($replyText, 3900);
            foreach ($chunks as $chunk) {
                kirimTelegram((string)$chatId, $chunk, 'Markdown');
            }
        } else {
            kirimTelegram((string)$chatId, $replyText, 'Markdown');
        }
    }

    http_response_code(200);
    echo 'OK';
    exit;
}

// Jalankan controller hanya jika file diakses langsung
if (PHP_SAPI !== 'cli' || (isset($_SERVER['SCRIPT_FILENAME']) && basename($_SERVER['SCRIPT_FILENAME']) === 'bot_telegram.php')) {
    handleTelegramRequest();
}

/**
 * Dispatcher perintah bot
 */
function handleTelegramCommand(int $chatId, string $input, string $userName): string {
    $parts = explode(' ', $input, 2);
    $cmd = strtolower($parts[0]);
    // Hilangkan mention bot jika ada (contoh: /ringkasan@manris_bot -> /ringkasan)
    if (str_contains($cmd, '@')) {
        $cmd = explode('@', $cmd)[0];
    }
    $args = trim($parts[1] ?? '');

    switch ($cmd) {
        case '/start':
        case '/help':
            return "👋 *Halo, {$userName}!* Selamat datang di Bot *MANRIS* (Sistem Informasi Manajemen Risiko).\n\n"
                 . "Berikut daftar perintah yang dapat Anda gunakan:\n\n"
                 . "📊 `/ringkasan` — Ringkasan profil & statistik risiko\n"
                 . "⏰ `/deadline` — 5 mitigasi terdekat yang belum selesai\n"
                 . "⚠️ `/risiko_tinggi` — Daftar risiko Tinggi & Ekstrem\n"
                 . "🔍 `/cek <kode>` — Cek detail risiko (contoh: `/cek R-01`)\n"
                 . "🤖 `/ai <tanya>` — Konsultasi risiko ke Asisten AI\n"
                 . "ℹ️ `/help` — Tampilkan bantuan ini\n\n"
                 . "🌐 [Buka Web MANRIS](" . APP_URL . ")";

        case '/ringkasan':
            return getBotSummary();

        case '/deadline':
            return getBotDeadlines();

        case '/risiko_tinggi':
            return getBotHighRisks();

        case '/cek':
            if (empty($args)) {
                return "⚠️ Mohon sertakan kode risiko yang ingin dicek.\nContoh: `/cek R-01` atau `/cek RIS-2026-001`";
            }
            return getBotRiskDetail($args);

        case '/ai':
            if (empty($args)) {
                return "🤖 Silakan tulis pertanyaan Anda setelah perintah `/ai`.\nContoh: `/ai bagaimana cara menurunkan tingkat probabilitas risiko?`";
            }
            return getBotAiResponse($args);

        default:
            return "❓ Perintah `{$cmd}` tidak dikenali.\nKetik `/help` untuk melihat daftar perintah yang tersedia.";
    }
}

/**
 * Handler /ringkasan
 */
function getBotSummary(): string {
    try {
        $db = getDB();

        // Total risiko & per level
        $res = $db->query("SELECT level_risiko, COUNT(*) as jml FROM risiko GROUP BY level_risiko");
        $levels = ['Sangat Tinggi' => 0, 'Tinggi' => 0, 'Sedang' => 0, 'Rendah' => 0, 'Sangat Rendah' => 0];
        $totalRisiko = 0;
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $lvl = $row['level_risiko'];
                $count = (int)$row['jml'];
                $levels[$lvl] = ($levels[$lvl] ?? 0) + $count;
                $totalRisiko += $count;
            }
        }

        // Mitigasi
        $mRes = $db->query("SELECT status, COUNT(*) as jml FROM mitigasi GROUP BY status");
        $selesai = 0;
        $totalMitigasi = 0;
        if ($mRes) {
            while ($mRow = $mRes->fetch_assoc()) {
                $count = (int)$mRow['jml'];
                if ($mRow['status'] === 'Selesai') $selesai += $count;
                $totalMitigasi += $count;
            }
        }
        $persenMitigasi = $totalMitigasi > 0 ? round(($selesai / $totalMitigasi) * 100, 1) : 0;

        return "📊 *RINGKASAN PROFIL RISIKO ORGANISASI*\n"
             . "Sistem Informasi Manajemen Risiko (MANRIS)\n"
             . "━━━━━━━━━━━━━━━━━━━━\n\n"
             . "📁 *Total Risiko Terdaftar:* {$totalRisiko}\n"
             . "🔴 Sangat Tinggi: *{$levels['Sangat Tinggi']}*\n"
             . "🟠 Tinggi: *{$levels['Tinggi']}*\n"
             . "🟡 Sedang: *{$levels['Sedang']}*\n"
             . "🟢 Rendah / Sangat Rendah: *" . ($levels['Rendah'] + $levels['Sangat Rendah']) . "*\n\n"
             . "🛡️ *Status Aksi Mitigasi:*\n"
             . "• Selesai: *{$selesai}* dari *{$totalMitigasi}* aksi ({$persenMitigasi}%)\n\n"
             . "🔗 [Lihat Dashboard Lengkap](" . APP_URL . "/?page=dashboard)";
    } catch (\Throwable $e) {
        return "⚠️ Gagal mengambil data ringkasan: " . $e->getMessage();
    }
}

/**
 * Handler /deadline
 */
function getBotDeadlines(): string {
    try {
        $db = getDB();
        $res = $db->query("
            SELECT m.aksi, m.pic, m.deadline, m.progress, r.kode_risiko, r.nama_risiko
            FROM mitigasi m
            JOIN risiko r ON m.id_risiko = r.id
            WHERE m.status != 'Selesai' AND m.deadline IS NOT NULL
            ORDER BY m.deadline ASC
            LIMIT 5
        ");

        if (!$res || $res->num_rows === 0) {
            return "🎉 *Alhamdulillah!* Tidak ada aksi mitigasi yang mendekati tenggat waktu atau tertunda saat ini.";
        }

        $msg = "⏰ *5 AKSI MITIGASI TERDEKAT (BELUM SELESAI)*\n━━━━━━━━━━━━━━━━━━━━\n\n";
        $no = 1;
        while ($row = $res->fetch_assoc()) {
            $daysLeft = (strtotime($row['deadline']) - strtotime(date('Y-m-d'))) / 86400;
            if ($daysLeft < 0) {
                $urgency = "⚠️ *TERLAMBAT " . abs((int)$daysLeft) . " HARI*";
            } elseif ($daysLeft === 0.0) {
                $urgency = "🔥 *HARI INI*";
            } else {
                $urgency = "⏳ *" . ceil($daysLeft) . " hari lagi*";
            }

            $msg .= "{$no}. *[{$row['kode_risiko']}]* {$row['nama_risiko']}\n"
                 . "   • Aksi: {$row['aksi']}\n"
                 . "   • PIC: {$row['pic']}\n"
                 . "   • Deadline: " . tglIndo($row['deadline']) . " ({$urgency})\n"
                 . "   • Progress: " . (int)$row['progress'] . "%\n\n";
            $no++;
        }

        $msg .= "🔗 [Buka Modul Mitigasi](" . APP_URL . "/?page=mitigasi)";
        return $msg;
    } catch (\Throwable $e) {
        return "⚠️ Gagal mengambil data deadline: " . $e->getMessage();
    }
}

/**
 * Handler /risiko_tinggi
 */
function getBotHighRisks(): string {
    try {
        $db = getDB();
        $res = $db->query("
            SELECT kode_risiko, nama_risiko, level_risiko, skor, kategori
            FROM risiko
            WHERE level_risiko IN ('Tinggi', 'Sangat Tinggi')
            ORDER BY skor DESC, id DESC
            LIMIT 5
        ");

        if (!$res || $res->num_rows === 0) {
            return "✅ *Kondisi Terkendali!* Tidak ditemukan risiko berstatus *Tinggi* atau *Sangat Tinggi*.";
        }

        $msg = "⚠️ *DAFTAR RISIKO LEVEL TINGGI / EKSTREM*\n━━━━━━━━━━━━━━━━━━━━\n\n";
        $no = 1;
        while ($row = $res->fetch_assoc()) {
            $badge = $row['level_risiko'] === 'Sangat Tinggi' ? '🔴 *SANGAT TINGGI*' : '🟠 *TINGGI*';
            $msg .= "{$no}. *[{$row['kode_risiko']}]* {$row['nama_risiko']}\n"
                 . "   • Level: {$badge} (Skor: {$row['skor']})\n"
                 . "   • Kategori: {$row['kategori']}\n\n";
            $no++;
        }

        $msg .= "Ketik `/cek <kode_risiko>` untuk melihat detail rencana mitigasinya.";
        return $msg;
    } catch (\Throwable $e) {
        return "⚠️ Gagal mengambil data risiko: " . $e->getMessage();
    }
}

/**
 * Handler /cek <kode>
 */
function getBotRiskDetail(string $kode): string {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT id, kode_risiko, nama_risiko, deskripsi, penyebab, dampak, 
                   probabilitas, dampak_level, skor, level_risiko, kategori
            FROM risiko 
            WHERE kode_risiko = ? OR id = ?
            LIMIT 1
        ");
        $idParam = is_numeric($kode) ? (int)$kode : 0;
        $stmt->bind_param('si', $kode, $idParam);
        $stmt->execute();
        $risk = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$risk) {
            return "🔍 Risiko dengan kode `{$kode}` tidak ditemukan di database.";
        }

        $riskId = (int)$risk['id'];
        $mStmt = $db->prepare("SELECT aksi, pic, deadline, status, progress FROM mitigasi WHERE id_risiko = ?");
        $mStmt->bind_param('i', $riskId);
        $mStmt->execute();
        $mitigasiList = $mStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $mStmt->close();

        $msg = "📌 *DETAIL RISIKO [{$risk['kode_risiko']}]*\n"
             . "━━━━━━━━━━━━━━━━━━━━\n"
             . "*Nama:* {$risk['nama_risiko']}\n"
             . "*Kategori:* {$risk['kategori']}\n"
             . "*Level:* *{$risk['level_risiko']}* (Skor: {$risk['skor']})\n"
             . "*Matriks:* Probabilitas {$risk['probabilitas']}/5 × Dampak {$risk['dampak_level']}/5\n\n"
             . "*Penyebab:* " . ($risk['penyebab'] ?: '-') . "\n"
             . "*Dampak:* " . ($risk['dampak'] ?: '-') . "\n\n"
             . "🛡️ *Rencana Mitigasi (" . count($mitigasiList) . " Aksi):*\n";

        if (empty($mitigasiList)) {
            $msg .= "_Belum ada rencana mitigasi yang ditambahkan._\n";
        } else {
            foreach ($mitigasiList as $idx => $m) {
                $statusIcon = $m['status'] === 'Selesai' ? '✅' : '⏳';
                $msg .= ($idx + 1) . ". {$statusIcon} *{$m['aksi']}*\n"
                     . "   PIC: {$m['pic']} | Progress: {$m['progress']}%\n";
            }
        }

        $msg .= "\n🔗 [Buka di Web](" . APP_URL . "/?page=risiko&detail=" . $riskId . ")";
        return $msg;
    } catch (\Throwable $e) {
        return "⚠️ Gagal mengambil detail: " . $e->getMessage();
    }
}

/**
 * Handler /ai <pertanyaan>
 */
function getBotAiResponse(string $tanya): string {
    $apiKey = env('GEMINI_API_KEY', '');
    if (!empty($apiKey)) {
        $model = env('GEMINI_MODEL', 'gemini-1.5-flash');
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . $apiKey;
        $systemInstruction = "Anda adalah konsultan ahli manajemen risiko organisasi untuk aplikasi MANRIS (ISO 31000, SPIP PP 60/2008, dan Pedoman Kemenkes RI / ISO 17025 Lab Lingkungan). Jawablah pertanyaan pengguna dengan terstruktur, ringkas, padat, dan jelas. PENTING: Pastikan jawaban tuntas dan selesai hingga kalimat atau simpulan penutup (JANGAN PERNAH terputus di tengah kalimat).";
        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => "{$systemInstruction}\n\nPertanyaan: {$tanya}"]]]
            ],
            'generationConfig' => ['maxOutputTokens' => 2048, 'temperature' => 0.5]
        ];
        $http = aiSaranHttpPost($url, json_encode($payload));
        if (($http['code'] ?? 0) === 200 && !empty($http['resp'])) {
            $data = json_decode($http['resp'], true);
            $ans = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if (is_string($ans) && trim($ans) !== '') {
                return "🤖 *Jawaban AI Asisten MANRIS:*\n\n" . trim($ans);
            }
        }
    }

    // Fallback cerdas jika AI key kosong / limit
    $q = mb_strtolower($tanya);

    if (str_contains($q, 'prinsip')) {
        return "🤖 *8 Prinsip Manajemen Risiko (ISO 31000:2018):*\nTujuan pokok: *Penciptaan & Perlindungan Nilai*.\n1. Terintegrasi\n2. Terstruktur & Komprehensif\n3. Disesuaikan (Customized)\n4. Inklusif\n5. Dinamis\n6. Informasi Terbaik yang Tersedia\n7. Faktor Manusia & Budaya\n8. Perbaikan Berkelanjutan.";
    }
    if (str_contains($q, 'inheren') || str_contains($q, 'residual')) {
        return "🤖 *Risiko Inheren vs Residual:*\n• *Risiko Inheren*: Besaran risiko murni sebelum adanya mitigasi/kontrol baru.\n• *Risiko Residual*: Risiko sisa pasca-mitigasi yang harus berada di bawah ambang *Selera Risiko (Risk Appetite)*.\n• *Risiko Sekunder*: Risiko baru yang timbul akibat diterapkannya tindakan mitigasi itu sendiri.";
    }
    if (str_contains($q, 'three lines') || str_contains($q, 'tiga lini')) {
        return "🤖 *Model Tiga Lini Pertahanan (Three Lines Model):*\n• *Lini 1 (Operasional)*: ADUM, Timker, Lab (mengidentifikasi & mitigasi harian).\n• *Lini 2 (Pemantau)*: Tim Manajemen Risiko & Mutu (fasilitasi & pantau batas selera risiko).\n• *Lini 3 (Asurans)*: Itjen Kemenkes / SPI (audit independen).";
    }
    if (str_contains($q, 'hierarki') || str_contains($q, 'eliminasi') || str_contains($q, 'substitusi')) {
        return "🤖 *Hierarki Pengendalian Risiko:*\n1. *Eliminasi* (Menghilangkan bahaya total)\n2. *Substitusi* (Mengganti bahan berbahaya ke yang lebih aman)\n3. *Rekayasa Teknis* (Lemari asam/BSC, sensor gas otomatis)\n4. *Administratif* (SOP, rotasi analis, pelatihan)\n5. *APD* (Masker respirator, kacamata goggle, sarung tangan nitril).";
    }
    if (str_contains($q, 'kontinjensi') || str_contains($q, 'tanggap darurat')) {
        return "🤖 *Mitigasi vs Kontinjensi:*\n• *Mitigasi*: Upaya preventif sebelum insiden (misal: servis genset berkala, kalibrasi tahunan).\n• *Rencana Kontinjensi*: Tindakan darurat saat risiko terjadi (misal: otomatisasi genset saat PLN padam, MOU rujukan uji ke lab mitra).";
    }
    if (str_contains($q, 'laboratorium') || str_contains($q, 'lab') || str_contains($q, 'biosafety') || str_contains($q, '17025')) {
        return "🤖 *Risiko Utama Lab Kesehatan Lingkungan (BBLKL & ISO 17025):*\n1. *Biologis & Biosafety*: Paparan patogen, kontaminasi silang sampel air/tanah/udara.\n2. *Akurasi Pengujian*: Deviasi kalibrasi instrumen (AAS, GC-MS), kerusakan rantai dingin (*cold chain*) reagen.\n3. *Limbah B3*: Kegagalan IPAL lab, tumpahan pelarut kimia pekat.\n4. *Turnaround Time*: Keterlambatan penerbitan Sertifikat Hasil Uji (SHU) saat lonjakan sampel KLB.";
    }
    if (str_contains($q, 'spip') || str_contains($q, 'pengendalian intern')) {
        return "🤖 *SPIP & Manajemen Risiko (PP No. 60/2008):*\nManajemen Risiko adalah pilar Penilaian Risiko (*Risk Assessment*) dalam 5 Unsur SPIP: Lingkungan Pengendalian, Penilaian Risiko, Kegiatan Pengendalian, Informasi & Komunikasi, serta Pemantauan.";
    }
    if (str_contains($q, 'kri') || str_contains($q, 'indikator risiko') || str_contains($q, 'early warning')) {
        return "🤖 *Key Risk Indicators (KRI):*\nMetrik sinyal peringatan dini sebelum insiden terjadi.\nContoh Lab: Fluktuasi suhu lemari pendingin reagen, % alat mendekati jatuh tempo kalibrasi, stok buffer reagen di bawah ambang minimum.";
    }
    if (str_contains($q, 'akar masalah') || str_contains($q, 'root cause') || str_contains($q, 'fishbone')) {
        return "🤖 *Analisis Akar Masalah (RCA):*\n• *5 Whys*: Menanyakan 'Mengapa?' 5 kali hingga ke akar sistemik.\n• *Diagram Tulang Ikan (Fishbone 6M)*: Analisis dari dimensi Man, Machine, Method, Material, Measurement, dan Milieu.";
    }
    if (str_contains($q, 'rumus') || str_contains($q, 'pernyataan') || str_contains($q, 'buat')) {
        return "🤖 *Formula Pernyataan Risiko:*\nFormat standar ISO 31000: *[Penyebab] → [Peristiwa Risiko] → [Dampak]*.\nContoh: *Karena keterlambatan vendor reagen (Penyebab), maka pengujian sampel tertunda (Peristiwa), berdampak pada denda klaim pelanggan (Dampak).*";
    }
    if (((str_contains($q, 'beda') || str_contains($q, 'perbedaan') || str_contains($q, 'bedanya')) && (str_contains($q, 'aksi') || str_contains($q, 'tindakan')) && (str_contains($q, 'saran') || str_contains($q, 'rekomendasi'))) || str_contains($q, 'aksi vs saran') || str_contains($q, 'saran vs aksi')) {
        return "🤖 *Perbedaan Aksi Mitigasi vs Saran Mitigasi:*\n"
             . "• *Saran Mitigasi*: Usulan/rekomendasi strategis konseptual dari AI/auditor mengenai solusi yang sebaiknya dilakukan. Belum mengikat dan belum memiliki PIC, biaya, atau deadline pasti.\n"
             . "• *Aksi Mitigasi*: Rencana tindak nyata operasional yang telah disahkan pemilik risiko, tercatat resmi di menu Mitigasi MANRIS, serta memiliki PIC, deadline, anggaran, progress (0-100%), dan bukti fisik pelaksanaan.";
    }
    if (str_contains($q, 'absen') || str_contains($q, 'presensi') || str_contains($q, 'kehadiran') || str_contains($q, 'pulang') || str_contains($q, 'tukin') || str_contains($q, 'disiplin')) {
        return "🤖 *5 Mitigasi Risiko Pegawai Lupa Rekam Absensi Kehadiran & Pulang:*\n"
             . "1. *Auto-Reminder*: Broadcast pengingat WhatsApp resmi pada 07.15 WIB & 15.45 WIB.\n"
             . "2. *Sarana Biometrik*: Tambah mesin fingerprint/face recognition cadangan di lobby gedung.\n"
             . "3. *SOP Dispensasi Terkendali*: Maksimal dispensasi 2x/semester dengan verifikasi atasan & log CCTV/kegiatan.\n"
             . "4. *Rekonsiliasi Harian*: Monitoring log presensi jam 09.00 WIB oleh ADUM sebelum data terkunci di portal pusat.\n"
             . "5. *Edukasi PP 94/2021*: Sosialisasi disiplin jam kerja ASN dan transparansi rumus potongan tunjangan kinerja (tukin).";
    }
    if (str_contains($q, 'saran mitigasi') || str_contains($q, '5 saran') || str_contains($q, 'rekomendasi mitigasi')) {
        return "🤖 *5 Rekomendasi Saran Mitigasi per Identifikasi Risiko:*\n"
             . "1. *Preventif*: Menetapkan kontrol akar masalah (buffer stock reagen 2 bulan, servis rutin alat uji).\n"
             . "2. *Rekayasa Teknis*: Pasang sistem pengaman otomatis (stabilizer/UPS industri, sensor alarm kebocoran, lemari asam HEPA).\n"
             . "3. *Administratif & SOP*: Pengetatan instruksi kerja, rotasi analis kerja, dan pelatihan berkala PIC.\n"
             . "4. *Protektif & APD*: APD lengkap tahan kimia/patogen (respirator uap, goggle, sarung tangan nitril tebal).\n"
             . "5. *Rencana Kontinjensi*: SOP rujukan uji lab jejaring, failover backup data harian, dan prosedur tanggap darurat.";
    }
    if (str_contains($q, 'mitigasi') || str_contains($q, 'strategi')) {
        return "🤖 *Opsi Mitigasi Risiko:*\n1. Menghindari (Avoid)\n2. Mengurangi kemungkinan/dampak (Mitigate)\n3. Mentransfer risiko ke pihak ketiga (Transfer)\n4. Menerima risiko dengan toleransi terukur (Accept).";
    }

    return "🤖 *AI Asisten MANRIS:*\nUntuk risiko, prioritaskan identifikasi akar masalah (root cause), ukur kemungkinan & dampaknya pada matriks 5×5, lalu susun aksi mitigasi konkret dengan target deadline dan PIC yang jelas.";
}
