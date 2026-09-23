<?php
/**
 * MODUL PERSETUJUAN — Pusat persetujuan Pimpinan
 * Menampilkan semua dokumen yang menunggu keputusan (Profil Risiko, KKPR,
 * KKPMR) beserta tombol Setujui (form langsung) & Revisi (SweetAlert2).
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
requireRole('Pimpinan');
$db = getDB();

// Ambil dokumen menunggu persetujuan
$pendingProfil = $db->query("
    SELECT p.id, p.tahun, p.unit_pemilik_risiko, u.nama AS pengaju
    FROM profil_risiko p LEFT JOIN users u ON p.created_by = u.id
    WHERE p.status = 'Menunggu Persetujuan'
    ORDER BY p.id DESC
")->fetch_all(MYSQLI_ASSOC) ?: [];

$pendingKkpr = $db->query("
    SELECT h.id, h.tahun, h.unit_pemilik_risiko, u.nama AS pengaju
    FROM kkpr_header h LEFT JOIN users u ON h.created_by = u.id
    WHERE h.status_kkpr = 'Menunggu Persetujuan'
    ORDER BY h.id DESC
")->fetch_all(MYSQLI_ASSOC) ?: [];

$pendingKkpmr = $db->query("
    SELECT h.id, h.tahun, h.unit_pemilik_risiko, u.nama AS pengaju
    FROM kkpr_header h LEFT JOIN users u ON h.created_by = u.id
    WHERE h.status_kkpmr = 'Menunggu Persetujuan'
    ORDER BY h.id DESC
")->fetch_all(MYSQLI_ASSOC) ?: [];

$totalPending = count($pendingProfil) + count($pendingKkpr) + count($pendingKkpmr);
$csrf = csrfToken();
$appUrl = APP_URL;
?>
<div class="risiko-hero profil-risiko-hero" style="background:linear-gradient(115deg,#1e3a5f 0%,#1e40af 55%,#1d4ed8 100%); align-items:flex-start !important;">
  <div class="risiko-hero-copy">
    <div class="risiko-eyebrow"><i class="fas fa-gavel"></i> Pusat Persetujuan</div>
    <h1 class="page-title" style="color:#fff">Persetujuan Dokumen</h1>
    <p class="page-sub" style="color:rgba(255,255,255,.82)">Daftar dokumen yang diajukan Risk Manager dan menunggu keputusan Anda.</p>
  </div>
  <div class="profil-hero-tools risiko-hero-tools-align">
    <div class="stats-grid cols-3" style="width:100%;margin-top:0">
      <div class="stat-card stat-card-glass" style="--ga:#60a5fa;--ga-tint:rgba(96,165,250,.3);--ga-line:rgba(96,165,250,.45);--ga-glow:rgba(96,165,250,.3)">
        <div class="stat-icon"><i class="fas fa-file-signature"></i></div>
        <div class="stat-content"><div class="stat-value"><?= count($pendingProfil) ?></div><div class="stat-label">Profil Risiko</div></div>
      </div>
      <div class="stat-card stat-card-glass" style="--ga:#fbbf24;--ga-tint:rgba(251,191,36,.3);--ga-line:rgba(251,191,36,.5);--ga-glow:rgba(251,191,36,.3)">
        <div class="stat-icon"><i class="fas fa-clipboard-list"></i></div>
        <div class="stat-content"><div class="stat-value"><?= count($pendingKkpr) ?></div><div class="stat-label">KKPR</div></div>
      </div>
      <div class="stat-card stat-card-glass" style="--ga:#34d399;--ga-tint:rgba(52,211,153,.28);--ga-line:rgba(52,211,153,.45);--ga-glow:rgba(52,211,153,.28)">
        <div class="stat-icon"><i class="fas fa-magnifying-glass-chart"></i></div>
        <div class="stat-content"><div class="stat-value"><?= count($pendingKkpmr) ?></div><div class="stat-label">KKPMR</div></div>
      </div>
    </div>
  </div>
</div>

<?php if ($totalPending === 0): ?>
<div class="card"><div class="card-body">
  <div class="empty-state">
    <i class="fas fa-circle-check" style="color:var(--success)"></i>
    <h3>Tidak ada dokumen menunggu persetujuan</h3>
    <p>Saat ini tidak ada dokumen yang diajukan ke Anda.</p>
  </div>
</div></div>
<?php else: ?>

<?php
// Helper render kartu dokumen pending
$renderRow = function (array $row, string $page, string $approveAksi, string $rejectAksi, string $label, string $icon, string $color): void {
    $id = (int)$row['id'];
    $tahun = xss($row['tahun']);
    $unit = xss($row['unit_pemilik_risiko'] ?: '-');
    $pengaju = xss($row['pengaju'] ?: '-');
    $lihat = $GLOBALS['appUrl'] . '/?page=' . $page . '&id=' . $id;
    ?>
    <div class="card" style="margin-bottom:12px">
      <div class="card-body" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:14px 18px">
        <span class="stat-icon" style="background:<?= $color ?>18;color:<?= $color ?>;width:42px;height:42px;border-radius:10px;flex:0 0 auto"><i class="fas <?= $icon ?>"></i></span>
        <div style="flex:1;min-width:200px">
          <div style="font-weight:700;font-size:.92rem"><?= $label ?> &middot; Tahun <?= $tahun ?></div>
          <div style="font-size:.78rem;color:var(--text-muted);margin-top:2px">
            <i class="fas fa-building"></i> <?= $unit ?>
            &nbsp;&middot;&nbsp; <i class="fas fa-user"></i> Diajukan oleh <?= $pengaju ?>
          </div>
        </div>
        <div class="act-btn-group" style="flex:0 0 auto;gap:6px;align-items:center">
          <a href="<?= xss($lihat) ?>" class="btn btn-sm btn-outline" title="Lihat dokumen"><i class="fas fa-eye"></i> Lihat</a>
          <form method="post" action="<?= $GLOBALS['appUrl'] ?>/?page=<?= $page ?>" style="margin:0;display:inline" onsubmit="return confirm('Setujui <?= $label ?> tahun <?= $tahun ?>?')">
            <input type="hidden" name="csrf_token" value="<?= $GLOBALS['csrf'] ?>">
            <input type="hidden" name="aksi" value="<?= $approveAksi ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <button type="submit" class="btn btn-sm btn-success" title="Setujui"><i class="fas fa-check"></i> Setujui</button>
          </form>
          <button type="button" class="btn btn-sm btn-danger" title="Revisi / Tolak"
                  onclick="revisiDokumen('<?= $page ?>','<?= $rejectAksi ?>',<?= $id ?>,'<?= $label ?> tahun <?= $tahun ?>')">
            <i class="fas fa-times"></i> Revisi
          </button>
        </div>
      </div>
    </div>
    <?php
};
?>

<?php if (!empty($pendingProfil)): ?>
<div style="margin:0 0 8px;font-weight:800;font-size:.82rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em"><i class="fas fa-file-signature"></i> Profil Risiko</div>
<?php foreach ($pendingProfil as $row): ?>
  <?= $renderRow($row, 'profil_risiko', 'approve_profil', 'reject_profil', 'Profil Risiko', 'fa-file-signature', '#10b981') ?>
<?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($pendingKkpr)): ?>
<div style="margin:16px 0 8px;font-weight:800;font-size:.82rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em"><i class="fas fa-clipboard-list"></i> Kertas Kerja Penilaian Risiko (KKPR)</div>
<?php foreach ($pendingKkpr as $row): ?>
  <?= $renderRow($row, 'kkpr', 'approve_kkpr', 'reject_kkpr', 'KKPR', 'fa-clipboard-list', '#8b5cf6') ?>
<?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($pendingKkpmr)): ?>
<div style="margin:16px 0 8px;font-weight:800;font-size:.82rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em"><i class="fas fa-magnifying-glass-chart"></i> Kertas Kerja Pemantauan &amp; Reviu (KKPMR)</div>
<?php foreach ($pendingKkpmr as $row): ?>
  <?= $renderRow($row, 'kkpmr', 'approve_kkpmr', 'reject_kkpmr', 'KKPMR', 'fa-magnifying-glass-chart', '#0d9488') ?>
<?php endforeach; ?>
<?php endif; ?>

<?php endif; ?>

<script>
// ── Revisi/Tolak via SweetAlert2 ───────────────────────────────
// Pakai Swal (sudah dimuat global di header) agar tidak bergantung
// pada modal klasik yang pecah di sebagian layout.
function revisiDokumen(page, aksi, id, label) {
  Swal.fire({
    title: 'Revisi ' + label,
    html: '<p style="font-size:.85rem;color:var(--text-muted);margin-bottom:8px">Tulis catatan revisi. Dokumen akan dikembalikan ke Risk Manager.</p>',
    input: 'textarea',
    inputPlaceholder: 'Contoh: Lengkapi data P/D, rencana penanganan, atau target residual...',
    showCancelButton: true,
    confirmButtonText: '<i class="fas fa-paper-plane"></i> Kirim Revisi',
    confirmButtonColor: '#dc2626',
    cancelButtonText: 'Batal',
    inputValidator: function (v) { if (!v || !v.trim()) return 'Catatan revisi wajib diisi'; }
  }).then(function (result) {
    if (!result.isConfirmed) return;
    var f = document.createElement('form');
    f.method = 'POST';
    f.action = '<?= $appUrl ?>/?page=' + encodeURIComponent(page);
    function add(name, value) { var i = document.createElement('input'); i.type = 'hidden'; i.name = name; i.value = value; f.appendChild(i); }
    add('csrf_token', '<?= $csrf ?>');
    add('aksi', aksi);
    add('id', String(id));
    add('catatan_revisi', result.value);
    document.body.appendChild(f);
    f.submit();
  });
}
</script>
