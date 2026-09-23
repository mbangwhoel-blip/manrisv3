<?php
/**
 * MODUL DIAKGNOSTIK PERSETUJUAN
 * Bantu cek kenapa dokumen pengajuan tidak muncul di halaman Pimpinan.
 * Akses: Admin saja. URL: /?page=diagnostik_persetujuan
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
requireRole('Admin');
$db = getDB();

// Ambil data diagnosis
$myRole = $_SESSION['user_role'] ?? '(belum login)';
$myUid  = (int)($_SESSION['user_id'] ?? 0);

// Daftar user dengan role terkait pimpinan (cek typo/whitespace)
$pimpinanUsers = $db->query("SELECT id, nama, username, role, aktif FROM users WHERE role LIKE '%impin%' OR role LIKE '%epala%' OR role LIKE '%PIMP%' ORDER BY role, id")->fetch_all(MYSQLI_ASSOC) ?: [];

// Distribusi role semua user
$roleDist = $db->query("SELECT role, COUNT(*) AS jml FROM users GROUP BY role ORDER BY jml DESC")->fetch_all(MYSQLI_ASSOC) ?: [];

// Status profil_risiko
$profilStatus = $db->query("SELECT status, COUNT(*) AS jml FROM profil_risiko GROUP BY status ORDER BY jml DESC")->fetch_all(MYSQLI_ASSOC) ?: [];

// Status kkpr
$kkprStatus = $db->query("SELECT status_kkpr, COUNT(*) AS jml FROM kkpr_header GROUP BY status_kkpr ORDER BY jml DESC")->fetch_all(MYSQLI_ASSOC) ?: [];

// Status kkpmr
$kkpmrStatus = $db->query("SELECT status_kkpmr, COUNT(*) AS jml FROM kkpr_header GROUP BY status_kkpmr ORDER BY jml DESC")->fetch_all(MYSQLI_ASSOC) ?: [];

// Profil yang menunggu persetujuan (detail)
$pendingDetail = $db->query("SELECT p.id, p.tahun, p.unit_pemilik_risiko, p.status, p.created_by, u.nama AS pengaju FROM profil_risiko p LEFT JOIN users u ON p.created_by=u.id WHERE p.status='Menunggu Persetujuan' ORDER BY p.id DESC LIMIT 20")->fetch_all(MYSQLI_ASSOC) ?: [];

$ok = function($v) { return '<span style="color:var(--success)">'.htmlspecialchars((string)$v).'</span>'; };
$bad = function($v) { return '<span style="color:var(--danger)">'.htmlspecialchars((string)$v).'</span>'; };
?>
<div class="card" style="margin-bottom:16px;border-left:4px solid var(--accent)">
  <div class="card-body" style="padding:16px 20px">
    <h3 style="margin:0 0 4px"><i class="fas fa-stethoscope" style="color:var(--accent)"></i> Diagnostik Persetujuan</h3>
    <p style="color:var(--text-muted);font-size:.82rem;margin:0">Cek konsistensi role & status untuk temukan kenapa dokumen pengajuan tidak muncul di Pimpinan.</p>
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-header"><span class="card-title"><i class="fas fa-user-shield"></i> Sesi Anda</span></div>
  <div class="card-body" style="padding:12px 20px;font-size:.85rem;line-height:1.8">
    <strong>Role sesi:</strong> <?= $myRole === 'Pimpinan' ? $ok($myRole) : $bad($myRole) ?>
    &nbsp;&middot;&nbsp; <strong>User ID:</strong> <?= (int)$myUid ?>
    <br><strong>hasRole('Pimpinan'):</strong> <?= hasRole('Pimpinan') ? $ok('true') : $bad('false') ?>
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-header"><span class="card-title"><i class="fas fa-users"></i> Distribusi Role User di DB</span></div>
  <div class="card-body" style="padding:0">
    <table class="data-table no-datatable">
      <thead><tr><th>Role</th><th style="text-align:center">Jumlah</th><th>Cocok 'Pimpinan'?</th></tr></thead>
      <tbody>
      <?php if (!$roleDist): ?><tr><td colspan="3" style="text-align:center;padding:14px;color:var(--text-muted)">Tidak ada user</td></tr>
      <?php else: foreach ($roleDist as $r): ?>
        <tr>
          <td><code><?= xss($r['role']) ?></code> <?= $r['role'] !== trim($r['role']) ? '<span style="color:var(--danger)">(ada spasi!)</span>' : '' ?></td>
          <td style="text-align:center"><?= (int)$r['jml'] ?></td>
          <td><?= $r['role'] === 'Pimpinan' ? $ok('YA') : $bad('TIDAK') ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-header"><span class="card-title"><i class="fas fa-user-tie"></i> User dengan role mirip "Pimpinan/Kepala"</span></div>
  <div class="card-body" style="padding:0">
    <table class="data-table no-datatable">
      <thead><tr><th>ID</th><th>Nama</th><th>Username</th><th>Role (exact)</th><th>Aktif</th></tr></thead>
      <tbody>
      <?php if (!$pimpinanUsers): ?><tr><td colspan="5" style="text-align:center;padding:14px;color:var(--text-muted)">Tidak ada user dengan role mengandung "impin"/"epala"/"PIMP". Ini masalahnya — belum ada user ber-role Pimpinan!</td></tr>
      <?php else: foreach ($pimpinanUsers as $u): ?>
        <tr>
          <td><?= (int)$u['id'] ?></td>
          <td><?= xss($u['nama']) ?></td>
          <td><?= xss($u['username']) ?></td>
          <td><code><?= xss($u['role']) ?></code> <?= $u['role'] === 'Pimpinan' ? $ok('OK') : $bad('BUKAN "Pimpinan" exact!') ?></td>
          <td><?= (int)$u['aktif'] === 1 ? $ok('Aktif') : $bad('Nonaktif') ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-header"><span class="card-title"><i class="fas fa-file-signature"></i> Status Profil Risiko di DB</span></div>
  <div class="card-body" style="padding:0">
    <table class="data-table no-datatable">
      <thead><tr><th>Status</th><th style="text-align:center">Jumlah</th><th>Akan muncul di Persetujuan?</th></tr></thead>
      <tbody>
      <?php if (!$profilStatus): ?><tr><td colspan="3" style="text-align:center;padding:14px;color:var(--text-muted)">Belum ada profil risiko</td></tr>
      <?php else: foreach ($profilStatus as $s): ?>
        <tr>
          <td><code><?= xss($s['status']) ?></code></td>
          <td style="text-align:center"><?= (int)$s['jml'] ?></td>
          <td><?= $s['status'] === 'Menunggu Persetujuan' ? $ok('YA') : '<span style="color:var(--text-muted)">Tidak</span>' ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (!empty($pendingDetail)): ?>
<div class="card" style="margin-bottom:16px;border-left:4px solid var(--warning)">
  <div class="card-header"><span class="card-title" style="color:var(--warning)"><i class="fas fa-paper-plane"></i> Profil "Menunggu Persetujuan" (<?= count($pendingDetail) ?>)</span></div>
  <div class="card-body" style="padding:0">
    <table class="data-table no-datatable">
      <thead><tr><th>ID</th><th>Tahun</th><th>Unit</th><th>Pengaju</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($pendingDetail as $p): ?>
        <tr>
          <td><?= (int)$p['id'] ?></td>
          <td><?= xss($p['tahun']) ?></td>
          <td><?= xss($p['unit_pemilik_risiko']) ?></td>
          <td><?= xss($p['pengaju'] ?? '?') ?></td>
          <td><code><?= xss($p['status']) ?></code></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px">
  <div class="card-header"><span class="card-title"><i class="fas fa-clipboard-list"></i> Status KKPR &amp; KKPMR</span></div>
  <div class="card-body" style="padding:12px 20px;font-size:.85rem;line-height:1.8">
    <strong>KKPR:</strong> <?php foreach ($kkprStatus as $s): ?><code><?= xss($s['status_kkpr']) ?></code>=<?= (int)$s['jml'] ?> &nbsp;<?php endforeach; ?>
    <br><strong>KKPMR:</strong> <?php foreach ($kkpmrStatus as $s): ?><code><?= xss($s['status_kkpmr']) ?></code>=<?= (int)$s['jml'] ?> &nbsp;<?php endforeach; ?>
  </div>
</div>

<div class="card">
  <div class="card-body" style="padding:14px 20px;font-size:.82rem;color:var(--text-muted);line-height:1.7">
    <strong>Kesimpulan otomatis:</strong><br>
    <?php
    $issues = [];
    $hasPimpinan = !empty(array_filter($pimpinanUsers, fn($u) => $u['role'] === 'Pimpinan' && (int)$u['aktif'] === 1));
    if (!$hasPimpinan) $issues[] = 'Tidak ada user aktif dengan role "Pimpinan" (exact). Edit user di Manajemen User → set role = Pimpinan (huruf besar P, tanpa spasi).';
    $hasPending = !empty($pendingDetail);
    if (!$hasPending) $issues[] = 'Tidak ada profil_risiko dengan status "Menunggu Persetujuan". Pastikan Risk Manager sudah klik "Ajukan Persetujuan" dan handler kirim_persetujuan di modules/profil_risiko.php sudah ter-upload (versi baru tanpa modal).';
    if (empty($issues)) {
        echo '<span style="color:var(--success)">Semua kondisi terpenuhi. Dokumen seharusnya muncul di halaman Persetujuan. Bila tidak, kemungkinan OPcache — akses /?clear_cache=1 lalu refresh.</span>';
    } else {
        foreach ($issues as $i) echo '<span style="color:var(--danger)">&bull; '.$i.'</span><br>';
    }
    ?>
  </div>
</div>
