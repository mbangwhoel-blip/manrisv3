<?php
require_once 'includes/config.php';
$db = getDB();

echo "Mulai memperbaiki kode risiko yang mengandung '-' (hash)...<br>\n";

// Ambil semua risiko master yang memiliki '-'
$q = $db->query("SELECT id, kode_risiko FROM risiko WHERE kode_risiko LIKE '%-%'");

$updated = 0;
while ($r = $q->fetch_assoc()) {
    $id = $r['id'];
    $oldKode = $r['kode_risiko'];
    
    // Ekstrak prefix (misal 'A' dari 'A.11-5251')
    $parts = explode('.', $oldKode);
    if (count($parts) < 2) continue;
    
    $prefix = $parts[0]; // 'A'
    
    // Cari angka terbesar untuk prefix ini di master risiko
    $qMax = $db->query("
        SELECT MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(kode_risiko, '-', 1), '.', -1) AS UNSIGNED)) as max_num
        FROM risiko 
        WHERE kode_risiko LIKE '$prefix.%'
    ");
    $maxRow = $qMax->fetch_assoc();
    $nextNum = (int)($maxRow['max_num'] ?? 0) + 1;
    
    $newKode = $prefix . '.' . $nextNum;
    
    echo "Memperbarui <b>$oldKode</b> menjadi <b>$newKode</b>...<br>\n";
    
    // 1. Update Master Risiko
    $db->query("UPDATE risiko SET kode_risiko = '$newKode' WHERE id = $id");
    
    // 2. Update Profil Risiko Detail
    $db->query("UPDATE profil_risiko_detail SET kode_risiko = '$newKode' WHERE id_risiko = $id OR kode_risiko = '$oldKode'");
    
    // 3. Update KKPR Risiko
    $db->query("UPDATE kkpr_risiko SET kode_risiko = '$newKode' WHERE id_risiko = $id OR kode_risiko = '$oldKode'");
    
    $updated++;
}

echo "<br><b>Selesai!</b> Total $updated kode risiko diperbarui.<br>\n";
echo "Silakan kembali ke halaman Profil Risiko dan refresh (F5).";
