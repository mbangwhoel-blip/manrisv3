<?php
require 'includes/functions.php';
$db = getDB();
$updated = 0;
// Cari semua kode_risiko yang memiliki format A.11-xxxx
$res = $db->query("SELECT id, kode_risiko FROM risiko WHERE kode_risiko REGEXP '^[a-zA-Z]+\\.[0-9]+-[0-9a-f]{4}$'");
while($r = $res->fetch_assoc()) {
    $id = $r['id'];
    $oldKode = $r['kode_risiko'];
    $parts = explode('-', $oldKode);
    $newKode = $parts[0];
    
    // Pastikan kode baru belum ada yang aktif
    $check = $db->query("SELECT id FROM risiko WHERE kode_aktif = '$newKode'");
    if ($check->num_rows == 0) {
        $db->query("UPDATE risiko SET kode_risiko = '$newKode' WHERE id = $id");
        echo "Updated $oldKode to $newKode<br>\n";
        $updated++;
    } else {
        echo "Skipped $oldKode (duplicate $newKode)<br>\n";
    }
}
echo "Total updated: $updated";
