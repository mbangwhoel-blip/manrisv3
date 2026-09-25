<?php
/**
 * MODUL MITIGASI — CRUD + Upload Bukti + Tanda Tangan Digital
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$db = getDB();

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { setFlash('error','Token tidak valid'); header('Location: '.APP_URL.'/?page=mitigasi'); exit; }
    requireRole('Admin','Risk Manager','Staff');

    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'simpan') {
        $id       = (int)($_POST['id'] ?? 0);
        $idRisiko = (int)($_POST['id_risiko'] ?? 0);
        $fields   = [
            'aksi'     => trim($_POST['aksi_mitigasi'] ?? ''),
            'pic'      => trim($_POST['pic'] ?? ''),
            'deadline' => $_POST['deadline'] ?? '',
            'status'   => $_POST['status_mitigasi'] ?? 'Belum Mulai',
            'biaya'    => (float)str_replace([',','.'],['','.'],$_POST['biaya'] ?? '0'),
            'catatan'  => trim($_POST['catatan'] ?? ''),
        ];
        if (empty($fields['aksi']) || !$idRisiko) {
            setFlash('error','Aksi mitigasi dan risiko wajib diisi'); header('Location: '.APP_URL.'/?page=mitigasi&risiko_id='.$idRisiko); exit;
        }

        // Pastikan risiko dan mitigasi yang diedit benar-benar terkait.
        $riskCheck = $db->prepare('SELECT id, pemilik_risiko, id_user_input FROM risiko WHERE id=? LIMIT 1');
        $riskCheck->bind_param('i', $idRisiko); $riskCheck->execute();
        $rInfo = $riskCheck->get_result()->fetch_assoc(); $riskCheck->close();
        if (!$rInfo) {
            setFlash('error', 'Risiko tidak ditemukan');
            header('Location: '.APP_URL.'/?page=mitigasi'); exit;
        }
        if (hasRole('Risk Manager') && !canAccessAllRecords()
            && (int)$rInfo['id_user_input'] !== (int)$_SESSION['user_id']) {
            setFlash('error', 'Risk Manager hanya dapat mengelola mitigasi untuk risiko miliknya.');
            header('Location: '.APP_URL.'/?page=mitigasi'); exit;
        }
        $mInfo = null;
        if ($id > 0) {
            $mCheck = $db->prepare('SELECT id, id_risiko, pic FROM mitigasi WHERE id=? LIMIT 1');
            $mCheck->bind_param('i', $id); $mCheck->execute();
            $mInfo = $mCheck->get_result()->fetch_assoc(); $mCheck->close();
            if (!$mInfo || (int)$mInfo['id_risiko'] !== $idRisiko) {
                setFlash('error', 'Data mitigasi tidak valid');
                header('Location: '.APP_URL.'/?page=mitigasi&risiko_id='.$idRisiko); exit;
            }
        }

        // --- IDOR Protection & Authorization ---
        if ($_SESSION['user_role'] === 'Staff') {
            $userName = trim($_SESSION['user_nama'] ?? '');
            
            $isAuthorized = false;
            // Staff owns the risk
            if ($rInfo && strcasecmp(trim($rInfo['pemilik_risiko'] ?? ''), $userName) === 0) $isAuthorized = true;
            // Staff is the original PIC of the mitigation (for update)
            if ($mInfo && strcasecmp(trim($mInfo['pic'] ?? ''), $userName) === 0) $isAuthorized = true;
            // Staff is assigning themselves as the new PIC (for insert/update)
            if (strcasecmp(trim($fields['pic'] ?? ''), $userName) === 0) $isAuthorized = true;

            if (!$isAuthorized) {
                error_log('[manris] IDOR attempt blocked: User ' . $userName . ' tried to modify mitigation ID ' . $id);
                setFlash('error', 'Akses ditolak: Anda bukan PIC dari mitigasi ini ataupun Pemilik Risiko tersebut.');
                header('Location: '.APP_URL.'/?page=mitigasi&risiko_id='.$idRisiko); exit;
            }
        }
        // ---------------------------------------

        // Upload bukti
        $buktiFn = null;
        if (!empty($_FILES['bukti_file']['name'])) {
            $buktiFn = uploadFile($_FILES['bukti_file'], BUKTI_PATH, 'bukti_'.$idRisiko);
            if (!$buktiFn) { setFlash('error','Gagal upload bukti. Pastikan format jpg/png/pdf dan ukuran < 5MB'); header('Location: '.APP_URL.'/?page=mitigasi&risiko_id='.$idRisiko); exit; }
        }

        // Tanda tangan digital (canvas base64) — validasi via GD
        $ttdData = trim($_POST['ttd_data'] ?? '');
        $ttdFn   = null;
        $ttdPath = null;
        if (!empty($ttdData) && str_starts_with($ttdData, 'data:image')) {
            $ttdKey = 'ttd_' . $idRisiko . '_' . time();
            $ttdRes = saveTtdBase64($ttdData, $ttdKey);
            if (!$ttdRes['valid']) {
                setFlash('error', $ttdRes['error'] ?? 'Tanda tangan tidak valid.');
                header('Location: '.APP_URL.'/?page=mitigasi&risiko_id='.$idRisiko); exit;
            }
            $ttdPath = $ttdRes['path'];
            $ttdFn   = basename($ttdPath);
            // Simpan path relatif (uploads/ttd/xxx.png), bukan base64 mentah — lebih aman & hemat DB
            $ttdData = $ttdPath;
        }

        if ($id > 0) {
            // Ambil data file lama untuk dihapus jika diganti
            $old = $db->query("SELECT bukti_file, ttd_file FROM mitigasi WHERE id=$id")->fetch_assoc();
            
            $sql = 'UPDATE mitigasi SET aksi=?,pic=?,deadline=?,status=?,biaya=?,catatan=?';
            $types = 'ssssds'; $params = [$fields['aksi'],$fields['pic'],$fields['deadline'],$fields['status'],$fields['biaya'],$fields['catatan']];
            if ($buktiFn) { 
                $sql.=',bukti_file=?'; $types.='s'; $params[]=$buktiFn; 
                if (!empty($old['bukti_file'])) @unlink(BUKTI_PATH . $old['bukti_file']);
            }
            if ($ttdFn) { 
                $sql.=',ttd_file=?,ttd_data=?'; $types.='ss'; $params[]=$ttdFn; $params[]=$ttdData; 
                if (!empty($old['ttd_file'])) @unlink(TTD_PATH . $old['ttd_file']);
            }
            $sql.=' WHERE id=?'; $types.='i'; $params[]=$id;
            $s=$db->prepare($sql); $s->bind_param($types,...$params); $s->execute(); $s->close();
            logAktivitas('UPDATE','mitigasi',$id,'Update mitigasi ID '.$id);
            setFlash('success','Mitigasi berhasil diperbarui');
        } else {
            $sql='INSERT INTO mitigasi (id_risiko,aksi,pic,deadline,status,biaya,catatan,bukti_file,ttd_file,ttd_data) VALUES (?,?,?,?,?,?,?,?,?,?)';
            $s=$db->prepare($sql); $s->bind_param('issssdssss',$idRisiko,$fields['aksi'],$fields['pic'],$fields['deadline'],$fields['status'],$fields['biaya'],$fields['catatan'],$buktiFn,$ttdFn,$ttdData);
            $s->execute(); $newId=$db->insert_id; $s->close();
            // Update status risiko jika ada mitigasi baru
            $db->query("UPDATE risiko SET status='Ditangani' WHERE id=$idRisiko AND status='Teridentifikasi'");
            logAktivitas('CREATE','mitigasi',$newId,'Tambah mitigasi untuk risiko ID '.$idRisiko);
            setFlash('success','Mitigasi berhasil ditambahkan');
        }
    } elseif ($aksi === 'hapus') {
        requireRole('Admin','Risk Manager');
        $id = (int)($_POST['id'] ?? 0);
        $ownerSql = 'SELECT m.bukti_file, m.ttd_file, r.id_user_input FROM mitigasi m JOIN risiko r ON r.id=m.id_risiko WHERE m.id=?';
        $owner = $db->prepare($ownerSql);
        $owner->bind_param('i', $id); $owner->execute();
        $row = $owner->get_result()->fetch_assoc(); $owner->close();
        if (!$row || (!canAccessAllRecords() && (int)$row['id_user_input'] !== (int)$_SESSION['user_id'])) {
            error_log('[manris] IDOR attempt blocked: User ID ' . (int)($_SESSION['user_id'] ?? 0)
                . ' tried to delete mitigasi ID ' . $id);
            setFlash('error', 'Anda tidak memiliki hak untuk menghapus mitigasi ini.');
            header('Location: '.APP_URL.'/?page=mitigasi'); exit;
        }
        // Hapus file bukti
        if ($row['bukti_file']) @unlink(BUKTI_PATH.$row['bukti_file']);
        if ($row['ttd_file'])   @unlink(TTD_PATH.$row['ttd_file']);
        $del = $db->prepare('DELETE FROM mitigasi WHERE id=?');
        $del->bind_param('i', $id); $del->execute(); $del->close();
        logAktivitas('DELETE','mitigasi',$id,'Hapus mitigasi ID '.$id);
        setFlash('success','Mitigasi dihapus');
    }
    $redir = (int)($_POST['id_risiko'] ?? 0);
    header('Location: '.APP_URL.'/?page=mitigasi'.($redir?"&risiko_id=$redir":'')); exit;
}

// ── Tampilkan mitigasi per risiko atau semua ──────────────────
$risikoId = (int)($_GET['risiko_id'] ?? 0);
$saranAwal = xss($_GET['saran'] ?? '');
$export = $_GET['export'] ?? '';

// Data risiko untuk dropdown (hanya risiko aktif, bukan yang terhapus)
$mitigasi_cond = 'WHERE deleted_at IS NULL';
if (!hasRole('Admin', 'Pimpinan')) $mitigasi_cond .= ' AND id_user_input = ' . (int)$_SESSION['user_id'];
$risikoList = $db->query("SELECT id, kode_risiko, nama_risiko FROM risiko $mitigasi_cond ORDER BY SUBSTRING_INDEX(kode_risiko, '.', 1) ASC, CAST(SUBSTRING_INDEX(kode_risiko, '.', -1) AS UNSIGNED) ASC, kode_risiko ASC")->fetch_all(MYSQLI_ASSOC) ?: [];

$risikoDetail = null;
$mitigasiRows = [];

if ($risikoId) {
    $scope = hasRole('Admin', 'Pimpinan') ? '' : ' AND r.id_user_input = ?';
    $s = $db->prepare('SELECT r.*, r.sumber AS kategori_nama FROM risiko r WHERE r.id=? AND r.deleted_at IS NULL'.$scope);
    if ($scope) { $uid = (int)$_SESSION['user_id']; $s->bind_param('ii',$risikoId,$uid); } else $s->bind_param('i',$risikoId);
    $s->execute();
    $risikoDetail = $s->get_result()->fetch_assoc(); $s->close();

    $s2 = $db->prepare('SELECT m.*, r.nama_risiko, r.kode_risiko, r.level_risiko FROM mitigasi m JOIN risiko r ON m.id_risiko=r.id WHERE m.id_risiko=? AND r.deleted_at IS NULL ORDER BY m.deadline ASC');
    $s2->bind_param('i',$risikoId); $s2->execute();
    $mitigasiRows = $s2->get_result()->fetch_all(MYSQLI_ASSOC); $s2->close();
} else {
    $mitigasi_cond_and = 'WHERE r.deleted_at IS NULL';
    if (!hasRole('Admin', 'Pimpinan')) $mitigasi_cond_and .= ' AND r.id_user_input = ' . (int)$_SESSION['user_id'];
    $mitigasiRows = $db->query("
        SELECT m.*, r.nama_risiko, r.kode_risiko, r.level_risiko
        FROM mitigasi m JOIN risiko r ON m.id_risiko=r.id
        $mitigasi_cond_and
        ORDER BY SUBSTRING_INDEX(r.kode_risiko, '.', 1) ASC, CAST(SUBSTRING_INDEX(r.kode_risiko, '.', -1) AS UNSIGNED) ASC, r.kode_risiko ASC, m.deadline ASC
        LIMIT 200
    ")->fetch_all(MYSQLI_ASSOC) ?: [];
}

// ── Penomoran bertingkat per risiko (mis. 1.1, 1.2) ───────────
// Satu nomor induk per risiko, sub-nomor per aksi. Dipakai untuk
// tampilan tabel, ekspor Excel, dan cetak/PDF.
$mitGroup    = [];
$mitColCount = $risikoId ? 9 : 10;
if (!empty($mitigasiRows)) {
    $n = count($mitigasiRows);
    $tmp = [];
    $seq = 0; $prevRid = null; $sub = 0;
    foreach ($mitigasiRows as $m) {
        $rid = (int)($m['id_risiko'] ?? 0);
        if ($rid !== $prevRid) { $seq++; $sub = 0; $prevRid = $rid; }
        $sub++;
        $tmp[] = ['g' => $seq, 's' => $sub];
    }
    for ($i = 0; $i < $n; $i++) {
        $first = ($i === 0) || ($tmp[$i]['g'] !== $tmp[$i - 1]['g']);
        $span  = 0;
        if ($first) {
            $span = 1;
            for ($j = $i + 1; $j < $n && $tmp[$j]['g'] === $tmp[$i]['g']; $j++) $span++;
        }
        $mitGroup[$i] = ['no' => $tmp[$i]['g'] . '.' . $tmp[$i]['s'], 'span' => $span, 'first' => $first];
    }
}

// ── Export Excel ──────────────────────────────────────────────
if ($export === 'excel') {
    $headers = ['No', 'Kode Risiko', 'Nama Risiko', 'Level', 'Aksi Mitigasi', 'PIC', 'Deadline', 'Status', 'Biaya', 'Catatan', 'Tgl Input'];
    $excelRows = [];
    foreach ($mitigasiRows as $i => $m) {
        $grp = $mitGroup[$i] ?? ['no' => $i + 1, 'first' => true];
        $excelRows[] = [
            ['v' => $grp['no']],
            $grp['first'] ? ($m['kode_risiko'] ?? '') : '',
            $grp['first'] ? ($m['nama_risiko'] ?? '') : '',
            $m['level_risiko'] ?? '',
            $m['aksi'] ?? '',
            $m['pic'] ?? '',
            !empty($m['deadline']) ? tglIndo($m['deadline']) : '',
            $m['status'] ?? '',
            $m['biaya'] ?? 0,
            $m['catatan'] ?? '',
            !empty($m['created_at']) ? tglIndo(substr($m['created_at'],0,10)) : '',
        ];
    }
    $namaFile = 'laporan_mitigasi' . ($risikoId ? '_' . ($risikoDetail['kode_risiko'] ?? '') : '') . '_' . date('Ymd_His');
    exportExcel($namaFile, $headers, $excelRows);
}

// ── Export PDF (print view) ───────────────────────────────────
if ($export === 'pdf') {
    $judul = $risikoDetail ? ('Aksi Mitigasi — ' . $risikoDetail['kode_risiko'] . ' ' . $risikoDetail['nama_risiko']) : 'Laporan Aksi Mitigasi';
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title><?= $judul ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:11px}
body{font-family:Arial,Helvetica,sans-serif;color:#1e293b;background:#fff;padding:22px 26px;line-height:1.5}
.print-bar{display:flex;gap:10px;align-items:center;padding:8px 14px;background:#eff6ff;border:1px dashed #93c5fd;border-radius:6px;margin-bottom:14px;font-size:12px}
.btn-print{padding:7px 16px;background:#1e3a5f;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:12px;font-weight:700}
.kop{display:flex;align-items:center;justify-content:space-between;border-bottom:3px solid #1e3a5f;padding-bottom:12px;margin-bottom:16px}
.kop-title{font-size:15px;font-weight:700;color:#1e3a5f}
.kop-sub{font-size:10px;color:#64748b;margin-top:2px}
.kop-meta{text-align:right;font-size:10px;color:#64748b;line-height:1.7}
.kop-meta strong{color:#1e3a5f}
.info-box{background:#f0f4f8;border-left:4px solid #3b82f6;border-radius:0 6px 6px 0;padding:8px 14px;margin-bottom:14px;font-size:10px;color:#475569}
table{width:100%;border-collapse:collapse;font-size:9.5px}
th,td{border:1px solid #cbd5e1;padding:5px 7px;vertical-align:top}
th{background:#1e3a5f;color:#fff;font-weight:700;text-align:center}
td.center{text-align:center}
.badge{display:inline-block;padding:2px 7px;border-radius:8px;font-size:8px;font-weight:700;color:#fff}
.b-st{background:#64748b}
.b-ip{background:#0ea5e9}
.b-rj{background:#dc2626}
.b-ap{background:#16a34a}
tr:nth-child(even) td{background:#f8fafc}
.footer{margin-top:14px;padding-top:8px;border-top:1px solid #e2e8f0;font-size:9px;color:#64748b;display:flex;justify-content:space-between}
@page{size:A4 landscape;margin:10mm}
@media print{body{padding:0}.print-bar{display:none!important}}
</style>
</head>
<body>
<div class="print-bar">
  <button class="btn-print" onclick="window.print()">&#128196; Cetak / PDF</button>
  <span style="color:#1d4ed8"><strong>Ctrl+P</strong> → Ukuran kertas: <strong>A4 Landscape</strong></span>
</div>

<div class="kop">
  <div>
    <div class="kop-title"><?= APP_NAME ?></div>
    <div class="kop-sub">Laporan Aksi Mitigasi Risiko</div>
  </div>
  <div class="kop-meta">
    Dicetak: <strong><?= date('d/m/Y H:i:s') ?></strong><br>
    Total: <strong><?= count($mitigasiRows) ?> aksi mitigasi</strong>
  </div>
</div>

<?php if ($risikoDetail): ?>
<div class="info-box">
  <strong>Risiko:</strong> <?= xss($risikoDetail['kode_risiko']) ?> — <?= xss($risikoDetail['nama_risiko']) ?> ·
  <strong>Level:</strong> <?= xss($risikoDetail['level_risiko']) ?> ·
  <strong>Status:</strong> <?= xss($risikoDetail['status']) ?>
</div>
<?php endif; ?>

<table>
  <thead>
    <tr>
      <th style="width:24px">No</th>
      <th style="width:60px">Kode</th>
      <th>Risiko</th>
      <th style="width:50px">Level</th>
      <th>Aksi Mitigasi</th>
      <th style="width:80px">PIC</th>
      <th style="width:70px">Deadline</th>
      <th style="width:70px">Status</th>
      <th style="width:60px">Biaya</th>
      <th>Catatan</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($mitigasiRows)): ?>
    <tr><td colspan="10" style="text-align:center;padding:20px;color:#94a3b8">Belum ada aksi mitigasi</td></tr>
  <?php else: ?>
    <?php foreach ($mitigasiRows as $i => $m): ?>
      <?php
        $stCls = match($m['status'] ?? '') {
          'Selesai' => 'b-ap',
          'Sedang Berjalan' => 'b-ip',
          'Terlambat' => 'b-rj',
          default => 'b-st',
        };
        $grp = $mitGroup[$i] ?? ['no' => $i + 1, 'span' => 1, 'first' => true];
      ?>
    <tr>
      <td class="center"><?= $grp['no'] ?></td>
      <?php if ($grp['first']): ?>
      <td class="center" rowspan="<?= $grp['span'] ?>"><code><?= xss($m['kode_risiko'] ?? '') ?></code></td>
      <td rowspan="<?= $grp['span'] ?>"><?= xss($m['nama_risiko'] ?? '') ?></td>
      <td class="center" rowspan="<?= $grp['span'] ?>"><?= xss($m['level_risiko'] ?? '-') ?></td>
      <?php endif; ?>
      <td><?= xss($m['aksi'] ?? '') ?></td>
      <td class="center"><?= xss($m['pic'] ?? '-') ?></td>
      <td class="center" style="white-space:nowrap"><?= !empty($m['deadline']) ? tglIndo($m['deadline']) : '-' ?></td>
      <td class="center"><span class="badge <?= $stCls ?>"><?= xss($m['status'] ?? '-') ?></span></td>
      <td class="center"><?= isset($m['biaya']) ? 'Rp ' . number_format($m['biaya'], 0, ',', '.') : '-' ?></td>
      <td style="font-size:9px;color:#475569"><?= xss($m['catatan'] ?? '-') ?></td>
    </tr>
    <?php endforeach; ?>
  <?php endif; ?>
  </tbody>
</table>

<div class="footer">
  <span><?= APP_NAME ?> — Laporan digenerate otomatis pada <?= date('d/m/Y H:i:s') ?></span>
  <span>Total: <?= count($mitigasiRows) ?> aksi mitigasi</span>
</div>

</body>
</html>
    <?php
    exit;
}

// ── Statistik hero (dihitung secara global) ──
$statMitSql = "SELECT COUNT(*) as total, 
    SUM(IF(m.progress >= 100, 1, 0)) as selesai, 
    SUM(IF(m.progress < 100 AND m.deadline < CURDATE(), 1, 0)) as terlambat 
    FROM mitigasi m JOIN risiko r ON m.id_risiko=r.id $mitigasi_cond";
$statMitRes = $db->query($statMitSql)->fetch_assoc();
$mitTotal = (int)$statMitRes['total'];
$mitSelesai = (int)$statMitRes['selesai'];
$mitTerlambat = (int)$statMitRes['terlambat'];
$mitStatLink = APP_URL . '/?page=mitigasi' . ($risikoId ? '&risiko_id=' . $risikoId : '');
?>

<div class="risiko-hero profil-risiko-hero" style="background:linear-gradient(115deg,#059669 0%,#047857 55%,#064e3b 100%); align-items: flex-start !important;">
  <div class="risiko-hero-copy">
    <div class="risiko-eyebrow"><i class="fas fa-tasks"></i> Penanganan Risiko</div>
    <h1 class="page-title" style="color:#fff">Aksi Mitigasi</h1>
    <p class="page-sub" style="color:rgba(255,255,255,.8)">Kelola rencana dan tindakan penanganan risiko</p>
  </div>
  <div class="profil-hero-tools risiko-hero-tools-align">
    <div class="risiko-export-actions" style="margin-top:0">
      <a href="<?= APP_URL ?>/?page=mitigasi<?= $risikoId ? '&risiko_id=' . $risikoId : '' ?>&export=excel" class="btn btn-hero-ghost"><i class="fas fa-file-excel"></i> Excel</a>
      <a href="<?= APP_URL ?>/?page=mitigasi<?= $risikoId ? '&risiko_id=' . $risikoId : '' ?>&export=pdf" target="_blank" class="btn btn-hero-ghost">&#128196; Cetak / PDF</a>
    </div>
    <div class="risiko-hero-actions" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
      <?php if($risikoId): ?>
      <a href="<?= APP_URL ?>/?page=risiko&detail=<?= $risikoId ?>" class="btn btn-hero-ghost"><i class="fas fa-arrow-left"></i> Kembali</a>
      <?php endif; ?>
      <a href="javascript:void(0)" class="btn btn-hero-primary" onclick="openModal('modalMitigasi')" style="margin:0;"><i class="fas fa-plus"></i> Tambah Mitigasi</a>
    </div>
  </div>

  <!-- Stat cards (glassmorphism inside hero) -->
  <div class="stats-grid cols-3" style="width:100%;margin-top:20px;margin-bottom:0">
    <a href="<?= $mitStatLink ?>" class="stat-card stat-card-glass" style="--ga:#60a5fa;--ga-tint:rgba(96,165,250,.3);--ga-line:rgba(96,165,250,.45);--ga-glow:rgba(96,165,250,.3);text-decoration:none">
      <div class="stat-icon"><i class="fas fa-tasks"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $mitTotal ?></div>
        <div class="stat-label">Total Aksi</div>
      </div>
    </a>
    <a href="<?= $mitStatLink ?>" class="stat-card stat-card-glass" style="--ga:#4ade80;--ga-tint:rgba(74,222,128,.28);--ga-line:rgba(74,222,128,.45);--ga-glow:rgba(74,222,128,.28);text-decoration:none">
      <div class="stat-icon"><i class="fas fa-check-double"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $mitSelesai ?></div>
        <div class="stat-label">Selesai</div>
      </div>
    </a>
    <a href="<?= $mitStatLink ?>" class="stat-card stat-card-glass" style="--ga:#f87171;--ga-tint:rgba(248,113,113,.28);--ga-line:rgba(248,113,113,.5);--ga-glow:rgba(248,113,113,.32);text-decoration:none">
      <div class="stat-icon"><i class="fas fa-clock"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $mitTerlambat ?></div>
        <div class="stat-label">Terlambat</div>
      </div>
    </a>
  </div>
</div>

<?php if($risikoDetail): ?>
<!-- Info Risiko -->
<div class="card" style="margin-bottom:20px;border-left:4px solid var(--accent)">
  <div class="card-body" style="padding:14px 20px;display:flex;align-items:center;gap:20px;flex-wrap:wrap">
    <div><div style="font-size:.72rem;color:var(--text-muted)">Risiko</div><div style="font-weight:700"><?= xss($risikoDetail['kode_risiko']) ?> — <?= xss($risikoDetail['nama_risiko']) ?></div></div>
    <div><?= badgeLevel($risikoDetail['level_risiko']) ?></div>
    <div><?= badgeStatus($risikoDetail['status']) ?></div>
    <div style="margin-left:auto;font-size:.8rem;color:var(--text-muted)">Skor: <strong style="font-size:1.2rem;color:var(--accent)"><?= $risikoDetail['skor_risiko'] ?></strong></div>
  </div>
</div>
<?php endif; ?>

<!-- Filter risiko jika tidak ada ID spesifik -->
<?php if(!$risikoId): ?>
<div class="filter-bar">
  <div class="form-group" style="flex:1">
    <label class="form-label">Filter berdasarkan Risiko</label>
    <select class="form-control" onchange="if(this.value)window.location='<?= APP_URL ?>/?page=mitigasi&risiko_id='+this.value">
      <option value="">-- Semua Mitigasi (50 terbaru) --</option>
      <?php foreach($risikoList as $r): ?>
      <option value="<?= $r['id'] ?>"><?= xss($r['kode_risiko']) ?> — <?= xss($r['nama_risiko']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</div>
<?php endif; ?>

<!-- Tabel Mitigasi -->
<div class="card">
  <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <span class="card-title">Daftar Aksi Mitigasi <span style="color:var(--text-muted);font-weight:400">(<?= count($mitigasiRows) ?>)</span></span>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;flex:1;justify-content:flex-end">
      <div class="search-bar" style="max-width:250px;width:100%">
        <i class="fas fa-search"></i>
        <input type="text" class="form-control" id="searchMitigasi" placeholder="Cari mitigasi..." onkeyup="filterTableMitigasi()" style="height:38px">
      </div>
      <div class="datatable-dropdown" style="margin:0; display:flex; align-items:center;">
        <select class="datatable-selector" id="limitMitigasi" onchange="filterTableMitigasi()">
          <option value="5">5</option>
          <option value="10" selected>10</option>
          <option value="15">15</option>
          <option value="20">20</option>
          <option value="25">25</option>
        </select>
        <label style="margin-left:8px; margin-bottom:0; font-size:14px;">Data</label>
      </div>
    </div>
  </div>
  <div class="table-responsive">
    <table class="data-table profil-detail-table mitigasi-data-table no-datatable" id="tableMitigasi">
      <colgroup>
        <col style="width:36px">
        <?php if(!$risikoId): ?><col style="min-width:200px"><?php endif; ?>
        <col style="min-width:200px">
        <col style="width:140px">
        <col style="width:105px">
        <col style="width:100px">
        <col style="width:90px">
        <col style="width:48px">
        <col style="width:48px">
        <col style="width:75px">
      </colgroup>
      <thead>
        <tr>
          <th style="width:36px;text-align:center">No</th>
          <?php if(!$risikoId): ?><th>Risiko</th><?php endif; ?>
          <th>Aksi Mitigasi</th>
          <th>PIC</th>
          <th style="text-align:center">Deadline</th>
          <th style="text-align:center">Status</th>
          <th style="text-align:center">Biaya</th>
          <th style="text-align:center">Bukti</th>
          <th style="text-align:center">TTD</th>
          <th style="text-align:center">Aksi</th>
        </tr>
      </thead>
      <tbody>
      <?php if(empty($mitigasiRows)): ?>
        <tr><td colspan="<?= $mitColCount ?>"><div class="empty-state" style="padding:32px"><i class="fas fa-tasks" style="font-size:2rem;opacity:.35;margin-bottom:8px;display:block"></i><h3 style="font-size:1rem;margin:0 0 4px">Belum ada mitigasi</h3><p style="margin:4px 0 0;color:var(--text-muted);font-size:.75rem">Klik "Tambah Mitigasi" untuk menambahkan aksi penanganan risiko</p></div></td></tr>
      <?php else: ?>
        <?php foreach($mitigasiRows as $idx => $m): ?>
        <?php
          $overdue = $m['status']!=='Selesai' && strtotime($m['deadline']) < time();
          $rowStyle = $overdue ? 'background:rgba(220,38,38,.04)' : '';
          $statusMit = ['Belum Mulai'=>'badge-secondary','Sedang Berjalan'=>'badge-info','Selesai'=>'badge-success','Terlambat'=>'badge-danger'];
          $grp = $mitGroup[$idx] ?? ['no' => $idx + 1, 'span' => 1, 'first' => true];
          $riskStyle = 'min-width:140px' . ($grp['first'] ? '' : ';display:none');
        ?>
        <tr style="<?= $rowStyle ?>">
          <td style="text-align:center;font-weight:600;color:var(--text-muted);white-space:nowrap"><?= xss($grp['no']) ?></td>
          <?php if(!$risikoId): ?>
          <td class="risk-cell" data-risk="<?= (int)$m['id_risiko'] ?>"<?= $grp['first'] ? ' rowspan="'.$grp['span'].'"' : '' ?> style="<?= $riskStyle ?>">
            <a href="?page=mitigasi&risiko_id=<?= $m['id_risiko'] ?>" style="text-decoration:none;display:inline-block;margin-bottom:3px">
              <span class="badge-kode-risiko"><?= xss($m['kode_risiko']) ?></span>
            </a>
            <div style="font-weight:600;color:var(--text-main);line-height:1.35"><?= xss($m['nama_risiko']) ?></div>
            <?php if(!empty($m['level_risiko'])): ?>
            <div style="margin-top:3px"><?= badgeLevel($m['level_risiko']) ?></div>
            <?php endif; ?>
          </td>
          <?php endif; ?>
          <td style="min-width:180px">
            <div style="font-weight:600;color:var(--text-main);line-height:1.35"><?= xss($m['aksi']) ?></div>
            <?php if($m['catatan']): ?><div style="font-size:.71rem;color:var(--text-muted);margin-top:2px"><?= xss($m['catatan']) ?></div><?php endif; ?>
          </td>
          <td>
            <div style="font-weight:500;font-size:.74rem;display:flex;align-items:flex-start;gap:4px">
              <i class="fas fa-user-tie" style="font-size:.68rem;color:var(--primary);opacity:.8;margin-top:2px"></i>
              <span><?= xss($m['pic']) ?></span>
            </div>
          </td>
          <td style="text-align:center">
            <div style="font-size:.74rem;display:inline-flex;align-items:center;gap:4px;white-space:nowrap;<?= $overdue?'color:var(--danger);font-weight:700':'' ?>">
              <i class="far fa-calendar-alt" style="font-size:.68rem;opacity:.75"></i>
              <span><?= tglIndo($m['deadline']) ?></span>
            </div>
            <?php if($overdue): ?><div style="font-size:.65rem;color:var(--danger);font-weight:700;margin-top:2px"><i class="fas fa-exclamation-circle"></i> Terlambat</div><?php endif; ?>
          </td>
          <td style="text-align:center">
            <span class="badge <?= $statusMit[$m['status']] ?? 'badge-secondary' ?>" style="font-size:.69rem;padding:3px 8px;border-radius:12px;font-weight:600;display:inline-flex;align-items:center;gap:4px">
              <?= xss($m['status']) ?>
            </span>
          </td>
          <td style="text-align:center;font-size:.74rem"><?= $m['biaya']>0 ? rupiah($m['biaya']) : '-' ?></td>
          <td style="text-align:center">
            <?php if (!empty($m['bukti_file'])): ?>
            <a href="<?= APP_URL ?>/serve.php?t=bukti&f=<?= urlencode($m['bukti_file']) ?>" target="_blank" class="act-btn act-btn-view" title="Lihat Bukti"><i class="fas fa-paperclip"></i></a>
            <?php else: ?><span style="color:var(--text-muted);font-size:.75rem">-</span><?php endif; ?>
          </td>
          <td style="text-align:center">
            <?php if($m['ttd_file']): ?>
            <button onclick="lihatTTD('<?= xss($m['ttd_file']) ?>')" class="act-btn act-btn-sign" title="Lihat TTD"><i class="fas fa-signature"></i></button>
            <?php else: ?><span style="color:var(--text-muted);font-size:.75rem">-</span><?php endif; ?>
          </td>
          <td class="mitigasi-action-cell" style="text-align:center;white-space:nowrap">
            <div class="act-btn-group">
              <button class="act-btn act-btn-edit" onclick="editMitigasi(<?= htmlspecialchars(json_encode($m),ENT_QUOTES) ?>)" title="Edit"><i class="fas fa-edit"></i></button>
              <?php if(hasRole('Admin','Risk Manager')): ?>
              <button class="act-btn act-btn-delete" onclick="hapusMitigasi(<?= $m['id'] ?>)" title="Hapus"><i class="fas fa-trash"></i></button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer" id="mitigasiPagination" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;padding:14px 20px;">
    <span class="pagination-info" id="mitigasiPageInfo" style="font-size:.76rem;color:var(--text-muted);font-weight:600;">Memuat...</span>
    <div class="pagination" id="mitigasiPages" style="margin:0;gap:5px;"></div>
  </div>
</div>
</div>

<!-- Modal Tambah/Edit Mitigasi -->
<div class="modal-overlay" id="modalMitigasi" style="display:none">
  <div class="modal modal-lg">
    <div class="modal-header">
      <h3 class="modal-title" id="modalMitTitle"><i class="fas fa-plus-circle"></i> Tambah Aksi Mitigasi</h3>
      <button class="btn-close" onclick="closeModal('modalMitigasi')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/?page=mitigasi" enctype="multipart/form-data" id="formMitigasi">
    <?= csrfField() ?>
    <input type="hidden" name="aksi" value="simpan">
    <input type="hidden" name="id" id="mitId" value="0">
    <input type="hidden" name="id_risiko" id="mitRisikoId" value="<?= $risikoId ?>">
    <input type="hidden" name="ttd_data" id="ttdDataInput">
    <div class="modal-body">
      <?php if(!$risikoId): ?>
      <div class="form-group">
        <label class="form-label">Risiko <span class="required">*</span></label>
        <select name="id_risiko" id="mitRisikoSelect" class="form-control" required onchange="document.getElementById('mitRisikoId').value=this.value">
          <option value="">-- Pilih Risiko --</option>
          <?php foreach($risikoList as $r): ?>
          <option value="<?= $r['id'] ?>"><?= xss($r['kode_risiko']) ?> &mdash; <?= xss($r['nama_risiko']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="form-group">
        <label class="form-label" style="display:flex;align-items:center;justify-content:space-between">
          <span>Aksi Mitigasi <span class="required">*</span></span>
          <?php if($risikoId): ?>
          <button type="button" class="btn btn-xs btn-accent" onclick="fetchAiSaranMitigasi(<?= (int)$risikoId ?>)"><i class="fas fa-robot"></i> Saran AI</button>
          <?php endif; ?>
        </label>
        <textarea name="aksi_mitigasi" id="mitAksi" class="form-control" rows="3" required placeholder="Deskripsi lengkap tindakan mitigasi yang akan dilakukan..."><?= xss($saranAwal) ?></textarea>
        <div id="mitAiSaranList" style="margin-top:8px;display:none;flex-direction:column;gap:6px"></div>
      </div>
      <div class="form-row-2">
        <div class="form-group">
          <label class="form-label">PIC (Penanggung Jawab) <span class="required">*</span></label>
          <input type="text" name="pic" id="mitPic" class="form-control" required placeholder="Nama penanggung jawab">
        </div>
        <div class="form-group">
          <label class="form-label">Deadline <span class="required">*</span></label>
          <input type="date" name="deadline" id="mitDeadline" class="form-control" required value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Status</label>
          <select name="status_mitigasi" id="mitStatus" class="form-control">
            <option>Belum Mulai</option>
            <option>Sedang Berjalan</option>
            <option>Selesai</option>
            <option>Terlambat</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Estimasi Biaya (Rp)</label>
          <input type="number" name="biaya" id="mitBiaya" class="form-control" placeholder="0" min="0" step="1000">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Catatan</label>
        <textarea name="catatan" id="mitCatatan" class="form-control" rows="2" placeholder="Catatan tambahan..."></textarea>
      </div>
      <!-- Upload Bukti -->
      <div class="form-group">
        <label class="form-label"><i class="fas fa-paperclip"></i> Upload Bukti Mitigasi</label>
        <input type="file" name="bukti_file" id="mitBukti" class="form-control" accept="image/*,.pdf">
        <div class="form-hint">Format: JPG, PNG, PDF. Maksimal 5 MB.</div>
        <div id="buktiPreview" style="margin-top:8px;display:none">
          <img id="buktiImg" style="max-height:120px;border-radius:8px;border:1px solid var(--border)">
          <span id="buktiName" style="font-size:.78rem;color:var(--text-muted);margin-left:8px"></span>
        </div>
      </div>
      <!-- Tanda Tangan Digital -->
      <div class="form-group">
        <label class="form-label"><i class="fas fa-signature"></i> Tanda Tangan Digital</label>
        <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:6px">Tanda tangani di area di bawah ini menggunakan mouse atau layar sentuh:</div>
        <canvas id="sigCanvas" class="sig-canvas" width="600" height="150"></canvas>
        <div class="sig-actions">
          <button type="button" class="btn btn-sm btn-outline" onclick="clearSig()"><i class="fas fa-eraser"></i> Hapus TTD</button>
          <span id="sigStatus" style="font-size:.75rem;color:var(--text-muted);align-self:center">Belum ada tanda tangan</span>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" onclick="closeModal('modalMitigasi')" class="btn btn-outline">Batal</button>
      <button type="submit" class="btn btn-success" onclick="saveSig()"><i class="fas fa-save"></i> Simpan Mitigasi</button>
    </div>
    </form>
  </div>
</div>

<!-- Modal Lihat TTD -->
<div class="modal-overlay" id="modalTTD" style="display:none">
  <div class="modal" style="max-width:420px">
    <div class="modal-header"><h3 class="modal-title"><i class="fas fa-signature"></i> Tanda Tangan</h3>
    <button class="btn-close" onclick="closeModal('modalTTD')"><i class="fas fa-times"></i></button></div>
    <div class="modal-body" style="text-align:center">
      <img id="ttdImgView" style="max-width:100%;border:1px solid var(--border);border-radius:8px">
    </div>
  </div>
</div>

<!-- Modal Hapus -->
<div class="modal-overlay" id="modalHapusMit" style="display:none">
  <div class="modal" style="max-width:400px">
    <div class="modal-header"><h3 class="modal-title" style="color:var(--danger)"><i class="fas fa-trash"></i> Hapus Mitigasi</h3>
    <button class="btn-close" onclick="closeModal('modalHapusMit')"><i class="fas fa-times"></i></button></div>
    <div class="modal-body"><p>Yakin ingin menghapus aksi mitigasi ini beserta file bukti dan tanda tangannya?</p></div>
    <div class="modal-footer">
      <form method="POST"><?= csrfField() ?>
      <input type="hidden" name="aksi" value="hapus">
      <input type="hidden" name="id_risiko" value="<?= $risikoId ?>">
      <input type="hidden" name="id" id="hapusMitId">
      <button type="button" onclick="closeModal('modalHapusMit')" class="btn btn-outline">Batal</button>
      <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Hapus</button>
      </form>
    </div>
  </div>
</div>

<script>
const mitigasiState = { page: 1 };

// Gabungkan kolom Risiko (rowspan) untuk baris yang tampil pada halaman ini.
function relayoutRiskCells(pageRows) {
  const cells = document.querySelectorAll('#tableMitigasi tbody .risk-cell');
  cells.forEach(c => { c.style.display = ''; c.removeAttribute('rowspan'); });
  if (!pageRows || !pageRows.length) return;
  let i = 0;
  while (i < pageRows.length) {
    const cell = pageRows[i].querySelector('.risk-cell');
    if (!cell) { i++; continue; }
    const rid = cell.getAttribute('data-risk');
    let j = i + 1;
    while (j < pageRows.length) {
      const c2 = pageRows[j].querySelector('.risk-cell');
      if (!c2 || c2.getAttribute('data-risk') !== rid) break;
      j++;
    }
    cell.rowSpan = j - i;
    for (let k = i + 1; k < j; k++) {
      const c2 = pageRows[k].querySelector('.risk-cell');
      if (c2) c2.style.display = 'none';
    }
    i = j;
  }
}

function filterTableMitigasi() {
  const query = (document.getElementById('searchMitigasi')?.value || '').toLowerCase();
  const limit = parseInt(document.getElementById('limitMitigasi')?.value || 10, 10);
  const allRows = [...document.querySelectorAll('#tableMitigasi tbody tr')];
  
  let count = 0;
  let emptyState = null;

  const visible = allRows.filter(row => {
    if(row.querySelector('.empty-state') || row.id === 'emptySearchMitigasi') {
      if(row.id === 'emptySearchMitigasi') row.style.display = 'none';
      if(row.querySelector('.empty-state')) emptyState = row;
      return false;
    }
    const text = row.textContent.toLowerCase();
    if (query && !text.includes(query)) return false;
    count++;
    return true;
  });

  const total = visible.length;
  const pages = Math.max(1, Math.ceil(total / limit));
  if (mitigasiState.page > pages) mitigasiState.page = pages;
  const start = (mitigasiState.page - 1) * limit;

  allRows.forEach(row => { if(row.id !== 'emptySearchMitigasi' && !row.querySelector('.empty-state')) row.style.display = 'none'; });
  const pageRows = visible.slice(start, start + limit);
  pageRows.forEach(row => { row.style.display = ''; });
  relayoutRiskCells(pageRows);

  const infoEl = document.getElementById('mitigasiPageInfo');
  if(infoEl) {
    infoEl.textContent = total === 0 ? 'Tidak ada data' : 'Menampilkan ' + (start + 1) + '–' + Math.min(start + limit, total) + ' dari ' + total + ' data';
  }

  const pagesEl = document.getElementById('mitigasiPages');
  if(pagesEl) {
    pagesEl.innerHTML = '';
    if (pages > 1) {
      pagesEl.style.display = 'flex';
      const mkBtn = (html, page, disabled, active, title) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'page-btn' + (active ? ' active' : '') + (disabled ? ' disabled' : '');
        b.innerHTML = html;
        b.disabled = disabled;
        if (title) b.title = title;
        if (!disabled && !active) {
          b.onclick = () => {
            mitigasiState.page = page;
            filterTableMitigasi();
            document.getElementById('tableMitigasi')?.scrollIntoView({behavior:'smooth', block:'nearest'});
          };
        }
        pagesEl.appendChild(b);
      };
      mkBtn('<i class="fas fa-angles-left"></i>', 1, mitigasiState.page <= 1, false, 'Halaman Pertama');
      mkBtn('<i class="fas fa-chevron-left"></i>', mitigasiState.page - 1, mitigasiState.page <= 1, false, 'Halaman Sebelumnya');
      for (let p = Math.max(1, mitigasiState.page - 2); p <= Math.min(pages, mitigasiState.page + 2); p++) {
        mkBtn(String(p), p, false, p === mitigasiState.page);
      }
      mkBtn('<i class="fas fa-chevron-right"></i>', mitigasiState.page + 1, mitigasiState.page >= pages, false, 'Halaman Berikutnya');
      mkBtn('<i class="fas fa-angles-right"></i>', pages, mitigasiState.page >= pages, false, 'Halaman Terakhir');
    } else {
      pagesEl.style.display = 'none';
    }
  }

  // Tampilkan empty state jika tidak ada hasil pencarian
  const colCount = document.querySelectorAll('#tableMitigasi thead th').length || 9;
  let emptyRow = document.getElementById('emptySearchMitigasi');
  if (count === 0 && allRows.length > 0 && !emptyState) {
    const emptyHtml = `<td colspan="${colCount}"><div class="empty-state"><i class="fas fa-search"></i><p>Pencarian "<b>${query}</b>" tidak ditemukan.</p></div></td>`;
    if (!emptyRow) {
      emptyRow = document.createElement('tr');
      emptyRow.id = 'emptySearchMitigasi';
      emptyRow.innerHTML = emptyHtml;
      document.querySelector('#tableMitigasi tbody').appendChild(emptyRow);
    } else {
      emptyRow.style.display = '';
      emptyRow.innerHTML = emptyHtml;
    }
  } else {
    if(emptyRow) emptyRow.style.display = 'none';
  }
}
setTimeout(() => filterTableMitigasi(), 100);
// ── Signature Canvas ──────────────────────────────────────────
const canvas = document.getElementById('sigCanvas');
const ctx    = canvas ? canvas.getContext('2d') : null;
let drawing  = false;
let hasSig   = false;

function resizeCanvas(){
  if(!canvas) return;
  const rect = canvas.getBoundingClientRect();
  canvas.width  = rect.width || 600;
  canvas.height = 150;
}
resizeCanvas();
window.addEventListener('resize', resizeCanvas);

function getPos(e){
  const rect = canvas.getBoundingClientRect();
  const src  = e.touches ? e.touches[0] : e;
  return {x: src.clientX - rect.left, y: src.clientY - rect.top};
}
if(ctx){
  canvas.addEventListener('mousedown',  e=>{ drawing=true; ctx.beginPath(); const p=getPos(e); ctx.moveTo(p.x,p.y); });
  canvas.addEventListener('mousemove',  e=>{ if(!drawing) return; const p=getPos(e); ctx.lineTo(p.x,p.y); ctx.strokeStyle='#1e3a5f'; ctx.lineWidth=2; ctx.lineCap='round'; ctx.stroke(); hasSig=true; document.getElementById('sigStatus').textContent='✓ Tanda tangan tersimpan'; });
  canvas.addEventListener('mouseup',    ()=>drawing=false);
  canvas.addEventListener('mouseleave', ()=>drawing=false);
  canvas.addEventListener('touchstart', e=>{ e.preventDefault(); drawing=true; ctx.beginPath(); const p=getPos(e); ctx.moveTo(p.x,p.y); },{passive:false});
  canvas.addEventListener('touchmove',  e=>{ e.preventDefault(); if(!drawing) return; const p=getPos(e); ctx.lineTo(p.x,p.y); ctx.strokeStyle='#1e3a5f'; ctx.lineWidth=2; ctx.lineCap='round'; ctx.stroke(); hasSig=true; },{passive:false});
  canvas.addEventListener('touchend',   ()=>drawing=false);
}
function clearSig(){ if(ctx){ ctx.clearRect(0,0,canvas.width,canvas.height); hasSig=false; document.getElementById('sigStatus').textContent='Belum ada tanda tangan'; document.getElementById('ttdDataInput').value=''; } }
function saveSig(){ if(hasSig && canvas){ document.getElementById('ttdDataInput').value = canvas.toDataURL('image/png'); } }

// ── Preview Bukti ─────────────────────────────────────────────
document.getElementById('mitBukti')?.addEventListener('change', function(){
  const f = this.files[0];
  if(!f) return;
  document.getElementById('buktiName').textContent = f.name;
  document.getElementById('buktiPreview').style.display='flex';
  document.getElementById('buktiPreview').style.alignItems='center';
  if(f.type.startsWith('image/')){
    const reader = new FileReader();
    reader.onload = e => { document.getElementById('buktiImg').src=e.target.result; document.getElementById('buktiImg').style.display=''; };
    reader.readAsDataURL(f);
  } else {
    document.getElementById('buktiImg').style.display='none';
  }
});

// ── Edit Mitigasi ─────────────────────────────────────────────
function editMitigasi(m){
  document.getElementById('modalMitTitle').innerHTML='<i class="fas fa-edit"></i> Edit Mitigasi';
  document.getElementById('mitId').value      = m.id;
  document.getElementById('mitRisikoId').value= m.id_risiko;
  document.getElementById('mitAksi').value    = m.aksi;
  document.getElementById('mitPic').value     = m.pic;
  document.getElementById('mitDeadline').value= m.deadline;
  document.getElementById('mitStatus').value  = m.status;
  document.getElementById('mitBiaya').value   = m.biaya;
  document.getElementById('mitCatatan').value = m.catatan||'';
  if(document.getElementById('mitRisikoSelect')) document.getElementById('mitRisikoSelect').value=m.id_risiko;
  openModal('modalMitigasi');
}
function hapusMitigasi(id){ document.getElementById('hapusMitId').value=id; openModal('modalHapusMit'); }
function lihatTTD(fn){ document.getElementById('ttdImgView').src='<?= APP_URL ?>/serve.php?t=ttd&f='+encodeURIComponent(fn); openModal('modalTTD'); }

// ── Saran Mitigasi AI (Gemini via backend /api.php/ai_saran) ────
// Tombol hanya muncul saat $risikoId sudah dipilih. Memakai window.APP_URL
// & window.CSRF_TOKEN yang sudah disediakan header.php.
function fetchAiSaranMitigasi(idRisiko){
  const box=document.getElementById('mitAiSaranList');
  const ta=document.getElementById('mitAksi');
  if(!box||!idRisiko) return;
  box.style.display='flex';
  box.innerHTML='<div style="font-size:.78rem;color:var(--text-muted)"><i class="fas fa-spinner fa-spin"></i> AI sedang menganalisis risiko…</div>';
  fetch(window.APP_URL+'/api.php/ai_saran?id_risiko='+encodeURIComponent(idRisiko),{method:'GET',headers:{'X-CSRF-Token':window.CSRF_TOKEN}})
    .then(function(r){ return r.json(); })
    .then(function(res){
      box.innerHTML='';
      if(res.ok&&Array.isArray(res.saran)&&res.saran.length){
        res.saran.forEach(function(s){
          const row=document.createElement('div');
          row.style.cssText='display:flex;gap:8px;align-items:flex-start;font-size:.8rem;line-height:1.5;background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:6px 8px';
          const txt=document.createElement('div'); txt.style.flex='1'; txt.textContent=s;
          const btn=document.createElement('button'); btn.type='button'; btn.className='btn btn-xs btn-outline';
          btn.innerHTML='<i class="fas fa-arrow-down"></i> Pakai'; btn.title='Isi ke kolom Aksi Mitigasi';
          btn.onclick=function(){ ta.value=s; ta.focus(); };
          row.appendChild(txt); row.appendChild(btn);
          box.appendChild(row);
        });
      } else {
        box.innerHTML='<div style="font-size:.78rem;color:var(--danger)">'+(res.error||'Tidak ada saran yang dihasilkan.')+'</div>';
      }
    })
    .catch(function(){ box.innerHTML='<div style="font-size:.78rem;color:var(--danger)">Gagal memanggil layanan AI (network/server).</div>'; });
}
</script>




