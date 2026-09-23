<?php
/**
 * API AI Chat Assistant — Sistem Manajemen Risiko (MANRIS)
 *
 * Endpoint: POST /api_ai_chat.php
 * Auth: Login Session + CSRF Token
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

/**
 * Cari data risiko aktif di database berdasarkan kode risiko, nomor ID, atau kata kunci operasional
 */
function findRiskInDatabase(?mysqli $db, string $query): ?array {
    if (!$db) {
        $db = getDB();
    }
    $q = trim($query);

    // 1. Pola kode risiko spesifik: A.1 s.d A.26, L.1 s.d L.23, M.1 s.d M.10, I.1 s.d I.7, G.1 s.d G.9
    if (preg_match('/\b([ALMIG])\s*[\.\-]?\s*(\d{1,2})\b/i', $q, $m)) {
        $prefix = strtoupper($m[1]);
        $num = (int)$m[2];
        $codeWithDot = $prefix . '.' . $num;
        $codeWithoutDot = $prefix . $num;

        $stmt = $db->prepare("
            SELECT r.*, u.nama as nama_user, u.username as role_user
            FROM risiko r
            LEFT JOIN users u ON u.id = r.id_user_input
            WHERE (r.kode_risiko = ? OR r.kode_aktif = ? OR r.kode_risiko = ?)
              AND r.deleted_at IS NULL AND r.kode_risiko NOT LIKE 'DEL_%'
            ORDER BY r.id DESC LIMIT 1
        ");
        $stmt->bind_param('sss', $codeWithDot, $codeWithDot, $codeWithoutDot);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            return $res->fetch_assoc();
        }
    }

    // 2. Cek apakah query menyebutkan ID spesifik (#12, ID: 12)
    if (preg_match('/\b(?:id|nomor|no)\s*[:#]?\s*(\d{1,3})\b/i', $q, $m)) {
        $idTarget = (int)$m[1];
        $stmt = $db->prepare("
            SELECT r.*, u.nama as nama_user, u.username as role_user
            FROM risiko r
            LEFT JOIN users u ON u.id = r.id_user_input
            WHERE r.id = ? AND r.deleted_at IS NULL AND r.kode_risiko NOT LIKE 'DEL_%'
            LIMIT 1
        ");
        $stmt->bind_param('i', $idTarget);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            return $res->fetch_assoc();
        }
    }

    // 3. Pencarian kata kunci operasional spesifik sistem/aplikasi
    $keywords = [
        'sakti' => 'sakti',
        'siamang' => 'siamang',
        'magang' => 'magang',
        'pnbp' => 'pnbp',
        'bmn' => 'barang milik negara',
        'persediaan' => 'persediaan',
        'arsip' => 'arsip',
        'korosi' => 'korosi',
        'ups' => 'ups',
        'hplc' => 'hplc',
        'lps' => 'lps',
        'sip' => 'surat izin praktik',
        'surat tugas' => 'surat tugas',
        'gratifikasi' => 'gratifikasi',
        'suap' => 'suap',
        'pemerasan' => 'pemerasan',
    ];

    $lowerQ = mb_strtolower($q);
    foreach ($keywords as $kw => $searchVal) {
        if (str_contains($lowerQ, $kw)) {
            $stmt = $db->prepare("
                SELECT r.*, u.nama as nama_user, u.username as role_user
                FROM risiko r
                LEFT JOIN users u ON u.id = r.id_user_input
                WHERE (r.nama_risiko LIKE CONCAT('%', ?, '%') OR r.penyebab LIKE CONCAT('%', ?, '%'))
                  AND r.deleted_at IS NULL AND r.kode_risiko NOT LIKE 'DEL_%'
                ORDER BY r.id ASC LIMIT 1
            ");
            $stmt->bind_param('ss', $searchVal, $searchVal);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                return $res->fetch_assoc();
            }
        }
    }

    return null;
}

/**
 * Format jawaban komprehensif risiko: Detail Identifikasi, 5 Aksi Mitigasi Real, dan 5 Saran Mitigasi AI
 */
function formatRiskFullResponse(mysqli $db, array $risk): string {
    $idR = (int)$risk['id'];
    $kode = htmlspecialchars_decode($risk['kode_aktif'] ?: $risk['kode_risiko']);
    $nama = htmlspecialchars_decode($risk['nama_risiko']);
    $unit = htmlspecialchars_decode($risk['departemen'] ?: ($risk['pemilik_risiko'] ?: ($risk['role_user'] ?? '-')));
    $penyebab = htmlspecialchars_decode($risk['penyebab'] ?: '-');
    $dampak = htmlspecialchars_decode($risk['dampak'] ?: '-');
    $prob = (int)$risk['probabilitas'];
    $damp = (int)$risk['dampak_level'];
    $skor = (int)($risk['skor_risiko'] ?: ($prob * $damp));
    $level = htmlspecialchars_decode($risk['level_risiko'] ?: 'Sedang');

    // 1. Ambil 5 Aksi Mitigasi dari tabel mitigasi
    $mitigasiList = [];
    $stmtM = $db->prepare("
        SELECT aksi, pic, deadline, status, progress, biaya, catatan
        FROM mitigasi
        WHERE id_risiko = ?
        ORDER BY id ASC
    ");
    $stmtM->bind_param('i', $idR);
    $stmtM->execute();
    $resM = $stmtM->get_result();
    while ($m = $resM->fetch_assoc()) {
        $mitigasiList[] = $m;
    }

    // 2. Ambil 5 Saran Mitigasi dari ai_saran_cache atau fallback
    $saranList = [];
    $stmtS = $db->prepare("SELECT saran_json FROM ai_saran_cache WHERE id_risiko = ? LIMIT 1");
    $stmtS->bind_param('i', $idR);
    $stmtS->execute();
    $resS = $stmtS->get_result();
    if ($resS && $rowS = $resS->fetch_assoc()) {
        $dec = json_decode($rowS['saran_json'], true);
        if (is_array($dec)) {
            $saranList = $dec;
        }
    }
    if (empty($saranList)) {
        $saranList = getSmartMitigasiFallback($risk);
    }

    // Rangkai Tampilan Markdown
    $out = "🎯 **Identitas Risiko & Konteks Organisasi**\n"
         . "• **Kode Risiko**: `$kode`\n"
         . "• **Nama Risiko**: $nama\n"
         . "• **Unit Pengelola**: $unit\n"
         . "• **Penyebab**: $penyebab\n"
         . "• **Dampak**: $dampak\n"
         . "• **Tingkat Risiko**: Level **$level** (Probabilitas: $prob, Dampak: $damp, Besaran Skor: $skor)\n\n";

    // Bagian 5 Aksi Mitigasi
    $totalAksi = count($mitigasiList);
    $out .= "📋 **5 Rencana Aksi Mitigasi Terdaftar di Sistem (Operasional):**\n";
    if ($totalAksi > 0) {
        $no = 1;
        foreach ($mitigasiList as $act) {
            $aksiText = htmlspecialchars_decode($act['aksi']);
            $pic = htmlspecialchars_decode($act['pic'] ?: '-');
            $dl = $act['deadline'] ? date('d/m/Y', strtotime($act['deadline'])) : '-';
            $status = $act['status'] ?: 'Belum Mulai';
            $progress = (int)$act['progress'];
            $biaya = (float)$act['biaya'];
            $biayaStr = $biaya > 0 ? " | Anggaran: Rp " . number_format($biaya, 0, ',', '.') : "";

            $out .= "$no. **[$status - {$progress}%]** $aksiText\n"
                  . "   ↳ *PIC*: $pic | *Target*: $dl$biayaStr\n";
            $no++;
        }
    } else {
        $out .= "*(Belum ada aksi mitigasi yang terdaftar di sistem)*\n";
    }
    $out .= "\n";

    // Bagian 5 Saran Mitigasi
    $out .= "💡 **5 Rekomendasi Saran Mitigasi Asisten Cerdas (ISO 31000):**\n";
    $no = 1;
    foreach ($saranList as $saran) {
        $saranText = htmlspecialchars_decode(is_string($saran) ? $saran : '');
        $saranClean = preg_replace('/^\d+[\.\)\-]\s*/', '', trim($saranText));
        $out .= "$no. $saranClean\n";
        $no++;
    }

    $out .= "\n📌 *Catatan*: **Aksi Mitigasi** adalah komitmen tindak lanjut operasional nyata yang dilaksanakan oleh unit kerja lengkap dengan PIC & target waktu. Sedangkan **Saran Mitigasi** adalah rekomendasi ISO 31000 dari AI untuk memperkuat efektivitas pengendalian risiko.";

    return $out;
}

/**
 * Rangkum daftar risiko untuk unit tertentu (adum, timker1, timker2, lab, upg, atau semua)
 */
function findUnitRisksInDatabase(mysqli $db, string $query): ?string {
    $q = mb_strtolower(trim($query));

    $unitFilter = null;
    $unitLabel = '';

    if (str_contains($q, 'adum') || str_contains($q, 'tata usaha') || str_contains($q, 'administrasi umum')) {
        $unitFilter = "u.username = 'adum'";
        $unitLabel = "Bagian Tata Usaha (ADUM)";
    } elseif (str_contains($q, 'timker1') || str_contains($q, 'timker 1') || str_contains($q, 'tim kerja 1')) {
        $unitFilter = "u.username = 'timker1'";
        $unitLabel = "Tim Kerja 1 (Labkesling & Lingkungan Fisik)";
    } elseif (str_contains($q, 'timker2') || str_contains($q, 'timker 2') || str_contains($q, 'tim kerja 2')) {
        $unitFilter = "u.username = 'timker2'";
        $unitLabel = "Tim Kerja 2 (Kemitraan & Tata Kelola)";
    } elseif (str_contains($q, 'koordinatorlab') || str_contains($q, 'koordinator lab') || $q === 'risiko lab' || str_contains($q, 'daftar risiko lab')) {
        $unitFilter = "u.username = 'koordinatorlab'";
        $unitLabel = "Koordinator Laboratorium";
    } elseif (str_contains($q, 'upg') || str_contains($q, 'daftar risiko gratifikasi') || $q === 'risiko upg' || $q === 'risiko gratifikasi') {
        $unitFilter = "u.username = 'upg'";
        $unitLabel = "Unit Pengendalian Gratifikasi (UPG)";
    } elseif ($q === 'semua risiko' || $q === 'daftar semua risiko' || str_contains($q, 'rekap semua risiko') || str_contains($q, 'seluruh risiko')) {
        $unitFilter = "1=1";
        $unitLabel = "Seluruh Unit Kerja (Konsolidasi MANRIS)";
    }

    if (!$unitFilter) {
        return null;
    }

    $sql = "
        SELECT r.id, r.kode_risiko, r.kode_aktif, r.nama_risiko, r.level_risiko, r.skor_risiko,
               u.username as unit_name,
               (SELECT COUNT(*) FROM mitigasi m WHERE m.id_risiko = r.id) as total_mitigasi,
               (SELECT COUNT(*) FROM ai_saran_cache c WHERE c.id_risiko = r.id) as total_saran
        FROM risiko r
        LEFT JOIN users u ON u.id = r.id_user_input
        WHERE $unitFilter
          AND r.deleted_at IS NULL AND r.kode_risiko NOT LIKE 'DEL_%'
        ORDER BY r.id ASC
    ";
    $res = $db->query($sql);
    if (!$res || $res->num_rows === 0) {
        return null;
    }

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }

    $totalRisiko = count($rows);
    $out = "📊 **Daftar & Status Mitigasi Risiko: $unitLabel**\n\n"
         . "Total Risiko Terdaftar: **$totalRisiko Risiko** (Seluruhnya memiliki **5 Aksi Mitigasi** operasional dan **5 Saran Mitigasi AI** lengkap di database).\n\n";

    $out .= "| No | Kode | Nama Risiko | Level | Aksi Mitigasi |\n";
    $out .= "|:---|:-----|:------------|:------|:--------------|\n";

    $no = 1;
    foreach ($rows as $r) {
        $kode = $r['kode_aktif'] ?: $r['kode_risiko'];
        $nama = mb_strimwidth($r['nama_risiko'], 0, 50, '...');
        $level = $r['level_risiko'] ?: 'Sedang';
        $aksi = $r['total_mitigasi'] . " Aksi (Lengkap)";
        $out .= "| $no | **$kode** | $nama | $level | $aksi |\n";
        $no++;
    }

    $out .= "\n💡 **Panduan Menampilkan Detail**:\n"
          . "Ketik kode risiko (misalnya: *\"mitigasi {$rows[0]['kode_risiko']}\"* atau cukup *\"{$rows[0]['kode_risiko']}\"*) untuk melihat rincian 5 aksi mitigasi operasional dan 5 saran mitigasi strategisnya.";

    return $out;
}

