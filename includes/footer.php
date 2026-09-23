  </main><!-- end .content -->
  <footer style="text-align: center; padding: 20px; font-size: 0.85rem; color: var(--text-muted); border-top: 1px solid var(--border); margin-top: auto; background: var(--surface);">
    &copy; 2026 Balai Besar Laboratorium Kesehatan Lingkungan v.2
  </footer>
</div><!-- end .main-wrapper -->

<!-- Toast Container -->
<div id="toastContainer" class="toast-container"></div>


<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= @filemtime(__DIR__ . '/../assets/js/main.js') ?: 0 ?>"></script>
<?php if (isLoggedIn()): ?>
<script>
  window.MANRIS_USER_ID = <?= json_encode((int)($_SESSION['user_id'] ?? 0)) ?>;
  window.MANRIS_SESSION_TOKEN = <?= json_encode(substr(hash('sha256', session_id() . (string)($_SESSION['user_id'] ?? 0) . APP_KEY), 0, 16)) ?>;
</script>
<script src="<?= APP_URL ?>/assets/js/ai_chat_widget.js?v=<?= @filemtime(__DIR__ . '/../assets/js/ai_chat_widget.js') ?: 0 ?>"></script>
<?php else: ?>
<script>
  try {
    for (var i = sessionStorage.length - 1; i >= 0; i--) {
      var k = sessionStorage.key(i);
      if (k && k.indexOf('manris_ai_chat') !== -1) sessionStorage.removeItem(k);
    }
    for (var j = localStorage.length - 1; j >= 0; j--) {
      var lk = localStorage.key(j);
      if (lk && lk.indexOf('manris_ai_chat') !== -1) localStorage.removeItem(lk);
    }
  } catch(e) {}
</script>
<?php endif; ?>
<?php
if (isset($db)) {
    $globalUsersWithNip = $db->query("SELECT nama, nip FROM users WHERE aktif=1 AND role != 'Admin' AND nip IS NOT NULL AND nip != ''")->fetch_all(MYSQLI_ASSOC) ?: [];
    echo '<datalist id="listUsersWithNip">';
    foreach($globalUsersWithNip as $u) {
        echo '<option value="'.htmlspecialchars($u['nama']).'" data-nip="'.htmlspecialchars($u['nip']).'"></option>';
    }
    echo '</datalist>';
}
?>
</body>
</html>
