<?php
require 'includes/functions.php';
$db = getDB();
$res = $db->query("SELECT id, kode_risiko FROM risiko");
while($r = $res->fetch_assoc()) {
    echo $r['kode_risiko'] . "\n";
}