/**
 * Fallback cerdas berbasis Knowledge Base Manajemen Risiko (ISO 31000 & MANRIS)
 */
function getKnowledgeBaseFallback(string $query): string {
    $q = mb_strtolower(trim($query));
    $db = getDB();

    // 0a. Cek kode risiko spesifik (A.1 s.d A.26, L.1 s.d L.23, M.1 s.d M.10, I.1 s.d I.7, G.1 s.d G.9) atau ID
    if (preg_match('/\b([ALMIG])\s*[\.\-]?\s*(\d{1,2})\b/i', $query)
        || preg_match('/\b(?:id|nomor|no)\s*[:#]?\s*(\d{1,3})\b/i', $query)) {
        $matched = findRiskInDatabase($db, $query);
        if ($matched) {
            return formatRiskFullResponse($db, $matched);
        }
    }

    // 0b. Cek permintaan daftar risiko per unit (adum, timker1, timker2, lab, upg, semua)
    $unitReply = findUnitRisksInDatabase($db, $query);
    if ($unitReply !== null) {
        return $unitReply;
    }

    // 1. Identifikasi Risiko
    if ((str_contains($q, 'identifikasi') && !str_contains($q, 'mitigasi') && !str_contains($q, 'saran')) || str_contains($q, 'kenali risiko') || str_contains($q, 'mencari risiko') || str_contains($q, 'menemukan risiko')) {
        return "🔍 **Apa itu Identifikasi Risiko (ISO 31000)?**\n\n"
             . "Identifikasi risiko adalah proses terstruktur untuk **menemukan, mengenali, dan menguraikan potensi risiko** yang dapat menghambat pencapaian target dan mutu layanan di Balai Besar Laboratorium Kesehatan Lingkungan.\n\n"
             . "📌 **Tujuan Utama Identifikasi:**\n"
             . "1. **Deteksi Dini**: Mengantisipasi kegagalan sebelum peristiwa buruk benar-benar terjadi.\n"
             . "2. **Menemukan Penyebab (*Causes*)**: Menggali akar masalah (misal: keterlambatan reagen, kerusakan alat uji, kekurangan SDM, listrik padam).\n"
             . "3. **Memetakan Dampak (*Consequences*)**: Menghitung potensi kerugian operasional, finansial, hukum, atau komplain publik.\n\n"
             . "📝 **Langkah di Aplikasi MANRIS:**\n"
             . "• Masuk ke menu **Identifikasi Risiko** di bilah menu kiri.\n"
             . "• Klik **+ Tambah Risiko** lalu isi perumusan: **[Penyebab] → [Peristiwa Risiko] → [Dampak]**.\n"
             . "• Tentukan nilai **Probabilitas (1-5)** dan **Dampak (1-5)** agar sistem menghitung skor level risiko secara otomatis.";
    }

    // 2. Profil Risiko
    if (str_contains($q, 'profil risiko') || str_contains($q, 'apa itu profil') || str_contains($q, 'dokumen profil') || $q === 'profil') {
        return "📑 **Apa itu Profil Risiko di MANRIS?**\n\n"
             . "Profil Risiko adalah **dokumen komprehensif tahunan yang memetakan seluruh risiko prioritas organisasi** di lingkungan Balai Besar Laboratorium Kesehatan Lingkungan dalam kurun waktu 1 tahun anggaran.\n\n"
             . "🎯 **Fungsi & Kegunaan Utama:**\n"
             . "1. **Menghubungkan Sasaran & Risiko**: Memetakan kaitan antara Program, Kegiatan, dan Indikator Kinerja Kegiatan (IKK) dengan potensi risiko penghambatnya.\n"
             . "2. **Menilai Level Inheren & Residual**: Menghitung besaran risiko sebelum mitigasi (inheren) dan menetapkan target penurunan risiko setelah mitigasi (residual).\n"
             . "3. **Dasar Selera Risiko**: Menjadi acuan resmi pimpinan dalam menentukan risiko mana yang wajib dimitigasi segera.\n"
             . "4. **Akuntabilitas & Legalisasi**: Disusun oleh Risk Manager (ADUM/Timker) dan disetujui resmi oleh Kepala Balai melalui tanda tangan elektronik.\n\n"
             . "💻 **Fitur Menu Profil Risiko di MANRIS:**\n"
             . "• **Tab 1 (Kertas Kerja & Detail Risiko)**: Penilaian skor probabilitas/dampak, rencana penanganan, jadwal, dan PIC.\n"
             . "• **Tab 2 (Info Dokumen / Header)**: Informasi sasaran, IKK master, pejabat penandatangan, dan riwayat revisi.";
    }

    // 3. Kertas Kerja IKK
    if (str_contains($q, 'kertas kerja ikk') || str_contains($q, 'apa itu ikk') || str_contains($q, 'indikator kinerja kegiatan') || (str_contains($q, 'ikk') && !str_contains($q, 'kkpr'))) {
        return "📊 **Apa itu Kertas Kerja IKK di MANRIS?**\n\n"
             . "**IKK (Indikator Kinerja Kegiatan)** adalah tolok ukur kuantitatif dan kualitatif pencapaian target kinerja operasional laboratorium sesuai Rencana Strategis (Renstra) Balai.\n\n"
             . "📌 **Fungsi Kertas Kerja IKK:**\n"
             . "1. **Monitoring Target Renstra**: Memantau realisasi vs target tahunan (misal: jumlah sampel lingkungan teruji, kecepatan layanan sertifikat hasil uji lab).\n"
             . "2. **Identifikasi Kendala/Gap**: Mencatat permasalahan teknis di lapangan yang menyebabkan target tidak tercapai.\n"
             . "3. **Sumber Utama Penyusunan Risiko**: Hambatan pada IKK menjadi dasar perumusan risiko pada menu **Profil Risiko** (risiko dikaitkan langsung ke IKK tertentu).\n"
             . "4. **Pelaporan Kinerja (LAKIP/LkjIP)**: Dapat diekspor langsung ke format Excel dan PDF untuk pelaporan akuntabilitas instansi ke Kemenkes RI.\n\n"
             . "💡 *Akses menu: Klik **Kertas Kerja IKK** pada bilah navigasi kiri.*";
    }

    // 4. Monev (Monitoring dan Evaluasi)
    if (str_contains($q, 'monev') || str_contains($q, 'monitoring') || str_contains($q, 'evaluasi') || str_contains($q, 'pemantauan')) {
        return "🔍 **Apa itu Monev (Monitoring & Evaluasi) di MANRIS?**\n\n"
             . "**Monev Manajemen Risiko** adalah proses pengawasan dan peninjauan berkala (triwulanan / semesteran) terhadap implementasi rencana aksi mitigasi risiko dan efektivitas pengendalian yang sedang berjalan.\n\n"
             . "⚙️ **2 Komponen Utama Monev:**\n"
             . "1. **Monitoring (Pemantauan Rutin)**:\n"
             . "   • Memeriksa apakah PIC/Risk Manager menjalankan aksi mitigasi sesuai jadwal (Target Waktu).\n"
             . "   • Memeriksa kelengkapan bukti dukung fisik (*evidence* seperti foto pelaksanaan, SOP baru, nota dinas, sertifikat kalibrasi instrumen lab).\n\n"
             . "2. **Evaluasi (Penilaian Efektivitas)**:\n"
             . "   • Menilai apakah tindakan mitigasi berhasil menurunkan level risiko (skor residual).\n"
             . "   • Mendeteksi apakah timbul risiko baru (*secondary risk*) selama proses mitigasi berlangsung.\n\n"
             . "📑 **Modul Terkait di MANRIS:**\n"
             . "• **KKPMR (Kertas Kerja Pemantauan & Reviu)**: Pengisian progres realisasi mitigasi per unit per periode.\n"
             . "• **Monev Konsolidasi**: Ringkasan eksekutif seluruh unit (ADUM, Timker 1, 2, 3) dalam bentuk matriks gabungan untuk Pimpinan.";
    }

    // 5. Laporan Manajemen Risiko
    if (str_contains($q, 'laporan') || str_contains($q, 'cetak') || str_contains($q, 'ekspor') || str_contains($q, 'rekapitulasi')) {
        return "📑 **Laporan dalam Sistem Manajemen Risiko MANRIS:**\n\n"
             . "Modul Laporan berfungsi menghasilkan rekapitulasi resmi, visualisasi matriks risiko, dan dokumen pertanggungjawaban untuk pimpinan satker serta pengawas eksternal (Itjen Kemenkes / BPKP).\n\n"
             . "🖨️ **Jenis-Jenis Laporan di MANRIS:**\n"
             . "1. **Laporan Profil Risiko (PDF/Excel)**: Dokumen formal daftar risiko tahunan, matriks peta panas (*heatmap* 5×5), dan lembar pengesahan TTD elektronik.\n"
             . "2. **Laporan Dokumen Kerja KKPR**: Berkas operasional detail penanganan risiko per unit kerja.\n"
             . "3. **Laporan Pemantauan KKPMR**: Rekapitulasi progres monev triwulanan/semesteran beserta perbandingan skor awal vs skor akhir.\n"
             . "4. **Laporan Kinerja IKK**: Laporan capaian target dan hambatan operasional tahun berjalan.\n"
             . "5. **Laporan Monev Konsolidasi**: Laporan terpadu seluruh unit kerja di Balai Besar Laboratorium Kesehatan Lingkungan.\n\n"
             . "💡 *Setiap tabel data di MANRIS dilengkapi tombol **Excel** dan **PDF/Cetak** di bagian atas tabel untuk pengunduhan cepat.*";
    }

    // 6. Tahapan / Alur / Siklus Manajemen Risiko
    if (str_contains($q, 'tahap') || str_contains($q, 'siklus') || str_contains($q, 'alur') || str_contains($q, 'langkah') || str_contains($q, 'proses manajemen')) {
        return "🔄 **Tahapan & Alur Manajemen Risiko Terintegrasi di MANRIS:**\n\n"
             . "1. **Langkah 1: Identifikasi Risiko**\n"
             . "   Mencatat daftar potensi risiko baru, mengelompokkan kategori, menguraikan penyebab dan dampak, serta menentukan skor awal.\n\n"
             . "2. **Langkah 2: Profil Risiko (Penilaian & Rencana)**\n"
             . "   Menghubungkan risiko terpilih dengan Program/Kegiatan/IKK tahunan, menilai level risiko inheren & residual, serta mengajukan persetujuan ke Pimpinan.\n\n"
             . "3. **Langkah 3: KKPR (Kertas Kerja Penilaian Risiko)**\n"
             . "   Dokumen kerja operasional yang memuat rincian mitigasi, PIC penanggung jawab, target waktu, dan bukti dukung mitigasi.\n\n"
             . "4. **Langkah 4: KKPMR (Pemantauan & Reviu / Monev)**\n"
             . "   Evaluasi berkala (triwulanan/semesteran) untuk memastikan tindakan mitigasi berjalan efektif dan skor risiko berhasil diturunkan.";
    }

    // 7. Formula Perumusan Risiko
    if (str_contains($q, 'rumus') || str_contains($q, 'pernyataan') || str_contains($q, 'sebab') || str_contains($q, 'buat kalimat') || str_contains($q, 'formulasi')) {
        return "💡 **Formula Standar Perumusan Risiko (ISO 31000):**\n\n"
             . "Format kalimat yang baik terdiri dari 3 unsur: **[Penyebab] → [Peristiwa Risiko] → [Dampak]**\n\n"
             . "📌 **Contoh Perumusan Standar Laboratorium:**\n"
             . "*\"Karena keterlambatan pasokan reagen dari vendor (Penyebab), maka pengujian sampel air minum tertunda (Peristiwa Risiko), yang berdampak pada keterlambatan penerbitan sertifikat hasil uji lab bagi pelanggan (Dampak).\"*\n\n"
             . "Tip: Hindari menuliskan negasi dari tujuan (misal: *\"tujuan tidak tercapai\"* bukanlah risiko). Fokuslah pada ketidakpastian yang nyata.";
    }

    // 8. Perhitungan Skor & Matriks Risiko (5x5)
    if (str_contains($q, 'skor') || str_contains($q, 'matriks') || str_contains($q, 'level') || str_contains($q, 'hitung') || str_contains($q, '5x5') || str_contains($q, 'kriteria')) {
        return "📊 **Perhitungan Level Risiko di MANRIS (Matriks 5×5):**\n\n"
             . "Rumus: **Besaran Risiko = Nilai Probabilitas (1-5) × Nilai Dampak (1-5)**\n\n"
             . "Tingkatan Level Besaran Risiko:\n"
             . "• **1 – 4 (Biru / Hijau)**: Sangat Rendah / Rendah (Risiko dapat diterima, cukup dipantau rutin)\n"
             . "• **5 – 11 (Kuning)**: Sedang (Perlu rencana aksi mitigasi terkendali)\n"
             . "• **12 – 19 (Oranye)**: Tinggi (Wajib mitigasi intensif & monitoring pimpinan)\n"
             . "• **20 – 25 (Merah)**: Sangat Tinggi / Ekstrem (Wajib tindakan darurat segera)\n\n"
             . "Di menu **Identifikasi Risiko**, sistem menghitung skor dan warna level secara otomatis saat Anda memilih probabilitas dan dampak.";
    }

    // 9. Skala Probabilitas / Kemungkinan
    if (str_contains($q, 'probabilitas') || str_contains($q, 'kemungkinan') || str_contains($q, 'frekuensi')) {
        return "📈 **Skala Probabilitas / Kemungkinan Risiko (Level 1 – 5):**\n\n"
             . "1. **Level 1 (Sangat Jarang / Rare)**: Kemungkinan terjadi < 1 kali dalam 2 tahun (hampir tidak pernah).\n"
             . "2. **Level 2 (Jarang / Unlikely)**: Kemungkinan terjadi 1 kali dalam 1 – 2 tahun.\n"
             . "3. **Level 3 (Sedang / Possible)**: Kemungkinan terjadi 1 kali dalam kurun waktu 6 bulan s.d 1 tahun.\n"
             . "4. **Level 4 (Sering / Likely)**: Terjadi beberapa kali dalam rentang waktu beberapa bulan.\n"
             . "5. **Level 5 (Sangat Sering / Almost Certain)**: Rutin terjadi hampir setiap minggu atau bulan.";
    }

    // 10. Skala Dampak / Akibat
    if (str_contains($q, 'dampak') || str_contains($q, 'akibat') || str_contains($q, 'keparahan') || str_contains($q, 'konsekuensi')) {
        return "💥 **Skala Dampak / Konsekuensi Risiko (Level 1 – 5):**\n\n"
             . "1. **Level 1 (Tidak Signifikan)**: Gangguan sangat kecil, tidak mengganggu operasional pengujian, tidak ada kerugian finansial berarti.\n"
             . "2. **Level 2 (Minor)**: Gangguan layanan singkat (< 1 hari), komplain lisan terselesaikan cepat, biaya perbaikan kecil.\n"
             . "3. **Level 3 (Moderat)**: Layanan terhenti sebagian (1–3 hari), membutuhkan perbaikan sedang, ada komplain tertulis pelanggan.\n"
             . "4. **Level 4 (Mayor)**: Gangguan layanan luas (> 3 hari), kerugian materi cukup besar, berpotensi sanksi administratif atau sorotan pengawas.\n"
             . "5. **Level 5 (Katastropik / Sangat Parah)**: Layanan laboratorium lumpuh total, korban jiwa/insiden fatal K3, pembekuan izin/akreditasi, atau proses hukum pidana.";
    }

    // 11. Hierarki Pengendalian Risiko (Hierarchy of Controls)
    if (str_contains($q, 'hierarki') || str_contains($q, 'hierarchy') || str_contains($q, 'eliminasi') || str_contains($q, 'substitusi') || str_contains($q, 'rekayasa teknis') || str_contains($q, 'apd')) {
        return "🪜 **Hierarki Pengendalian Risiko (*Hierarchy of Controls*):**\n\n"
             . "Urutan prioritas efektivitas tindakan mitigasi dari yang paling kuat hingga pertahanan terakhir:\n"
             . "1. **Eliminasi (*Elimination*) - Paling Efektif**:\n"
             . "   Menghilangkan sumber bahaya secara total dari aktivitas laboratorium/kerja (misal: menghentikan metode uji berbahaya dan beralih ke instrumen otomatis non-destruktif).\n"
             . "2. **Substitusi (*Substitution*)**:\n"
             . "   Mengganti bahan, zat, atau proses berbahaya dengan alternatif yang lebih aman (misal: mengganti pelarut beracun karsinogenik dengan pelarut organik ramah lingkungan).\n"
             . "3. **Rekayasa Teknis (*Engineering Controls*)**:\n"
             . "   Mengisolasi bahaya dari kontak langsung personel (misal: instalasi lemari asam (*fume hood*), Biosafety Cabinet (BSC) terkalibrasi, sensor kebocoran gas otomatis, ventilasi tekanan negatif).\n"
             . "4. **Pengendalian Administratif (*Administrative Controls*)**:\n"
             . "   Mengatur prosedur dan cara kerja orang (misal: pengetatan SOP uji lab, rotasi analis untuk mencegah kelelahan, rambu K3, pelatihan kompetensi berkala).\n"
             . "5. **Alat Pelindung Diri (*APD / PPE*) - Tingkat Terakhir**:\n"
             . "   Melindungi tubuh pekerja jika bahaya residual masih ada (misal: masker respirator, sarung tangan nitril tahan kimia, goggle pengaman, jas lab standar).";
    }

    // 12. Rencana Kontinjensi vs Rencana Mitigasi
    if (str_contains($q, 'kontinjensi') || str_contains($q, 'contingency') || str_contains($q, 'tanggap darurat') || str_contains($q, 'disaster recovery') || str_contains($q, 'rencana darurat')) {
        return "⚡ **Perbedaan Rencana Mitigasi vs Rencana Kontinjensi (*Contingency Plan*):**\n\n"
             . "• **Rencana Mitigasi (*Mitigation Plan / Tindakan Preventif*)**:\n"
             . "  Tindakan proaktif yang dijalankan **SEBELUM** risiko terjadi dengan tujuan menurunkan probabilitas terjadinya (*likelihood*) atau memperkecil dampak (*impact*).\n"
             . "  *Contoh Lab*: Pemeliharaan rutin genset setiap minggu, kalibrasi alat uji AAS/GC tiap tahun, backup database SIMLAB otomatis setiap malam.\n\n"
             . "• **Rencana Kontinjensi (*Contingency Plan / Rencana Tanggap Darurat*)**:\n"
             . "  Prosedur darurat yang telah disiapkan sebelumnya namun **HANYA DIAKTIFKAN JIKA** peristiwa risiko benar-benar terjadi untuk memulihkan layanan dengan cepat.\n"
             . "  *Contoh Lab*: SOP pengalihan otomatis aliran genset saat listrik PLN padam saat running sampel; MOU rujukan uji darurat ke lab mitra saat instrumen utama terbakar/rusak berat; prosedur evakuasi tumpahan bahan B3 pekat (*spill response*).";
    }

    // 13. Kaitan Manajemen Risiko dengan SPIP (PP No. 60 Tahun 2008)
    if (str_contains($q, 'spip') || str_contains($q, 'pp 60') || str_contains($q, 'pengendalian intern') || str_contains($q, 'bpkp') || str_contains($q, 'maturitas spip')) {
        return "🏛️ **Kaitan Manajemen Risiko dengan SPIP (PP No. 60 Tahun 2008):**\n\n"
             . "Manajemen Risiko adalah pilar utama dari **Sistem Pengendalian Intern Pemerintah (SPIP)** di seluruh instansi pemerintah / Kementerian Kesehatan RI.\n\n"
             . "📌 **5 Unsur SPIP Terintegrasi:**\n"
             . "1. **Lingkungan Pengendalian**: Pimpinan membangun integritas, etika, struktur organisasi yang sehat, dan kesadaran risiko (*tone at the top*).\n"
             . "2. **Penilaian Risiko (*Risk Assessment*)**: Fondasi aplikasi MANRIS! Proses sistematis identifikasi dan analisis risiko untuk menetapkan prioritas mitigasi.\n"
             . "3. **Kegiatan Pengendalian**: SOP tertulis, otorisasi transaksi berjenjang, pengamanan fisik instrumen/reagen lab, dan pembagian tugas (*segregation of duties*).\n"
             . "4. **Informasi & Komunikasi**: Pendokumentasian data risiko yang valid, pelaporan transparan via MANRIS ke Pimpinan dan Itjen Kemenkes.\n"
             . "5. **Pemantauan Pengendalian Intern**: Reviu berkala (Monev KKPMR triwulanan/semesteran) serta tindak lanjut rekomendasi audit BPKP/Itjen.\n\n"
             . "💡 *Tingkat Maturitas SPIP Satker sangat ditentukan oleh seberapa konsisten proses manajemen risiko diterapkan dan dibuktikan dengan eviden fisik.*";
    }

    // 14. Indikator Risiko Utama (Key Risk Indicators / KRI)
    if (str_contains($q, 'kri') || str_contains($q, 'key risk indicator') || str_contains($q, 'indikator risiko') || str_contains($q, 'early warning') || str_contains($q, 'sinyal risiko') || str_contains($q, 'peringatan dini')) {
        return "🚨 **Indikator Risiko Utama (*Key Risk Indicators* / KRI):**\n\n"
             . "KRI adalah parameter atau metrik terukur yang digunakan untuk **memantau perubahan tingkat eksposur risiko dan memberikan sinyal peringatan dini (*early warning indicator*)** sebelum insiden merugikan benar-benar terjadi.\n\n"
             . "📌 **Perbedaan KRI vs KPI (IKK):**\n"
             . "• **KPI / IKK (*Lagging*)**: Mengukur hasil akhir capaian kinerja masa lalu (misal: jumlah sampel teruji tahun ini).\n"
             . "• **KRI (*Leading*)**: Mengukur sinyal pemicu potensi kegagalan di masa depan.\n\n"
             . "📌 **Contoh KRI di Laboratorium Kesehatan Lingkungan:**\n"
             . "1. **KRI Fluktuasi Suhu Lemari Reagen**: Suhu naik di atas toleransi >2 jam (sinyal peringatan dini degradasi kualitas reagen sebelum merusak hasil uji).\n"
             . "2. **KRI % Alat Mendekati Jatuh Tempo Kalibrasi**: Alat uji yang belum dikalibrasi dalam sisa waktu 30 hari (sinyal peringatan dini hasil uji lab *invalid*).\n"
             . "3. **KRI Sisa Buffer Stok Reagen Kritis**: Stok tersisa di bawah ambang 15 hari (sinyal peringatan dini penghentian layanan pengujian).\n"
             . "4. **KRI Rasio Jam Lembur Analis**: Jam lembur melonjak >40% (sinyal peringatan dini potensi *human error* dan kecelakaan K3 lab).";
    }

    // 15. Analisis Akar Masalah (Root Cause Analysis / RCA - 5 Whys & Fishbone)
    if (str_contains($q, 'akar masalah') || str_contains($q, 'root cause') || str_contains($q, 'rca') || str_contains($q, '5 why') || str_contains($q, 'fishbone') || str_contains($q, 'ishikawa') || str_contains($q, 'tulang ikan')) {
        return "🔍 **Analisis Akar Masalah (*Root Cause Analysis* - RCA):**\n\n"
             . "Untuk menghasilkan rencana mitigasi yang tepat sasaran, perumusan penyebab risiko harus menggali hingga **akar masalah (*root causes*)**, bukan sekadar melihat gejala permukaan.\n\n"
             . "📌 **2 Metode Utama dalam Manajemen Risiko:**\n"
             . "1. **Metode 5 Whys (5 Mengapa)**:\n"
             . "   Menanyakan *'Mengapa?'* secara berulang (rata-rata 5 kali) hingga menemukan akar kegagalan sistemik.\n"
             . "   *Simulasi Lab*: Hasil uji sampel tertunda → *Mengapa?* Alat spektrofotometer mati mendadak → *Mengapa?* Lampu sumber putus → *Mengapa?* Tidak diganti sesuai jam terbang → *Mengapa?* Tidak ada jadwal pemeliharaan preventif → *Akar Masalah: Belum adanya SOP & anggaran kontrak servis preventif berkala.*\n\n"
             . "2. **Diagram Tulang Ikan (*Fishbone / Ishikawa 6M*)**:\n"
             . "   Mengurai faktor penyebab dari 6 dimensi:\n"
             . "   • **Man (SDM)**: Beban kerja tinggi, analis belum tersertifikasi, kelelahan.\n"
             . "   • **Machine (Alat/Mesin)**: Deviasi sensor, instrumen tua, belum terkalibrasi KAN.\n"
             . "   • **Method (Metode/SOP)**: Instruksi kerja usang, SOP pengujian kurang jelas.\n"
             . "   • **Material (Bahan/Reagen)**: Reagen kadaluarsa, kemurnian pelarut rendah, rantai dingin terputus.\n"
             . "   • **Measurement (Pengukuran)**: Batas deteksi alat (*LOD*) meleset, standar kalibrasi *expired*.\n"
             . "   • **Milieu (Lingkungan)**: Fluktuasi listrik lab, AC ruang instrumen mati, kelembaban tinggi.";
    }

    // 16. Model Tiga Lini Pertahanan (Three Lines Model / Three Lines of Defense)
    if (str_contains($q, 'three lines') || str_contains($q, 'tiga lini') || str_contains($q, '3 lini') || str_contains($q, 'lini pertahanan') || str_contains($q, 'tata kelola risiko')) {
        return "🛡️ **Model Tiga Lini Pertahanan (*Three Lines Model*) Manajemen Risiko:**\n\n"
             . "Kerangka kerja tata kelola yang membagi peran dan tanggung jawab pengendalian risiko secara berjenjang:\n\n"
             . "1. **Lini Pertama (*First Line - Pemilik & Pengelola Operasional*)**:\n"
             . "   • Unit kerja operasional: Bagian Administrasi Umum (ADUM), Tim Kerja (Timker 1, 2, 3), dan Koordinator Lab.\n"
             . "   • *Peran*: Mengidentifikasi risiko harian, menilai skor awal, menjalankan aksi mitigasi, dan mengisi KKPMR.\n\n"
             . "2. **Lini Kedua (*Second Line - Pemantau, Fasilitator & Kepatuhan*)**:\n"
             . "   • Tim Manajemen Risiko Satker, Koordinator Mutu Lab, dan Tim Kepatuhan Internal.\n"
             . "   • *Peran*: Memfasilitasi penerapan standar ISO 31000, mengawal konsistensi data di MANRIS, memantau batas selera risiko, dan menyiapkan laporan agregasi ke Kepala Balai.\n\n"
             . "3. **Lini Ketiga (*Third Line - Asurans Independen*)**:\n"
             . "   • Aparat Pengawasan Intern Pemerintah (APIP) / Inspektorat Jenderal Kemenkes dan Satuan Pengawas Intern (SPI).\n"
             . "   • *Peran*: Melakukan audit independen, mengevaluasi kecukupan desain serta efektivitas implementasi tata kelola risiko satker.";
    }

    // 17. Risiko Inheren, Residual, dan Sekunder
    if (str_contains($q, 'inheren') || str_contains($q, 'residual') || str_contains($q, 'sekunder') || str_contains($q, 'melekat') || str_contains($q, 'risiko sisa')) {
        return "⚖️ **Perbedaan Risiko Inheren, Residual, dan Sekunder:**\n\n"
             . "• **1. Risiko Inheren (*Inherent Risk / Risiko Melekat*)**:\n"
             . "  Besaran tingkat risiko murni sebelum adanya rencana aksi mitigasi atau pengendalian internal baru yang diterapkan. Menggambarkan potensi keparahan bahaya dalam kondisi dasar.\n\n"
             . "• **2. Risiko Residual (*Residual Risk / Risiko Sisa*)**:\n"
             . "  Tingkat risiko yang tersisa setelah rencana aksi mitigasi diimplementasikan dan sistem pengendalian bekerja secara efektif. Target di MANRIS adalah memastikan level residual risiko berada di bawah atau sama dengan **Selera Risiko (*Risk Appetite*)** organisasi.\n\n"
             . "• **3. Risiko Sekunder (*Secondary Risk / Risiko Ikutan*)**:\n"
             . "  Risiko baru yang timbul sebagai akibat langsung dari implementasi tindakan perlakuan/mitigasi risiko tertentu.\n"
             . "  *Contoh*: Beralih ke sistem digital MANRIS untuk mencegah risiko kehilangan arsip fisik memunculkan risiko sekunder baru berupa potensi serangan siber atau server padam.";
    }

    // 18. 8 Prinsip Manajemen Risiko (ISO 31000:2018 Clause 4)
    if (str_contains($q, 'prinsip') || str_contains($q, '8 prinsip') || str_contains($q, 'nilai manajemen')) {
        return "🌐 **8 Prinsip Manajemen Risiko (ISO 31000:2018):**\n\n"
             . "Tujuan pokok manajemen risiko adalah **penciptaan dan perlindungan nilai (*Value Creation & Protection*)**. Delapan prinsip panduannya:\n\n"
             . "1. **Terintegrasi (*Integrated*)**: Bagian integral tak terpisahkan dari seluruh aktivitas perencanaan, tata kelola, dan operasional laboratorium.\n"
             . "2. **Terstruktur & Komprehensif (*Structured & Comprehensive*)**: Pendekatan sistematis menghasilkan hasil yang konsisten dan terukur.\n"
             . "3. **Disesuaikan (*Customized*)**: Disesuaikan secara proporsional dengan konteks internal dan sasaran kinerja satker.\n"
             . "4. **Inklusif (*Inclusive*)**: Melibatkan partisipasi aktif pemangku kepentingan (*stakeholders*) agar wawasan dan persepsinya terakomodasi.\n"
             . "5. **Dinamis (*Dynamic*)**: Adaptif dan tanggap dalam mendeteksi perubahan lingkungan eksternal maupun internal organisasi.\n"
             . "6. **Informasi Terbaik yang Tersedia (*Best Available Information*)**: Mempertimbangkan data historis, tren terkini, serta proyeksi masa depan.\n"
             . "7. **Faktor Manusia & Budaya (*Human & Cultural Factors*)**: Mengakui bahwa kapabilitas, persepsi, dan budaya staf sangat memengaruhi efektivitas mitigasi.\n"
             . "8. **Perbaikan Berkelanjutan (*Continual Improvement*)**: Terus ditingkatkan melalui pembelajaran dan evaluasi berkala (*learning by doing*).";
    }

    // 19. Kerangka Kerja Manajemen Risiko (Framework ISO 31000:2018 Clause 5)
    if (str_contains($q, 'kerangka kerja') || str_contains($q, 'framework') || str_contains($q, 'leadership and commitment') || str_contains($q, 'siklus kepemimpinan')) {
        return "🏛️ **Kerangka Kerja Manajemen Risiko (ISO 31000:2018):**\n\n"
             . "Kerangka kerja berfungsi mengintegrasikan manajemen risiko ke dalam seluruh tata kelola satker:\n\n"
             . "• **Kepemimpinan & Komitmen (*Leadership & Commitment*) - Inti Kerangka**:\n"
             . "  Pimpinan tertinggi (Kepala Balai) wajib memimpin komitmen, menetapkan kebijakan risiko satker, mengalokasikan sumber daya, dan menanamkan akuntabilitas.\n\n"
             . "• **5 Elemen Pendukung**:\n"
             . "  1. **Integrasi (*Integration*)**: Menyatu dalam kultur kerja dan siklus perencanaan anggaran DIPA.\n"
             . "  2. **Desain (*Design*)**: Memahami konteks lingkungan satker, menetapkan selera risiko, dan merancang pembagian peran (Risk Owner, Risk Manager).\n"
             . "  3. **Implementasi (*Implementation*)**: Melaksanakan rencana kerja manajemen risiko melalui perumusan dan mitigasi konkret.\n"
             . "  4. **Evaluasi (*Evaluation*)**: Menilai efektivitas implementasi kerangka kerja secara berkala (Monev triwulanan/semesteran).\n"
             . "  5. **Perbaikan (*Improvement*)**: Menyesuaikan kerangka kerja dengan dinamika perubahan lingkungan strategis secara berkelanjutan.";
    }

    // 20. Proses Penilaian Risiko (Risk Assessment: Identifikasi, Analisis, Evaluasi)
    if (str_contains($q, 'penilaian risiko') || str_contains($q, 'risk assessment') || str_contains($q, 'analisis risiko') || str_contains($q, 'evaluasi risiko')) {
        return "🔬 **Proses Penilaian Risiko (*Risk Assessment* - ISO 31000):**\n\n"
             . "Penilaian risiko adalah proses inti yang mencakup 3 tahapan sistematis berurutan:\n\n"
             . "1. **Identifikasi Risiko (*Risk Identification*)**:\n"
             . "   Menemukan, mengenali, dan mencatat potensi risiko beserta akar penyebab (*causes*) dan konsekuensi dampaknya (*consequences*).\n\n"
             . "2. **Analisis Risiko (*Risk Analysis*)**:\n"
             . "   Memahami sifat risiko dan menghitung tingkat besaran risiko dengan mengukur:\n"
             . "   • Nilai Kemungkinan/Probabilitas (1-5).\n"
             . "   • Nilai Keparahan/Dampak (1-5).\n"
             . "   • Memeriksa kecukupan pengendalian yang ada saat ini (*existing controls*).\n\n"
             . "3. **Evaluasi Risiko (*Risk Evaluation*)**:\n"
             . "   Membandingkan hasil analisis tingkat risiko dengan **Selera Risiko (*Risk Appetite*)** organisasi untuk memutuskan apakah risiko memerlukan rencana aksi mitigasi tambahan (*treatment*) atau dapat diterima (*acceptable*), serta menyusun urutan prioritas penanganan.";
    }

    // 20b. Perbedaan Aksi Mitigasi vs Saran Mitigasi
    if (((str_contains($q, 'beda') || str_contains($q, 'perbedaan') || str_contains($q, 'bedanya')) && (str_contains($q, 'aksi') || str_contains($q, 'tindakan')) && (str_contains($q, 'saran') || str_contains($q, 'rekomendasi'))) || str_contains($q, 'aksi vs saran') || str_contains($q, 'saran vs aksi')) {
        return "⚖️ **Perbedaan Mendasar: Aksi Mitigasi vs Saran Mitigasi di MANRIS:**\n\n"
             . "Dalam tata kelola manajemen risiko (ISO 31000 & SPIP) serta sistem aplikasi MANRIS, terdapat perbedaan mendasar antara **Saran Mitigasi** dan **Aksi Mitigasi**:\n\n"
             . "• **1. Saran Mitigasi (*Mitigation Advice / Treatment Recommendations*)**:\n"
             . "  • **Sifat**: Rekomendasi/usulan strategis konseptual dari Asisten Cerdas AI, auditor (APIP), atau konsultan manajemen risiko.\n"
             . "  • **Fokus**: Menjawab *'Opsi apa saja yang sebaiknya dilakukan untuk menurunkan kemungkinan atau memperkecil dampak risiko?'*\n"
             . "  • **Karakteristik**: Berupa tawaran opsi solusi (belum mengikat), belum ada penunjukan nama personel (PIC), belum ada alokasi anggaran, belum ada tanggal batas waktu pasti, dan belum memiliki nilai progres fisik (0–100%).\n"
             . "  • **Di Aplikasi MANRIS**: Muncul sebagai rekomendasi cerdas pada tombol **'💡 5 Saran Mitigasi Asisten Cerdas'** saat Anda mengklik detail risiko atau bertanya ke chatbot.\n\n"
             . "• **2. Aksi Mitigasi (*Mitigation Action Plan / Rencana Tindak Pengendalian*)**:\n"
             . "  • **Sifat**: Rencana tindakan nyata dan terikat komitmen operasional yang disahkan oleh Pemilik Risiko (*Risk Owner*) dan dijalankan oleh unit pengelola (Risk Manager).\n"
             . "  • **Fokus**: Menjawab *'Siapa yang mengerjakan apa, kapan harus selesai, berapa anggarannya, apa bukti dukungnya, dan bagaimana progres realisasinya?'*\n"
             . "  • **Karakteristik**: Memiliki atribut operasional lengkap di database MANRIS: **Uraian Tindakan Konkret**, **Penanggung Jawab (PIC)**, **Tenggat Waktu (Deadline)**, **Biaya/Anggaran (Rp)**, **Status Pelaksanaan (Belum Mulai / Sedang Berjalan / Selesai / Terlambat)**, **Persentase Progress (0–100%)**, serta **Bukti Fisik & Tanda Tangan Digital**.\n"
             . "  • **Di Aplikasi MANRIS**: Terdaftar resmi pada tabel **Mitigasi Risiko (`page=mitigasi`)** dan dipantau berkala di KKPMR.\n\n"
             . "💡 **Hubungan Keduanya**: Saran mitigasi yang dirumuskan oleh AI dapat langsung dikonversi menjadi Aksi Mitigasi resmi dengan mengklik tombol **'Terapkan'** di modal detail risiko.";
    }

    // 20c. Kasus Spesifik: Presensi Kepegawaian (Pegawai Lupa Rekam Absensi Kehadiran dan Pulang)
    if (str_contains($q, 'absen') || str_contains($q, 'presensi') || str_contains($q, 'kehadiran') || str_contains($q, 'pulang') || str_contains($q, 'lupa absen') || str_contains($q, 'rekam absensi') || (str_contains($q, 'disiplin') && str_contains($q, 'pegawai')) || str_contains($q, 'tukin')) {
        return "💡 **5 Rekomendasi Aksi/Saran Mitigasi untuk Identifikasi Risiko: Pegawai Lupa Melakukan Rekam Absensi Kehadiran dan Pulang**\n\n"
             . "1. **Penerapan Sistem Notifikasi Pengingat Otomatis (*Auto-Reminder*)**:\n"
             . "   Mengaktifkan notifikasi pengingat otomatis terjadwal melalui broadcast WhatsApp Group resmi kantor atau push notification aplikasi presensi mobile (pukul 07.15 WIB sebelum batas masuk dan pukul 15.45 WIB sebelum jam kepulangan kantor) agar seluruh pegawai teringat melakukan *tap* presensi.\n\n"
             . "2. **Penambahan & Optimalisasi Sarana Mesin Absensi Biometrik**:\n"
             . "   Memasang mesin absensi biometrik cadangan (*fingerprint* dan *face recognition*) di setiap lobby gedung utama dan lorong laboratorium dengan koneksi jaringan LAN stabil untuk mengurai antrean panjang saat jam sibuk masuk dan pulang.\n\n"
             . "3. **Penetapan SOP Dispensasi Lupa Absensi dengan Formulir Keterangan Terverifikasi**:\n"
             . "   Menerbitkan SOP dispensasi lupa absensi dengan batasan kuota maksimal 2 kali per semester yang wajib dilengkapi surat keterangan tertulis atasan langsung dan bukti dukung fisik keberadaan di kantor (log aktivitas harian/CCTV) agar tidak merugikan pemotongan tunjangan kinerja (tukin).\n\n"
             . "4. **Rekonsiliasi & Monitoring Harian oleh Pengelola Kepegawaian (Subbag ADUM)**:\n"
             . "   Petugas kepegawaian mengunduh dan mencocokkan log presensi harian setiap pukul 09.00 WIB untuk melakukan konfirmasi dini dan teguran simpatik kepada pegawai yang belum tercatat hadir sebelum data presensi terkunci otomatis di portal pusat.\n\n"
             . "5. **Sosialisasi Berkala PP 94/2021 dan Transparansi Skema Pemotongan Tukin**:\n"
             . "   Mengadakan briefing rutin pada setiap apel pagi bulanan mengenai disiplin jam kerja ASN sesuai PP No. 94 Tahun 2021 serta mengedukasi transparansi formula pemotongan tunjangan kinerja/TPP akibat kelalaian rekam absensi masuk maupun pulang.";
    }

    // 21. Rekomendasi 5 Saran Mitigasi per Identifikasi Risiko (Laboratorium & Publik)
    if (str_contains($q, 'saran mitigasi') || str_contains($q, '5 saran') || str_contains($q, 'lima saran') || str_contains($q, 'rekomendasi mitigasi') || str_contains($q, 'rekomendasi saran') || (str_contains($q, 'mitigasi') && (str_contains($q, 'contoh') || str_contains($q, 'identifikasi')))) {
        // Kasus 1: Reagen & Bahan Habis Pakai Lab
        if (str_contains($q, 'reagen') || str_contains($q, 'bahan') || str_contains($q, 'logistik')) {
            return "💡 **5 Rekomendasi Saran Mitigasi untuk Identifikasi Risiko: Keterlambatan Pasokan Reagen Lab**\n\n"
                 . "1. **Tindakan Preventif**: Menetapkan ambang batas aman persediaan (*buffer stock*) reagen kritis minimal sebesar 2 bulan kebutuhan operasional pengujian.\n"
                 . "2. **Rekayasa Pengadaan**: Mempercepat proses pemesanan e-katalog pada triwulan sebelumnya dengan klausul *Service Level Agreement* (SLA) pengiriman vendor maksimal 14 hari kalender.\n"
                 . "3. **Pengendalian Administratif & Kontraktual**: Mencantumkan klausul denda penalti keterlambatan dan kewajiban penggantian darurat dalam kontrak kerja sama penyedia.\n"
                 . "4. **Otomasi & Peringatan Dini**: Mengaktifkan sistem notifikasi stok minimum otomatis pada SIMLAB/inventaris saat sisa reagen mendekati 20%.\n"
                 . "5. **Rencana Kontinjensi**: Menjalin kesepakatan peminjaman antar-laboratorium (*inter-lab sharing*) dengan balai laboratorium kesehatan lingkungan jejaring terdekat.";
        }

        // Kasus 2: Kerusakan & Kalibrasi Instrumen Pengujian Lab (AAS, GC-MS, HPLC, dsb.)
        if (str_contains($q, 'alat') || str_contains($q, 'instrumen') || str_contains($q, 'kalibrasi') || str_contains($q, 'mesin')) {
            return "💡 **5 Rekomendasi Saran Mitigasi untuk Identifikasi Risiko: Kerusakan & Deviasi Kalibrasi Alat Uji Lab**\n\n"
                 . "1. **Tindakan Preventif**: Menerapkan jadwal pemeliharaan berkala (*preventive maintenance*) mingguan dan bulanan oleh teknisi internal bersertifikat.\n"
                 . "2. **Rekayasa Teknis**: Memasang stabilizer industri dan sistem catu daya bebas gangguan (*UPS online*) serta genset otomatis untuk mencegah lonjakan tegangan merusak sensor instrumen.\n"
                 . "3. **Pengendalian Kalibrasi**: Melakukan re-kalibrasi tahunan terakreditasi KAN serta uji verifikasi harian menggunakan Bahan Acuan Bersertifikat (*CRM*).\n"
                 . "4. **Kontrak Servis Vendor**: Mengikat kontrak servis berkala tahunan (*annual maintenance contract*) resmi dengan agen tunggal pemegang merk alat.\n"
                 . "5. **Rencana Kontinjensi**: Menyiapkan instrumen uji cadangan (*backup instrument*) dan SOP rujukan uji darurat ke lab mitra terakreditasi.";
        }

        // Kasus 3: Keselamatan K3 Personel Lab & Paparan Bahan Kimia Toksik/B3
        if (str_contains($q, 'k3') || str_contains($q, 'keselamatan') || str_contains($q, 'paparan') || str_contains($q, 'kecelakaan') || str_contains($q, 'racun')) {
            return "💡 **5 Rekomendasi Saran Mitigasi untuk Identifikasi Risiko: Keselamatan Personel K3 Lab & Paparan B3**\n\n"
                 . "1. **Eliminasi & Substitusi**: Mengganti bahan kimia karsinogenik/pelarut berbahaya dengan senyawa alternatif ramah lingkungan (*green chemistry*) yang setara.\n"
                 . "2. **Rekayasa Teknis**: Memastikan sertifikasi sertifikasi berkala kecepatan aliran udara lemari asam (*fume hood*) dan Biosafety Cabinet (BSC) terpasang HEPA filter.\n"
                 . "3. **Pengendalian Administratif**: Mewajibkan pelatihan K3 lab, sertifikasi penanganan B3, dan pembaruan berkala Lembar Data Keselamatan Bahan (*MSDS*).\n"
                 . "4. **Proteksi & APD Lengkap**: Menyediakan APD wajib terstandardisasi (respirator uap organik, sarung tangan nitril tebal tahan bahan kimia, goggle, jas lab anti-cairan).\n"
                 . "5. **Rencana Tanggap Darurat**: Menyediakan *eyewash*, *emergency shower*, dan *chemical spill kit* di setiap lorong lab serta menggelar simulasi evakuasi tiap semester.";
        }

        // Kasus 4: Kegagalan IPAL & Pengolahan Limbah Medis/Kimia B3
        if (str_contains($q, 'ipal') || str_contains($q, 'limbah') || str_contains($q, 'lingkungan') || str_contains($q, 'b3')) {
            return "💡 **5 Rekomendasi Saran Mitigasi untuk Identifikasi Risiko: Kegagalan IPAL Lab & Pencemaran Limbah B3**\n\n"
                 . "1. **Tindakan Pemilahan**: Melakukan pemisahan ketat limbah kimia cair B3 di sumber pengujian menggunakan jeriken khusus bersandi warna sesuai karakteristik limbah.\n"
                 . "2. **Pengawasan Baku Mutu**: Menguji parameter baku mutu influen dan efluen IPAL (pH, COD, TSS, logam berat) secara harian sebelum dialirkan ke badan air penerima.\n"
                 . "3. **Kemitraan Berizin**: Mengikat kerja sama resmi dengan transporter dan pengolah akhir limbah B3 yang memiliki izin aktif KLHK dengan manifest Festronik.\n"
                 . "4. **Rekayasa Sensor**: Memasang sensor alarm digital otomatis pada bak penampung limbah untuk mendeteksi kenaikan volume berlebih (*overflow*) dini.\n"
                 . "5. **Rencana Kontinjensi**: Menyediakan tangki retensi cadangan darurat dan stok netralisator kimia asam/basa instan saat terjadi malfungsi bakteri IPAL.";
        }

        // Kasus 5: Kontaminasi Silang & Ketidakakuratan Hasil Pengujian (ISO/IEC 17025)
        if (str_contains($q, 'kontaminasi') || str_contains($q, 'hasil uji') || str_contains($q, 'mutu') || str_contains($q, 'akurasi')) {
            return "💡 **5 Rekomendasi Saran Mitigasi untuk Identifikasi Risiko: Kontaminasi Silang & Penurunan Mutu Hasil Uji**\n\n"
                 . "1. **Zonasi & Tata Ruang**: Memisahkan secara fisik ruang preparasi sampel konsentrasi tinggi, ruang instrumen presisi, dan ruang analisis jejak (*trace analysis*).\n"
                 . "2. **Pengendalian Udara**: Mengatur tata udara bertekanan positif dan filtrasi udara khusus di ruang penimbangan mikro untuk mencegah partikel kontaminan.\n"
                 . "3. **Kontrol Mutu Rutin (*QC*)**: Menyisipkan sampel *blank*, sampel duplikat, dan *spiked sample* pada setiap batch pengujian (minimal 10% dari total sampel).\n"
                 . "4. **Uji Profisiensi**: Wajib berpartisipasi dalam program Uji Profisiensi (UP) nasional/internasional minimal 1 kali per tahun untuk setiap ruang lingkup pengujian.\n"
                 . "5. **Audit Analitik**: Melakukan audit mutu internal dan re-tes oleh analis senior bila deviasi koefisien variasi (*CV*) melampaui batas keberterimaan.";
        }

        // Kasus 6: Keterlambatan Penerbitan Sertifikat Hasil Pengujian (Turnaround Time / TAT)
        if (str_contains($q, 'tat') || str_contains($q, 'sertifikat') || str_contains($q, 'shu') || str_contains($q, 'terlambat') || str_contains($q, 'waktu layanan')) {
            return "💡 **5 Rekomendasi Saran Mitigasi untuk Identifikasi Risiko: Keterlambatan Penerbitan Sertifikat Hasil Pengujian (TAT)**\n\n"
                 . "1. **Digitalisasi Data Uji**: Mengintegrasikan sistem antarmuka instrumen lab langsung ke SIMLAB untuk menghapus entri data manual yang memakan waktu.\n"
                 . "2. **Penandatanganan Digital (TTE)**: Menerapkan Tanda Tangan Elektronik tersertifikasi BSrE bagi validator dan Kepala Balai agar verifikasi dapat dilakukan secara mobile kapan saja.\n"
                 . "3. **Peringatan Batas Waktu**: Mengaktifkan sistem pemantauan *early warning* otomatis di dashboard MANRIS saat sisa waktu pengerjaan sampel < 24 jam.\n"
                 . "4. **Manajemen Kapasitas Dinamis**: Menetapkan prosedur pengalihan sampel dan skema lembur darurat bagi analis saat terjadi lonjakan sampel KLB lingkungan.\n"
                 . "5. **Evaluasi Hambatan Mingguan**: Melakukan reviu mingguan atas tahapan pre-analitik, analitik, dan pasca-analitik untuk memangkas proses yang tidak efisien.";
        }

        // Kasus 7: Keamanan Siber & Server SIMLAB / MANRIS Padam
        if (str_contains($q, 'siber') || str_contains($q, 'server') || str_contains($q, 'simlab') || str_contains($q, 'down') || str_contains($q, 'jaringan')) {
            return "💡 **5 Rekomendasi Saran Mitigasi untuk Identifikasi Risiko: Gangguan Keamanan Siber & Server Aplikasi Padam**\n\n"
                 . "1. **Pencadangan Otomatis (*Automated Backup*)**: Mengaktifkan replikasi cadangan database harian terenkripsi ke server *offsite/cloud* terpisah.\n"
                 . "2. **Penguatan Akses**: Mewajibkan autentikasi ganda (MFA), pergantian password periodik, dan pembatasan hak akses berbasis peran (*RBAC*).\n"
                 . "3. **Proteksi Jaringan**: Memasang Web Application Firewall (WAF), sistem antivirus endpoint terpusat, dan pembaruan patch keamanan sistem secara rutin.\n"
                 . "4. **Audit Kerentanan**: Melakukan pemindaian kerentanan (*vulnerability assessment*) berkala setiap semester oleh tim IT satker.\n"
                 . "5. **Rencana Pemulihan Bencana (*DRP*)**: Menyiapkan server cadangan (*failover*) dengan target *Recovery Time Objective* (RTO) di bawah 2 jam.";
        }

        // Kasus 8: Penyerapan Anggaran DIPA & Pengadaan Barang/Jasa
        if (str_contains($q, 'anggaran') || str_contains($q, 'dipa') || str_contains($q, 'pengadaan') || str_contains($q, 'keuangan')) {
            return "💡 **5 Rekomendasi Saran Mitigasi untuk Identifikasi Risiko: Keterlambatan Pengadaan & Rendahnya Serapan Anggaran DIPA**\n\n"
                 . "1. **Perencanaan Awal (*Early Procurement*)**: Menyusun dokumen Kerangka Acuan Kerja (KAK) dan Rencana Umum Pengadaan (RUP) sebelum tahun anggaran berjalan dimulai.\n"
                 . "2. **Katalog Elektronik**: Memaksimalkan pengadaan instrumen dan reagen lab melalui e-katalog sektoral Kemenkes untuk memangkas waktu lelang umum.\n"
                 . "3. **Monev Realisasi Bulanan**: Menggelar rapat evaluasi penyerapan anggaran secara berkala bersama Pejabat Pembuat Komitmen (PPK) dan Timker.\n"
                 . "4. **Manajemen Risiko Kontrak**: Melakukan reviu kelayakan finansial dan rekam jejak vendor sebelum penetapan pemenang pengadaan barang/jasa.\n"
                 . "5. **Percepatan Revisi Anggaran**: Melakukan pengusulan revisi DIPA antisipatif ke Kemenkeu jika terdapat kegiatan mendesak atau penghematan anggaran.";
        }

        // Panduan Standar Umum: Tepat 5 Saran Mitigasi Wajib untuk Setiap Identifikasi Risiko
        return "💡 **5 Saran Mitigasi Standar untuk Identifikasi Risiko Organisasi (MANRIS):**\n\n"
             . "Untuk setiap risiko yang telah diidentifikasi, susun rencana penanganan yang mencakup 5 butir aksi berikut:\n"
             . "1. **Tindakan Preventif pada Akar Masalah**: Mengeliminasi penyebab utama risiko (*root cause*) sebelum peristiwa kegagalan terjadi.\n"
             . "2. **Rekayasa Teknis & Pengamanan Sistem**: Memasang sensor alarm, pemeliharaan rutin mesin/alat uji, atau otomasi sistem kontrol.\n"
             . "3. **Pengendalian Administratif & SOP**: Memperketat instruksi kerja, rotasi tugas personel, dan pelatihan kompetensi berkala penanggung jawab (PIC).\n"
             . "4. **Tindakan Protektif & APD**: Melindungi personel atau aset secara fisik dari paparan dampak langsung bahaya residual.\n"
             . "5. **Rencana Kontinjensi & Pemulihan Layanan (*Contingency Plan*)**: Menyiapkan SOP darurat dan mitra cadangan agar operasional lekas pulih jika risiko tetap terjadi.\n\n"
             . "👉 *Tip: Ketik misalnya **\"saran mitigasi reagen\"** atau **\"saran mitigasi kalibrasi alat\"** untuk melihat 5 saran mitigasi spesifik.*";
    }

    // 22. Manajemen Risiko Khusus Laboratorium Kesehatan Lingkungan (BBLKL & ISO/IEC 17025)
    if (str_contains($q, 'laboratorium') || str_contains($q, 'lab') || str_contains($q, 'bblkl') || str_contains($q, 'biosafety') || str_contains($q, 'biosecurity') || str_contains($q, '17025') || str_contains($q, 'limbah b3') || str_contains($q, 'uji profisiensi') || str_contains($q, 'reagen')) {
        return "🧪 **Manajemen Risiko Khusus Laboratorium Kesehatan Lingkungan (BBLKL & ISO/IEC 17025):**\n\n"
             . "Laboratorium lingkungan memiliki profil risiko operasional dan teknis yang sangat khas:\n\n"
             . "1. **Risiko Biologis & Biosafety/Biosecurity**:\n"
             . "   • Paparan patogen berbahaya bagi analis lab, kebocoran mikroorganisme infeksius, dan kontaminasi silang sampel air/tanah/udara.\n"
             . "   • *Mitigasi*: Penggunaan Biosafety Cabinet (BSC) terkalibrasi, sistem ventilasi hepa filter bertekanan negatif, kepatuhan APD level lengkap, dan SOP dekontaminasi berkala.\n\n"
             . "2. **Risiko Akurasi Hasil Uji & Keandalan Teknis (ISO/IEC 17025)**:\n"
             . "   • Instrumen pengujian (AAS, GC-MS, HPLC, spektrofotometer) mengalami deviasi kalibrasi; reagen rusak karena rantai dingin (*cold chain*) terputus.\n"
             . "   • *Mitigasi*: Kalibrasi berkala oleh laboratorium kalibrasi terakreditasi KAN, partisipasi rutin Uji Profisiensi (UP) / uji banding antarlab, dan sensor suhu chiller otomatis 24/7.\n\n"
             . "3. **Risiko Limbah B3 & Pencemaran Lingkungan**:\n"
             . "   • Kegagalan instalasi pengolahan air limbah (IPAL) lab, tumpahan pelarut organik pekat, dan limbah medis infeksius.\n"
             . "   • *Mitigasi*: Penyediaan *chemical spill kit*, manifest limbah B3 berizin resmi, dan pengujian baku mutu berkala influen/efluen lab.\n\n"
             . "4. **Risiko Layanan & Turnaround Time (TAT)**:\n"
             . "   • Keterlambatan penerbitan Sertifikat Hasil Pengujian (SHU) bagi pelanggan akibat antrean sampel melonjak pada musim KLB.\n"
             . "   • *Mitigasi*: Integrasi aplikasi SIMLAB, alokasi kapasitas darurat, dan SOP prioritas penanganan sampel darurat.";
    }

    // 22. Budaya Sadar Risiko (Risk-Aware Culture)
    if (str_contains($q, 'budaya risiko') || str_contains($q, 'budaya sadar') || str_contains($q, 'risk culture') || str_contains($q, 'no blame') || str_contains($q, 'tone at the top')) {
        return "🌱 **Membangun Budaya Sadar Risiko (*Risk-Aware Culture*):**\n\n"
             . "Budaya risiko adalah pola pikir, nilai, dan perilaku bersama seluruh pegawai satker dalam menghadapi ketidakpastian:\n\n"
             . "1. **Keteladanan Pimpinan (*Tone at the Top*)**: Pimpinan secara aktif memimpin pembahasan risiko pada setiap rapat koordinasi dan menjadi teladan kepatuhan.\n"
             . "2. **Budaya Tanpa Menyalahkan (*No-Blame Culture*)**: Mendorong staf untuk berani dan cepat melaporkan insiden nyaris celaka (*near-miss*) atau kendala lapangan tanpa takut dihukum.\n"
             . "3. **Komunikasi Terbuka Dua Arah**: Informasi potensi risiko mengalir transparan dari analis teknis ke Pimpinan Satker dan sebaliknya.\n"
             . "4. **Apresiasi & Motivasi**: Memberikan penghargaan kepada unit kerja (ADUM/Timker) yang proaktif menurunkan skor residual dan tertib mengisi KKPMR.\n"
             . "5. **Pembelajaran dari Kegagalan (*Learning from Events*)**: Setiap deviasi hasil uji atau kendala logistik dijadikan bahan evaluasi pembaruan SOP.";
    }

    // 23. Daftar Risiko / Risk Register
    if (str_contains($q, 'daftar risiko') || str_contains($q, 'risk register')) {
        return "📋 **Daftar Risiko (*Risk Register*) di MANRIS:**\n\n"
             . "Risk Register adalah dokumen induk komprehensif yang mencatat seluruh profil risiko teridentifikasi di lingkungan Balai Besar Laboratorium Kesehatan Lingkungan.\n\n"
             . "📌 **Kolom-Kolom Standar Risk Register di MANRIS:**\n"
             . "• **Identitas**: No, Kode Risiko, Unit Kerja (ADUM/Timker), Kaitan Program/IKK Renstra.\n"
             . "• **Deskripsi Risiko**: Formulasi kalimat [Penyebab] → [Peristiwa Risiko] → [Dampak].\n"
             . "• **Penilaian Inheren**: Nilai Probabilitas (1-5), Nilai Dampak (1-5), Besaran Skor, dan Kategori Level Warna.\n"
             . "• **Pengendalian yang Ada (*Existing Controls*)**: SOP atau sarana kontrol yang sudah berjalan saat ini.\n"
             . "• **Rencana Aksi Mitigasi**: Uraian penanganan baru, PIC penanggung jawab, target waktu (deadline), dan bukti dukung fisik.\n"
             . "• **Target Risiko Residual**: Target penurunan skor probabilitas, dampak, dan level risiko setelah mitigasi tuntas.";
    }

    // 24. Glosarium & Istilah Penting Manajemen Risiko
    if (str_contains($q, 'glosarium') || str_contains($q, 'kamus') || str_contains($q, 'istilah risiko') || str_contains($q, 'arti singkatan')) {
        return "📖 **Glosarium Istilah Kunci Manajemen Risiko MANRIS:**\n\n"
             . "• **Risk Owner (Pemilik Risiko)**: Kepala Balai yang memegang akuntabilitas tertinggi atas pencapaian sasaran dan pengesahan profil risiko.\n"
             . "• **Risk Manager (Pengelola Risiko)**: Koordinator Unit Kerja (ADUM / Timker) yang bertugas menyusun analisis dan memantau mitigasi harian.\n"
             . "• **Heatmap (Peta Panas)**: Matriks visual 5×5 (Hijau, Kuning, Oranye, Merah) yang menggambarkan sebaran konsentrasi tingkat risiko.\n"
             . "• **Risk Exposure (Eksposur Risiko)**: Besaran potensi kerugian atau tingkat bahaya yang dihadapi satker pada periode berjalan.\n"
             . "• **Existing Control**: Sistem pengendalian intern yang telah ada dan sedang berjalan saat ini.\n"
             . "• **Near-Miss (Nyaris Celaka)**: Kejadian bahaya yang hampir menimbulkan kerugian namun berhasil terhindarkan secara kebetulan.\n"
             . "• **KKPR**: Kertas Kerja Penilaian Risiko (perencanaan awal tahun).\n"
             . "• **KKPMR**: Kertas Kerja Pemantauan & Reviu (monitoring realisasi berkala).";
    }

    // 25. Strategi Aksi Mitigasi (ISO 31000)
    if (str_contains($q, 'mitigasi') || str_contains($q, 'pengendalian') || str_contains($q, 'aksi') || str_contains($q, 'cegah') || str_contains($q, 'penanganan')) {
        return "🛡️ **Strategi Aksi Mitigasi Risiko (ISO 31000):**\n\n"
             . "Ada 4 opsi respon risiko utama:\n"
             . "1. **Menghindari (Avoid)**: Menghentikan atau mengubah aktivitas yang menimbulkan risiko.\n"
             . "2. **Mengurangi Kemungkinan (Mitigate Likelihood)**: Pengetatan SOP, pemeliharaan preventif alat, pelatihan kompetensi personel lab.\n"
             . "3. **Mengurangi Dampak (Mitigate Impact)**: Sistem backup data, genset cadangan, asuransi, rencana pemulihan bencana (disaster recovery).\n"
             . "4. **Mentransfer (Share/Transfer)**: Kerjasama rujukan lab pihak ketiga, garansi SLA penyedia barang/jasa.\n\n"
             . "👉 Setiap aksi mitigasi di MANRIS harus memiliki **Target Waktu (Deadline)**, **PIC Penanggung Jawab**, dan **Bukti Dukung**.";
    }

    // 26. Kategori Risiko
    if (str_contains($q, 'kategori') || str_contains($q, 'jenis risiko') || str_contains($q, 'tipe risiko') || str_contains($q, 'klasifikasi')) {
        return "📂 **Kategori Risiko di MANRIS:**\n\n"
             . "• **Risiko Operasional**: Terkait kendala proses kerja lab, kerusakan instrumen uji, reagen kadaluarsa, keselamatan personel (K3), atau gangguan sistem IT/jaringan.\n"
             . "• **Risiko Kepatuhan (Hukum/Regulasi)**: Terkait kepatuhan terhadap permenkes, standar akreditasi laboratorium (ISO/IEC 17025 / 15189), atau temuan audit BPK/Itjen.\n"
             . "• **Risiko Finansial**: Hambatan penyerapan anggaran DIPA, keterlambatan revisi anggaran, atau kehilangan aset/BBMN.\n"
             . "• **Risiko Reputasi**: Penurunan kepercayaan publik, aduan pelanggan atas hasil uji mutu lingkungan, atau pemberitaan negatif.\n"
             . "• **Risiko Strategis**: Hambatan pencapaian sasaran strategis Balai dan target Indikator Kinerja Utama (IKU/IKK).";
    }

    // 27. Peran Pengguna (Risk Manager, ADUM, Timker, Pimpinan)
    if (str_contains($q, 'peran') || str_contains($q, 'pengelola') || str_contains($q, 'pemilik risiko') || str_contains($q, 'risk manager') || str_contains($q, 'adum') || str_contains($q, 'timker') || str_contains($q, 'pimpinan') || str_contains($q, 'tanggung jawab')) {
        return "👥 **Struktur Peran Pengelolaan Risiko di MANRIS:**\n\n"
             . "• **Pimpinan / Pemilik Risiko (*Risk Owner*)**:\n"
             . "  Kepala Balai yang memegang mandat tertinggi. Menetapkan arah selera risiko serta memberikan persetujuan (approval) dan tanda tangan elektronik pada dokumen Profil Risiko & KKPR.\n\n"
             . "• **Risk Manager / Pengelola Risiko**:\n"
             . "  Koordinator Unit Kerja (seperti **Bagian Administrasi Umum / ADUM**, **Tim Kerja / Timker 1, 2, 3**). Bertanggung jawab mengidentifikasi risiko unit, menyusun mitigasi, dan memantau realisasi mitigasi di KKPMR.\n\n"
             . "• **Staff / Pelaksana Lapangan**:\n"
             . "  Personel teknis yang mengusulkan kejadian risiko baru dari aktivitas kerja sehari-hari.\n\n"
             . "• **Administrator Sistem**:\n"
             . "  Mengelola master data, hak akses akun, audit trail, serta integrasi bot Telegram, WhatsApp, dan AI.";
    }

    // 28. Selera Risiko, Toleransi Risiko, & Kapasitas Risiko
    if (str_contains($q, 'selera') || str_contains($q, 'appetite') || str_contains($q, 'toleransi') || str_contains($q, 'kapasitas risiko')) {
        return "🎯 **Konsep Selera Risiko, Toleransi Risiko, & Kapasitas Risiko:**\n\n"
             . "• **Kapasitas Risiko (*Risk Capacity*)**:\n"
             . "  Batas jumlah risiko absolut maksimum yang mampu ditanggung oleh satker sebelum organisasi mengalami kegagalan fatal/lumpuh secara operasional maupun hukum.\n\n"
             . "• **Selera Risiko (*Risk Appetite*)**:\n"
             . "  Tingkat besaran risiko yang secara sadar **bersedia diterima oleh Kepala Balai/Pimpinan** dalam upaya mengejar sasaran kinerja Renstra & IKK.\n\n"
             . "• **Toleransi Risiko (*Risk Tolerance*)**:\n"
             . "  Batas variasi deviasi yang masih dapat diterima di sekitar target sasaran operasional tanpa melanggar batas selera risiko.\n\n"
             . "📌 **Pedoman Evaluasi pada Matriks MANRIS (5×5):**\n"
             . "• **Risiko ≤ Selera Risiko (Skor 1-11 / Hijau & Kuning)**: Risiko dapat diterima (*acceptable/tolerable*), cukup dipantau berkala.\n"
             . "• **Risiko > Selera Risiko (Skor 12-25 / Oranye & Merah)**: Risiko tidak dapat diterima (*unacceptable*), **wajib disusun rencana aksi mitigasi agresif** agar tingkat risiko residual turun.";
    }

    // 29. Perbedaan KKPR dan KKPMR
    if (str_contains($q, 'kkpr') || str_contains($q, 'kkpmr') || str_contains($q, 'perbedaan')) {
        return "📑 **Perbedaan Dokumen KKPR vs KKPMR di MANRIS:**\n\n"
             . "1. **KKPR (Kertas Kerja Penilaian Risiko)**:\n"
             . "   Disusun pada awal periode/tahun anggaran. Berisi rincian analisis seluruh risiko unit, penetapan akar penyebab, dan perancangan aksi mitigasi awal beserta target waktu & penanggung jawab.\n\n"
             . "2. **KKPMR (Kertas Kerja Pemantauan & Reviu)**:\n"
             . "   Dilaksanakan secara berkala (triwulanan/semesteran). Berfungsi mereviu apakah aksi mitigasi sudah terealisasi, apakah skor risiko berhasil turun, atau diperlukan penyesuaian strategi mitigasi baru.";
    }

    // 30. Alur Approval / Persetujuan
    if (str_contains($q, 'approval') || str_contains($q, 'persetujuan') || str_contains($q, 'tolak') || str_contains($q, 'revisi') || str_contains($q, 'kirim') || str_contains($q, 'status draft')) {
        return "✍️ **Alur Pengajuan & Persetujuan Dokumen di MANRIS:**\n\n"
             . "1. **Draft**: Dokumen (Profil/KKPR/KKPMR) sedang disusun oleh Risk Manager (ADUM/Timker).\n"
             . "2. **Pending (Diajukan)**: Risk Manager mengajukan dokumen yang telah lengkap ke Pimpinan untuk direviu.\n"
             . "3. **Revisi (Rejected)**: Pimpinan mengembalikan dokumen disertai catatan perbaikan jika masih terdapat kekurangan.\n"
             . "4. **Disetujui (Approved)**: Pimpinan menandatangani dokumen secara elektronik. Dokumen terkunci sebagai acuan resmi pelaksanaan manajemen risiko.";
    }

    // 31. Definisi / Pengertian Risiko Umum
    if (str_contains($q, 'apa itu risiko') || str_contains($q, 'definisi risiko') || str_contains($q, 'pengertian risiko') || str_contains($q, 'arti risiko') || str_contains($q, 'makna risiko') || str_contains($q, 'konsep risiko') || $q === 'risiko' || $q === 'apa risiko' || str_contains($q, 'risiko adalah')) {
        return "📘 **Pengertian Risiko Menurut Standar ISO 31000:2018:**\n\n"
             . "Risiko didefinisikan sebagai **\"Efek dari ketidakpastian terhadap pencapaian sasaran\" (*Effect of uncertainty on objectives*).**\n\n"
             . "Tiga unsur utama dalam konsep risiko:\n"
             . "1. **Sasaran (*Objectives*)**: Target kerja, mutu pelayanan lab, atau IKK yang hendak dicapai.\n"
             . "2. **Ketidakpastian (*Uncertainty*)**: Kejadian di masa depan yang belum tentu terjadi namun memiliki potensi kemungkinan.\n"
             . "3. **Efek (*Effect*)**: Deviasi atau penyimpangan dari hasil yang diharapkan (dapat berupa kerugian, penundaan waktu, pemborosan, atau penurunan reputasi).\n\n"
             . "💡 *Di MANRIS, risiko dikelola secara proaktif agar ketidakpastian tersebut dapat dicegah atau diminimalkan dampaknya.*";
    }

    // 32. Salam & Pembuka
    if (str_contains($q, 'halo') || str_contains($q, 'hai') || str_contains($q, 'bantuan') || str_contains($q, 'pagi') || str_contains($q, 'siang') || str_contains($q, 'sore') || str_contains($q, 'malam')) {
        return "Halo! Saya **Asisten Cerdas MANRIS** siap membantu Anda. 👋\n\n"
             . "Saya menguasai seluruh pedoman manajemen risiko (ISO 31000:2018, SPIP PP 60/2008, dan standar teknis Lab Kesehatan Lingkungan ISO 17025).\n\n"
             . "Anda dapat menanyakan berbagai topik, misalnya:\n"
             . "• *\"Apa saja 8 prinsip manajemen risiko ISO 31000?\"*\n"
             . "• *\"Bagaimana formula perumusan risiko yang baik?\"*\n"
             . "• *\"Apa perbedaan risiko inheren dan residual?\"*\n"
             . "• *\"Apa risiko spesifik laboratorium kesehatan lingkungan?\"*\n"
             . "• *\"Jelaskan konsep Three Lines of Defense!\"*\n"
             . "• *\"Apa hubungan SPIP dengan manajemen risiko?\"*\n"
             . "• *\"Bagaimana hierarki pengendalian risiko?\"*\n"
             . "• *\"Apa itu KRI (Key Risk Indicators)?\"*\n\n"
             . "Silakan ketik pertanyaan Anda!";
    }

    // 32b. Cek pencarian risiko spesifik di database berdasarkan kata kunci operasional
    $matchedKw = findRiskInDatabase($db, $query);
    if ($matchedKw) {
        return formatRiskFullResponse($db, $matchedKw);
    }

    // 33. Default Fallback yang Ramah dan Informatif
    return "Saya adalah **Asisten Cerdas Manajemen Risiko MANRIS** (Balai Besar Laboratorium Kesehatan Lingkungan).\n\n"
         . "Anda dapat menanyakan panduan seputar topik-topik manajemen risiko berikut:\n"
         . "• 🌐 Ketik **\"prinsip manajemen risiko\"** untuk 8 prinsip standar ISO 31000:2018.\n"
         . "• ⚖️ Ketik **\"risiko inheren dan residual\"** untuk memahami tingkat risiko awal vs akhir.\n"
         . "• 🧪 Ketik **\"risiko laboratorium lingkungan\"** untuk profil risiko biosafety, kalibrasi & limbah B3.\n"
         . "• 🛡️ Ketik **\"three lines of defense\"** untuk pembagian peran lini 1, 2, dan 3.\n"
         . "• 🏛️ Ketik **\"spip dan manajemen risiko\"** untuk integrasi 5 unsur SPIP PP 60/2008.\n"
         . "• 🚨 Ketik **\"apa itu kri\"** untuk indikator peringatan dini (*Key Risk Indicators*).\n"
         . "• 🪜 Ketik **\"hierarki pengendalian risiko\"** untuk prioritas mitigasi (eliminasi s.d APD).\n"
         . "• 🔍 Ketik **\"analisis akar masalah fishbone\"** untuk metode 5 Whys & Ishikawa 6M.\n"
         . "• ⚡ Ketik **\"rencana kontinjensi\"** untuk perbedaan mitigasi preventif vs tanggap darurat.\n"
         . "• 💡 Ketik **\"rumus perumusan risiko\"** untuk formula [Penyebab] → [Risiko] → [Dampak].\n"
         . "• 📊 Ketik **\"skala probabilitas dan dampak\"** untuk matriks penilaian risiko (5×5).\n"
         . "• 🎯 Ketik **\"selera risiko\"** untuk batas toleransi dan kapasitas risiko satker.\n"
         . "• 📑 Ketik **\"apa itu profil risiko\"** atau **\"perbedaan kkpr dan kkpmr\"** untuk panduan dokumen MANRIS.\n\n"
         . "Silakan ketik atau pilih topik yang ingin Anda diskusikan!";
}

