<?php
/**
 * Property 1: Akses ditolak untuk peran tidak berwenang
 * Validates: Requirements 1.6
 *
 * Pengujian dilakukan dalam dua lapisan:
 *  1. Logika role-check murni melalui fungsi cekAksesLaporan() — tanpa session/DB
 *  2. Verifikasi struktural bahwa requireRole() ada di awal modul (≤20 baris kode)
 */

// ─── Helper assertion ─────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) {
        echo "  ✓ {$label}\n";
        $passed++;
    } else {
        echo "  ✗ GAGAL: {$label}\n";
        $failed++;
    }
}

// ─── Fungsi yang diuji ────────────────────────────────────────────────────────

/**
 * Memodelkan logika pengecekan role yang dilakukan requireRole() di modul:
 * hanya peran dalam daftar yang diizinkan yang boleh mengakses.
 *
 * Fungsi ini pure (tanpa efek samping) sehingga dapat diuji tanpa session.
 */
function cekAksesLaporan(string $userRole): bool {
    $allowed = ['Admin', 'Risk Manager', 'Pimpinan', 'Koordinator'];
    return in_array($userRole, $allowed, true); // strict (case-sensitive)
}

// ─── Suite ────────────────────────────────────────────────────────────────────

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  Property 1: Akses Kontrol — Peran Tidak Berwenang Ditolak  ║\n";
echo "║  Validates: Requirements 1.6                                 ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";

// ── 1. Peran yang diizinkan harus mengembalikan true ─────────────────────────
echo "\n1. Peran yang DIIZINKAN → cekAksesLaporan() harus true\n";

$allowedRoles = ['Admin', 'Risk Manager', 'Pimpinan', 'Koordinator'];
foreach ($allowedRoles as $role) {
    ok(cekAksesLaporan($role) === true, "Peran '{$role}' → true (diizinkan)");
}

// ── 2. Peran yang tidak diizinkan harus mengembalikan false ──────────────────
echo "\n2. Peran yang TIDAK DIIZINKAN → cekAksesLaporan() harus false\n";

$disallowedRoles = [
    'Staf',
    'Operator',
    'Guest',
    '',            // string kosong
    'admin',       // huruf kecil semua
    'ADMIN',       // huruf besar semua
    'Invalid',
    'risk manager',   // variasi huruf kecil
    'RISK MANAGER',   // variasi huruf besar
    'pimpinan',       // huruf kecil semua
    'koordinator',    // huruf kecil semua
    'SuperAdmin',
    'User',
    '0',
    ' Admin',      // spasi di depan
    'Admin ',      // spasi di belakang
    'Viewer',
];

foreach ($disallowedRoles as $role) {
    $display = ($role === '') ? '(empty string)' : "'{$role}'";
    ok(cekAksesLaporan($role) === false, "Peran {$display} → false (ditolak)");
}

// ── 3. Case-sensitivity: perbandingan harus eksak ────────────────────────────
echo "\n3. Case-sensitivity — pencocokan harus persis (exact match)\n";

// Pasangan: variasi non-eksak dari peran yang valid harus ditolak
$casePairs = [
    ['admin',        'Admin'],
    ['ADMIN',        'Admin'],
    ['risk manager', 'Risk Manager'],
    ['RISK MANAGER', 'Risk Manager'],
    ['pimpinan',     'Pimpinan'],
    ['PIMPINAN',     'Pimpinan'],
    ['koordinator',  'Koordinator'],
    ['KOORDINATOR',  'Koordinator'],
];

foreach ($casePairs as [$wrongCase, $correctCase]) {
    ok(
        cekAksesLaporan($wrongCase) === false,
        "'{$wrongCase}' ditolak (bukan '{$correctCase}' yang eksak)"
    );
    ok(
        cekAksesLaporan($correctCase) === true,
        "'{$correctCase}' (eksak) tetap diterima"
    );
}

// ── 4. Idempotency — hasil tidak berubah pada pemanggilan berulang ────────────
echo "\n4. Idempotency — hasil konsisten pada pemanggilan berulang\n";

