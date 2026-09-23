<?php
require 'includes/config.php';
$db = getDB();

// Reset Profil Risiko
$db->query("UPDATE profil_risiko SET status = 'Draft'");
$rowsProfil = $db->affected_rows;

// Reset KKPR dan KKPMR 
$db->query("UPDATE kkpr_header SET status_kkpr = 'Draft', status_kkpmr = 'Draft'");
$rowsKkpr = $db->affected_rows;

echo "<h3>Berhasil!</h3>";
echo "Mereset $rowsProfil dokumen Profil Risiko menjadi Draft.<br>";
echo "Mereset $rowsKkpr dokumen KKPR/KKPMR menjadi Draft.<br>";
echo "<br><b style='color:red'>PENTING:</b> Harap segera hapus file <code>reset_draft.php</code> ini dari hosting Anda untuk menjaga keamanan sistem.";
?>