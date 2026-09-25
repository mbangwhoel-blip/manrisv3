<?php
/**
 * MODUL KERTAS KERJA PENILAIAN RISIKO (KKPR)
 * Format: UPR-T.II Kemenkes
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$db = getDB();

// ── Helper warna tingkat ──────────────────────────────────────
function kkprColor(string $t): string {
    return match($t) {
        'Sangat Tinggi' => '#dc2626',
        'Tinggi'        => '#f97316',
        'Sedang'        => '#000',
        'Rendah'        => '#22c55e',
        default         => '#3b82f6',
    };
}
function kkprBg(string $t): string {
    return match($t) {
        'Sangat Tinggi' => '#fee2e2',
        'Tinggi'        => '#fef3c7',
        'Sedang'        => '#fefce8',
        'Rendah'        => '#dcfce7',
        default         => '#dbeafe',
    };
}

$workflowNotice = 'Tahap 3: KKPR adalah kertas kerja resmi. Gunakan hasil penilaian dari Profil Risiko sebagai acuan; jangan mengubah P/D master di Identifikasi Risiko.';
$workflowNotice .= ' Hanya risiko yang sudah disetujui yang dapat dimasukkan ke KKPR.';

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { setFlash('error','Token tidak valid'); header('Location: '.APP_URL.'/?page=kkpr'); exit; }
    requireRole('Admin','Risk Manager');
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'simpan_header') {
        $id  = (int)($_POST['id'] ?? 0);
        $uid = (int)$_SESSION['user_id'];
        $f = [
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
        // Validasi TTD via GD re-encode (anti malicious base64)
        if (!empty($f['ttd_pemilik']) && str_starts_with($f['ttd_pemilik'], 'data:image')) {
            $validated = saveTtdBase64($f['ttd_pemilik'], 'kkpr_pemilik');
            if (!$validated['valid']) { setFlash('error', $validated['error'] ?? 'Tanda tangan pemilik tidak valid.'); header('Location: '.APP_URL.'/?page=kkpr'); exit; }
            $f['ttd_pemilik'] = $validated['path'];
        }
        if (!empty($f['ttd_pengelola']) && str_starts_with($f['ttd_pengelola'], 'data:image')) {
            $validated = saveTtdBase64($f['ttd_pengelola'], 'kkpr_pengelola');
            if (!$validated['valid']) { setFlash('error', $validated['error'] ?? 'Tanda tangan pengelola tidak valid.'); header('Location: '.APP_URL.'/?page=kkpr'); exit; }
            $f['ttd_pengelola'] = $validated['path'];
        }
        if ($id > 0) {
            if (!ownsKkpr($db, $id)) {
                setFlash('error', 'Anda tidak memiliki hak untuk mengubah KKPR ini.');
                header('Location: '.APP_URL.'/?page=kkpr'); exit;
            }
            $s = $db->prepare('UPDATE kkpr_header SET tahun=?,unit_pemilik_risiko=?,nama_pemilik_risiko=?,nip_pemilik_risiko=?,nama_pengelola_risiko=?,nip_pengelola_risiko=?,tujuan=?,sasaran=?,indikator_kinerja=?,target=?,program=?,kegiatan=?,tgl_penilaian=?,periode_risiko=?,tgl_update=?,nama_ttd_pemilik=?,nip_ttd_pemilik=?,nama_ttd_pengelola=?,nip_ttd_pengelola=?,ttd_pemilik=?,ttd_pengelola=? WHERE id=?');
            $s->bind_param('sssssssssssssssssssssi',$f['tahun'],$f['unit_pemilik_risiko'],$f['nama_pemilik_risiko'],$f['nip_pemilik_risiko'],$f['nama_pengelola_risiko'],$f['nip_pengelola_risiko'],$f['tujuan'],$f['sasaran'],$f['indikator_kinerja'],$f['target'],$f['program'],$f['kegiatan'],$f['tgl_penilaian'],$f['periode_risiko'],$f['tgl_update'],$f['nama_ttd_pemilik'],$f['nip_ttd_pemilik'],$f['nama_ttd_pengelola'],$f['nip_ttd_pengelola'],$f['ttd_pemilik'],$f['ttd_pengelola'],$id);
            $s->execute(); $s->close();
            setFlash('success','KKPR berhasil diperbarui');
            header('Location: '.APP_URL.'/?page=kkpr&id='.$id.'&tab=detail'); exit;
        } else {
            $s = $db->prepare('INSERT INTO kkpr_header (tahun,unit_pemilik_risiko,nama_pemilik_risiko,nip_pemilik_risiko,nama_pengelola_risiko,nip_pengelola_risiko,tujuan,sasaran,indikator_kinerja,target,program,kegiatan,tgl_penilaian,periode_risiko,tgl_update,nama_ttd_pemilik,nip_ttd_pemilik,nama_ttd_pengelola,nip_ttd_pengelola,ttd_pemilik,ttd_pengelola,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            // 22 vars: 21×s + 1×i (created_by)
            $s->bind_param('sssssssssssssssssssssi',$f['tahun'],$f['unit_pemilik_risiko'],$f['nama_pemilik_risiko'],$f['nip_pemilik_risiko'],$f['nama_pengelola_risiko'],$f['nip_pengelola_risiko'],$f['tujuan'],$f['sasaran'],$f['indikator_kinerja'],$f['target'],$f['program'],$f['kegiatan'],$f['tgl_penilaian'],$f['periode_risiko'],$f['tgl_update'],$f['nama_ttd_pemilik'],$f['nip_ttd_pemilik'],$f['nama_ttd_pengelola'],$f['nip_ttd_pengelola'],$f['ttd_pemilik'],$f['ttd_pengelola'],$uid);
            $s->execute(); $newId = $db->insert_id; $s->close();
            logAktivitas('CREATE','kkpr',$newId,'Buat KKPR '.$f['tahun']);
            setFlash('success','KKPR berhasil dibuat! Silakan tambah data risiko.');
            header('Location: '.APP_URL.'/?page=kkpr&id='.$newId.'&tab=detail'); exit;
        }
    }

    if ($aksi === 'simpan_risiko') {
        $idKkpr   = (int)($_POST['id_kkpr'] ?? 0);
        $idRisiko = (int)($_POST['id_risiko_row'] ?? 0);
        $kodeInput = trim($_POST['kode_risiko'] ?? '');
        if ($kodeInput !== '') {
            $approved = $db->prepare("SELECT id FROM risiko WHERE kode_risiko=? AND deleted_at IS NULL AND (approval_status='approved' OR approval_status IS NULL) LIMIT 1");
            $approved->bind_param('s', $kodeInput); $approved->execute();
            if (!$approved->get_result()->fetch_assoc()) {
                $approved->close();
                setFlash('error', 'Risiko belum disetujui atau sudah tidak aktif sehingga belum dapat dimasukkan ke KKPR.');
                header('Location: '.APP_URL.'/?page=kkpr&id='.$idKkpr.'&tab=detail'); exit;
            }
            $approved->close();
        }
        $p = (int)($_POST['probabilitas'] ?? 1);
        $d = (int)($_POST['dampak_level'] ?? 1);
        $b = getBobot($p, $d);
        $nilai   = round($p * $d * $b);
        $tingkat = getLevelRisiko((int)$nilai);
        $tp = (int)($_POST['target_p'] ?? 1);
        $td = (int)($_POST['target_d'] ?? 1);
        if (!ownsKkpr($db, $idKkpr)) {
            setFlash('error', 'Anda tidak memiliki hak untuk mengubah KKPR ini.');
            header('Location: '.APP_URL.'/?page=kkpr'); exit;
        }
        if (!validRiskScale($p) || !validRiskScale($d) || !validRiskScale($tp) || !validRiskScale($td)) {
            setFlash('error', 'Probabilitas dan dampak harus bernilai 1 sampai 5.');
            header('Location: '.APP_URL.'/?page=kkpr&id='.$idKkpr.'&tab=detail'); exit;
        }
        $tb = getBobot($tp, $td);
        $tNilai  = round($tp * $td * $tb);
        $tTingkat = getLevelRisiko((int)$tNilai);

        $f = [
            'no_urut'               => (int)($_POST['no_urut'] ?? 1),
            'nama_risiko'           => trim($_POST['nama_risiko'] ?? ''),
            'kode_risiko'           => trim($_POST['kode_risiko'] ?? ''),
            'sebab'                 => trim($_POST['sebab'] ?? ''),
            'sumber'                => normalizeSumberRisiko($_POST['sumber'] ?? 'Eksternal'),
            'c_uc'                  => $_POST['c_uc'] ?? 'UC',
            'dampak_uraian'         => trim($_POST['dampak_uraian'] ?? ''),
            'pengendalian_uraian'   => trim($_POST['pengendalian_uraian'] ?? ''),
            'pengendalian_jenis'    => $_POST['pengendalian_jenis'] ?? '',
            'pengendalian_efektivitas' => $_POST['pengendalian_efektivitas'] ?? '',
            'probabilitas'          => $p,
            'dampak_level'          => $d,
            'bobot'                 => $b,
            'nilai_risiko'          => $nilai,
            'tingkat_risiko'        => $tingkat,
            'prioritas_risiko'      => (int)($_POST['prioritas_risiko'] ?? 0),
            'pilihan_penanganan'    => $nilai <= 9 ? 'Menerima risiko' : 'Mitigasi Risiko',
            'selera_risiko'         => $nilai <= 9 ? 'Dalam batas selera risiko' : 'Diatas batas selera risiko',
            'evaluasi_warna'        => trim($_POST['evaluasi_warna'] ?? ''),
            'rpti_uraian'           => trim($_POST['rpti_uraian'] ?? ''),
            'rpti_jadwal'           => trim($_POST['rpti_jadwal'] ?? ''),
            'target_p'              => $tp,
            'target_d'              => $td,
            'target_bobot'          => $tb,
            'target_nilai'          => $tNilai,
            'target_tingkat'        => $tTingkat,
        ];

        if ($idRisiko > 0) {
            // UPDATE 26 SET + 1 WHERE = 27 vars
            $s = $db->prepare('UPDATE kkpr_risiko SET no_urut=?,nama_risiko=?,kode_risiko=?,sebab=?,sumber=?,c_uc=?,dampak_uraian=?,pengendalian_uraian=?,pengendalian_jenis=?,pengendalian_efektivitas=?,probabilitas=?,dampak_level=?,bobot=?,nilai_risiko=?,tingkat_risiko=?,prioritas_risiko=?,pilihan_penanganan=?,selera_risiko=?,evaluasi_warna=?,rpti_uraian=?,rpti_jadwal=?,target_p=?,target_d=?,target_bobot=?,target_nilai=?,target_tingkat=? WHERE id=? AND id_kkpr=?');
            $s->bind_param(
                'isssssssssiiddsisssssiiddsii',
                $f['no_urut'],$f['nama_risiko'],$f['kode_risiko'],$f['sebab'],
                $f['sumber'],$f['c_uc'],$f['dampak_uraian'],$f['pengendalian_uraian'],
                $f['pengendalian_jenis'],$f['pengendalian_efektivitas'],
                $f['probabilitas'],$f['dampak_level'],$f['bobot'],$f['nilai_risiko'],
                $f['tingkat_risiko'],$f['prioritas_risiko'],
                $f['pilihan_penanganan'],$f['selera_risiko'],$f['evaluasi_warna'],
                $f['rpti_uraian'],$f['rpti_jadwal'],
                $f['target_p'],$f['target_d'],$f['target_bobot'],$f['target_nilai'],
                $f['target_tingkat'],$idRisiko,$idKkpr
            );
        } else {
            // INSERT id_kkpr + 26 fields = 27 vars
            $s = $db->prepare('INSERT INTO kkpr_risiko (id_kkpr,no_urut,nama_risiko,kode_risiko,sebab,sumber,c_uc,dampak_uraian,pengendalian_uraian,pengendalian_jenis,pengendalian_efektivitas,probabilitas,dampak_level,bobot,nilai_risiko,tingkat_risiko,prioritas_risiko,pilihan_penanganan,selera_risiko,evaluasi_warna,rpti_uraian,rpti_jadwal,target_p,target_d,target_bobot,target_nilai,target_tingkat) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $s->bind_param(
                'iisssssssssiiddsisssssiidds',
                $idKkpr,$f['no_urut'],$f['nama_risiko'],$f['kode_risiko'],$f['sebab'],
                $f['sumber'],$f['c_uc'],$f['dampak_uraian'],$f['pengendalian_uraian'],
                $f['pengendalian_jenis'],$f['pengendalian_efektivitas'],
                $f['probabilitas'],$f['dampak_level'],$f['bobot'],$f['nilai_risiko'],
                $f['tingkat_risiko'],$f['prioritas_risiko'],
                $f['pilihan_penanganan'],$f['selera_risiko'],$f['evaluasi_warna'],
                $f['rpti_uraian'],$f['rpti_jadwal'],
                $f['target_p'],$f['target_d'],$f['target_bobot'],$f['target_nilai'],
                $f['target_tingkat']
            );
        }
        $s->execute(); $s->close();
        setFlash('success','Data risiko berhasil disimpan');
        header('Location: '.APP_URL.'/?page=kkpr&id='.$idKkpr.'&tab=detail'); exit;
    }

    if ($aksi === 'hapus_risiko') {
        $idR = (int)($_POST['id_risiko_row'] ?? 0);
        $idK = (int)($_POST['id_kkpr'] ?? 0);
        if (!ownsKkpr($db, $idK)) {
            setFlash('error', 'Anda tidak memiliki hak untuk menghapus data KKPR ini.');
            header('Location: '.APP_URL.'/?page=kkpr'); exit;
        }
        $s = $db->prepare('DELETE FROM kkpr_risiko WHERE id=? AND id_kkpr=?');
        $s->bind_param('ii', $idR, $idK); $s->execute(); $s->close();
        setFlash('success','Data risiko dihapus');
        header('Location: '.APP_URL.'/?page=kkpr&id='.$idK.'&tab=detail'); exit;
    }

    if ($aksi === 'hapus_kkpr') {
        $id = (int)($_POST['id'] ?? 0);
        if (!hasRole('Admin', 'Pimpinan')) {
            $sCheck = $db->prepare("SELECT created_by FROM kkpr_header WHERE id=?");
            $sCheck->bind_param("i", $id); $sCheck->execute();
            $rowCheck = $sCheck->get_result()->fetch_assoc(); $sCheck->close();
            if (!$rowCheck || $rowCheck['created_by'] != $_SESSION['user_id']) {
                setFlash('error', 'Anda tidak memiliki hak untuk menghapus KKPR ini');
                header('Location: '.APP_URL.'/?page=kkpr'); exit;
            }
        }
        $db->query("DELETE FROM kkpr_header WHERE id=$id");
        logAktivitas('DELETE','kkpr',$id,'Hapus KKPR ID '.$id);
        setFlash('success','KKPR dihapus');
        header('Location: '.APP_URL.'/?page=kkpr'); exit;
    }

    if ($aksi === 'import_batch') {
        $idKkpr = (int)($_POST['id_kkpr'] ?? 0);
        $batch  = json_decode($_POST['batch_data'] ?? '[]', true);
        if ($idKkpr <= 0 || !is_array($batch) || empty($batch)) {
            setFlash('error', 'Data impor tidak valid');
            header('Location: '.APP_URL.'/?page=kkpr&id='.$idKkpr.'&tab=detail'); exit;
        }
        if (!ownsKkpr($db, $idKkpr)) {
            setFlash('error', 'Anda tidak memiliki hak untuk mengimpor ke KKPR ini.');
            header('Location: '.APP_URL.'/?page=kkpr'); exit;
        }
        $existing = [];
        $exQ = $db->prepare('SELECT kode_risiko FROM kkpr_risiko WHERE id_kkpr=?');
        $exQ->bind_param('i', $idKkpr); $exQ->execute();
        $exR = $exQ->get_result();
        while ($ex = $exR->fetch_assoc()) $existing[$ex['kode_risiko']] = true;
        $exQ->close();

        $inserted = 0; $skipped = 0;

        // Lookup sebab & dampak dari master risiko (tabel identifikasi).
        // Detail Profil Risiko adalah snapshot yang sah; status master bisa berubah
        // setelah detail profil dibuat, sehingga tidak boleh membuat impor terlewat.
        $masterLookup = [];
        $mkode = array_filter(array_map(fn($r) => trim($r['kode_risiko'] ?? ''), $batch));
        if (!empty($mkode)) {
            $placeholders = implode(',', array_fill(0, count($mkode), '?'));
            $lk = $db->prepare("SELECT kode_risiko, penyebab, dampak, sumber FROM risiko WHERE kode_risiko IN ($placeholders)");
            $lk->bind_param(str_repeat('s', count($mkode)), ...$mkode);
            $lk->execute();
            $lr = $lk->get_result();
            while ($row = $lr->fetch_assoc()) $masterLookup[$row['kode_risiko']] = $row;
            $lk->close();
        }

        $insSql = 'INSERT INTO kkpr_risiko (id_kkpr,no_urut,nama_risiko,kode_risiko,sebab,sumber,c_uc,dampak_uraian,pengendalian_uraian,pengendalian_jenis,pengendalian_efektivitas,probabilitas,dampak_level,bobot,nilai_risiko,tingkat_risiko,prioritas_risiko,pilihan_penanganan,selera_risiko,evaluasi_warna,rpti_uraian,rpti_jadwal,target_p,target_d,target_bobot,target_nilai,target_tingkat) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $stmt = $db->prepare($insSql);
        foreach ($batch as $r) {
            $kode = trim($r['kode_risiko'] ?? '');
            if ($kode !== '' && isset($existing[$kode])) { $skipped++; continue; }
            $p = (int)($r['probabilitas'] ?? 1);
            $d = (int)($r['dampak'] ?? 1);
            $b = (float)($r['bobot'] ?? 1);
            $nilai = round($p * $d * $b, 2);
            $tingkat = getLevelRisiko((int)round($nilai));
            $tp = (int)($r['target_p'] ?? 1);
            $td = (int)($r['target_d'] ?? 1);
            $tb = (float)($r['target_bobot'] ?? 1);
            $tNilai = round($tp * $td * $tb, 2);
            $tTingkat = getLevelRisiko((int)round($tNilai));
            $no = (int)($r['no_urut'] ?? 0);
            $nama = $r['nama_risiko'] ?? '';
            // Ambil sebab & dampak dari master risiko (Identifikasi Risiko)
            $master = $masterLookup[$kode] ?? null;
            $sebab = $master['penyebab'] ?? '';
            $dampakU = $master['dampak'] ?? '';
            $sumber = normalizeSumberRisiko($master['sumber'] ?? ''); $cuc = 'UC';
            $pengU = ''; $pengJ = ''; $pengE = '';
            $prio = (int)($r['prioritas_risiko'] ?? 0);
            $pilPen = '';
            $seleraImp = $nilai <= 9 ? 'Dalam batas selera risiko' : 'Diatas batas selera risiko';
            $evalW = '';
            $rptiU = $r['rencana_penanganan'] ?? '';
            $rptiJ = $r['jadwal_pelaksanaan'] ?? '';
            $stmt->bind_param('iisssssssssiiddsisssssiidds',
                $idKkpr,$no,$nama,$kode,$sebab,$sumber,$cuc,$dampakU,$pengU,$pengJ,$pengE,
                $p,$d,$b,$nilai,$tingkat,$prio,$pilPen,$seleraImp,$evalW,$rptiU,$rptiJ,
                $tp,$td,$tb,$tNilai,$tTingkat
            );
            $stmt->execute();
            $existing[$kode] = true;
            $inserted++;
        }
        $stmt->close();
        logAktivitas('IMPORT','kkpr',$idKkpr,"Impor $inserted risiko dari Profil (skip $skipped)");
        setFlash('success', $inserted.' risiko diimpor dari Profil'.($skipped>0 ? ', '.$skipped.' dilewati (duplikat)' : ''));
        header('Location: '.APP_URL.'/?page=kkpr&id='.$idKkpr.'&tab=detail'); exit;
    }

    if ($aksi === 'kirim_persetujuan_kkpr') {
        requireRole('Risk Manager');
        $id = (int)($_POST['id'] ?? 0);
        if (!ownsRecord($db, 'kkpr_header', $id)) {
            setFlash('error', 'Anda tidak berhak mengajukan persetujuan KKPR ini.');
            header('Location: '.APP_URL.'/?page=kkpr'); exit;
        }
        $s = $db->prepare("UPDATE kkpr_header SET status_kkpr='Menunggu Persetujuan' WHERE id=?");
        $s->bind_param('i', $id); $s->execute(); $s->close();
        logAktivitas('UPDATE', 'kkpr', $id, 'Mengajukan persetujuan KKPR');
        setFlash('success', 'KKPR berhasil diajukan untuk persetujuan Pimpinan.');
        header('Location: '.APP_URL.'/?page=kkpr&id='.$id); exit;
    }

    if ($aksi === 'approve_kkpr') {
        requireRole('Pimpinan');
        $id = (int)($_POST['id'] ?? 0);
        $uid = (int)$_SESSION['user_id'];
        $s = $db->prepare("UPDATE kkpr_header SET status_kkpr='Disetujui', approved_by_kkpr=?, approved_at_kkpr=NOW() WHERE id=?");
        $s->bind_param('ii', $uid, $id); $s->execute(); $s->close();
        
        $q = $db->query("SELECT created_by, tahun FROM kkpr_header WHERE id=$id");
        if ($q && $r = $q->fetch_assoc()) {
            notifikasi((int)$r['created_by'], 'status_change', 'KKPR Disetujui', 'KKPR tahun '.$r['tahun'].' telah disetujui oleh Pimpinan.', APP_URL.'/?page=kkpr&id='.$id);
        }
        
        logAktivitas('UPDATE', 'kkpr', $id, 'Menyetujui KKPR');
        setFlash('success', 'KKPR berhasil disetujui.');
        header('Location: '.APP_URL.'/?page=kkpr&id='.$id); exit;
    }

    if ($aksi === 'reject_kkpr') {
        requireRole('Pimpinan');
        $id = (int)($_POST['id'] ?? 0);
        $catatan = trim($_POST['catatan_revisi'] ?? '');
        $s = $db->prepare("UPDATE kkpr_header SET status_kkpr='Revisi', catatan_revisi_kkpr=? WHERE id=?");
        $s->bind_param('si', $catatan, $id); $s->execute(); $s->close();
        
        $q = $db->query("SELECT created_by, tahun FROM kkpr_header WHERE id=$id");
        if ($q && $r = $q->fetch_assoc()) {
            notifikasi((int)$r['created_by'], 'status_change', 'KKPR Direvisi', 'KKPR tahun '.$r['tahun'].' dikembalikan oleh Pimpinan dengan catatan: '.$catatan, APP_URL.'/?page=kkpr&id='.$id);
        }
        
        logAktivitas('UPDATE', 'kkpr', $id, 'Menolak/revisi KKPR');
        setFlash('success', 'KKPR dikembalikan untuk direvisi.');
        header('Location: '.APP_URL.'/?page=kkpr&id='.$id); exit;
    }
}

// ── Data ──────────────────────────────────────────────────────
$activeId  = (int)($_GET['id'] ?? 0);
$activeTab = $_GET['tab'] ?? 'detail';
$fTahun    = trim((string)($_GET['tahun'] ?? ''));

$kkprRow  = null;
$rows     = [];
$editRow  = null;

if ($activeId > 0) {
    $scope = canAccessAllRecords() ? '' : ' AND created_by = ?';
    $s = $db->prepare('SELECT * FROM kkpr_header WHERE id=?' . $scope);
    if ($scope) { $uid = (int)$_SESSION['user_id']; $s->bind_param('ii',$activeId, $uid); }
    else $s->bind_param('i',$activeId);
    $s->execute();
    $kkprRow = $s->get_result()->fetch_assoc(); $s->close();

    if ($kkprRow) {
        $s2 = $db->prepare("SELECT * FROM kkpr_risiko WHERE id_kkpr=? ORDER BY SUBSTRING_INDEX(kode_risiko, '.', 1) ASC, CAST(SUBSTRING_INDEX(kode_risiko, '.', -1) AS UNSIGNED) ASC, kode_risiko ASC");
        $s2->bind_param('i',$activeId); $s2->execute();
        $rows = $s2->get_result()->fetch_all(MYSQLI_ASSOC); $s2->close();

        $editId = (int)($_GET['edit'] ?? 0);
        if ($editId > 0) {
            $se = $db->prepare('SELECT * FROM kkpr_risiko WHERE id=? AND id_kkpr=?');
            $se->bind_param('ii',$editId, $activeId); $se->execute();
            $editRow = $se->get_result()->fetch_assoc(); $se->close();
            $activeTab = 'detail';
        }
    }
}

$activeTahun = (string)($kkprRow['tahun'] ?? ($fTahun !== '' ? $fTahun : date('Y')));
$tahunList   = getDaftarTahun($db, 'kkpr_header', [$activeTahun]);

$kkpr_cond = hasRole('Admin', 'Pimpinan') ? "WHERE 1=1" : "WHERE h.created_by = " . (int)$_SESSION['user_id'];
if ($fTahun !== '') {
    $kkpr_cond .= " AND h.tahun = '" . $db->real_escape_string($fTahun) . "'";
}
$kkprList  = $db->query("SELECT h.id,h.tahun,h.unit_pemilik_risiko,h.nama_pemilik_risiko, u.nama AS nama_creator, (SELECT COUNT(*) FROM kkpr_risiko r WHERE r.id_kkpr = h.id) AS jml_detail FROM kkpr_header h LEFT JOIN users u ON h.created_by = u.id $kkpr_cond ORDER BY h.tahun DESC, h.id DESC")->fetch_all(MYSQLI_ASSOC) ?: [];

// Master risiko untuk dropdown auto-fill (semua yang aktif)
$risikoCond = (function_exists('canAccessAllRecords') && canAccessAllRecords()) || hasRole('Admin', 'Pimpinan') ? "" : " AND id_user_input = " . (int)$_SESSION['user_id'];
$risikoMaster = $db->query("SELECT kode_risiko, nama_risiko, deskripsi, penyebab, dampak, sumber FROM risiko WHERE deleted_at IS NULL AND (approval_status='approved' OR approval_status IS NULL) $risikoCond ORDER BY SUBSTRING_INDEX(kode_risiko, '.', 1) ASC, CAST(SUBSTRING_INDEX(kode_risiko, '.', -1) AS UNSIGNED) ASC, kode_risiko ASC")->fetch_all(MYSQLI_ASSOC) ?: [];

// ── Export Excel ──────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'excel' && $activeId > 0 && $kkprRow) {
    $periode = $_GET['periode'] ?? 'tahunan';
    if (!in_array($periode, ['tahunan','tw1','tw2','tw3','tw4'], true)) $periode = 'tahunan';
    $triwulanRomawi = ['I','II','III','IV'];
    $periodeLabel = $periode === 'tahunan'
        ? 'Laporan Tahunan'
        : 'Laporan Triwulan ' . $triwulanRomawi[(int)substr($periode, 2) - 1];
    $GLOBALS['EXPORT_PERIODE'] = $periodeLabel;

    $headers = ['No', 'Kode Risiko', 'Nama Risiko', 'Sebab', 'Sumber Risiko', 'C/UC', 'Dampak', 'Pengendalian Uraian', 'Efektif', 'Tidak', 'P', 'D', 'Bobot', 'Nilai', 'Tingkat', 'Prioritas', 'Selera Risiko', 'Pilihan Penanganan', 'RPR Uraian', 'RPR Jadwal', 'Target P', 'Target D', 'Target Bobot', 'Target Nilai', 'Target Tingkat'];
    $excelRows = [];
    foreach ($rows as $i => $r) {
        $excelRows[] = [
            $i + 1,
            $r['kode_risiko'] ?? '',
            $r['nama_risiko'] ?? '',
            $r['sebab'] ?? '',
            normalizeSumberRisiko($r['sumber'] ?? ''),
            $r['c_uc'] ?? '',
            $r['dampak_uraian'] ?? '',
            $r['pengendalian_uraian'] ?? '',
            $r['pengendalian_jenis'] ?? '',
            $r['pengendalian_efektivitas'] ?? '',
            $r['probabilitas'] ?? '',
            $r['dampak_level'] ?? '',
            $r['bobot'] ?? '',
            $r['nilai_risiko'] ?? '',
            $r['tingkat_risiko'] ?? '',
            $r['prioritas_risiko'] ?? '',
            $r['selera_risiko'] ?? '',
            $r['pilihan_penanganan'] ?? '',
            $r['rpti_uraian'] ?? '',
            $r['rpti_jadwal'] ?? '',
            $r['target_p'] ?? '',
            $r['target_d'] ?? '',
            $r['target_bobot'] ?? '',
            $r['target_nilai'] ?? '',
            $r['target_tingkat'] ?? '',
        ];
    }
    $namaFile = 'kkpr_' . ($kkprRow['tahun'] ?? '') . '_' . date('Ymd_His');
    exportExcel($namaFile, $headers, $excelRows);
}


?>

<?php
$kkTotalKkpr = count($kkprList);
$kkRisiko = 0;
$kkTinggi = 0;
if ($kkTotalKkpr > 0) {
    $kIds = implode(',', array_column($kkprList, 'id'));
    $statQ = $db->query("SELECT COUNT(*) as tot, SUM(IF(tingkat_risiko IN ('Tinggi','Sangat Tinggi'), 1, 0)) as th FROM kkpr_risiko WHERE id_kkpr IN ($kIds)");
    if ($statQ) {
        $statRes = $statQ->fetch_assoc();
        $kkRisiko = (int)$statRes['tot'];
        $kkTinggi = (int)$statRes['th'];
    }
}
$kkprStatus = trim($kkprRow['status_kkpr'] ?? '');
if ($kkprStatus === '') {
    $kkprStatus = 'Draft';
}
$kkprStatusClass = 'badge-info';
if ($kkprStatus === 'Menunggu Persetujuan') $kkprStatusClass = 'badge-warning';
elseif ($kkprStatus === 'Disetujui') $kkprStatusClass = 'badge-success';
elseif ($kkprStatus === 'Revisi') $kkprStatusClass = 'badge-danger';
?>
<div class="risiko-hero profil-risiko-hero kkpr-risiko-hero" style="background:linear-gradient(115deg, #2e1065 0%, #3b1d82 55%, #4c2896 100%); align-items: flex-start !important;">
  <div class="risiko-hero-copy">
    <div class="risiko-eyebrow"><i class="fas fa-file-contract"></i> Tahap 3 dari 3</div>
    <h1 class="page-title">Kertas Kerja Penilaian Risiko</h1>
    <?php if($activeId && $kkprRow): ?>
    <div style="margin-top:-4px; margin-bottom:8px;">
      <span class="badge <?= $kkprStatusClass ?>" style="font-size:.72rem;padding:4px 12px;"><i class="fas fa-circle-check"></i> <?= xss($kkprStatus) ?></span>
    </div>
    <?php endif; ?>
    <p class="page-sub"><?= xss($workflowNotice) ?></p>

    <div style="margin-top:12px; display:flex; gap:8px;">
      <?php if(!empty($kkprList) && $activeId && $kkprRow): ?>
          <?php if (hasRole('Risk Manager') && in_array($kkprStatus, ['Draft', 'Revisi'])): ?>
               <button class="btn btn-hero-primary" onclick="openModal('modalKirimPersetujuanKKPR')" style="padding:6px 14px; font-size:12px;"><i class="fas fa-paper-plane"></i> Ajukan Persetujuan</button>
          <?php elseif (hasRole('Pimpinan') && $kkprStatus === 'Menunggu Persetujuan'): ?>
              <form method="post" style="display:inline; margin:0;" onsubmit="return confirm('Setujui KKPR ini?');">
                  <?= csrfField() ?>
                  <input type="hidden" name="aksi" value="approve_kkpr">
                  <input type="hidden" name="id" value="<?= $activeId ?>">
                  <button type="submit" class="btn btn-hero-primary" style="background:var(--success); border-color:var(--success); padding:6px 14px; font-size:12px;"><i class="fas fa-check"></i> Setujui</button>
              </form>
              <button class="btn btn-hero-primary" style="background:var(--danger); border-color:var(--danger); padding:6px 14px; font-size:12px;" onclick="openModal('modalTolakPersetujuanKKPR')"><i class="fas fa-times"></i> Revisi</button>
          <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  <div class="profil-hero-tools risiko-hero-tools-align">
  <select class="form-control hero-year-select" style="max-width:130px;width:auto;text-align:center;text-align-last:center;" onchange="if(this.value) window.location.href='<?= APP_URL ?>/?page=kkpr&tahun='+encodeURIComponent(this.value)" aria-label="Pilih tahun">
    <?php foreach($tahunList as $y): ?>
    <option value="<?= xss($y) ?>" <?= (string)$y === (string)$activeTahun ? 'selected' : '' ?> style="text-align:center;"><?= xss($y) ?></option>
    <?php endforeach; ?>
  </select>
  <?php if($activeId && $kkprRow): ?>
  <div class="risiko-export-actions" style="margin-top:0">
    <a href="<?= APP_URL ?>/?page=kkpr&id=<?= $activeId ?>&export=excel" class="btn btn-hero-ghost"><i class="fas fa-file-excel"></i> Excel</a>
    <select class="form-control hero-year-select" style="width: 155px !important; max-width: 155px !important; padding: 0 24px 0 14px !important; text-align-last: center !important;" onchange="if(this.value){window.open('<?= APP_URL ?>/?page=kkpr&id=<?= $activeId ?>&export=pdf&periode='+encodeURIComponent(this.value),'_blank');this.selectedIndex=0;}" aria-label="Cetak Laporan" title="Cetak Laporan per triwulan / tahunan / bulanan">
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
    <?php $isLockedKkpr = isset($kkprRow) && in_array($kkprRow['status_kkpr'] ?? '', ['Menunggu Persetujuan', 'Disetujui']); ?>
    <?php if((!$activeId || !$kkprRow) && hasRole('Admin','Risk Manager')): ?>
     <button id="btnKkprBaruHeader" class="btn btn-hero-primary btn-standard-action" onclick="openModal('modalKkprBaru')" style="margin:0;padding:8px 14px;white-space:nowrap;"><i class="fas fa-plus"></i> Tambah KKPR</button>
    <?php endif; ?>
  </div>
  </div>

  <!-- Stat cards (glassmorphism inside hero) -->
  <div class="stats-grid cols-3" style="width:100%;margin-top:20px;margin-bottom:0">
    <a href="<?= APP_URL ?>/?page=kkpr" class="stat-card stat-card-glass" style="--ga:#60a5fa;--ga-tint:rgba(96,165,250,.3);--ga-line:rgba(96,165,250,.45);--ga-glow:rgba(96,165,250,.3);text-decoration:none">
      <div class="stat-icon"><i class="fas fa-folder-open"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $kkTotalKkpr ?></div>
        <div class="stat-label">Total KKPR</div>
      </div>
    </a>
    <a href="<?= APP_URL ?>/?page=kkpr<?= $activeId ? '&id='.$activeId.'#kkprDataRisiko' : '' ?>" class="stat-card stat-card-glass" style="--ga:#a78bfa;--ga-tint:rgba(167,139,250,.3);--ga-line:rgba(167,139,250,.45);--ga-glow:rgba(167,139,250,.3);text-decoration:none">
      <div class="stat-icon"><i class="fas fa-list-check"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $kkRisiko ?></div>
        <div class="stat-label">Risiko Diinput</div>
      </div>
    </a>
    <a href="<?= APP_URL ?>/?page=kkpr<?= $activeId ? '&id='.$activeId.'#kkprDataRisiko' : '' ?>" class="stat-card stat-card-glass" style="--ga:#f87171;--ga-tint:rgba(248,113,113,.28);--ga-line:rgba(248,113,113,.5);--ga-glow:rgba(248,113,113,.32);text-decoration:none">
      <div class="stat-icon"><i class="fas fa-fire"></i></div>
      <div class="stat-content">
        <div class="stat-value"><?= $kkTinggi ?></div>
        <div class="stat-label">Risiko Tinggi+</div>
      </div>
    </a>
  </div>
</div>

<div class="risiko-flow">
  <a href="<?= APP_URL ?>/?page=risiko" class="risiko-flow-item"><span class="rf-num">1</span><div><strong>Identifikasi</strong><small>Master risiko</small></div></a>
  <a href="<?= APP_URL ?>/?page=profil_risiko" class="risiko-flow-item"><span class="rf-num">2</span><div><strong>Profil Risiko</strong><small>Penilaian & rencana</small></div></a>
  <div class="risiko-flow-item is-current"><span class="rf-num">3</span><div><strong>KKPR</strong><small>Dokumen kerja</small></div></div>
  <a href="<?= APP_URL ?>/?page=kkpmr" class="risiko-flow-item"><span class="rf-num">4</span><div><strong>KKPMR</strong><small>Pemantauan & reviu</small></div></a>
  <a href="<?= APP_URL ?>/?page=kkpmr" class="risiko-flow-btn"><i class="fas fa-right-long"></i> Lanjut ke KKPMR</a>
</div>

<?php if (isset($_GET['baru']) && $_GET['baru'] === '1' && hasRole('Admin', 'Risk Manager')): ?>
<script>document.addEventListener('DOMContentLoaded', function () { openModal('modalKkprBaru'); });</script>
<?php endif; ?>

<!-- Konten -->
<div style="min-width:0; width: 100%;">
<?php if(!$activeId || !$kkprRow): ?>
  <?php if(empty($kkprList)): ?>
  <div class="card">
    <div class="card-body">
      <div class="empty-state" style="padding:60px">
        <i class="fas fa-clipboard-list" style="font-size:3rem;color:var(--warning);opacity:.3;margin-bottom:16px;display:block"></i>
        <h3>Belum Ada Data KKPR</h3>
        <p style="margin-top:8px; margin-bottom: 24px; color:var(--text-muted)">Sistem belum memiliki data Kertas Kerja Penilaian Risiko. Silakan buat KKPR pertama Anda.</p>
        <?php if(hasRole('Admin','Risk Manager')): ?>
        <button class="btn btn-primary" onclick="openModal('modalKkprBaru')"><i class="fas fa-plus"></i> Buat KKPR Baru</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php else: ?>
  <!-- Grid KKPR -->
  <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(320px, 1fr));gap:24px;margin-bottom:24px">
    <?php foreach($kkprList as $kl): ?>
    <div class="card" style="cursor:pointer;transition:all .25s ease" 
         onclick="window.location.href='<?= APP_URL ?>/?page=kkpr&id=<?= $kl['id'] ?>'" 
         onmouseover="this.style.transform='translateY(-6px)';this.style.boxShadow='0 15px 30px rgba(0,0,0,0.1)'" 
         onmouseout="this.style.transform='none';this.style.boxShadow='var(--shadow)'">
      <div class="card-body" style="padding:24px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px">
          <div style="width:52px;height:52px;border-radius:14px;background:var(--warning-glow);display:flex;align-items:center;justify-content:center;color:var(--warning);font-size:1.5rem">
            <i class="fas fa-clipboard-list"></i>
          </div>
          <div style="background:var(--surface2);padding:5px 12px;border-radius:20px;font-size:.78rem;font-weight:700;color:var(--text)">
            Tahun <?= xss($kl['tahun']) ?>
          </div>
          <?php if(hasRole('Admin','Pimpinan','Risk Manager')): ?>
          <form method="POST" action="<?= APP_URL ?>/?page=kkpr" style="display:inline;margin:0" onclick="event.stopPropagation()" onsubmit="return confirm('Hapus KKPR tahun <?= xss($kl['tahun']) ?>? Semua data risiko di dalamnya juga akan dihapus.')">
            <?= csrfField() ?>
            <input type="hidden" name="aksi" value="hapus_kkpr">
            <input type="hidden" name="id" value="<?= $kl['id'] ?>">
            <button type="submit" title="Hapus KKPR" style="background:rgba(220,38,38,.12);color:var(--danger);border:none;width:34px;height:34px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center"><i class="fas fa-trash"></i></button>
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
        <button class="btn btn-outline" style="width:100%;justify-content:center;font-weight:600">Buka Kertas Kerja <i class="fas fa-arrow-right" style="margin-left:8px;font-size:.8rem"></i></button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
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

<!-- Tabs KKPR -->
<div class="tabs" style="margin-bottom:16px;">
  <button class="tab-btn <?= $activeTab==='detail'?'active':'' ?>" data-tab="kkprTabD" onclick="swTab('kkprTabD',this)"><i class="fas fa-table"></i> 1. Kertas Kerja &amp; Detail Risiko <span style="background:var(--accent);color:#fff;border-radius:12px;padding:2px 8px;font-size:.72rem;margin-left:6px;font-weight:700"><?= count($rows) ?></span></button>
  <button class="tab-btn <?= $activeTab==='header'?'active':'' ?>" data-tab="kkprTabH" onclick="swTab('kkprTabH',this)"><i class="fas fa-info-circle"></i> 2. Info Dokumen KKPR (Header)</button>
</div>

<!-- Tab Header -->
<div id="kkprTabH" class="tab-content <?= $activeTab==='header'?'active':'' ?>">
  <div class="card">
    <div class="card-header" style="background:linear-gradient(135deg,var(--primary),var(--primary-light));border:none">
      <div>
        <span class="card-title" style="color:#fff;font-size:1rem"><i class="fas fa-file-invoice"></i> Info KKPR — <?= xss($kkprRow['tahun']) ?></span>
        <div style="color:rgba(255,255,255,.65);font-size:.78rem;margin-top:2px"><?= xss($kkprRow['unit_pemilik_risiko']??'') ?></div>
      </div>
      <?php if(hasRole('Admin','Risk Manager')): ?>
      <button class="btn btn-sm" style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3)" onclick="openModal('modalKkprEdit')"><i class="fas fa-edit"></i> Edit</button>
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
            ['Tujuan',               $kkprRow['tujuan'],           'fa-crosshairs'],
            ['Sasaran',              $kkprRow['sasaran'],          'fa-flag'],
            ['Indikator Kinerja Kegiatan',    $kkprRow['indikator_kinerja'],'fa-chart-line'],
            ['Target',               $kkprRow['target'],           'fa-bullseye'],
            ['Program',              $kkprRow['program'],          'fa-sitemap'],
            ['Kegiatan',             $kkprRow['kegiatan'],         'fa-tasks'],
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
            ['Unit Pemilik Risiko',      $kkprRow['unit_pemilik_risiko'],   'fa-building'],
            ['Nama Pemilik Risiko',      $kkprRow['nama_pemilik_risiko'],   'fa-user-tie'],
            ['NIP Pemilik Risiko',       $kkprRow['nip_pemilik_risiko'] ?? '', 'fa-id-badge'],
            ['Nama Pengelola Risiko',    $kkprRow['nama_pengelola_risiko'], 'fa-users-cog'],
            ['NIP Pengelola Risiko',     $kkprRow['nip_pengelola_risiko'] ?? '', 'fa-id-card'],
            ['Tgl Penilaian',            tglIndo($kkprRow['tgl_penilaian']??''), 'fa-calendar-check'],
            ['Periode',                  $kkprRow['periode_risiko'],        'fa-calendar-alt'],
            ['Tgl Update',               tglIndo($kkprRow['tgl_update']??''), 'fa-sync-alt'],
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
          ['Pemilik Risiko', $kkprRow['ttd_pemilik'], $kkprRow['nama_ttd_pemilik'], $kkprRow['nip_ttd_pemilik']],
          ['Tim Pengelola', $kkprRow['ttd_pengelola'], $kkprRow['nama_ttd_pengelola'], $kkprRow['nip_ttd_pengelola']]
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

      <!-- CTA Banner: Arahkan langsung ke Detail Risiko -->
      <div style="padding:14px 20px;background:var(--surface2);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div style="font-size:.82rem;color:var(--text-muted)">
          <i class="fas fa-lightbulb" style="color:var(--accent);margin-right:6px"></i> Form di atas adalah informasi umum/sasaran KKPR. Untuk pengisian kertas kerja dan penilaian risiko, silakan buka tab Detail Risiko.
        </div>
        <button type="button" class="btn btn-primary" onclick="bukaTabDetailKkpr()" style="font-weight:700">
          <i class="fas fa-table"></i> Buka Kertas Kerja &amp; Detail Risiko (<?= count($rows) ?>) <i class="fas fa-arrow-right"></i>
        </button>
      </div>
      
    </div>
  </div>
</div>

<!-- Tab Detail -->
<div id="kkprTabD" class="tab-content <?= $activeTab==='detail'?'active':'' ?>">

  <!-- Compact Context Bar -->
  <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:12px 18px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;box-shadow:var(--shadow-sm)">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
      <span style="background:var(--primary-glow);color:var(--primary);font-weight:800;padding:4px 10px;border-radius:8px;font-size:.85rem">
        <i class="fas fa-file-invoice"></i> KKPR Tahun <?= xss($kkprRow['tahun']) ?>
      </span>
      <span style="font-weight:700;color:var(--text);font-size:.92rem">
        <?= xss($kkprRow['unit_pemilik_risiko'] ?? 'Unit Belum Ditentukan') ?>
      </span>
      <?php if(!empty($kkprRow['status_kkpr'])): ?>
      <span class="badge badge-<?= $kkprRow['status_kkpr']==='Disetujui'?'success':($kkprRow['status_kkpr']==='Revisi'?'danger':'warning') ?>" style="font-size:.75rem">
        Status: <?= xss($kkprRow['status_kkpr']) ?>
      </span>
      <?php endif; ?>
      <?php if(!empty($kkprRow['sasaran'])): ?>
      <span style="color:var(--text-muted);font-size:.82rem;border-left:1px solid var(--border);padding-left:12px;max-width:380px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= xss($kkprRow['sasaran']) ?>">
        <i class="fas fa-bullseye" style="color:var(--primary);margin-right:4px"></i> Sasaran: <?= xss($kkprRow['sasaran']) ?>
      </span>
      <?php endif; ?>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <button type="button" class="btn btn-sm btn-outline" onclick="bukaTabHeaderKkpr()" title="Lihat dan edit sasaran, program, dan TTD dokumen KKPR">
        <i class="fas fa-info-circle"></i> Info Sasaran &amp; Dokumen <i class="fas fa-chevron-right" style="font-size:.7rem;margin-left:2px"></i>
      </button>
    </div>
  </div>

<!-- Tabel -->
<div class="card" id="kkprDataRisiko">
  <?php if(hasRole('Admin','Risk Manager') && $activeId && $kkprRow): ?>
  <!-- Bar Impor dari Profil -->
  <div style="padding:10px 16px;background:var(--surface2);border-bottom:1px solid var(--border);display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <i class="fas fa-file-import" style="color:var(--success)"></i>
    <span style="font-size:.82rem;font-weight:600;color:var(--text);white-space:nowrap">Impor dari Profil:</span>
    <select id="importProfilSelect" class="form-control" style="width:auto;flex:1;min-width:180px;padding:6px 10px;font-size:.82rem;height:34px">
      <option value="">-- Pilih Profil --</option>
    </select>
    <button type="button" class="btn btn-xs btn-success" style="padding:8px 12px" onclick="importDetailFromProfil()"><i class="fas fa-download"></i> Impor Sekaligus</button>
  </div>
  <?php endif; ?>
  <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <span class="card-title" style="margin:0;"><i class="fas fa-table"></i> Data Risiko KKPR (<?= count($rows) ?>)</span>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;flex:1;justify-content:flex-end">
      <div class="search-bar" style="max-width:250px;width:100%">
        <i class="fas fa-search"></i>
        <input type="text" class="form-control" id="searchKkpr" placeholder="Cari risiko..." onkeyup="filterTableKkpr()" style="height:38px">
      </div>
      <div class="datatable-dropdown" style="margin:0; display:flex; align-items:center;">
        <select class="datatable-selector" id="limitKkpr" onchange="filterTableKkpr()">
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
    <table class="data-table kkpr-data-table no-datatable" id="tableKkpr">
      <colgroup>
        <col class="col-no"><col class="col-code"><col class="col-risk">
        <col class="col-text"><col class="col-source"><col class="col-small"><col class="col-text">
        <col class="col-control"><col class="col-small">
        <col class="col-score"><col class="col-score"><col class="col-val"><col class="col-level">
        <col class="col-choice"><col class="col-choice"><col class="col-control"><col class="col-schedule">
        <col class="col-score"><col class="col-score"><col class="col-val"><col class="col-level">
        <?php if(hasRole('Admin','Risk Manager')): ?><col class="col-action"><?php endif; ?>
      </colgroup>
      <thead>
        <tr>
          <th rowspan="2" class="col-no" style="text-align:center">No</th>
          <th rowspan="2" class="col-code" style="text-align:center">Kode</th>
          <th rowspan="2" class="col-risk" style="min-width:140px">Risiko</th>
          <th colspan="4" style="text-align:center;background:#f1f5f9;color:#334155;border-bottom:1px solid #cbd5e1">IDENTIFIKASI RISIKO</th>
          <th colspan="6" style="text-align:center;background:#e2e8f0;color:#1e3a8a;border-bottom:1px solid #cbd5e1">ANALISIS RISIKO</th>
          <th colspan="2" style="text-align:center;background:#fef3c7;color:#92400e;border-bottom:1px solid #fde68a">EVALUASI RISIKO</th>
          <th colspan="2" style="text-align:center;background:#e0e7ff;color:#3730a3;border-bottom:1px solid #c7d2fe">RPR</th>
          <th colspan="4" style="text-align:center;background:#dcfce7;color:#166534;border-bottom:1px solid #bbf7d0">TARGET</th>
          <?php if(hasRole('Admin','Risk Manager')): ?><th rowspan="2" class="col-action" style="text-align:center">Aksi</th><?php endif; ?>
        </tr>
        <tr>
          <th style="min-width:120px">Sebab</th>
          <th style="width:75px;text-align:center">Sumber</th>
          <th style="width:48px;text-align:center">C/UC</th>
          <th style="min-width:120px">Dampak</th>
          <th style="min-width:120px">Uraian Pengendalian</th>
          <th style="width:75px;text-align:center">Efektivitas</th>
          <th style="width:32px;text-align:center" title="Probabilitas">P</th>
          <th style="width:32px;text-align:center" title="Dampak">D</th>
          <th style="width:52px;text-align:center" title="Nilai &amp; Bobot">Nilai<br><span style="font-size:.6rem;font-weight:normal;color:#475569">(Bobot)</span></th>
          <th style="width:80px;text-align:center">Tingkat</th>
          <th style="width:85px;text-align:center">Selera Risiko</th>
          <th style="width:95px;text-align:center">Pilihan Penanganan</th>
          <th style="min-width:120px">Uraian</th>
          <th style="width:110px">Jadwal</th>
          <th style="width:32px;text-align:center" title="Target Probabilitas">P&darr;</th>
          <th style="width:32px;text-align:center" title="Target Dampak">D&darr;</th>
          <th style="width:52px;text-align:center" title="Target Nilai &amp; Bobot">Nilai&darr;<br><span style="font-size:.6rem;font-weight:normal;color:#166534">(Bobot)</span></th>
          <th style="width:80px;text-align:center">Tingkat&darr;</th>
        </tr>
      </thead>
      <tbody>
      <?php if(empty($rows)): ?>
      <tr><td colspan="<?= hasRole('Admin','Risk Manager') ? 22 : 21 ?>"><div class="empty-state"><i class="fas fa-table"></i><h3>Belum ada data</h3></div></td></tr>
      <?php else: ?>
      <?php foreach($rows as $r):
        $bgT  = kkprBg($r['tingkat_risiko']??'Rendah');
        $clT  = kkprColor($r['tingkat_risiko']??'Rendah');
        $bgTT = kkprBg($r['target_tingkat']??'Rendah');
        $clTT = kkprColor($r['target_tingkat']??'Rendah');
        $bgEv = $r['evaluasi_warna'] ? $r['evaluasi_warna'] : $bgT;
      ?>
      <tr>
        <td style="text-align:center;font-weight:600;color:var(--text-muted)"><?= $r['no_urut'] ?></td>
        <td style="text-align:center;white-space:nowrap"><span class="badge-kode-risiko"><?= xss($r['kode_risiko']??'-') ?></span></td>
        <td style="min-width:140px;line-height:1.35;font-weight:600;color:var(--text-main)"><?= xss($r['nama_risiko']??'') ?></td>
        <td style="font-size:.72rem;line-height:1.35;min-width:120px"><?= formatUraianList($r['sebab'] ?? '') ?: '-' ?></td>
        <td style="text-align:center"><span class="badge badge-secondary" style="font-size:.68rem;padding:2px 6px"><?= xss(normalizeSumberRisiko($r['sumber'] ?? '')) ?></span></td>
        <td style="text-align:center"><span class="badge badge-info" style="font-size:.68rem;padding:2px 6px"><?= xss($r['c_uc']??'-') ?></span></td>
        <td style="font-size:.72rem;line-height:1.35;min-width:120px"><?= xss($r['dampak_uraian']??'') ?: '-' ?></td>
        <td style="font-size:.72rem;line-height:1.35;min-width:120px"><?= xss($r['pengendalian_uraian']??'') ?: '-' ?></td>
        <td style="text-align:center">
          <?php if(($r['pengendalian_efektivitas']??'')==='E'): ?>
            <span class="badge badge-success" style="font-size:.68rem;padding:2px 6px">Efektif</span>
          <?php elseif(in_array($r['pengendalian_efektivitas']??'',['TE','BE'])): ?>
            <span class="badge badge-danger" style="font-size:.68rem;padding:2px 6px">Tdk Efektif</span>
          <?php else: ?>
            <span style="color:var(--text-muted)">-</span>
          <?php endif; ?>
        </td>
        <td style="text-align:center;font-weight:700"><?= $r['probabilitas'] ?></td>
        <td style="text-align:center;font-weight:700"><?= $r['dampak_level'] ?></td>
        <td style="text-align:center">
          <div style="font-weight:700;font-size:.82rem;line-height:1.1"><?= round((float)$r['nilai_risiko']) ?></div>
          <div style="font-size:.66rem;color:var(--accent);font-weight:600;margin-top:1px" title="Bobot"><?= $r['bobot'] ?></div>
        </td>
        <td style="text-align:center">
          <span style="background:<?= $bgT ?>;color:<?= $clT ?>;padding:3px 7px;border-radius:10px;font-weight:700;font-size:.68rem;display:inline-block;white-space:nowrap"><?= xss($r['tingkat_risiko']??'-') ?></span>
        </td>
        <td style="text-align:center;font-size:.68rem;font-weight:700;<?php
          $sr=$r['selera_risiko']??'';
          if(str_contains($sr,'Dalam')) echo 'color:#000000;background:#FFFF00;padding:2px 4px;border-radius:4px;';
          elseif(str_contains($sr,'Diatas')) echo 'color:#ffffff;background:#ED7D31;padding:2px 4px;border-radius:4px;';
        ?>"><?= xss($sr?:'-') ?></td>
        <td style="text-align:center;font-size:.68rem;<?php
          $pp = $r['pilihan_penanganan'] ?? '';
          if (str_contains($pp,'Menerima')) echo 'background:#FFFF00;color:#000000;font-weight:700;padding:2px 4px;border-radius:4px;';
          elseif (str_contains($pp,'Mitigasi')) echo 'background:#FF0000;color:#ffffff;font-weight:700;padding:2px 4px;border-radius:4px;';
        ?>"><?= xss($pp?:'-') ?></td>
        <td style="font-size:.72rem;line-height:1.35;min-width:120px"><?= xss($r['rpti_uraian']??'') ?: '-' ?></td>
        <td style="font-size:.70rem;line-height:1.3;"><?= xss($r['rpti_jadwal']??'') ?: '-' ?></td>
        <td style="text-align:center;font-weight:700"><?= $r['target_p'] ?></td>
        <td style="text-align:center;font-weight:700"><?= $r['target_d'] ?></td>
        <td style="text-align:center">
          <div style="font-weight:700;font-size:.82rem;line-height:1.1;color:var(--success)"><?= round((float)$r['target_nilai']) ?></div>
          <div style="font-size:.66rem;color:#16a34a;font-weight:600;margin-top:1px" title="Target Bobot"><?= $r['target_bobot'] ?></div>
        </td>
        <td style="text-align:center">
          <span style="background:<?= $bgTT ?>;color:<?= $clTT ?>;padding:3px 7px;border-radius:10px;font-weight:700;font-size:.68rem;display:inline-block;white-space:nowrap"><?= xss($r['target_tingkat']??'-') ?></span>
        </td>
        <?php if(hasRole('Admin','Risk Manager')): ?>
        <td class="kkpr-action-cell" style="text-align:center;white-space:nowrap">
          <div class="act-btn-group" style="justify-content:center">
          <?php if($isLockedKkpr): ?>
              <button type="button" class="act-btn act-btn-view" onclick='editKkprRisiko(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)' title="Lihat detail"><i class="fas fa-eye"></i></button>
              <form method="POST" style="display:contents;"><?= csrfField() ?>
                <input type="hidden" name="aksi" value="hapus_risiko">
                <input type="hidden" name="id_risiko_row" value="<?= $r['id'] ?>">
                <input type="hidden" name="id_kkpr" value="<?= $activeId ?>">
                <button type="submit" class="act-btn act-btn-delete" title="Hapus risiko" onclick="return confirm('KKPR sedang terkunci. Hapus risiko ini juga?')"><i class="fas fa-trash"></i></button>
              </form>
          <?php else: ?>
              <button type="button" class="act-btn act-btn-edit" onclick='editKkprRisiko(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)' title="Edit risiko"><i class="fas fa-edit"></i></button>
              <form method="POST" style="display:contents;"><?= csrfField() ?>
                <input type="hidden" name="aksi" value="hapus_risiko">
                <input type="hidden" name="id_risiko_row" value="<?= $r['id'] ?>">
                <input type="hidden" name="id_kkpr" value="<?= $activeId ?>">
                <button type="submit" class="act-btn act-btn-delete" title="Hapus risiko" onclick="return confirm('Hapus?')"><i class="fas fa-trash"></i></button>
              </form>
          <?php endif; ?>
          </div>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer" id="kkprPagination" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;padding:14px 20px;">
    <span class="pagination-info" id="kkprPageInfo" style="font-size:.85rem;color:var(--text-muted);font-weight:500;">Memuat...</span>
    <div class="pagination" id="kkprPages" style="margin:0;gap:6px;"></div>
  </div>
</div>
</div><!-- end tab detail -->
<?php endif; ?>
</div><!-- end konten -->

<!-- Modal Form Risiko KKPR (Tambah/Edit) -->
<div class="modal-overlay" id="modalKkprRisiko" style="display:none">
  <div class="modal" style="max-width:820px;max-height:92vh;overflow-y:auto">
    <div class="modal-header" style="background:linear-gradient(135deg,var(--warning),#d97706);position:sticky;top:0;z-index:10">
      <h3 class="modal-title" id="kkprRisikoTitle" style="color:#fff"><i class="fas fa-plus-circle"></i> Tambah Risiko KKPR</h3>
      <button class="btn-close" onclick="closeModal('modalKkprRisiko')" style="color:#fff"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/?page=kkpr" id="formKkprRisiko">
    <?= csrfField() ?>
    <input type="hidden" name="aksi" value="simpan_risiko">
    <input type="hidden" name="id_kkpr" value="<?= $activeId ?>">
    <input type="hidden" name="id_risiko_row" id="kkpr_row_id" value="0">
    <input type="hidden" name="evaluasi_warna" id="kkpr_warna_hidden" value="">
    <div class="modal-body" style="padding:20px">

      <!-- Sub-tabs -->
      <div style="display:flex;gap:4px;margin-bottom:16px;flex-wrap:wrap;border-bottom:2px solid var(--border)" class="kkpr-form-tabs">
        <button type="button" class="btn btn-xs kkpr-ftab active" onclick="kkprFormTab('ftab1',this)" style="border-radius:6px 6px 0 0;border-bottom:3px solid var(--primary)"><i class="fas fa-search"></i> A. Identifikasi</button>
        <button type="button" class="btn btn-xs kkpr-ftab" onclick="kkprFormTab('ftab2',this)" style="border-radius:6px 6px 0 0"><i class="fas fa-shield-alt"></i> B. Pengendalian</button>
        <button type="button" class="btn btn-xs kkpr-ftab" onclick="kkprFormTab('ftab3',this)" style="border-radius:6px 6px 0 0"><i class="fas fa-chart-bar"></i> C. Analisis &amp; Evaluasi</button>
        <button type="button" class="btn btn-xs kkpr-ftab" onclick="kkprFormTab('ftab4',this)" style="border-radius:6px 6px 0 0"><i class="fas fa-bullseye"></i> D. Rencana &amp; Target</button>
      </div>

      <!-- TAB 1: IDENTIFIKASI -->
      <div id="ftab1" class="kkpr-ftab-content">
        <div style="background:var(--surface2);border-radius:8px;padding:14px;border-left:3px solid var(--primary)">
          <div style="display:grid;grid-template-columns:80px 1fr;gap:12px;margin-bottom:12px">
            <div class="form-group" style="margin:0"><label class="form-label">No Urut</label><input type="number" name="no_urut" id="kkpr_no" class="form-control" value="<?= count($rows)+1 ?>" min="1"></div>
            <div class="form-group" style="margin:0"><label class="form-label">Kode Risiko <span class="required">*</span></label>
              <select name="kode_risiko" id="kkpr_kode" class="form-control" onchange="kkprAutoFill(this)" required>
                <option value="">-- Pilih Risiko dari Identifikasi --</option>
                <?php foreach($risikoMaster as $rm): ?>
                <option value="<?= htmlspecialchars($rm['kode_risiko']) ?>" data-nama="<?= htmlspecialchars($rm['nama_risiko']) ?>" data-sebab="<?= htmlspecialchars($rm['penyebab']??'') ?>" data-dampak="<?= htmlspecialchars($rm['dampak']??'') ?>" data-sumber="<?= htmlspecialchars(normalizeSumberRisiko($rm['sumber'] ?? '')) ?>">
                  <?= htmlspecialchars($rm['kode_risiko']) ?> - <?= htmlspecialchars($rm['nama_risiko']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-group" style="margin-bottom:12px"><label class="form-label">Nama / Uraian Risiko <span class="required">*</span></label><textarea name="nama_risiko" id="kkpr_nama" class="form-control" rows="2" required placeholder="Otomatis terisi saat pilih kode, atau ketik manual"></textarea></div>
          <div class="form-row-2">
            <div class="form-group"><label class="form-label">Sebab Risiko</label><textarea name="sebab" id="kkpr_sebab" class="form-control" rows="2" placeholder="Otomatis dari master, bisa edit"></textarea></div>
            <div class="form-group"><label class="form-label">Dampak Risiko</label><textarea name="dampak_uraian" id="kkpr_dampak" class="form-control" rows="2" placeholder="Otomatis dari master, bisa edit"></textarea></div>
            <div class="form-group">
              <label class="form-label">Sumber Risiko</label>
              <select name="sumber" id="kkpr_sumber" class="form-control">
                <?php foreach(['Internal','Eksternal','Internal & Eksternal'] as $opt): ?>
                <option value="<?= $opt ?>" <?= $opt==='Eksternal'?'selected':'' ?>><?= $opt ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">C / UC</label>
              <select name="c_uc" id="kkpr_cuc" class="form-control">
                <?php foreach(['C','UC'] as $opt): ?>
                <option value="<?= $opt ?>" <?= $opt==='UC'?'selected':'' ?>><?= $opt ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div style="margin-top:14px;display:flex;justify-content:flex-end">
          <button type="button" class="btn btn-primary btn-sm" onclick="kkprLanjut('ftab2')"><i class="fas fa-arrow-right"></i> Lanjut ke Pengendalian</button>
        </div>
      </div>

      <!-- TAB 2: PENGENDALIAN -->
      <div id="ftab2" class="kkpr-ftab-content" style="display:none">
        <div style="background:var(--surface2);border-radius:8px;padding:14px;border-left:3px solid var(--info)">
          <div class="form-group" style="margin-bottom:12px"><label class="form-label">Uraian Pengendalian</label><textarea name="pengendalian_uraian" id="kkpr_peng_uraian" class="form-control" rows="3" placeholder="Uraian pengendalian yang sudah ada..."></textarea></div>
          <div class="form-row-2">
            <div class="form-group">
              <label class="form-label">Tidak Efektif</label>
              <input type="text" name="pengendalian_jenis" id="kkpr_peng_jenis" class="form-control" placeholder="Keterangan jika tidak efektif (opsional)...">
            </div>
            <div class="form-group">
              <label class="form-label">Efektivitas (E/TE/BE)</label>
              <select name="pengendalian_efektivitas" id="kkpr_peng_efek" class="form-control">
                <option value="">-</option>
                <?php foreach(['E'=>'E - Efektif','TE'=>'TE - Tidak Efektif','BE'=>'BE - Belum Efektif'] as $v=>$l): ?>
                <option value="<?= $v ?>"><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div style="margin-top:14px;display:flex;justify-content:space-between">
          <button type="button" class="btn btn-outline btn-sm" onclick="kkprLanjut('ftab1')"><i class="fas fa-arrow-left"></i> Kembali</button>
          <button type="button" class="btn btn-primary btn-sm" onclick="kkprLanjut('ftab3')">Lanjut ke Analisis <i class="fas fa-arrow-right"></i></button>
        </div>
      </div>

      <!-- TAB 3: ANALISIS + EVALUASI -->
      <div id="ftab3" class="kkpr-ftab-content" style="display:none">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
          <div style="background:var(--surface2);border-radius:8px;padding:14px;border-left:3px solid var(--warning)">
            <div style="font-size:.72rem;font-weight:700;color:var(--warning);text-transform:uppercase;letter-spacing:.07em;margin-bottom:12px">C. Analisis Risiko</div>
            <!-- Slider P -->
            <div style="margin-bottom:14px">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                <label style="font-size:.78rem;font-weight:700;color:var(--text)">Probabilitas:</label>
                <span id="kkpr_lbl_p_val" style="background:var(--warning);color:#fff;border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:800">3</span>
                <span id="kkpr_lbl_p_text" style="font-size:.78rem;color:var(--warning);font-weight:600">— Sedang</span>
              </div>
              <input type="range" name="probabilitas" id="kkpr_p" min="1" max="5" value="3"
                style="width:100%;accent-color:var(--warning);height:6px;cursor:pointer"
                oninput="kkprUpdateSlider('p');kkprHitung()">
              <div style="display:flex;justify-content:space-between;font-size:.63rem;color:#64748b;margin-top:3px;padding:0 2px">
                <span>1=Jarang</span><span>2=Kecil</span><span>3=Sedang</span><span>4=Besar</span><span>5=Hampir Pasti</span>
              </div>
            </div>
            <!-- Slider D -->
            <div style="margin-bottom:12px">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                <label style="font-size:.78rem;font-weight:700;color:var(--text)">Dampak Level:</label>
                <span id="kkpr_lbl_d_val" style="background:var(--warning);color:#fff;border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:800">3</span>
                <span id="kkpr_lbl_d_text" style="font-size:.78rem;color:var(--warning);font-weight:600">— Sedang</span>
              </div>
              <input type="range" name="dampak_level" id="kkpr_d" min="1" max="5" value="3"
                style="width:100%;accent-color:var(--warning);height:6px;cursor:pointer"
                oninput="kkprUpdateSlider('d');kkprHitung()">
              <div style="display:flex;justify-content:space-between;font-size:.63rem;color:#64748b;margin-top:3px;padding:0 2px">
                <span>1=T.Signifikan</span><span>2=Kecil</span><span>3=Sedang</span><span>4=Besar</span><span>5=Katastropik</span>
              </div>
            </div>
            <input type="hidden" name="bobot" id="kkpr_b" value="1.43">
            <!-- Ringkasan P,D,Bobot,Skor -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">
              <div>
                <div style="font-size:.63rem;color:#64748b;text-align:center;margin-bottom:2px">Nilai Risiko</div>
                <div id="kkpr_nilai" style="font-size:1.6rem;font-weight:800;color:var(--accent);text-align:center;line-height:1">-</div>
              </div>
              <div>
                <div style="font-size:.63rem;color:#64748b;text-align:center;margin-bottom:2px">Tingkat Risiko</div>
                <div id="kkpr_tingkat" style="padding:5px 8px;border-radius:8px;font-weight:800;font-size:.8rem;text-align:center">-</div>
              </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:4px;margin-top:4px">
              <div style="text-align:center;background:var(--warning);border-radius:6px;padding:5px 2px">
                <div id="kkpr_sum_p" style="font-size:1rem;font-weight:800;color:#fff">3</div>
                <div style="font-size:.6rem;color:rgba(255,255,255,.85)">P</div>
              </div>
              <div style="text-align:center;background:var(--warning);border-radius:6px;padding:5px 2px">
                <div id="kkpr_sum_d" style="font-size:1rem;font-weight:800;color:#fff">3</div>
                <div style="font-size:.6rem;color:rgba(255,255,255,.85)">D</div>
              </div>
              <div style="text-align:center;background:var(--warning);border-radius:6px;padding:5px 2px">
                <div id="kkpr_sum_bobot" style="font-size:1rem;font-weight:800;color:#fff">1.43</div>
                <div style="font-size:.6rem;color:rgba(255,255,255,.85)">Bobot</div>
              </div>
              <div style="text-align:center;background:#22c55e;border-radius:6px;padding:5px 2px" id="kkpr_sum_skor_box">
                <div id="kkpr_sum_skor" style="font-size:1rem;font-weight:800;color:#fff">-</div>
                <div style="font-size:.6rem;color:rgba(255,255,255,.85)">Skor</div>
              </div>
            </div>
            <input type="number" name="prioritas_risiko" id="kkpr_prio" value="1" style="display:none">
          </div>
          <div style="background:var(--surface2);border-radius:8px;padding:14px;border-left:3px solid var(--danger)">
            <div style="font-size:.72rem;font-weight:700;color:var(--danger);text-transform:uppercase;letter-spacing:.07em;margin-bottom:10px">D. Evaluasi Risiko</div>
            <div class="form-group">
              <label class="form-label">Selera Risiko (auto dari nilai)</label>
              <div id="kkpr_selera_preview" style="padding:10px 14px;border-radius:8px;font-weight:700;font-size:.85rem;text-align:center;background:var(--surface);color:var(--text-muted);border:2px dashed var(--border)">Otomatis terisi saat P/D diubah</div>
              <input type="hidden" name="selera_risiko" id="kkpr_selera_hidden" value="">
            </div>
            <div class="form-group">
              <label class="form-label">Pilihan Penanganan Risiko (auto)</label>
              <div id="kkpr_penanganan_preview" style="padding:10px 14px;border-radius:8px;font-weight:700;font-size:.85rem;text-align:center;background:var(--surface);color:var(--text-muted);border:2px dashed var(--border)">Otomatis terisi</div>
              <input type="hidden" name="pilihan_penanganan" id="kkpr_penanganan_hidden" value="">
            </div>
            <div class="form-group" style="margin-bottom:10px">
              <label class="form-label">Warna Evaluasi (auto dari tingkat)</label>
              <div id="kkpr_warna_preview" style="padding:10px 14px;border-radius:8px;font-weight:700;font-size:.85rem;text-align:center;background:var(--surface);color:var(--text-muted);border:2px dashed var(--border)">Otomatis terisi saat P/D diubah</div>
            </div>
          </div>
        </div>
        <div style="margin-top:14px;display:flex;justify-content:space-between">
          <button type="button" class="btn btn-outline btn-sm" onclick="kkprLanjut('ftab2')"><i class="fas fa-arrow-left"></i> Kembali</button>
          <button type="button" class="btn btn-primary btn-sm" onclick="kkprLanjut('ftab4')">Lanjut ke Rencana <i class="fas fa-arrow-right"></i></button>
        </div>
      </div>

      <!-- TAB 4: RPR + TARGET -->
      <div id="ftab4" class="kkpr-ftab-content" style="display:none">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div style="background:var(--surface2);border-radius:8px;padding:14px;border-left:3px solid var(--success)">
            <div style="font-size:.72rem;font-weight:700;color:var(--success);text-transform:uppercase;letter-spacing:.07em;margin-bottom:10px">E. Rencana Penanganan (RPR)</div>
            <div class="form-group" style="margin-bottom:10px"><label class="form-label">Uraian Rencana</label><textarea name="rpti_uraian" id="kkpr_rpti" class="form-control" rows="3" placeholder="Uraian rencana penanganan..."></textarea></div>
            <div class="form-group" style="margin:0"><label class="form-label">Jadwal Pelaksanaan</label><input type="text" name="rpti_jadwal" id="kkpr_jadwal" class="form-control" placeholder="Juli 2024 / 1 Tahun"></div>
          </div>
          <div style="background:var(--surface2);border-radius:8px;padding:14px;border-left:3px solid #8b5cf6">
            <div style="font-size:.72rem;font-weight:700;color:#8b5cf6;text-transform:uppercase;letter-spacing:.07em;margin-bottom:10px">F. Target Penurunan</div>
            <!-- Slider P Target -->
            <div style="margin-bottom:14px">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                <label style="font-size:.78rem;font-weight:700;color:var(--text)">Probabilitas:</label>
                <span id="kkpr_lbl_tp_val" style="background:#8b5cf6;color:#fff;border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:800">2</span>
                <span id="kkpr_lbl_tp_text" style="font-size:.78rem;color:#8b5cf6;font-weight:600">— Kecil</span>
              </div>
              <input type="range" name="target_p" id="kkpr_tp" min="1" max="5" value="2"
                style="width:100%;accent-color:#8b5cf6;height:6px;cursor:pointer"
                oninput="kkprUpdateSlider('tp');kkprHitungTarget()">
              <div style="display:flex;justify-content:space-between;font-size:.63rem;color:#64748b;margin-top:3px;padding:0 2px">
                <span>1=Jarang</span><span>2=Kecil</span><span>3=Sedang</span><span>4=Besar</span><span>5=Hampir Pasti</span>
              </div>
            </div>
            <!-- Slider D Target -->
            <div style="margin-bottom:12px">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                <label style="font-size:.78rem;font-weight:700;color:var(--text)">Dampak Level:</label>
                <span id="kkpr_lbl_td_val" style="background:#8b5cf6;color:#fff;border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:800">2</span>
                <span id="kkpr_lbl_td_text" style="font-size:.78rem;color:#8b5cf6;font-weight:600">— Kecil</span>
              </div>
              <input type="range" name="target_d" id="kkpr_td" min="1" max="5" value="2"
                style="width:100%;accent-color:#8b5cf6;height:6px;cursor:pointer"
                oninput="kkprUpdateSlider('td');kkprHitungTarget()">
              <div style="display:flex;justify-content:space-between;font-size:.63rem;color:#64748b;margin-top:3px;padding:0 2px">
                <span>1=T.Signifikan</span><span>2=Kecil</span><span>3=Sedang</span><span>4=Besar</span><span>5=Katastropik</span>
              </div>
            </div>
            <input type="hidden" name="target_bobot" id="kkpr_tb" value="1.80">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">
              <div>
                <div style="font-size:.63rem;color:#64748b;text-align:center;margin-bottom:2px">Nilai Target</div>
                <div id="kkpr_tnilai" style="font-size:1.6rem;font-weight:800;color:#8b5cf6;text-align:center;line-height:1">-</div>
              </div>
              <div>
                <div style="font-size:.63rem;color:#64748b;text-align:center;margin-bottom:2px">Tingkat Target</div>
                <div id="kkpr_ttingkat" style="padding:5px 8px;border-radius:8px;font-weight:700;font-size:.8rem;text-align:center">-</div>
              </div>
            </div>
          </div>
        </div>
        <div style="margin-top:14px;display:flex;justify-content:space-between">
          <button type="button" class="btn btn-outline btn-sm" onclick="kkprLanjut('ftab3')"><i class="fas fa-arrow-left"></i> Kembali</button>
        </div>
      </div>

    </div>
    <div class="modal-footer" style="background:var(--surface2)">
      <button type="button" onclick="closeModal('modalKkprRisiko')" class="btn btn-outline">Batal</button>
      <button type="submit" class="btn btn-success" id="btnSimpanKkprRisiko"><i class="fas fa-save"></i> Simpan Risiko</button>
    </div>
    </form>
  </div>
</div>

<!-- Modal KKPR Baru -->
<div class="modal-overlay" id="modalKkprBaru" style="display:none">
  <div class="modal modal-lg">
    <div class="modal-header"><h3 class="modal-title"><i class="fas fa-plus"></i> Buat KKPR Baru</h3>
    <button class="btn-close" onclick="closeModal('modalKkprBaru')"><i class="fas fa-times"></i></button></div>
    <form method="POST" action="<?= APP_URL ?>/?page=kkpr">
    <?= csrfField() ?><input type="hidden" name="aksi" value="simpan_header"><input type="hidden" name="id" value="0">
    <input type="hidden" name="ttd_pemilik" id="kkprTtdP"><input type="hidden" name="ttd_pengelola" id="kkprTtdG">
    <div class="modal-body">
      <div id="copyBarKkpr" style="margin-bottom:14px;padding:10px 14px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <i class="fas fa-copy" style="color:var(--accent)"></i>
        <span style="font-size:.82rem;font-weight:600;color:var(--text)">Salin header dari Profil Risiko:</span>
        <select id="copyProfilSelect" class="form-control" style="width:auto;flex:1;min-width:180px;padding:6px 10px;font-size:.82rem">
          <option value="">-- Pilih Profil --</option>
        </select>
        <button type="button" class="btn btn-xs btn-accent" onclick="copyHeaderToKkpr()"><i class="fas fa-paste"></i> Salin</button>
      </div>
      <div class="form-row-2">
        <div class="form-group"><label class="form-label">Tahun <span class="required">*</span></label><input type="text" name="tahun" class="form-control" required value="<?= date('Y') ?>" style="text-align: center;"></div>
        <div class="form-group"><label class="form-label">Unit Pemilik Risiko</label><input type="text" name="unit_pemilik_risiko" class="form-control" value="Balai Besar Laboratorium Kesehatan Lingkungan"></div>
        <div class="form-group"><label class="form-label">Nama Pemilik Risiko</label><input type="text" name="nama_pemilik_risiko" class="form-control" list="listUsersWithNip" onchange="autofillNip(this, 'nip_pemilik_risiko')"></div>
        <div class="form-group"><label class="form-label">NIP Pemilik Risiko</label><input type="text" name="nip_pemilik_risiko" class="form-control" placeholder="NIP pemilik risiko"></div>
        <div class="form-group"><label class="form-label">Nama Pengelola Risiko</label><input type="text" name="nama_pengelola_risiko" class="form-control" list="listUsersWithNip" onchange="autofillNip(this, 'nip_pengelola_risiko')" value="<?= xss(getDefaultPengelola()) ?>"></div>
        <div class="form-group"><label class="form-label">NIP Pengelola Risiko</label><input type="text" name="nip_pengelola_risiko" class="form-control" placeholder="NIP pengelola risiko"></div>
      </div>
      <div class="form-group"><label class="form-label">Tujuan</label><input type="text" name="tujuan" class="form-control" value="Terciptanya Sistem Ketahanan yang Tangguh"></div>
      <div class="form-group"><label class="form-label">Sasaran</label><textarea name="sasaran" class="form-control" rows="2"></textarea></div>
      <div class="form-row-2">
        <div class="form-group"><label class="form-label">Indikator Kinerja Utama</label><textarea name="indikator_kinerja" class="form-control" rows="4"></textarea></div>
        <div class="form-group"><label class="form-label">Target</label><textarea name="target" class="form-control" rows="4"></textarea></div>
        <div class="form-group"><label class="form-label">Program</label><input type="text" name="program" class="form-control" value="Pencegahan dan Pengendalian Penyakit"></div>
        <div class="form-group"><label class="form-label">Kegiatan</label><input type="text" name="kegiatan" class="form-control"></div>
        <div class="form-group"><label class="form-label">Tgl Penilaian</label><input type="date" name="tgl_penilaian" class="form-control" value="<?= date('Y-m-d') ?>"></div>
        <div class="form-group"><label class="form-label">Periode Risiko</label><input type="text" name="periode_risiko" class="form-control" placeholder="Januari s.d Desember <?= date('Y') ?>"></div>
        <div class="form-group"><label class="form-label">Tgl Update</label><input type="date" name="tgl_update" class="form-control" value="<?= date('Y-m-d') ?>"></div>
      </div>
      <hr style="margin:12px 0;border-color:var(--border)">
      <!-- TTD Section -->
      <div class="form-row-2">
        <?php foreach([['P','kkprTtdP','kkprCvP','kkprUpP','kkprSpP','Pemilik Risiko','nama_ttd_pemilik','nip_ttd_pemilik'],['G','kkprTtdG','kkprCvG','kkprUpG','kkprSpG','Pengelola Risiko','nama_ttd_pengelola','nip_ttd_pengelola']] as [$key,$hidId,$cvId,$upId,$stId,$lbl,$nField,$nipField]): ?>
        <div>
          <label class="form-label"><i class="fas fa-signature"></i> TTD <?= $lbl ?></label>
          <div style="display:flex;gap:6px;margin-bottom:6px">
            <button type="button" class="btn btn-xs btn-primary" onclick="kkprSetMode('<?= $key ?>','draw')"><i class="fas fa-pen"></i> Gambar</button>
            <button type="button" class="btn btn-xs btn-outline" onclick="kkprSetMode('<?= $key ?>','upload')"><i class="fas fa-upload"></i> Upload</button>
          </div>
          <div id="kkprDraw<?= $key ?>">
            <canvas id="<?= $cvId ?>" class="sig-canvas" height="100"></canvas>
            <div class="sig-actions">
              <button type="button" class="btn btn-xs btn-outline" onclick="kkprClear('<?= $cvId ?>','<?= $hidId ?>','<?= $stId ?>')"><i class="fas fa-eraser"></i> Hapus</button>
              <span id="<?= $stId ?>" style="font-size:.72rem;color:var(--text-muted)">Belum ada</span>
            </div>
          </div>
          <div id="kkprUpload<?= $key ?>" style="display:none">
            <div style="border:2px dashed var(--border);border-radius:8px;padding:10px;text-align:center;background:var(--surface2)" ondragover="event.preventDefault()" ondrop="kkprDrop(event,'<?= $hidId ?>','<?= $stId ?>','kkprPrev<?= $key ?>')">
              <input type="file" id="<?= $upId ?>" accept="image/*" style="display:none" onchange="kkprUpload(this,'<?= $hidId ?>','<?= $stId ?>','kkprPrev<?= $key ?>')">
              <button type="button" class="btn btn-xs btn-outline" onclick="document.getElementById('<?= $upId ?>').click()"><i class="fas fa-folder-open"></i> Pilih File</button>
              <div style="font-size:.68rem;color:var(--text-muted);margin-top:3px">PNG/JPG max 2MB</div>
            </div>
            <div id="kkprPrev<?= $key ?>" style="margin-top:6px;display:none;text-align:center">
              <img id="kkprPrevImg<?= $key ?>" style="max-height:70px;border:1px solid var(--border);border-radius:4px;background:repeating-conic-gradient(#e5e5e5 0% 25%,transparent 0% 50%) 0 0/8px 8px">
              <div style="font-size:.7rem;color:var(--success)"><i class="fas fa-check-circle"></i> <span id="kkprPrevName<?= $key ?>"></span></div>
            </div>
          </div>
          <div class="form-row-2" style="margin-top:8px">
            <div class="form-group"><label class="form-label">Nama</label><input type="text" name="<?= $nField ?>" class="form-control" placeholder="Nama lengkap + gelar"></div>
            <div class="form-group"><label class="form-label">NIP</label><input type="text" name="<?= $nipField ?>" class="form-control"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" onclick="closeModal('modalKkprBaru')" class="btn btn-outline">Batal</button>
      <button type="submit" class="btn btn-primary" onclick="kkprSaveSigs()"><i class="fas fa-save"></i> Buat KKPR</button>
    </div>
    </form>
  </div>
</div>

<?php if($activeId && $kkprRow && hasRole('Admin','Risk Manager')): ?>
<!-- Modal Edit Header -->
<div class="modal-overlay" id="modalKkprEdit" style="display:none">
  <div class="modal modal-lg">
    <div class="modal-header"><h3 class="modal-title"><i class="fas fa-edit"></i> Edit KKPR <?= xss($kkprRow['tahun']) ?></h3>
    <button class="btn-close" onclick="closeModal('modalKkprEdit')"><i class="fas fa-times"></i></button></div>
    <form method="POST" action="<?= APP_URL ?>/?page=kkpr">
    <?= csrfField() ?><input type="hidden" name="aksi" value="simpan_header"><input type="hidden" name="id" value="<?= $activeId ?>">
    <input type="hidden" name="ttd_pemilik" id="kkprTtdPE" value="<?= htmlspecialchars($kkprRow['ttd_pemilik']??'') ?>">
    <input type="hidden" name="ttd_pengelola" id="kkprTtdGE" value="<?= htmlspecialchars($kkprRow['ttd_pengelola']??'') ?>">
    <div class="modal-body">
      <div class="form-row-2">
        <div class="form-group"><label class="form-label">Tahun</label><input type="text" name="tahun" class="form-control" value="<?= xss($kkprRow['tahun']) ?>" style="text-align: center;"></div>
        <div class="form-group"><label class="form-label">Unit Pemilik</label><input type="text" name="unit_pemilik_risiko" class="form-control" value="<?= xss($kkprRow['unit_pemilik_risiko'] ?: 'Balai Besar Laboratorium Kesehatan Lingkungan') ?>"></div>
        <div class="form-group"><label class="form-label">Nama Pemilik</label><input type="text" name="nama_pemilik_risiko" class="form-control" list="listUsersWithNip" onchange="autofillNip(this, 'nip_pemilik_risiko')" value="<?= xss($kkprRow['nama_pemilik_risiko']??'') ?>"></div>
        <div class="form-group"><label class="form-label">NIP Pemilik</label><input type="text" name="nip_pemilik_risiko" class="form-control" value="<?= xss($kkprRow['nip_pemilik_risiko']??'') ?>"></div>
        <div class="form-group"><label class="form-label">Nama Pengelola</label><input type="text" name="nama_pengelola_risiko" class="form-control" list="listUsersWithNip" onchange="autofillNip(this, 'nip_pengelola_risiko')" value="<?= xss($kkprRow['nama_pengelola_risiko'] ?: getDefaultPengelola()) ?>"></div>
        <div class="form-group"><label class="form-label">NIP Pengelola</label><input type="text" name="nip_pengelola_risiko" class="form-control" value="<?= xss($kkprRow['nip_pengelola_risiko']??'') ?>"></div>
        <div class="form-group"><label class="form-label">Tujuan</label><input type="text" name="tujuan" class="form-control" value="<?= xss($kkprRow['tujuan']??'') ?>"></div>
        <div class="form-group"><label class="form-label">Sasaran</label><textarea name="sasaran" class="form-control"><?= xss($kkprRow['sasaran']??'') ?></textarea></div>
        <div class="form-group"><label class="form-label">Indikator Kinerja</label><textarea name="indikator_kinerja" class="form-control" rows="4"><?= xss($kkprRow['indikator_kinerja']??'') ?></textarea></div>
        <div class="form-group"><label class="form-label">Target</label><textarea name="target" class="form-control" rows="4"><?= xss($kkprRow['target']??'') ?></textarea></div>
        <div class="form-group"><label class="form-label">Program</label><input type="text" name="program" class="form-control" value="<?= xss($kkprRow['program']??'') ?>"></div>
        <div class="form-group"><label class="form-label">Kegiatan</label><input type="text" name="kegiatan" class="form-control" value="<?= xss($kkprRow['kegiatan']??'') ?>"></div>
        <div class="form-group"><label class="form-label">Tgl Penilaian</label><input type="date" name="tgl_penilaian" class="form-control" value="<?= $kkprRow['tgl_penilaian']??'' ?>"></div>
        <div class="form-group"><label class="form-label">Periode</label><input type="text" name="periode_risiko" class="form-control" value="<?= xss($kkprRow['periode_risiko']??'') ?>"></div>
        <div class="form-group"><label class="form-label">Tgl Update</label><input type="date" name="tgl_update" class="form-control" value="<?= $kkprRow['tgl_update']??'' ?>"></div>
        <input type="hidden" name="nama_ttd_pemilik" value="<?= xss($kkprRow['nama_ttd_pemilik']??'') ?>">
        <input type="hidden" name="nip_ttd_pemilik" value="<?= xss($kkprRow['nip_ttd_pemilik']??'') ?>">
        <input type="hidden" name="nama_ttd_pengelola" value="<?= xss($kkprRow['nama_ttd_pengelola']??'') ?>">
        <input type="hidden" name="nip_ttd_pengelola" value="<?= xss($kkprRow['nip_ttd_pengelola']??'') ?>">
      </div>
      <hr style="margin:10px 0;border-color:var(--border)">
      <div class="form-row-2">
        <?php foreach([['PE','kkprTtdPE','kkprCvPE','kkprUpPE','kkprSpPE','Pemilik'],['GE','kkprTtdGE','kkprCvGE','kkprUpGE','kkprSpGE','Pengelola']] as [$key,$hidId,$cvId,$upId,$stId,$lbl]): ?>
        <div>
          <label class="form-label">Update TTD <?= $lbl ?></label>
          <div style="display:flex;gap:6px;margin-bottom:6px">
            <button type="button" class="btn btn-xs btn-primary" onclick="kkprSetMode('<?= $key ?>','draw')"><i class="fas fa-pen"></i> Gambar</button>
            <button type="button" class="btn btn-xs btn-outline" onclick="kkprSetMode('<?= $key ?>','upload')"><i class="fas fa-upload"></i> Upload</button>
          </div>
          <div id="kkprDraw<?= $key ?>">
            <canvas id="<?= $cvId ?>" class="sig-canvas" height="90"></canvas>
            <div class="sig-actions"><button type="button" class="btn btn-xs btn-outline" onclick="kkprClear('<?= $cvId ?>','<?= $hidId ?>','<?= $stId ?>')"><i class="fas fa-eraser"></i> Hapus</button><span id="<?= $stId ?>" style="font-size:.7rem;color:var(--text-muted)"><?= ($key=='PE'?$kkprRow['ttd_pemilik']:$kkprRow['ttd_pengelola'])?'Ada TTD':'Belum ada' ?></span></div>
          </div>
          <div id="kkprUpload<?= $key ?>" style="display:none">
            <div style="border:2px dashed var(--border);border-radius:8px;padding:10px;text-align:center;background:var(--surface2)" ondragover="event.preventDefault()" ondrop="kkprDrop(event,'<?= $hidId ?>','<?= $stId ?>','kkprPrev<?= $key ?>')">
              <input type="file" id="<?= $upId ?>" accept="image/*" style="display:none" onchange="kkprUpload(this,'<?= $hidId ?>','<?= $stId ?>','kkprPrev<?= $key ?>')">
              <button type="button" class="btn btn-xs btn-outline" onclick="document.getElementById('<?= $upId ?>').click()"><i class="fas fa-folder-open"></i> Pilih File</button>
            </div>
            <div id="kkprPrev<?= $key ?>" style="margin-top:6px;display:none;text-align:center">
              <img id="kkprPrevImg<?= $key ?>" style="max-height:60px;border:1px solid var(--border);border-radius:4px">
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" onclick="closeModal('modalKkprEdit')" class="btn btn-outline">Batal</button>
      <button type="submit" class="btn btn-primary" onclick="kkprSaveSigsEdit()"><i class="fas fa-save"></i> Simpan</button>
    </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Modal Kirim Persetujuan KKPR -->
<div class="modal-overlay" id="modalKirimPersetujuanKKPR" style="display:none">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title"><i class="fas fa-paper-plane"></i> Ajukan Persetujuan KKPR</h3>
      <button class="btn-close" onclick="closeModal('modalKirimPersetujuanKKPR')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/?page=kkpr">
      <?= csrfField() ?>
      <input type="hidden" name="aksi" value="kirim_persetujuan_kkpr">
      <input type="hidden" name="id" value="<?= $activeId ?>">
      <div class="modal-body">
        <p style="margin-bottom:16px; color:var(--text-muted)">Sebelum mengajukan ke Pimpinan, mohon pastikan bahwa:</p>
        <div style="display:flex; flex-direction:column; gap:12px; margin-bottom: 24px;">
          <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
            <input type="checkbox" required style="margin-top:4px;">
            <span>Data header KKPR telah diisi dengan benar.</span>
          </label>
          <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
            <input type="checkbox" required style="margin-top:4px;">
            <span>Risiko telah dimasukkan dan dievaluasi sesuai Profil Risiko.</span>
          </label>
        </div>
        <p style="font-size:0.85rem; color:var(--accent);"><strong>Catatan:</strong> Setelah dikirim, Anda tidak dapat mengubah data sampai Pimpinan memberikan keputusan (Disetujui/Revisi).</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('modalKirimPersetujuanKKPR')">Batal</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Kirim ke Pimpinan</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Tolak / Revisi KKPR -->
<div class="modal-overlay" id="modalTolakPersetujuanKKPR" style="display:none">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title"><i class="fas fa-times"></i> Revisi KKPR</h3>
      <button class="btn-close" onclick="closeModal('modalTolakPersetujuanKKPR')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/?page=kkpr">
      <?= csrfField() ?>
      <input type="hidden" name="aksi" value="reject_kkpr">
      <input type="hidden" name="id" value="<?= $activeId ?>">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Catatan Revisi / Alasan Penolakan <span class="text-danger">*</span></label>
          <textarea name="catatan_revisi" class="form-control" rows="4" required placeholder="Tulis instruksi perbaikan untuk Risk Manager..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('modalTolakPersetujuanKKPR')">Batal</button>
        <button type="submit" class="btn btn-danger" style="background:var(--danger);color:#fff"><i class="fas fa-undo"></i> Kembalikan untuk Revisi</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Matriks Bobot (port dari Profil Risiko) ───────────────────
const matriksBobot = {
  5: {1: 1.5, 2: 1.4, 3: 1.13, 4: 1.15, 5: 1},
  4: {1: 1.2, 2: 1.19, 3: 1.3, 4: 1.16, 5: 1.2},
  3: {1: 1.17, 2: 1.42, 3: 1.43, 4: 1.46, 5: 1.47},
  2: {1: 1, 2: 1.8, 3: 1.83, 4: 1.9, 5: 2.1},
  1: {1: 1, 2: 1.5, 3: 2, 4: 3, 5: 4}
};
function kkprGetBobot(p, d) { return (matriksBobot[p] && matriksBobot[p][d]) ? matriksBobot[p][d] : 1.00; }
function kkprGetPrioritas(n) { return n<=4?5:n<=9?4:n<=14?3:n<=19?2:1; }

// ── Hitung nilai KKPR (auto bobot + prioritas + warna) ────────
const kkprLevels = s => s>=20?'Sangat Tinggi':s>=15?'Tinggi':s>=10?'Sedang':s>=5?'Rendah':'Sangat Rendah';
const kkprBgMap  = s => s>=20?'#dc2626':s>=15?'#f97316':s>=10?'#FFFF00':s>=5?'#22c55e':'#3b82f6';
const kkprClMap  = s => (s>=10&&s<=14)?'#000':'#ffffff';
const kkprWarnaMap = s => s>=20?'#dc2626':s>=15?'#f97316':s>=10?'#FFFF00':s>=5?'#22c55e':'#3b82f6';
const kkprWarnaLabel = s => s>=20?'Merah (Sangat Tinggi)':s>=15?'Oranye (Tinggi)':s>=10?'Kuning (Sedang)':s>=5?'Hijau (Rendah)':'Biru (Sangat Rendah)';

const kkprPLabels = {1:'Jarang',2:'Kecil',3:'Sedang',4:'Besar',5:'Hampir Pasti'};
const kkprDLabels = {1:'T.Signifikan',2:'Kecil',3:'Sedang',4:'Besar',5:'Katastropik'};

function kkprUpdateSlider(key) {
  const map = {
    'p':  {valId:'kkpr_lbl_p_val',  txtId:'kkpr_lbl_p_text',  inpId:'kkpr_p',  labels:kkprPLabels},
    'd':  {valId:'kkpr_lbl_d_val',  txtId:'kkpr_lbl_d_text',  inpId:'kkpr_d',  labels:kkprDLabels},
    'tp': {valId:'kkpr_lbl_tp_val', txtId:'kkpr_lbl_tp_text', inpId:'kkpr_tp', labels:kkprPLabels},
    'td': {valId:'kkpr_lbl_td_val', txtId:'kkpr_lbl_td_text', inpId:'kkpr_td', labels:kkprDLabels},
  };
  const m = map[key]; if(!m) return;
  const v = parseInt(document.getElementById(m.inpId)?.value||1);
  const elV = document.getElementById(m.valId);
  const elT = document.getElementById(m.txtId);
  if(elV) elV.textContent = v;
  if(elT) elT.textContent = '— ' + (m.labels[v] || v);
}

function kkprHitung(){
  const p=+document.getElementById('kkpr_p')?.value||0;
  const d=+document.getElementById('kkpr_d')?.value||0;
  const b=kkprGetBobot(p,d);
  const elB=document.getElementById('kkpr_b'); if(elB) elB.value=b;
  const s=p*d*b;
  const n=Math.round(s);
  if(document.getElementById('kkpr_nilai')) document.getElementById('kkpr_nilai').textContent=n;
  const elPr=document.getElementById('kkpr_prio'); if(elPr) elPr.value=kkprGetPrioritas(s);
  const el=document.getElementById('kkpr_tingkat');
  if(el){el.textContent=kkprLevels(s);el.style.background=kkprBgMap(s);el.style.color=kkprClMap(s);}
  // Update ringkasan P,D,Bobot,Skor
  const sp=document.getElementById('kkpr_sum_p'); if(sp) sp.textContent=p;
  const sd=document.getElementById('kkpr_sum_d'); if(sd) sd.textContent=d;
  const sb=document.getElementById('kkpr_sum_bobot'); if(sb) sb.textContent=b;
  const ss=document.getElementById('kkpr_sum_skor'); if(ss) ss.textContent=n;
  const sb2=document.getElementById('kkpr_sum_skor_box'); if(sb2) sb2.style.background=kkprBgMap(s);
  // Auto warna evaluasi
  const warna=kkprWarnaMap(s);
  const elH=document.getElementById('kkpr_warna_hidden'); if(elH) elH.value=warna;
  const elW=document.getElementById('kkpr_warna_preview');
  if(elW){elW.textContent=kkprWarnaLabel(s);elW.style.background=warna;elW.style.color='#fff';elW.style.border='none';}
  // Auto selera risiko: nilai<=9 → Dalam batas, else → Diatas batas
  const selera = s<=9 ? 'Dalam batas selera risiko' : 'Diatas batas selera risiko';
  const elSH=document.getElementById('kkpr_selera_hidden'); if(elSH) elSH.value=selera;
  const elSP=document.getElementById('kkpr_selera_preview');
  if(elSP){
    elSP.textContent=selera;
    elSP.style.background = s<=9 ? '#FFFF00' : '#ED7D31';
    elSP.style.color = s<=9 ? '#000000' : '#ffffff';
    elSP.style.border='none';
  }
  const penanganan = s<=9 ? 'Menerima risiko' : 'Mitigasi Risiko';
  const elPH=document.getElementById('kkpr_penanganan_hidden'); if(elPH) elPH.value=penanganan;
  const elPP=document.getElementById('kkpr_penanganan_preview');
  if(elPP){
    elPP.textContent=penanganan;
    elPP.style.background = s<=9 ? '#FFFF00' : '#FF0000';
    elPP.style.color = s<=9 ? '#000000' : '#ffffff';
    elPP.style.border='none';
  }
}
function kkprHitungTarget(){
  const p=+document.getElementById('kkpr_tp')?.value||0;
  const d=+document.getElementById('kkpr_td')?.value||0;
  const b=kkprGetBobot(p,d);
  const elB=document.getElementById('kkpr_tb'); if(elB) elB.value=b;
  const s=p*d*b;
  const n=Math.round(s);
  if(document.getElementById('kkpr_tnilai')) document.getElementById('kkpr_tnilai').textContent=n;
  const el=document.getElementById('kkpr_ttingkat');
  if(el){el.textContent=kkprLevels(s);el.style.background=kkprBgMap(s);el.style.color=kkprClMap(s);}
}
kkprHitung(); kkprHitungTarget();
// Init slider labels
['p','d','tp','td'].forEach(k => kkprUpdateSlider(k));

// ── Auto-fill dari master saat pilih kode risiko ──────────────
function kkprAutoFill(sel){
  if(!sel.value) return;
  const opt=sel.options[sel.selectedIndex];
  if(!opt) return;
  const set=(id,v)=>{const el=document.getElementById(id); if(el) el.value=v||'';};
  set('kkpr_nama', opt.getAttribute('data-nama'));
  set('kkpr_sebab', opt.getAttribute('data-sebab'));
  set('kkpr_dampak', opt.getAttribute('data-dampak'));
}

// ── Buka modal tambah ─────────────────────────────────────────
function bukaModalKkprRisiko(){
  document.getElementById('kkprRisikoTitle').innerHTML='<i class="fas fa-plus-circle"></i> Tambah Risiko KKPR';
  document.getElementById('kkpr_row_id').value='0';
  document.getElementById('formKkprRisiko').reset();
  document.getElementById('kkpr_no').value=<?= count($rows)+1 ?>;
  document.getElementById('kkpr_p').value=3;
  document.getElementById('kkpr_d').value=3;
  document.getElementById('kkpr_tp').value=2;
  document.getElementById('kkpr_td').value=2;
  document.getElementById('btnSimpanKkprRisiko').innerHTML='<i class="fas fa-save"></i> Simpan Risiko';
  kkprHitung(); kkprHitungTarget();
  kkprFormTab('ftab1', document.querySelector('.kkpr-ftab'));
  openModal('modalKkprRisiko');
}

// ── Buka modal edit ───────────────────────────────────────────
const kkprEditData = <?= json_encode($editRow ?: null) ?>;
function editKkprRisiko(r){
  const _btnSimpan = document.getElementById('btnSimpanKkprRisiko');
  const _isView = !_btnSimpan; // KKPR terkunci (Menunggu/Disetujui) -> read-only
  document.getElementById('kkprRisikoTitle').innerHTML = _isView
    ? '<i class="fas fa-eye"></i> Lihat Risiko — '+(r.kode_risiko||'')
    : '<i class="fas fa-edit"></i> Edit Risiko — '+(r.kode_risiko||'');
  document.getElementById('kkpr_row_id').value=r.id||0;
  const set=(id,v)=>{const el=document.getElementById(id); if(el) el.value=v??'';};
  set('kkpr_no', r.no_urut);
  set('kkpr_kode', r.kode_risiko);
  set('kkpr_nama', r.nama_risiko);
  set('kkpr_sebab', r.sebab);
  set('kkpr_dampak', r.dampak_uraian);
  set('kkpr_sumber', r.sumber);
  set('kkpr_cuc', r.c_uc);
  set('kkpr_peng_uraian', r.pengendalian_uraian);
  set('kkpr_peng_jenis', r.pengendalian_jenis);
  set('kkpr_peng_efek', r.pengendalian_efektivitas);
  set('kkpr_p', r.probabilitas);
  set('kkpr_d', r.dampak_level);
  set('kkpr_prio', r.prioritas_risiko);
  set('kkpr_penanganan', r.pilihan_penanganan);
  // Sync slider labels
  ['p','d','tp','td'].forEach(k => kkprUpdateSlider(k));
  set('kkpr_rpti', r.rpti_uraian);
  set('kkpr_jadwal', r.rpti_jadwal);
  set('kkpr_tp', r.target_p);
  set('kkpr_td', r.target_d);
  // Jika sebab/dampak kosong, coba ambil dari master via auto-fill
  if (!r.sebab || !r.dampak_uraian) {
    const sel = document.getElementById('kkpr_kode');
    if (sel) kkprAutoFill(sel);
  }
  if (_btnSimpan) _btnSimpan.innerHTML='<i class="fas fa-save"></i> Update Risiko';
  kkprHitung(); kkprHitungTarget();
  kkprFormTab('ftab1', document.querySelector('.kkpr-ftab'));
  openModal('modalKkprRisiko');
}
<?php if($editRow): ?>
// Auto-open edit modal if edit param in URL
document.addEventListener('DOMContentLoaded',()=>{editKkprRisiko(<?= json_encode($editRow) ?>);});
<?php endif; ?>

// ── Canvas TTD ────────────────────────────────────────────────
const kkprCV={};
function kkprInitCanvas(id,hid,stid){
  const c=document.getElementById(id); if(!c) return;
  const ctx=c.getContext('2d'); let draw=false;
  c.width=c.getBoundingClientRect().width||380; c.height=parseInt(c.getAttribute('height'))||100;
  kkprCV[id]={ctx,hasData:false};
  const pos=e=>{const r=c.getBoundingClientRect();const s=e.touches?e.touches[0]:e;return{x:s.clientX-r.left,y:s.clientY-r.top};};
  c.addEventListener('mousedown', e=>{draw=true;ctx.beginPath();const p=pos(e);ctx.moveTo(p.x,p.y);});
  c.addEventListener('mousemove', e=>{if(!draw)return;const p=pos(e);ctx.lineTo(p.x,p.y);ctx.strokeStyle='#1e3a5f';ctx.lineWidth=2;ctx.lineCap='round';ctx.stroke();kkprCV[id].hasData=true;const s=document.getElementById(stid);if(s)s.textContent='✓ TTD terekam';});
  c.addEventListener('mouseup',()=>draw=false); c.addEventListener('mouseleave',()=>draw=false);
  c.addEventListener('touchstart',e=>{e.preventDefault();draw=true;ctx.beginPath();const p=pos(e);ctx.moveTo(p.x,p.y);},{passive:false});
  c.addEventListener('touchmove',e=>{e.preventDefault();if(!draw)return;const p=pos(e);ctx.lineTo(p.x,p.y);ctx.strokeStyle='#1e3a5f';ctx.lineWidth=2;ctx.lineCap='round';ctx.stroke();kkprCV[id].hasData=true;},{passive:false});
  c.addEventListener('touchend',()=>draw=false);
}
function kkprClear(cvid,hid,stid){const c=document.getElementById(cvid);if(c){c.getContext('2d').clearRect(0,0,c.width,c.height);kkprCV[cvid]&&(kkprCV[cvid].hasData=false);}const h=document.getElementById(hid);if(h)h.value='';const s=document.getElementById(stid);if(s)s.textContent='Dihapus';}
function kkprSetMode(key,mode){const d=document.getElementById('kkprDraw'+key);const u=document.getElementById('kkprUpload'+key);if(!d||!u)return;d.style.display=mode==='draw'?'':'none';u.style.display=mode==='upload'?'':'none';}
function kkprUpload(inp,hid,stid,prevId){const f=inp.files[0];if(!f)return;if(f.size>2097152){alert('Max 2MB');return;}const r=new FileReader();r.onload=e=>{const h=document.getElementById(hid);if(h)h.value=e.target.result;const s=document.getElementById(stid);if(s)s.textContent='✓ File TTD siap';const pDiv=document.getElementById(prevId);if(pDiv){pDiv.style.display='';const img=pDiv.querySelector('img');if(img)img.src=e.target.result;const nm=pDiv.querySelector('span');if(nm)nm.textContent=f.name;};};r.readAsDataURL(f);}
function kkprDrop(e,hid,stid,prevId){e.preventDefault();const f=e.dataTransfer.files[0];if(!f)return;const r=new FileReader();r.onload=ev=>{const h=document.getElementById(hid);if(h)h.value=ev.target.result;const s=document.getElementById(stid);if(s)s.textContent='✓ TTD siap';};r.readAsDataURL(f);}
function kkprSetMode(key,mode){const d=document.getElementById('kkprDraw'+key);const u=document.getElementById('kkprUpload'+key);if(!d||!u)return;d.style.display=mode==='draw'?'':'none';u.style.display=mode==='upload'?'':'none';}
function kkprUpload(inp,hid,stid,prevId){const f=inp.files[0];if(!f)return;if(f.size>2097152){alert('Max 2MB');return;}const r=new FileReader();r.onload=e=>{const h=document.getElementById(hid);if(h)h.value=e.target.result;const s=document.getElementById(stid);if(s)s.textContent='✓ File TTD siap';const pDiv=document.getElementById(prevId);if(pDiv){pDiv.style.display='';const img=pDiv.querySelector('img');if(img)img.src=e.target.result;const nm=pDiv.querySelector('span');if(nm)nm.textContent=f.name;};};r.readAsDataURL(f);}
function kkprDrop(e,hid,stid,prevId){e.preventDefault();const f=e.dataTransfer.files[0];if(!f)return;const r=new FileReader();r.onload=ev=>{const h=document.getElementById(hid);if(h)h.value=ev.target.result;const s=document.getElementById(stid);if(s)s.textContent='✓ TTD siap';};r.readAsDataURL(f);}
function kkprSaveSigs(){[['kkprCvP','kkprTtdP'],['kkprCvG','kkprTtdG']].forEach(([c,h])=>{const cv=document.getElementById(c);const hv=document.getElementById(h);if(cv&&hv&&kkprCV[c]?.hasData)hv.value=cv.toDataURL('image/png');});}
function kkprSaveSigsEdit(){[['kkprCvPE','kkprTtdPE'],['kkprCvGE','kkprTtdGE']].forEach(([c,h])=>{const cv=document.getElementById(c);const hv=document.getElementById(h);if(cv&&hv&&kkprCV[c]?.hasData)hv.value=cv.toDataURL('image/png');});}
// Init canvas
['kkprCvP:kkprTtdP:kkprSpP','kkprCvG:kkprTtdG:kkprSpG','kkprCvPE:kkprTtdPE:kkprSpPE','kkprCvGE:kkprTtdGE:kkprSpGE'].forEach(s=>{const[c,h,st]=s.split(':');kkprInitCanvas(c,h,st);});
function swTab(id,btn){
  document.querySelectorAll('.tab-content').forEach(t=>t.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  document.getElementById(id)?.classList.add('active');
  if(btn) {
    btn.classList.add('active');
  } else {
    document.querySelector(`.tab-btn[data-tab="${id}"]`)?.classList.add('active');
  }
  const newKkprButton=document.getElementById('btnKkprBaruHeader');
  if(newKkprButton) newKkprButton.style.display=id==='kkprTabH'?'':'none';
  if(history.replaceState) {
    const u = new URL(window.location);
    u.searchParams.set('tab', id === 'kkprTabH' ? 'header' : 'detail');
    history.replaceState(null, '', u);
  }
}
function bukaTabDetailKkpr(){
  const btn = document.querySelector('.tab-btn[data-tab="kkprTabD"]');
  swTab('kkprTabD', btn);
  document.getElementById('kkprDataRisiko')?.scrollIntoView({behavior:'smooth', block:'start'});
}
function bukaTabHeaderKkpr(){
  const btn = document.querySelector('.tab-btn[data-tab="kkprTabH"]');
  swTab('kkprTabH', btn);
  window.scrollTo({top:0, behavior:'smooth'});
}
function kkprFormTab(tabId,btn){document.querySelectorAll('.kkpr-ftab-content').forEach(t=>t.style.display='none');document.querySelectorAll('.kkpr-ftab').forEach(b=>{b.classList.remove('active');b.style.borderBottom='3px solid transparent';});const el=document.getElementById(tabId);if(el)el.style.display='';btn.classList.add('active');btn.style.borderBottom='3px solid var(--primary)';}
function kkprLanjut(tabId){const btn=document.querySelector('.kkpr-ftab[onclick*="'+tabId+'"]');if(btn)kkprFormTab(tabId,btn);}

// ── Salin header dari Profil Risiko ───────────────────────────
async function loadProfilListForCopy() {
  const sel = document.getElementById('copyProfilSelect');
  const isel = document.getElementById('importProfilSelect');
  if (!sel && !isel) return;
  try {
    const r = await fetch((window.APP_URL||'') + '/api.php/list_for_copy/profil', {
      headers: {'X-CSRF-Token': window.CSRF_TOKEN||''}, credentials:'same-origin'
    });
    const j = await r.json();
    if (j && j.data) {
      if (sel) while (sel.options.length > 1) sel.remove(1);
      if (isel) while (isel.options.length > 1) isel.remove(1);
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
        const label = 'Tahun ' + d.tahun + ' — ' + (d.unit_pemilik_risiko || '') + (tag ? ' (' + tag + ')' : '');

        if (sel) {
          const o = document.createElement('option');
          o.value = d.id;
          o.textContent = label;
          sel.appendChild(o);
        }
        if (isel) {
          const o2 = document.createElement('option');
          o2.value = d.id;
          o2.textContent = label;
          isel.appendChild(o2);
        }
      });
    }
  } catch(e) { console.warn('loadProfilListForCopy', e); }
}
async function copyHeaderToKkpr() {
  const sel = document.getElementById('copyProfilSelect');
  if (!sel || !sel.value) { showToast('Pilih Profil dulu','warning'); return; }
  try {
    const r = await fetch((window.APP_URL||'') + '/api.php/copy_header/profil/' + sel.value, {
      headers: {'X-CSRF-Token': window.CSRF_TOKEN||''}, credentials:'same-origin'
    });
    const j = await r.json();
    if (!j.data) { showToast(j.error||'Gagal salin','error'); return; }
    const d = j.data;
    const set = (n,v) => { const el=document.querySelector('#modalKkprBaru [name="'+n+'"]'); if(el&&v!==null) el.value=v; };
    set('tahun', d.tahun); set('unit_pemilik_risiko', d.unit_pemilik_risiko);
    set('nama_pemilik_risiko', d.nama_pemilik_risiko); set('nip_pemilik_risiko', d.nip_pemilik_risiko); set('nama_pengelola_risiko', d.nama_pengelola_risiko); set('nip_pengelola_risiko', d.nip_pengelola_risiko);
    set('tujuan', d.tujuan); set('sasaran', d.sasaran);
    set('indikator_kinerja', d.indikator_kinerja); set('target', d.target);
    set('program', d.program); set('kegiatan', d.kegiatan);
    set('tgl_penilaian', d.tgl_penilaian); set('periode_risiko', d.periode_risiko);
    set('nama_ttd_pemilik', d.nama_ttd_pemilik); set('nip_ttd_pemilik', d.nip_ttd_pemilik);
    set('nama_ttd_pengelola', d.nama_ttd_pengelola); set('nip_ttd_pengelola', d.nip_ttd_pengelola);
    if (d.ttd_pemilik) { const h=document.getElementById('kkprTtdP'); if(h) h.value=d.ttd_pemilik; const s=document.getElementById('kkprSpP'); if(s) s.textContent='✓ TTD disalin'; }
    if (d.ttd_pengelola) { const h=document.getElementById('kkprTtdG'); if(h) h.value=d.ttd_pengelola; const s=document.getElementById('kkprSpG'); if(s) s.textContent='✓ TTD disalin'; }
    showToast('Header disalin dari Profil. Periksa lalu simpan.','success');
  } catch(e) { showToast('Gagal: '+e.message,'error'); }
}

// ── Impor detail dari Profil Risiko ───────────────────────────
async function importDetailFromProfil() {
  const sel = document.getElementById('importProfilSelect');
  if (!sel || !sel.value) { showToast('Pilih Profil dulu','warning'); return; }
  const kkprId = <?= (int)$activeId ?>;
  if (kkprId <= 0) { showToast('Buka KKPR dulu sebelum impor','warning'); return; }
  if (!confirm('Impor semua risiko dari Profil terpilih ke KKPR ini? Risiko yang sudah ada (kode sama) akan dilewati.')) return;
  try {
    const r = await fetch((window.APP_URL||'') + '/api.php/import_detail/' + sel.value, {
      headers: {'X-CSRF-Token': window.CSRF_TOKEN||''}, credentials:'same-origin'
    });
    const j = await r.json();
    if (!j.data) { showToast(j.error||'Gagal impor','error'); return; }
    const rows = j.data;
    if (rows.length === 0) { showToast('Profil tersebut belum punya detail risiko','warning'); return; }
    // Submit via hidden form untuk setiap baris — kirim batch POST
    const form = document.createElement('form');
    form.method = 'POST'; form.action = (window.APP_URL||'') + '/?page=kkpr';
    const csrf = document.createElement('input'); csrf.type='hidden'; csrf.name='csrf_token'; csrf.value=window.CSRF_TOKEN||'';
    form.appendChild(csrf);
    const aksi = document.createElement('input'); aksi.type='hidden'; aksi.name='aksi'; aksi.value='import_batch';
    form.appendChild(aksi);
    const ik = document.createElement('input'); ik.type='hidden'; ik.name='id_kkpr'; ik.value=kkprId;
    form.appendChild(ik);
    const data = document.createElement('input'); data.type='hidden'; data.name='batch_data'; data.value=JSON.stringify(rows);
    form.appendChild(data);
    document.body.appendChild(form); form.submit();
  } catch(e) { showToast('Gagal: '+e.message,'error'); }
}
loadProfilListForCopy();

const kkprState = { page: 1, lastQuery: '', lastLimit: 10 };
let emptyRow = null;
function filterTableKkpr() {
  const query = (document.getElementById('searchKkpr')?.value || '').toLowerCase();
  const limit = parseInt(document.getElementById('limitKkpr')?.value || 10, 10);
  if (query !== kkprState.lastQuery || limit !== kkprState.lastLimit) {
    kkprState.page = 1;
    kkprState.lastQuery = query;
    kkprState.lastLimit = limit;
  }
  const allRows = [...document.querySelectorAll('#tableKkpr tbody tr:not(.empty-state-row)')];
  
  const visible = allRows.filter(row => {
    if(row.id === 'emptySearchKkpr') return false;
    const text = row.textContent.toLowerCase();
    if (query && !text.includes(query)) return false;
    return true;
  });

  const total = visible.length;
  const pages = Math.max(1, Math.ceil(total / limit));
  if (kkprState.page > pages) kkprState.page = pages;
  const start = (kkprState.page - 1) * limit;

  allRows.forEach(row => { if (row.id !== 'emptySearchKkpr') row.style.display = 'none'; });
  visible.slice(start, start + limit).forEach(row => { row.style.display = ''; });

  const infoEl = document.getElementById('kkprPageInfo');
  if(infoEl) {
    infoEl.textContent = total === 0 ? 'Tidak ada data' : 'Menampilkan ' + (start + 1) + '–' + Math.min(start + limit, total) + ' dari ' + total + ' data';
  }

  const pagesEl = document.getElementById('kkprPages');
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
          kkprState.page = page;
          filterTableKkpr();
          document.getElementById('tableKkpr')?.scrollIntoView({behavior:'smooth', block:'nearest'});
        };
      }
      pagesEl.appendChild(b);
    };

    mkBtn('<i class=\"fas fa-angles-left\"></i>', 1, kkprState.page <= 1, false, 'Halaman Pertama');
    mkBtn('<i class=\"fas fa-chevron-left\"></i>', kkprState.page - 1, kkprState.page <= 1, false, 'Halaman Sebelumnya');
    
    let winStart = Math.max(1, kkprState.page - 2);
    let winEnd = Math.min(pages, winStart + 4);
    if (winEnd - winStart < 4) {
      winStart = Math.max(1, winEnd - 4);
    }
    if (winStart > 1) {
      mkBtn('1', 1, false, kkprState.page === 1);
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
      mkBtn(String(p), p, false, p === kkprState.page);
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
      mkBtn(String(pages), pages, false, kkprState.page === pages);
    }
    mkBtn('<i class=\"fas fa-chevron-right\"></i>', kkprState.page + 1, kkprState.page >= pages, false, 'Halaman Berikutnya');
    mkBtn('<i class=\"fas fa-angles-right\"></i>', pages, kkprState.page >= pages, false, 'Halaman Terakhir');
  }
  
  if (total === 0 && allRows.length > 0) {
    if (!emptyRow) {
      emptyRow = document.createElement('tr');
      emptyRow.id = 'emptySearchKkpr';
      emptyRow.innerHTML = `<td colspan=\"25\"><div class=\"empty-state\"><i class=\"fas fa-search\"></i><p>Pencarian \"<b>${query}</b>\" tidak ditemukan.</p></div></td>`;
      document.querySelector('#tableKkpr tbody').appendChild(emptyRow);
    } else {
      emptyRow.style.display = '';
      emptyRow.innerHTML = `<td colspan=\"25\"><div class=\"empty-state\"><i class=\"fas fa-search\"></i><p>Pencarian \"<b>${query}</b>\" tidak ditemukan.</p></div></td>`;
    }
  } else if (emptyRow) {
    emptyRow.style.display = 'none';
  }
}
setTimeout(() => filterTableKkpr(), 100);
</script>
<style>@media(max-width:900px){.kkpr-grid{grid-template-columns:1fr!important}}</style>










