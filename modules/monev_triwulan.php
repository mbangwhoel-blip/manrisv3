<?php
/**
 * MODUL MONEV TRIWULANAN
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
requireRole('Admin', 'Risk Manager', 'Pimpinan');
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { setFlash('error', 'Token tidak valid'); header('Location: ' . APP_URL . '/?page=monev_triwulan'); exit; }

    $aksi = $_POST['aksi'] ?? '';
    if ($aksi === 'simpan_monev') {
        $idRisiko = (int)($_POST['id_risiko'] ?? 0);
        $triwulan = (int)($_POST['triwulan'] ?? 1);
        $idKkpr   = (int)($_POST['id_kkpr'] ?? 0);

        $pp = (int)($_POST['pantau_p'] ?? 1);
        $pd = (int)($_POST['pantau_d'] ?? 1);
        $pb = getBobot($pp, $pd);
        $pNilai  = round($pp * $pd * $pb);
        $pTingkat = getLevelRisiko((int)$pNilai);

        $upaya = trim($_POST['upaya_pengendalian'] ?? '');
        $link  = trim($_POST['link_data_dukung'] ?? '');
        $kendala = trim($_POST['kendala'] ?? '');
        $rtl = trim($_POST['rencana_tindak_lanjut'] ?? '');

        // Hitung simpulan dengan membandingkan triwulan sebelumnya (atau penilaian awal)
        $nilaiSebelumnya = 0;
        if ($triwulan == 1) {
            $s = $db->prepare("SELECT nilai_risiko FROM kkpr_risiko WHERE id=?");
            $s->bind_param("i", $idRisiko); $s->execute(); $r = $s->get_result()->fetch_assoc();
            if ($r) $nilaiSebelumnya = $r['nilai_risiko'];
        } else {
            $twPrev = $triwulan - 1;
            $s = $db->prepare("SELECT pantau_nilai FROM monev_triwulan WHERE id_risiko=? AND triwulan=?");
            $s->bind_param("ii", $idRisiko, $twPrev); $s->execute(); $r = $s->get_result()->fetch_assoc();
            if ($r) $nilaiSebelumnya = $r['pantau_nilai'];
            else {
                // fallback ke awal
                $s = $db->prepare("SELECT nilai_risiko FROM kkpr_risiko WHERE id=?");
                $s->bind_param("i", $idRisiko); $s->execute(); $r = $s->get_result()->fetch_assoc();
                if ($r) $nilaiSebelumnya = $r['nilai_risiko'];
            }
        }

        $simpulan = 'Tingkat risiko tetap';
        $efektifitas = 'Tidak Efektif';
        if ($pNilai < $nilaiSebelumnya) {
            $simpulan = 'Tingkat risiko mengalami penurunan';
            $efektifitas = 'Efektif';
        } elseif ($pNilai > $nilaiSebelumnya) {
            $simpulan = 'Tingkat risiko mengalami peningkatan';
            $efektifitas = 'Tidak Efektif';
        }

        // Simpan / Update
        $stmt = $db->prepare("SELECT id FROM monev_triwulan WHERE id_risiko=? AND triwulan=?");
        $stmt->bind_param("ii", $idRisiko, $triwulan); $stmt->execute(); $res = $stmt->get_result()->fetch_assoc(); $stmt->close();
        $uid = $_SESSION['user_id'];
        
        if ($res) {
            $sq = "UPDATE monev_triwulan SET pantau_p=?, pantau_d=?, pantau_bobot=?, pantau_nilai=?, pantau_tingkat=?, upaya_pengendalian=?, link_data_dukung=?, simpulan_tingkat=?, efektifitas=?, kendala=?, rencana_tindak_lanjut=?, created_by=? WHERE id=?";
            $s = $db->prepare($sq);
            $s->bind_param("iiddsssssssii", $pp, $pd, $pb, $pNilai, $pTingkat, $upaya, $link, $simpulan, $efektifitas, $kendala, $rtl, $uid, $res['id']);
            $s->execute();
        } else {
            $sq = "INSERT INTO monev_triwulan (id_risiko, triwulan, pantau_p, pantau_d, pantau_bobot, pantau_nilai, pantau_tingkat, upaya_pengendalian, link_data_dukung, simpulan_tingkat, efektifitas, kendala, rencana_tindak_lanjut, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $s = $db->prepare($sq);
            $s->bind_param("iiiiddsssssssi", $idRisiko, $triwulan, $pp, $pd, $pb, $pNilai, $pTingkat, $upaya, $link, $simpulan, $efektifitas, $kendala, $rtl, $uid);
            $s->execute();
        }
        setFlash('success', 'Monev Triwulan '.$triwulan.' berhasil disimpan.');
        header('Location: ' . APP_URL . '/?page=monev_triwulan&id=' . $idKkpr . '&tw=' . $triwulan); exit;
    }
}

// Data
$activeId = (int)($_GET['id'] ?? 0);
$activeTw = (int)($_GET['tw'] ?? 1);
if ($activeTw < 1) $activeTw = 1;
if ($activeTw > 4) $activeTw = 4;

$kkpr_cond = hasRole('Admin', 'Pimpinan') ? "" : "WHERE created_by = " . (int)$_SESSION['user_id'];
$kkprList = $db->query("SELECT id, tahun, unit_pemilik_risiko, nama_pemilik_risiko FROM kkpr_header $kkpr_cond ORDER BY tahun DESC")->fetch_all(MYSQLI_ASSOC);

$kkprRow = null;
$rows = [];

if ($activeId > 0) {
    $s = $db->prepare("SELECT * FROM kkpr_header WHERE id=?");
    $s->bind_param('i', $activeId); $s->execute();
    $kkprRow = $s->get_result()->fetch_assoc(); $s->close();

    if ($kkprRow) {
        // Get all risks for this KKPR
        $s2 = $db->prepare("SELECT * FROM kkpr_risiko WHERE id_kkpr=? ORDER BY SUBSTRING_INDEX(kode_risiko, '.', 1) ASC, CAST(SUBSTRING_INDEX(kode_risiko, '.', -1) AS UNSIGNED) ASC, no_urut, id");
        $s2->bind_param('i', $activeId); $s2->execute();
        $baseRisks = $s2->get_result()->fetch_all(MYSQLI_ASSOC); $s2->close();

        // Get Monev for this Triwulan
        $m_s = $db->prepare("SELECT * FROM monev_triwulan WHERE triwulan=?");
        $m_s->bind_param('i', $activeTw); $m_s->execute();
        $monevCurrRaw = $m_s->get_result()->fetch_all(MYSQLI_ASSOC); $m_s->close();
        $monevCurr = []; foreach ($monevCurrRaw as $m) $monevCurr[$m['id_risiko']] = $m;

        // Get Monev for Previous Triwulan (or Awal if tw=1)
        $monevPrev = [];
        if ($activeTw > 1) {
            $twPrev = $activeTw - 1;
            $m_p = $db->prepare("SELECT * FROM monev_triwulan WHERE triwulan=?");
            $m_p->bind_param('i', $twPrev); $m_p->execute();
            $monevPrevRaw = $m_p->get_result()->fetch_all(MYSQLI_ASSOC); $m_p->close();
            foreach ($monevPrevRaw as $m) $monevPrev[$m['id_risiko']] = $m;
        }

        foreach ($baseRisks as $r) {
            $idr = $r['id'];
            $curr = $monevCurr[$idr] ?? null;
            $prev = $monevPrev[$idr] ?? null;

            if ($activeTw == 1) {
                $pA = $r['probabilitas']; $dA = $r['dampak_level']; $bA = $r['bobot']; $nA = $r['nilai_risiko']; $tA = $r['tingkat_risiko'];
                $linkPrev = '-'; // No previous link for AWAL
            } else {
                $pA = $prev ? $prev['pantau_p'] : $r['probabilitas'];
                $dA = $prev ? $prev['pantau_d'] : $r['dampak_level'];
                $bA = $prev ? $prev['pantau_bobot'] : $r['bobot'];
                $nA = $prev ? $prev['pantau_nilai'] : $r['nilai_risiko'];
                $tA = $prev ? $prev['pantau_tingkat'] : $r['tingkat_risiko'];
                $linkPrev = $prev ? $prev['link_data_dukung'] : '-';
            }

            $r['prev_p'] = $pA;
            $r['prev_d'] = $dA;
            $r['prev_bobot'] = $bA;
            $r['prev_nilai'] = $nA;
            $r['prev_tingkat'] = $tA;
            $r['prev_link'] = $linkPrev;
            
            $r['curr'] = $curr;
            $rows[] = $r;
        }
    }
}
?>
<div class="risiko-hero" style="background:linear-gradient(115deg,#0284c7 0%,#0369a1 55%,#075985 100%)">
  <div class="risiko-hero-copy">
    <div class="risiko-eyebrow"><i class="fas fa-chart-line"></i> Monitoring & Evaluasi</div>
    <h1 class="page-title" style="color:#fff">Monev Manajemen Risiko Triwulanan</h1>
    <p class="page-sub" style="color:rgba(255,255,255,.8)">Monitoring dan evaluasi risiko per triwulan.</p>
  </div>
</div>

<div class="content">
  <div class="container-fluid">
    <div class="card mb-4" style="border-radius:var(--radius-lg);box-shadow:var(--shadow-sm)">
      <div class="card-body">
        <form method="GET" action="" class="row align-items-end g-3">
          <input type="hidden" name="page" value="monev_triwulan">
          <div class="col-md-5">
            <label class="form-label" style="font-weight:600">Pilih Dokumen KKPR (Tahun)</label>
            <select name="id" class="form-control" onchange="this.form.submit()" style="border-radius:var(--radius-sm)">
              <option value="">-- Pilih KKPR --</option>
              <?php foreach($kkprList as $k): ?>
                <option value="<?= $k['id'] ?>" <?= $k['id']==$activeId?'selected':'' ?>>Tahun <?= htmlspecialchars($k['tahun']) ?> - <?= htmlspecialchars($k['unit_pemilik_risiko']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-5">
            <label class="form-label" style="font-weight:600">Pilih Triwulan</label>
            <select name="tw" class="form-control" onchange="this.form.submit()" style="border-radius:var(--radius-sm)">
              <option value="1" <?= $activeTw==1?'selected':'' ?>>Triwulan 1</option>
              <option value="2" <?= $activeTw==2?'selected':'' ?>>Triwulan 2</option>
              <option value="3" <?= $activeTw==3?'selected':'' ?>>Triwulan 3</option>
              <option value="4" <?= $activeTw==4?'selected':'' ?>>Triwulan 4</option>
            </select>
          </div>
        </form>
      </div>
    </div>

    <?php if ($activeId > 0 && $kkprRow): ?>
    <div class="card" style="border-radius:var(--radius-lg);box-shadow:var(--shadow-sm)">
      <div class="card-header" style="background:var(--surface);border-bottom:1px solid var(--border);padding:1.25rem 1.5rem">
        <div class="d-flex justify-content-between align-items-center">
          <h5 class="mb-0" style="font-weight:700">Monev Triwulan <?= $activeTw ?> - Tahun <?= htmlspecialchars($kkprRow['tahun']) ?></h5>
          <div class="d-flex gap-2">
            <button class="btn btn-outline-success btn-sm" onclick="exportTo('excel')"><i class="fas fa-file-excel"></i> Export Excel</button>
            <button class="btn btn-outline-danger btn-sm" onclick="exportTo('pdf')"><i class="fas fa-file-pdf"></i> Export PDF</button>
          </div>
        </div>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-bordered table-hover mb-0" style="font-size:0.8rem">
            <thead style="background:var(--bg);text-align:center;vertical-align:middle">
              <tr>
                <th rowspan="2">NO</th>
                <th rowspan="2">RISIKO</th>
                <th rowspan="2">KODE<br>RISIKO</th>
                <th colspan="5">KONDISI <?= $activeTw == 1 ? 'AWAL' : 'AKHIR TRIWULAN '.($activeTw-1) ?></th>
                <th rowspan="2">UPAYA PENGENDALIAN</th>
                <th rowspan="2">LINK DATA DUKUNG<br><?= $activeTw == 1 ? '' : 'TW '.($activeTw-1) ?></th>
                <th colspan="5">KONDISI AKHIR TRIWULAN <?= $activeTw ?></th>
                <th colspan="2">SIMPULAN</th>
                <th rowspan="2">KENDALA / MASALAH</th>
                <th rowspan="2">RENCANA TINDAK LANJUT</th>
                <th rowspan="2">LINK DATA DUKUNG<br>TW <?= $activeTw ?></th>
                <?php if(hasRole('Admin','Risk Manager')): ?><th rowspan="2">AKSI</th><?php endif; ?>
              </tr>
              <tr>
                <th>P</th><th>D</th><th>BOBOT</th><th>NILAI</th><th>TINGKAT RISIKO</th>
                <th>P</th><th>D</th><th>BOBOT</th><th>NILAI</th><th>TINGKAT RISIKO</th>
                <th>TINGKAT RISIKO</th><th>EFEKTIFITAS</th>
              </tr>
            </thead>
            <tbody>
              <?php if(empty($rows)): ?><tr><td colspan="19" class="text-center">Belum ada risiko</td></tr><?php endif; ?>
              <?php foreach($rows as $i => $r): 
                $c = $r['curr'];
                function bC($t){
                    if($t=='Sangat Tinggi') return '#dc2626'; if($t=='Tinggi') return '#f97316';
                    if($t=='Sedang') return '#eab308'; if($t=='Rendah') return '#22c55e';
                    if($t=='Sangat Rendah') return '#3b82f6'; return '';
                }
              ?>
              <tr>
                <td class="text-center"><?= $i+1 ?></td>
                <td><?= nl2br(htmlspecialchars($r['nama_risiko'])) ?></td>
                <td class="text-center"><?= htmlspecialchars($r['kode_risiko']??'-') ?></td>
                <!-- Baseline -->
                <td class="text-center"><?= $r['prev_p'] ?></td>
                <td class="text-center"><?= $r['prev_d'] ?></td>
                <td class="text-center"><?= $r['prev_bobot'] ?></td>
                <td class="text-center"><?= $r['prev_nilai'] ?></td>
                <td class="text-center" style="background:<?=bC($r['prev_tingkat'])?>;color:#fff;font-weight:bold"><?= $r['prev_tingkat'] ?></td>
                
                <!-- Upaya -->
                <td><?= $c ? nl2br(htmlspecialchars($c['upaya_pengendalian'])) : '-' ?></td>
                <td><?= $r['prev_link'] === '-' ? '-' : '<a href="'.htmlspecialchars($r['prev_link']).'" target="_blank">Link</a>' ?></td>
                
                <!-- Current -->
                <td class="text-center"><?= $c ? $c['pantau_p'] : '-' ?></td>
                <td class="text-center"><?= $c ? $c['pantau_d'] : '-' ?></td>
                <td class="text-center"><?= $c ? $c['pantau_bobot'] : '-' ?></td>
                <td class="text-center"><?= $c ? $c['pantau_nilai'] : '-' ?></td>
                <td class="text-center" style="<?= $c ? 'background:'.bC($c['pantau_tingkat']).';color:#fff;font-weight:bold' : '' ?>"><?= $c ? $c['pantau_tingkat'] : '-' ?></td>
                
                <!-- Simpulan -->
                <td class="text-center"><?= $c ? htmlspecialchars($c['simpulan_tingkat']) : '-' ?></td>
                <td class="text-center"><?= $c ? htmlspecialchars($c['efektifitas']) : '-' ?></td>
                
                <!-- K/RTL/L2 -->
                <td><?= $c ? nl2br(htmlspecialchars($c['kendala'])) : '-' ?></td>
                <td><?= $c ? nl2br(htmlspecialchars($c['rencana_tindak_lanjut'])) : '-' ?></td>
                <td><?= $c && !empty($c['link_data_dukung']) ? '<a href="'.htmlspecialchars($c['link_data_dukung']).'" target="_blank">Link</a>' : '-' ?></td>
                
                <?php if(hasRole('Admin','Risk Manager')): ?>
                <td class="text-center">
                  <button class="btn btn-sm btn-primary" onclick='editMonev(<?= json_encode($r) ?>, <?= $activeTw ?>)'>
                    <i class="fas fa-edit"></i> Isi Monev
                  </button>
                </td>
                <?php endif; ?>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Modal Form -->
<div class="modal fade" id="modalMonev" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="POST" action="">
      <?= csrfField() ?>
      <input type="hidden" name="aksi" value="simpan_monev">
      <input type="hidden" name="id_kkpr" value="<?= $activeId ?>">
      <input type="hidden" name="triwulan" value="<?= $activeTw ?>">
      <input type="hidden" name="id_risiko" id="m_id_risiko">
      
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Isi Monev Triwulan <?= $activeTw ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Upaya Pengendalian</label>
            <textarea name="upaya_pengendalian" id="m_upaya" class="form-control" rows="3"></textarea>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Pantau Probabilitas (1-5)</label>
              <input type="number" name="pantau_p" id="m_p" class="form-control" min="1" max="5" value="1">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Pantau Dampak (1-5)</label>
              <input type="number" name="pantau_d" id="m_d" class="form-control" min="1" max="5" value="1">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Link Data Dukung TW <?= $activeTw ?></label>
            <input type="url" name="link_data_dukung" id="m_link" class="form-control" placeholder="https://...">
          </div>
          <div class="mb-3">
            <label class="form-label">Kendala / Masalah</label>
            <textarea name="kendala" id="m_kendala" class="form-control" rows="2"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Rencana Tindak Lanjut</label>
            <textarea name="rencana_tindak_lanjut" id="m_rtl" class="form-control" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
          <button type="submit" class="btn btn-primary">Simpan</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
function editMonev(r, tw) {
    document.getElementById('m_id_risiko').value = r.id;
    if (r.curr) {
        document.getElementById('m_upaya').value = r.curr.upaya_pengendalian || '';
        document.getElementById('m_p').value = r.curr.pantau_p || 1;
        document.getElementById('m_d').value = r.curr.pantau_d || 1;
        document.getElementById('m_link').value = r.curr.link_data_dukung || '';
        document.getElementById('m_kendala').value = r.curr.kendala || '';
        document.getElementById('m_rtl').value = r.curr.rencana_tindak_lanjut || '';
    } else {
        document.getElementById('m_upaya').value = '';
        document.getElementById('m_p').value = r.prev_p || 1;
        document.getElementById('m_d').value = r.prev_d || 1;
        document.getElementById('m_link').value = '';
        document.getElementById('m_kendala').value = '';
        document.getElementById('m_rtl').value = '';
    }
    var m = new bootstrap.Modal(document.getElementById('modalMonev'));
    m.show();
}

function exportTo(type) {
    let url = '<?= APP_URL ?>/?page=monev_triwulan&id=<?= $activeId ?>&tw=<?= $activeTw ?>&type=' + type;
    window.open(url, '_blank');
}
</script>