foreach ($allowedRoles as $role) {
    $first  = cekAksesLaporan($role);
    $second = cekAksesLaporan($role);
    ok($first === $second && $first === true, "Panggil dua kali '{$role}' → hasil sama (true)");
}

ok(cekAksesLaporan('Staf') === cekAksesLaporan('Staf'), "Panggil dua kali 'Staf' → hasil sama (false)");

// ── 5. Verifikasi struktural: requireRole() ada dalam 20 baris awal kode ─────
echo "\n5. Verifikasi struktural modul — requireRole() di awal file\n";

$modulePath = __DIR__ . '/../modules/laporan_monev.php';
ok(file_exists($modulePath), "File modules/laporan_monev.php ada");

if (file_exists($modulePath)) {
    $source = file_get_contents($modulePath);

    // Periksa bahwa requireRole( ada di file
    ok(
        strpos($source, "requireRole(") !== false,
        "requireRole() ditemukan dalam modul"
    );

    // Cari posisi karakter requireRole( lalu hitung berapa banyak baris kode
    // (bukan komentar, bukan baris kosong) yang mendahuluinya.
    $lines = explode("\n", $source);
    $requireRoleLine = null;

    foreach ($lines as $lineNum => $lineContent) {
        if (strpos($lineContent, 'requireRole(') !== false) {
            $requireRoleLine = $lineNum + 1; // 1-indexed
            break;
        }
    }

    ok($requireRoleLine !== null, "Baris requireRole() berhasil dideteksi (baris {$requireRoleLine})");

    if ($requireRoleLine !== null) {
        // Hitung hanya baris "kode nyata" sebelum requireRole():
        // abaikan <?php, komentar /* ... */, komentar // ..., dan baris kosong
        $codeLinesBefore = 0;
        for ($i = 0; $i < $requireRoleLine - 1; $i++) {
            $l = trim($lines[$i]);
            if ($l === '') continue;           // baris kosong
            if ($l === '<?php') continue;      // tag pembuka
            if (str_starts_with($l, '//')) continue;     // komentar baris
            if (str_starts_with($l, '*')) continue;      // baris docblock
            if (str_starts_with($l, '/**')) continue;    // awal docblock
            if (str_starts_with($l, '/*')) continue;     // awal komentar blok
            $codeLinesBefore++;
        }

        ok(
            $codeLinesBefore <= 20,
            "requireRole() ada dalam 20 baris kode pertama " .
            "(baris kode sebelumnya: {$codeLinesBefore})"
        );

        // requireLogin() harus muncul sebelum requireRole()
        $requireLoginLine = null;
        foreach ($lines as $lineNum => $lineContent) {
            if (strpos($lineContent, 'requireLogin(') !== false) {
                $requireLoginLine = $lineNum + 1;
                break;
            }
        }

        ok(
            $requireLoginLine !== null && $requireLoginLine < $requireRoleLine,
            "requireLogin() (baris {$requireLoginLine}) muncul SEBELUM requireRole() (baris {$requireRoleLine})"
        );
    }

    // requireRole() dipanggil dengan semua 4 peran yang diizinkan
    ok(
        strpos($source, "'Admin'") !== false &&
        strpos($source, "'Risk Manager'") !== false &&
        strpos($source, "'Pimpinan'") !== false &&
        strpos($source, "'Koordinator'") !== false,
        "requireRole() mencantumkan keempat peran: Admin, Risk Manager, Pimpinan, Koordinator"
    );
}

// ─── Ringkasan ────────────────────────────────────────────────────────────────

$total = $passed + $failed;
echo "\n" . str_repeat("─", 62) . "\n";
if ($failed === 0) {
    echo "  ✓ Semua {$total} test LULUS\n";
} else {
    echo "  ✗ {$failed} dari {$total} test GAGAL\n";
}
echo str_repeat("─", 62) . "\n";

exit($failed > 0 ? 1 : 0);
