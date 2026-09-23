<?php
/** Master pasangan Indikator Kinerja Kegiatan dan Target. */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
requireRole('Admin');
$db = getDB();

$db->query("CREATE TABLE IF NOT EXISTS master_indikator_kegiatan (
    id INT NOT NULL AUTO_INCREMENT,
    tahun VARCHAR(9) NOT NULL,
    program VARCHAR(200) DEFAULT NULL,
    kegiatan TEXT DEFAULT NULL,
    sasaran TEXT DEFAULT NULL,
    indikator TEXT NOT NULL,
    target VARCHAR(500) DEFAULT NULL,
    satuan VARCHAR(100) DEFAULT NULL,
    unit_pemilik_risiko VARCHAR(200) DEFAULT NULL,
    aktif TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_master_indikator_tahun (tahun),
    KEY idx_master_indikator_aktif (aktif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$db->query("CREATE TABLE IF NOT EXISTS profil_risiko_indikator (
    id INT NOT NULL AUTO_INCREMENT, id_profil INT NOT NULL, id_master INT DEFAULT NULL,
    tahun VARCHAR(9) DEFAULT NULL, program VARCHAR(200) DEFAULT NULL, kegiatan TEXT DEFAULT NULL,
    sasaran TEXT DEFAULT NULL, indikator TEXT NOT NULL, target VARCHAR(500) DEFAULT NULL,
    satuan VARCHAR(100) DEFAULT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), KEY idx_profil_indikator (id_profil), KEY idx_master_profil_indikator (id_master)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Pecah data lama yang biasanya disimpan satu item per baris, termasuk format "1.".
$splitLegacyItems = static function (string $value): array {
    $lines = preg_split('/\R/u', trim($value)) ?: [];
    $items = [];
    foreach ($lines as $line) {
        $line = trim((string)$line);
        $line = preg_replace('/^\s*(?:\d+\s*[.)]|[-*])\s*/u', '', $line) ?? $line;
        if ($line !== '') $items[] = trim($line);
    }
    return $items ?: (trim($value) !== '' ? [trim($value)] : []);
};

$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
    $s = $db->prepare('SELECT * FROM master_indikator_kegiatan WHERE id=?');
    $s->bind_param('i', $editId); $s->execute();
    $editRow = $s->get_result()->fetch_assoc(); $s->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { setFlash('error', 'Token tidak valid'); header('Location: '.APP_URL.'/?page=master_indikator'); exit; }
    $aksi = $_POST['aksi'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($aksi === 'simpan') {
        $f = [
            'tahun' => trim($_POST['tahun'] ?? date('Y')),
            'program' => trim($_POST['program'] ?? ''),
            'kegiatan' => trim($_POST['kegiatan'] ?? ''),
            'sasaran' => trim($_POST['sasaran'] ?? ''),
            'indikator' => trim($_POST['indikator'] ?? ''),
            'target' => trim($_POST['target'] ?? ''),
            'satuan' => trim($_POST['satuan'] ?? ''),
            'unit_pemilik_risiko' => trim($_POST['unit_pemilik_risiko'] ?? ''),
            'aktif' => isset($_POST['aktif']) ? 1 : 0,
        ];
        if ($f['indikator'] === '') {
            setFlash('error', 'Indikator wajib diisi');
        } elseif ($id > 0) {
            $s = $db->prepare('UPDATE master_indikator_kegiatan SET tahun=?,program=?,kegiatan=?,sasaran=?,indikator=?,target=?,satuan=?,unit_pemilik_risiko=?,aktif=? WHERE id=?');
            $s->bind_param('ssssssssii', $f['tahun'],$f['program'],$f['kegiatan'],$f['sasaran'],$f['indikator'],$f['target'],$f['satuan'],$f['unit_pemilik_risiko'],$f['aktif'],$id);
            $s->execute(); $s->close();
            logAktivitas('UPDATE', 'master_indikator', $id, 'Update master indikator: ' . $f['indikator']);
            setFlash('success', 'Master indikator berhasil diperbarui');
        } else {
            $uid = (int)$_SESSION['user_id'];
            $s = $db->prepare('INSERT INTO master_indikator_kegiatan (tahun,program,kegiatan,sasaran,indikator,target,satuan,unit_pemilik_risiko,aktif,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $s->bind_param('ssssssssii', $f['tahun'],$f['program'],$f['kegiatan'],$f['sasaran'],$f['indikator'],$f['target'],$f['satuan'],$f['unit_pemilik_risiko'],$f['aktif'],$uid);
            $s->execute(); $newId = $db->insert_id; $s->close();
            logAktivitas('CREATE', 'master_indikator', $newId, 'Tambah master indikator: ' . $f['indikator']);
            setFlash('success', 'Master indikator berhasil ditambahkan');
        }
        header('Location: '.APP_URL.'/?page=master_indikator'); exit;
    }
    if ($aksi === 'hapus') {
        $s = $db->prepare('UPDATE master_indikator_kegiatan SET aktif=0 WHERE id=?');
        $s->bind_param('i', $id); $s->execute(); $s->close();
        logAktivitas('DELETE', 'master_indikator', $id, 'Nonaktifkan master indikator ID ' . $id);
        setFlash('success', 'Master indikator dinonaktifkan, data tidak dihapus');
        header('Location: '.APP_URL.'/?page=master_indikator'); exit;
    }
    if ($aksi === 'aktifkan') {
        $s = $db->prepare('UPDATE master_indikator_kegiatan SET aktif=1 WHERE id=?');
        $s->bind_param('i', $id); $s->execute(); $s->close();
        logAktivitas('UPDATE', 'master_indikator', $id, 'Aktifkan kembali master indikator ID ' . $id);
        setFlash('success', 'Master indikator diaktifkan kembali');
        header('Location: '.APP_URL.'/?page=master_indikator'); exit;
    }
    if ($aksi === 'hapus_permanen') {
        $s = $db->prepare('DELETE FROM master_indikator_kegiatan WHERE id=?');
        $s->bind_param('i', $id); $s->execute(); $s->close();
        logAktivitas('DELETE', 'master_indikator', $id, 'Hapus permanen master indikator ID ' . $id);
        setFlash('success', 'Master indikator dihapus permanen');
        header('Location: '.APP_URL.'/?page=master_indikator'); exit;
    }
    if ($aksi === 'import_lama') {
        $createdMaster = 0; $linkedProfiles = 0; $linkedItems = 0; $skippedProfiles = 0;
        $warningProfiles = [];
        $db->begin_transaction();
        try {
            $profiles = $db->query("SELECT id,tahun,unit_pemilik_risiko,program,kegiatan,sasaran,indikator_kinerja,target FROM profil_risiko WHERE TRIM(COALESCE(indikator_kinerja,'')) <> '' OR TRIM(COALESCE(target,'')) <> '' ORDER BY id")->fetch_all(MYSQLI_ASSOC) ?: [];
            $findMaster = $db->prepare('SELECT id FROM master_indikator_kegiatan WHERE tahun=? AND program=? AND kegiatan=? AND sasaran=? AND indikator=? AND target=? AND satuan=? AND unit_pemilik_risiko=? LIMIT 1');
            $insertMaster = $db->prepare('INSERT INTO master_indikator_kegiatan (tahun,program,kegiatan,sasaran,indikator,target,satuan,unit_pemilik_risiko,aktif,created_by) VALUES (?,?,?,?,?,?,?,?,1,?)');
            $findLink = $db->prepare('SELECT id FROM profil_risiko_indikator WHERE id_profil=? AND id_master=? LIMIT 1');
            $insertLink = $db->prepare('INSERT INTO profil_risiko_indikator (id_profil,id_master,tahun,program,kegiatan,sasaran,indikator,target,satuan) VALUES (?,?,?,?,?,?,?,?,?)');
            foreach ($profiles as $profile) {
                $indicators = $splitLegacyItems((string)($profile['indikator_kinerja'] ?? ''));
                $targets = $splitLegacyItems((string)($profile['target'] ?? ''));
                if (!$indicators) { $skippedProfiles++; continue; }
                if (count($indicators) !== count($targets) && count($indicators) > 1) {
                    $warningProfiles[] = (int)$profile['id'];
                }
                $profileLinked = false;
                foreach ($indicators as $index => $indicator) {
                    $target = $targets[$index] ?? (count($targets) === 1 ? $targets[0] : '');
                    $program = trim((string)($profile['program'] ?? ''));
                    $kegiatan = trim((string)($profile['kegiatan'] ?? ''));
                    $sasaran = trim((string)($profile['sasaran'] ?? ''));
                    $unit = trim((string)($profile['unit_pemilik_risiko'] ?? ''));
                    $satuan = '';
                    $findMaster->bind_param('ssssssss', $profile['tahun'],$program,$kegiatan,$sasaran,$indicator,$target,$satuan,$unit);
                    $findMaster->execute(); $master = $findMaster->get_result()->fetch_assoc();
                    if ($master) {
                        $masterId = (int)$master['id'];
                    } else {
                        $uid = (int)$_SESSION['user_id'];
                        $insertMaster->bind_param('ssssssssi', $profile['tahun'],$program,$kegiatan,$sasaran,$indicator,$target,$satuan,$unit,$uid);
                        $insertMaster->execute(); $masterId = (int)$db->insert_id; $createdMaster++;
                    }
                    $profileId = (int)$profile['id'];
                    $findLink->bind_param('ii', $profileId, $masterId); $findLink->execute();
                    if ($findLink->get_result()->fetch_assoc()) continue;
                    $insertLink->bind_param('iisssssss', $profileId,$masterId,$profile['tahun'],$program,$kegiatan,$sasaran,$indicator,$target,$satuan);
                    $insertLink->execute(); $linkedItems++; $profileLinked = true;
                }
                if ($profileLinked) $linkedProfiles++;
            }
            $findMaster->close(); $insertMaster->close(); $findLink->close(); $insertLink->close();
            $db->commit();
            $message = "Import selesai: {$createdMaster} master baru, {$linkedItems} pasangan terhubung ke {$linkedProfiles} profil.";
            if ($warningProfiles) $message .= ' Perlu cek pasangan indikator-target pada profil ID: '.implode(', ', $warningProfiles).'.';
            setFlash('success', $message);
        } catch (Throwable $e) {
            $db->rollback();
            error_log('[manris] import master indikator error: '.$e->getMessage());
            setFlash('error', 'Import gagal dan perubahan dibatalkan: '.$e->getMessage());
        }
        header('Location: '.APP_URL.'/?page=master_indikator'); exit;
    }
}

$search = trim($_GET['q'] ?? '');
$fTahun = trim($_GET['tahun'] ?? '');
$fStatus = trim($_GET['status'] ?? '');

$whereParts = ['1=1'];
$qParams = [];
$qTypes = '';
if ($search !== '') {
    $whereParts[] = '(m.program LIKE ? OR m.kegiatan LIKE ? OR m.sasaran LIKE ? OR m.indikator LIKE ? OR m.target LIKE ? OR m.unit_pemilik_risiko LIKE ?)';
    $qParams = array_fill(0, 6, '%'.$search.'%');
    $qTypes .= 'ssssss';
}
if ($fTahun !== '') {
    $whereParts[] = 'm.tahun = ?';
    $qParams[] = $fTahun; $qTypes .= 's';
}
if ($fStatus === 'aktif')      { $whereParts[] = 'm.aktif = 1'; }
elseif ($fStatus === 'nonaktif') { $whereParts[] = 'm.aktif = 0'; }
$whereStr = implode(' AND ', $whereParts);

$stmt = $db->prepare("SELECT m.*, u.nama AS pembuat FROM master_indikator_kegiatan m LEFT JOIN users u ON u.id=m.created_by WHERE $whereStr ORDER BY m.tahun DESC, m.program ASC, m.indikator ASC");
if ($qTypes) $stmt->bind_param($qTypes, ...$qParams);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

// ── Export Excel ──────────────────────────────────────────────
$exportType = $_GET['export'] ?? '';
if ($exportType === 'excel') {
    $headers = ['No', 'Tahun', 'Program', 'Kegiatan', 'Sasaran', 'Indikator', 'Target', 'Satuan', 'Unit Pemilik Risiko', 'Status', 'Dibuat Oleh'];
    $excelData = [];
    foreach ($rows as $i => $r) {
        $excelData[] = [
            $i + 1,
            $r['tahun'] ?? '',
            $r['program'] ?? '',
            $r['kegiatan'] ?? '',
            $r['sasaran'] ?? '',
            $r['indikator'] ?? '',
            $r['target'] ?? '',
            $r['satuan'] ?? '',
            $r['unit_pemilik_risiko'] ?? '',
            ((int)($r['aktif'] ?? 0) === 1) ? 'Aktif' : 'Nonaktif',
            $r['pembuat'] ?? '-',
        ];
    }
    exportExcel('master_indikator_' . date('Ymd_His'), $headers, $excelData);
}

// ── Export PDF ────────────────────────────────────────────────
if ($exportType === 'pdf') {
    $pdfFilterInfo = [];
    if ($fTahun !== '') $pdfFilterInfo[] = 'Tahun ' . xss($fTahun);
    if ($fStatus !== '') $pdfFilterInfo[] = ($fStatus === 'aktif' ? 'Aktif' : 'Nonaktif');
    if ($search !== '') $pdfFilterInfo[] = 'Cari: "' . xss($search) . '"';
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Master Indikator &amp; Target</title>
<style>
  body{font-family:Arial,sans-serif;font-size:9px;color:#1e293b;margin:10mm}
  h2{font-size:13px;text-align:center;margin-bottom:4px;color:#1e3a5f}
  p.sub{text-align:center;font-size:9px;color:#64748b;margin-bottom:10px}
  table{width:100%;border-collapse:collapse;font-size:8.5px}
  th{background:#1e3a5f;color:#fff;font-weight:700;padding:5px 6px;text-align:center;border:1px solid #cbd5e1}
  td{border:1px solid #cbd5e1;padding:4px 6px;vertical-align:top}
  tr:nth-child(even) td{background:#f8fafc}
  td.center{text-align:center}
  td.nonaktif{color:#94a3b8;font-style:italic}
  .footer{margin-top:12px;font-size:8px;color:#64748b;text-align:center;border-top:1px solid #e2e8f0;padding-top:6px}
  .print-bar{display:flex;gap:10px;align-items:center;padding:8px 14px;background:#eff6ff;border:1px dashed #93c5fd;border-radius:6px;margin-bottom:14px;font-size:12px}
  .btn-print{padding:7px 16px;background:#1e3a5f;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:12px;font-weight:700}
  @page{size:A4 landscape;margin:10mm}
  @media print{.print-bar{display:none!important}}
</style>
</head>
<body>
  <div class="print-bar">
    <button class="btn-print" onclick="window.print()">🖨 Cetak / PDF</button>
    <span style="color:#1d4ed8"><strong>Ctrl+P</strong> → Ukuran kertas: <strong>A4 Landscape</strong></span>
  </div>
  <h2>DAFTAR MASTER INDIKATOR &amp; TARGET</h2>
  <p class="sub"><?= APP_NAME ?> &nbsp;|&nbsp; Dicetak: <?= date('d M Y H:i') ?><?= $pdfFilterInfo ? ' &nbsp;|&nbsp; ' . implode(' • ', $pdfFilterInfo) : '' ?> &nbsp;|&nbsp; Total: <?= count($rows) ?> data</p>
  <table>
    <thead>
      <tr>
        <th style="width:28px">No</th>
        <th style="width:42px">Tahun</th>
        <th>Program</th>
        <th>Kegiatan</th>
        <th>Sasaran</th>
        <th>Indikator</th>
        <th>Target</th>
        <th style="width:55px">Satuan</th>
        <th>Unit Pemilik Risiko</th>
        <th style="width:50px">Status</th>
        <th style="width:75px">Dibuat Oleh</th>
      </tr>
    </thead>
    <tbody>
    <?php if(empty($rows)): ?>
      <tr><td colspan="11" class="center" style="color:#64748b;padding:16px">Belum ada data master indikator.</td></tr>
    <?php else: ?>
      <?php foreach($rows as $i => $r): ?>
      <tr class="<?= (int)($r['aktif']??0)===1?'':'nonaktif' ?>">
        <td class="center"><?= $i+1 ?></td>
        <td class="center"><?= xss($r['tahun']??'') ?></td>
        <td><?= xss($r['program']??'-') ?></td>
        <td><?= nl2br(xss($r['kegiatan']??'-')) ?></td>
        <td><?= nl2br(xss($r['sasaran']??'-')) ?></td>
        <td><?= nl2br(xss($r['indikator']??'-')) ?></td>
        <td><?= nl2br(xss($r['target']??'-')) ?><?= !empty($r['satuan'])?' '.xss($r['satuan']):'' ?></td>
        <td><?= xss($r['satuan']??'-') ?></td>
        <td><?= xss($r['unit_pemilik_risiko']??'Semua Unit') ?></td>
        <td class="center"><?= (int)($r['aktif']??0)===1?'Aktif':'Nonaktif' ?></td>
        <td><?= xss($r['pembuat']??'-') ?></td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
  <div class="footer">Sistem Informasi Manajemen Risiko (SEMAR) &nbsp;|&nbsp; <?= APP_NAME ?></div>
</body>
</html>
    <?php exit;
}

$tahunList = $db->query('SELECT DISTINCT tahun FROM master_indikator_kegiatan ORDER BY tahun DESC')->fetch_all(MYSQLI_ASSOC) ?: [];
$totalAll   = (int)($db->query('SELECT COUNT(*) FROM master_indikator_kegiatan')->fetch_row()[0] ?? 0);
$totalAktif = (int)($db->query('SELECT COUNT(*) FROM master_indikator_kegiatan WHERE aktif=1')->fetch_row()[0] ?? 0);
$totalNon   = $totalAll - $totalAktif;
?>
<div class="risiko-hero profil-risiko-hero" style="background:linear-gradient(115deg,#1f2937 0%,#374151 55%,#4b5563 100%); align-items: flex-start !important;">
  <div class="risiko-hero-copy">
    <div class="risiko-eyebrow"><i class="fas fa-database"></i> Data Master</div>
    <h1 class="page-title" style="color:#fff">Master Indikator &amp; Target</h1>
    <p class="page-sub" style="color:rgba(255,255,255,.8)">Kelola pasangan indikator dan target yang dapat dipilih pada Profil Risiko.</p>
  </div>
  <div class="profil-hero-tools risiko-hero-tools-align">
    <div style="display:flex;align-items:flex-start;gap:8px;flex-wrap:wrap">
      <?php if(!empty($tahunList)): ?>
      <select class="form-control hero-year-select wide" onchange="window.location.href='<?= APP_URL ?>/?page=master_indikator<?= $search !== '' ? '&q=' . urlencode($search) : '' ?>&tahun='+encodeURIComponent(this.value)" aria-label="Pilih tahun">
        <option value="">Semua Tahun</option>
        <?php foreach($tahunList as $t): ?>
        <option value="<?= xss($t['tahun']) ?>" <?= $fTahun===$t['tahun']?'selected':'' ?>><?= xss($t['tahun']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <?php $exportQs = http_build_query(array_filter(['page'=>'master_indikator','q'=>$search,'tahun'=>$fTahun,'status'=>$fStatus], fn($v)=>$v!=='')); ?>
      <div class="risiko-export-actions" style="margin-top:0">
        <a href="<?= APP_URL ?>/?<?= $exportQs ?>&export=excel" class="btn btn-hero-ghost"><i class="fas fa-file-excel"></i> Excel</a>
        <a href="<?= APP_URL ?>/?<?= $exportQs ?>&export=pdf" target="_blank" class="btn btn-hero-ghost"><i class="fas fa-file-pdf"></i> PDF</a>
      </div>
      <div class="risiko-hero-actions" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
        <button type="button" class="btn btn-hero-primary" onclick="showMasterIndicatorForm()"><i class="fas fa-plus"></i> Tambah</button>
      </div>
    </div>
  </div>

  <!-- Stat cards (glassmorphism inside hero) -->
  <div class="stats-grid cols-3" style="width:100%;margin-top:20px;margin-bottom:0">
    <div class="stat-card stat-card-glass" style="--ga:#60a5fa;--ga-tint:rgba(96,165,250,.3);--ga-line:rgba(96,165,250,.45);--ga-glow:rgba(96,165,250,.3)">
      <div class="stat-icon"><i class="fas fa-list-check"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $totalAll ?></div>
        <div class="stat-label">Total Master</div>
      </div>
    </div>
    <div class="stat-card stat-card-glass" style="--ga:#4ade80;--ga-tint:rgba(74,222,128,.28);--ga-line:rgba(74,222,128,.45);--ga-glow:rgba(74,222,128,.28)">
      <div class="stat-icon"><i class="fas fa-circle-check"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $totalAktif ?></div>
        <div class="stat-label">Aktif</div>
      </div>
    </div>
    <div class="stat-card stat-card-glass" style="--ga:#94a3b8;--ga-tint:rgba(148,163,184,.25);--ga-line:rgba(148,163,184,.4);--ga-glow:rgba(148,163,184,.25)">
      <div class="stat-icon"><i class="fas fa-circle-minus"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $totalNon ?></div>
        <div class="stat-label">Nonaktif</div>
      </div>
    </div>
  </div>
</div>

<div class="card" style="margin-bottom:18px;border-left:4px solid var(--accent)">
  <div class="card-body" style="padding:14px 18px">
    <strong><i class="fas fa-file-import"></i> Import data Profil Risiko lama</strong>
    <p style="margin:6px 0 10px;color:var(--text-muted);font-size:.84rem">Membuat master dari indikator dan target yang sudah tersimpan. Data master yang sama tidak dibuat ulang dan profil yang sudah terhubung tidak ditimpa.</p>
    <form method="post" onsubmit="return confirm('Import semua indikator dan target lama ke master sekarang?')">
      <?= csrfField() ?><input type="hidden" name="aksi" value="import_lama">
      <button class="btn btn-accent"><i class="fas fa-file-import"></i> Import Data Lama</button>
    </form>
  </div>
</div>

<!-- Form Tambah/Edit -->
<div class="card" id="formTambah" style="margin-bottom:18px;<?= $editRow ? '' : 'display:none' ?>">
  <div class="card-header"><span class="card-title"><?= $editRow ? '<i class="fas fa-edit"></i> Edit Master Indikator' : '<i class="fas fa-plus"></i> Tambah Master Indikator' ?></span></div>
  <div class="card-body">
    <form method="post">
      <?= csrfField() ?><input type="hidden" name="aksi" value="simpan"><input type="hidden" name="id" value="<?= $editRow['id'] ?? 0 ?>">
      <div class="form-row-2">
        <div class="form-group"><label class="form-label">Tahun <span class="required">*</span></label><input class="form-control" name="tahun" required value="<?= xss($editRow['tahun'] ?? date('Y')) ?>"></div>
        <div class="form-group"><label class="form-label">Unit Pemilik Risiko</label><input class="form-control" name="unit_pemilik_risiko" value="<?= xss($editRow['unit_pemilik_risiko'] ?? '') ?>"></div>
        <div class="form-group"><label class="form-label">Program</label><input class="form-control" name="program" value="<?= xss($editRow['program'] ?? '') ?>"></div>
        <div class="form-group"><label class="form-label">Kegiatan</label><textarea class="form-control" name="kegiatan" rows="2"><?= xss($editRow['kegiatan'] ?? '') ?></textarea></div>
        <div class="form-group"><label class="form-label">Sasaran</label><textarea class="form-control" name="sasaran" rows="2"><?= xss($editRow['sasaran'] ?? '') ?></textarea></div>
        <div class="form-group"><label class="form-label">Satuan</label><input class="form-control" name="satuan" placeholder="Persen, dokumen, orang, kegiatan" value="<?= xss($editRow['satuan'] ?? '') ?>"></div>
        <div class="form-group" style="grid-column:span 2"><label class="form-label">Indikator Kinerja Kegiatan <span class="required">*</span></label><textarea class="form-control" name="indikator" rows="3" required><?= xss($editRow['indikator'] ?? '') ?></textarea></div>
        <div class="form-group" style="grid-column:span 2"><label class="form-label">Target</label><textarea class="form-control" name="target" rows="2"><?= xss($editRow['target'] ?? '') ?></textarea></div>
      </div>
      <label style="display:flex;gap:8px;align-items:center;margin:8px 0 14px"><input type="checkbox" name="aktif" value="1" <?= (!$editRow || $editRow['aktif']) ? 'checked' : '' ?>> Aktif dan dapat dipilih user</label>
      <button class="btn btn-primary"><i class="fas fa-save"></i> Simpan</button>
      <?php if ($editRow): ?><a href="<?= APP_URL ?>/?page=master_indikator" class="btn btn-outline">Batal</a><?php endif; ?>
    </form>
  </div>
</div>

<script>
function showMasterIndicatorForm() {
  const form = document.getElementById('formTambah');
  if (!form) return;
  form.style.display = 'block';
  form.scrollIntoView({behavior: 'smooth', block: 'start'});
}
</script>

<!-- Tabel daftar -->
<div class="card">
  <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
    <span class="card-title"><i class="fas fa-table"></i> Daftar Master (<?= count($rows) ?>)</span>
    <form class="risiko-table-search" method="GET" action="<?= APP_URL ?>/" role="search" style="margin-bottom:0">
      <input type="hidden" name="page" value="master_indikator">
      <input type="hidden" name="tahun" value="<?= xss($fTahun) ?>">
      <div class="search-bar" style="margin-bottom:0">
        <i class="fas fa-search"></i>
        <input type="search" name="q" class="form-control" placeholder="Cari program, kegiatan, indikator..." value="<?= xss($search) ?>" aria-label="Cari">
      </div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="data-table no-datatable" style="font-size:.8rem">
      <thead><tr><th>No</th><th>Tahun</th><th>Program/Kegiatan</th><th>Indikator</th><th>Target</th><th>Unit</th><th>Status</th><th style="text-align:center">Aksi</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
      <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--text-muted)"><i class="fas fa-inbox" style="font-size:1.6rem;opacity:.3;display:block;margin-bottom:8px"></i>Belum ada master indikator<?= ($search||$fTahun||$fStatus)?' yang cocok':'' ?>.</td></tr>
      <?php else: foreach ($rows as $i => $r): ?>
      <tr style="<?= $r['aktif']?'':'opacity:.65' ?>">
        <td><?= $i + 1 ?></td>
        <td><strong><?= xss($r['tahun']) ?></strong></td>
        <td><?= xss($r['program'] ?: '-') ?><br><small style="color:var(--text-muted)"><?= xss(mb_strimwidth($r['kegiatan'] ?: '-',0,60,'...')) ?></small></td>
        <td style="max-width:260px"><?= nl2br(xss($r['indikator'])) ?></td>
        <td style="max-width:200px"><?= nl2br(xss($r['target'] ?: '-')) ?><?= $r['satuan'] ? ' '.xss($r['satuan']) : '' ?></td>
        <td><?= xss($r['unit_pemilik_risiko'] ?: 'Semua Unit') ?></td>
        <td>
          <?php if($r['aktif']): ?>
          <span class="badge badge-success">Aktif</span>
          <?php else: ?>
          <span class="badge badge-secondary">Nonaktif</span>
          <?php endif; ?>
        </td>
        <td style="white-space:nowrap;text-align:center">
          <div class="act-btn-group">
            <a class="act-btn act-btn-edit" href="<?= APP_URL ?>/?page=master_indikator&edit=<?= $r['id'] ?>" title="Edit"><i class="fas fa-edit"></i></a>
            <?php if ($r['aktif']): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Nonaktifkan master ini?')"><?= csrfField() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="act-btn act-btn-toggle" title="Nonaktifkan"><i class="fas fa-toggle-on"></i></button></form>
            <?php else: ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Aktifkan kembali master ini?')"><?= csrfField() ?><input type="hidden" name="aksi" value="aktifkan"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="act-btn act-btn-toggle" title="Aktifkan kembali"><i class="fas fa-toggle-off"></i></button></form>
            <form method="post" style="display:inline" onsubmit="return confirm('Hapus permanen master ini? Tindakan tidak bisa dibatalkan.')"><?= csrfField() ?><input type="hidden" name="aksi" value="hapus_permanen"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="act-btn act-btn-delete" title="Hapus permanen"><i class="fas fa-trash"></i></button></form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
