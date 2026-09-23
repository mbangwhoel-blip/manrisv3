<?php
/**
 * MODUL LOG AKTIVITAS
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
requireRole('Admin');
$db = getDB();

$page_num = max(1,(int)($_GET['p'] ?? 1));
$perPage  = 25;
$fModul   = trim($_GET['modul'] ?? '');
$fAksi    = trim($_GET['aksi'] ?? '');
$fUser    = (int)($_GET['user'] ?? 0);

$where = ['1=1']; $params=[]; $types='';
if ($fModul) { $where[]='l.modul=?'; $params[]=$fModul; $types.='s'; }
if ($fAksi)  { $where[]='l.aksi=?'; $params[]=$fAksi; $types.='s'; }
if ($fUser)  { $where[]='l.id_user=?'; $params[]=$fUser; $types.='i'; }
$whereStr = implode(' AND ', $where);

$cnt = $db->prepare("SELECT COUNT(*) FROM log_aktivitas l WHERE $whereStr");
if ($types) $cnt->bind_param($types,...$params); $cnt->execute();
$total = $cnt->get_result()->fetch_row()[0]; $cnt->close();

$pg = paginate($total, $perPage, $page_num, APP_URL.'/?page=log&modul='.urlencode($fModul).'&aksi='.urlencode($fAksi).'&user='.$fUser);

$sql = "SELECT l.*, u.nama AS user_nama, u.role AS user_role FROM log_aktivitas l JOIN users u ON l.id_user=u.id WHERE $whereStr ORDER BY l.created_at DESC LIMIT ? OFFSET ?";
$dTypes = $types.'ii'; $dParams = array_merge($params,[$perPage,$pg['offset']]);
$stmt = $db->prepare($sql); $stmt->bind_param($dTypes,...$dParams); $stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$users = $db->query('SELECT id, nama FROM users ORDER BY nama')->fetch_all(MYSQLI_ASSOC);
?>

<div class="page-header-row">
  <div class="page-header">
    <h1 class="page-title"><i class="fas fa-history" style="color:var(--primary)"></i> Log Aktivitas</h1>
    <p class="page-sub">Rekam jejak semua aktivitas pengguna dalam sistem</p>
  </div>
</div>

<form class="filter-bar" method="GET" action="<?= APP_URL ?>/">
  <input type="hidden" name="page" value="log">
  <div class="form-group">
    <label class="form-label">Modul</label>
    <select name="modul" class="form-control">
      <option value="">Semua Modul</option>
      <?php foreach(['auth','risiko','mitigasi','user','laporan'] as $m): ?>
      <option value="<?= $m ?>" <?= $fModul===$m?'selected':'' ?>><?= ucfirst($m) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label class="form-label">Aksi</label>
    <select name="aksi" class="form-control">
      <option value="">Semua Aksi</option>
      <?php foreach(['LOGIN','LOGOUT','CREATE','UPDATE','DELETE','LOGIN_GAGAL'] as $a): ?>
      <option value="<?= $a ?>" <?= $fAksi===$a?'selected':'' ?>><?= $a ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label class="form-label">User</label>
    <select name="user" class="form-control">
      <option value="">Semua User</option>
      <?php foreach($users as $u): ?>
      <option value="<?= $u['id'] ?>" <?= $fUser==$u['id']?'selected':'' ?>><?= xss($u['nama']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div style="display:flex;gap:8px;align-self:flex-end;padding-bottom:1px">
    <button type="submit" class="btn btn-accent"><i class="fas fa-filter"></i> Filter</button>
    <a href="<?= APP_URL ?>/?page=log" class="btn btn-outline"><i class="fas fa-times"></i></a>
  </div>
</form>

<div class="card">
  <div class="card-header">
    <span class="card-title">Log Aktivitas <span style="color:var(--text-muted);font-weight:400">(<?= $total ?>)</span></span>
    <div style="display:flex;gap:6px">
      <button class="btn btn-xs btn-outline" onclick="toggleTimeline()" id="timelineBtn"><i class="fas fa-stream"></i> Timeline</button>
    </div>
  </div>
  <!-- Timeline View -->
  <div id="timelineView" style="display:none;padding:20px 24px">
    <?php if(empty($rows)): ?>
      <div class="empty-state"><div class="empty-state-icon"><i class="fas fa-history"></i></div><div class="empty-state-title">Belum ada log</div></div>
    <?php else: ?>
      <div style="position:relative;padding-left:28px">
        <div style="position:absolute;left:10px;top:0;bottom:0;width:2px;background:var(--border)"></div>
        <?php foreach($rows as $l):
          $aksiColors = ['CREATE'=>'#16a34a','UPDATE'=>'#0284c7','DELETE'=>'#dc2626','LOGIN'=>'#1e3a5f','LOGOUT'=>'#64748b','APPROVE'=>'#16a34a','REJECT'=>'#dc2626'];
          $color = $aksiColors[$l['aksi']] ?? '#64748b';
        ?>
        <div style="position:relative;padding-bottom:20px">
          <div style="position:absolute;left:-22px;top:2px;width:12px;height:12px;border-radius:50%;background:<?= $color ?>;border:2px solid var(--surface);box-shadow:0 0 0 2px var(--border)"></div>
          <div style="font-size:.72rem;color:var(--text-muted)"><?= date('d M Y, H:i', strtotime($l['created_at'])) ?></div>
          <div style="font-size:.85rem;margin-top:2px">
            <strong><?= xss($l['user_nama']) ?></strong>
            <span class="badge" style="background:<?= $color ?>20;color:<?= $color ?>;font-size:.68rem;margin:0 4px"><?= xss($l['aksi']) ?></span>
            <span style="color:var(--text-muted)">·</span>
            <code style="font-size:.72rem"><?= xss($l['modul']) ?></code>
          </div>
          <div style="font-size:.78rem;color:var(--text-muted);margin-top:2px"><?= xss($l['deskripsi']?:'-') ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <!-- Table View -->
  <div id="tableView" class="table-responsive">
    <table class="data-table">
      <thead><tr><th>Waktu</th><th>User</th><th>Role</th><th>Aksi</th><th>Modul</th><th>Deskripsi</th><th>Perubahan</th><th>IP</th></tr></thead>
      <tbody>
      <?php if(empty($rows)): ?>
        <tr><td colspan="8"><div class="empty-state"><i class="fas fa-history"></i><h3>Belum ada log</h3></div></td></tr>
      <?php else: ?>
        <?php foreach($rows as $l): ?>
        <?php
          $aksiColors = ['CREATE'=>'badge-success','UPDATE'=>'badge-info','DELETE'=>'badge-danger','LOGIN'=>'badge-primary','LOGOUT'=>'badge-secondary','LOGIN_GAGAL'=>'badge-warning','LOGIN_LOCKED'=>'badge-danger','RESTORE'=>'badge-warning','APPROVE'=>'badge-success','REJECT'=>'badge-danger'];
          $aksiCls = $aksiColors[$l['aksi']] ?? 'badge-secondary';
        ?>
        <tr>
          <td style="font-size:.78rem;white-space:nowrap;color:var(--text-muted)"><?= date('d/m/Y H:i:s', strtotime($l['created_at'])) ?></td>
          <td style="font-weight:600;font-size:.83rem"><?= xss($l['user_nama']) ?></td>
          <td><span class="badge badge-secondary" style="font-size:.7rem"><?= xss($l['user_role']) ?></span></td>
          <td><span class="badge <?= $aksiCls ?>"><?= xss($l['aksi']) ?></span></td>
          <td><code style="font-size:.75rem"><?= xss($l['modul']) ?></code></td>
          <td style="font-size:.82rem;max-width:250px"><?= xss($l['deskripsi']?:'-') ?></td>
          <td style="font-size:.75rem;max-width:300px">
            <?php if (!empty($l['data_lama']) || !empty($l['data_baru'])): ?>
              <details>
                <summary style="cursor:pointer;color:var(--accent)">Lihat detail</summary>
                <div style="margin-top:6px;display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:.72rem">
                  <?php if (!empty($l['data_lama'])): ?>
                    <div>
                      <strong style="color:var(--danger)">Sebelum:</strong>
                      <pre style="background:var(--surface);padding:4px 6px;border-radius:4px;overflow-x:auto;max-height:150px;margin:2px 0"><?= xss(json_encode(json_decode($l['data_lama']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: $l['data_lama']) ?></pre>
                    </div>
                  <?php endif; ?>
                  <?php if (!empty($l['data_baru'])): ?>
                    <div>
                      <strong style="color:var(--success)">Sesudah:</strong>
                      <pre style="background:var(--surface);padding:4px 6px;border-radius:4px;overflow-x:auto;max-height:150px;margin:2px 0"><?= xss(json_encode(json_decode($l['data_baru']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: $l['data_baru']) ?></pre>
                    </div>
                  <?php endif; ?>
                </div>
              </details>
            <?php else: ?>
              <span style="color:var(--text-muted)">-</span>
            <?php endif; ?>
          </td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= xss($l['ip_address']?:'-') ?></td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer" id="logFooter">
    <?php if($pg['total_pages'] > 1): ?>
    <div class="pagination">
      <?php
        $base = APP_URL.'/?page=log&modul='.urlencode($fModul).'&aksi='.urlencode($fAksi).'&user='.$fUser;
        echo '<a class="page-btn '.($pg['current']<=1?'disabled':'').'" href="'.$base.'&p=1" title="Halaman Pertama"><i class="fas fa-angles-left"></i></a>';
        echo '<a class="page-btn '.($pg['current']<=1?'disabled':'').'" href="'.$base.'&p='.($pg['current']-1).'"><i class="fas fa-chevron-left"></i></a>';
        for($i=max(1,$pg['current']-2);$i<=min($pg['total_pages'],$pg['current']+2);$i++){
          echo '<a class="page-btn '.($i==$pg['current']?'active':'').'" href="'.$base.'&p='.$i.'">'.$i.'</a>';
        }
        echo '<a class="page-btn '.($pg['current']>=$pg['total_pages']?'disabled':'').'" href="'.$base.'&p='.($pg['current']+1).'"><i class="fas fa-chevron-right"></i></a>';
        echo '<a class="page-btn '.($pg['current']>=$pg['total_pages']?'disabled':'').'" href="'.$base.'&p='.$pg['total_pages'].'" title="Halaman Terakhir"><i class="fas fa-angles-right"></i></a>';
      ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
function toggleTimeline() {
  const tl = document.getElementById('timelineView');
  const tv = document.getElementById('tableView');
  const ft = document.getElementById('logFooter');
  const btn = document.getElementById('timelineBtn');
  if (tl.style.display === 'none') {
    tl.style.display = 'block';
    tv.style.display = 'none';
    ft.style.display = 'none';
    btn.innerHTML = '<i class="fas fa-table"></i> Tabel';
  } else {
    tl.style.display = 'none';
    tv.style.display = 'block';
    ft.style.display = '';
    btn.innerHTML = '<i class="fas fa-stream"></i> Timeline';
  }
}
</script>
