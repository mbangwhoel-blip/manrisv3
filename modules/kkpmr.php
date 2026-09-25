<?php
/**
 * MODUL KERTAS KERJA PEMANTAUAN DAN REVIU (KKPMR)
 * Format: UPR-T.II Kemenkes — V6 KK-identifikasi Risiko
 * Menampilkan hasil pemantauan atas risiko yang sudah dinilai di KKPR.
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
requireRole('Admin', 'Risk Manager', 'Pimpinan');
$db = getDB();
$monevStatusColumn = $db->query("SHOW COLUMNS FROM kkpr_risiko LIKE 'monev_status'");
if ($monevStatusColumn && $monevStatusColumn->num_rows === 0) {
    $db->query("ALTER TABLE kkpr_risiko ADD COLUMN monev_status VARCHAR(30) NOT NULL DEFAULT 'Belum Dipantau' AFTER efektifitas");
}

function kkpmrColor(string $t): string {
    return match($t) {
        'Sangat Tinggi' => '#dc2626',
        'Tinggi'        => '#f97316',
        'Sedang'        => '#000',
        'Rendah'        => '#22c55e',
        default         => '#3b82f6',
    };
}
function kkpmrBg(string $t): string {
    return match($t) {
        'Sangat Tinggi' => '#fee2e2',
        'Tinggi'        => '#fef3c7',
        'Sedang'        => '#FFFF00',
        'Rendah'        => '#dcfce7',
        default         => '#dbeafe',
    };
}

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { setFlash('error', 'Token tidak valid'); header('Location: ' . APP_URL . '/?page=kkpmr'); exit; }

    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'simpan_pemantauan') {
        $idRisiko = (int)($_POST['id_risiko'] ?? 0);
        $idKkpr   = (int)($_POST['id_kkpr'] ?? 0);

        $pp = (int)($_POST['pantau_p'] ?? 1);
        $pd = (int)($_POST['pantau_d'] ?? 1);
        $pb = getBobot($pp, $pd);
        if (!ownsKkpr($db, $idKkpr)) {
            setFlash('error', 'Anda tidak memiliki hak untuk memperbarui pemantauan KKPR ini.');
            header('Location: ' . APP_URL . '/?page=kkpmr'); exit;
        }
        if (!validRiskScale($pp) || !validRiskScale($pd)) {
            setFlash('error', 'Probabilitas dan dampak pemantauan harus bernilai 1 sampai 5.');
            header('Location: ' . APP_URL . '/?page=kkpmr&id=' . $idKkpr); exit;
        }
        $pNilai  = round($pp * $pd * $pb);
        $pTingkat = getLevelRisiko((int)$pNilai);

        $stmt = $db->prepare('SELECT nilai_risiko FROM kkpr_risiko WHERE id=? AND id_kkpr=?');
        $stmt->bind_param('ii', $idRisiko, $idKkpr);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $nilaiAwal = $res ? round((float)$res['nilai_risiko']) : 0;
        $stmt->close();
        
        $simpulan = 'Tetap';
        $efektifitas = 'Tidak Efektif';
        if ($pNilai < $nilaiAwal) {
            $simpulan = 'Penurunan';
            $efektifitas = 'Efektif';
        } elseif ($pNilai > $nilaiAwal) {
            $simpulan = 'Peningkatan';
            $efektifitas = 'Tidak Efektif';
        }

        if ($idRisiko > 0) {
            $monevStatus = $simpulan === 'Peningkatan' ? 'Eskalasi' : 'Sudah Dipantau';
            $s = $db->prepare('UPDATE kkpr_risiko SET pantau_p=?,pantau_d=?,pantau_bobot=?,pantau_nilai=?,pantau_tingkat=?,simpulan=?,efektifitas=?,monev_status=? WHERE id=? AND id_kkpr=?');
            $s->bind_param('iiddssssii', $pp, $pd, $pb, $pNilai, $pTingkat, $simpulan, $efektifitas, $monevStatus, $idRisiko, $idKkpr);
            $s->execute(); $s->close();
            if ($simpulan === 'Peningkatan') {
                $owner = $db->prepare('SELECT h.created_by, h.tahun, r.kode_risiko, r.nama_risiko FROM kkpr_header h JOIN kkpr_risiko r ON r.id_kkpr=h.id WHERE h.id=? AND r.id=? LIMIT 1');
                $owner->bind_param('ii', $idKkpr, $idRisiko); $owner->execute(); $ownerRow = $owner->get_result()->fetch_assoc(); $owner->close();
                if ($ownerRow && (int)$ownerRow['created_by'] !== (int)$_SESSION['user_id']) {
                    notifikasi((int)$ownerRow['created_by'], 'status_change', 'Eskalasi Risiko Monev', 'Risiko '.$ownerRow['kode_risiko'].' - '.$ownerRow['nama_risiko'].' mengalami peningkatan saat pemantauan. Segera lakukan evaluasi ulang pengendalian.', APP_URL.'/?page=kkpmr&id='.$idKkpr);
                }
            }
            logAktivitas('UPDATE', 'kkpmr', $idRisiko, 'Update pemantauan risiko KKPR');
            setFlash('success', 'Hasil pemantauan disimpan');
        }
        header('Location: ' . APP_URL . '/?page=kkpmr&id=' . $idKkpr); exit;
    }

    if ($aksi === 'update_header_pemantauan') {
        $idKkpr      = (int)($_POST['id_kkpr'] ?? 0);
        $tglPemantauan = $_POST['tgl_pemantauan'] ?? null;
        $periodePemantauan = trim($_POST['periode_pemantauan'] ?? '');
        $namaTtdPemilik = trim($_POST['nama_ttd_pemilik'] ?? '');
        $nipTtdPemilik  = trim($_POST['nip_ttd_pemilik'] ?? '');
        $namaTtdPengelola = trim($_POST['nama_ttd_pengelola'] ?? '');
        $nipTtdPengelola  = trim($_POST['nip_ttd_pengelola'] ?? '');

        // Simpan ke kkpr_header (reuse field tgl_update & periode_risiko untuk pemantauan)
        if ($idKkpr > 0) {
            if (!ownsKkpr($db, $idKkpr)) {
                setFlash('error', 'Anda tidak memiliki hak untuk memperbarui KKPR ini.');
                header('Location: ' . APP_URL . '/?page=kkpmr'); exit;
            }
            $s = $db->prepare('UPDATE kkpr_header SET tgl_update=?, periode_risiko=?, nama_ttd_pemilik=?, nip_ttd_pemilik=?, nama_ttd_pengelola=?, nip_ttd_pengelola=? WHERE id=?');
            $s->bind_param('ssssssi', $tglPemantauan, $periodePemantauan, $namaTtdPemilik, $nipTtdPemilik, $namaTtdPengelola, $nipTtdPengelola, $idKkpr);
            $s->execute(); $s->close();
            setFlash('success', 'Header pemantauan diperbarui');
        }
        header('Location: ' . APP_URL . '/?page=kkpmr&id=' . $idKkpr . '&tab=header'); exit;
    }

    if ($aksi === 'kirim_persetujuan_kkpmr') {
        requireRole('Risk Manager');
        $id = (int)($_POST['id'] ?? 0);
        if (!ownsRecord($db, 'kkpr_header', $id)) {
            setFlash('error', 'Anda tidak berhak mengajukan persetujuan KKPMR ini.');
            header('Location: '.APP_URL.'/?page=kkpmr'); exit;
        }
        $s = $db->prepare("UPDATE kkpr_header SET status_kkpmr='Menunggu Persetujuan' WHERE id=?");
        $s->bind_param('i', $id); $s->execute(); $s->close();
        logAktivitas('UPDATE', 'kkpmr', $id, 'Mengajukan persetujuan KKPMR');
        setFlash('success', 'KKPMR berhasil diajukan untuk persetujuan Pimpinan.');
        header('Location: '.APP_URL.'/?page=kkpmr&id='.$id); exit;
    }

    if ($aksi === 'approve_kkpmr') {
        requireRole('Pimpinan');
        $id = (int)($_POST['id'] ?? 0);
        $uid = (int)$_SESSION['user_id'];
        $s = $db->prepare("UPDATE kkpr_header SET status_kkpmr='Disetujui', approved_by_kkpmr=?, approved_at_kkpmr=NOW() WHERE id=?");
        $s->bind_param('ii', $uid, $id); $s->execute(); $s->close();
        
        $q = $db->query("SELECT created_by, tahun FROM kkpr_header WHERE id=$id");
        if ($q && $r = $q->fetch_assoc()) {
            notifikasi((int)$r['created_by'], 'status_change', 'KKPMR Disetujui', 'KKPMR tahun '.$r['tahun'].' telah disetujui oleh Pimpinan.', APP_URL.'/?page=kkpmr&id='.$id);
        }
        
        logAktivitas('UPDATE', 'kkpmr', $id, 'Menyetujui KKPMR');
        setFlash('success', 'KKPMR berhasil disetujui.');
        header('Location: '.APP_URL.'/?page=kkpmr&id='.$id); exit;
    }

    if ($aksi === 'reject_kkpmr') {
        requireRole('Pimpinan');
        $id = (int)($_POST['id'] ?? 0);
        $catatan = trim($_POST['catatan_revisi'] ?? '');
        $s = $db->prepare("UPDATE kkpr_header SET status_kkpmr='Revisi', catatan_revisi_kkpmr=? WHERE id=?");
        $s->bind_param('si', $catatan, $id); $s->execute(); $s->close();
        
        $q = $db->query("SELECT created_by, tahun FROM kkpr_header WHERE id=$id");
        if ($q && $r = $q->fetch_assoc()) {
            notifikasi((int)$r['created_by'], 'status_change', 'KKPMR Direvisi', 'KKPMR tahun '.$r['tahun'].' dikembalikan oleh Pimpinan dengan catatan: '.$catatan, APP_URL.'/?page=kkpmr&id='.$id);
        }
        
        logAktivitas('UPDATE', 'kkpmr', $id, 'Menolak/revisi KKPMR');
        setFlash('success', 'KKPMR dikembalikan untuk direvisi.');
        header('Location: '.APP_URL.'/?page=kkpmr&id='.$id); exit;
    }

    // Hapus dokumen KKPMR (sekaligus KKPR karena memakai record kkpr_header yg sama).
    // Admin/Pimpinan bebas hapus; role lain hanya boleh hapus miliknya sendiri.
    if ($aksi === 'hapus_kkpmr') {
        $id = (int)($_POST['id'] ?? 0);
        if (!hasRole('Admin', 'Pimpinan')) {
            $sCheck = $db->prepare("SELECT created_by FROM kkpr_header WHERE id=?");
            $sCheck->bind_param("i", $id); $sCheck->execute();
            $rowCheck = $sCheck->get_result()->fetch_assoc(); $sCheck->close();
            if (!$rowCheck || $rowCheck['created_by'] != $_SESSION['user_id']) {
                setFlash('error', 'Anda tidak memiliki hak untuk menghapus KKPMR ini');
                header('Location: '.APP_URL.'/?page=kkpmr'); exit;
            }
        }
        $del = $db->prepare("DELETE FROM kkpr_header WHERE id=?");
        $del->bind_param("i", $id); $del->execute(); $del->close();
        logAktivitas('DELETE', 'kkpmr', $id, 'Hapus KKPMR/KKPR ID '.$id);
        setFlash('success', 'KKPMR (beserta KKPR) berhasil dihapus');
        header('Location: '.APP_URL.'/?page=kkpmr'); exit;
    }
}

// ── Data ──────────────────────────────────────────────────────
$activeId  = (int)($_GET['id'] ?? 0);
$activeTab = $_GET['tab'] ?? 'detail';
$fTahun    = trim((string)($_GET['tahun'] ?? ''));

$kkprRow = null;
$rows    = [];
$editRow = null;

if ($activeId > 0) {
    $scope = canAccessAllRecords() ? '' : ' AND created_by = ?';
    $s = $db->prepare('SELECT * FROM kkpr_header WHERE id=?' . $scope);
    if ($scope) { $uid = (int)$_SESSION['user_id']; $s->bind_param('ii', $activeId, $uid); }
    else $s->bind_param('i', $activeId);
    $s->execute();
    $kkprRow = $s->get_result()->fetch_assoc(); $s->close();

    if ($kkprRow) {
        $s2 = $db->prepare("SELECT * FROM kkpr_risiko WHERE id_kkpr=? ORDER BY SUBSTRING_INDEX(kode_risiko, '.', 1) ASC, CAST(SUBSTRING_INDEX(kode_risiko, '.', -1) AS UNSIGNED) ASC, kode_risiko ASC");
        $s2->bind_param('i', $activeId); $s2->execute();
        $rows = $s2->get_result()->fetch_all(MYSQLI_ASSOC); $s2->close();
    }

    $editId = (int)($_GET['edit'] ?? 0);
    if ($editId > 0) {
        $se = $db->prepare('SELECT * FROM kkpr_risiko WHERE id=? AND id_kkpr=?');
        $se->bind_param('ii', $editId, $activeId); $se->execute();
        $editRow = $se->get_result()->fetch_assoc(); $se->close();
    }
}

$activeTahun = (string)($kkprRow['tahun'] ?? ($fTahun !== '' ? $fTahun : date('Y')));
$tahunList   = getDaftarTahun($db, 'kkpr_header', [$activeTahun]);

$kkpr_cond = hasRole('Admin', 'Pimpinan') ? "WHERE 1=1" : "WHERE h.created_by = " . (int)$_SESSION['user_id'];
if ($fTahun !== '') {
    $kkpr_cond .= " AND h.tahun = '" . $db->real_escape_string($fTahun) . "'";
}
$kkprList = $db->query("SELECT h.id, h.tahun, h.unit_pemilik_risiko, h.nama_pemilik_risiko, u.nama AS nama_creator, (SELECT COUNT(*) FROM kkpr_risiko r WHERE r.id_kkpr = h.id) AS jml_detail FROM kkpr_header h LEFT JOIN users u ON h.created_by = u.id $kkpr_cond ORDER BY h.tahun DESC, h.id DESC")->fetch_all(MYSQLI_ASSOC) ?: [];

// ── Export Excel ──────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'excel' && $activeId > 0 && $kkprRow) {
    $periode = $_GET['periode'] ?? 'tahunan';
    if (!in_array($periode, ['tahunan','tw1','tw2','tw3','tw4'], true)) $periode = 'tahunan';
    $triwulanRomawi = ['I','II','III','IV'];
    $periodeLabel = $periode === 'tahunan'
        ? 'Laporan Tahunan'
        : 'Laporan Triwulan ' . $triwulanRomawi[(int)substr($periode, 2) - 1];
    $GLOBALS['EXPORT_PERIODE'] = $periodeLabel;

    $headers = ['No', 'Risiko', 'Kode', 'P Awal', 'D Awal', 'Bobot Awal', 'Nilai Awal', 'Tingkat Awal', 'Prioritas', 'Pengendalian', 'Jadwal', 'P Pantau', 'D Pantau', 'Bobot Pantau', 'Nilai Pantau', 'Tingkat Pantau', 'Simpulan', 'Efektifitas'];
    $excelRows = [];
    foreach ($rows as $i => $r) {
        $excelRows[] = [
            $i + 1,
            $r['nama_risiko'] ?? '',
            $r['kode_risiko'] ?? '',
            $r['probabilitas'] ?? '',
            $r['dampak_level'] ?? '',
            $r['bobot'] ?? '',
            $r['nilai_risiko'] ?? '',
            $r['tingkat_risiko'] ?? '',
            $r['prioritas_risiko'] ?? '',
            $r['rpti_uraian'] ?? '',
            $r['rpti_jadwal'] ?? '',
            $r['pantau_p'] ?? '',
            $r['pantau_d'] ?? '',
            $r['pantau_bobot'] ?? '',
            $r['pantau_nilai'] ?? '',
            $r['pantau_tingkat'] ?? '',
            $r['simpulan'] ?? '',
            $r['efektifitas'] ?? '',
        ];
    }
    $namaFile = 'kkpmr_' . ($kkprRow['tahun'] ?? '') . '_' . date('Ymd_His');
    exportExcel($namaFile, $headers, $excelRows);
}

?>


<?php
$isLockedKkpmr = isset($kkprRow) && in_array($kkprRow['status_kkpmr'] ?? '', ['Menunggu Persetujuan', 'Disetujui']);
$kmTotal = count($kkprList);
$kmRisiko = 0;
$kmTinggi = 0;
if ($kmTotal > 0) {
    $kIds = implode(',', array_column($kkprList, 'id'));
    $statQ = $db->query("SELECT COUNT(*) as tot, SUM(IF(tingkat_risiko IN ('Tinggi','Sangat Tinggi'), 1, 0)) as th FROM kkpr_risiko WHERE id_kkpr IN ($kIds)");
    if ($statQ) {
        $statRes = $statQ->fetch_assoc();
        $kmRisiko = (int)$statRes['tot'];
        $kmTinggi = (int)$statRes['th'];
    }
}
$kmBelumDipantau = count(array_filter($rows, static function (array $r): bool {
    $status = $r['monev_status'] ?? ($r['pantau_nilai'] === null ? 'Belum Dipantau' : 'Sudah Dipantau');
    return in_array($status, ['Belum Dipantau', 'Eskalasi'], true);
}));
$kmStatusText = $kmBelumDipantau > 0 ? ($kmBelumDipantau === $kmRisiko ? 'Mulai Pemantauan' : 'Lanjutkan Pemantauan') : 'Selesai';
$kmStatusIcon = $kmBelumDipantau > 0 ? 'fa-arrow-right' : 'fa-circle-check';
?>
<div class="risiko-hero profil-risiko-hero kkpr-risiko-hero" style="background:linear-gradient(115deg,#06281f 0%,#0f4d3a 55%,#12614d 100%); align-items: flex-start !important;">
  <div class="risiko-hero-copy">
    <div class="risiko-eyebrow"><i class="fas fa-search-plus"></i> Pemantauan &amp; Reviu</div>
    <?php
    $badgeClass = 'badge-secondary';
    $statusText = $kkprRow['status_kkpmr'] ?? 'Draft';
    if ($statusText === 'Draft' || $statusText === 'Revisi') $badgeClass = 'badge-warning';
    if ($statusText === 'Menunggu Persetujuan') $badgeClass = 'badge-primary';
    if ($statusText === 'Disetujui') $badgeClass = 'badge-success';
    ?>
    <h1 class="page-title">Kertas Kerja Pemantauan &amp; Reviu</h1>
    <?php if ($activeId && $kkprRow): ?>
    <div style="margin-top:-4px; margin-bottom:8px;">
      <span class="badge <?= $badgeClass ?>" style="font-size:.72rem;padding:4px 12px;"><i class="fas fa-circle-check"></i> <?= xss($statusText) ?></span>
    </div>
    <?php endif; ?>
    <p class="page-sub">Pemantauan dan reviu risiko atas KKPR - pantau efektivitas mitigasi dan tindak lanjut.</p>
    
    <?php if ($activeId && $kkprRow): ?>
    <div style="margin-top:12px; display:flex; gap:8px;">
      <?php if(hasRole('Risk Manager') && !in_array($kkprRow['status_kkpmr'] ?? '', ['Menunggu Persetujuan', 'Disetujui'])): ?>
      <button class="btn btn-hero-primary" onclick="openModal('modalPersetujuanKkpmr')" style="padding:6px 14px; font-size:12px;"><i class="fas fa-paper-plane"></i> Ajukan Persetujuan</button>
      <?php endif; ?>
      <?php if(hasRole('Pimpinan') && ($kkprRow['status_kkpmr'] ?? '') === 'Menunggu Persetujuan'): ?>
      <form method="post" style="display:inline; margin:0;" onsubmit="return confirm('Setujui KKPMR ini?');">
          <?= csrfField() ?>
          <input type="hidden" name="aksi" value="approve_kkpmr">
          <input type="hidden" name="id" value="<?= $activeId ?>">
          <button type="submit" class="btn btn-hero-primary" style="background:var(--success); border-color:var(--success); padding:6px 14px; font-size:12px;"><i class="fas fa-check"></i> Setujui</button>
      </form>
      <button class="btn btn-hero-primary" style="background:var(--danger); border-color:var(--danger); padding:6px 14px; font-size:12px;" onclick="openModal('modalRejectKkpmr')"><i class="fas fa-times"></i> Revisi</button>
      <?php endif; ?>

    </div>
    <?php endif; ?>

  </div>
  <div class="profil-hero-tools risiko-hero-tools-align">
    <select class="form-control hero-year-select" style="max-width:130px;width:auto;text-align:center;text-align-last:center;" onchange="if(this.value) window.location.href='<?= APP_URL ?>/?page=kkpmr&tahun='+encodeURIComponent(this.value)" aria-label="Pilih tahun">
      <?php foreach ($tahunList as $y): ?>
      <option value="<?= xss($y) ?>" <?= (string)$y === (string)$activeTahun ? 'selected' : '' ?> style="text-align:center;"><?= xss($y) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($activeId && $kkprRow): ?>
    <div class="risiko-export-actions" style="margin-top:0; display:flex; align-items:center; gap:8px;">
      <a href="<?= APP_URL ?>/?page=kkpmr&id=<?= $activeId ?>&export=excel" class="btn btn-hero-ghost"><i class="fas fa-file-excel"></i> Excel</a>

      <select class="form-control hero-year-select" style="width: 155px !important; max-width: 155px !important; padding: 0 24px 0 14px !important; text-align-last: center !important;" onchange="if(this.value){window.open('<?= APP_URL ?>/?page=kkpmr&id=<?= $activeId ?>&export=pdf&periode='+encodeURIComponent(this.value),'_blank');this.selectedIndex=0;}" aria-label="Cetak Laporan" title="Cetak Laporan per triwulan / tahunan / bulanan">
        <option value="">&#128196; Cetak / PDF</option>
        <option value="bulan_ini" style="text-align: left;">Laporan Bulan Ini</option>
        <option value="tw1" style="text-align: left;">Laporan Triwulan I</option>
        <option value="tw2" style="text-align: left;">Laporan Triwulan II</option>
        <option value="tw3" style="text-align: left;">Laporan Triwulan III</option>
        <option value="tw4" style="text-align: left;">Laporan Triwulan IV</option>
        <option value="tahunan" style="text-align: left;">Laporan Tahunan</option>
      </select>
    </div>
    <?php endif; ?>
    <div class="risiko-hero-actions" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
      <?php if (hasRole('Admin', 'Risk Manager') && (!$activeId || !$kkprRow)): ?><a href="<?= APP_URL ?>/?page=kkpr&baru=1" class="btn btn-hero-primary btn-standard-action" style="margin:0;padding:8px 14px;white-space:nowrap;"><i class="fas fa-plus"></i> Tambah KKPMR</a><?php endif; ?>
      <?php if (!empty($kkprList) && $activeId && $kkprRow): ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Stat cards (glassmorphism inside hero) -->
  <div class="stats-grid cols-3" style="width:100%;margin-top:20px;margin-bottom:0">
    <a href="<?= APP_URL ?>/?page=kkpmr" class="stat-card stat-card-glass" style="--ga:#60a5fa;--ga-tint:rgba(96,165,250,.3);--ga-line:rgba(96,165,250,.45);--ga-glow:rgba(96,165,250,.3);text-decoration:none">
      <div class="stat-icon"><i class="fas fa-folder-open"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $kmTotal ?></div>
        <div class="stat-label">Total KKPMR</div>
      </div>
    </a>
    <a href="<?= APP_URL ?>/?page=kkpmr<?= $activeId ? '&id='.$activeId.'#kkpmrDataRisiko' : '' ?>" class="stat-card stat-card-glass" style="--ga:#a78bfa;--ga-tint:rgba(167,139,250,.3);--ga-line:rgba(167,139,250,.45);--ga-glow:rgba(167,139,250,.3);text-decoration:none">
      <div class="stat-icon"><i class="fas fa-magnifying-glass-chart"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $kmRisiko ?></div>
        <div class="stat-label">Risiko Dipantau</div>
      </div>
    </a>
    <a href="<?= APP_URL ?>/?page=kkpmr<?= $activeId ? '&id='.$activeId.'#kkpmrDataRisiko' : '' ?>" class="stat-card stat-card-glass" style="--ga:#f87171;--ga-tint:rgba(248,113,113,.28);--ga-line:rgba(248,113,113,.5);--ga-glow:rgba(248,113,113,.32);text-decoration:none">
      <div class="stat-icon"><i class="fas fa-fire"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $kmTinggi ?></div>
        <div class="stat-label">Risiko Tinggi+</div>
      </div>
    </a>
  </div>
</div>

<div class="risiko-flow">
  <a href="<?= APP_URL ?>/?page=risiko" class="risiko-flow-item"><span class="rf-num">1</span><div><strong>Identifikasi</strong><small>Master risiko</small></div></a>
  <a href="<?= APP_URL ?>/?page=profil_risiko" class="risiko-flow-item"><span class="rf-num">2</span><div><strong>Profil Risiko</strong><small>Penilaian & rencana</small></div></a>
  <a href="<?= APP_URL ?>/?page=kkpr" class="risiko-flow-item"><span class="rf-num">3</span><div><strong>KKPR</strong><small>Dokumen kerja</small></div></a>
  <div class="risiko-flow-item is-current"><span class="rf-num">4</span><div><strong>KKPMR</strong><small>Pemantauan & reviu</small></div></div>
  <a href="#kkpmrDataRisiko" class="risiko-flow-btn kkpmr-status-btn"><i class="fas <?= $kmStatusIcon ?>"></i> <?= $kmStatusText ?></a>
</div>

<?php if (empty($kkprList)): ?>
<div class="card" id="kkpmrDataRisiko">
  <div class="empty-state" style="padding:48px">
    <i class="fas fa-inbox" style="font-size:2.5rem;opacity:.3"></i>
    <h3 style="margin:12px 0 4px">Belum ada KKPR</h3>
    <p style="color:var(--text-muted);font-size:.85rem">Buat Kertas Kerja Penilaian Risiko terlebih dahulu sebelum melakukan pemantauan.</p>
    <?php if (hasRole('Admin', 'Risk Manager')): ?><a href="<?= APP_URL ?>/?page=kkpr&baru=1" class="btn btn-primary" style="margin-top:14px"><i class="fas fa-plus"></i> Tambah KKPMR</a><?php endif; ?>
  </div>
</div>
<?php elseif (!$activeId): ?>
<!-- Grid pilih KKPR -->
<div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(320px, 1fr));gap:24px;margin-bottom:24px">
  <?php foreach($kkprList as $kl): ?>
  <div class="card" style="cursor:pointer;transition:all .25s ease"
       onclick="window.location.href='<?= APP_URL ?>/?page=kkpmr&id=<?= $kl['id'] ?>'"
       onmouseover="this.style.transform='translateY(-6px)';this.style.boxShadow='0 15px 30px rgba(0,0,0,0.1)'"
       onmouseout="this.style.transform='none';this.style.boxShadow='var(--shadow)'">
    <div class="card-body" style="padding:24px">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px">
        <div style="width:52px;height:52px;border-radius:14px;background:rgba(16,185,129,0.15);display:flex;align-items:center;justify-content:center;color:var(--success);font-size:1.5rem">
          <i class="fas fa-search-plus"></i>
        </div>
        <div style="background:var(--surface2);padding:5px 12px;border-radius:20px;font-size:.78rem;font-weight:700;color:var(--text)">
          Tahun <?= xss($kl['tahun']) ?>
        </div>
        <?php if(hasRole('Admin','Pimpinan','Risk Manager')): ?>
        <form method="POST" action="<?= APP_URL ?>/?page=kkpmr" style="display:inline;margin:0" onclick="event.stopPropagation()" onsubmit="return confirm('Hapus KKPMR tahun <?= xss($kl['tahun']) ?>? Karena KKPMR & KKPR memakai record yg sama, KKPR-nya juga akan ikut terhapus.')">
          <?= csrfField() ?>
          <input type="hidden" name="aksi" value="hapus_kkpmr">
          <input type="hidden" name="id" value="<?= $kl['id'] ?>">
          <button type="submit" title="Hapus KKPMR" style="background:rgba(220,38,38,.12);color:var(--danger);border:none;width:34px;height:34px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center"><i class="fas fa-trash"></i></button>
        </form>
        <?php endif; ?>
      </div>
      <?php if((int)($kl['jml_detail'] ?? 0) === 0): ?>
      <div style="background:#fef9c3;border:1px solid #fde047;color:#854d0e;padding:6px 12px;border-radius:8px;font-size:.75rem;font-weight:700;margin-bottom:12px;display:flex;align-items:center;gap:6px">
        <i class="fas fa-exclamation-circle"></i> Belum lengkap — risiko belum diisi (0 risiko)
      </div>
      <?php else: ?>
      <div style="background:#dcfce7;border:1px solid #bbf7d0;color:#166534;padding:6px 12px;border-radius:8px;font-size:.75rem;font-weight:700;margin-bottom:12px;display:flex;align-items:center;gap:6px">
        <i class="fas fa-check-circle"></i> <?= (int)$kl['jml_detail'] ?> risiko dinilai
      </div>
      <?php endif; ?>
      <h3 style="font-size:1.15rem;font-weight:700;margin-bottom:8px;color:var(--text);line-height:1.4">
        <?= xss(mb_substr($kl['unit_pemilik_risiko']??'Unit Belum Ditentukan',0,50)) ?><?= mb_strlen($kl['unit_pemilik_risiko']??'')>50?'...':'' ?>
      </h3>
      <div style="font-size:.85rem;color:var(--text-muted);margin-bottom:24px;display:flex;flex-direction:column;gap:6px">
        <div style="display:flex;align-items:center;gap:8px">
          <i class="fas fa-user-tie" style="opacity:.6"></i> <span><?= xss($kl['nama_pemilik_risiko']?:'Pemilik Belum Ditentukan') ?></span>
        </div>
        <div style="display:flex;align-items:center;gap:8px;font-size:.75rem">
          <i class="fas fa-user-edit" style="opacity:.6"></i> <span>Dibuat oleh: <?= xss($kl['nama_creator'] ?? 'Sistem') ?></span>
        </div>
      </div>
      <button class="btn btn-outline" style="width:100%;justify-content:center;font-weight:600">Mulai Pemantauan <i class="fas fa-arrow-right" style="margin-left:8px;font-size:.8rem"></i></button>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php else: ?>

<!-- Badge Status Penilaian Risiko Aktif -->
<?php if(count($rows) === 0): ?>
<div style="background:#fef9c3;border:1px solid #fde047;color:#854d0e;padding:8px 14px;border-radius:8px;font-size:.8rem;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:8px">
  <i class="fas fa-exclamation-circle"></i> Belum lengkap — risiko belum diisi (0 risiko)
</div>
<?php else: ?>
<div style="background:#dcfce7;border:1px solid #bbf7d0;color:#166534;padding:8px 14px;border-radius:8px;font-size:.8rem;font-weight:700;margin-bottom:14px;display:inline-flex;align-items:center;gap:8px">
  <i class="fas fa-check-circle"></i> <?= count($rows) ?> risiko dinilai
</div>
<?php endif; ?>

<!-- Tabs KKPMR -->
<div class="tabs" style="margin-bottom:16px;">
  <button class="tab-btn <?= $activeTab==='detail'?'active':'' ?>" data-tab="kkpmrTabD" onclick="swTabKkpmr('kkpmrTabD',this)"><i class="fas fa-table"></i> 1. Hasil Pemantauan &amp; Reviu Risiko <span style="background:var(--accent);color:#fff;border-radius:12px;padding:2px 8px;font-size:.72rem;margin-left:6px;font-weight:700"><?= count($rows) ?></span></button>
  <button class="tab-btn <?= $activeTab==='header'?'active':'' ?>" data-tab="kkpmrTabH" onclick="swTabKkpmr('kkpmrTabH',this)"><i class="fas fa-info-circle"></i> 2. Info Dokumen &amp; Periode Pemantauan</button>
</div>

<!-- Tab: Header Pemantauan -->
<div id="kkpmrTabH" class="tab-content <?= $activeTab==='header'?'active':'' ?>">
  <div class="card" style="margin-bottom:18px">
    <div class="card-header" style="background:linear-gradient(135deg,var(--primary),var(--primary-light));border:none">
      <div>
        <span class="card-title" style="color:#fff;font-size:1rem"><i class="fas fa-info-circle"></i> Informasi Pemantauan — Tahun <?= xss($kkprRow['tahun']) ?></span>
        <div style="color:rgba(255,255,255,.65);font-size:.78rem;margin-top:2px"><?= xss($kkprRow['unit_pemilik_risiko']??'') ?></div>
      </div>
    </div>
  <?php
    // Ambil default Pimpinan
    $defPimpinanNama = ''; $defPimpinanNip = '';
    $pQuery = $db->query("SELECT nama, nip FROM users WHERE role = 'Pimpinan' LIMIT 1");
    if ($p = $pQuery->fetch_assoc()) {
        $defPimpinanNama = $p['nama'];
        $defPimpinanNip = $p['nip'];
    }
    // Default Pengelola (Risk Manager / current user)
    $defPengelolaNama = $_SESSION['user_nama'] ?? '';
    $defPengelolaNip = '';
    if (isset($_SESSION['user_id'])) {
        $uQuery = $db->query("SELECT nip FROM users WHERE id = " . (int)$_SESSION['user_id']);
        if ($u = $uQuery->fetch_assoc()) {
            $defPengelolaNip = $u['nip'];
        }
    }
    ?>
  <div class="card-body">
    <form method="POST" action="<?= APP_URL ?>/?page=kkpmr">
      <?= csrfField() ?>
      <input type="hidden" name="aksi" value="update_header_pemantauan">
      <input type="hidden" name="id_kkpr" value="<?= $activeId ?>">
      <div class="form-row-3">
        <div class="form-group">
          <label class="form-label">Periode Pemantauan</label>
          <input type="text" name="periode_pemantauan" class="form-control" placeholder="mis. Januari–Juni 2025" value="<?= xss($kkprRow['periode_risiko'] ?: '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Tgl Pemantauan</label>
          <input type="date" name="tgl_pemantauan" class="form-control" value="<?= xss($kkprRow['tgl_update'] ? date('Y-m-d', strtotime($kkprRow['tgl_update'])) : date('Y-m-d')) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Unit Pemilik Risiko</label>
          <input type="text" class="form-control" value="<?= xss($kkprRow['unit_pemilik_risiko'] ?? '') ?>" readonly style="background:var(--surface2)">
        </div>
      </div>
      <div class="form-row-2">
        <div class="form-group">
          <label class="form-label">Nama Pemilik Risiko (TTD)</label>
          <input type="text" name="nama_ttd_pemilik" class="form-control" list="listUsersWithNip" onchange="autofillNip(this, 'nip_ttd_pemilik')" value="<?= xss($kkprRow['nama_ttd_pemilik'] ?: $defPimpinanNama) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">NIP Pemilik Risiko</label>
          <input type="text" name="nip_ttd_pemilik" class="form-control" value="<?= xss($kkprRow['nip_ttd_pemilik'] ?: $defPimpinanNip) ?>">
        </div>
      </div>
      <div class="form-row-2">
        <div class="form-group">
          <label class="form-label">Nama Pengelola Risiko (TTD)</label>
          <input type="text" name="nama_ttd_pengelola" class="form-control" list="listUsersWithNip" onchange="autofillNip(this, 'nip_ttd_pengelola')" value="<?= xss($kkprRow['nama_ttd_pengelola'] ?: $defPengelolaNama) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">NIP Pengelola Risiko</label>
          <input type="text" name="nip_ttd_pengelola" class="form-control" value="<?= xss($kkprRow['nip_ttd_pengelola'] ?: $defPengelolaNip) ?>">
        </div>
      </div>
      <?php if (empty($isLockedKkpmr)): ?>
      <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Info Pemantauan</button>
      <?php endif; ?>
    </form>

    <!-- CTA Banner: Arahkan langsung ke Hasil Pemantauan -->
    <div style="margin-top:20px;padding:14px 18px;background:var(--surface2);border-radius:8px;border:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
      <div style="font-size:.82rem;color:var(--text-muted)">
        <i class="fas fa-lightbulb" style="color:var(--accent);margin-right:6px"></i> Form di atas adalah informasi periode dan pejabat pemantauan. Untuk pengisian hasil monev dan efektivitas risiko, silakan buka tab Hasil Pemantauan.
      </div>
      <button type="button" class="btn btn-primary" onclick="bukaTabDetailKkpmr()" style="font-weight:700">
        <i class="fas fa-table"></i> Buka Hasil Pemantauan (<?= count($rows) ?>) <i class="fas fa-arrow-right"></i>
      </button>
    </div>
  </div>
</div>
</div>

<!-- Tab: Detail Pemantauan -->
<div id="kkpmrTabD" class="tab-content <?= $activeTab==='detail'?'active':'' ?>">

  <!-- Compact Context Bar -->
  <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:12px 18px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;box-shadow:var(--shadow-sm)">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
      <span style="background:var(--primary-glow);color:var(--primary);font-weight:800;padding:4px 10px;border-radius:8px;font-size:.85rem">
        <i class="fas fa-search-plus"></i> KKPMR Tahun <?= xss($kkprRow['tahun']) ?>
      </span>
      <span style="font-weight:700;color:var(--text);font-size:.92rem">
        <?= xss($kkprRow['unit_pemilik_risiko'] ?? 'Unit Belum Ditentukan') ?>
      </span>
      <?php if(!empty($kkprRow['status_kkpmr'])): ?>
      <span class="badge <?= $badgeClass ?>" style="font-size:.75rem">
        Status: <?= xss($kkprRow['status_kkpmr']) ?>
      </span>
      <?php endif; ?>
      <span style="background:#dcfce7;border:1px solid #bbf7d0;color:#166534;padding:3px 10px;border-radius:8px;font-size:.76rem;font-weight:700;display:inline-flex;align-items:center;gap:5px">
        <i class="fas fa-check-circle"></i> <?= count($rows) ?> risiko dinilai
      </span>
      <?php if(!empty($kkprRow['periode_risiko'])): ?>
      <span style="color:var(--text-muted);font-size:.82rem;border-left:1px solid var(--border);padding-left:12px">
        <i class="fas fa-calendar-alt" style="color:var(--primary);margin-right:4px"></i> Periode: <?= xss($kkprRow['periode_risiko']) ?>
      </span>
      <?php endif; ?>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <span style="font-size:.8rem;color:var(--text-muted)">
        Belum Dipantau: <strong style="color:<?= $kmBelumDipantau === 0 ? 'var(--success)' : 'var(--danger)' ?>"><?= $kmBelumDipantau ?></strong> / <?= count($rows) ?>
      </span>
      <button type="button" class="btn btn-sm btn-outline" onclick="bukaTabHeaderKkpmr()" title="Lihat dan edit periode pemantauan dan pejabat penandatangan">
        <i class="fas fa-info-circle"></i> Info Periode &amp; TTD <i class="fas fa-chevron-right" style="font-size:.7rem;margin-left:2px"></i>
      </button>
    </div>
  </div>

<!-- Tabel Pemantauan -->
<div class="card" id="kkpmrDataRisiko">
  <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <span class="card-title" style="margin:0;"><i class="fas fa-table"></i> Hasil Pemantauan &amp; Reviu Risiko (<?= count($rows) ?> risiko)</span>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;flex:1;justify-content:flex-end">
      <div class="search-bar" style="max-width:250px;width:100%">
        <i class="fas fa-search"></i>
        <input type="text" class="form-control" id="searchKkpmr" placeholder="Cari risiko..." onkeyup="filterTableKkpmr()" style="height:38px">
      </div>
      <div class="datatable-dropdown" style="margin:0; display:flex; align-items:center;">
        <select class="datatable-selector" id="limitKkpmr" onchange="filterTableKkpmr()">
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
    <table class="data-table kkpmr-data-table no-datatable" id="tableKkpmr" style="font-size:.74rem">
      <thead>
        <tr style="background:var(--surface2)">
          <th rowspan="2" style="vertical-align:middle;text-align:center;width:34px">NO</th>
          <th rowspan="2" style="vertical-align:middle;text-align:center;width:65px">KODE</th>
          <th rowspan="2" style="vertical-align:middle;min-width:180px">RISIKO</th>
          <th colspan="4" style="text-align:center;background:#e2e8f0;color:#1e3a8a;border-bottom:1px solid #cbd5e1">PENILAIAN AWAL</th>
          <th rowspan="2" style="vertical-align:middle;min-width:140px">URAIAN PENGENDALIAN</th>
          <th rowspan="2" style="vertical-align:middle;text-align:center;width:110px">JADWAL</th>
          <th colspan="4" style="text-align:center;background:#dcfce7;color:#166534;border-bottom:1px solid #bbf7d0">HASIL PEMANTAUAN</th>
          <th colspan="2" style="text-align:center;background:#fef3c7;color:#92400e;border-bottom:1px solid #fde68a">SIMPULAN</th>
          <th rowspan="2" style="vertical-align:middle;text-align:center;width:85px">STATUS MONEV</th>
          <th rowspan="2" style="vertical-align:middle;text-align:center;width:48px">AKSI</th>
        </tr>
        <tr style="background:var(--surface2)">
          <th style="text-align:center;width:30px;background:#edf2f7" title="Probabilitas">P</th>
          <th style="text-align:center;width:30px;background:#edf2f7" title="Dampak">D</th>
          <th style="text-align:center;width:52px;background:#edf2f7" title="Nilai &amp; Bobot">Nilai<br><span style="font-size:.6rem;font-weight:normal;color:#475569">(Bobot)</span></th>
          <th style="text-align:center;width:75px;background:#edf2f7">TINGKAT</th>
          <th style="text-align:center;width:30px;background:#e8fdf0" title="Target Probabilitas">P</th>
          <th style="text-align:center;width:30px;background:#e8fdf0" title="Target Dampak">D</th>
          <th style="text-align:center;width:52px;background:#e8fdf0" title="Target Nilai &amp; Bobot">Nilai<br><span style="font-size:.6rem;font-weight:normal;color:#166534">(Bobot)</span></th>
          <th style="text-align:center;width:75px;background:#e8fdf0">TINGKAT</th>
          <th style="text-align:center;width:110px;background:#fef9c3">TINGKAT RISIKO</th>
          <th style="text-align:center;width:80px;background:#fef9c3">EFEKTIFITAS</th>
        </tr>
        <tr style="background:var(--surface3);color:var(--text-muted);font-size:.68rem">
          <th style="text-align:center">1</th>
          <th style="text-align:center">2</th>
          <th style="text-align:center">3</th>
          <th style="text-align:center">4</th>
          <th style="text-align:center">5</th>
          <th style="text-align:center">6</th>
          <th style="text-align:center">7</th>
          <th style="text-align:center">8</th>
          <th style="text-align:center">9</th>
          <th style="text-align:center">10</th>
          <th style="text-align:center">11</th>
          <th style="text-align:center">12</th>
          <th style="text-align:center">13</th>
          <th style="text-align:center">14</th>
          <th style="text-align:center">15</th>
          <th style="text-align:center">16</th>
          <th style="text-align:center">17</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="17"><div class="empty-state" style="padding:24px"><i class="fas fa-inbox"></i><h3>Belum ada risiko di KKPR ini</h3><p>Tambahkan risiko di modul KKPR terlebih dahulu.</p></div></td></tr>
      <?php else: ?>
        <?php foreach ($rows as $i => $r): ?>
        <tr>
          <td style="text-align:center;color:var(--text-muted);font-weight:600"><?= $i + 1 ?></td>
          <td style="text-align:center;white-space:nowrap"><span class="badge-kode-risiko"><?= xss($r['kode_risiko'] ?: '-') ?></span></td>
          <td style="min-width:180px;font-weight:600;line-height:1.35;color:var(--text-main)"><?= xss($r['nama_risiko']) ?></td>
          <!-- Penilaian awal -->
          <td style="text-align:center;font-weight:700"><?= (int)$r['probabilitas'] ?></td>
          <td style="text-align:center;font-weight:700"><?= (int)$r['dampak_level'] ?></td>
          <td style="text-align:center">
            <div style="font-weight:700;font-size:.82rem;line-height:1.1;color:var(--accent)"><?= round((float)$r['nilai_risiko']) ?></div>
            <div style="font-size:.66rem;color:var(--accent);font-weight:600;margin-top:1px" title="Bobot"><?= number_format((float)$r['bobot'], 2) ?></div>
          </td>
          <td style="text-align:center">
            <?php $bgT = kkpmrBg($r['tingkat_risiko'] ?? 'Rendah'); $clT = kkpmrColor($r['tingkat_risiko'] ?? 'Rendah'); ?>
            <span style="background:<?= $bgT ?>;color:<?= $clT ?>;padding:3px 7px;border-radius:10px;font-weight:700;font-size:.68rem;display:inline-block;white-space:nowrap"><?= xss($r['tingkat_risiko'] ?: '-') ?></span>
          </td>
          <td style="font-size:.72rem;line-height:1.35"><?= xss($r['rpti_uraian'] ?? '-') ?></td>
          <td style="text-align:center;font-size:.70rem;"><?= xss($r['rpti_jadwal'] ?: '-') ?></td>
          <!-- Hasil pemantauan -->
          <td style="text-align:center;font-weight:700;color:var(--success)"><?= $r['pantau_p'] !== null ? (int)$r['pantau_p'] : '-' ?></td>
          <td style="text-align:center;font-weight:700;color:var(--success)"><?= $r['pantau_d'] !== null ? (int)$r['pantau_d'] : '-' ?></td>
          <td style="text-align:center">
            <?php if ($r['pantau_nilai'] !== null): ?>
            <div style="font-weight:700;font-size:.82rem;line-height:1.1;color:var(--success)"><?= round((float)$r['pantau_nilai']) ?></div>
            <div style="font-size:.66rem;color:#16a34a;font-weight:600;margin-top:1px" title="Target Bobot"><?= number_format((float)$r['pantau_bobot'], 2) ?></div>
            <?php else: ?>-<?php endif; ?>
          </td>
          <td style="text-align:center">
            <?php if (!empty($r['pantau_tingkat'])): ?>
              <?php $pBgT = kkpmrBg($r['pantau_tingkat']); $pClT = kkpmrColor($r['pantau_tingkat']); ?>
              <span style="background:<?= $pBgT ?>;color:<?= $pClT ?>;padding:3px 7px;border-radius:10px;font-weight:700;font-size:.68rem;display:inline-block;white-space:nowrap"><?= xss($r['pantau_tingkat']) ?></span>
            <?php else: ?> - <?php endif; ?>
          </td>
          <?php 
            $sCls = ''; $sText = '-';
            if (!empty($r['simpulan'])) {
              if ($r['simpulan'] === 'Penurunan') { $sCls = 'background:#dcfce7;'; $sText = 'Penurunan'; }
              elseif ($r['simpulan'] === 'Peningkatan') { $sCls = 'background:#fee2e2;'; $sText = 'Peningkatan'; }
              else { $sCls = 'background:#fefce8;'; $sText = 'Tetap'; }
            }
          ?>
          <td style="text-align:center;font-size:.70rem;font-weight:600;<?= $sCls ?>"><?= xss($sText) ?></td>
          <?php 
            $eCls = ''; $eText = '-';
            if (!empty($r['efektifitas'])) {
              if ($r['efektifitas'] === 'Efektif') { $eCls = 'background:#dcfce7;'; $eText = 'Efektif'; }
              else { $eCls = 'background:#fee2e2;'; $eText = 'Tidak Efektif'; }
            }
          ?>
          <td style="text-align:center;font-size:.70rem;font-weight:600;<?= $eCls ?>"><?= xss($eText) ?></td>
          <?php $monevStatusLabel = $r['monev_status'] ?? ($r['pantau_nilai'] === null ? 'Belum Dipantau' : ($r['simpulan'] === 'Peningkatan' ? 'Eskalasi' : 'Sudah Dipantau')); ?>
          <td style="text-align:center;font-size:.68rem"><span class="badge <?= $monevStatusLabel === 'Eskalasi' ? 'badge-danger' : ($monevStatusLabel === 'Sudah Dipantau' ? 'badge-success' : 'badge-warning') ?>"><?= xss($monevStatusLabel) ?></span></td>
          <td class="kkpmr-action-cell" style="text-align:center;white-space:nowrap">
            <div class="act-btn-group" style="justify-content:center">
              <?php if (empty($isLockedKkpmr)): ?>
              <button type="button" class="act-btn act-btn-edit" onclick="editPemantauan(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)" title="Edit"><i class="fas fa-edit"></i></button>
              <?php else: ?>
              <button type="button" class="act-btn act-btn-view" onclick="editPemantauan(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)" title="Lihat Detail"><i class="fas fa-eye"></i></button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer" id="kkpmrPagination" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;padding:14px 20px;">
    <span class="pagination-info" id="kkpmrPageInfo" style="font-size:.76rem;color:var(--text-muted);font-weight:600;">Memuat...</span>
    <div class="pagination" id="kkpmrPages" style="margin:0;gap:5px;"></div>
  </div>
</div>
</div>

<!-- Modal Edit Pemantauan -->
<div class="modal-overlay" id="modalPemantauan" style="display:none">
  <div class="modal modal-lg">
    <div class="modal-header">
      <h3 class="modal-title"><i class="fas fa-search-plus"></i> Hasil Pemantauan &amp; Reviu</h3>
      <button class="btn-close" onclick="closeModal('modalPemantauan')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/?page=kkpmr">
      <?= csrfField() ?>
      <input type="hidden" name="aksi" value="simpan_pemantauan">
      <input type="hidden" name="id_risiko" id="pmIdRisiko">
      <input type="hidden" name="id_kkpr" value="<?= $activeId ?>">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Risiko</label>
          <div id="pmNamaRisiko" style="padding:8px 12px;background:var(--surface2);border-radius:8px;font-weight:600"></div>
        </div>
        <div class="form-row-2" style="margin-bottom:12px">
          <div style="padding:10px 14px;background:var(--surface2);border-radius:8px;border-left:3px solid var(--accent)">
            <div style="font-size:.72rem;color:var(--text-muted);font-weight:700">PENILAIAN AWAL</div>
            <div id="pmAwal" style="font-size:.85rem;margin-top:4px"></div>
          </div>
          <div style="padding:10px 14px;background:var(--surface2);border-radius:8px;border-left:3px solid var(--success)">
            <div style="font-size:.72rem;color:var(--text-muted);font-weight:700">TARGET PENURUNAN</div>
            <div id="pmTarget" style="font-size:.85rem;margin-top:4px"></div>
          </div>
        </div>
        <hr style="margin:14px 0;border-color:var(--border)">
        <h4 style="margin-bottom:12px;color:var(--success)"><i class="fas fa-chart-line"></i> Hasil Pemantauan</h4>
        <div style="display:grid;grid-template-columns:1fr 240px;gap:16px;margin-bottom:16px">
          <!-- Sliders -->
          <div>
            <div style="margin-bottom:20px">
              <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:.85rem;font-weight:700">
                <span>Probabilitas: <span id="pmPLabelBadge" style="background:#3b82f6;color:#fff;padding:2px 8px;border-radius:12px;margin:0 4px">1</span> &mdash; <span id="pmPLabelText">Jarang</span></span>
              </div>
              <input type="range" name="pantau_p" id="pmP" min="1" max="5" value="1" style="width:100%;accent-color:#3b82f6;cursor:pointer">
              <div style="display:flex;justify-content:space-between;font-size:.7rem;color:var(--text-muted);margin-top:4px">
                <span>1=Jarang</span><span>2=Kecil</span><span>3=Sedang</span><span>4=Besar</span><span>5=Hampir Pasti</span>
              </div>
            </div>
            <div>
              <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:.85rem;font-weight:700">
                <span>Dampak Level: <span id="pmDLabelBadge" style="background:#3b82f6;color:#fff;padding:2px 8px;border-radius:12px;margin:0 4px">1</span> &mdash; <span id="pmDLabelText">Tidak Signifikan</span></span>
              </div>
              <input type="range" name="pantau_d" id="pmD" min="1" max="5" value="1" style="width:100%;accent-color:#3b82f6;cursor:pointer">
              <div style="display:flex;justify-content:space-between;font-size:.7rem;color:var(--text-muted);margin-top:4px">
                <span>1=T.Signifikan</span><span>2=Kecil</span><span>3=Sedang</span><span>4=Besar</span><span>5=Katastropik</span>
              </div>
            </div>
            <!-- Hidden inputs to submit values -->
            <input type="hidden" name="pantau_bobot" id="pmBobot" value="1">
          </div>
          
          <!-- Summary Card -->
          <div style="background:var(--surface2);border-radius:12px;padding:16px;border:1px solid var(--border);display:flex;flex-direction:column;justify-content:center">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px">
              <div>
                <div style="font-size:.75rem;color:var(--text-muted);font-weight:600">Nilai Risiko</div>
                <div id="pmHasilNilai" style="font-size:2.5rem;font-weight:800;color:#3b82f6;line-height:1">1</div>
              </div>
              <div style="text-align:right">
                <div style="font-size:.75rem;color:var(--text-muted);font-weight:600;margin-bottom:4px">Tingkat Risiko</div>
                <div id="pmHasilTingkatBadge" style="background:#dcfce7;color:#166534;padding:4px 10px;border-radius:6px;font-size:.8rem;font-weight:700;display:inline-block">Sangat Rendah</div>
              </div>
            </div>
            
            <hr style="border-color:var(--border);margin:0 0 16px 0">
            
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:8px;text-align:center">
              <div>
                <div id="pmHasilP" style="background:#1e3a8a;color:#fff;border-radius:6px;padding:6px 0;font-weight:700;font-size:.9rem;box-shadow:inset 0 -3px 0 rgba(0,0,0,.2)">1</div>
                <div style="font-size:.65rem;color:var(--text-muted);margin-top:4px;font-weight:600">P</div>
              </div>
              <div>
                <div id="pmHasilD" style="background:#1e3a8a;color:#fff;border-radius:6px;padding:6px 0;font-weight:700;font-size:.9rem;box-shadow:inset 0 -3px 0 rgba(0,0,0,.2)">1</div>
                <div style="font-size:.65rem;color:var(--text-muted);margin-top:4px;font-weight:600">D</div>
              </div>
              <div>
                <div id="pmHasilB" style="background:#1e3a8a;color:#fff;border-radius:6px;padding:6px 0;font-weight:700;font-size:.9rem;box-shadow:inset 0 -3px 0 rgba(0,0,0,.2)">1.00</div>
                <div style="font-size:.65rem;color:var(--text-muted);margin-top:4px;font-weight:600">Bobot</div>
              </div>
              <div>
                <div id="pmHasilS" style="background:#16a34a;color:#fff;border-radius:6px;padding:6px 0;font-weight:700;font-size:.9rem;box-shadow:inset 0 -3px 0 rgba(0,0,0,.2)">1</div>
                <div style="font-size:.65rem;color:var(--text-muted);margin-top:4px;font-weight:600">Skor</div>
              </div>
            </div>
          </div>
        </div>
        <div class="form-row-2">
          <div class="form-group">
            <label class="form-label">Simpulan (Otomatis)</label>
            <input type="text" name="simpulan" id="pmSimpulan" class="form-control" style="background:var(--surface2);font-weight:700" readonly>
          </div>
          <div class="form-group">
            <label class="form-label">Efektifitas (Otomatis)</label>
            <input type="text" name="efektifitas" id="pmEfektifitas" class="form-control" style="background:var(--surface2);font-weight:700" readonly>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" onclick="closeModal('modalPemantauan')" class="btn btn-outline">Tutup</button>
        <?php if (empty($isLockedKkpmr)): ?>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Pemantauan</button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<script>
function swTabKkpmr(id, btn){
  document.querySelectorAll('#kkpmrTabD, #kkpmrTabH').forEach(t=>t.classList.remove('active'));
  document.querySelectorAll('.tabs .tab-btn').forEach(b=>b.classList.remove('active'));
  document.getElementById(id)?.classList.add('active');
  if(btn) {
    btn.classList.add('active');
  } else {
    document.querySelector(`.tabs .tab-btn[data-tab="${id}"]`)?.classList.add('active');
  }
  if(history.replaceState) {
    const u = new URL(window.location);
    u.searchParams.set('tab', id === 'kkpmrTabH' ? 'header' : 'detail');
    history.replaceState(null, '', u);
  }
}
function bukaTabDetailKkpmr(){
  const btn = document.querySelector('.tabs .tab-btn[data-tab="kkpmrTabD"]');
  swTabKkpmr('kkpmrTabD', btn);
  document.getElementById('kkpmrDataRisiko')?.scrollIntoView({behavior:'smooth', block:'start'});
}
function bukaTabHeaderKkpmr(){
  const btn = document.querySelector('.tabs .tab-btn[data-tab="kkpmrTabH"]');
  swTabKkpmr('kkpmrTabH', btn);
  window.scrollTo({top:0, behavior:'smooth'});
}

const matriksBobot = {
  5: {1: 1.5, 2: 1.4, 3: 1.13, 4: 1.15, 5: 1},
  4: {1: 1.2, 2: 1.19, 3: 1.3, 4: 1.16, 5: 1.2},
  3: {1: 1.17, 2: 1.42, 3: 1.43, 4: 1.46, 5: 1.47},
  2: {1: 1, 2: 1.8, 3: 1.83, 4: 1.9, 5: 2.1},
  1: {1: 1, 2: 1.5, 3: 2, 4: 3, 5: 4}
};
const lblP = {1:'Jarang',2:'Kecil',3:'Sedang',4:'Besar',5:'Hampir Pasti'};
const lblD = {1:'Tidak Signifikan',2:'Kecil',3:'Sedang',4:'Besar',5:'Katastropik'};

function kkpmrLevels(s){
    if(s>=20)return 'Sangat Tinggi';
    if(s>=15)return 'Tinggi';
    if(s>=10)return 'Sedang';
    if(s>=5)return 'Rendah';
    return 'Sangat Rendah';
}
function kkpmrBgMap(s){
    if(s>=20)return '#fee2e2';
    if(s>=15)return '#ffedd5';
    if(s>=10)return '#FFFF00';
    if(s>=5)return '#dcfce7';
    return '#dbeafe';
}
function kkpmrClMap(s){
    if(s>=20)return '#991b1b';
    if(s>=15)return '#c2410c';
    if(s>=10)return '#b45309';
    if(s>=5)return '#166534';
    return '#1d4ed8';
}

let currentNilaiAwal = 0;

function editPemantauan(r) {
  document.getElementById('pmIdRisiko').value = r.id;
  document.getElementById('pmNamaRisiko').textContent = r.nama_risiko || '(tanpa nama)';
  currentNilaiAwal = Math.round(parseFloat(r.nilai_risiko)) || 0;
  
  document.getElementById('pmAwal').innerHTML =
    'P=' + r.probabilitas + ' &middot; D=' + r.dampak_level +
    ' &middot; Bobot=' + parseFloat(r.bobot).toFixed(2) +
    ' &middot; Nilai=' + currentNilaiAwal +
    ' &middot; <strong>' + (r.tingkat_risiko || '-') + '</strong>';
  document.getElementById('pmTarget').innerHTML =
    'P=' + r.target_p + ' &middot; D=' + r.target_d +
    ' &middot; Bobot=' + parseFloat(r.target_bobot).toFixed(2) +
    ' &middot; Nilai=' + Math.round(parseFloat(r.target_nilai)) +
    ' &middot; <strong>' + (r.target_tingkat || '-') + '</strong>';
  document.getElementById('pmP').value = r.pantau_p || 1;
  document.getElementById('pmD').value = r.pantau_d || 1;
  
  updatePmHasil();
  openModal('modalPemantauan');
}

function updatePmHasil() {
  const p = parseInt(document.getElementById('pmP').value) || 1;
  const d = parseInt(document.getElementById('pmD').value) || 1;
  
  const b = (matriksBobot[p] && matriksBobot[p][d]) ? matriksBobot[p][d] : 1.00;
  document.getElementById('pmBobot').value = b;
  
  const skor = p * d * b;
  const nilai = Math.round(skor);
  const tingkat = kkpmrLevels(skor);
  
  // Update Labels
  document.getElementById('pmPLabelBadge').innerText = p;
  document.getElementById('pmPLabelText').innerText = lblP[p];
  document.getElementById('pmDLabelBadge').innerText = d;
  document.getElementById('pmDLabelText').innerText = lblD[d];
  
  // Update Card
  document.getElementById('pmHasilNilai').innerText = nilai;
  
  const elTingkat = document.getElementById('pmHasilTingkatBadge');
  elTingkat.innerText = tingkat;
  elTingkat.style.background = kkpmrBgMap(skor);
  elTingkat.style.color = kkpmrClMap(skor);
  
  document.getElementById('pmHasilP').innerText = p;
  document.getElementById('pmHasilD').innerText = d;
  document.getElementById('pmHasilB').innerText = parseFloat(b).toFixed(2);
  document.getElementById('pmHasilS').innerText = nilai;
  
  // Auto-calculate Simpulan & Efektifitas
  let simpulanVal = 'Tidak ada penurunan tingkat risiko';
  let efektifitasVal = 'Tidak Efektif';
  
  if (nilai < currentNilaiAwal) {
      simpulanVal = 'Tingkat risiko mengalami penurunan';
      efektifitasVal = 'Efektif';
  } else if (nilai > currentNilaiAwal) {
      simpulanVal = 'Tingkat risiko mengalami peningkatan';
      efektifitasVal = 'Tidak Efektif';
  }
  
  document.getElementById('pmSimpulan').value = simpulanVal;
  document.getElementById('pmEfektifitas').value = efektifitasVal;
  
  const elSkorBox = document.getElementById('pmHasilS');
  elSkorBox.style.background = (skor>=20?'#dc2626':skor>=15?'#ea580c':skor>=10?'#d97706':skor>=5?'#16a34a':'#2563eb');
}

document.getElementById('pmP').addEventListener('input', updatePmHasil);
document.getElementById('pmD').addEventListener('input', updatePmHasil);

const kkpmrState = { page: 1, lastQuery: '', lastLimit: 10 };
let emptyRowKkpmr = null;
function filterTableKkpmr() {
  const query = (document.getElementById('searchKkpmr')?.value || '').toLowerCase();
  const limit = parseInt(document.getElementById('limitKkpmr')?.value || 10, 10);
  if (query !== kkpmrState.lastQuery || limit !== kkpmrState.lastLimit) {
    kkpmrState.page = 1;
    kkpmrState.lastQuery = query;
    kkpmrState.lastLimit = limit;
  }
  const allRows = [...document.querySelectorAll('#tableKkpmr tbody tr')];
  
  const visible = allRows.filter(row => {
    if(row.querySelector('.empty-state') || row.id === 'emptySearchKkpmr') return false;
    const text = row.textContent.toLowerCase();
    if (query && !text.includes(query)) return false;
    return true;
  });

  const total = visible.length;
  const pages = Math.max(1, Math.ceil(total / limit));
  if (kkpmrState.page > pages) kkpmrState.page = pages;
  const start = (kkpmrState.page - 1) * limit;

  allRows.forEach(row => { if (row.id !== 'emptySearchKkpmr') row.style.display = 'none'; });
  visible.slice(start, start + limit).forEach(row => { row.style.display = ''; });

  const infoEl = document.getElementById('kkpmrPageInfo');
  if(infoEl) {
    infoEl.textContent = total === 0 ? 'Tidak ada data' : 'Menampilkan ' + (start + 1) + '–' + Math.min(start + limit, total) + ' dari ' + total + ' data';
  }

  const pagesEl = document.getElementById('kkpmrPages');
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
            kkpmrState.page = page;
            filterTableKkpmr();
            document.getElementById('tableKkpmr')?.scrollIntoView({behavior:'smooth', block:'nearest'});
          };
        }
        pagesEl.appendChild(b);
      };

      mkBtn('<i class="fas fa-angles-left"></i>', 1, kkpmrState.page <= 1, false, 'Halaman Pertama');
      mkBtn('<i class="fas fa-chevron-left"></i>', kkpmrState.page - 1, kkpmrState.page <= 1, false, 'Halaman Sebelumnya');
      for (let p = Math.max(1, kkpmrState.page - 2); p <= Math.min(pages, kkpmrState.page + 2); p++) {
        mkBtn(String(p), p, false, p === kkpmrState.page);
      }
      mkBtn('<i class="fas fa-chevron-right"></i>', kkpmrState.page + 1, kkpmrState.page >= pages, false, 'Halaman Berikutnya');
      mkBtn('<i class="fas fa-angles-right"></i>', pages, kkpmrState.page >= pages, false, 'Halaman Terakhir');
    } else {
      pagesEl.style.display = 'none';
    }
  }
  
  if (total === 0 && allRows.length > 0) {
    if (!emptyRowKkpmr) {
      emptyRowKkpmr = document.createElement('tr');
      emptyRowKkpmr.id = 'emptySearchKkpmr';
      emptyRowKkpmr.innerHTML = `<td colspan=\"20\"><div class=\"empty-state\"><i class=\"fas fa-search\"></i><p>Pencarian \"<b>${query}</b>\" tidak ditemukan.</p></div></td>`;
      document.querySelector('#tableKkpmr tbody').appendChild(emptyRowKkpmr);
    } else {
      emptyRowKkpmr.style.display = '';
      emptyRowKkpmr.innerHTML = `<td colspan=\"20\"><div class=\"empty-state\"><i class=\"fas fa-search\"></i><p>Pencarian \"<b>${query}</b>\" tidak ditemukan.</p></div></td>`;
    }
  } else if (emptyRowKkpmr) {
    emptyRowKkpmr.style.display = 'none';
  }
}
// Init limit
setTimeout(() => filterTableKkpmr(), 100);
</script>

<!-- Modal Approval KKPMR -->
<div class="modal-overlay" id="modalPersetujuanKkpmr" style="display:none">
  <div class="modal modal-md">
    <div class="modal-header">
      <h3 class="modal-title">Ajukan Persetujuan KKPMR</h3>
      <button class="btn-close" onclick="closeModal('modalPersetujuanKkpmr')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/?page=kkpmr">
      <?= csrfField() ?>
      <input type="hidden" name="aksi" value="kirim_persetujuan_kkpmr">
      <input type="hidden" name="id" value="<?= $activeId ?>">
      <div class="modal-body">
        <p>Anda yakin ingin mengajukan KKPMR ini untuk persetujuan Pimpinan? Setelah diajukan, data KKPMR tidak dapat diubah kecuali dikembalikan (Revisi) oleh Pimpinan.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('modalPersetujuanKkpmr')">Batal</button>
        <button type="submit" class="btn btn-primary">Ya, Ajukan Persetujuan</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-overlay" id="modalApproveKkpmr" style="display:none">
  <div class="modal modal-md">
    <div class="modal-header">
      <h3 class="modal-title">Setujui KKPMR</h3>
      <button class="btn-close" onclick="closeModal('modalApproveKkpmr')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/?page=kkpmr">
      <?= csrfField() ?>
      <input type="hidden" name="aksi" value="approve_kkpmr">
      <input type="hidden" name="id" value="<?= $activeId ?>">
      <div class="modal-body">
        <p>Anda yakin ingin menyetujui KKPMR ini? KKPMR yang disetujui akan dianggap final (locked).</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('modalApproveKkpmr')">Batal</button>
        <button type="submit" class="btn btn-success">Setujui KKPMR</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-overlay" id="modalRejectKkpmr" style="display:none">
  <div class="modal modal-md">
    <div class="modal-header">
      <h3 class="modal-title">Tolak / Kembalikan KKPMR</h3>
      <button class="btn-close" onclick="closeModal('modalRejectKkpmr')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/?page=kkpmr">
      <?= csrfField() ?>
      <input type="hidden" name="aksi" value="reject_kkpmr">
      <input type="hidden" name="id" value="<?= $activeId ?>">
      <div class="modal-body">
        <p>Masukkan catatan revisi mengapa KKPMR ini dikembalikan.</p>
        <div class="form-group">
          <label class="form-label">Catatan Revisi</label>
          <textarea class="form-control" name="catatan_revisi" rows="4" required></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('modalRejectKkpmr')">Batal</button>
        <button type="submit" class="btn btn-danger">Tolak &amp; Kembalikan</button>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>