/**
 * Handle HTTP Request
 */
function handleAiChatRequest(): void {
    header('Content-Type: application/json; charset=UTF-8');

    // Verifikasi sesi login dan masa aktif sesi
    if (!hasValidSession()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Sesi telah berakhir atau kedaluwarsa. Silakan login kembali.']);
        exit;
    }

    // Hanya method POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Metode request tidak diizinkan.']);
        exit;
    }

    // Ambil input JSON atau POST
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    // Validasi CSRF Token
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $data['csrf_token'] ?? '';
    if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Token keamanan tidak valid. Silakan muat ulang halaman.']);
        exit;
    }

    $pesan = trim((string)($data['pesan'] ?? ''));
    if ($pesan === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Pesan tidak boleh kosong.']);
        exit;
    }

    // Riwayat percakapan sebelumnya (jika ada)
    $history = is_array($data['history'] ?? null) ? $data['history'] : [];

    // Cek apakah API Key Gemini tersedia dan valid (bukan placeholder default)
    $apiKey = trim((string)env('GEMINI_API_KEY', ''));
    $isPlaceholderKey = empty($apiKey)
        || $apiKey === 'MASUKKAN_API_KEY_ANDA_DISINI'
        || $apiKey === 'AIzaSyxxxxxxxxxxxx'
        || str_starts_with($apiKey, 'MASUKKAN_')
        || str_starts_with($apiKey, 'YOUR_');

    if ($isPlaceholderKey) {
        $reply = getKnowledgeBaseFallback($pesan);
        echo json_encode([
            'ok' => true,
            'reply' => $reply,
            'source' => 'knowledge_base'
        ]);
        exit;
    }

    // Rakit pesan ke Gemini API
    $model = env('GEMINI_MODEL', 'gemini-1.5-flash');
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . $apiKey;

    $systemInstruction = "Anda adalah Asisten Ahli Manajemen Risiko Organisasi untuk aplikasi MANRIS (Sistem Informasi Manajemen Risiko Balai Besar Laboratorium Kesehatan Lingkungan). "
        . "Anda menguasai secara mendalam standar ISO 31000:2018 (8 Prinsip, Kerangka Kerja Kepemimpinan & Komitmen, serta Proses Penilaian Risiko: Identifikasi, Analisis, Evaluasi), Sistem Pengendalian Intern Pemerintah (SPIP PP No. 60/2008 & BPKP), standar mutu dan keselamatan laboratorium kesehatan lingkungan (ISO/IEC 17025, Biosafety, Biosecurity, K3 Lab, Pengelolaan Reagen & Limbah B3), model tata kelola Tiga Lini Pertahanan (Three Lines Model), analisis akar masalah (5 Whys, Diagram Tulang Ikan/Fishbone Ishikawa 6M), Key Risk Indicators (KRI), Hierarki Pengendalian Risiko (Eliminasi, Substitusi, Rekayasa Teknis, Administrasi, APD), konsep Risiko Inheren vs Residual vs Sekunder, serta penetapan Selera Risiko (Risk Appetite), Toleransi Risiko, dan Rencana Kontinjensi. "
        . "Tugas Anda adalah memandu staf, Risk Manager (ADUM / Timker), dan Pimpinan dalam merumuskan risiko [Penyebab] → [Peristiwa Risiko] → [Dampak], menentukan skala probabilitas dan dampak (1-5), memberikan rekomendasi mitigasi konkret, serta menjelaskan seluruh alur dan modul MANRIS: Identifikasi Risiko, Profil Risiko Tahunan, Kertas Kerja KKPR, Pemantauan KKPMR, Kertas Kerja IKK (Indikator Kinerja Kegiatan), Monev Konsolidasi Satker, dan Laporan Ekspor PDF/Excel (ISO 31000 / pedoman Kemenkes RI). "
        . "Anda juga memahami secara persis perbedaan antara Saran Mitigasi (rekomendasi konseptual/strategis dari AI yang menjawab apa yang sebaiknya dilakukan) dan Aksi Mitigasi (rencana tindak nyata operasional di tabel mitigasi yang memiliki PIC, deadline, anggaran, status, dan progres fisik), serta menguasai mitigasi risiko tata kelola kepegawaian (seperti kelalaian rekam absensi masuk/pulang kerja, disiplin ASN PP 94/2021, dan pemotongan tunjangan kinerja/tukin). "
        . "ATURAN WAJIB REKOMENDASI MITIGASI: Setiap kali diminta memberikan saran atau rekomendasi mitigasi untuk identifikasi risiko apa pun, Anda WAJIB memberikan TEPAT 5 saran mitigasi yang konkret, spesifik, bernomor (1, 2, 3, 4, 5), dan terukur: 1. Tindakan Preventif Akar Masalah, 2. Rekayasa Teknis/Pengamanan Alat, 3. Pengendalian Administratif & SOP, 4. Protektif/APD, dan 5. Rencana Kontinjensi/Pemulihan Layanan. "
        . "Gunakan Bahasa Indonesia yang profesional, ramah, terstruktur (gunakan poin/tebal bila perlu), dan praktis. "
        . "PENTING: Selalu berikan jawaban yang tuntas, tervalidasi, dan selesai hingga kalimat atau simpulan penutup (JANGAN PERNAH memotong atau membiarkan jawaban berhenti menggantung di tengah kalimat). Rangkum penjelasan secara padat, komprehensif, dan jelas.";

    // Grounding data resmi dari database MANRIS jika pesan menanyakan risiko tertentu atau unit
    $db = getDB();
    $dbMatchedRisk = findRiskInDatabase($db, $pesan);
    if ($dbMatchedRisk) {
        $systemInstruction .= "\n\n=== DATA RESMI DATABASE SISTEM MANRIS UNTUK RISIKO TERKAIT ===\n"
            . formatRiskFullResponse($db, $dbMatchedRisk)
            . "\n\nINSTRUKSI PENTING: Jadikan data resmi database di atas sebagai rujukan fakta utama (kode risiko, unit pengelola, 5 aksi mitigasi terdaftar, dan 5 saran mitigasi) dalam menyusun jawaban kepada pengguna.";
    } else {
        $dbUnitSummary = findUnitRisksInDatabase($db, $pesan);
        if ($dbUnitSummary) {
            $systemInstruction .= "\n\n=== DATA RESMI DATABASE SISTEM MANRIS UNTUK UNIT TERKAIT ===\n"
                . $dbUnitSummary
                . "\n\nINSTRUKSI PENTING: Gunakan rangkuman data unit di atas untuk menjawab pengguna secara akurat.";
        }
    }

    $contents = [];
    $recentHistory = array_slice($history, -6);
    foreach ($recentHistory as $msg) {
        $role = ($msg['role'] ?? '') === 'user' ? 'user' : 'model';
        $text = trim((string)($msg['text'] ?? ''));
        if ($text !== '') {
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => $text]]
            ];
        }
    }

    $contents[] = [
        'role' => 'user',
        'parts' => [['text' => $pesan]]
    ];

    $payload = [
        'system_instruction' => [
            'parts' => [
                ['text' => $systemInstruction]
            ]
        ],
        'contents' => $contents,
        'generationConfig' => [
            'temperature'     => 0.5,
            'maxOutputTokens' => 8192,
            'topP'            => 0.95,
        ],
    ];

    $http = aiSaranHttpPost($url, json_encode($payload, JSON_UNESCAPED_UNICODE));
    $resp = $http['resp'] ?? null;
    $code = (int)($http['code'] ?? 0);

    if ($resp !== null && $code >= 200 && $code < 300) {
        $data = json_decode($resp, true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (is_string($text) && trim($text) !== '') {
            echo json_encode([
                'ok' => true,
                'reply' => trim($text),
                'source' => 'gemini_ai',
                'model' => $model
            ]);
            exit;
        }
    }

    // Bila Gemini API mengalami limit/error, fallback dengan aman ke Knowledge Base
    $fallback = getKnowledgeBaseFallback($pesan);
    echo json_encode([
        'ok' => true,
        'reply' => $fallback,
        'source' => 'knowledge_base_fallback'
    ]);
}

// Jalankan controller hanya jika file diakses langsung
if (PHP_SAPI !== 'cli' || (isset($_SERVER['SCRIPT_FILENAME']) && basename($_SERVER['SCRIPT_FILENAME']) === 'api_ai_chat.php')) {
    handleAiChatRequest();
}
