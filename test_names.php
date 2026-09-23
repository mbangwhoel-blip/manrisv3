<?php
require 'includes/functions.php';
$db = getDB();
$res = $db->query("SELECT id, kode_risiko, nama_risiko FROM risiko WHERE nama_risiko LIKE '%Keterlambatan penyusunan%' OR nama_risiko LIKE '%Kinerja Anggaran Labkesmas%'");
while($r = $res->fetch_assoc()) {
    echo $r['id'] . ' | ' . $r['kode_risiko'] . ' | ' . $r['nama_risiko'] . "\n";
}
