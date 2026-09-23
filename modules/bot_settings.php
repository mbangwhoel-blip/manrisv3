<?php
/**
 * MODUL PENGATURAN & UJI COBA BOT — MANRIS (Admin Only)
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
requireRole('Admin');

$db = getDB();

// Handle Form Action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('error', 'Token keamanan tidak valid.');
        header('Location: ' . APP_URL . '/?page=bot_settings');
        exit;
    }

    $aksi = $_POST['aksi'] ?? '';

    // 1. Uji Coba Telegram
    if ($aksi === 'test_telegram') {
        $chatId = trim($_POST['chat_id'] ?? '');
        $pesan  = trim($_POST['pesan'] ?? '');
        if (empty($chatId) || empty($pesan)) {
            setFlash('error', 'Chat ID dan isi pesan wajib diisi.');
        } else {
            $res = kirimTelegram($chatId, "🧪 *[MANRIS TEST NOTIFIKASI]*\n\n" . $pesan . "\n\n_Waktu: " . date('d/m/Y H:i:s') . "_");
            if ($res['ok']) {
                logAktivitas('TEST_BOT', 'bot_telegram', 0, 'Uji coba kirim Telegram ke ' . $chatId . ' berhasil');
                setFlash('success', 'Pesan uji coba Telegram BERHASIL dikirim!');
            } else {
                setFlash('error', 'Gagal mengirim Telegram: ' . ($res['error'] ?? 'Terjadi kesalahan.'));
            }
        }
        header('Location: ' . APP_URL . '/?page=bot_settings');
        exit;
    }

    // 2. Uji Coba WhatsApp
    if ($aksi === 'test_whatsapp') {
        $nomor = trim($_POST['nomor_wa'] ?? '');
        $pesan = trim($_POST['pesan'] ?? '');
        if (empty($nomor) || empty($pesan)) {
            setFlash('error', 'Nomor WA dan isi pesan wajib diisi.');
        } else {
            $res = kirimWhatsApp($nomor, "*[MANRIS TEST NOTIFIKASI]*\n\n" . $pesan . "\n\nWaktu: " . date('d/m/Y H:i:s'));
            if ($res['ok']) {
                logAktivitas('TEST_BOT', 'bot_whatsapp', 0, 'Uji coba kirim WhatsApp ke ' . $nomor . ' berhasil');
                setFlash('success', 'Pesan uji coba WhatsApp BERHASIL dikirim!');
            } else {
                setFlash('error', 'Gagal mengirim WhatsApp: ' . ($res['error'] ?? 'Terjadi kesalahan.'));
            }
        }
        header('Location: ' . APP_URL . '/?page=bot_settings');
        exit;
    }

    // 3. Set Webhook Telegram
    if ($aksi === 'set_webhook') {
        $token = env('TELEGRAM_BOT_TOKEN', '');
        if (empty($token)) {
            setFlash('error', 'TELEGRAM_BOT_TOKEN belum diatur di file .env.');
        } else {
            $webhookUrl = APP_URL . '/bot_telegram.php';
            $secret = env('TELEGRAM_WEBHOOK_SECRET', '');
            $setApi = "https://api.telegram.org/bot{$token}/setWebhook?url=" . urlencode($webhookUrl);
            if (!empty($secret)) {
                $setApi .= '&secret_token=' . urlencode($secret);
            }
            $ch = curl_init($setApi);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT => 10
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);
            $json = json_decode((string)$resp, true);
            if (!empty($json['ok'])) {
                setFlash('success', 'Webhook Telegram berhasil dipasang: ' . $webhookUrl);
            } else {
                setFlash('error', 'Telegram API Error: ' . ($json['description'] ?? 'Gagal pasang webhook.'));
            }
        }
        header('Location: ' . APP_URL . '/?page=bot_settings');
        exit;
    }

    // 4. Hapus Webhook Telegram
    if ($aksi === 'delete_webhook') {
        $token = env('TELEGRAM_BOT_TOKEN', '');
        if (!empty($token)) {
            $delApi = "https://api.telegram.org/bot{$token}/deleteWebhook";
            $ch = curl_init($delApi);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT => 10
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);
            $json = json_decode((string)$resp, true);
            if (!empty($json['ok'])) {
                setFlash('success', 'Webhook Telegram berhasil dihapus.');
            } else {
                setFlash('error', 'Error: ' . ($json['description'] ?? 'Gagal hapus webhook.'));
            }
        }
        header('Location: ' . APP_URL . '/?page=bot_settings');
        exit;
    }
}

// Cek Status Konfigurasi Saat Ini
$teleToken  = env('TELEGRAM_BOT_TOKEN', '');
$teleChatId = env('TELEGRAM_DEFAULT_CHAT_ID', '');
$waToken    = env('WA_GATEWAY_TOKEN', '');
$waUrl      = env('WA_GATEWAY_URL', 'https://api.fonnte.com/send');
$waNumber   = env('WA_DEFAULT_NUMBER', '');
$geminiKey  = env('GEMINI_API_KEY', '');
$geminiModel = env('GEMINI_MODEL', 'gemini-1.5-flash');
?>

<div class="page-header" style="margin-bottom: 24px;">
  <div>
    <h1 style="font-size: 1.6rem; font-weight: 800; color: var(--text); margin-bottom: 4px;">
      <i class="fas fa-robot" style="color: var(--accent); margin-right: 8px;"></i>Integrasi & Pengaturan Bot
    </h1>
    <p style="color: var(--text-muted); font-size: 0.9rem;">
      Kelola Bot Notifikasi (Telegram & WhatsApp), Asisten AI Web, serta Chatbot Interaktif MANRIS.
    </p>
  </div>
</div>

<!-- Status Layanan Bot -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 28px;">
  <!-- Card Telegram -->
  <div style="background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; box-shadow: var(--shadow-sm);">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
      <div style="display: flex; align-items: center; gap: 10px;">
        <div style="width: 40px; height: 40px; border-radius: 10px; background: rgba(0, 136, 204, 0.12); color: #0088cc; display: flex; align-items: center; justify-content: center; font-size: 20px;">
          <i class="fab fa-telegram-plane"></i>
        </div>
        <div>
          <h3 style="font-size: 1rem; font-weight: 700; margin: 0;">Telegram Bot</h3>
          <span style="font-size: 0.78rem; color: var(--text-muted);">Notifikasi & Interaktif</span>
        </div>
      </div>
      <?php if (!empty($teleToken)): ?>
        <span class="badge" style="background: #dcfce7; color: #166534; font-size: 0.75rem; padding: 4px 10px; border-radius: 20px;">Aktif</span>
      <?php else: ?>
        <span class="badge" style="background: #fee2e2; color: #991b1b; font-size: 0.75rem; padding: 4px 10px; border-radius: 20px;">Belum Diatur</span>
      <?php endif; ?>
    </div>
    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 8px;">
      Token: <?= !empty($teleToken) ? '••••' . substr($teleToken, -6) : '<em>(Belum diisi di .env)</em>' ?>
    </p>
    <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">
      Default Chat ID: <?= !empty($teleChatId) ? htmlspecialchars($teleChatId) : '<em>(Belum diatur)</em>' ?>
    </p>
  </div>

  <!-- Card WhatsApp -->
  <div style="background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; box-shadow: var(--shadow-sm);">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
      <div style="display: flex; align-items: center; gap: 10px;">
        <div style="width: 40px; height: 40px; border-radius: 10px; background: rgba(37, 211, 102, 0.12); color: #25d366; display: flex; align-items: center; justify-content: center; font-size: 20px;">
          <i class="fab fa-whatsapp"></i>
        </div>
        <div>
          <h3 style="font-size: 1rem; font-weight: 700; margin: 0;">WhatsApp Gateway</h3>
          <span style="font-size: 0.78rem; color: var(--text-muted);">Notifikasi Pengingat</span>
        </div>
      </div>
      <?php if (!empty($waToken)): ?>
        <span class="badge" style="background: #dcfce7; color: #166534; font-size: 0.75rem; padding: 4px 10px; border-radius: 20px;">Aktif</span>
      <?php else: ?>
        <span class="badge" style="background: #fee2e2; color: #991b1b; font-size: 0.75rem; padding: 4px 10px; border-radius: 20px;">Belum Diatur</span>
      <?php endif; ?>
    </div>
    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 8px;">
      Gateway: <?= htmlspecialchars($waUrl) ?>
    </p>
    <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">
      Default No HP: <?= !empty($waNumber) ? htmlspecialchars($waNumber) : '<em>(Belum diatur)</em>' ?>
    </p>
  </div>

  <!-- Card AI Assistant -->
  <div style="background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; box-shadow: var(--shadow-sm);">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
      <div style="display: flex; align-items: center; gap: 10px;">
        <div style="width: 40px; height: 40px; border-radius: 10px; background: rgba(124, 58, 237, 0.12); color: #7c3aed; display: flex; align-items: center; justify-content: center; font-size: 20px;">
          <i class="fas fa-brain"></i>
        </div>
        <div>
          <h3 style="font-size: 1rem; font-weight: 700; margin: 0;">AI Asisten Web</h3>
          <span style="font-size: 0.78rem; color: var(--text-muted);">Gemini AI / Fallback KB</span>
        </div>
      </div>
      <?php if (!empty($geminiKey)): ?>
        <span class="badge" style="background: #dcfce7; color: #166534; font-size: 0.75rem; padding: 4px 10px; border-radius: 20px;">Gemini Live</span>
      <?php else: ?>
        <span class="badge" style="background: #e0f2fe; color: #0369a1; font-size: 0.75rem; padding: 4px 10px; border-radius: 20px;">Knowledge Base Active</span>
      <?php endif; ?>
    </div>
    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 8px;">
      Model: <?= htmlspecialchars($geminiModel) ?>
    </p>
    <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">
      Floating Widget: <strong>Aktif di pojok kanan bawah</strong>
    </p>
  </div>
</div>

<!-- Forms & Tools Grid -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 24px;">

  <!-- Form Uji Coba Telegram -->
  <div style="background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; box-shadow: var(--shadow-sm);">
    <h2 style="font-size: 1.15rem; font-weight: 700; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
      <i class="fab fa-telegram" style="color: #0088cc;"></i> Uji Coba Kirim Notifikasi Telegram
    </h2>
    <form method="POST" action="<?= APP_URL ?>/?page=bot_settings">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="aksi" value="test_telegram">

      <div style="margin-bottom: 16px;">
        <label style="display: block; font-size: 0.88rem; font-weight: 600; margin-bottom: 6px;">Chat ID / Channel ID Tujuan:</label>
        <input type="text" name="chat_id" value="<?= htmlspecialchars($teleChatId) ?>" placeholder="Contoh: 123456789 atau -100xxxxxxx" required
               style="width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface2); color: var(--text);">
        <small style="color: var(--text-muted); font-size: 0.78rem;">Chat ID akun Anda atau ID grup/channel tempat bot dimasukkan.</small>
      </div>

      <div style="margin-bottom: 16px;">
        <label style="display: block; font-size: 0.88rem; font-weight: 600; margin-bottom: 6px;">Pesan Uji Coba:</label>
        <textarea name="pesan" rows="3" required style="width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface2); color: var(--text);">Halo! Ini adalah notifikasi pengujian dari Sistem Manajemen Risiko (MANRIS).</textarea>
      </div>

      <button type="submit" class="btn btn-primary" style="padding: 10px 20px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px;">
        <i class="fas fa-paper-plane"></i> Kirim Pesan Uji Coba
      </button>
    </form>

    <hr style="border: none; border-top: 1px solid var(--border); margin: 24px 0;">

    <h3 style="font-size: 1rem; font-weight: 700; margin-bottom: 12px;">Webhook Bot Telegram (Dua Arah)</h3>
    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 12px;">
      URL Webhook aplikasi Anda: <br>
      <code style="display: block; padding: 8px 12px; background: var(--surface2); border: 1px solid var(--border); border-radius: 6px; margin-top: 4px; word-break: break-all; color: var(--primary);">
        <?= htmlspecialchars(APP_URL . '/bot_telegram.php') ?>
      </code>
    </p>

    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
      <form method="POST" action="<?= APP_URL ?>/?page=bot_settings">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="aksi" value="set_webhook">
        <button type="submit" class="btn btn-secondary" style="padding: 8px 16px; font-size: 0.85rem;" title="Hubungkan URL ke server Telegram">
          <i class="fas fa-link"></i> Pasang Webhook
        </button>
      </form>
      <form method="POST" action="<?= APP_URL ?>/?page=bot_settings">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="aksi" value="delete_webhook">
        <button type="submit" class="btn btn-danger" style="padding: 8px 16px; font-size: 0.85rem;" onclick="return confirm('Yakin ingin melepas webhook Telegram?');">
          <i class="fas fa-unlink"></i> Lepas Webhook
        </button>
      </form>
      <a href="<?= APP_URL ?>/bot_telegram.php?test=1&cmd=/ringkasan" target="_blank" class="btn" style="padding: 8px 16px; font-size: 0.85rem; background: var(--surface2); border: 1px solid var(--border); color: var(--text);">
        <i class="fas fa-terminal"></i> Simulasi Perintah Bot
      </a>
    </div>
  </div>

  <!-- Form Uji Coba WhatsApp & Panduan -->
  <div style="background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; box-shadow: var(--shadow-sm);">
    <h2 style="font-size: 1.15rem; font-weight: 700; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
      <i class="fab fa-whatsapp" style="color: #25d366;"></i> Uji Coba Kirim Notifikasi WhatsApp
    </h2>
    <form method="POST" action="<?= APP_URL ?>/?page=bot_settings">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="aksi" value="test_whatsapp">

      <div style="margin-bottom: 16px;">
        <label style="display: block; font-size: 0.88rem; font-weight: 600; margin-bottom: 6px;">Nomor HP Tujuan (WhatsApp):</label>
        <input type="text" name="nomor_wa" value="<?= htmlspecialchars($waNumber) ?>" placeholder="Contoh: 081234567890 atau 6281234567890" required
               style="width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface2); color: var(--text);">
      </div>

      <div style="margin-bottom: 16px;">
        <label style="display: block; font-size: 0.88rem; font-weight: 600; margin-bottom: 6px;">Pesan WhatsApp:</label>
        <textarea name="pesan" rows="3" required style="width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface2); color: var(--text);">Halo! Ini adalah pesan pengujian notifikasi WhatsApp dari Sistem Manajemen Risiko (MANRIS).</textarea>
      </div>

      <button type="submit" class="btn btn-success" style="padding: 10px 20px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; background: #16a34a; color: white;">
        <i class="fab fa-whatsapp"></i> Kirim WhatsApp Test
      </button>
    </form>

    <hr style="border: none; border-top: 1px solid var(--border); margin: 24px 0;">

    <h3 style="font-size: 1rem; font-weight: 700; margin-bottom: 8px;">📖 Panduan Menyiapkan Kredensial</h3>
    <div style="font-size: 0.85rem; color: var(--text-muted); line-height: 1.6;">
      <p style="margin-bottom: 8px;">Tambahkan parameter berikut ke file konfigurasi <code>.env</code> Anda:</p>
      <pre style="background: var(--surface2); border: 1px solid var(--border); padding: 12px; border-radius: 8px; font-size: 0.82rem; overflow-x: auto; color: var(--text);">
# ── Integrasi Telegram Bot ──
TELEGRAM_BOT_TOKEN=123456789:ABCdefGHIjklMNOpqrSTU
TELEGRAM_DEFAULT_CHAT_ID=987654321

# ── Integrasi WhatsApp (Contoh Fonnte/Wablas) ──
WA_GATEWAY_URL=https://api.fonnte.com/send
WA_GATEWAY_TOKEN=token_anda_disini
WA_DEFAULT_NUMBER=081234567890

# ── Integrasi AI Gemini (Opsional, Fallback Tersedia) ──
GEMINI_API_KEY=AIzaSyxxxxxxxxxxxx
# Pilihan Model: gemini-1.5-flash, gemini-2.0-flash, gemini-2.5-flash, gemini-3.8-flash, dll.
GEMINI_MODEL=gemini-1.5-flash
      </pre>
      <ol style="margin-left: 18px; margin-top: 10px;">
        <li>Buka Telegram, cari <strong>@BotFather</strong>, ketik <code>/newbot</code> untuk mendapatkan token.</li>
        <li>Kirim pesan apa saja ke bot Anda, lalu cari Chat ID via <strong>@userinfobot</strong>.</li>
        <li>Simpan ke <code>.env</code>, lalu klik <strong>Pasang Webhook</strong> di atas.</li>
      </ol>
    </div>
  </div>

</div>
