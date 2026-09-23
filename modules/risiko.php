<?php
/**
 * MODUL MANAJEMEN RISIKO — CRUD Lengkap
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$db = getDB();

// Migrasi satu kali: risiko pending/rejected lama dianggap approved (proses review dihilangkan).
$db->query("UPDATE risiko SET approval_status='approved' WHERE deleted_at IS NULL AND approval_status IN ('pending','rejected')");

// Nilai 0 berasal dari data lama saat kolom sumber masih bertipe ENUM.
// Fungsi global tersedia di includes/functions.php; guard mencegah redeclare.
if (!function_exists('normalizeSumberRisiko')) {
    function normalizeSumberRisiko($value): string
    {
        $value = trim((string)$value);
        return ($value === '' || $value === '0') ? 'Internal' : $value;
    }
}

// ── Kategori untuk dropdown ───────────────────────────────────
// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { setFlash('error','Token tidak valid'); header('Location: '.APP_URL.'/?page=risiko'); exit; }
    requireRole('Admin','Risk Manager');

    $aksi = $_POST['aksi'] ?? '';

    if (in_array($aksi, ['approve', 'reject'], true)) {
        if (!hasRole('Admin', 'Risk Manager', 'Pimpinan')) {
            setFlash('error', 'Anda tidak memiliki hak untuk memproses review risiko.');
            header('Location: '.APP_URL.'/?page=risiko'); exit;
        }
        $reviewId = (int)($_POST['id'] ?? 0);
        $reviewNote = trim($_POST['approval_note'] ?? '');
        $newApproval = $aksi === 'approve' ? 'approved' : 'rejected';
        $newStatus = $aksi === 'approve' ? 'Teridentifikasi' : 'Teridentifikasi';
        $reviewUser = (int)$_SESSION['user_id'];
        $stmt = $db->prepare('UPDATE risiko SET approval_status=?, approved_by=?, approved_at=NOW(), approval_note=?, status=? WHERE id=? AND deleted_at IS NULL');
        $stmt->bind_param('sissi', $newApproval, $reviewUser, $reviewNote, $newStatus, $reviewId);
        $stmt->execute(); $stmt->close();
        logAktivitas($newApproval === 'approved' ? 'APPROVE' : 'REJECT', 'risiko', $reviewId, 'Review risiko: '.$newApproval);
        $ownerStmt = $db->prepare('SELECT id_user_input, kode_risiko, nama_risiko FROM risiko WHERE id=? LIMIT 1');
        $ownerStmt->bind_param('i', $reviewId); $ownerStmt->execute();
        $reviewedRisk = $ownerStmt->get_result()->fetch_assoc(); $ownerStmt->close();
        if ($reviewedRisk && (int)$reviewedRisk['id_user_input'] !== (int)$_SESSION['user_id']) {
            $reviewTitle = $newApproval === 'approved' ? 'Risiko Disetujui' : 'Risiko Ditolak';
            $reviewMessage = "Risiko {$reviewedRisk['kode_risiko']} - {$reviewedRisk['nama_risiko']} telah " . ($newApproval === 'approved' ? 'disetujui.' : 'ditolak.') . ($reviewNote !== '' ? "\nCatatan: {$reviewNote}" : '');
            notifikasi((int)$reviewedRisk['id_user_input'], 'approval', $reviewTitle, $reviewMessage, APP_URL.'/?page=risiko&detail='.$reviewId);
        }
        setFlash('success', $newApproval === 'approved' ? 'Risiko disetujui.' : 'Risiko ditolak.');
        header('Location: '.APP_URL.'/?page=risiko'); exit;
    }

    if ($aksi === 'simpan') {
        $id       = (int)($_POST['id'] ?? 0);
        $reqKode  = trim($_POST['kode_risiko'] ?? '');
        // Penilaian P/D dilakukan hanya pada Profil Risiko, bukan master.
        $prob     = 1;
        $dmpk     = 1;
        $bobot    = getBobot($prob, $dmpk);
        $skor     = (int)round($prob * $dmpk * $bobot);
        $level    = getLevelRisiko($skor);
        $fields   = [
            'nama_kegiatan'        => trim($_POST['nama_kegiatan'] ?? ''),
            'nama_risiko'          => trim($_POST['nama_risiko'] ?? ''),
            'id_kategori'          => (int)($_POST['id_kategori'] ?? 0) ?: null,
            'sumber'               => trim($_POST['sumber'] ?? 'Internal'),
            'deskripsi'            => trim($_POST['deskripsi'] ?? ''),
            'penyebab'             => trim($_POST['penyebab'] ?? ''),
            'dampak'               => trim($_POST['dampak'] ?? ''),
            'probabilitas'         => $prob,
            'dampak_level'         => $dmpk,
            'bobot'                => $bobot,
            'skor_risiko'          => $skor,
            'level_risiko'         => $level,
            'pemilik_risiko'       => trim($_POST['pemilik_risiko'] ?? ''),
            'departemen'           => trim($_POST['departemen'] ?? ''),
            'rencana_pengendalian' => '',
            'jadwal'               => null,
            'tanggal_identifikasi' => $_POST['tanggal_identifikasi'] ?? date('Y-m-d'),
            'status'               => $_POST['status'] ?? 'Teridentifikasi',
        ];
        if (empty($fields['nama_risiko']) ) {
            setFlash('error','Nama risiko dan kategori wajib diisi'); header('Location: '.APP_URL.'/?page=risiko'); exit;
        }
        if ($id > 0 && !ownsRecord($db, 'risiko', $id, 'id_user_input')) {
            setFlash('error', 'Anda tidak memiliki hak untuk mengubah risiko ini.');
            header('Location: '.APP_URL.'/?page=risiko'); exit;
        }
        if ($id > 0) {
            // UPDATE — 17 SET + 1 WHERE = 18 bind vars
            if (empty($reqKode)) {
                $oldQ = $db->prepare('SELECT kode_risiko FROM risiko WHERE id=?');
                $oldQ->bind_param('i', $id); $oldQ->execute();
                $oldR = $oldQ->get_result()->fetch_assoc(); $oldQ->close();
                $reqKode = $oldR['kode_risiko'];
            }
            $stmt = $db->prepare('UPDATE risiko SET kode_risiko=?,nama_kegiatan=?,nama_risiko=?,id_kategori=?,sumber=?,deskripsi=?,penyebab=?,dampak=?,probabilitas=?,dampak_level=?,bobot=?,skor_risiko=?,level_risiko=?,pemilik_risiko=?,departemen=?,rencana_pengendalian=?,jadwal=?,tanggal_identifikasi=?,status=? WHERE id=?');
            $stmt->bind_param(
                'sssiisssiidssssssssi',
                $reqKode,
                $fields['nama_kegiatan'],
                $fields['nama_risiko'],
                $fields['id_kategori'],
                $fields['sumber'],
                $fields['deskripsi'],
                $fields['penyebab'],
                $fields['dampak'],
                $fields['probabilitas'],
                $fields['dampak_level'],
                $fields['bobot'],
                $fields['skor_risiko'],
                $fields['level_risiko'],
                $fields['pemilik_risiko'],
                $fields['departemen'],
                $fields['rencana_pengendalian'],
                $fields['jadwal'],
                $fields['tanggal_identifikasi'],
                $fields['status'],
                $id
            );
            $stmt->execute(); $stmt->close();
            logAktivitas('UPDATE','risiko',$id,'Update risiko: '.$fields['nama_risiko']);
            setFlash('success','Risiko berhasil diperbarui');
        } else {
            // INSERT — 18 kolom = 18 bind vars
            $uid  = (int)$_SESSION['user_id'];
            $kode = !empty($reqKode) ? $reqKode : generateKodeRisiko($uid);
            // Risiko baru langsung disetujui (tanpa proses review).
            $approvalStatus = 'approved';
            $stmt = $db->prepare('INSERT INTO risiko (kode_risiko,nama_kegiatan,nama_risiko,id_kategori,sumber,deskripsi,penyebab,dampak,probabilitas,dampak_level,bobot,skor_risiko,level_risiko,pemilik_risiko,departemen,rencana_pengendalian,jadwal,tanggal_identifikasi,status,approval_status,id_user_input) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->bind_param(
                'sssiisssiidissssssssi',
                $kode,
                $fields['nama_kegiatan'],
                $fields['nama_risiko'],
                $fields['id_kategori'],
                $fields['sumber'],
                $fields['deskripsi'],
                $fields['penyebab'],
                $fields['dampak'],
                $fields['probabilitas'],
                $fields['dampak_level'],
                $fields['bobot'],
                $fields['skor_risiko'],
                $fields['level_risiko'],
                $fields['pemilik_risiko'],
                $fields['departemen'],
                $fields['rencana_pengendalian'],
                $fields['jadwal'],
                $fields['tanggal_identifikasi'],
                $fields['status'],
                $approvalStatus,
                $uid
            );
            try {
                $stmt->execute(); $newId = $db->insert_id; $stmt->close();
            } catch (mysqli_sql_exception $e) {
                $stmt->close();
                if (str_contains($e->getMessage(), 'Duplicate')) {
                    // Race condition: kode dipakai sesaat sebelum insert.
                    // Generate ulang dengan suffix unik.
                    $kode = $kode . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
                    $stmt2 = $db->prepare('INSERT INTO risiko (kode_risiko,nama_kegiatan,nama_risiko,id_kategori,sumber,deskripsi,penyebab,dampak,probabilitas,dampak_level,bobot,skor_risiko,level_risiko,pemilik_risiko,departemen,rencana_pengendalian,jadwal,tanggal_identifikasi,status,approval_status,id_user_input) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                    $stmt2->bind_param(
                        'sssiisssiidissssssssi',
                        $kode,
                        $fields['nama_kegiatan'],
                        $fields['nama_risiko'],
                        $fields['id_kategori'],
                        $fields['sumber'],
                        $fields['deskripsi'],
                        $fields['penyebab'],
                        $fields['dampak'],
                        $fields['probabilitas'],
                        $fields['dampak_level'],
                        $fields['bobot'],
                        $fields['skor_risiko'],
                        $fields['level_risiko'],
                        $fields['pemilik_risiko'],
                        $fields['departemen'],
                        $fields['rencana_pengendalian'],
                        $fields['jadwal'],
                        $fields['tanggal_identifikasi'],
                        $fields['status'],
                        $approvalStatus,
                        $uid
                    );
                    $stmt2->execute(); $newId = $db->insert_id; $stmt2->close();
                } else {
                    error_log('[manris] INSERT risiko error: ' . $e->getMessage());
                    setFlash('error', 'Gagal menyimpan risiko: ' . $e->getMessage());
                    header('Location: '.APP_URL.'/?page=risiko'); exit;
                }
            }
            logAktivitas('CREATE','risiko',$newId,'Tambah risiko: '.$kode);
            setFlash('success','Risiko '.$kode.' berhasil ditambahkan');
        }
    } elseif ($aksi === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        requireRole('Admin', 'Risk Manager');

        $stmt = $db->prepare('SELECT id, kode_risiko, nama_risiko, id_user_input, approval_status FROM risiko WHERE id=? AND deleted_at IS NULL');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $risiko = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$risiko) {
            setFlash('error', 'Risiko tidak ditemukan.');
            header('Location: '.APP_URL.'/?page=risiko'); exit;
        }

        // Risk Manager hanya dapat menghapus risiko miliknya sendiri.
        if (hasRole('Risk Manager') && !hasRole('Admin')
            && (int)$risiko['id_user_input'] !== (int)$_SESSION['user_id']) {
            setFlash('error', 'Risk Manager hanya dapat menghapus risiko miliknya sendiri.');
            header('Location: '.APP_URL.'/?page=risiko'); exit;
        }
        
        $refs = 0;
        $checks = [
            ['SELECT COUNT(*) FROM profil_risiko_detail WHERE kode_risiko=?', 's'],
            ['SELECT COUNT(*) FROM kkpr_risiko WHERE kode_risiko=?', 's'],
            ['SELECT COUNT(*) FROM mitigasi WHERE id_risiko=?', 'i'],
        ];
        foreach ($checks as [$sql, $type]) {
            $check = $db->prepare($sql);
            if ($type === 's') $check->bind_param('s', $risiko['kode_risiko']); else $check->bind_param('i', $id);
            $check->execute();
            $refs += (int)$check->get_result()->fetch_row()[0];
            $check->close();
        }

        // Data draft/rejected yang belum dipakai dokumen aman dihapus total.
        // Kode kosong dapat digunakan kembali untuk koreksi saat uji coba.
        if (in_array($risiko['approval_status'] ?? 'approved', ['draft', 'rejected'], true) && $refs === 0) {
            $stmt = $db->prepare('DELETE FROM risiko WHERE id=?');
            $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
            logAktivitas('DELETE', 'risiko', $id, 'Hapus draft tanpa referensi: ' . $risiko['kode_risiko'], $risiko);
            setFlash('success', 'Risiko draft dihapus. Kode ' . $risiko['kode_risiko'] . ' dapat dipakai kembali.');
        } else {
            $deletedBy = (int)$_SESSION['user_id'];
            $stmt = $db->prepare('UPDATE risiko SET deleted_at=NOW(), deleted_by=? WHERE id=? AND deleted_at IS NULL');
            $stmt->bind_param('ii', $deletedBy, $id); $stmt->execute(); $stmt->close();
            logAktivitas('DELETE', 'risiko', $id, 'Soft delete risiko: ' . $risiko['kode_risiko'], $risiko, ['deleted_by' => $deletedBy]);
            setFlash('success', 'Risiko dinonaktifkan karena sudah disetujui atau digunakan dokumen; Admin dapat memulihkannya.');
        }
    }
    header('Location: '.APP_URL.'/?page=risiko'); exit;
}

// ── Filter & Search ───────────────────────────────────────────
$search    = trim($_GET['q'] ?? '');
$fSumber   = trim($_GET['sumber'] ?? '');
$fStatus   = trim($_GET['status'] ?? '');
$fApproval = trim($_GET['approval'] ?? '');
$fLevel    = trim($_GET['level'] ?? '');
$fDept     = trim($_GET['departemen'] ?? '');
$page_num  = max(1,(int)($_GET['p'] ?? 1));
$perPage   = (isset($_GET['limit']) && in_array((int)$_GET['limit'], [5, 10, 15, 20, 25])) ? (int)$_GET['limit'] : 10;
$showDeleted = hasRole('Admin') && isset($_GET['deleted']) && $_GET['deleted'] === '1';

$where = [$showDeleted ? 'r.deleted_at IS NOT NULL' : 'r.deleted_at IS NULL'];
if (!hasRole('Admin', 'Pimpinan')) {
    $where[] = "r.id_user_input = " . (int)$_SESSION['user_id'];
}
$params = []; $types = '';

if ($search)  { $where[] = '(r.nama_risiko LIKE ? OR r.kode_risiko LIKE ? OR r.deskripsi LIKE ?)'; $s="%$search%"; $params[]=$s;$params[]=$s;$params[]=$s; $types.='sss'; }
if ($fSumber) { $where[] = 'r.sumber = ?'; $params[]=$fSumber; $types.='s'; }
if ($fStatus) { $where[] = 'r.status = ?'; $params[]=$fStatus; $types.='s'; }
if ($fApproval && in_array($fApproval, ['pending', 'approved', 'rejected', 'draft'], true)) { $where[] = 'r.approval_status = ?'; $params[]=$fApproval; $types.='s'; }
if ($fLevel)  { 
    if ($fLevel === 'tinggi_plus') {
        $where[] = 'r.skor_risiko >= 15';
    } else {
        $where[] = 'r.level_risiko = ?'; $params[]=$fLevel; $types.='s'; 
    }
}
if ($fDept)   { $where[] = 'r.departemen LIKE ?'; $params[]="%$fDept%"; $types.='s'; }

$whereStr = implode(' AND ', $where);

// ── Export Excel / PDF (fetch ALL rows tanpa pagination) ─────
$export = $_GET['export'] ?? '';
if (in_array($export, ['excel', 'pdf'], true)) {
    $sqlExport = "SELECT r.*, k.nama AS kategori_nama FROM risiko r LEFT JOIN kategori_risiko k ON r.id_kategori=k.id WHERE $whereStr ORDER BY UPPER(SUBSTRING_INDEX(SUBSTRING_INDEX(r.kode_risiko, '-', 1), '.', 1)) ASC, CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(r.kode_risiko, '-', '.'), '.', 2), '.', -1) AS UNSIGNED) ASC, r.kode_risiko ASC, r.created_at ASC";
    $eStmt = $db->prepare($sqlExport);
    if ($types) $eStmt->bind_param($types, ...$params);
    $eStmt->execute();
    $expRows = $eStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($expRows as &$r) {
        $r['sumber'] = normalizeSumberRisiko($r['sumber'] ?? '');
        if ($r['departemen'] === 'Administrasi Umum') {
            $r['departemen'] = 'Kepala Subbagian Administrasi Umum';
        }
    }
    unset($r);
    $eStmt->close();

    if ($export === 'excel') {
        $headers = ['No', 'Kode Risiko', 'Nama Kegiatan', 'Nama Risiko', 'Kategori', 'Sumber', 'Sebab', 'Dampak', 'Pemilik Risiko', 'Pengelola Risiko', 'Tanggal Identifikasi'];
        $excelRows = [];
        foreach ($expRows as $i => $r) {
            $excelRows[] = [
                $i + 1,
                $r['kode_risiko'],
                $r['nama_kegiatan'] ?? '',
                $r['nama_risiko'],
                $r['kategori_nama'] ?? '',
                $r['sumber'],
                formatUraianList($r['penyebab'] ?? '', 'text'),
                formatUraianList($r['dampak'] ?? '', 'text'),
                $r['pemilik_risiko'],
                $r['departemen'],
                tglIndo($r['tanggal_identifikasi']),
            ];
        }
        exportExcel('daftar_risiko_' . date('Ymd_His'), $headers, $excelRows);
    }

    if ($export === 'pdf') {
        $filterInfo = [];
        if ($search) $filterInfo[] = 'Cari: "' . $search . '"';
        if ($fSumber) $filterInfo[] = 'Sumber: ' . $fSumber;
        if ($fStatus) $filterInfo[] = 'Status: ' . $fStatus;
        if ($fDept) $filterInfo[] = 'Pengelola: ' . $fDept;
        ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Identifikasi Risiko— <?= APP_NAME ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:11px}
body{font-family:Arial,Helvetica,sans-serif;color:#1e293b;background:#fff;padding:22px 26px;line-height:1.5}
.print-bar{display:flex;gap:10px;align-items:center;padding:8px 14px;background:#eff6ff;border:1px dashed #93c5fd;border-radius:6px;margin-bottom:14px;font-size:12px}
.btn-print{padding:7px 16px;background:#1e3a5f;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:12px;font-weight:700}
.kop{display:flex;align-items:center;justify-content:space-between;border-bottom:3px solid #1e3a5f;padding-bottom:12px;margin-bottom:14px}
.kop-title{font-size:15px;font-weight:700;color:#1e3a5f}
.kop-sub{font-size:10px;color:#64748b;margin-top:2px}
.kop-meta{text-align:right;font-size:10px;color:#64748b;line-height:1.7}
.kop-meta strong{color:#1e3a5f}
.filter-info{background:#f0f4f8;border-left:4px solid #3b82f6;border-radius:0 6px 6px 0;padding:8px 14px;margin-bottom:14px;font-size:10px;color:#475569}
.filter-info span{margin-right:14px}
.filter-info strong{color:#1e3a5f}
.stat-row{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:14px}
.stat-box{border-radius:6px;padding:8px 12px;color:#fff;font-size:10px}
.stat-box .val{font-size:1.6rem;font-weight:800;line-height:1}
.stat-box .lbl{margin-top:2px;opacity:.9}
.sb-total{background:#3b82f6}
.sb-tinggi{background:#dc2626}
.sb-sedang{background:#FFFF00;color:#1e293b}
.sb-rendah{background:#22c55e}
table{width:100%;border-collapse:collapse;font-size:9px}
th,td{border:1px solid #cbd5e1;padding:4px 6px;vertical-align:top}
th{background:#1e3a5f;color:#fff;font-weight:700;text-align:center}
td.center{text-align:center}
td.strong{font-weight:700}
.badge{display:inline-block;padding:1px 6px;border-radius:8px;font-size:7.5px;font-weight:700;color:#fff}
.b-st{background:#64748b}.b-ip{background:#0ea5e9}.b-rj{background:#dc2626}.b-ap{background:#16a34a}
.lv-sangat-tinggi{background:#dc2626}.lv-tinggi{background:#f97316}.lv-sedang{background:#FFFF00;color:#1e293b}.lv-rendah{background:#22c55e}.lv-sangat-rendah{background:#3b82f6}
tr:nth-child(even) td{background:#f8fafc}
.footer{margin-top:12px;padding-top:8px;border-top:1px solid #e2e8f0;font-size:9px;color:#64748b;display:flex;justify-content:space-between}
@page{size:A4 landscape;margin:8mm}
@media print{body{padding:0}.print-bar{display:none!important}}
</style>
</head>
<body>
<div class="print-bar">
  <button class="btn-print" onclick="window.print()">🖨 Cetak / PDF</button>
  <span style="color:#1d4ed8"><strong>Ctrl+P</strong> → Ukuran kertas: <strong>A4 Landscape</strong></span>
</div>

<div class="kop">
  <div>
    <div class="kop-title"><?= APP_NAME ?></div>
    <div class="kop-sub">Identifikasi Risiko</div>
  </div>
    <div class="kop-meta">
    Dicetak: <strong><?= date('d/m/Y H:i:s') ?></strong><br>
    Total: <strong><?= count($expRows) ?> risiko</strong>
  </div>
</div>

<?php if (!empty($filterInfo)): ?>
<div class="filter-info">
  <strong>Filter Aktif:</strong>
  <?php foreach ($filterInfo as $fi): ?>
  <span><?= xss($fi) ?></span>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<table>
  <thead>
    <tr>
      <th style="width:22px">No</th>
       <th style="width:50px">Kode Risiko</th>
       <th>Nama Kegiatan</th>
       <th>Nama Risiko</th>
       <th style="width:70px">Kategori</th>
       <th style="width:60px">Sumber</th>
       <th>Sebab</th>
       <th>Dampak</th>
       <th>Pemilik Risiko</th>
       <th style="width:60px">Pengelola Risiko</th>
       <th style="width:60px">Tanggal Identifikasi</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($expRows)): ?>
     <tr><td colspan="11" style="text-align:center;padding:20px;color:#94a3b8">Tidak ada data risiko</td></tr>
  <?php else: ?>
    <?php foreach ($expRows as $i => $r): ?>
      <?php
        $stCls = match($r['status']) {
          'Ditutup' => 'b-ap',
          'Ditangani' => 'b-ip',
          'Dimonitor' => 'b-st',
          default => 'b-rj',
        };
      ?>
    <tr>
      <td class="center"><?= $i + 1 ?></td>
      <td class="center"><code><?= xss($r['kode_risiko']) ?></code></td>
      <td style="font-size:8.5px"><?= xss($r['nama_kegiatan'] ?? '-') ?></td>
      <td><strong><?= xss($r['nama_risiko']) ?></strong></td>
      <td style="font-size:8.5px"><?= xss($r['kategori_nama'] ?: '-') ?></td>
      <td><?= xss($r['sumber']) ?></td>
      <td style="font-size:8.5px;max-width:140px"><?= formatUraianList($r['penyebab'] ?: '-') ?></td>
      <td style="font-size:8.5px;max-width:140px"><?= formatUraianList($r['dampak'] ?: '-') ?></td>
      <td style="font-size:8.5px"><?= xss($r['pemilik_risiko'] ?: '-') ?></td>
      <td style="font-size:8.5px"><?= xss($r['departemen'] ?: '-') ?></td>
      <td class="center" style="white-space:nowrap"><?= tglIndo($r['tanggal_identifikasi']) ?></td>
    </tr>
    <?php endforeach; ?>
  <?php endif; ?>
  </tbody>
</table>

<div class="footer">
  <span><?= APP_NAME ?> — Identifikasi Risiko digenerate otomatis pada <?= date('d/m/Y H:i:s') ?></span>
  <span>Total: <?= count($expRows) ?> risiko</span>
</div>

</body>
</html>
        <?php
        exit;
    }
}

// Total
$cntStmt = $db->prepare("SELECT COUNT(*) FROM risiko r WHERE $whereStr");
if ($types) $cntStmt->bind_param($types, ...$params);
$cntStmt->execute();
$total = $cntStmt->get_result()->fetch_row()[0];
$cntStmt->close();

// Statistik ringkas (scope pengguna sama dengan $whereStr)
$cntApproved = 0; $cntTinggi = 0;
$statSql = "SELECT COUNT(*) AS jml, SUM(r.approval_status='approved') AS appr, SUM(r.level_risiko IN ('Tinggi','Sangat Tinggi')) AS tggi FROM risiko r WHERE $whereStr";
$statStmt = $db->prepare($statSql);
if ($types) $statStmt->bind_param($types, ...$params);
$statStmt->execute();
$statRow = $statStmt->get_result()->fetch_assoc();
$statStmt->close();
$cntApproved = (int)($statRow['appr'] ?? 0);
$cntTinggi   = (int)($statRow['tggi'] ?? 0);

$pg = paginate($total, $perPage, $page_num, APP_URL.'/?page=risiko&q='.urlencode($search).'&sumber='.$fSumber.'&status='.urlencode($fStatus).'&level='.urlencode($fLevel).'&departemen='.urlencode($fDept).'&limit='.$perPage);
$offset = $pg['offset'];

// Fetch rows
$sql = "SELECT r.*, k.nama AS kategori_nama FROM risiko r LEFT JOIN kategori_risiko k ON r.id_kategori=k.id WHERE $whereStr ORDER BY UPPER(SUBSTRING_INDEX(SUBSTRING_INDEX(r.kode_risiko, '-', 1), '.', 1)) ASC, CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(r.kode_risiko, '-', '.'), '.', 2), '.', -1) AS UNSIGNED) ASC, r.kode_risiko ASC, r.created_at ASC LIMIT ? OFFSET ?";
$dTypes = $types.'ii';
$dParams = array_merge($params, [$perPage, $pg['offset']]);
$dStmt = $db->prepare($sql);
$dStmt->bind_param($dTypes, ...$dParams);
$dStmt->execute();
$rows = $dStmt->get_result()->fetch_all(MYSQLI_ASSOC);
foreach ($rows as &$r) {
    $r['sumber'] = normalizeSumberRisiko($r['sumber'] ?? '');
    if ($r['departemen'] === 'Administrasi Umum') {
        $r['departemen'] = 'Kepala Subbagian Administrasi Umum';
    }
}
unset($r);
$dStmt->close();

// Daftar kategori untuk dropdown form
$kategoriList = $db->query("SELECT id, nama FROM kategori_risiko ORDER BY nama ASC")->fetch_all(MYSQLI_ASSOC) ?: [];
$detailId = (int)($_GET['detail'] ?? 0);
$detailRow = null;
if ($detailId > 0) {
    $s = $db->prepare('SELECT r.* FROM risiko r WHERE r.id=?');
    $s->bind_param('i',$detailId); $s->execute();
    $detailRow = $s->get_result()->fetch_assoc(); $s->close();
    if ($detailRow) {
        $detailRow['sumber'] = normalizeSumberRisiko($detailRow['sumber'] ?? '');
    }

    // Ambil data penilaian (P/D/bobot/nilai/tingkat) dari profil_risiko_detail
    // jika risiko ini sudah dinilai di Profil Risiko.
    if ($detailRow) {
        $pd = $db->prepare("SELECT probabilitas, dampak, bobot, nilai, tingkat_risiko, rencana_penanganan, jadwal_pelaksanaan, penanggungjawab FROM profil_risiko_detail WHERE kode_risiko=? ORDER BY id DESC LIMIT 1");
        $pd->bind_param('s', $detailRow['kode_risiko']);
        $pd->execute();
        $penilaian = $pd->get_result()->fetch_assoc();
        $pd->close();
    }
}


// Hitung statistik global untuk hero (menggunakan kondisi role pengguna, abaikan filter saat ini)
$globalWhere = hasRole('Admin', 'Pimpinan') ? "deleted_at IS NULL" : "deleted_at IS NULL AND id_user_input = " . (int)$_SESSION['user_id'];
$statHeroSql = "SELECT 
    COUNT(*) as total, 
    SUM(status = 'Aktif') as aktif,
    SUM(approval_status = 'draft') as draft 
    FROM risiko WHERE $globalWhere";
$statHeroStmt = $db->query($statHeroSql);
$heroStats = $statHeroStmt->fetch_assoc();
$totalMaster = (int)$heroStats['total'];
$activeMaster = (int)$heroStats['aktif'];
$draftMaster = (int)$heroStats['draft'];
?>
<div class="risiko-hero profil-risiko-hero" style="background:linear-gradient(115deg, #1e3a8a 0%, #1d4ed8 55%, #2563eb 100%); align-items: flex-start !important;">
  <div class="risiko-hero-copy">
    <div class="risiko-eyebrow"><i class="fas fa-exclamation-triangle"></i> Tahap 1 dari 3</div>
    <h1 class="page-title">Identifikasi Risiko</h1>
    <p class="page-sub">Catat master risiko (nama, sebab, dampak, unit). Penilaian P/D dan rencana penanganan diisi pada Profil Risiko.</p>
  </div>
  <div class="profil-hero-tools risiko-hero-tools-align">
    <?php if(hasRole('Admin','Risk Manager')): ?>
    <div class="risiko-export-actions" style="margin-top:0">
      <a href="<?= APP_URL ?>/?page=risiko&q=<?= urlencode($search) ?>&sumber=<?= urlencode($fSumber) ?>&status=<?= urlencode($fStatus) ?>&level=<?= urlencode($fLevel) ?>&departemen=<?= urlencode($fDept) ?>&export=excel" class="btn btn-hero-ghost"><i class="fas fa-file-excel"></i> Excel</a>
      <a href="<?= APP_URL ?>/?page=risiko&q=<?= urlencode($search) ?>&sumber=<?= urlencode($fSumber) ?>&status=<?= urlencode($fStatus) ?>&level=<?= urlencode($fLevel) ?>&departemen=<?= urlencode($fDept) ?>&export=pdf" target="_blank" class="btn btn-hero-ghost"><i class="fas fa-file-pdf"></i> PDF</a>
    </div>
    <div class="risiko-hero-actions">
      <button class="btn btn-hero-primary" type="button" onclick="document.getElementById('formId').value='0';document.getElementById('modalTambahTitle').innerHTML='<i class=\'fas fa-plus-circle\'></i> Tambah Risiko Baru';openModal('modalTambah')">
        <i class="fas fa-plus" style="margin-right: 6px;"></i> Tambah Risiko
      </button>
    </div>
    <?php endif; ?>
  </div>

  <!-- Stat cards (gaya monev_tahunan) -->
  <div class="stats-grid cols-3" style="width:100%;margin-top:20px;margin-bottom:0">
    <a href="<?= APP_URL ?>/?page=risiko" class="stat-card stat-card-glass" style="--ga:#60a5fa;--ga-tint:rgba(96,165,250,.3);--ga-line:rgba(96,165,250,.45);--ga-glow:rgba(96,165,250,.3)">
      <div class="stat-icon"><i class="fas fa-clipboard-list"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $totalMaster ?></div>
        <div class="stat-label">Total Risiko</div>
      </div>
    </a>
    <a href="<?= APP_URL ?>/?page=risiko&status=Aktif" class="stat-card stat-card-glass" style="--ga:#4ade80;--ga-tint:rgba(74,222,128,.28);--ga-line:rgba(74,222,128,.45);--ga-glow:rgba(74,222,128,.28)">
      <div class="stat-icon"><i class="fas fa-circle-check"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $activeMaster ?></div>
        <div class="stat-label">Risiko Aktif</div>
      </div>
    </a>
    <a href="<?= APP_URL ?>/?page=risiko&approval=draft" class="stat-card stat-card-glass" style="--ga:#fbbf24;--ga-tint:rgba(251,191,36,.3);--ga-line:rgba(251,191,36,.45);--ga-glow:rgba(251,191,36,.3)">
      <div class="stat-icon"><i class="fas fa-pen-to-square"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $draftMaster ?></div>
        <div class="stat-label">Draft</div>
      </div>
    </a>
  </div>
</div>

<!-- DATA TABLE -->
<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <span class="card-title"><i class="fas fa-list"></i> Daftar Master Risiko</span>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;flex:1;justify-content:flex-end">
      <form id="formFilterRisiko" method="GET" action="" style="display:flex;gap:8px;align-items:center;margin:0">
        <input type="hidden" name="page" value="risiko">
        <div class="search-bar">
          <i class="fas fa-search"></i>
          <input type="text" name="q" class="form-control" placeholder="Cari risiko..." value="<?= xss($search) ?>" style="height:38px">
        </div>
        <button type="submit" class="btn btn-outline" style="height:38px"><i class="fas fa-filter"></i> Filter</button>
      </form>
      <div class="datatable-dropdown" style="margin:0; display:flex; align-items:center;">
        <select name="limit" form="formFilterRisiko" class="datatable-selector" onchange="document.getElementById('formFilterRisiko').submit()">
          <option value="5" <?= $perPage===5?'selected':'' ?>>5</option>
          <option value="10" <?= $perPage===10?'selected':'' ?>>10</option>
          <option value="15" <?= $perPage===15?'selected':'' ?>>15</option>
          <option value="20" <?= $perPage===20?'selected':'' ?>>20</option>
          <option value="25" <?= $perPage===25?'selected':'' ?>>25</option>
        </select>
        <label style="margin-left:8px; margin-bottom:0; font-size:14px;">Data</label>
      </div>
    </div>
  </div>
  <div class="table-responsive table-responsive-no-x">
    <table class="data-table no-datatable risiko-table-compact" id="tableRisiko" style="table-layout:fixed; width:100%; word-wrap:break-word;">
      <thead>
        <tr>
          <th style="width:5%;text-align:center">NO</th>
          <th style="width:9%">KODE</th>
          <th style="width:24%; white-space:normal;">NAMA RISIKO / KEGIATAN</th>
          <th style="width:15%">PEMILIK</th>
          <th style="width:15%">PENGELOLA</th>
          <th style="width:9%;text-align:center">TANGGAL</th>
          <th style="width:9%;text-align:center">STATUS</th>
          <th style="width:14%;text-align:center">AKSI</th>
        </tr>
      </thead>
      <tbody>
      <?php if(empty($rows)): ?>
        <tr><td colspan="8">
          <div class="empty-state" style="padding:24px">
            <i class="fas fa-folder-open"></i>
            <p>Belum ada master risiko.</p>
          </div>
        </td></tr>
      <?php else: ?>
        <?php foreach($rows as $i => $r): ?>
        <tr>
          <td style="text-align:center"><?= $offset + $i + 1 ?></td>
          <td><code style="font-size:0.75rem"><?= xss($r['kode_risiko']) ?></code></td>
          <td>
            <div style="font-weight:600"><?= xss($r['nama_risiko']) ?></div>
            <?php if($r['nama_kegiatan']): ?><div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px"><?= xss($r['nama_kegiatan']) ?></div><?php endif; ?>
          </td>
          <td style="font-size:0.8rem"><?= xss($r['pemilik_risiko']?:'-') ?></td>
          <td style="font-size:0.8rem"><?= xss($r['departemen']?:'-') ?></td>
          <td style="text-align:center;font-size:0.75rem"><?= date('d/m/Y', strtotime($r['tanggal_identifikasi'])) ?></td>
          <td style="text-align:center">
            <?php if ($r['approval_status'] === 'approved'): ?>
              <span class="badge badge-success">Disetujui</span>
            <?php elseif ($r['approval_status'] === 'rejected'): ?>
              <span class="badge badge-danger">Ditolak</span>
            <?php else: ?>
              <span class="badge badge-warning">Menunggu</span>
            <?php endif; ?>
          </td>
                    <td class="risiko-action-cell" style="text-align:center">
              <div class="act-btn-group">
                <a href="?page=risiko&detail=<?= $r['id'] ?>&saran=1" class="act-btn act-btn-view" title="Detail & Saran"><i class="fas fa-eye"></i></a>
                <?php if(hasRole('Admin','Risk Manager')): ?>
                <button type="button" class="act-btn act-btn-edit" onclick='editRisiko(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)' title="Edit Risiko"><i class="fas fa-edit"></i></button>
                <button type="button" class="act-btn act-btn-delete" onclick="hapusRisiko(<?= $r['id'] ?>, '<?= xss($r['kode_risiko']) ?>')" title="Hapus Risiko"><i class="fas fa-trash"></i></button>
                <?php endif; ?>
              </div>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;padding:14px 20px;">
    <span class="pagination-info" style="font-size:.76rem;color:var(--text-muted);font-weight:600;">
      <?= $total == 0 ? 'Tidak ada data' : 'Menampilkan ' . ($offset + 1) . '–' . min($offset + $perPage, $total) . ' dari ' . $total . ' data' ?>
    </span>
    <?php if($pg['total_pages'] > 1): ?>
    <div class="pagination" style="margin:0;gap:5px;">
      <?php
        $baseP = APP_URL.'/?page=risiko&q='.urlencode($search).'&sumber='.urlencode($fSumber).'&status='.urlencode($fStatus).'&level='.urlencode($fLevel).'&departemen='.urlencode($fDept).'&limit='.$perPage;
        echo '<a class="page-btn '.($pg['current']<=1?'disabled':'').'" href="'.$baseP.'&p=1" title="Halaman Pertama"><i class="fas fa-angles-left"></i></a>';
        echo '<a class="page-btn '.($pg['current']<=1?'disabled':'').'" href="'.$baseP.'&p='.($pg['current']-1).'" title="Halaman Sebelumnya"><i class="fas fa-chevron-left"></i></a>';
        for($i=max(1,$pg['current']-2);$i<=min($pg['total_pages'],$pg['current']+2);$i++){
          echo '<a class="page-btn '.($i==$pg['current']?'active':'').'" href="'.$baseP.'&p='.$i.'">'.$i.'</a>';
        }
        echo '<a class="page-btn '.($pg['current']>=$pg['total_pages']?'disabled':'').'" href="'.$baseP.'&p='.($pg['current']+1).'" title="Halaman Berikutnya"><i class="fas fa-chevron-right"></i></a>';
        echo '<a class="page-btn '.($pg['current']>=$pg['total_pages']?'disabled':'').'" href="'.$baseP.'&p='.$pg['total_pages'].'" title="Halaman Terakhir"><i class="fas fa-angles-right"></i></a>';
      ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Detail Modal -->
<?php if($detailRow): ?>
<div class="modal-overlay" id="modalDetail" style="display:flex">
  <div class="modal modal-lg">
    <div class="modal-header">

      <h3 class="modal-title"><i class="fas fa-eye"></i> <?= xss($detailRow['kode_risiko']) ?> — Detail Risiko</h3>
      <button class="btn-close" onclick="closeModal('modalDetail')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="form-row-2">
        <div><strong>Nama Risiko</strong><p><?= xss($detailRow['nama_risiko']) ?></p></div>
        <div><strong>Sumber Risiko</strong><p><?= xss($detailRow['sumber']) ?></p></div>
        <div><strong>Pemilik Risiko</strong><p><?= xss($detailRow['pemilik_risiko']?:'-') ?></p></div>
        <div><strong>Pengelola Risiko</strong><p><?= xss($detailRow['departemen']?:'-') ?></p></div>
      </div>
      <hr style="margin:14px 0;border-color:var(--border)">
      <div><strong>Peristiwa Risiko</strong><p style="color:var(--text-muted)"><?= xss($detailRow['deskripsi']) ?></p></div>
    <div style="margin-top:12px"><strong>Sebab</strong><p style="color:var(--text-muted)"><?= formatUraianList($detailRow['penyebab']) ?></p></div>
    <div style="margin-top:12px"><strong>Dampak</strong><p style="color:var(--text-muted)"><?= formatUraianList($detailRow['dampak']) ?></p></div>
      <hr style="margin:14px 0;border-color:var(--border)">
      <?php if (!empty($penilaian) && (int)$penilaian['probabilitas'] > 0): ?>
      <?php
        $pNilai = (int)$penilaian['probabilitas'];
        $dNilai = (int)$penilaian['dampak'];
        $bNilai = (float)$penilaian['bobot'];
        $skorNilai = (int)round($penilaian['nilai']);
        $tingkatNilai = $penilaian['tingkat_risiko'] ?? '-';
      ?>
      <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:center">
        <div style="text-align:center"><div style="font-size:.72rem;color:var(--text-muted)">Probabilitas</div><div style="font-size:2rem;font-weight:800;color:var(--accent)"><?= $pNilai ?></div></div>
        <div style="text-align:center"><div style="font-size:.72rem;color:var(--text-muted)">&times;</div><div style="font-size:2rem;color:var(--text-muted)">&times;</div></div>
        <div style="text-align:center"><div style="font-size:.72rem;color:var(--text-muted)">Dampak</div><div style="font-size:2rem;font-weight:800;color:var(--accent)"><?= $dNilai ?></div></div>
        <div style="text-align:center"><div style="font-size:.72rem;color:var(--text-muted)">&times;</div><div style="font-size:2rem;color:var(--text-muted)">&times;</div></div>
        <div style="text-align:center"><div style="font-size:.72rem;color:var(--text-muted)">Bobot</div><div style="font-size:1.4rem;font-weight:700;color:var(--accent)"><?= $bNilai ?></div></div>
        <div style="text-align:center"><div style="font-size:.72rem;color:var(--text-muted)">=</div><div style="font-size:2rem;color:var(--text-muted)">=</div></div>
        <?php
        $cNilai = 'var(--text)';
        if ($tingkatNilai === 'Sangat Tinggi') $cNilai = 'var(--danger)';
        elseif ($tingkatNilai === 'Tinggi') $cNilai = '#f97316';
        elseif ($tingkatNilai === 'Sedang') $cNilai = '#eab308';
        elseif ($tingkatNilai === 'Rendah') $cNilai = 'var(--success)';
        elseif ($tingkatNilai === 'Sangat Rendah') $cNilai = 'var(--info)';
        ?>
        <div style="text-align:center"><div style="font-size:.72rem;color:var(--text-muted)">Nilai</div><div style="font-size:2rem;font-weight:800;color:<?= $cNilai ?>"><?= $skorNilai ?></div></div>
        <div style="margin-left:auto;align-self:center"><?= badgeLevel($tingkatNilai) ?></div>
        <div style="align-self:center"><?= badgeStatus($detailRow['status']) ?></div>
      </div>
      <?php if (!empty($penilaian['rencana_penanganan'])): ?>
      <div style="margin-top:12px"><strong>Rencana Penanganan (dari Profil Risiko):</strong><p style="color:var(--text-muted)"><?= xss($penilaian['rencana_penanganan']) ?></p></div>
      <?php endif; ?>
      <?php if (!empty($penilaian['penanggungjawab'])): ?>
      <div style="margin-top:8px"><strong>Penanggungjawab:</strong> <span style="color:var(--text-muted)"><?= xss($penilaian['penanggungjawab']) ?></span></div>
      <?php endif; ?>
      <?php if (!empty($penilaian['jadwal_pelaksanaan'])): ?>
      <div style="margin-top:8px"><strong>Jadwal Pelaksanaan:</strong> <span style="color:var(--text-muted)"><?= xss($penilaian['jadwal_pelaksanaan']) ?></span></div>
      <?php endif; ?>
      <?php else: ?>
      <div style="padding:16px;background:var(--surface2);border-radius:8px;text-align:center;color:var(--text-muted);font-size:.85rem">
        <i class="fas fa-info-circle" style="color:var(--accent);margin-right:6px"></i>
        Risiko ini belum dinilai di Profil Risiko. Buka <a href="<?= APP_URL ?>/?page=profil_risiko" style="color:var(--accent);font-weight:600">Profil Risiko</a> untuk mengisi P/D, bobot, dan rencana penanganan.
      </div>
      <div style="margin-top:10px;display:flex;gap:20px;align-items:center">
        <div style="align-self:center"><?= badgeStatus($detailRow['status']) ?></div>
      </div>
      <?php endif; ?>

      <!-- 5 Rekomendasi Saran Mitigasi Asisten Cerdas MANRIS -->
      <hr style="margin:16px 0;border-color:var(--border)">
      <div class="ai-saran-card" style="background:var(--surface2);border:1px solid rgba(59,130,246,0.35);border-radius:8px;padding:14px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px">
          <div style="font-weight:700;color:var(--primary);font-size:.88rem;display:flex;align-items:center;gap:6px">
            <i class="fas fa-robot" style="color:var(--accent)"></i> 5 Saran Mitigasi Asisten Cerdas MANRIS
          </div>
          <button type="button" class="btn btn-xs btn-accent" id="btnMuatAiSaran" onclick="loadAiSaranIdentifikasi(<?= (int)$detailRow['id'] ?>)">
            <i class="fas fa-magic"></i> Muat 5 Saran AI
          </button>
        </div>
        <div id="aiSaranContainer">
          <div style="font-size:.8rem;color:var(--text-muted);padding:4px 0">
            <i class="fas fa-lightbulb" style="color:var(--accent);margin-right:4px"></i> Klik <strong>"Muat 5 Saran AI"</strong> untuk merumuskan 5 rekomendasi mitigasi terstruktur khusus untuk risiko ini.
          </div>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <a href="<?= APP_URL ?>/?page=mitigasi&risiko_id=<?= $detailRow['id'] ?>" class="btn btn-success"><i class="fas fa-tasks"></i> Kelola Mitigasi</a>
      <button onclick="closeModal('modalDetail')" class="btn btn-outline">Tutup</button>
    </div>
  </div>
</div>
<script>
document.getElementById('modalDetail').style.display='flex';
<?php if(isset($_GET['saran'])): ?>
loadAiSaranIdentifikasi(<?= (int)$detailRow['id'] ?>);
<?php endif; ?>
</script>
<?php endif; ?>

<!-- Modal Tambah/Edit -->
<div class="modal-overlay" id="modalTambah" style="display:none">
  <div class="modal modal-lg">
    <div class="modal-header">
      <h3 class="modal-title" id="modalTambahTitle"><i class="fas fa-plus-circle"></i> Tambah Risiko Baru</h3>
      <button class="btn-close" onclick="closeModal('modalTambah')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/?page=risiko" id="formRisiko">
    <?= csrfField() ?>
    <input type="hidden" name="aksi" value="simpan">
    <input type="hidden" name="id" id="formId" value="0">
    <div class="modal-body">
      <div class="form-row-2">
        <div class="form-group" id="groupKodeRisiko" style="display:none">
          <label class="form-label">Kode Risiko</label>
          <input type="text" name="kode_risiko" id="fKode" class="form-control" placeholder="Kosongkan untuk otomatis">
        </div>
        <div class="form-group">
          <label class="form-label">Nama Kegiatan</label>
          <input type="text" name="nama_kegiatan" id="fKegiatan" class="form-control" placeholder="Nama kegiatan terkait risiko">
        </div>
        <div class="form-group">
          <label class="form-label">Nama Risiko <span class="required">*</span></label>
          <input type="text" name="nama_risiko" id="fNama" class="form-control" required placeholder="Nama risiko yang jelas">
        </div>
        <div class="form-group">
          <label class="form-label">Kategori Risiko</label>
          <select name="id_kategori" id="fKategori" class="form-control">
            <option value="">-- Pilih Kategori --</option>
            <?php foreach($kategoriList as $kat): ?>
            <option value="<?= $kat['id'] ?>"><?= xss($kat['nama']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Sumber Risiko <span class="required">*</span></label>
          <select name="sumber" id="fSumber" class="form-control" required>
            <option value="">-- Pilih Sumber --</option>
            <option value="Internal">Internal</option>
            <option value="Eksternal">Eksternal</option>
            <option value="Internal & Eksternal">Internal & Eksternal</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Unit Kerja Pemilik Risiko</label>
          <input type="text" name="pemilik_risiko" id="fPemilik" class="form-control" value="Balai Besar Laboratorium Kesehatan Lingkungan" placeholder="Nama penanggung jawab">
        </div>
        <div class="form-group">
          <label class="form-label">Pengelola Risiko</label>
          <?php $defPengelola = getDefaultPengelola(); ?>
          <select name="departemen" id="fDept" class="form-control">
            <option value="">-- Pilih Pengelola Risiko --</option>
            <option value="Kepala Subbagian Administrasi Umum" <?= $defPengelola === 'Kepala Subbagian Administrasi Umum' ? 'selected' : '' ?>>Kepala Subbagian Administrasi Umum</option>
            <option value="Katimker 1" <?= $defPengelola === 'Katimker 1' ? 'selected' : '' ?>>Katimker 1</option>
            <option value="Katimker 2" <?= $defPengelola === 'Katimker 2' ? 'selected' : '' ?>>Katimker 2</option>
            <option value="Katimker 3" <?= $defPengelola === 'Katimker 3' ? 'selected' : '' ?>>Katimker 3</option>
            <option value="Koordinator Instalasi" <?= $defPengelola === 'Koordinator Instalasi' ? 'selected' : '' ?>>Koordinator Instalasi</option>
            <option value="Unit Pengendali Gratifikasi" <?= $defPengelola === 'Unit Pengendali Gratifikasi' ? 'selected' : '' ?>>Unit Pengendali Gratifikasi</option>
          </select>
        </div>
      </div>
       <div class="form-row-2">
         <div class="form-group"><label class="form-label">Sebab</label><textarea name="penyebab" id="fPenyebab" class="form-control" rows="2" placeholder="Akar penyebab risiko"></textarea></div>
         <div class="form-group"><label class="form-label">Dampak</label><textarea name="dampak" id="fDampak" class="form-control" rows="2" placeholder="Dampak jika risiko terjadi"></textarea></div>
       </div>
       <div style="padding:12px 14px;background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm);font-size:.82rem;color:var(--text-muted)"><i class="fas fa-arrow-right" style="color:var(--accent)"></i> Setelah master risiko disimpan, buka <strong>Profil Risiko Unit</strong> untuk mengisi P/D, penilaian, rencana penanganan, PIC, jadwal, dan target residual.</div>
      <div class="form-group">
          <label class="form-label">Tanggal Identifikasi</label>
          <input type="date" name="tanggal_identifikasi" id="fTgl" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>
    </div>
    <div class="modal-footer">
      <button type="button" onclick="closeModal('modalTambah')" class="btn btn-outline">Batal</button>
      <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Risiko</button>
    </div>
    </form>
  </div>
</div>

<!-- Hapus Confirm -->
<div class="modal-overlay" id="modalHapus" style="display:none">
  <div class="modal" style="max-width:420px">
    <div class="modal-header"><h3 class="modal-title text-danger"><i class="fas fa-trash"></i> Hapus Risiko</h3>
    <button class="btn-close" onclick="closeModal('modalHapus')"><i class="fas fa-times"></i></button></div>
    <div class="modal-body"><p>Yakin ingin menghapus risiko <strong id="hapusKode"></strong>?</p><p style="margin-top:10px;font-size:.78rem;color:var(--text-muted)">Risiko yang sudah disetujui/digunakan dokumen akan dinonaktifkan (bisa dipulihkan Admin). Risiko draft tanpa referensi dihapus permanen.</p></div>
    <div class="modal-footer">
      <form method="POST"><?= csrfField() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" id="hapusId">
      <button type="button" onclick="closeModal('modalHapus')" class="btn btn-outline">Batal</button>
      <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Hapus</button></form>
    </div>
  </div>
</div>

<script>
// Draft lokal Identifikasi Risiko: membantu user tanpa mengubah database.
(function () {
  const form = document.getElementById('formRisiko');
  if (!form) return;
  const draftId = form.querySelector('input[name="id"]')?.value || 'new';
  const draftKey = 'manrisv2:risiko:' + (draftId === '0' ? 'new' : draftId);
  const fields = Array.from(form.querySelectorAll('input:not([type="hidden"]):not([type="file"]), textarea, select'));
  const status = document.createElement('small');
  status.style.cssText = 'display:block;color:var(--text-muted);font-size:.72rem;margin-top:6px';
  status.textContent = 'Draft tersimpan otomatis di perangkat ini';
  form.querySelector('.modal-body')?.prepend(status);
  const saveDraft = () => {
    const data = {};
    fields.forEach(field => { if (field.name) data[field.name] = field.value; });
    localStorage.setItem(draftKey, JSON.stringify({savedAt: Date.now(), data}));
    status.textContent = 'Draft tersimpan otomatis pukul ' + new Date().toLocaleTimeString('id-ID', {hour:'2-digit', minute:'2-digit'});
  };
  let timer;
  fields.forEach(field => field.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(saveDraft, 500); }));
  fields.forEach(field => field.addEventListener('change', saveDraft));
  try {
    const raw = localStorage.getItem(draftKey);
    if (raw) {
      const draft = JSON.parse(raw);
      if (Date.now() - Number(draft.savedAt || 0) < 7 * 24 * 60 * 60 * 1000 && draft.data && confirm('Ada draft Identifikasi Risiko tersimpan. Pulihkan sekarang?')) {
        fields.forEach(field => { if (field.name in draft.data) field.value = draft.data[field.name]; });
      }
    }
  } catch (e) {}
  form.addEventListener('submit', () => localStorage.removeItem(draftKey));
})();

const levelMap={2:'Sangat Rendah',4:'Rendah',9:'Sedang',14:'Sedang',19:'Tinggi',24:'Tinggi',25:'Sangat Tinggi'};
const matriksBobot = {
  5: {1: 1.5, 2: 1.4, 3: 1.13, 4: 1.15, 5: 1},
  4: {1: 1.2, 2: 1.19, 3: 1.3, 4: 1.16, 5: 1.2},
  3: {1: 1.17, 2: 1.42, 3: 1.43, 4: 1.46, 5: 1.47},
  2: {1: 1, 2: 1.8, 3: 1.83, 4: 1.9, 5: 2.1},
  1: {1: 1, 2: 1.5, 3: 2, 4: 3, 5: 4}
};
function getLevel(s){return s>=20?'Sangat Tinggi':s>=15?'Tinggi':s>=10?'Sedang':s>=5?'Rendah':'Sangat Rendah'}
function getLevelClass(s){return s>=20?'badge-danger':s>=15?'badge-orange':s>=10?'badge-warning':s>=5?'badge-success':'badge-info'}
function updateSkor(){
  const p=+document.getElementById('fProb').value;
  const d=+document.getElementById('fDmpk').value;
  document.getElementById('probVal').textContent=p;
  document.getElementById('dmpkVal').textContent=d;
  const bobot = matriksBobot[p]?.[d] || 1.00;
  const skor = Math.round(p * d * bobot);
  document.getElementById('skorDisplay').textContent=skor;
  document.getElementById('skorDisplay').style.color = skor>=20?'#dc2626':skor>=15?'#ea580c':skor>=10?'#ca8a04':skor>=5?'#16a34a':'#0284c7';
    const lv=getLevel(skor);
    const el=document.getElementById('levelDisplay');
    if(el) { el.textContent=lv; el.className='badge '+getLevelClass(skor); }
    let pr = 5;
    if(skor <= 4) pr = 5;
    else if(skor <= 9) pr = 4;
    else if(skor <= 14) pr = 3;
    else if(skor <= 19) pr = 2;
    else pr = 1;
    const elPr = document.getElementById('prioritasDisplay');
    if(elPr) elPr.textContent = 'PR: ' + pr;
  // Update bar chart mini — P & D scale 1-5 (20%-100%), Skor scale 1-25 (4%-100%)
  document.getElementById('barP').style.height=(p*20)+'%';
  document.getElementById('barD').style.height=(d*20)+'%';
  const barBElem = document.getElementById('barB');
  if(barBElem) {
    barBElem.style.height=Math.min(100, (bobot/4)*100)+'%';
    document.getElementById('barBVal').textContent=bobot.toFixed(2);
  }
  document.getElementById('barS').style.height=Math.min(100, (skor/25)*100)+'%';
  document.getElementById('barPVal').textContent=p;
  document.getElementById('barDVal').textContent=d;
  document.getElementById('barSVal').textContent=skor;
  // Warna bar Skor sesuai level
  const barS=document.getElementById('barS');
  const skorCls=getLevelClass(skor);
  barS.style.background = skor>=20?'linear-gradient(180deg,#dc2626,#7f1d1d)'
    : skor>=15?'linear-gradient(180deg,#f97316,#9a3412)'
    : skor>=10?'linear-gradient(180deg,#FFFF00,#a16207)'
    : skor>=5?'linear-gradient(180deg,#22c55e,#14532d)'
    : 'linear-gradient(180deg,#3b82f6,#1e3a5f)';
  updateProbDesc();
  updateDmpkDesc();
}
// Deskripsi probabilitas per angka
const probDescMap={1:'Jarang',2:'Kecil',3:'Sedang',4:'Besar',5:'Hampir Pasti'};
function updateProbDesc(){
  const p=+document.getElementById('fProb').value;
  const el=document.getElementById('probDesc');
  if(el) el.textContent=probDescMap[p]||'-';
}
// Deskripsi dampak per angka
const dmpkDescMap={1:'Tidak Signifikan',2:'Kecil',3:'Sedang',4:'Besar',5:'Katastropik'};
function updateDmpkDesc(){
  const d=+document.getElementById('fDmpk').value;
  const el=document.getElementById('dmpkDesc');
  if(el) el.textContent=dmpkDescMap[d]||'-';
}
function editRisiko(r){
  document.getElementById('modalTambahTitle').innerHTML='<i class="fas fa-edit"></i> Edit Risiko — '+r.kode_risiko;
  document.getElementById('formId').value=r.id;
  const elKegiatan=document.getElementById('fKegiatan'); if(elKegiatan) elKegiatan.value=r.nama_kegiatan||'';
  document.getElementById('fNama').value=r.nama_risiko;
  const elKat=document.getElementById('fKategori'); if(elKat) elKat.value=r.id_kategori||'';
  document.getElementById('fSumber').value=r.sumber;
  document.getElementById('fPemilik').value=r.pemilik_risiko||'Balai Besar Laboratorium Kesehatan Lingkungan';
  document.getElementById('fDept').value=r.departemen||'';
  document.getElementById('fPenyebab').value=r.penyebab||'';
  document.getElementById('fDampak').value=r.dampak||'';
  document.getElementById('fTgl').value=r.tanggal_identifikasi;
  openModal('modalTambah');
}
function hapusRisiko(id,kode){document.getElementById('hapusId').value=id;document.getElementById('hapusKode').textContent=kode;openModal('modalHapus')}

function loadAiSaranIdentifikasi(idRisiko) {
  const container = document.getElementById('aiSaranContainer');
  if (!container || !idRisiko) return;
  container.innerHTML = '<div style="font-size:.8rem;color:var(--text-muted);padding:8px 0"><i class="fas fa-spinner fa-spin"></i> Asisten Cerdas sedang menganalisis risiko dan merumuskan 5 saran mitigasi...</div>';
  fetch((window.APP_URL || '') + '/api.php/ai_saran?id_risiko=' + encodeURIComponent(idRisiko), {
    headers: { 'X-CSRF-Token': window.CSRF_TOKEN || '' }
  })
  .then(function(res) { return res.json(); })
  .then(function(data) {
    container.innerHTML = '';
    if (data.ok && Array.isArray(data.saran) && data.saran.length) {
      const list = data.saran.slice(0, 5); // Tepat 5 saran
      list.forEach(function(s, idx) {
        const item = document.createElement('div');
        item.style.cssText = 'display:flex;justify-content:space-between;align-items:flex-start;gap:10px;padding:8px 10px;background:var(--surface);border:1px solid var(--border);border-radius:6px;margin-bottom:6px;font-size:.82rem;line-height:1.5;';
        const txt = document.createElement('div');
        txt.style.flex = '1';
        txt.innerHTML = '<strong style="color:var(--accent);margin-right:6px">#' + (idx + 1) + '</strong> ' + s;
        const btn = document.createElement('a');
        btn.href = (window.APP_URL || '') + '/?page=mitigasi&risiko_id=' + encodeURIComponent(idRisiko) + '&saran=' + encodeURIComponent(s);
        btn.className = 'btn btn-xs btn-outline';
        btn.style.whiteSpace = 'nowrap';
        btn.innerHTML = '<i class="fas fa-plus-circle"></i> Terapkan';
        btn.title = 'Terapkan saran mitigasi ini ke rencana aksi';
        item.appendChild(txt);
        item.appendChild(btn);
        container.appendChild(item);
      });
    } else {
      container.innerHTML = '<div style="font-size:.8rem;color:var(--danger)"><i class="fas fa-exclamation-triangle"></i> ' + (data.error || 'Tidak ada saran yang dapat dimuat.') + '</div>';
    }
  })
  .catch(function() {
    container.innerHTML = '<div style="font-size:.8rem;color:var(--danger)"><i class="fas fa-exclamation-triangle"></i> Gagal menghubungi Asisten Cerdas.</div>';
  });
}
</script>








