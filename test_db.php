<?php
require 'includes/functions.php';
$db = getDB();
$res = $db->query("SHOW CREATE TABLE risiko");
$row = $res->fetch_assoc();
echo $row['Create Table'];
