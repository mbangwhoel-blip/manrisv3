<?php
/**
 * MODUL PROFIL RISIKO — Input & Laporan
 * Sesuai format dok: Profil Risiko Tingkat Unit UPR-T.II Kemenkes
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$db = getDB();

$riskIdColumn = $db->query("SHOW COLUMNS FROM profil_risiko_detail LIKE 'id_risiko'");
if ($riskIdColumn && $riskIdColumn->num_rows === 0) {
    $db->query("ALTER TABLE profil_risiko_detail ADD COLUMN id_risiko INT DEFAULT NULL AFTER id_profil");
    $db->query("UPDATE profil_risiko_detail d JOIN risiko r ON r.kode_risiko=d.kode_risiko SET d.id_risiko=r.id WHERE d.id_risiko IS NULL AND r.deleted_at IS NULL");
    $db->query("ALTER TABLE profil_risiko_detail ADD KEY idx_profil_detail_risiko (id_risiko)");
}

// Master indikator-target dan snapshot pilihan profil dibuat otomatis agar fitur
// dapat langsung diuji tanpa menjalankan SQL manual terlebih dahulu.
$db->query("CREATE TABLE IF NOT EXISTS master_indikator_kegiatan (
    id INT NOT NULL AUTO_INCREMENT, tahun VARCHAR(9) NOT NULL, program VARCHAR(200) DEFAULT NULL,
    kegiatan TEXT DEFAULT NULL, sasaran TEXT DEFAULT NULL, indikator TEXT NOT NULL,
    target VARCHAR(500) DEFAULT NULL, satuan VARCHAR(100) DEFAULT NULL,
    unit_pemilik_risiko VARCHAR(200) DEFAULT NULL, aktif TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT DEFAULT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id), KEY idx_master_indikator_tahun (tahun), KEY idx_master_indikator_aktif (aktif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$db->query("CREATE TABLE IF NOT EXISTS profil_risiko_indikator (
    id INT NOT NULL AUTO_INCREMENT, id_profil INT NOT NULL, id_master INT DEFAULT NULL,
    tahun VARCHAR(9) DEFAULT NULL, program VARCHAR(200) DEFAULT NULL, kegiatan TEXT DEFAULT NULL,
    sasaran TEXT DEFAULT NULL, indikator TEXT NOT NULL, target VARCHAR(500) DEFAULT NULL,
    satuan VARCHAR(100) DEFAULT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), KEY idx_profil_indikator (id_profil), KEY idx_master_profil_indikator (id_master)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$syncProfilIndikator = static function (mysqli $db, int $idProfil, array $masterIds, string $tahun, string $fallbackProgram, string $fallbackKegiatan, string $fallbackSasaran, string $fallbackIndikator, string $fallbackTarget): void {
    $del = $db->prepare('DELETE FROM profil_risiko_indikator WHERE id_profil=?');
    $del->bind_param('i', $idProfil); $del->execute(); $del->close();
    if (!$masterIds) return;
    $get = $db->prepare('SELECT program,kegiatan,sasaran,indikator,target,satuan FROM master_indikator_kegiatan WHERE id=? AND aktif=1 LIMIT 1');
    $ins = $db->prepare('INSERT INTO profil_risiko_indikator (id_profil,id_master,tahun,program,kegiatan,sasaran,indikator,target,satuan) VALUES (?,?,?,?,?,?,?,?,?)');
    foreach ($masterIds as $masterId) {
        $masterId = (int)$masterId;
        $get->bind_param('i', $masterId); $get->execute();
        $m = $get->get_result()->fetch_assoc();
        if (!$m) continue;
        $program = $m['program'] ?: $fallbackProgram; $kegiatan = $m['kegiatan'] ?: $fallbackKegiatan;
        $sasaran = $m['sasaran'] ?: $fallbackSasaran; $indikator = $m['indikator'] ?: $fallbackIndikator;
        $target = $m['target'] ?: $fallbackTarget; $satuan = $m['satuan'] ?? '';
        $ins->bind_param('iisssssss', $idProfil,$masterId,$tahun,$program,$kegiatan,$sasaran,$indikator,$target,$satuan);
        $ins->execute();
    }
    $get->close(); $ins->close();
};

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { setFlash('error','Token tidak valid'); header('Location: '.APP_URL.'/?page=profil_risiko'); exit; }
    requireRole('Admin','Risk Manager','Pimpinan');
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'duplikasi_profil') {
        requireRole('Admin', 'Risk Manager');
        $sourceId = (int)($_POST['id_profil'] ?? 0);
        $newYear = trim($_POST['tahun_baru'] ?? date('Y'));
        $sourceStmt = $db->prepare('SELECT * FROM profil_risiko WHERE id=? LIMIT 1');
        $sourceStmt->bind_param('i', $sourceId); $sourceStmt->execute();
        $source = $sourceStmt->get_result()->fetch_assoc(); $sourceStmt->close();
        if (!$source || !ownsRecord($db, 'profil_risiko', $sourceId)) {
            setFlash('error', 'Profil sumber tidak ditemukan atau tidak dapat diakses.');
            header('Location: '.APP_URL.'/?page=profil_risiko'); exit;
        }
        $newUnit = trim((string)($source['unit_pemilik_risiko'] ?? ''));
        $exists = $db->prepare('SELECT id FROM profil_risiko WHERE tahun=? AND unit_pemilik_risiko=? AND created_by=? LIMIT 1');
        $uid = (int)$_SESSION['user_id']; $exists->bind_param('ssi', $newYear, $newUnit, $uid); $exists->execute();
        $existing = $exists->get_result()->fetch_assoc(); $exists->close();
        if ($existing) {
            setFlash('error', 'Profil untuk tahun dan unit tersebut sudah ada.');
            header('Location: '.APP_URL.'/?page=profil_risiko&id='.(int)$existing['id']); exit;
        }
        $insert = $db->prepare('INSERT INTO profil_risiko (tahun,unit_pemilik_risiko,nama_pemilik_risiko,nip_pemilik_risiko,nama_pengelola_risiko,nip_pengelola_risiko,tujuan,sasaran,indikator_kinerja,target,program,kegiatan,tgl_penilaian,periode_risiko,tgl_update,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $newDate = date('Y-m-d');
        $insert->bind_param('sssssssssssssssi', $newYear,$newUnit,$source['nama_pemilik_risiko'],$source['nip_pemilik_risiko'],$source['nama_pengelola_risiko'],$source['nip_pengelola_risiko'],$source['tujuan'],$source['sasaran'],$source['indikator_kinerja'],$source['target'],$source['program'],$source['kegiatan'],$newDate,$source['periode_risiko'],$newDate,$uid);
        $insert->execute(); $newProfileId = $db->insert_id; $insert->close();
        $copyDetails = $db->prepare('INSERT INTO profil_risiko_detail (id_profil,no_urut,unit_kerja,kode_risiko,nama_risiko,probabilitas,dampak,bobot,nilai,tingkat_risiko,prioritas_risiko,rencana_penanganan,jadwal_pelaksanaan,penanggungjawab,target_p,target_d,target_bobot,target_nilai,target_tingkat_risiko) SELECT ?,no_urut,unit_kerja,kode_risiko,nama_risiko,1,1,1,1,"Sangat Rendah",5,rencana_penanganan,jadwal_pelaksanaan,penanggungjawab,1,1,1,1,"Sangat Rendah" FROM profil_risiko_detail WHERE id_profil=?');
        $copyDetails->bind_param('ii', $newProfileId, $sourceId); $copyDetails->execute(); $copyDetails->close();
        $copyIndicators = $db->prepare('INSERT INTO profil_risiko_indikator (id_profil,id_master,tahun,program,kegiatan,sasaran,indikator,target,satuan) SELECT ?,id_master,?,program,kegiatan,sasaran,indikator,target,satuan FROM profil_risiko_indikator WHERE id_profil=?');
        if ($copyIndicators) { $copyIndicators->bind_param('isi', $newProfileId, $newYear, $sourceId); $copyIndicators->execute(); $copyIndicators->close(); }
        logAktivitas('CREATE', 'profil_risiko', $newProfileId, 'Duplikasi profil dari ID '.$sourceId.' ke tahun '.$newYear);
        setFlash('success', 'Profil berhasil disalin sebagai draft kerja baru. P/D dan target residual dikembalikan ke nilai awal.');
        header('Location: '.APP_URL.'/?page=profil_risiko&id='.$newProfileId.'&tab=detail'); exit;
    }

    if ($aksi === 'simpan_header') {
        requireRole('Admin','Risk Manager');
        $id = (int)($_POST['id'] ?? 0);
        $f  = [
            'tahun'                 => trim($_POST['tahun'] ?? date('Y')),
            'unit_pemilik_risiko'   => trim($_POST['unit_pemilik_risiko'] ?? ''),
            'nama_pemilik_risiko'   => trim($_POST['nama_pemilik_risiko'] ?? ''),
            'nip_pemilik_risiko'    => trim($_POST['nip_pemilik_risiko'] ?? ''),
            'nama_pengelola_risiko' => trim($_POST['nama_pengelola_risiko'] ?? ''),
            'nip_pengelola_risiko'  => trim($_POST['nip_pengelola_risiko'] ?? ''),
            'tujuan'                => trim($_POST['tujuan'] ?? ''),
            'sasaran'               => trim($_POST['sasaran'] ?? ''),
            'indikator_kinerja'     => trim($_POST['indikator_kinerja'] ?? ''),
            'target'                => trim($_POST['target'] ?? ''),
            'program'               => trim($_POST['program'] ?? ''),
            'kegiatan'              => trim($_POST['kegiatan'] ?? ''),
            'tgl_penilaian'         => $_POST['tgl_penilaian'] ?? null,
            'periode_risiko'        => trim($_POST['periode_risiko'] ?? ''),
            'tgl_update'            => $_POST['tgl_update'] ?? date('Y-m-d'),
            'nama_ttd_pemilik'      => trim($_POST['nama_ttd_pemilik'] ?? ''),
            'nip_ttd_pemilik'       => trim($_POST['nip_ttd_pemilik'] ?? ''),
            'nama_ttd_pengelola'    => trim($_POST['nama_ttd_pengelola'] ?? ''),
            'nip_ttd_pengelola'     => trim($_POST['nip_ttd_pengelola'] ?? ''),
            'ttd_pemilik'           => trim($_POST['ttd_pemilik'] ?? ''),
            'ttd_pengelola'         => trim($_POST['ttd_pengelola'] ?? ''),
        ];
        $masterIndicatorIds = array_values(array_filter(array_map('intval', (array)($_POST['master_indikator_ids'] ?? []))));
        // Validasi TTD via GD re-encode (anti malicious base64)
        if (!empty($f['ttd_pemilik']) && str_starts_with($f['ttd_pemilik'], 'data:image')) {
            $validated = saveTtdBase64($f['ttd_pemilik'], 'profil_pemilik');
            if (!$validated['valid']) { setFlash('error', $validated['error'] ?? 'Tanda tangan pemilik tidak valid.'); header('Location: '.APP_URL.'/?page=profil_risiko'); exit; }
            $f['ttd_pemilik'] = $validated['path'];
        }
        if (!empty($f['ttd_pengelola']) && str_starts_with($f['ttd_pengelola'], 'data:image')) {
            $validated = saveTtdBase64($f['ttd_pengelola'], 'profil_pengelola');
            if (!$validated['valid']) { setFlash('error', $validated['error'] ?? 'Tanda tangan pengelola tidak valid.'); header('Location: '.APP_URL.'/?page=profil_risiko'); exit; }
            $f['ttd_pengelola'] = $validated['path'];
        }
        $uid = (int)$_SESSION['user_id'];
        if ($id > 0) {
            if (!ownsRecord($db, 'profil_risiko', $id)) {
                setFlash('error', 'Anda tidak memiliki hak untuk mengubah profil ini.');
                header('Location: '.APP_URL.'/?page=profil_risiko'); exit;
            }
            try {
                $s = $db->prepare('UPDATE profil_risiko SET tahun=?,unit_pemilik_risiko=?,nama_pemilik_risiko=?,nip_pemilik_risiko=?,nama_pengelola_risiko=?,nip_pengelola_risiko=?,tujuan=?,sasaran=?,indikator_kinerja=?,target=?,program=?,kegiatan=?,tgl_penilaian=?,periode_risiko=?,tgl_update=?,nama_ttd_pemilik=?,nip_ttd_pemilik=?,nama_ttd_pengelola=?,nip_ttd_pengelola=?,ttd_pemilik=?,ttd_pengelola=? WHERE id=?');
                $s->bind_param('sssssssssssssssssssssi', $f['tahun'],$f['unit_pemilik_risiko'],$f['nama_pemilik_risiko'],$f['nip_pemilik_risiko'],$f['nama_pengelola_risiko'],$f['nip_pengelola_risiko'],$f['tujuan'],$f['sasaran'],$f['indikator_kinerja'],$f['target'],$f['program'],$f['kegiatan'],$f['tgl_penilaian'],$f['periode_risiko'],$f['tgl_update'],$f['nama_ttd_pemilik'],$f['nip_ttd_pemilik'],$f['nama_ttd_pengelola'],$f['nip_ttd_pengelola'],$f['ttd_pemilik'],$f['ttd_pengelola'],$id);
                 $s->execute(); $s->close();
                 $syncProfilIndikator($db, $id, $masterIndicatorIds, $f['tahun'], $f['program'], $f['kegiatan'], $f['sasaran'], $f['indikator_kinerja'], $f['target']);
                logAktivitas('UPDATE','profil_risiko',$id,'Update profil risiko '.$f['tahun']);
                setFlash('success','Profil risiko berhasil diperbarui');
                header('Location: '.APP_URL.'/?page=profil_risiko&id='.$id.'&tab=detail'); exit;
            } catch (Throwable $e) {
                error_log('[manris] profil_risiko UPDATE error: ' . $e->getMessage());
                setFlash('error','Gagal memperbarui profil risiko. Detail: ' . $e->getMessage());
                header('Location: '.APP_URL.'/?page=profil_risiko&id='.$id); exit;
            }
        } else {
            try {
                $same = $db->prepare('SELECT id FROM profil_risiko WHERE tahun=? AND unit_pemilik_risiko=? AND created_by=? LIMIT 1');
                $same->bind_param('ssi', $f['tahun'], $f['unit_pemilik_risiko'], $uid); $same->execute();
                $sameRow = $same->get_result()->fetch_assoc(); $same->close();
                if ($sameRow) {
                    setFlash('error','Profil untuk tahun dan unit tersebut sudah ada');
                    header('Location: '.APP_URL.'/?page=profil_risiko&id='.(int)$sameRow['id']); exit;
                }
                $s = $db->prepare('INSERT INTO profil_risiko (tahun,unit_pemilik_risiko,nama_pemilik_risiko,nip_pemilik_risiko,nama_pengelola_risiko,nip_pengelola_risiko,tujuan,sasaran,indikator_kinerja,target,program,kegiatan,tgl_penilaian,periode_risiko,tgl_update,nama_ttd_pemilik,nip_ttd_pemilik,nama_ttd_pengelola,nip_ttd_pengelola,ttd_pemilik,ttd_pengelola,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $s->bind_param('sssssssssssssssssssssi', $f['tahun'],$f['unit_pemilik_risiko'],$f['nama_pemilik_risiko'],$f['nip_pemilik_risiko'],$f['nama_pengelola_risiko'],$f['nip_pengelola_risiko'],$f['tujuan'],$f['sasaran'],$f['indikator_kinerja'],$f['target'],$f['program'],$f['kegiatan'],$f['tgl_penilaian'],$f['periode_risiko'],$f['tgl_update'],$f['nama_ttd_pemilik'],$f['nip_ttd_pemilik'],$f['nama_ttd_pengelola'],$f['nip_ttd_pengelola'],$f['ttd_pemilik'],$f['ttd_pengelola'],$uid);
                 $s->execute(); $newId = $db->insert_id; $s->close();
                 $syncProfilIndikator($db, $newId, $masterIndicatorIds, $f['tahun'], $f['program'], $f['kegiatan'], $f['sasaran'], $f['indikator_kinerja'], $f['target']);
                logAktivitas('CREATE','profil_risiko',$newId,'Buat profil risiko '.$f['tahun']);
                setFlash('success','Profil risiko berhasil dibuat! Tambahkan detail risiko.');
                header('Location: '.APP_URL.'/?page=profil_risiko&id='.$newId.'&tab=detail&open_detail=1'); exit;
            } catch (Throwable $e) {
                error_log('[manris] profil_risiko INSERT error: ' . $e->getMessage());
                setFlash('error','Gagal menyimpan profil risiko. Pastikan database sudah ter-install dengan schema terbaru. Detail: ' . $e->getMessage());
                header('Location: '.APP_URL.'/?page=profil_risiko'); exit;
            }
        }
    }

    if ($aksi === 'simpan_detail') {
        requireRole('Admin','Risk Manager');
        $idProfil = (int)($_POST['id_profil'] ?? 0);
        $idDetail = (int)($_POST['id_detail'] ?? 0);
        $kodeRisiko = trim($_POST['kode_risiko'] ?? '');

        // Detail profil hanya boleh berasal dari master Identifikasi Risiko.
        if ($idProfil <= 0 || $kodeRisiko === '') {
            setFlash('error', 'Profil dan kode risiko wajib diisi');
            header('Location: '.APP_URL.'/?page=profil_risiko&id='.$idProfil.'&tab=detail'); exit;
        }
        if (!ownsRecord($db, 'profil_risiko', $idProfil)) {
            setFlash('error', 'Anda tidak memiliki hak untuk mengubah detail profil ini.');
            header('Location: '.APP_URL.'/?page=profil_risiko'); exit;
        }
        $master = $db->prepare("SELECT id, deleted_at, approval_status FROM risiko WHERE kode_risiko=? AND deleted_at IS NULL AND (approval_status='approved' OR approval_status IS NULL) LIMIT 1");
        $master->bind_param('s', $kodeRisiko); $master->execute();
        $masterRow = $master->get_result()->fetch_assoc(); $master->close();
        if (!$masterRow) {
            setFlash('error', 'Kode risiko "'.$kodeRisiko.'" tidak ditemukan atau sudah dinonaktifkan di Identifikasi Risiko.');
            header('Location: '.APP_URL.'/?page=profil_risiko&id='.$idProfil.'&tab=detail'); exit;
        }
        $duplicate = $db->prepare('SELECT id FROM profil_risiko_detail WHERE id_profil=? AND kode_risiko=? AND id<>? LIMIT 1');
        $duplicate->bind_param('isi', $idProfil, $kodeRisiko, $idDetail); $duplicate->execute();
        $duplicateRow = $duplicate->get_result()->fetch_assoc(); $duplicate->close();
        if ($duplicateRow) {
            setFlash('error', 'Risiko tersebut sudah ada di profil ini');
            header('Location: '.APP_URL.'/?page=profil_risiko&id='.$idProfil.'&tab=detail'); exit;
        }
        if ($idDetail > 0) {
            $noStmt = $db->prepare('SELECT no_urut FROM profil_risiko_detail WHERE id=? AND id_profil=? LIMIT 1');
            $noStmt->bind_param('ii', $idDetail, $idProfil); $noStmt->execute();
            $noRow = $noStmt->get_result()->fetch_assoc(); $noStmt->close();
            $autoNo = (int)($noRow['no_urut'] ?? 1);
        } else {
            $noRow = $db->prepare('SELECT COALESCE(MAX(no_urut),0)+1 AS no_urut FROM profil_risiko_detail WHERE id_profil=?');
            $noRow->bind_param('i', $idProfil); $noRow->execute();
            $autoNo = (int)$noRow->get_result()->fetch_assoc()['no_urut']; $noRow->close();
        }
        $p = (int)($_POST['probabilitas'] ?? 1);
        $d = (int)($_POST['dampak'] ?? 1);
        $tp = (int)($_POST['target_p'] ?? 1);
        $td = (int)($_POST['target_d'] ?? 1);
        if (!validRiskScale($p) || !validRiskScale($d) || !validRiskScale($tp) || !validRiskScale($td)) {
            setFlash('error', 'Probabilitas dan dampak harus bernilai 1 sampai 5.');
            header('Location: '.APP_URL.'/?page=profil_risiko&id='.$idProfil.'&tab=detail'); exit;
        }
        $bobot = getBobot($p, $d);
        $nilai = (int)round($p * $d * $bobot);
        
        $tb = getBobot($tp, $td);
        $tnilai = (int)round($tp * $td * $tb);
        $tingkat = getLevelRisiko($nilai);
        $ttingkat = getLevelRisiko((int)round($tnilai));
        if ($nilai <= 4) $prioritas = 5;
        elseif ($nilai <= 9) $prioritas = 4;
        elseif ($nilai <= 14) $prioritas = 3;
        elseif ($nilai <= 19) $prioritas = 2;
        else $prioritas = 1;

        $f2 = [
            'no_urut'            => $autoNo,
            'unit_kerja'         => trim($_POST['unit_kerja'] ?? ''),
            'kode_risiko'        => $kodeRisiko,
            'nama_risiko'        => trim($_POST['nama_risiko'] ?? ''),
            'probabilitas'       => $p, 'dampak' => $d,
            'bobot'              => $bobot, 'nilai' => $nilai,
            'tingkat_risiko'     => $tingkat,
            'prioritas_risiko'   => $prioritas,
            'rencana_penanganan' => trim($_POST['rencana_penanganan'] ?? ''),
            'jadwal_pelaksanaan' => trim($_POST['jadwal_pelaksanaan'] ?? ''),
            'penanggungjawab'    => trim($_POST['penanggungjawab'] ?? ''),
            'target_p'           => $tp, 'target_d' => $td,
            'target_bobot'       => $tb, 'target_nilai' => $tnilai,
            'target_tingkat'     => $ttingkat,
        ];
        if ($idDetail > 0) {
            $s = $db->prepare('UPDATE profil_risiko_detail SET id_risiko=?,no_urut=?,unit_kerja=?,kode_risiko=?,nama_risiko=?,probabilitas=?,dampak=?,bobot=?,nilai=?,tingkat_risiko=?,prioritas_risiko=?,rencana_penanganan=?,jadwal_pelaksanaan=?,penanggungjawab=?,target_p=?,target_d=?,target_bobot=?,target_nilai=?,target_tingkat_risiko=? WHERE id=? AND id_profil=?');
            $s->bind_param(
                'iisssiiddsisssiiddsii',
                $masterRow['id'],$f2['no_urut'],$f2['unit_kerja'],$f2['kode_risiko'],$f2['nama_risiko'],
                $f2['probabilitas'],$f2['dampak'],$f2['bobot'],$f2['nilai'],
                $f2['tingkat_risiko'],$f2['prioritas_risiko'],
                $f2['rencana_penanganan'],$f2['jadwal_pelaksanaan'],$f2['penanggungjawab'],
                $f2['target_p'],$f2['target_d'],$f2['target_bobot'],$f2['target_nilai'],
                $f2['target_tingkat'],$idDetail,$idProfil
            );
        } else {
            $s = $db->prepare('INSERT INTO profil_risiko_detail (id_profil,id_risiko,no_urut,unit_kerja,kode_risiko,nama_risiko,probabilitas,dampak,bobot,nilai,tingkat_risiko,prioritas_risiko,rencana_penanganan,jadwal_pelaksanaan,penanggungjawab,target_p,target_d,target_bobot,target_nilai,target_tingkat_risiko) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $s->bind_param(
                'iiisssiiddsisssiidds',
                $idProfil,$masterRow['id'],$f2['no_urut'],$f2['unit_kerja'],$f2['kode_risiko'],$f2['nama_risiko'],
                $f2['probabilitas'],$f2['dampak'],$f2['bobot'],$f2['nilai'],
                $f2['tingkat_risiko'],$f2['prioritas_risiko'],
                $f2['rencana_penanganan'],$f2['jadwal_pelaksanaan'],$f2['penanggungjawab'],
                $f2['target_p'],$f2['target_d'],$f2['target_bobot'],$f2['target_nilai'],
                $f2['target_tingkat']
            );
        }
        if (!$s->execute()) {
            setFlash('error', 'Detail risiko gagal disimpan. Pastikan risiko belum terdaftar di profil ini.');
            $s->close();
            header('Location: '.APP_URL.'/?page=profil_risiko&id='.$idProfil.'&tab=detail'); exit;
        }
        $s->close();
        setFlash('success','Detail risiko berhasil disimpan');
        header('Location: '.APP_URL.'/?page=profil_risiko&id='.$idProfil.'&tab=detail'); exit;
    }

    if ($aksi === 'hapus_detail') {
        requireRole('Admin','Risk Manager');
        $idDetail = (int)($_POST['id_detail'] ?? 0);
        $idProfil = (int)($_POST['id_profil'] ?? 0);
        if (!ownsRecord($db, 'profil_risiko', $idProfil)) {
            setFlash('error', 'Anda tidak memiliki hak untuk menghapus detail profil ini.');
            header('Location: '.APP_URL.'/?page=profil_risiko'); exit;
        }
        $s = $db->prepare('DELETE FROM profil_risiko_detail WHERE id=? AND id_profil=?');
        $s->bind_param('ii', $idDetail, $idProfil); $s->execute(); $s->close();
        setFlash('success','Detail risiko dihapus');
        header('Location: '.APP_URL.'/?page=profil_risiko&id='.$idProfil.'&tab=detail'); exit;
    }

    if ($aksi === 'hapus_profil') {
        $id = (int)($_POST['id'] ?? 0);
        if (!hasRole('Admin', 'Pimpinan')) {
            $sCheck = $db->prepare("SELECT created_by FROM profil_risiko WHERE id=?");
            $sCheck->bind_param("i", $id); $sCheck->execute();
            $rowCheck = $sCheck->get_result()->fetch_assoc(); $sCheck->close();
            if (!$rowCheck || $rowCheck['created_by'] != $_SESSION['user_id']) {
                setFlash('error', 'Anda tidak memiliki hak untuk menghapus profil ini');
                header('Location: '.APP_URL.'/?page=profil_risiko'); exit;
            }
        }
        $s = $db->prepare('DELETE FROM profil_risiko WHERE id=?');
        $s->bind_param('i', $id); $s->execute(); $s->close();
        logAktivitas('DELETE','profil_risiko',$id,'Hapus profil risiko ID '.$id);
        setFlash('success','Profil risiko dihapus');
        header('Location: '.APP_URL.'/?page=profil_risiko'); exit;
    }

    if ($aksi === 'kirim_persetujuan') {
        requireRole('Risk Manager');
        $id = (int)($_POST['id'] ?? 0);
        $uid = (int)$_SESSION['user_id'];
        if (!ownsRecord($db, 'profil_risiko', $id)) {
            setFlash('error', 'GAGAL mengajukan: Anda tidak berhak mengajukan profil ini.');
            notifikasi($uid, 'status_change', 'Pengajuan Gagal Terkirim', 'Pengajuan profil risiko GAGAL: Anda tidak berhak mengajukan profil ini.', '/?page=profil_risiko&id='.$id);
            header('Location: '.APP_URL.'/?page=profil_risiko'); exit;
        }
        $s = $db->prepare("UPDATE profil_risiko SET status='Menunggu Persetujuan' WHERE id=?");
        $s->bind_param('i', $id); $s->execute(); $s->close();

        // Info profil untuk pesan notifikasi
        $infoQ = $db->prepare("SELECT tahun, unit_pemilik_risiko FROM profil_risiko WHERE id=?");
        $infoQ->bind_param('i', $id); $infoQ->execute();
        $info = $infoQ->get_result()->fetch_assoc(); $infoQ->close();
        $tahun = $info['tahun'] ?? '-';
        $unit  = $info['unit_pemilik_risiko'] ?? '-';
        $pengaju = trim($_SESSION['user_nama'] ?? 'Risk Manager');

        // Notifikasi ke semua Pimpinan: ada dokumen menunggu keputusan
        $pimpinanList = $db->query("SELECT id FROM users WHERE role='Pimpinan' AND aktif=1")->fetch_all(MYSQLI_ASSOC) ?: [];
        foreach ($pimpinanList as $p) {
            notifikasi((int)$p['id'], 'approval',
                'Profil Risiko Menunggu Persetujuan',
                'Profil Risiko tahun '.$tahun.' ('.$unit.') diajukan oleh '.$pengaju.' dan menunggu keputusan Anda.',
                APP_URL.'/?page=profil_risiko&id='.$id
            );
        }

        // Notifikasi ke pengaju: konfirmasi status TERKIRIM
        notifikasi($uid, 'status_change',
            'Pengajuan Profil Risiko Terkirim',
            'Profil Risiko tahun '.$tahun.' telah TERKIRIM ke Pimpinan untuk persetujuan. Menunggu keputusan.',
            '/?page=profil_risiko&id='.$id
        );

        logAktivitas('UPDATE', 'profil_risiko', $id, 'Mengajukan persetujuan profil risiko oleh '.$pengaju);
        setFlash('success', 'Pengajuan Profil Risiko TERKIRIM ke Pimpinan. Notifikasi terkirim ke '.count($pimpinanList).' Pimpinan. Menunggu keputusan.');
        header('Location: '.APP_URL.'/?page=profil_risiko&id='.$id); exit;
    }

    if ($aksi === 'approve_profil') {
        requireRole('Pimpinan');
        $id = (int)($_POST['id'] ?? 0);
        $uid = (int)$_SESSION['user_id'];
        $s = $db->prepare("UPDATE profil_risiko SET status='Disetujui', approved_by=?, approved_at=NOW() WHERE id=?");
        $s->bind_param('ii', $uid, $id); $s->execute(); $s->close();
        
        // Kirim notifikasi ke pembuat profil
        $q = $db->query("SELECT created_by, tahun FROM profil_risiko WHERE id=$id");
        if ($q && $r = $q->fetch_assoc()) {
            notifikasi((int)$r['created_by'], 'status_change', 'Profil Risiko Disetujui', 'Profil Risiko tahun '.$r['tahun'].' telah disetujui oleh Pimpinan.', APP_URL.'/?page=profil_risiko&id='.$id);
        }
        
        logAktivitas('UPDATE', 'profil_risiko', $id, 'Menyetujui profil risiko');
        setFlash('success', 'Profil Risiko berhasil disetujui.');
        header('Location: '.APP_URL.'/?page=profil_risiko&id='.$id); exit;
    }

    if ($aksi === 'reject_profil') {
        requireRole('Pimpinan');
        $id = (int)($_POST['id'] ?? 0);
        $catatan = trim($_POST['catatan_revisi'] ?? '');
        $s = $db->prepare("UPDATE profil_risiko SET status='Revisi', catatan_revisi=? WHERE id=?");
        $s->bind_param('si', $catatan, $id); $s->execute(); $s->close();
        
        // Kirim notifikasi ke pembuat profil
        $q = $db->query("SELECT created_by, tahun FROM profil_risiko WHERE id=$id");
        if ($q && $r = $q->fetch_assoc()) {
            notifikasi((int)$r['created_by'], 'status_change', 'Profil Risiko Direvisi', 'Profil Risiko tahun '.$r['tahun'].' dikembalikan oleh Pimpinan dengan catatan: '.$catatan, APP_URL.'/?page=profil_risiko&id='.$id);
        }
        
        logAktivitas('UPDATE', 'profil_risiko', $id, 'Menolak/revisi profil risiko');
        setFlash('success', 'Profil Risiko dikembalikan untuk direvisi.');
        header('Location: '.APP_URL.'/?page=profil_risiko&id='.$id); exit;
    }
}

// ── Data ──────────────────────────────────────────────────────
$activeId  = (int)($_GET['id'] ?? 0);
$activeTab = $_GET['tab'] ?? 'detail';
$fTahun    = trim((string)($_GET['tahun'] ?? ''));

$profilRow = null;
if ($activeId > 0) {
    try {
        $scope = hasRole('Admin', 'Pimpinan') ? '' : ' AND created_by = ?';
        $s = $db->prepare('SELECT * FROM profil_risiko WHERE id=?'.$scope);
        if ($scope) { $uid = (int)$_SESSION['user_id']; $s->bind_param('ii',$activeId,$uid); } else $s->bind_param('i',$activeId);
        $s->execute();
        $profilRow = $s->get_result()->fetch_assoc(); $s->close();
    } catch (Throwable $e) {
        $profilRow = null;
    }
}

$activeTahun = (string)($profilRow['tahun'] ?? ($fTahun !== '' ? $fTahun : date('Y')));
$tahunList   = getDaftarTahun($db, 'profil_risiko', [$activeTahun]);

// Cek apakah tabel profil_risiko ada & kolom tahun ada (fail-safe untuk hosting baru)
$profilList = [];
try {
    $profil_cond = hasRole('Admin', 'Pimpinan') ? "WHERE 1=1" : "WHERE p.created_by = " . (int)$_SESSION['user_id'];
    if ($fTahun !== '') {
        $profil_cond .= " AND p.tahun = '" . $db->real_escape_string($fTahun) . "'";
    }
    $profilList = $db->query("SELECT p.id, p.tahun, p.unit_pemilik_risiko, p.nama_pemilik_risiko, p.created_at, u.nama AS nama_creator, (SELECT COUNT(*) FROM profil_risiko_detail d WHERE d.id_profil = p.id) AS jml_detail FROM profil_risiko p LEFT JOIN users u ON p.created_by = u.id $profil_cond ORDER BY p.tahun DESC, p.id DESC")->fetch_all(MYSQLI_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('[manris] profil_risiko table error: ' . $e->getMessage());
    $profilList = [];
}

$detailRows  = [];
$profilChecklist = [];
$profilCompleteness = 0;
// Hanya risiko yang sudah disetujui yang dapat masuk ke penilaian profil.
$risikoCond = hasRole('Admin', 'Pimpinan') ? "" : " AND id_user_input = " . (int)$_SESSION['user_id'];
$risikoList  = $db->query("SELECT kode_risiko, nama_risiko, probabilitas, dampak_level AS dampak, approval_status FROM risiko WHERE deleted_at IS NULL AND (approval_status='approved' OR approval_status IS NULL) $risikoCond ORDER BY SUBSTRING_INDEX(kode_risiko, '.', 1) ASC, CAST(SUBSTRING_INDEX(kode_risiko, '.', -1) AS UNSIGNED) ASC, kode_risiko ASC")->fetch_all(MYSQLI_ASSOC) ?: [];
$risikoCount = count($risikoList);
$editDetail  = null;

$aktifKode = [];
foreach ($risikoList as $rk) $aktifKode[$rk['kode_risiko']] = true;

// Ambil daftar IKK master
$masterIkkList = $db->query("SELECT id,tahun,program,kegiatan,sasaran,indikator,target,satuan,unit_pemilik_risiko FROM master_indikator_kegiatan WHERE aktif=1 ORDER BY tahun DESC, indikator ASC")->fetch_all(MYSQLI_ASSOC) ?: [];
$selectedMasterIndicators = [];
if ($activeId > 0) {
    $sm = $db->prepare('SELECT id_master FROM profil_risiko_indikator WHERE id_profil=? AND id_master IS NOT NULL');
    $sm->bind_param('i', $activeId); $sm->execute();
    foreach ($sm->get_result()->fetch_all(MYSQLI_ASSOC) as $sr) $selectedMasterIndicators[] = (int)$sr['id_master'];
    $sm->close();
}

// Ambil daftar user dengan NIP untuk auto-fill
$usersWithNip = $db->query("SELECT nama, nip FROM users WHERE aktif=1 AND role != 'Admin' AND nip IS NOT NULL AND nip != ''")->fetch_all(MYSQLI_ASSOC) ?: [];

    if ($profilRow) {
        // Detail profil ditambahkan secara sadar dari master Identifikasi Risiko.
        // Tidak ada sinkronisasi otomatis agar P/D dan rencana penanganan tidak tertimpa.
        try {
            $s2 = $db->prepare("SELECT * FROM profil_risiko_detail WHERE id_profil=? ORDER BY SUBSTRING_INDEX(kode_risiko, '.', 1) ASC, CAST(SUBSTRING_INDEX(kode_risiko, '.', -1) AS UNSIGNED) ASC, kode_risiko ASC");
            $s2->bind_param('i',$activeId); $s2->execute();
            $detailRows = $s2->get_result()->fetch_all(MYSQLI_ASSOC); $s2->close();
        } catch (Throwable $e) {
            $detailRows = [];
        }

        $headerFields = ['unit_pemilik_risiko', 'nama_pemilik_risiko', 'tujuan', 'sasaran', 'indikator_kinerja', 'target', 'program', 'kegiatan'];
        $headerComplete = count(array_filter($headerFields, static fn(string $field): bool => trim((string)($profilRow[$field] ?? '')) !== ''));
        $detailTotal = count($detailRows);
        $detailScored = count(array_filter($detailRows, static fn(array $row): bool => $row['probabilitas'] !== null && $row['dampak'] !== null && $row['nilai'] !== null));
        $detailPlan = count(array_filter($detailRows, static fn(array $row): bool => trim((string)($row['rencana_penanganan'] ?? '')) !== '' && trim((string)($row['penanggungjawab'] ?? '')) !== ''));
        $detailTarget = count(array_filter($detailRows, static fn(array $row): bool => $row['target_p'] !== null && $row['target_d'] !== null));
        $missingPlanPic = [];
        foreach ($detailRows as $detailRow) {
            $missing = [];
            if (trim((string)($detailRow['rencana_penanganan'] ?? '')) === '') $missing[] = 'Uraian Pengendalian';
            if (trim((string)($detailRow['penanggungjawab'] ?? '')) === '') $missing[] = 'PIC';
            if ($missing) $missingPlanPic[] = ($detailRow['kode_risiko'] ?? $detailRow['nama_risiko'] ?? 'Risiko') . ': ' . implode(' dan ', $missing);
        }
        $profilChecklist = [
            ['Header profil lengkap', $headerComplete === count($headerFields), $headerComplete . '/' . count($headerFields) . ' field'],
            ['Indikator dan target terisi', trim((string)($profilRow['indikator_kinerja'] ?? '')) !== '' && trim((string)($profilRow['target'] ?? '')) !== '', 'Profil utama'],
            ['Detail risiko ditambahkan', $detailTotal > 0, $detailTotal . ' risiko'],
            ['Penilaian P/D lengkap', $detailTotal > 0 && $detailScored === $detailTotal, $detailScored . '/' . $detailTotal . ' dinilai'],
            ['Rencana dan PIC lengkap', $detailTotal > 0 && $detailPlan === $detailTotal, $detailPlan . '/' . $detailTotal . ' lengkap', $missingPlanPic],
            ['Target risiko lengkap', $detailTotal > 0 && $detailTarget === $detailTotal, $detailTarget . '/' . $detailTotal . ' lengkap'],
        ];
        $profilCompleteness = (int)round(count(array_filter($profilChecklist, static fn(array $item): bool => $item[1])) / count($profilChecklist) * 100);

        $editDetailId = (int)($_GET['edit_detail'] ?? 0);
        if ($editDetailId > 0) {
            try {
                $s3 = $db->prepare('SELECT * FROM profil_risiko_detail WHERE id=? AND id_profil=?');
                $s3->bind_param('ii',$editDetailId, $activeId); $s3->execute();
                $editDetail = $s3->get_result()->fetch_assoc(); $s3->close();
                $activeTab = 'detail';
            } catch (Throwable $e) {
                $editDetail = null;
            }
        }
    }

// ── Warna tingkat ─────────────────────────────────────────────
function colorTingkat(string $t): string {
    if ($t === 'Sangat Tinggi') return '#991b1b';
    if ($t === 'Tinggi') return '#c2410c';
    if ($t === 'Sedang') return '#000';
    if ($t === 'Rendah') return '#166534';
    return '#1e40af';
}
function bgTingkat(string $t): string {
    if ($t === 'Sangat Tinggi') return '#fee2e2';
    if ($t === 'Tinggi') return '#ffedd5';
    if ($t === 'Sedang') return '#FFFF00';
    if ($t === 'Rendah') return '#dcfce7';
    return '#dbeafe';
}

// ── Export Excel ──────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'excel' && $activeId > 0 && $profilRow) {
    $periode = $_GET['periode'] ?? 'tahunan';
    if (!in_array($periode, ['tahunan','tw1','tw2','tw3','tw4'], true)) $periode = 'tahunan';
    $triwulanRomawi = ['I','II','III','IV'];
    $periodeLabel = $periode === 'tahunan'
        ? 'Laporan Tahunan'
        : 'Laporan Triwulan ' . $triwulanRomawi[(int)substr($periode, 2) - 1];
    $GLOBALS['EXPORT_PERIODE'] = $periodeLabel;

    $headers = ['NO', 'UNIT KERJA PEMILIK RISIKO', 'RISIKO', 'KODE RISIKO', 'P', 'D', 'BOBOT', 'NILAI', 'TINGKAT RISIKO', 'PRIORITAS RISIKO', 'URAIAN PENGENDALIAN', 'JADWAL PELAKSANAAN', 'PENANGGUNGJAWAB', 'P (TARGET)', 'D (TARGET)', 'BOBOT (TARGET)', 'NILAI (TARGET)', 'TINGKAT RISIKO (TARGET)'];
    $excelRows = [];
    foreach ($detailRows as $i => $dr) {
        $rowNum = $i + 2;
        $excelRows[] = [
            $i + 1,
            $dr['unit_kerja'] ?? '',
            $dr['nama_risiko'] ?? '',
            $dr['kode_risiko'] ?? '',
            $dr['probabilitas'] ?? '',
            $dr['dampak'] ?? '',
            "=ARRAY_CONSTRAIN(ARRAYFORMULA(INDEX(Bobot!\$XEY\$2:\$XFC\$6, E{$rowNum}, F{$rowNum})), 1, 1)",
            $dr['nilai'] ?? '',
            $dr['tingkat_risiko'] ?? '',
            "=IF(H{$rowNum}<=4,5,IF(H{$rowNum}<=9,4,IF(H{$rowNum}<=14,3,IF(H{$rowNum}<=19,2,1))))",
            $dr['rencana_penanganan'] ?? '',
            $dr['jadwal_pelaksanaan'] ?? '',
            $dr['penanggungjawab'] ?? '',
            $dr['target_p'] ?? '',
            $dr['target_d'] ?? '',
            "=ARRAY_CONSTRAIN(ARRAYFORMULA(INDEX(Bobot!\$XEY\$2:\$XFC\$6, N{$rowNum}, O{$rowNum})), 1, 1)",
            $dr['target_nilai'] ?? '',
            $dr['target_tingkat_risiko'] ?? '',
        ];
    }
    $namaFile = 'profil_risiko_' . ($profilRow['tahun'] ?? '') . '_' . date('Ymd_His');
    exportExcel($namaFile, $headers, $excelRows);
}
?>




<script>
function duplicateProfil(event, form) {
    event.stopPropagation();
    const year = prompt('Salin profil ini ke tahun berapa?', String(new Date().getFullYear()));
    if (!year || !/^\d{4}$/.test(year)) return false;
    form.querySelector('input[name="tahun_baru"]').value = year;
    return confirm('Buat salinan Profil Risiko untuk tahun ' + year + '?');
}
function bukaTabDetailProfil() {
    const button = document.querySelector('.tab-btn[onclick*="tabDetail"]');
    if (button) button.click();
    else window.location.hash = 'tabDetail';
    document.getElementById('tabDetail')?.scrollIntoView({behavior:'smooth', block:'start'});
}

// Draft lokal profil: tidak masuk database sampai user menekan Simpan.
(function () {
    const form = document.getElementById('formProfilHeader');
    if (!form) return;
    const draftKey = 'manrisv2:profil-header:' + (form.querySelector('input[name="id"]')?.value || 'new');
    const fields = Array.from(form.querySelectorAll('input:not([type="hidden"]), textarea, select'));
    const status = document.createElement('small');
    status.style.cssText = 'display:block;color:var(--text-muted);font-size:.72rem;margin-top:6px';
    status.textContent = 'Draft tersimpan otomatis di perangkat ini';
    form.querySelector('.modal-body')?.prepend(status);
    const saveDraft = () => {
        const data = {};
        fields.forEach(field => { if (field.name && field.type !== 'file') data[field.name] = field.multiple ? Array.from(field.selectedOptions).map(o => o.value) : field.value; });
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
            if (Date.now() - Number(draft.savedAt || 0) < 7 * 24 * 60 * 60 * 1000 && draft.data && confirm('Ada draft Profil Risiko tersimpan. Pulihkan sekarang?')) {
                fields.forEach(field => { if (!(field.name in draft.data)) return; if (field.multiple) Array.from(field.options).forEach(o => o.selected = draft.data[field.name].includes(o.value)); else field.value = draft.data[field.name]; });
            }
        }
    } catch (e) {}
    form.addEventListener('submit', () => localStorage.removeItem(draftKey));
})();

function autofillNip(inputEl, nipFieldName, scopeSelector) {
    const list = document.getElementById('listUsersWithNip');
    const options = list.options;
    let foundNip = '';
    for(let i=0; i<options.length; i++) {
        if(options[i].value === inputEl.value) {
            foundNip = options[i].getAttribute('data-nip');
            break;
        }
    }
    if(foundNip) {
        const nipEl = document.querySelector(scopeSelector + ' input[name="'+nipFieldName+'"]');
        if(nipEl) nipEl.value = foundNip;
    }
}
function appendMasterIndicators(selectEl, indicatorSelector, targetSelector) {
    const indicators = Array.from(selectEl.selectedOptions)
        .filter(option => option.value)
        .map((option, index) => (index + 1) + '. ' + (option.dataset.indikator || ''))
        .filter(Boolean);
    const targets = Array.from(selectEl.selectedOptions)
        .filter(option => option.value)
        .map((option, index) => (index + 1) + '. ' + (option.dataset.target || ''))
        .filter(Boolean);
    const indicatorEl = document.querySelector(indicatorSelector);
    const targetEl = document.querySelector(targetSelector);
    if (indicatorEl && indicators.length) indicatorEl.value = indicators.join('\n');
    else if (indicatorEl) indicatorEl.value = '';
    
    if (targetEl && targets.length) targetEl.value = targets.join('\n');
    else if (targetEl) targetEl.value = '';
}

document.addEventListener('DOMContentLoaded', function() {
    // Membuat select multiple bisa ditoggle tanpa menahan Ctrl
    document.querySelectorAll('select[multiple]').forEach(select => {
        select.addEventListener('mousedown', function(e) {
            e.preventDefault();
            const option = e.target;
            if (option.tagName === 'OPTION') {
                option.selected = !option.selected;
                select.dispatchEvent(new Event('change'));
            }
            select.focus();
        });
    });
    if ($profilRow) {
        // Detail profil ditambahkan secara sadar dari master Identifikasi Risiko.
        // Tidak ada sinkronisasi otomatis agar P/D dan rencana penanganan tidak tertimpa.
        try {
            $s2 = $db->prepare("SELECT * FROM profil_risiko_detail WHERE id_profil=? ORDER BY SUBSTRING_INDEX(kode_risiko, '.', 1) ASC, CAST(SUBSTRING_INDEX(kode_risiko, '.', -1) AS UNSIGNED) ASC, kode_risiko ASC");
            $s2->bind_param('i',$activeId); $s2->execute();
            $detailRows = $s2->get_result()->fetch_all(MYSQLI_ASSOC); $s2->close();
        } catch (Throwable $e) {
            $detailRows = [];
        }

        $headerFields = ['unit_pemilik_risiko', 'nama_pemilik_risiko', 'tujuan', 'sasaran', 'indikator_kinerja', 'target', 'program', 'kegiatan'];
        $headerComplete = count(array_filter($headerFields, static fn(string $field): bool => trim((string)($profilRow[$field] ?? '')) !== ''));
        $detailTotal = count($detailRows);
        $detailScored = count(array_filter($detailRows, static fn(array $row): bool => $row['probabilitas'] !== null && $row['dampak'] !== null && $row['nilai'] !== null));
        $detailPlan = count(array_filter($detailRows, static fn(array $row): bool => trim((string)($row['rencana_penanganan'] ?? '')) !== '' && trim((string)($row['penanggungjawab'] ?? '')) !== ''));
        $detailTarget = count(array_filter($detailRows, static fn(array $row): bool => $row['target_p'] !== null && $row['target_d'] !== null));
        $missingPlanPic = [];
        foreach ($detailRows as $detailRow) {
            $missing = [];
            if (trim((string)($detailRow['rencana_penanganan'] ?? '')) === '') $missing[] = 'Uraian Pengendalian';
            if (trim((string)($detailRow['penanggungjawab'] ?? '')) === '') $missing[] = 'PIC';
            if ($missing) $missingPlanPic[] = ($detailRow['kode_risiko'] ?? $detailRow['nama_risiko'] ?? 'Risiko') . ': ' . implode(' dan ', $missing);
        }
        $profilChecklist = [
            ['Header profil lengkap', $headerComplete === count($headerFields), $headerComplete . '/' . count($headerFields) . ' field'],
            ['Indikator dan target terisi', trim((string)($profilRow['indikator_kinerja'] ?? '')) !== '' && trim((string)($profilRow['target'] ?? '')) !== '', 'Profil utama'],
            ['Detail risiko ditambahkan', $detailTotal > 0, $detailTotal . ' risiko'],
            ['Penilaian P/D lengkap', $detailTotal > 0 && $detailScored === $detailTotal, $detailScored . '/' . $detailTotal . ' dinilai'],
            ['Rencana dan PIC lengkap', $detailTotal > 0 && $detailPlan === $detailTotal, $detailPlan . '/' . $detailTotal . ' lengkap', $missingPlanPic],
            ['Target risiko lengkap', $detailTotal > 0 && $detailTarget === $detailTotal, $detailTarget . '/' . $detailTotal . ' lengkap'],
        ];
        $profilCompleteness = (int)round(count(array_filter($profilChecklist, static fn(array $item): bool => $item[1])) / count($profilChecklist) * 100);

        $editDetailId = (int)($_GET['edit_detail'] ?? 0);
        if ($editDetailId > 0) {
            try {
                $s3 = $db->prepare('SELECT * FROM profil_risiko_detail WHERE id=? AND id_profil=?');
                $s3->bind_param('ii',$editDetailId, $activeId); $s3->execute();
                $editDetail = $s3->get_result()->fetch_assoc(); $s3->close();
                $activeTab = 'detail';
            } catch (Throwable $e) {
                $editDetail = null;
            }
        }
    }

// ── Warna tingkat ─────────────────────────────────────────────
function colorTingkat(string $t): string {
    return match($t) {
        'Sangat Tinggi' => '#991b1b', // badge-danger
        'Tinggi'        => '#c2410c', // badge-orange
        'Sedang'        => '#000', // text hitam untuk bg kuning
        'Rendah'        => '#166534', // badge-success
        default         => '#1e40af', // badge-info
    };
}
function bgTingkat(string $t): string {
    return match($t) {
        'Sangat Tinggi' => '#fee2e2',
        'Tinggi'        => '#ffedd5',
        'Sedang'        => '#FFFF00', // kuning terang background
        'Rendah'        => '#dcfce7',
        default         => '#dbeafe',
    };
}

// ── Export Excel ──────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'excel' && $activeId > 0 && $profilRow) {
    $periode = $_GET['periode'] ?? 'tahunan';
    if (!in_array($periode, ['tahunan','tw1','tw2','tw3','tw4'], true)) $periode = 'tahunan';
    $triwulanRomawi = ['I','II','III','IV'];
    $periodeLabel = $periode === 'tahunan'
        ? 'Laporan Tahunan'
        : 'Laporan Triwulan ' . $triwulanRomawi[(int)substr($periode, 2) - 1];
    $GLOBALS['EXPORT_PERIODE'] = $periodeLabel;

    $headers = ['NO', 'UNIT KERJA PEMILIK RISIKO', 'RISIKO', 'KODE RISIKO', 'P', 'D', 'BOBOT', 'NILAI', 'TINGKAT RISIKO', 'PRIORITAS RISIKO', 'URAIAN PENGENDALIAN', 'JADWAL PELAKSANAAN', 'PENANGGUNGJAWAB', 'P (TARGET)', 'D (TARGET)', 'BOBOT (TARGET)', 'NILAI (TARGET)', 'TINGKAT RISIKO (TARGET)'];
    $excelRows = [];
    foreach ($detailRows as $i => $dr) {
        $rowNum = $i + 2;
        $excelRows[] = [
            $i + 1,
            $dr['unit_kerja'] ?? '',
            $dr['nama_risiko'] ?? '',
            $dr['kode_risiko'] ?? '',
            $dr['probabilitas'] ?? '',
            $dr['dampak'] ?? '',
            "=ARRAY_CONSTRAIN(ARRAYFORMULA(INDEX(Bobot!\$XEY\$2:\$XFC\$6, E{$rowNum}, F{$rowNum})), 1, 1)",
            $dr['nilai'] ?? '',
            $dr['tingkat_risiko'] ?? '',
            "=IF(H{$rowNum}<=4,5,IF(H{$rowNum}<=9,4,IF(H{$rowNum}<=14,3,IF(H{$rowNum}<=19,2,1))))",
            $dr['rencana_penanganan'] ?? '',
            $dr['jadwal_pelaksanaan'] ?? '',
            $dr['penanggungjawab'] ?? '',
            $dr['target_p'] ?? '',
            $dr['target_d'] ?? '',
            "=ARRAY_CONSTRAIN(ARRAYFORMULA(INDEX(Bobot!\$XEY\$2:\$XFC\$6, N{$rowNum}, O{$rowNum})), 1, 1)",
            $dr['target_nilai'] ?? '',
            $dr['target_tingkat_risiko'] ?? '',
        ];
    }
    $namaFile = 'profil_risiko_' . ($profilRow['tahun'] ?? '') . '_' . date('Ymd_His');
    exportExcel($namaFile, $headers, $excelRows);
}
?>




<script>
function duplicateProfil(event, form) {
    event.stopPropagation();
    const year = prompt('Salin profil ini ke tahun berapa?', String(new Date().getFullYear()));
    if (!year || !/^\d{4}$/.test(year)) return false;
    form.querySelector('input[name="tahun_baru"]').value = year;
    return confirm('Buat salinan Profil Risiko untuk tahun ' + year + '?');
}
function bukaTabDetailProfil() {
    const button = document.querySelector('.tab-btn[onclick*="tabDetail"]');
    if (button) button.click();
    else window.location.hash = 'tabDetail';
    document.getElementById('tabDetail')?.scrollIntoView({behavior:'smooth', block:'start'});
}

// Draft lokal profil: tidak masuk database sampai user menekan Simpan.
(function () {
    const form = document.getElementById('formProfilHeader');
    if (!form) return;
    const draftKey = 'manrisv2:profil-header:' + (form.querySelector('input[name="id"]')?.value || 'new');
    const fields = Array.from(form.querySelectorAll('input:not([type="hidden"]), textarea, select'));
    const status = document.createElement('small');
    status.style.cssText = 'display:block;color:var(--text-muted);font-size:.72rem;margin-top:6px';
    status.textContent = 'Draft tersimpan otomatis di perangkat ini';
    form.querySelector('.modal-body')?.prepend(status);
    const saveDraft = () => {
        const data = {};
        fields.forEach(field => { if (field.name && field.type !== 'file') data[field.name] = field.multiple ? Array.from(field.selectedOptions).map(o => o.value) : field.value; });
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
            if (Date.now() - Number(draft.savedAt || 0) < 7 * 24 * 60 * 60 * 1000 && draft.data && confirm('Ada draft Profil Risiko tersimpan. Pulihkan sekarang?')) {
                fields.forEach(field => { if (!(field.name in draft.data)) return; if (field.multiple) Array.from(field.options).forEach(o => o.selected = draft.data[field.name].includes(o.value)); else field.value = draft.data[field.name]; });
            }
        }
    } catch (e) {}
    form.addEventListener('submit', () => localStorage.removeItem(draftKey));
})();

function autofillNip(inputEl, nipFieldName, scopeSelector) {
    const list = document.getElementById('listUsersWithNip');
    const options = list.options;
    let foundNip = '';
    for(let i=0; i<options.length; i++) {
        if(options[i].value === inputEl.value) {
            foundNip = options[i].getAttribute('data-nip');
            break;
        }
    }
    if(foundNip) {
        const nipEl = document.querySelector(scopeSelector + ' input[name="'+nipFieldName+'"]');
        if(nipEl) nipEl.value = foundNip;
    }
}
function appendMasterIndicators(selectEl, indicatorSelector, targetSelector) {
    const indicators = Array.from(selectEl.selectedOptions)
        .filter(option => option.value)
        .map((option, index) => (index + 1) + '. ' + (option.dataset.indikator || ''))
        .filter(Boolean);
    const targets = Array.from(selectEl.selectedOptions)
        .filter(option => option.value)
        .map((option, index) => (index + 1) + '. ' + (option.dataset.target || ''))
        .filter(Boolean);
    const indicatorEl = document.querySelector(indicatorSelector);
    const targetEl = document.querySelector(targetSelector);
    if (indicatorEl && indicators.length) indicatorEl.value = indicators.join('\n');
    else if (indicatorEl) indicatorEl.value = '';
    
    if (targetEl && targets.length) targetEl.value = targets.join('\n');
    else if (targetEl) targetEl.value = '';
}

document.addEventListener('DOMContentLoaded', function() {
    // Membuat select multiple bisa ditoggle tanpa menahan Ctrl
    document.querySelectorAll('select[multiple]').forEach(select => {
        select.addEventListener('mousedown', function(e) {
            e.preventDefault();
            const option = e.target;
            if (option.tagName === 'OPTION') {
                option.selected = !option.selected;
                select.dispatchEvent(new Event('change'));
            }
            select.focus();
        });
    });
});
</script>

<?php
$prTotalProfil = count($profilList);
$prRisikoDiinput = 0;
$prTinggi = 0;
if ($prTotalProfil > 0) {
    $pIds = implode(',', array_column($profilList, 'id'));
    $statQ = $db->query("SELECT COUNT(*) as tot, SUM(IF(tingkat_risiko IN ('Tinggi','Sangat Tinggi'), 1, 0)) as th FROM profil_risiko_detail WHERE id_profil IN ($pIds)");
    if ($statQ) {
        $statRes = $statQ->fetch_assoc();
        $prRisikoDiinput = (int)$statRes['tot'];
        $prTinggi = (int)$statRes['th'];
    }
}
$profilStatus = trim($profilRow['status'] ?? '');
if ($profilStatus === '') {
    $profilStatus = 'Draft';
}
$profilStatusClass = 'badge-info';
if ($profilStatus === 'Menunggu Persetujuan') $profilStatusClass = 'badge-warning';
elseif ($profilStatus === 'Disetujui') $profilStatusClass = 'badge-success';
elseif ($profilStatus === 'Revisi') $profilStatusClass = 'badge-danger';
?>
<div class="risiko-hero profil-risiko-hero" style="background:linear-gradient(115deg, #115e59 0%, #0f766e 55%, #0d9488 100%); align-items: flex-start !important;">
  <div class="risiko-hero-copy">
    <div class="risiko-eyebrow"><i class="fas fa-file-signature"></i> Tahap 2 dari 3</div>
    <h1 class="page-title">Profil Risiko Unit</h1>
    <?php if($activeId && $profilRow): ?>
    <div style="margin-top:-4px; margin-bottom:8px;">
      <span class="badge <?= $profilStatusClass ?>" style="font-size:.72rem;padding:4px 12px;"><i class="fas fa-circle-check"></i> <?= xss($profilStatus) ?></span>
    </div>
    <?php endif; ?>
    <p class="page-sub">Pilih risiko master, isi P/D, bobot otomatis, rencana penanganan, PIC, jadwal, target residual.</p>
    
    <?php if($activeId && $profilRow): ?>
    <div style="margin-top:12px; display:flex; gap:8px;">
      <?php if (hasRole('Risk Manager') && in_array($profilStatus, ['Draft', 'Revisi'])): ?>
           <form method="post" style="display:inline; margin:0;" onsubmit="return confirm('Ajukan Profil Risiko ini untuk persetujuan Pimpinan?\n\nSetelah diajukan, data tidak dapat diubah sampai Pimpinan memberikan keputusan (Disetujui/Revisi).');">
               <?= csrfField() ?>
               <input type="hidden" name="aksi" value="kirim_persetujuan">
               <input type="hidden" name="id" value="<?= $activeId ?>">
               <button type="submit" class="btn btn-hero-primary" style="padding:6px 14px; font-size:12px;"><i class="fas fa-paper-plane"></i> Ajukan Persetujuan</button>
           </form>
      <?php endif; ?>
      <?php if (hasRole('Pimpinan') && $profilStatus === 'Menunggu Persetujuan'): ?>
          <form method="post" style="display:inline; margin:0;" onsubmit="return confirm('Setujui Profil Risiko ini?');">
              <?= csrfField() ?>
              <input type="hidden" name="aksi" value="approve_profil">
              <input type="hidden" name="id" value="<?= $activeId ?>">
              <button type="submit" class="btn btn-hero-primary" style="background:var(--success); border-color:var(--success); padding:6px 14px; font-size:12px;"><i class="fas fa-check"></i> Setujui</button>
          </form>
          <button type="button" class="btn btn-hero-primary" style="background:var(--danger); border-color:var(--danger); padding:6px 14px; font-size:12px;" onclick="revisiProfil(<?= (int)$activeId ?>)"><i class="fas fa-times"></i> Revisi</button>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div>
  <div class="profil-hero-tools risiko-hero-tools-align">
    <select class="form-control hero-year-select" style="max-width:130px;width:auto;text-align:center;text-align-last:center;" onchange="if(this.value) window.location.href='<?= APP_URL ?>/?page=profil_risiko&tahun='+encodeURIComponent(this.value)" aria-label="Pilih tahun">
       <?php foreach($tahunList as $y): ?>
       <option value="<?= xss($y) ?>" <?= (string)$y === (string)$activeTahun ? 'selected' : '' ?> style="text-align:center;"><?= xss($y) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if($activeId && $profilRow): ?>
    <div class="risiko-export-actions" style="margin-top:0; display:flex; align-items:center; gap:8px;">
      <a href="<?= APP_URL ?>/?page=profil_risiko&id=<?= $activeId ?>&export=excel" class="btn btn-hero-ghost"><i class="fas fa-file-excel"></i> Excel</a>

      <select class="form-control hero-year-select" style="width: 155px !important; max-width: 155px !important; padding: 0 24px 0 14px !important; text-align-last: center !important;" onchange="if(this.value){window.open('<?= APP_URL ?>/?page=profil_risiko&id=<?= $activeId ?>&export=pdf&periode='+encodeURIComponent(this.value),'_blank');this.selectedIndex=0;}" aria-label="Cetak Laporan" title="Cetak Laporan per triwulan / tahunan / bulanan">
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
    <?php $isLocked = isset($profilRow) && in_array($profilRow['status'] ?? '', ['Menunggu Persetujuan', 'Disetujui']); ?>
    <?php if((!$activeId || !$profilRow) && hasRole('Admin','Risk Manager')): ?>
     <button id="btnProfilBaruHeader" class="btn btn-hero-primary btn-standard-action" onclick="openModal('modalNewProfil')" style="margin:0;padding:8px 14px;white-space:nowrap;"><i class="fas fa-plus"></i>Tambah Profil</button>
    <?php endif; ?>
    </div>
  </div>

  <!-- Stat cards (glassmorphism inside hero) -->
  <div class="stats-grid cols-3" style="width:100%;margin-top:20px;margin-bottom:0">
    <a href="<?= APP_URL ?>/?page=profil_risiko" class="stat-card stat-card-glass" style="--ga:#60a5fa;--ga-tint:rgba(96,165,250,.3);--ga-line:rgba(96,165,250,.45);--ga-glow:rgba(96,165,250,.3);text-decoration:none">
      <div class="stat-icon"><i class="fas fa-folder-open"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $prTotalProfil ?></div>
        <div class="stat-label">Total Profil</div>
      </div>
    </a>
    <a href="<?= APP_URL ?>/?page=profil_risiko<?= $activeId ? '&id='.$activeId.'#tab-detail' : '' ?>" class="stat-card stat-card-glass" style="--ga:#a78bfa;--ga-tint:rgba(167,139,250,.3);--ga-line:rgba(167,139,250,.45);--ga-glow:rgba(167,139,250,.3);text-decoration:none">
      <div class="stat-icon"><i class="fas fa-list-check"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $prRisikoDiinput ?></div>
        <div class="stat-label">Risiko Diinput</div>
      </div>
    </a>
    <a href="<?= APP_URL ?>/?page=profil_risiko<?= $activeId ? '&id='.$activeId.'#tab-detail' : '' ?>" class="stat-card stat-card-glass" style="--ga:#f87171;--ga-tint:rgba(248,113,113,.28);--ga-line:rgba(248,113,113,.5);--ga-glow:rgba(248,113,113,.32);text-decoration:none">
      <div class="stat-icon"><i class="fas fa-fire"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $prTinggi ?></div>
        <div class="stat-label">Risiko Tinggi+</div>
      </div>
    </a>
  </div>
</div>

<div class="risiko-flow">
  <a href="<?= APP_URL ?>/?page=risiko" class="risiko-flow-item"><span class="rf-num">1</span><div><strong>Identifikasi</strong><small>Master risiko</small></div></a>
  <div class="risiko-flow-item is-current"><span class="rf-num">2</span><div><strong>Profil Risiko</strong><small>Penilaian & rencana</small></div></div>
  <a href="<?= APP_URL ?>/?page=kkpr" class="risiko-flow-item"><span class="rf-num">3</span><div><strong>KKPR</strong><small>Dokumen kerja</small></div></a>
  <a href="<?= APP_URL ?>/?page=kkpmr" class="risiko-flow-item"><span class="rf-num">4</span><div><strong>KKPMR</strong><small>Pemantauan & reviu</small></div></a>
  <a href="<?= APP_URL ?>/?page=kkpr" class="risiko-flow-btn"><i class="fas fa-right-long"></i> Lanjut ke KKPR</a>
</div>
<!-- Konten Utama -->
<div style="min-width:0; width: 100%;">
<?php if(!$activeId || !$profilRow): ?>
  <?php if(empty($profilList)): ?>
  <div class="card">
    <div class="card-body">
      <div class="empty-state" style="padding:60px">
        <i class="fas fa-shield-alt" style="font-size:3rem;color:var(--primary);opacity:.3;margin-bottom:16px;display:block"></i>
        <h3>Belum Ada Profil Risiko</h3>
        <p style="margin-top:8px; margin-bottom: 24px; color:var(--text-muted)">Sistem belum memiliki data profil risiko. Silakan buat profil pertama Anda.</p>
        <?php if(hasRole('Admin','Risk Manager')): ?>
        <button class="btn btn-primary" onclick="openModal('modalNewProfil')"><i class="fas fa-plus"></i> Buat Profil Baru</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php else: ?>
  <!-- Grid Profil Risiko -->
  <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(320px, 1fr));gap:24px;margin-bottom:24px">
    <?php foreach($profilList as $pl): ?>
    <div class="card" style="cursor:pointer;transition:all .25s ease" 
         onclick="window.location.href='<?= APP_URL ?>/?page=profil_risiko&id=<?= $pl['id'] ?>'" 
         onmouseover="this.style.transform='translateY(-6px)';this.style.boxShadow='0 15px 30px rgba(0,0,0,0.1)'" 
         onmouseout="this.style.transform='none';this.style.boxShadow='var(--shadow)'">
      <div class="card-body" style="padding:24px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px">
          <div style="width:52px;height:52px;border-radius:14px;background:var(--primary-glow);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:1.5rem">
            <i class="fas fa-shield-alt"></i>
          </div>
          <div style="background:var(--surface2);padding:5px 12px;border-radius:20px;font-size:.78rem;font-weight:700;color:var(--text)">
            Tahun <?= xss($pl['tahun']) ?>
          </div>
        </div>
        <?php if((int)($pl['jml_detail'] ?? 0) === 0): ?>
        <div style="background:#fef9c3;border:1px solid #fde047;color:#854d0e;padding:6px 12px;border-radius:8px;font-size:.75rem;font-weight:700;margin-bottom:12px;display:flex;align-items:center;gap:6px">
          <i class="fas fa-exclamation-circle"></i> Belum lengkap — detail risiko belum diisi (0 risiko)
        </div>
        <?php else: ?>
        <div style="background:#dcfce7;border:1px solid #bbf7d0;color:#166534;padding:6px 12px;border-radius:8px;font-size:.75rem;font-weight:700;margin-bottom:12px;display:flex;align-items:center;gap:6px">
          <i class="fas fa-check-circle"></i> <?= (int)$pl['jml_detail'] ?> risiko dinilai
        </div>
        <?php endif; ?>
        <h3 style="font-size:1.15rem;font-weight:700;margin-bottom:8px;color:var(--text);line-height:1.4">
          <?= xss(mb_substr($pl['unit_pemilik_risiko']??'Unit Belum Ditentukan',0,50)) ?><?= mb_strlen($pl['unit_pemilik_risiko']??'')>50?'...':'' ?>
        </h3>
        <div style="font-size:.85rem;color:var(--text-muted);margin-bottom:24px;display:flex;flex-direction:column;gap:6px">
          <div style="display:flex;align-items:center;gap:8px">
            <i class="fas fa-user-tie" style="opacity:.6"></i> <span><?= xss($pl['nama_pemilik_risiko']?:'Pemilik Belum Ditentukan') ?></span>
          </div>
          <div style="display:flex;align-items:center;gap:8px;font-size:.75rem">
            <i class="fas fa-user-edit" style="opacity:.6"></i> <span>Dibuat oleh: <?= xss($pl['nama_creator'] ?? 'Sistem') ?></span>
          </div>
        </div>
         <div style="display:flex;gap:8px">
           <button class="btn btn-outline" style="flex:1;justify-content:center;font-weight:600">Buka Profil <i class="fas fa-arrow-right" style="margin-left:8px;font-size:.8rem"></i></button>
           <?php if(hasRole('Admin','Risk Manager')): ?>
           <form method="post" style="margin:0" onsubmit="return duplicateProfil(event, this)">
             <?= csrfField() ?><input type="hidden" name="aksi" value="duplikasi_profil"><input type="hidden" name="id_profil" value="<?= $pl['id'] ?>"><input type="hidden" name="tahun_baru" value="">
             <button type="submit" class="btn btn-accent" title="Salin ke periode baru"><i class="fas fa-copy"></i></button>
           </form>
           <?php endif; ?>
         </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
<?php else: ?>

<!-- Tabs Profil Risiko -->
<div class="tabs" style="margin-bottom:16px;">
  <button class="tab-btn <?= $activeTab==='detail'?'active':'' ?>" data-tab="tabDetail" onclick="switchTabProfil('tabDetail',this)"><i class="fas fa-table"></i> 1. Detail &amp; Penilaian Risiko <span style="background:var(--accent);color:#fff;border-radius:12px;padding:2px 8px;font-size:.72rem;margin-left:6px;font-weight:700"><?= count($detailRows) ?></span></button>
  <button class="tab-btn <?= $activeTab==='header'?'active':'' ?>" data-tab="tabHeader" onclick="switchTabProfil('tabHeader',this)"><i class="fas fa-info-circle"></i> 2. Info Sasaran &amp; Dokumen (Header)</button>
</div>

<!-- Tab: Header — layout card modern -->
<div id="tabHeader" class="tab-content <?= $activeTab==='header'?'active':'' ?>">
  <div class="card" style="margin-bottom:14px;border-left:4px solid <?= $profilCompleteness === 100 ? 'var(--success)' : 'var(--accent)' ?>">
    <div class="card-body" style="padding:14px 18px">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:10px">
        <strong><i class="fas fa-clipboard-check" style="color:var(--accent)"></i> Kelengkapan Profil</strong>
        <strong style="color:<?= $profilCompleteness === 100 ? 'var(--success)' : 'var(--accent)' ?>"><?= $profilCompleteness ?>%</strong>
      </div>
      <div style="height:8px;background:var(--surface2);border-radius:10px;overflow:hidden;margin-bottom:12px"><div style="height:100%;width:<?= $profilCompleteness ?>%;background:<?= $profilCompleteness === 100 ? 'var(--success)' : 'var(--accent)' ?>;border-radius:10px"></div></div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:7px">
        <?php foreach ($profilChecklist as $check): [$label, $complete, $detail] = $check; ?>
        <div style="font-size:.78rem;color:<?= $complete ? 'var(--success)' : 'var(--text-muted)' ?>"><i class="fas <?= $complete ? 'fa-circle-check' : 'fa-circle' ?>" style="margin-right:5px"></i><?= xss($label) ?> <small>(<?= xss($detail) ?>)</small><?php if (!$complete && $label === 'Rencana dan PIC lengkap'): ?> <button type="button" class="btn btn-xs btn-outline" style="margin-left:6px;padding:2px 7px;font-size:.68rem" onclick="bukaTabDetailProfil()">Isi di Detail Risiko</button><?php if (!empty($check[3])): ?><div style="margin:5px 0 0 22px;color:var(--danger);font-size:.7rem"><?= nl2br(xss(implode("\n", $check[3]))) ?></div><?php endif; ?><?php endif; ?></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="card">
    <div class="card-header" style="background:linear-gradient(135deg,var(--primary),var(--primary-light));border:none">
      <div>
        <span class="card-title" style="color:#fff;font-size:1rem"><i class="fas fa-shield-alt"></i> Profil Risiko — Tahun <?= xss($profilRow['tahun']) ?></span>
        <div style="color:rgba(255,255,255,.65);font-size:.78rem;margin-top:2px"><?= xss($profilRow['unit_pemilik_risiko']??'') ?></div>
      </div>
      <?php if(hasRole('Admin','Risk Manager')): ?>
      <button class="btn btn-sm" style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3)" onclick="openModal('modalEditHeader')"><i class="fas fa-edit"></i> Edit</button>
      <?php endif; ?>
    </div>
    <div class="card-body" style="padding:0">

      <!-- Seksi info dalam grid 2 kolom -->
      <div style="display:grid;grid-template-columns:1fr 1fr;border-bottom:1px solid var(--border)">
        <!-- Kiri: Program & Sasaran -->
        <div style="padding:16px 20px;border-right:1px solid var(--border)">
          <div style="font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--primary);margin-bottom:10px"><i class="fas fa-bullseye"></i> Sasaran & Program</div>
          <?php
          $infoKiri = [
            ['Tujuan',               $profilRow['tujuan'],           'fa-crosshairs'],
            ['Sasaran',              $profilRow['sasaran'],          'fa-flag'],
            ['Indikator Kinerja Kegiatan',    $profilRow['indikator_kinerja'],'fa-chart-line'],
            ['Target',               $profilRow['target'],           'fa-bullseye'],
            ['Program',              $profilRow['program'],          'fa-sitemap'],
            ['Kegiatan',             $profilRow['kegiatan'],         'fa-tasks'],
          ];
          foreach($infoKiri as [$label,$val,$icon]):
          ?>
          <div style="display:flex;gap:10px;margin-bottom:8px;align-items:flex-start">
            <div style="width:28px;height:28px;border-radius:6px;background:var(--primary-glow);display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <i class="fas <?= $icon ?>" style="color:var(--primary);font-size:.75rem"></i>
            </div>
            <div style="flex:1">
              <div style="font-size:.72rem;color:var(--text-muted);font-weight:600"><?= $label ?></div>
              <div style="font-size:.83rem;color:var(--text);margin-top:1px"><?= nl2br(xss($val?:'-')) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Kanan: Unit & Penanggungjawab -->
        <div style="padding:16px 20px">
          <div style="font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--accent);margin-bottom:10px"><i class="fas fa-users"></i> Unit & Penanggungjawab</div>
          <?php
          $infoKanan = [
            ['Unit Pemilik Risiko',      $profilRow['unit_pemilik_risiko'],   'fa-building'],
            ['Nama Pemilik Risiko',      $profilRow['nama_pemilik_risiko'],   'fa-user-tie'],
            ['NIP Pemilik Risiko',       $profilRow['nip_pemilik_risiko'] ?? '', 'fa-id-badge'],
            ['Nama Pengelola Risiko',    $profilRow['nama_pengelola_risiko'], 'fa-users-cog'],
            ['NIP Pengelola Risiko',     $profilRow['nip_pengelola_risiko'] ?? '', 'fa-id-card'],
            ['Tgl Penilaian Risiko',     tglIndo($profilRow['tgl_penilaian']??''), 'fa-calendar-check'],
            ['Periode Risiko',           $profilRow['periode_risiko'],        'fa-calendar-alt'],
            ['Tgl Update Risiko',        tglIndo($profilRow['tgl_update']??''), 'fa-sync-alt'],
          ];
          foreach($infoKanan as [$label,$val,$icon]):
          ?>
          <div style="display:flex;gap:10px;margin-bottom:8px;align-items:flex-start">
            <div style="width:28px;height:28px;border-radius:6px;background:rgba(59,130,246,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <i class="fas <?= $icon ?>" style="color:var(--accent);font-size:.75rem"></i>
            </div>
            <div style="flex:1">
              <div style="font-size:.72rem;color:var(--text-muted);font-weight:600"><?= $label ?></div>
              <div style="font-size:.83rem;color:var(--text);margin-top:1px;font-weight:<?= in_array($label,['Nama Pemilik Risiko','Nama Pengelola Risiko'])?'600':'400' ?>"><?= xss($val?:'-') ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- TTD Area -->
      <div style="display:grid;grid-template-columns:1fr 1fr;background:var(--surface2)">
        <?php foreach([
          ['Pemilik Risiko', $profilRow['ttd_pemilik'], $profilRow['nama_ttd_pemilik'], $profilRow['nip_ttd_pemilik']],
          ['Pengelola Risiko', $profilRow['ttd_pengelola'], $profilRow['nama_ttd_pengelola'], $profilRow['nip_ttd_pengelola']]
        ] as $i => [$label,$ttd,$nama,$nip]): ?>
        <div style="padding:14px 20px;<?= $i===0?'border-right:1px solid var(--border)':'' ?>">
          <div style="font-size:.72rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px"><i class="fas fa-signature"></i> <?= $label ?></div>
          <?php if($ttd): ?>
          <img src="<?= $ttd ?>" style="height:55px;border:1px solid var(--border);border-radius:6px;background:#fff;padding:3px;display:block">
          <?php else: ?>
          <div style="height:55px;border:1.5px dashed var(--border);border-radius:6px;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:.75rem;background:#fff"><i class="fas fa-pen" style="margin-right:6px;opacity:.4"></i> Belum ada TTD</div>
          <?php endif; ?>
          <div style="margin-top:6px;font-weight:700;font-size:.83rem"><?= xss($nama??'') ?></div>
          <?php if($nip): ?><div style="font-size:.75rem;color:var(--text-muted)">NIP <?= xss($nip) ?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>

    </div>
  </div>

  <!-- Banner CTA Menuju Detail Risiko -->
  <div style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1px solid #86efac;border-radius:12px;padding:16px 20px;margin-top:16px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;box-shadow:var(--shadow-sm)">
    <div style="display:flex;align-items:center;gap:12px">
      <div style="width:42px;height:42px;border-radius:50%;background:#22c55e;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.15rem;flex-shrink:0">
        <i class="fas fa-clipboard-check"></i>
      </div>
      <div>
        <strong style="color:#166534;font-size:.95rem;display:block">Data Sasaran &amp; Profil Siap</strong>
        <span style="color:#15803d;font-size:.82rem">Langkah berikutnya: lengkapi penilaian probabilitas, dampak, dan rencana penanganan pada detail risiko.</span>
      </div>
    </div>
    <button type="button" class="btn btn-success" style="padding:10px 20px;font-weight:700;display:inline-flex;align-items:center;gap:8px" onclick="bukaTabDetailProfil()">
      <i class="fas fa-table"></i> Lanjut Isi / Nilai Detail Risiko (<?= count($detailRows) ?>) <i class="fas fa-arrow-right"></i>
    </button>
  </div>
</div>

<!-- Tab: Detail — tabel + tombol Tambah yang buka modal -->
<div id="tabDetail" class="tab-content <?= $activeTab==='detail'?'active':'' ?>">

  <!-- Compact Context Bar -->
  <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:12px 18px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;box-shadow:var(--shadow-sm)">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
      <span style="background:var(--primary-glow);color:var(--primary);font-weight:800;padding:4px 10px;border-radius:8px;font-size:.85rem">
        <i class="fas fa-shield-alt"></i> Tahun <?= xss($profilRow['tahun']) ?>
      </span>
      <span style="font-weight:700;color:var(--text);font-size:.92rem">
        <?= xss($profilRow['unit_pemilik_risiko'] ?? 'Unit Belum Ditentukan') ?>
      </span>
      <?php if(!empty($profilRow['sasaran'])): ?>
      <span style="color:var(--text-muted);font-size:.82rem;border-left:1px solid var(--border);padding-left:12px;max-width:400px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= xss($profilRow['sasaran']) ?>">
        <i class="fas fa-bullseye" style="color:var(--primary);margin-right:4px"></i> Sasaran: <?= xss($profilRow['sasaran']) ?>
      </span>
      <?php endif; ?>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <span style="font-size:.8rem;color:var(--text-muted)">
        Kelengkapan: <strong style="color:<?= $profilCompleteness === 100 ? 'var(--success)' : 'var(--accent)' ?>"><?= $profilCompleteness ?>%</strong>
      </span>
      <button type="button" class="btn btn-sm btn-outline" onclick="bukaTabHeaderProfil()" title="Lihat dan edit sasaran, program, dan pejabat penandatangan">
        <i class="fas fa-info-circle"></i> Info Sasaran &amp; TTD <i class="fas fa-chevron-right" style="font-size:.7rem;margin-left:2px"></i>
      </button>
    </div>
  </div>

  <?php if(empty($detailRows)): ?>
  <!-- Banner panduan: profil belum punya detail risiko -->
  <div style="background:linear-gradient(135deg,#fffbeb,#fef3c7);border:1px solid #fcd34d;border-left:5px solid #f59e0b;border-radius:12px;padding:18px 22px;margin-bottom:16px">
    <div style="display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap">
      <div style="width:44px;height:44px;border-radius:50%;background:#f59e0b;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex:0 0 auto">
        <i class="fas fa-flag"></i>
      </div>
      <div style="flex:1;min-width:240px">
        <div style="font-weight:800;font-size:.95rem;color:#92400e;margin-bottom:4px">Profil ini belum memiliki detail risiko</div>
        <div style="font-size:.8rem;color:#78350f;margin-bottom:10px">
          Detail risiko adalah inti dari Profil Risiko — di sinilah penilaian dilakukan. Langkah mengisi:
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px;font-size:.75rem;color:#78350f">
          <div style="background:rgba(255,255,255,.7);border-radius:8px;padding:8px 10px"><strong>1.</strong> Pilih risiko dari master Identifikasi</div>
          <div style="background:rgba(255,255,255,.7);border-radius:8px;padding:8px 10px"><strong>2.</strong> Geser P &amp; D kondisi saat ini</div>
          <div style="background:rgba(255,255,255,.7);border-radius:8px;padding:8px 10px"><strong>3.</strong> Atur target penurunan risiko</div>
          <div style="background:rgba(255,255,255,.7);border-radius:8px;padding:8px 10px"><strong>4.</strong> Isi pengendalian, jadwal &amp; PJ</div>
        </div>
      </div>
      <?php if(hasRole('Admin','Risk Manager')): ?>
      <div style="flex:0 0 auto">
        <?php if($risikoCount > 0): ?>
        <button type="button" class="btn btn-primary" onclick="try{bukaModalDetail();}catch(e){openModal('modalDetailRisiko');}">
          <i class="fas fa-plus"></i> Mulai Isi Detail Risiko
        </button>
        <?php else: ?>
        <a class="btn btn-warning" href="<?= APP_URL ?>/?page=risiko"><i class="fas fa-exclamation-triangle"></i> Buat Risiko Master Dulu</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Tabel Detail -->
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="fas fa-table"></i> Daftar Detail Risiko</span>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;flex:1;justify-content:flex-end">
        <div class="search-bar" style="max-width:250px;width:100%">
          <i class="fas fa-search"></i>
          <input type="text" class="form-control" id="searchDetailRisiko" placeholder="Cari detail risiko..." onkeyup="filterTableDetailRisiko()" style="height:38px">
        </div>
        <?php if(hasRole('Admin','Risk Manager')): ?>
        <?php if($risikoCount > 0): ?>
        <button type="button" class="btn btn-success" onclick="try{bukaModalDetail();}catch(e){openModal('modalDetailRisiko');}" style="height:38px;display:inline-flex;align-items:center;padding:0 16px;margin:0">
          <i class="fas fa-plus" style="margin-right:6px"></i> Tambah Detail Risiko
        </button>
        <?php else: ?>
        <a class="btn btn-sm btn-warning" href="<?= APP_URL ?>/?page=risiko" title="Belum ada risiko master yang disetujui">
          <i class="fas fa-plus"></i> Buat Risiko Master Dulu
        </a>
        <?php endif; ?>
        <?php endif; ?>
        <div class="datatable-dropdown" style="margin:0; display:flex; align-items:center;">
          <select class="datatable-selector" id="limitDetailRisiko" onchange="filterTableDetailRisiko()">
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
      <table class="data-table no-datatable profil-detail-table" id="tableDetailRisiko">
        <colgroup>
          <col class="col-no">
          <col class="col-code">
          <col class="col-risk">
          <col class="col-score"><col class="col-score"><col class="col-val"><col class="col-level">
          <col class="col-control">
          <col class="col-pic-jadwal">
          <col class="col-score"><col class="col-score"><col class="col-val"><col class="col-level">
          <?php if(hasRole('Admin','Risk Manager')): ?><col class="col-action"><?php endif; ?>
        </colgroup>
        <thead>
          <tr>
            <th rowspan="2" class="col-no" style="text-align:center">No</th>
            <th rowspan="2" class="col-code" style="text-align:center">Kode</th>
            <th rowspan="2" class="col-risk">Risiko &amp; Unit Kerja</th>
            <th colspan="4" style="text-align:center;background:#e2e8f0;color:#1e3a8a;font-weight:700;border-bottom:1px solid #cbd5e1">Kondisi Saat Ini</th>
            <th rowspan="2" class="col-control">Uraian Pengendalian</th>
            <th rowspan="2" class="col-pic-jadwal">PIC &amp; Jadwal</th>
            <th colspan="4" style="text-align:center;background:#dcfce7;color:#166534;font-weight:700;border-bottom:1px solid #bbf7d0">Target Penurunan Risiko</th>
            <?php if(hasRole('Admin','Risk Manager')): ?><th rowspan="2" class="col-action" style="text-align:center">Aksi</th><?php endif; ?>
          </tr>
          <tr>
            <th class="col-score" style="background:#edf2f7;text-align:center" title="Probabilitas">P</th>
            <th class="col-score" style="background:#edf2f7;text-align:center" title="Dampak">D</th>
            <th class="col-val" style="background:#edf2f7;text-align:center" title="Nilai &amp; Bobot">Nilai<br><span style="font-size:.62rem;font-weight:500;color:#475569">(Bobot)</span></th>
            <th class="col-level" style="background:#edf2f7;text-align:center">Tingkat</th>
            <th class="col-score" style="background:#e8fdf0;text-align:center" title="Target Probabilitas">P</th>
            <th class="col-score" style="background:#e8fdf0;text-align:center" title="Target Dampak">D</th>
            <th class="col-val" style="background:#e8fdf0;text-align:center" title="Target Nilai &amp; Bobot">Nilai<br><span style="font-size:.62rem;font-weight:500;color:#166534">(Bobot)</span></th>
            <th class="col-level" style="background:#e8fdf0;text-align:center">Tingkat</th>
          </tr>
        </thead>
        <tbody>
        <?php if(empty($detailRows)): ?>
        <tr><td colspan="<?= hasRole('Admin','Risk Manager') ? 14 : 13 ?>"><div class="empty-state" style="padding:40px">
          <i class="fas fa-table" style="font-size:2.5rem;opacity:.3;margin-bottom:12px;display:block"></i>
          <h3>Belum ada detail risiko</h3>
          <p style="margin-top:8px;color:var(--text-muted);max-width:480px;margin-left:auto;margin-right:auto">
            Tambahkan risiko dari master <strong>Identifikasi Risiko</strong> ke profil ini,
            lalu isi P/D awal, rencana penanganan, dan target penurunan.
          </p>
          <?php if(hasRole('Admin','Risk Manager')): ?>
          <div style="margin-top:20px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
            <?php if($risikoCount > 0): ?>
            <button type="button" class="btn btn-primary" onclick="bukaModalDetail()">
              <i class="fas fa-plus"></i> Tambah Detail Risiko
            </button>
            <?php else: ?>
            <a class="btn btn-warning" href="<?= APP_URL ?>/?page=risiko">
              <i class="fas fa-exclamation-triangle"></i> Buat Risiko di Identifikasi Dulu
            </a>
            <?php endif; ?>
            <a class="btn btn-outline" href="<?= APP_URL ?>/?page=risiko">
              <i class="fas fa-external-link-alt"></i> Lihat Identifikasi Risiko
            </a>
          </div>
          </div>
          </td>
          <?php endif; ?></tr>
        <?php else: ?>
        <?php foreach($detailRows as $i => $dr): ?>
        <tr>
          <td style="text-align:center;font-weight:600;color:var(--text-muted)"><?= $i + 1 ?></td>
          <td style="text-align:center;white-space:nowrap">
            <span class="badge-kode-risiko"><?= xss($dr['kode_risiko']??'-') ?></span>
            <?php if (!empty($dr['kode_risiko']) && !isset($aktifKode[$dr['kode_risiko']])): ?>
            <div style="margin-top:2px"><span class="badge badge-warning" title="Risiko master nonaktif" style="font-size:.6rem;padding:1px 4px"><i class="fas fa-exclamation-triangle"></i> Nonaktif</span></div>
            <?php endif; ?>
          </td>
          <td>
            <div style="font-weight:600;color:var(--text-main);line-height:1.35;margin-bottom:3px"><?= xss($dr['nama_risiko']) ?></div>
            <div style="font-size:.71rem;color:var(--text-muted);display:flex;align-items:center;gap:4px">
              <i class="fas fa-building" style="font-size:.65rem;color:var(--text-muted);opacity:.7"></i>
              <span><?= xss($dr['unit_kerja']??'-') ?></span>
            </div>
          </td>
          <td style="text-align:center;font-weight:700"><?= $dr['probabilitas'] ?></td>
          <td style="text-align:center;font-weight:700"><?= $dr['dampak'] ?></td>
          <td style="text-align:center">
            <div style="font-weight:700;font-size:.82rem;line-height:1.1"><?= round((float)$dr['nilai']) ?></div>
            <div style="font-size:.66rem;color:var(--accent);font-weight:600;margin-top:1px" title="Bobot"><?= $dr['bobot'] ?></div>
          </td>
          <td style="text-align:center">
            <span style="background:<?= bgTingkat($dr['tingkat_risiko']??'Rendah') ?>;color:<?= colorTingkat($dr['tingkat_risiko']??'Rendah') ?>;padding:3px 8px;border-radius:12px;font-weight:700;font-size:.70rem;display:inline-block;white-space:nowrap;box-shadow:0 1px 2px rgba(0,0,0,.06)">
              <?= xss($dr['tingkat_risiko']??'-') ?>
            </span>
          </td>
          <td style="font-size:.75rem;line-height:1.35;word-break:break-word">
            <?= nl2br(xss($dr['rencana_penanganan']??'-')) ?>
          </td>
          <td style="line-height:1.35">
            <div style="font-weight:600;font-size:.74rem;color:var(--text-main);margin-bottom:3px;word-break:break-word">
              <i class="fas fa-user-tie" style="color:var(--primary);font-size:.70rem;margin-right:3px"></i><?= xss($dr['penanggungjawab']??'-') ?>
            </div>
            <?php if(!empty($dr['jadwal_pelaksanaan'])): ?>
            <div style="font-size:.69rem;color:var(--text-muted);display:flex;align-items:flex-start;gap:4px">
              <i class="far fa-calendar-alt" style="font-size:.67rem;margin-top:2px;opacity:.75"></i>
              <span style="word-break:break-word"><?= xss($dr['jadwal_pelaksanaan']) ?></span>
            </div>
            <?php endif; ?>
          </td>
          <td style="text-align:center;font-weight:700"><?= $dr['target_p'] ?></td>
          <td style="text-align:center;font-weight:700"><?= $dr['target_d'] ?></td>
          <td style="text-align:center">
            <div style="font-weight:700;font-size:.82rem;line-height:1.1;color:var(--success)"><?= round((float)$dr['target_nilai']) ?></div>
            <div style="font-size:.66rem;color:#16a34a;font-weight:600;margin-top:1px" title="Target Bobot"><?= $dr['target_bobot'] ?></div>
          </td>
          <td style="text-align:center">
            <span style="background:<?= bgTingkat($dr['target_tingkat_risiko']??'Rendah') ?>;color:<?= colorTingkat($dr['target_tingkat_risiko']??'Rendah') ?>;padding:3px 8px;border-radius:12px;font-weight:700;font-size:.70rem;display:inline-block;white-space:nowrap;box-shadow:0 1px 2px rgba(0,0,0,.06)">
              <?= xss($dr['target_tingkat_risiko']??'-') ?>
            </span>
          </td>
          <?php if(hasRole('Admin','Risk Manager')): ?>
          <td class="risiko-action-cell" style="text-align:center;white-space:nowrap">
            <div class="act-btn-group" style="justify-content:center">
              <button type="button" class="act-btn act-btn-edit"
                onclick='editDetailModal(<?= htmlspecialchars(json_encode($dr), ENT_QUOTES) ?>)'
                title="Edit">
                <i class="fas fa-edit"></i>
              </button>
              <form method="POST" style="display:contents;"><?= csrfField() ?>
                <input type="hidden" name="aksi" value="hapus_detail">
                <input type="hidden" name="id_detail" value="<?= $dr['id'] ?>">
                <input type="hidden" name="id_profil" value="<?= $activeId ?>">
                <button type="submit" class="act-btn act-btn-delete"
                  onclick="return confirm('Hapus detail ini?')"
                  title="Hapus">
                  <i class="fas fa-trash"></i>
                </button>
              </form>
            </div>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer" id="profilRisikoPagination" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;padding:14px 20px;">
      <span class="pagination-info" id="profilRisikoPageInfo" style="font-size:.85rem;color:var(--text-muted);font-weight:500;">Memuat...</span>
      <div class="pagination" id="profilRisikoPages" style="margin:0;gap:6px;"></div>
    </div>
  </div>
</div><!-- end tab detail -->

<?php endif; // end if activeId ?>
</div><!-- end konten utama -->

<!-- Modal Form Detail Risiko — 1 Layar -->
<div class="modal-overlay" id="modalDetailRisiko" style="display:none">
  <div class="modal" style="max-width:780px;max-height:92vh;overflow-y:auto">
    <div class="modal-header" style="background:linear-gradient(135deg,var(--primary),var(--primary-light));position:sticky;top:0;z-index:10">
      <h3 class="modal-title" id="modalDetailTitle" style="color:#fff">
        <i class="fas fa-plus-circle"></i> Tambah Detail Risiko
      </h3>
      <button class="btn-close" onclick="closeModal('modalDetailRisiko')" style="color:#fff"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/?page=profil_risiko" id="formDetailRisiko">
    <?= csrfField() ?>
    <input type="hidden" name="aksi" value="simpan_detail">
    <input type="hidden" name="id_profil" value="<?= $activeId ?>">
    <input type="hidden" name="id_detail" id="modalDetailId" value="0">
    <div class="modal-body" style="padding:20px">

      <!-- Panduan singkat -->
      <div style="background:var(--surface2);border-radius:10px;padding:10px 14px;margin-bottom:16px;font-size:.75rem;color:var(--text-muted);line-height:1.6">
        <i class="fas fa-info-circle" style="color:var(--accent)"></i>
        Isi form mengikuti langkah <strong>1 &rarr; 5</strong>. Hanya field bertanda <span class="required">*</span> yang wajib.
        Nilai <strong>Bobot, Nilai, Tingkat, dan Prioritas dihitung otomatis</strong> — tidak perlu diisi.
      </div>

      <!-- LANGKAH 1: Kode dan prioritas -->
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
        <span style="width:22px;height:22px;border-radius:50%;background:var(--primary);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:800;flex:0 0 auto">1</span>
        <span style="font-size:.8rem;font-weight:800;color:var(--text)">Pilih Risiko dari Identifikasi</span>
      </div>
      <div style="display:grid;grid-template-columns:1fr 100px;gap:12px;margin-bottom:14px">
        <div class="form-group" style="margin:0">
          <label class="form-label">Kode Risiko</label>
          <select name="kode_risiko" id="md_kode" class="form-control" onchange="autoFillRisiko(this)" required>
            <option value="">-- Pilih Kode Risiko --</option>
            <?php foreach($risikoList as $rsk): 
              $st = $rsk['approval_status'] ?? 'approved';
              $stMark = $st === 'approved' ? '' : ' [' . ucfirst($st) . ']';
            ?>
            <option value="<?= htmlspecialchars($rsk['kode_risiko']) ?>" data-nama="<?= htmlspecialchars($rsk['nama_risiko']) ?>" data-p="<?= $rsk['probabilitas'] ?>" data-d="<?= $rsk['dampak'] ?>">
              <?= htmlspecialchars($rsk['kode_risiko']) ?> - <?= htmlspecialchars($rsk['nama_risiko']) ?><?= $stMark ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin:0">
          <label class="form-label">Prioritas (Auto)</label>
          <input type="number" name="prioritas_risiko" id="md_prioritas" class="form-control" readonly style="background:#f1f5f9">
        </div>
      </div>

      <!-- LANGKAH 2: Unit Kerja & Nama Risiko -->
      <div style="display:flex;align-items:center;gap:8px;margin:16px 0 8px">
        <span style="width:22px;height:22px;border-radius:50%;background:var(--primary);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:800;flex:0 0 auto">2</span>
        <span style="font-size:.8rem;font-weight:800;color:var(--text)">Deskripsi Risiko &amp; Unit Kerja</span>
      </div>
      <div class="form-group" style="margin-bottom:14px">
        <label class="form-label">Unit Kerja Pemilik Risiko <span style="font-weight:400;color:var(--text-muted);font-size:.72rem">(opsional — sudah terisi default)</span></label>
        <input type="text" name="unit_kerja" id="md_unit" class="form-control" value="Balai Besar Laboratorium Kesehatan Lingkungan">
      </div>
      <div class="form-group" style="margin-bottom:14px">
        <label class="form-label">Risiko <span class="required">*</span></label>
        <textarea name="nama_risiko" id="md_nama" class="form-control" rows="2" required
          placeholder="Deskripsi risiko yang diidentifikasi..."></textarea>
      </div>

      <!-- LANGKAH 3 & 4: P D Bobot — dua grup dalam 1 baris -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
        <!-- Kondisi Saat Ini -->
        <div style="background:#eff6ff;border-radius:10px;padding:14px;border:1px solid #bfdbfe">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
            <span style="width:22px;height:22px;border-radius:50%;background:#1d4ed8;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:800;flex:0 0 auto">3</span>
            <div style="font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:#1d4ed8">
              Kondisi Saat Ini
            </div>
          </div>
          <div style="font-size:.68rem;color:#64748b;margin-bottom:12px;line-height:1.5">
            <strong>P</strong> = seberapa mungkin risiko terjadi &nbsp;&middot;&nbsp; <strong>D</strong> = seberapa besar dampaknya. Geser slider; nilai &amp; tingkat terhitung otomatis.
          </div>
          <!-- Slider P -->
          <div style="margin-bottom:14px">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
              <label class="form-label" style="font-size:.78rem;margin:0;font-weight:700">Probabilitas:</label>
              <span id="lbl_p_val" style="background:#1d4ed8;color:#fff;border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:800">3</span>
              <span id="lbl_p_text" style="font-size:.78rem;color:#1d4ed8;font-weight:600">— Sedang</span>
            </div>
            <input type="range" name="probabilitas" id="inp_p" min="1" max="5" value="3"
              style="width:100%;accent-color:#1d4ed8;height:6px;cursor:pointer"
              oninput="updateSliderLabel('p');hitungNilai()">
            <div style="display:flex;justify-content:space-between;font-size:.65rem;color:#64748b;margin-top:3px;padding:0 2px">
              <span>1=Jarang</span><span>2=Kecil</span><span>3=Sedang</span><span>4=Besar</span><span>5=Hampir Pasti</span>
            </div>
          </div>
          <!-- Slider D -->
          <div style="margin-bottom:12px">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
              <label class="form-label" style="font-size:.78rem;margin:0;font-weight:700">Dampak Level:</label>
              <span id="lbl_d_val" style="background:#1d4ed8;color:#fff;border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:800">3</span>
              <span id="lbl_d_text" style="font-size:.78rem;color:#1d4ed8;font-weight:600">— Sedang</span>
            </div>
            <input type="range" name="dampak" id="inp_d" min="1" max="5" value="3"
              style="width:100%;accent-color:#1d4ed8;height:6px;cursor:pointer"
              oninput="updateSliderLabel('d');hitungNilai()">
            <div style="display:flex;justify-content:space-between;font-size:.65rem;color:#64748b;margin-top:3px;padding:0 2px">
              <span>1=T.Signifikan</span><span>2=Kecil</span><span>3=Sedang</span><span>4=Besar</span><span>5=Katastropik</span>
            </div>
          </div>
          <!-- Hidden bobot + ringkasan -->
          <input type="hidden" name="bobot" id="inp_bobot" value="1.43">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px">
            <div>
              <div style="font-size:.65rem;color:#64748b;text-align:center;margin-bottom:3px">Nilai Risiko</div>
              <div id="hasil_nilai" style="font-size:1.6rem;font-weight:800;color:#1d4ed8;text-align:center;line-height:1">-</div>
            </div>
            <div>
              <div style="font-size:.65rem;color:#64748b;text-align:center;margin-bottom:3px">Tingkat Risiko</div>
              <div id="md_tingkat" style="padding:5px 8px;border-radius:8px;font-weight:700;font-size:.78rem;text-align:center;background:#dbeafe;color:#1d4ed8">-</div>
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:4px;margin-top:8px">
            <div style="text-align:center;background:#1d4ed8;border-radius:6px;padding:5px 2px">
              <div id="sum_p" style="font-size:1rem;font-weight:800;color:#fff">3</div>
              <div style="font-size:.6rem;color:rgba(255,255,255,.8)">P</div>
            </div>
            <div style="text-align:center;background:#1d4ed8;border-radius:6px;padding:5px 2px">
              <div id="sum_d" style="font-size:1rem;font-weight:800;color:#fff">3</div>
              <div style="font-size:.6rem;color:rgba(255,255,255,.8)">D</div>
            </div>
            <div style="text-align:center;background:#1d4ed8;border-radius:6px;padding:5px 2px">
              <div id="sum_bobot" style="font-size:1rem;font-weight:800;color:#fff">1.43</div>
              <div style="font-size:.6rem;color:rgba(255,255,255,.8)">Bobot</div>
            </div>
            <div style="text-align:center;background:#22c55e;border-radius:6px;padding:5px 2px" id="sum_skor_box">
              <div id="sum_skor" style="font-size:1rem;font-weight:800;color:#fff">-</div>
              <div style="font-size:.6rem;color:rgba(255,255,255,.8)">Skor</div>
            </div>
          </div>
        </div>

        <!-- Target Penurunan -->
        <div style="background:#f0fdf4;border-radius:10px;padding:14px;border:1px solid #bbf7d0">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
            <span style="width:22px;height:22px;border-radius:50%;background:#16a34a;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:800;flex:0 0 auto">4</span>
            <div style="font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:#16a34a">
              Target Penurunan
            </div>
          </div>
          <div style="font-size:.68rem;color:#64748b;margin-bottom:12px;line-height:1.5">
            Isi target yang diinginkan <strong>setelah</strong> pengendalian diterapkan (umumnya lebih kecil dari Kondisi Saat Ini).
          </div>
          <!-- Slider P Target -->
          <div style="margin-bottom:14px">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
              <label class="form-label" style="font-size:.78rem;margin:0;font-weight:700">Probabilitas:</label>
              <span id="lbl_tp_val" style="background:#16a34a;color:#fff;border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:800">2</span>
              <span id="lbl_tp_text" style="font-size:.78rem;color:#16a34a;font-weight:600">— Kecil</span>
            </div>
            <input type="range" name="target_p" id="inp_tp" min="1" max="5" value="2"
              style="width:100%;accent-color:#16a34a;height:6px;cursor:pointer"
              oninput="updateSliderLabel('tp');hitungTarget()">
            <div style="display:flex;justify-content:space-between;font-size:.65rem;color:#64748b;margin-top:3px;padding:0 2px">
              <span>1=Jarang</span><span>2=Kecil</span><span>3=Sedang</span><span>4=Besar</span><span>5=Hampir Pasti</span>
            </div>
          </div>
          <!-- Slider D Target -->
          <div style="margin-bottom:12px">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
              <label class="form-label" style="font-size:.78rem;margin:0;font-weight:700">Dampak Level:</label>
              <span id="lbl_td_val" style="background:#16a34a;color:#fff;border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:800">2</span>
              <span id="lbl_td_text" style="font-size:.78rem;color:#16a34a;font-weight:600">— Kecil</span>
            </div>
            <input type="range" name="target_d" id="inp_td" min="1" max="5" value="2"
              style="width:100%;accent-color:#16a34a;height:6px;cursor:pointer"
              oninput="updateSliderLabel('td');hitungTarget()">
            <div style="display:flex;justify-content:space-between;font-size:.65rem;color:#64748b;margin-top:3px;padding:0 2px">
              <span>1=T.Signifikan</span><span>2=Kecil</span><span>3=Sedang</span><span>4=Besar</span><span>5=Katastropik</span>
            </div>
          </div>
          <!-- Hidden bobot target -->
          <input type="hidden" name="target_bobot" id="inp_tb" value="1.80">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px">
            <div>
              <div style="font-size:.65rem;color:#64748b;text-align:center;margin-bottom:3px">Nilai Target</div>
              <div id="hasil_target" style="font-size:1.6rem;font-weight:800;color:#16a34a;text-align:center;line-height:1">-</div>
            </div>
            <div>
              <div style="font-size:.65rem;color:#64748b;text-align:center;margin-bottom:3px">Tingkat Target</div>
              <div id="md_ttingkat" style="padding:5px 8px;border-radius:8px;font-weight:700;font-size:.78rem;text-align:center;background:#dcfce7;color:#16a34a">-</div>
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:4px;margin-top:8px">
            <div style="text-align:center;background:#16a34a;border-radius:6px;padding:5px 2px">
              <div id="sum_tp" style="font-size:1rem;font-weight:800;color:#fff">2</div>
              <div style="font-size:.6rem;color:rgba(255,255,255,.8)">P</div>
            </div>
            <div style="text-align:center;background:#16a34a;border-radius:6px;padding:5px 2px">
              <div id="sum_td" style="font-size:1rem;font-weight:800;color:#fff">2</div>
              <div style="font-size:.6rem;color:rgba(255,255,255,.8)">D</div>
            </div>
            <div style="text-align:center;background:#16a34a;border-radius:6px;padding:5px 2px">
              <div id="sum_tbobot" style="font-size:1rem;font-weight:800;color:#fff">1.80</div>
              <div style="font-size:.6rem;color:rgba(255,255,255,.8)">Bobot</div>
            </div>
            <div style="text-align:center;background:#22c55e;border-radius:6px;padding:5px 2px" id="sum_tskor_box">
              <div id="sum_tskor" style="font-size:1rem;font-weight:800;color:#fff">-</div>
              <div style="font-size:.6rem;color:rgba(255,255,255,.8)">Skor</div>
            </div>
          </div>
        </div>
      </div>

      <!-- LANGKAH 5: Rencana & Jadwal & Penanggungjawab -->
      <div style="display:flex;align-items:center;gap:8px;margin:16px 0 8px">
        <span style="width:22px;height:22px;border-radius:50%;background:var(--primary);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:800;flex:0 0 auto">5</span>
        <span style="font-size:.8rem;font-weight:800;color:var(--text)">Rencana Pengendalian, Jadwal &amp; Penanggungjawab</span>
      </div>
      <div style="display:grid;grid-template-columns:1fr 180px 200px;gap:12px">
        <div class="form-group" style="margin:0">
          <label class="form-label">Uraian Pengendalian</label>
          <textarea name="rencana_penanganan" id="md_rencana" class="form-control" rows="2"
            placeholder="Uraikan pengendalian..."></textarea>
        </div>
        <div class="form-group" style="margin:0">
          <label class="form-label">Jadwal Pelaksanaan</label>
          <div style="display:flex;gap:4px;align-items:center">
            <select id="jadwal_start" class="form-control" style="padding:4px 8px;font-size:12px" onchange="updateJadwal()">
              <option value="">Pilih...</option>
              <option value="Januari">Jan</option>
              <option value="Februari">Feb</option>
              <option value="Maret">Mar</option>
              <option value="April">Apr</option>
              <option value="Mei">Mei</option>
              <option value="Juni">Jun</option>
              <option value="Juli">Jul</option>
              <option value="Agustus">Ags</option>
              <option value="September">Sep</option>
              <option value="Oktober">Okt</option>
              <option value="November">Nov</option>
              <option value="Desember">Des</option>
              <option value="Sepanjang Tahun">1 Tahun</option>
            </select>
            <span style="font-size:12px;color:var(--text-muted)">s.d.</span>
            <select id="jadwal_end" class="form-control" style="padding:4px 8px;font-size:12px" onchange="updateJadwal()">
              <option value="">(Sama)</option>
              <option value="Januari">Jan</option>
              <option value="Februari">Feb</option>
              <option value="Maret">Mar</option>
              <option value="April">Apr</option>
              <option value="Mei">Mei</option>
              <option value="Juni">Jun</option>
              <option value="Juli">Jul</option>
              <option value="Agustus">Ags</option>
              <option value="September">Sep</option>
              <option value="Oktober">Okt</option>
              <option value="November">Nov</option>
              <option value="Desember">Des</option>
            </select>
          </div>
          <input type="hidden" name="jadwal_pelaksanaan" id="md_jadwal">
        </div>
        <div class="form-group" style="margin:0">
          <label class="form-label">Penanggungjawab</label>
          <input type="text" list="list_penanggungjawab" name="penanggungjawab" id="md_penanggungjawab" class="form-control" placeholder="-- Pilih / Ketik Penanggungjawab --" autocomplete="off">
            <datalist id="list_penanggungjawab">
              <option value="Kepala Subbagian Administrasi Umum">
              <option value="Katimker 1">
              <option value="Katimker 2">
              <option value="Katimker 3">
              <option value="Koordinator Instalasi">
              <option value="Unit Pengendali Gratifikasi">
              <?php foreach ($usersWithNip as $pjUser): ?>
              <option value="<?= htmlspecialchars($pjUser['nama'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($pjUser['nama'], ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </datalist>
        </div>
      </div>

    </div>
    <div class="modal-footer" style="background:var(--surface2)">
      <button type="button" onclick="closeModal('modalDetailRisiko')" class="btn btn-outline">Batal</button>
      <button type="submit" class="btn btn-success" id="btnSimpanDetail">
        <i class="fas fa-save"></i> Simpan Detail
      </button>
    </div>
    </form>
  </div>
</div>

<!-- Modal Profil Baru -->
<div class="modal-overlay" id="modalNewProfil" style="display:none">
  <div class="modal modal-lg">
    <div class="modal-header">
      <h3 class="modal-title"><i class="fas fa-plus-circle"></i> Buat Profil Risiko Baru</h3>
      <button class="btn-close" onclick="closeModal('modalNewProfil')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/?page=profil_risiko" id="formProfilHeader">
    <?= csrfField() ?>
    <input type="hidden" name="aksi" value="simpan_header">
    <input type="hidden" name="id" value="0">
    <input type="hidden" name="ttd_pemilik" id="ttdPemilikNew">
    <input type="hidden" name="ttd_pengelola" id="ttdPengelolaNew">
    <div class="modal-body">
      <div id="copyBarProfil" style="margin-bottom:14px;padding:10px 14px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <i class="fas fa-copy" style="color:var(--accent)"></i>
        <span style="font-size:.82rem;font-weight:600;color:var(--text)">Salin header dari KKPR:</span>
        <select id="copyKkprSelect" class="form-control" style="width:auto;flex:1;min-width:180px;padding:6px 10px;font-size:.82rem">
          <option value="">-- Pilih KKPR --</option>
        </select>
        <button type="button" class="btn btn-xs btn-accent" onclick="copyHeaderToProfil()"><i class="fas fa-paste"></i> Salin</button>
      </div>
      <div class="form-row-2">
        <div class="form-group"><label class="form-label">Tahun <span class="required">*</span></label>
          <input type="text" name="tahun" class="form-control" required value="<?= date('Y') ?>" placeholder="2026" style="text-align: center;"></div>
        <div class="form-group"><label class="form-label">Unit Pemilik Risiko</label>
          <input type="text" name="unit_pemilik_risiko" class="form-control" value="Balai Besar Laboratorium Kesehatan Lingkungan"></div>
        <div class="form-group"><label class="form-label">Nama Pemilik Risiko</label>
          <input type="text" name="nama_pemilik_risiko" class="form-control" placeholder="Pimpinan Unit" list="listUsersWithNip" onchange="autofillNip(this, 'nip_pemilik_risiko', '#modalNewProfil')"></div>
        <div class="form-group"><label class="form-label">NIP Pemilik Risiko</label>
          <input type="text" name="nip_pemilik_risiko" class="form-control" placeholder="NIP pemilik risiko"></div>
        <div class="form-group"><label class="form-label">Nama Pengelola Risiko</label>
          <input type="text" name="nama_pengelola_risiko" class="form-control" value="<?= xss(getDefaultPengelola()) ?>" list="listUsersWithNip" onchange="autofillNip(this, 'nip_pengelola_risiko', '#modalNewProfil')"></div>
        <div class="form-group"><label class="form-label">NIP Pengelola Risiko</label>
          <input type="text" name="nip_pengelola_risiko" class="form-control" placeholder="NIP pengelola risiko"></div>
      </div>
      <div class="form-group"><label class="form-label">Tujuan</label>
        <input type="text" name="tujuan" class="form-control" value="Terciptanya Sistem Ketahanan yang Tangguh"></div>
      <div class="form-group"><label class="form-label">Sasaran</label>
        <textarea name="sasaran" class="form-control" rows="2" placeholder="Meningkatnya jumlah dan kemampuan..."></textarea></div>
      <div class="form-row-2">
          <div class="form-group" style="grid-column:span 2">
            <label class="form-label" style="display:flex;justify-content:space-between;align-items:center;">
              Indikator Kinerja Kegiatan
              <select name="master_indikator_ids[]" multiple class="form-control" style="width:260px;display:inline-block;padding:2px 6px;height:58px;font-size:0.75rem" onchange="appendMasterIndicators(this, '#modalNewProfil textarea[name=indikator_kinerja]', '#modalNewProfil textarea[name=target]')">
                <option value="">-- Pilih dari Master IKK --</option>
                <?php foreach($masterIkkList as $ik): ?>
                <option value="<?= $ik['id'] ?>" data-indikator="<?= htmlspecialchars($ik['indikator']) ?>" data-target="<?= htmlspecialchars($ik['target'] ?? '') ?>" <?= in_array((int)$ik['id'], $selectedMasterIndicators, true) ? 'selected' : '' ?>><?= htmlspecialchars($ik['tahun'].' - '.mb_strimwidth($ik['indikator'], 0, 45, '...')) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <textarea name="indikator_kinerja" class="form-control" rows="6" placeholder="Masukkan indikator kinerja kegiatan (bisa input beberapa indikator, satu per baris)"></textarea>
          </div>
        <div class="form-group" style="grid-column:span 2"><label class="form-label">Target</label>
          <textarea name="target" class="form-control" rows="4" placeholder="Masukkan target capaian (bisa input beberapa target, satu per baris)"></textarea></div>
        <div class="form-group"><label class="form-label">Program</label>
          <input type="text" name="program" class="form-control" value="Pencegahan dan Pengendalian Penyakit"></div>
        <div class="form-group"><label class="form-label">Kegiatan</label>
          <input type="text" name="kegiatan" class="form-control"></div>
        <div class="form-group"><label class="form-label">Tgl Penilaian Risiko</label>
          <input type="date" name="tgl_penilaian" class="form-control" value="<?= date('Y-m-d') ?>"></div>
        <div class="form-group"><label class="form-label">Periode Risiko</label>
          <input type="text" name="periode_risiko" class="form-control" placeholder="Januari s.d Desember 2024"></div>
        <div class="form-group"><label class="form-label">Tgl Update Risiko</label>
          <input type="date" name="tgl_update" class="form-control" value="<?= date('Y-m-d') ?>"></div>
      </div>
      <hr style="margin:16px 0;border-color:var(--border)">
      <div class="form-row-2">
        <div>
          <label class="form-label"><i class="fas fa-signature"></i> Tanda Tangan Pemilik Risiko</label>
          <!-- Toggle mode -->
          <div class="ttd-mode-bar" style="display:flex;gap:6px;margin-bottom:8px">
            <button type="button" class="btn btn-xs btn-primary active-ttd-btn" id="btnModeDrawP" onclick="setTtdMode('P','draw')"><i class="fas fa-pen"></i> Gambar</button>
            <button type="button" class="btn btn-xs btn-outline" id="btnModeUploadP" onclick="setTtdMode('P','upload')"><i class="fas fa-upload"></i> Upload File</button>
          </div>
          <!-- Mode Gambar -->
          <div id="ttdDrawP">
            <canvas id="canvasPemilik" class="sig-canvas" width="400" height="120"></canvas>
            <div class="sig-actions">
              <button type="button" class="btn btn-sm btn-outline" onclick="clearCanvas('canvasPemilik','ttdPemilikNew','sigStatusP')"><i class="fas fa-eraser"></i> Hapus</button>
              <span id="sigStatusP" style="font-size:.75rem;color:var(--text-muted)">Belum ada</span>
            </div>
          </div>
          <!-- Mode Upload -->
          <div id="ttdUploadP" style="display:none">
            <div style="border:2px dashed var(--border);border-radius:8px;padding:12px;text-align:center;background:var(--surface2)" id="dropzoneP" ondragover="event.preventDefault()" ondrop="handleDrop(event,'P','ttdPemilikNew','sigStatusP','previewP')">
              <i class="fas fa-cloud-upload-alt" style="font-size:1.8rem;color:var(--text-muted);margin-bottom:6px;display:block"></i>
              <div style="font-size:.8rem;color:var(--text-muted);margin-bottom:8px">Drag & drop atau klik pilih file</div>
              <input type="file" id="uploadP" accept="image/png,image/jpeg,image/gif,image/webp" style="display:none" onchange="handleUpload(this,'ttdPemilikNew','sigStatusP','previewP')">
              <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('uploadP').click()"><i class="fas fa-folder-open"></i> Pilih File</button>
              <div style="font-size:.72rem;color:var(--text-muted);margin-top:4px">PNG/JPG/GIF • Maks 2MB • Background transparan direkomendasikan</div>
            </div>
            <div id="previewP" style="margin-top:8px;display:none;text-align:center">
              <img id="previewImgP" style="max-height:100px;max-width:100%;border:1px solid var(--border);border-radius:6px;background:repeating-conic-gradient(#e5e5e5 0% 25%,transparent 0% 50%) 0 0/10px 10px;padding:4px">
              <div style="font-size:.75rem;color:var(--success);margin-top:4px"><i class="fas fa-check-circle"></i> <span id="previewNameP"></span></div>
              <button type="button" class="btn btn-xs btn-outline" onclick="clearUpload('P','ttdPemilikNew','sigStatusP','previewP')"><i class="fas fa-times"></i> Hapus</button>
            </div>
          </div>

        </div>
        <div>
          <label class="form-label"><i class="fas fa-signature"></i> Tanda Tangan Pengelola Risiko</label>
          <div class="ttd-mode-bar" style="display:flex;gap:6px;margin-bottom:8px">
            <button type="button" class="btn btn-xs btn-primary active-ttd-btn" id="btnModeDrawG" onclick="setTtdMode('G','draw')"><i class="fas fa-pen"></i> Gambar</button>
            <button type="button" class="btn btn-xs btn-outline" id="btnModeUploadG" onclick="setTtdMode('G','upload')"><i class="fas fa-upload"></i> Upload File</button>
          </div>
          <div id="ttdDrawG">
            <canvas id="canvasPengelola" class="sig-canvas" width="400" height="120"></canvas>
            <div class="sig-actions">
              <button type="button" class="btn btn-sm btn-outline" onclick="clearCanvas('canvasPengelola','ttdPengelolaNew','sigStatusG')"><i class="fas fa-eraser"></i> Hapus</button>
              <span id="sigStatusG" style="font-size:.75rem;color:var(--text-muted)">Belum ada</span>
            </div>
          </div>
          <div id="ttdUploadG" style="display:none">
            <div style="border:2px dashed var(--border);border-radius:8px;padding:12px;text-align:center;background:var(--surface2)" id="dropzoneG" ondragover="event.preventDefault()" ondrop="handleDrop(event,'G','ttdPengelolaNew','sigStatusG','previewG')">
              <i class="fas fa-cloud-upload-alt" style="font-size:1.8rem;color:var(--text-muted);margin-bottom:6px;display:block"></i>
              <div style="font-size:.8rem;color:var(--text-muted);margin-bottom:8px">Drag & drop atau klik pilih file</div>
              <input type="file" id="uploadG" accept="image/png,image/jpeg,image/gif,image/webp" style="display:none" onchange="handleUpload(this,'ttdPengelolaNew','sigStatusG','previewG')">
              <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('uploadG').click()"><i class="fas fa-folder-open"></i> Pilih File</button>
              <div style="font-size:.72rem;color:var(--text-muted);margin-top:4px">PNG/JPG/GIF • Maks 2MB • Background transparan direkomendasikan</div>
            </div>
            <div id="previewG" style="margin-top:8px;display:none;text-align:center">
              <img id="previewImgG" style="max-height:100px;max-width:100%;border:1px solid var(--border);border-radius:6px;background:repeating-conic-gradient(#e5e5e5 0% 25%,transparent 0% 50%) 0 0/10px 10px;padding:4px">
              <div style="font-size:.75rem;color:var(--success);margin-top:4px"><i class="fas fa-check-circle"></i> <span id="previewNameG"></span></div>
              <button type="button" class="btn btn-xs btn-outline" onclick="clearUpload('G','ttdPengelolaNew','sigStatusG','previewG')"><i class="fas fa-times"></i> Hapus</button>
            </div>
          </div>

        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" onclick="closeModal('modalNewProfil')" class="btn btn-outline">Batal</button>
      <button type="submit" class="btn btn-primary" onclick="saveSigs()"><i class="fas fa-save"></i> Simpan Profil</button>
    </div>
    </form>
  </div>
</div>

<!-- Modal Edit Header (jika sudah ada profil) -->
<?php if($activeId && $profilRow && hasRole('Admin','Risk Manager')): ?>
<div class="modal-overlay" id="modalEditHeader" style="display:none">
  <div class="modal modal-lg">
    <div class="modal-header">
      <h3 class="modal-title"><i class="fas fa-edit"></i> Edit Profil — Tahun <?= xss($profilRow['tahun']) ?></h3>
      <button class="btn-close" onclick="closeModal('modalEditHeader')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/?page=profil_risiko">
    <?= csrfField() ?>
    <input type="hidden" name="aksi" value="simpan_header">
    <input type="hidden" name="id" value="<?= $activeId ?>">
    <input type="hidden" name="ttd_pemilik" id="ttdPemilikEdit" value="<?= xss($profilRow['ttd_pemilik']??'') ?>">
    <input type="hidden" name="ttd_pengelola" id="ttdPengelolaEdit" value="<?= xss($profilRow['ttd_pengelola']??'') ?>">
    <div class="modal-body">
      <div class="form-row-2">
        <div class="form-group"><label class="form-label">Tahun</label><input type="text" name="tahun" class="form-control" value="<?= xss($profilRow['tahun']) ?>" style="text-align: center;"></div>
        <div class="form-group"><label class="form-label">Unit Pemilik Risiko</label><input type="text" name="unit_pemilik_risiko" class="form-control" value="<?= xss($profilRow['unit_pemilik_risiko'] ?: 'Balai Besar Laboratorium Kesehatan Lingkungan') ?>"></div>
        <div class="form-group"><label class="form-label">Nama Pemilik Risiko</label><input type="text" name="nama_pemilik_risiko" class="form-control" value="<?= xss($profilRow['nama_pemilik_risiko']??'') ?>" list="listUsersWithNip" onchange="autofillNip(this, 'nip_pemilik_risiko', '#modalEditHeader')"></div>
        <div class="form-group"><label class="form-label">NIP Pemilik Risiko</label><input type="text" name="nip_pemilik_risiko" class="form-control" value="<?= xss($profilRow['nip_pemilik_risiko']??'') ?>"></div>
        <div class="form-group"><label class="form-label">Nama Pengelola Risiko</label><input type="text" name="nama_pengelola_risiko" class="form-control" value="<?= xss($profilRow['nama_pengelola_risiko'] ?: getDefaultPengelola()) ?>" list="listUsersWithNip" onchange="autofillNip(this, 'nip_pengelola_risiko', '#modalEditHeader')"></div>
        <div class="form-group"><label class="form-label">NIP Pengelola Risiko</label><input type="text" name="nip_pengelola_risiko" class="form-control" value="<?= xss($profilRow['nip_pengelola_risiko']??'') ?>"></div>
      </div>
      <div class="form-group"><label class="form-label">Tujuan</label><input type="text" name="tujuan" class="form-control" value="<?= xss($profilRow['tujuan']??'') ?>"></div>
      <div class="form-group"><label class="form-label">Sasaran</label><textarea name="sasaran" class="form-control" rows="2"><?= xss($profilRow['sasaran']??'') ?></textarea></div>
      <div class="form-row-2">
        <div class="form-group">
          <label class="form-label" style="display:flex;justify-content:space-between;align-items:center;">
            Indikator Kinerja Kegiatan
            <select name="master_indikator_ids[]" multiple class="form-control" style="width:260px;display:inline-block;padding:2px 6px;height:58px;font-size:0.75rem" onchange="appendMasterIndicators(this, '#modalEditHeader textarea[name=indikator_kinerja]', '#modalEditHeader textarea[name=target]')">
              <option value="">-- Pilih dari Master IKK --</option>
              <?php foreach($masterIkkList as $ik): ?>
              <option value="<?= $ik['id'] ?>" data-indikator="<?= htmlspecialchars($ik['indikator']) ?>" data-target="<?= htmlspecialchars($ik['target'] ?? '') ?>" <?= in_array((int)$ik['id'], $selectedMasterIndicators, true) ? 'selected' : '' ?>><?= htmlspecialchars($ik['tahun'].' - '.mb_strimwidth($ik['indikator'], 0, 45, '...')) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <textarea name="indikator_kinerja" class="form-control" rows="4"><?= xss($profilRow['indikator_kinerja']??'') ?></textarea>
        </div>
        <div class="form-group"><label class="form-label">Target</label><textarea name="target" class="form-control" rows="4"><?= xss($profilRow['target']??'') ?></textarea></div>
        <div class="form-group"><label class="form-label">Program</label><input type="text" name="program" class="form-control" value="<?= xss($profilRow['program']??'') ?>"></div>
        <div class="form-group"><label class="form-label">Kegiatan</label><input type="text" name="kegiatan" class="form-control" value="<?= xss($profilRow['kegiatan']??'') ?>"></div>
        <div class="form-group"><label class="form-label">Tgl Penilaian</label><input type="date" name="tgl_penilaian" class="form-control" value="<?= $profilRow['tgl_penilaian']??'' ?>"></div>
        <div class="form-group"><label class="form-label">Periode Risiko</label><input type="text" name="periode_risiko" class="form-control" value="<?= xss($profilRow['periode_risiko']??'') ?>"></div>
        <div class="form-group"><label class="form-label">Tgl Update</label><input type="date" name="tgl_update" class="form-control" value="<?= $profilRow['tgl_update']??'' ?>"></div>
        <input type="hidden" name="nama_ttd_pemilik" value="<?= xss($profilRow['nama_ttd_pemilik']??'') ?>">
        <input type="hidden" name="nip_ttd_pemilik" value="<?= xss($profilRow['nip_ttd_pemilik']??'') ?>">
        <input type="hidden" name="nama_ttd_pengelola" value="<?= xss($profilRow['nama_ttd_pengelola']??'') ?>">
        <input type="hidden" name="nip_ttd_pengelola" value="<?= xss($profilRow['nip_ttd_pengelola']??'') ?>">
      </div>
      <hr style="margin:12px 0;border-color:var(--border)">
      <div class="form-row-2">
        <div>
          <label class="form-label">TTD Pemilik (update)</label>
          <div style="display:flex;gap:6px;margin-bottom:8px">
            <button type="button" class="btn btn-xs btn-primary" id="btnModeDrawPE" onclick="setTtdMode('PE','draw')"><i class="fas fa-pen"></i> Gambar</button>
            <button type="button" class="btn btn-xs btn-outline" id="btnModeUploadPE" onclick="setTtdMode('PE','upload')"><i class="fas fa-upload"></i> Upload</button>
          </div>
          <div id="ttdDrawPE">
            <canvas id="canvasPemilikEdit" class="sig-canvas" width="400" height="100"></canvas>
            <div class="sig-actions">
              <button type="button" class="btn btn-xs btn-outline" onclick="clearCanvas('canvasPemilikEdit','ttdPemilikEdit','spE')"><i class="fas fa-eraser"></i> Hapus</button>
              <span id="spE" style="font-size:.72rem;color:var(--text-muted)"><?= $profilRow['ttd_pemilik']?'Ada TTD tersimpan':'Belum ada' ?></span>
            </div>
          </div>
          <div id="ttdUploadPE" style="display:none">
            <div style="border:2px dashed var(--border);border-radius:8px;padding:10px;text-align:center;background:var(--surface2)" ondragover="event.preventDefault()" ondrop="handleDrop(event,'PE','ttdPemilikEdit','spE','previewPE')">
              <input type="file" id="uploadPE" accept="image/png,image/jpeg,image/gif,image/webp" style="display:none" onchange="handleUpload(this,'ttdPemilikEdit','spE','previewPE')">
              <button type="button" class="btn btn-xs btn-outline" onclick="document.getElementById('uploadPE').click()"><i class="fas fa-folder-open"></i> Pilih File TTD</button>
              <div style="font-size:.7rem;color:var(--text-muted);margin-top:4px">PNG/JPG • Maks 2MB</div>
            </div>
            <div id="previewPE" style="margin-top:6px;display:none;text-align:center">
              <img id="previewImgPE" style="max-height:80px;border:1px solid var(--border);border-radius:6px;background:repeating-conic-gradient(#e5e5e5 0% 25%,transparent 0% 50%) 0 0/10px 10px;padding:3px">
              <div style="font-size:.72rem;color:var(--success);margin-top:3px"><i class="fas fa-check-circle"></i> <span id="previewNamePE"></span></div>
              <button type="button" class="btn btn-xs btn-outline" onclick="clearUpload('PE','ttdPemilikEdit','spE','previewPE')"><i class="fas fa-times"></i></button>
            </div>
            <?php if($profilRow['ttd_pemilik']): ?>
            <div style="margin-top:6px;text-align:center">
              <div style="font-size:.72rem;color:var(--text-muted);margin-bottom:3px">TTD tersimpan saat ini:</div>
              <img src="<?= $profilRow['ttd_pemilik'] ?>" style="max-height:50px;border:1px solid var(--border);border-radius:4px;background:#fff;padding:2px">
            </div>
            <?php endif; ?>
          </div>
        </div>
        <div>
          <label class="form-label">TTD Pengelola (update)</label>
          <div style="display:flex;gap:6px;margin-bottom:8px">
            <button type="button" class="btn btn-xs btn-primary" id="btnModeDrawGE" onclick="setTtdMode('GE','draw')"><i class="fas fa-pen"></i> Gambar</button>
            <button type="button" class="btn btn-xs btn-outline" id="btnModeUploadGE" onclick="setTtdMode('GE','upload')"><i class="fas fa-upload"></i> Upload</button>
          </div>
          <div id="ttdDrawGE">
            <canvas id="canvasPengelolaEdit" class="sig-canvas" width="400" height="100"></canvas>
            <div class="sig-actions">
              <button type="button" class="btn btn-xs btn-outline" onclick="clearCanvas('canvasPengelolaEdit','ttdPengelolaEdit','sgE')"><i class="fas fa-eraser"></i> Hapus</button>
              <span id="sgE" style="font-size:.72rem;color:var(--text-muted)"><?= $profilRow['ttd_pengelola']?'Ada TTD tersimpan':'Belum ada' ?></span>
            </div>
          </div>
          <div id="ttdUploadGE" style="display:none">
            <div style="border:2px dashed var(--border);border-radius:8px;padding:10px;text-align:center;background:var(--surface2)" ondragover="event.preventDefault()" ondrop="handleDrop(event,'GE','ttdPengelolaEdit','sgE','previewGE')">
              <input type="file" id="uploadGE" accept="image/png,image/jpeg,image/gif,image/webp" style="display:none" onchange="handleUpload(this,'ttdPengelolaEdit','sgE','previewGE')">
              <button type="button" class="btn btn-xs btn-outline" onclick="document.getElementById('uploadGE').click()"><i class="fas fa-folder-open"></i> Pilih File TTD</button>
              <div style="font-size:.7rem;color:var(--text-muted);margin-top:4px">PNG/JPG • Maks 2MB</div>
            </div>
            <div id="previewGE" style="margin-top:6px;display:none;text-align:center">
              <img id="previewImgGE" style="max-height:80px;border:1px solid var(--border);border-radius:6px;background:repeating-conic-gradient(#e5e5e5 0% 25%,transparent 0% 50%) 0 0/10px 10px;padding:3px">
              <div style="font-size:.72rem;color:var(--success);margin-top:3px"><i class="fas fa-check-circle"></i> <span id="previewNameGE"></span></div>
              <button type="button" class="btn btn-xs btn-outline" onclick="clearUpload('GE','ttdPengelolaEdit','sgE','previewGE')"><i class="fas fa-times"></i></button>
            </div>
            <?php if($profilRow['ttd_pengelola']): ?>
            <div style="margin-top:6px;text-align:center">
              <div style="font-size:.72rem;color:var(--text-muted);margin-bottom:3px">TTD tersimpan saat ini:</div>
              <img src="<?= $profilRow['ttd_pengelola'] ?>" style="max-height:50px;border:1px solid var(--border);border-radius:4px;background:#fff;padding:2px">
            </div>
            <?php endif; ?>
          </div>
    </div>
    </div>
    <div class="modal-footer">
      <button type="button" onclick="closeModal('modalEditHeader')" class="btn btn-outline">Batal</button>
      <button type="submit" class="btn btn-primary" onclick="saveSigsEdit()"><i class="fas fa-save"></i> Simpan</button>
    </div>
    </form>
  </div>
</div>
<?php endif; ?>
<!-- Modal Kirim Persetujuan -->
<div class="modal-overlay" id="modalKirimPersetujuan" style="display:none">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title"><i class="fas fa-paper-plane"></i> Ajukan Persetujuan Profil Risiko</h3>
      <button class="btn-close" onclick="closeModal('modalKirimPersetujuan')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/?page=profil_risiko">
      <?= csrfField() ?>
      <input type="hidden" name="aksi" value="kirim_persetujuan">
      <input type="hidden" name="id" value="<?= $activeId ?>">
      <div class="modal-body">
        <p style="margin-bottom:16px; color:var(--text-muted)">Sebelum mengajukan ke Pimpinan, mohon pastikan bahwa:</p>
        <div style="display:flex; flex-direction:column; gap:12px; margin-bottom: 24px;">
          <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
            <input type="checkbox" required style="margin-top:4px;">
            <span>Data profil (Unit, Pemilik Risiko, Tujuan, Sasaran) telah diisi lengkap.</span>
          </label>
          <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
            <input type="checkbox" required style="margin-top:4px;">
            <span>Seluruh risiko telah dinilai (Probabilitas & Dampak).</span>
          </label>
          <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
            <input type="checkbox" required style="margin-top:4px;">
            <span>Rencana penanganan dan target residual telah ditentukan dengan realistis.</span>
          </label>
        </div>
        <p style="font-size:0.85rem; color:var(--accent);"><strong>Catatan:</strong> Setelah dikirim, Anda tidak dapat mengubah data sampai Pimpinan memberikan keputusan (Disetujui/Revisi).</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('modalKirimPersetujuan')">Batal</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Kirim ke Pimpinan</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Tolak / Revisi -->
<div class="modal-overlay" id="modalTolakPersetujuan" style="display:none">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title"><i class="fas fa-times"></i> Revisi Profil Risiko</h3>
      <button class="btn-close" onclick="closeModal('modalTolakPersetujuan')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/?page=profil_risiko">
      <?= csrfField() ?>
      <input type="hidden" name="aksi" value="reject_profil">
      <input type="hidden" name="id" value="<?= $activeId ?>">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Catatan Revisi / Alasan Penolakan <span class="text-danger">*</span></label>
          <textarea name="catatan_revisi" class="form-control" rows="4" required placeholder="Tulis instruksi perbaikan untuk Risk Manager..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('modalTolakPersetujuan')">Batal</button>
        <button type="submit" class="btn btn-danger" style="background:var(--danger);color:#fff"><i class="fas fa-undo"></i> Kembalikan untuk Revisi</button>
      </div>
    </form>
  </div>
</div>

<script>
const defPengelola = <?= json_encode(getDefaultPengelola()) ?>;
const matriksBobot = {
  5: {1:1.5, 2:1.4, 3:1.13, 4:1.15, 5:1},
  4: {1:1.2, 2:1.19, 3:1.3, 4:1.16, 5:1.2},
  3: {1:1.17, 2:1.42, 3:1.43, 4:1.46, 5:1.47},
  2: {1:1, 2:1.8, 3:1.83, 4:1.9, 5:2.1},
  1: {1:1, 2:1.5, 3:2, 4:3, 5:4}
};
const mdTingkat  = s => s>=20?'Sangat Tinggi':s>=15?'Tinggi':s>=10?'Sedang':s>=5?'Rendah':'Sangat Rendah';
const mdBg       = s => s>=20?'#dc2626':s>=15?'#ea580c':s>=10?'#FFFF00':s>=5?'#22c55e':'#3b82f6';
const mdCl       = s => '#000';

function hitungNilai() {
  const p = parseInt(document.getElementById('inp_p').value)||0;
  const d = parseInt(document.getElementById('inp_d').value)||0;
  const s = Math.round((matriksBobot[p]?.[d] || 1) * p * d);
  const resEl = document.getElementById('hasil_nilai');
  if(resEl) resEl.textContent = s;
  
  const tkEl = document.getElementById('md_tingkat');
  if(tkEl) {
    tkEl.textContent = mdTingkat(s);
    tkEl.style.background = mdBg(s);
    tkEl.style.color = mdCl(s);
  }
  
  const sp = document.getElementById('sum_p'); if(sp) sp.textContent = p;
  const sd = document.getElementById('sum_d'); if(sd) sd.textContent = d;
  
  const ss = document.getElementById('sum_skor');
  if(ss) ss.textContent = s;
  
  const sb2 = document.getElementById('sum_skor_box');
  if(sb2) {
    sb2.style.background = mdBg(s);
    if(ss) ss.style.color = mdCl(s);
    let lbl = sb2.querySelector('div:nth-child(2)');
    if(lbl) lbl.style.color = mdCl(s) === '#000' ? 'rgba(0,0,0,0.7)' : 'rgba(255,255,255,0.8)';
  }
}

function hitungTarget() {
  const p = parseInt(document.getElementById('inp_tp').value)||0;
  const d = parseInt(document.getElementById('inp_td').value)||0;
  const s = Math.round((matriksBobot[p]?.[d] || 1) * p * d);
  const resEl = document.getElementById('hasil_target');
  if(resEl) resEl.textContent = s;
  
  const tkEl = document.getElementById('md_ttingkat');
  if(tkEl) {
    tkEl.textContent = mdTingkat(s);
    tkEl.style.background = mdBg(s);
    tkEl.style.color = mdCl(s);
  }
  
  const sp = document.getElementById('sum_tp'); if(sp) sp.textContent = p;
  const sd = document.getElementById('sum_td'); if(sd) sd.textContent = d;
  
  const ss = document.getElementById('sum_tskor');
  if(ss) ss.textContent = s;
  
  const sb2 = document.getElementById('sum_tskor_box');
  if(sb2) {
    sb2.style.background = mdBg(s);
    if(ss) ss.style.color = mdCl(s);
    let lbl = sb2.querySelector('div:nth-child(2)');
    if(lbl) lbl.style.color = mdCl(s) === '#000' ? 'rgba(0,0,0,0.7)' : 'rgba(255,255,255,0.8)';
  }
}

const arrLblP = ['','Jarang','Kecil','Sedang','Besar','Hampir Pasti'];
const arrLblD = ['','T.Signifikan','Kecil','Sedang','Besar','Katastropik'];
function updateSliderLabel(k) {
  const val = document.getElementById('inp_'+k)?.value;
  if(!val) return;
  const elVal = document.getElementById('lbl_'+k+'_val');
  if(elVal) elVal.textContent = val;
  const elText = document.getElementById('lbl_'+k+'_text');
  if(elText) {
    if(k.includes('p')) elText.textContent = '— '+arrLblP[val];
    else elText.textContent = '— '+arrLblD[val];
  }
}

// ── Auto-fill nama & P/D saat kode risiko dipilih ─────────────
function autoFillRisiko(sel) {
  const opt = sel.selectedOptions[0];
  if (!opt || !opt.value) return;
  const nama = opt.getAttribute('data-nama') || '';
  const p    = opt.getAttribute('data-p')    || '';
  const d    = opt.getAttribute('data-d')    || '';
  if (nama) document.getElementById('md_nama').value = nama;
  if (p)    document.getElementById('inp_p').value   = p;
  if (d)    document.getElementById('inp_d').value   = d;
  ['p','d'].forEach(k => updateSliderLabel(k));
  hitungNilai();
}

// ── Buka modal tambah baru ────────────────────────────────────
function bukaModalDetail() {
  document.getElementById('modalDetailTitle').innerHTML =
    '<i class="fas fa-plus-circle"></i> Tambah Detail Risiko';
  document.getElementById('modalDetailId').value = '0';
  document.getElementById('md_unit').value     = 'Balai Besar Laboratorium Kesehatan Lingkungan';
  document.getElementById('md_kode').value     = '';
  document.getElementById('md_prioritas').value= '1';
  document.getElementById('md_nama').value     = '';
  document.getElementById('inp_p').value       = '3';
  document.getElementById('inp_d').value       = '3';
  document.getElementById('inp_bobot').value   = '1.00';
  document.getElementById('inp_tp').value      = '2';
  document.getElementById('inp_td').value      = '2';
  document.getElementById('inp_tb').value      = '1.00';
  document.getElementById('md_rencana').value  = '';
  document.getElementById('md_jadwal').value   = '';
  document.getElementById('jadwal_start').value = '';
  document.getElementById('jadwal_end').value = '';
  document.getElementById('md_penanggungjawab').value = defPengelola;
  document.getElementById('btnSimpanDetail').innerHTML = '<i class="fas fa-save"></i> Simpan Detail';
  hitungNilai(); hitungTarget();
  openModal('modalDetailRisiko');
}

// ── Buka modal edit ───────────────────────────────────────────
function editDetailModal(dr) {
  document.getElementById('modalDetailTitle').innerHTML =
    '<i class="fas fa-edit"></i> Edit Detail Risiko — ' + (dr.kode_risiko||'');
  document.getElementById('modalDetailId').value = dr.id;
  document.getElementById('md_unit').value       = dr.unit_kerja||'Balai Besar Laboratorium Kesehatan Lingkungan';
  document.getElementById('md_kode').value       = dr.kode_risiko||'';
  document.getElementById('md_prioritas').value  = dr.prioritas_risiko||'1';
  document.getElementById('md_nama').value       = dr.nama_risiko||'';
  document.getElementById('inp_p').value         = dr.probabilitas;
  document.getElementById('inp_d').value         = dr.dampak;
  if(document.getElementById('inp_bobot')) document.getElementById('inp_bobot').value = dr.bobot;
  document.getElementById('inp_tp').value        = dr.target_p;
  document.getElementById('inp_td').value        = dr.target_d;
  if(document.getElementById('inp_tb')) document.getElementById('inp_tb').value = dr.target_bobot;
  document.getElementById('md_rencana').value    = dr.rencana_penanganan||'';
  // Sync slider labels
  ['p','d','tp','td'].forEach(k => updateSliderLabel(k));
  const jdwl = dr.jadwal_pelaksanaan||'';
  document.getElementById('md_jadwal').value     = jdwl;
  if(jdwl.includes(' s.d. ')) {
    const parts = jdwl.split(' s.d. ');
    document.getElementById('jadwal_start').value = parts[0];
    document.getElementById('jadwal_end').value = parts[1];
  } else {
    document.getElementById('jadwal_start').value = jdwl;
    document.getElementById('jadwal_end').value = '';
  }
  document.getElementById('md_penanggungjawab').value = dr.penanggungjawab||defPengelola;
  document.getElementById('btnSimpanDetail').innerHTML = '<i class="fas fa-save"></i> Update Detail';
  hitungNilai(); hitungTarget();
  openModal('modalDetailRisiko');
}

// ── Jadwal Pelaksanaan Logic ────────────────────────────────────
function updateJadwal() {
  const start = document.getElementById('jadwal_start').value;
  const end = document.getElementById('jadwal_end').value;
  const target = document.getElementById('md_jadwal');
  if (start && end && start !== end && start !== 'Sepanjang Tahun' && end !== 'Sepanjang Tahun') {
    target.value = start + ' s.d. ' + end;
  } else if (start) {
    target.value = start;
  } else {
    target.value = '';
  }
}

// ── Toggle Mode TTD: draw / upload ────────────────────────────
function setTtdMode(key, mode) {
  const drawEl   = document.getElementById('ttdDraw'  + key);
  const uploadEl = document.getElementById('ttdUpload'+ key);
  const btnDraw  = document.getElementById('btnModeDraw'  + key);
  const btnUp    = document.getElementById('btnModeUpload'+ key);
  if (!drawEl || !uploadEl) return;
  if (mode === 'draw') {
    drawEl.style.display   = '';
    uploadEl.style.display = 'none';
    btnDraw?.classList.add('btn-primary');    btnDraw?.classList.remove('btn-outline');
    btnUp?.classList.add('btn-outline');      btnUp?.classList.remove('btn-primary');
  } else {
    drawEl.style.display   = 'none';
    uploadEl.style.display = '';
    btnUp?.classList.add('btn-primary');      btnUp?.classList.remove('btn-outline');
    btnDraw?.classList.add('btn-outline');    btnDraw?.classList.remove('btn-primary');
  }
}

// ── Upload file TTD → base64 → hidden input ───────────────────
function handleUpload(input, hiddenId, statusId, previewId) {
  const file = input.files[0];
  if (!file) return;
  if (file.size > 2 * 1024 * 1024) { alert('Ukuran file maksimal 2MB'); input.value=''; return; }
  if (!file.type.startsWith('image/')) { alert('Hanya file gambar yang diizinkan'); input.value=''; return; }

  const reader = new FileReader();
  reader.onload = function(e) {
    const b64 = e.target.result;
    // Simpan ke hidden input
    const h = document.getElementById(hiddenId);
    if(h) h.value = b64;
    // Update status
    const s = document.getElementById(statusId);
    if(s) s.textContent = '✓ File TTD siap diunggah';
    // Tampilkan preview
    const pDiv = document.getElementById(previewId);
    const pImg = document.getElementById('previewImg' + previewId.replace('preview',''));
    const pName = document.getElementById('previewName'+ previewId.replace('preview',''));
    if(pDiv) pDiv.style.display = '';
    if(pImg) pImg.src = b64;
    if(pName) pName.textContent = file.name + ' (' + (file.size/1024).toFixed(1) + ' KB)';
  };
  reader.readAsDataURL(file);
}

// ── Drag & drop ───────────────────────────────────────────────
function handleDrop(event, key, hiddenId, statusId, previewId) {
  event.preventDefault();
  const file = event.dataTransfer.files[0];
  if (!file) return;
  // Simulasi input file
  const dt = new DataTransfer();
  dt.items.add(file);
  const uploadId = 'upload' + key;
  const inp = document.getElementById(uploadId);
  if(inp) { inp.files = dt.files; handleUpload(inp, hiddenId, statusId, previewId); }
  else { // fallback langsung baca
    const tmpInp = { files: [file] };
    handleUpload(tmpInp, hiddenId, statusId, previewId);
  }
}

// ── Hapus upload ──────────────────────────────────────────────
function clearUpload(key, hiddenId, statusId, previewId) {
  const h = document.getElementById(hiddenId);
  if(h) h.value = '';
  const pDiv = document.getElementById(previewId);
  if(pDiv) pDiv.style.display='none';
  const s = document.getElementById(statusId);
  if(s) s.textContent = 'Dihapus';
  const inp = document.getElementById('upload'+key);
  if(inp) inp.value = '';
}

// ── Canvas TTD multi-canvas ───────────────────────────────────
const canvases = {};
function initCanvas(canvasId, hiddenId, statusId) {
  const c = document.getElementById(canvasId);
  if (!c) return;
  // Skip jika sudah pernah init (cegah double listener)
  if (canvases[canvasId]) {
    // Re-set ukuran saja (modal mungkin baru dibuka)
    const rect = c.getBoundingClientRect();
    if (rect.width > 0) c.width = rect.width;
    c.height = parseInt(c.getAttribute('height')) || 120;
    return;
  }
  const ctx = c.getContext('2d');
  let drawing = false;
  // Tunda set width sampai modal visible, fallback ke attr
  const rect = c.getBoundingClientRect();
  c.width  = rect.width > 0 ? rect.width : (c.offsetWidth > 0 ? c.offsetWidth : 400);
  c.height = parseInt(c.getAttribute('height')) || 120;
  canvases[canvasId] = { ctx, hasData: false, hiddenId, statusId };

  function getPos(e) {
    const r = c.getBoundingClientRect();
    const src = e.touches ? e.touches[0] : e;
    return { x: src.clientX - r.left, y: src.clientY - r.top };
  }
  c.addEventListener('mousedown',  e => { drawing=true; ctx.beginPath(); const p=getPos(e); ctx.moveTo(p.x,p.y); });
  c.addEventListener('mousemove',  e => { if(!drawing) return; const p=getPos(e); ctx.lineTo(p.x,p.y); ctx.strokeStyle='#1e3a5f'; ctx.lineWidth=2; ctx.lineCap='round'; ctx.stroke(); canvases[canvasId].hasData=true; const s=document.getElementById(statusId); if(s) s.textContent='✓ TTD terekam'; });
  c.addEventListener('mouseup',    () => drawing=false);
  c.addEventListener('mouseleave', () => drawing=false);
  c.addEventListener('touchstart', e => { e.preventDefault(); drawing=true; ctx.beginPath(); const p=getPos(e); ctx.moveTo(p.x,p.y); }, {passive:false});
  c.addEventListener('touchmove',  e => { e.preventDefault(); if(!drawing) return; const p=getPos(e); ctx.lineTo(p.x,p.y); ctx.strokeStyle='#1e3a5f'; ctx.lineWidth=2; ctx.lineCap='round'; ctx.stroke(); canvases[canvasId].hasData=true; }, {passive:false});
  c.addEventListener('touchend',   () => drawing=false);
}

// Re-init canvas width saat modal dibuka (fix width=0 saat display:none)
function reinitCanvasOnOpen() {
  ['canvasPemilik:ttdPemilikNew:sigStatusP',
   'canvasPengelola:ttdPengelolaNew:sigStatusG',
   'canvasPemilikEdit:ttdPemilikEdit:spE',
   'canvasPengelolaEdit:ttdPengelolaEdit:sgE'].forEach(s => {
    const [c, h, st] = s.split(':');
    initCanvas(c, h, st);
  });
}

// Init awal (jika canvas sudah visible di halaman ini)
reinitCanvasOnOpen();

// Re-init saat modal dibuka — gunakan MutationObserver atau hook ke openModal
const observer = new MutationObserver(() => {
  document.querySelectorAll('.modal[style*="display: block"], .modal[style*="display:block"]').forEach(m => {
    const canvasesInModal = m.querySelectorAll('canvas.sig-canvas');
    if (canvasesInModal.length > 0) {
      // Re-init width dengan delay singkat agar layout computed
      setTimeout(reinitCanvasOnOpen, 50);
    }
  });
});
observer.observe(document.body, { attributes: true, subtree: true, attributeFilter: ['style'] });

function clearCanvas(canvasId, hiddenId, statusId) {
  const c = document.getElementById(canvasId);
  if(!c) return;
  c.getContext('2d').clearRect(0,0,c.width,c.height);
  if(canvases[canvasId]) canvases[canvasId].hasData = false;
  const h = document.getElementById(hiddenId);
  if(h) h.value = '';
  const s = document.getElementById(statusId);
  if(s) s.textContent = 'TTD dihapus';
}

function saveSigs() {
  ['canvasPemilik:ttdPemilikNew','canvasPengelola:ttdPengelolaNew'].forEach(pair => {
    const [cid, hid] = pair.split(':');
    const c = document.getElementById(cid);
    const h = document.getElementById(hid);
    if(c && h && canvases[cid]?.hasData) h.value = c.toDataURL('image/png');
  });
}
function saveSigsEdit() {
  ['canvasPemilikEdit:ttdPemilikEdit','canvasPengelolaEdit:ttdPengelolaEdit'].forEach(pair => {
    const [cid, hid] = pair.split(':');
    const c = document.getElementById(cid);
    const h = document.getElementById(hid);
    if(c && h && canvases[cid]?.hasData) h.value = c.toDataURL('image/png');
  });
}

// Init & re-init di-handle oleh reinitCanvasOnOpen() + MutationObserver di atas

// ── Tab switch ────────────────────────────────────────────────
function switchTabProfil(id, btn) {
  document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById(id)?.classList.add('active');
  if (btn) {
    btn.classList.add('active');
  } else {
    const selector = id === 'tabDetail' ? '[data-tab="tabDetail"]' : '[data-tab="tabHeader"]';
    document.querySelector(selector)?.classList.add('active');
  }
  const newProfileButton = document.getElementById('btnProfilBaruHeader');
  if (newProfileButton) newProfileButton.style.display = id === 'tabHeader' ? '' : 'none';
}

function bukaTabDetailProfil() {
  const btn = document.querySelector('[data-tab="tabDetail"]') || document.querySelectorAll('.tab-btn')[0];
  switchTabProfil('tabDetail', btn);
  const target = document.getElementById('tabDetail');
  if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function bukaTabHeaderProfil() {
  const btn = document.querySelector('[data-tab="tabHeader"]') || document.querySelectorAll('.tab-btn')[1];
  switchTabProfil('tabHeader', btn);
  const target = document.getElementById('tabHeader');
  if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ── Salin header dari KKPR ────────────────────────────────────
async function loadKkprListForCopy() {
  const sel = document.getElementById('copyKkprSelect');
  if (!sel) return;
  try {
    const r = await fetch((window.APP_URL||'') + '/api.php/list_for_copy/kkpr', {
      headers: {'X-CSRF-Token': window.CSRF_TOKEN||''}, credentials:'same-origin'
    });
    const j = await r.json();
    if (j && j.data) {
      while (sel.options.length > 1) sel.remove(1);
      j.data.forEach(d => {
        const parts = [];
        const creator = (d.nama_creator || '').trim();
        const username = (d.username_creator || '').trim();
        const pengelola = (d.nama_pengelola_risiko || '').trim();

        let unitName = (username.toLowerCase() === 'adum') ? 'ADUM' : (creator || username);
        if (unitName) parts.push(unitName);
        if (pengelola && pengelola.toLowerCase() !== (creator||'').toLowerCase() && pengelola.toLowerCase() !== (username||'').toLowerCase()) {
          parts.push(pengelola);
        }

        const tag = parts.join(' — ');
        const o = document.createElement('option');
        o.value = d.id;
        o.textContent = 'Tahun ' + d.tahun + ' — ' + (d.unit_pemilik_risiko || '') + (tag ? ' (' + tag + ')' : '');
        sel.appendChild(o);
      });
    }
  } catch(e) { console.warn('loadKkprListForCopy', e); }
}
async function copyHeaderToProfil() {
  const sel = document.getElementById('copyKkprSelect');
  if (!sel || !sel.value) { showToast('Pilih KKPR dulu','warning'); return; }
  try {
    const r = await fetch((window.APP_URL||'') + '/api.php/copy_header/kkpr/' + sel.value, {
      headers: {'X-CSRF-Token': window.CSRF_TOKEN||''}, credentials:'same-origin'
    });
    const j = await r.json();
    if (!j.data) { showToast(j.error||'Gagal salin','error'); return; }
    const d = j.data;
    const set = (n,v) => { const el=document.querySelector('#modalNewProfil [name="'+n+'"]'); if(el&&v!==null) el.value=v; };
    set('tahun', d.tahun); set('unit_pemilik_risiko', d.unit_pemilik_risiko);
    set('nama_pemilik_risiko', d.nama_pemilik_risiko); set('nip_pemilik_risiko', d.nip_pemilik_risiko); set('nama_pengelola_risiko', d.nama_pengelola_risiko); set('nip_pengelola_risiko', d.nip_pengelola_risiko);
    set('tujuan', d.tujuan); set('sasaran', d.sasaran);
    set('indikator_kinerja', d.indikator_kinerja); set('target', d.target);
    set('program', d.program); set('kegiatan', d.kegiatan);
    set('tgl_penilaian', d.tgl_penilaian); set('periode_risiko', d.periode_risiko);
    set('nama_ttd_pemilik', d.nama_ttd_pemilik); set('nip_ttd_pemilik', d.nip_ttd_pemilik);
    set('nama_ttd_pengelola', d.nama_ttd_pengelola); set('nip_ttd_pengelola', d.nip_ttd_pengelola);
    if (d.ttd_pemilik) { const h=document.getElementById('ttdPemilikNew'); if(h) h.value=d.ttd_pemilik; const s=document.getElementById('sigStatusP'); if(s) s.textContent='✓ TTD disalin dari KKPR'; }
    if (d.ttd_pengelola) { const h=document.getElementById('ttdPengelolaNew'); if(h) h.value=d.ttd_pengelola; const s=document.getElementById('sigStatusG'); if(s) s.textContent='✓ TTD disalin dari KKPR'; }
    showToast('Header disalin dari KKPR. Periksa lalu simpan.','success');
  } catch(e) { showToast('Gagal: '+e.message,'error'); }
}
loadKkprListForCopy();

const prState = { page: 1, lastQuery: '', lastLimit: 10 };
function filterTableDetailRisiko() {
  const query = (document.getElementById('searchDetailRisiko')?.value || '').toLowerCase();
  const limit = parseInt(document.getElementById('limitDetailRisiko')?.value || 10, 10);
  if (query !== prState.lastQuery || limit !== prState.lastLimit) {
    prState.page = 1;
    prState.lastQuery = query;
    prState.lastLimit = limit;
  }
  const allRows = [...document.querySelectorAll('#tableDetailRisiko tbody tr')];
  
  const visible = allRows.filter(row => {
    if(row.querySelector('.empty-state') || row.id === 'emptySearchProfil') return false;
    const text = row.textContent.toLowerCase();
    if (query && !text.includes(query)) return false;
    return true;
  });

  const total = visible.length;
  const pages = Math.max(1, Math.ceil(total / limit));
  if (prState.page > pages) prState.page = pages;
  const start = (prState.page - 1) * limit;

  allRows.forEach(row => { if (row.id !== 'emptySearchProfil') row.style.display = 'none'; });
  visible.slice(start, start + limit).forEach(row => { row.style.display = ''; });

  const infoEl = document.getElementById('profilRisikoPageInfo');
  if(infoEl) {
    infoEl.textContent = total === 0 ? 'Tidak ada data' : 'Menampilkan ' + (start + 1) + '–' + Math.min(start + limit, total) + ' dari ' + total + ' data';
  }

  const pagesEl = document.getElementById('profilRisikoPages');
  if(pagesEl) {
    pagesEl.innerHTML = '';
    const mkBtn = (html, page, disabled, active, title) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'page-btn' + (active ? ' active' : '') + (disabled ? ' disabled' : '');
      b.innerHTML = html;
      b.disabled = disabled;
      if (title) b.title = title;
      if (!disabled && !active) {
        b.onclick = () => {
          prState.page = page;
          filterTableDetailRisiko();
          document.getElementById('tableDetailRisiko')?.scrollIntoView({behavior:'smooth', block:'nearest'});
        };
      }
      pagesEl.appendChild(b);
    };

    mkBtn('<i class=\"fas fa-angles-left\"></i>', 1, prState.page <= 1, false, 'Halaman Pertama');
    mkBtn('<i class=\"fas fa-chevron-left\"></i>', prState.page - 1, prState.page <= 1, false, 'Halaman Sebelumnya');
    
    let winStart = Math.max(1, prState.page - 2);
    let winEnd = Math.min(pages, winStart + 4);
    if (winEnd - winStart < 4) {
      winStart = Math.max(1, winEnd - 4);
    }
    if (winStart > 1) {
      mkBtn('1', 1, false, prState.page === 1);
      if (winStart > 2) {
        const dots = document.createElement('span');
        dots.className = 'page-btn disabled';
        dots.textContent = '...';
        dots.style.border = 'none';
        dots.style.background = 'transparent';
        pagesEl.appendChild(dots);
      }
    }
    for (let p = winStart; p <= winEnd; p++) {
      mkBtn(String(p), p, false, p === prState.page);
    }
    if (winEnd < pages) {
      if (winEnd < pages - 1) {
        const dots = document.createElement('span');
        dots.className = 'page-btn disabled';
        dots.textContent = '...';
        dots.style.border = 'none';
        dots.style.background = 'transparent';
        pagesEl.appendChild(dots);
      }
      mkBtn(String(pages), pages, false, prState.page === pages);
    }
    mkBtn('<i class=\"fas fa-chevron-right\"></i>', prState.page + 1, prState.page >= pages, false, 'Halaman Berikutnya');
    mkBtn('<i class=\"fas fa-angles-right\"></i>', pages, prState.page >= pages, false, 'Halaman Terakhir');
  }

  let emptyRow = document.getElementById('emptySearchProfil');
  if (total === 0 && allRows.length > 0) {
    if (!emptyRow) {
      emptyRow = document.createElement('tr');
      emptyRow.id = 'emptySearchProfil';
      emptyRow.innerHTML = `<td colspan=\"25\"><div class=\"empty-state\" style=\"padding:24px\"><i class=\"fas fa-search\"></i><p>Pencarian \"<b>${query}</b>\" tidak ditemukan.</p></div></td>`;
      document.querySelector('#tableDetailRisiko tbody').appendChild(emptyRow);
    } else {
      emptyRow.style.display = '';
      emptyRow.innerHTML = `<td colspan=\"25\"><div class=\"empty-state\" style=\"padding:24px\"><i class=\"fas fa-search\"></i><p>Pencarian \"<b>${query}</b>\" tidak ditemukan.</p></div></td>`;
    }
  } else if (emptyRow) {
    emptyRow.style.display = 'none';
  }
}
setTimeout(() => filterTableDetailRisiko(), 100);

// ── Portal modal persetujuan ke <body> ─────────────────────────
// Bug containing-block: <main class="content"> punya animasi
// @keyframes fadeInContent (transform: translateY) → .content jadi
// containing block untuk position:fixed → modal-overlay terbuka tapi
// terpotong/tak terlihat (gejala: "klik tombol tak ada popup"). Sama
// persis dengan fix yang sudah dipakai di modules/saran_mitigasi.php.
// Memindahkan modal langsung jadi child <body> membuat position:fixed
// selalu relatif ke viewport.
(function() {
  ['modalKirimPersetujuan', 'modalTolakPersetujuan'].forEach(function(id) {
    var m = document.getElementById(id);
    if (m && m.parentElement !== document.body) {
      document.body.appendChild(m);
    }
  });
})();

// ── Revisi Profil (Pimpinan) via SweetAlert2 ──────────────────
// Modal klasik (modalTolakPersetujuan) tidak merespons di beberapa layout;
// Swal sudah dimuat global di header & tidak bergantung CSS modal, jadi andal.
function revisiProfil(id) {
  Swal.fire({
    title: 'Revisi Profil Risiko',
    html: '<p style="font-size:.85rem;color:var(--text-muted);margin-bottom:8px">Tulis catatan revisi untuk dikembalikan ke Risk Manager.</p>',
    input: 'textarea',
    inputPlaceholder: 'Contoh: Lengkapi rencana penanganan risiko X dan target residualnya...',
    showCancelButton: true,
    confirmButtonText: '<i class="fas fa-paper-plane"></i> Kirim Revisi',
    confirmButtonColor: '#dc2626',
    cancelButtonText: 'Batal',
    inputValidator: function(v) { if (!v || !v.trim()) return 'Catatan revisi wajib diisi'; }
  }).then(function(result) {
    if (!result.isConfirmed) return;
    var f = document.createElement('form');
    f.method = 'POST';
    f.action = '<?= APP_URL ?>/?page=profil_risiko';
    function add(name, value) { var i = document.createElement('input'); i.type='hidden'; i.name=name; i.value=value; f.appendChild(i); }
    add('csrf_token', '<?= csrfToken() ?>');
    add('aksi', 'reject_profil');
    add('id', String(id));
    add('catatan_revisi', result.value);
    document.body.appendChild(f);
    f.submit();
  });
}

// ── Auto-buka modal Detail Risiko untuk profil yang baru dibuat ──
(function() {
  var params = new URLSearchParams(window.location.search);
  if (params.get('open_detail') === '1') {
    // Bersihkan parameter dari URL agar modal tidak terbuka lagi saat refresh
    params.delete('open_detail');
    var clean = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
    window.history.replaceState({}, '', clean);
    setTimeout(function() {
      var selKode = document.getElementById('md_kode');
      if (!selKode || selKode.options.length <= 1) return; // belum ada risiko master
      try { bukaModalDetail(); } catch (e) { openModal('modalDetailRisiko'); }
    }, 500);
  }
})();
</script>

<style>
@media(max-width:900px){ .profil-grid{ grid-template-columns:1fr!important } }

/* ── Hero Profil Risiko: copy kiri + tombol kanan sejajar (bukan menumpuk) ── */
.risiko-hero.profil-risiko-hero{
  display:grid;
  grid-template-columns:minmax(0,1fr) auto;
  align-items:center;
  column-gap:24px;
  row-gap:16px;
}
.risiko-hero.profil-risiko-hero .risiko-hero-copy{
  grid-column:1;
  max-width:520px;
}
.risiko-hero.profil-risiko-hero .profil-hero-tools{
  grid-column:2;
  justify-self:end;
  max-width:100%;
}
.risiko-hero.profil-risiko-hero .stats-grid{
  grid-column:1 / -1;
}
@media(max-width:900px){
  .risiko-hero.profil-risiko-hero{ grid-template-columns:1fr; align-items:start; }
  .risiko-hero.profil-risiko-hero .profil-hero-tools{ grid-column:1; justify-self:start; }
}
</style>


