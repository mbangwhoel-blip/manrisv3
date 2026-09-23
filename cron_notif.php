<?php
/**
 * CRON JOB — Notifikasi Mitigasi Deadline
 *
 * Jalankan via cron setiap hari:
 *   0 8 * * * php /path/to/manris/cron_notif.php
 *   atau via browser: https://domain/manris/cron_notif.php
 *     dengan header X-Cron-Key: CRON_SECRET_KEY (atau ?key=CRON_SECRET_KEY)
 *
 * Fungsi: kirim notifikasi untuk mitigasi yang deadline-nya ≤ 3 hari & belum selesai.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

// Auth via CLI atau secret key (untuk web trigger).
// Key dikirim via header X-Cron-Key (disarankan — tidak terekam di access log).
// ?key=... masih didukung untuk kompatibilitas, tapi header lebih disarankan.
if (PHP_SAPI !== 'cli') {
    $key = $_SERVER['HTTP_X_CRON_KEY'] ?? $_GET['key'] ?? '';
    $expectedKey = getenv('CRON_SECRET_KEY') ?: '';
    if ($expectedKey === '' || !hash_equals($expectedKey, $key)) {
        http_response_code(403);
        echo 'Forbidden — invalid or missing cron secret key.';
        exit;
    }
}

$db = getDB();

// Cari mitigasi yang deadline sampai 3 hari ke depan atau sudah terlambat.
$soon = $db->query("
    SELECT m.id, m.aksi, m.pic, m.pic_user_id, m.deadline, m.status, m.progress,
           r.id AS risiko_id, r.kode_risiko, r.nama_risiko, r.id_user_input
    FROM mitigasi m
    JOIN risiko r ON m.id_risiko = r.id
    WHERE m.status != 'Selesai'
      AND m.deadline IS NOT NULL
      AND m.deadline <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
");

$sent = 0;
while ($m = $soon->fetch_assoc()) {
    $userId = (int)($m['pic_user_id'] ?: $m['id_user_input']);
    if ($userId <= 0) continue;

    $daysLeft = (strtotime($m['deadline']) - strtotime(date('Y-m-d'))) / 86400;
    if ($daysLeft < 0) $urgency = 'TERLAMBAT ' . abs((int)$daysLeft) . ' hari';
    elseif ($daysLeft === 0.0) $urgency = 'HARI INI';
    else $urgency = ceil($daysLeft) . ' hari lagi';

    notifikasi(
        $userId,
        'mitigasi_deadline',
        'Deadline Mitigasi: ' . $urgency,
        "Mitigasi berikut akan jatuh tempo dalam $urgency:\n\n" .
        "Risiko: " . $m['kode_risiko'] . ' - ' . $m['nama_risiko'] . "\n" .
        "Aksi: " . $m['aksi'] . "\n" .
        "PIC: " . $m['pic'] . "\n" .
        "Deadline: " . tglIndo($m['deadline']) . "\n\n" .
         "Progress saat ini: " . (int)($m['progress'] ?? 0) . "%\n\nSegera selesaikan atau perbarui status.",
        APP_URL . '/?page=mitigasi&risiko_id=' . $m['risiko_id']
    );
    $sent++;
}

$resp = ['ok' => true, 'sent' => $sent, 'timestamp' => date('Y-m-d H:i:s')];
if (PHP_SAPI === 'cli') {
    echo "Cron notifikasi: $sent notifikasi dikirim.\n";
} else {
    header('Content-Type: application/json');
    echo json_encode($resp);
}
