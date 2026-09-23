<?php
/**
 * AUTENTIKASI - Login / Logout
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// ── Proses Login ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {

    if (!verifyCsrf()) {
        setFlash('error', 'Permintaan tidak valid. Silakan coba lagi.');
        header('Location: ' . APP_URL . '/index.php?page=login');
        exit;
    }

    // -- Brute Force Protection (Rate Limiting) --
    // Kombinasi session + IP-based (lebih kuat dari session-only)
    $ipAddr = getIPAddress();

    if (isset($_SESSION['lockout_time'])) {
        if (time() < $_SESSION['lockout_time']) {
            $remaining = ceil(($_SESSION['lockout_time'] - time()) / 60);
            setFlash('error', 'Terlalu banyak percobaan gagal. Coba lagi dalam ' . $remaining . ' menit.');
            header('Location: ' . APP_URL . '/index.php?page=login');
            exit;
        } else {
            // Lockout expired, reset counters
            unset($_SESSION['lockout_time']);
            unset($_SESSION['login_attempts']);
        }
    }

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? ''; // jangan trim — password bisa ada spasi

    if (empty($username) || empty($password)) {
        setFlash('error', 'Username dan password wajib diisi.');
        header('Location: ' . APP_URL . '/index.php?page=login');
        exit;
    }

    // Kredensial contoh tidak boleh menjadi jalan masuk ke deployment produksi.
    if (APP_ENV !== 'development' && $password === 'password') {
        logAktivitas('LOGIN_GAGAL', 'auth', null, 'Percobaan penggunaan password demo untuk username: ' . $username);
        setFlash('error', 'Username atau password salah.');
        header('Location: ' . APP_URL . '/index.php?page=login');
        exit;
    }

    // IP-based rate limit (survives session reset).
    // Prioritas: tabel DB `login_lockouts` dengan increment atomik —
    // fallback ke file bila migrasi add_security_tables.sql belum dijalankan.
    $ipHash    = hash_hmac('sha256', $ipAddr, APP_KEY);
    $lockFile  = sys_get_temp_dir() . '/manris_login_' . $ipHash . '.json';
    $lockData  = ['fails' => 0, 'lock_until' => 0];
    $db        = getDB();
    $dbLockout = false;
    try {
        $probe = $db->prepare('SELECT 1 FROM login_lockouts LIMIT 1');
        $probe->execute();
        $probe->close();
        $dbLockout = true;
        // Reset atomik bila lockout sudah kedaluwarsa
        $now = time();
        $rst = $db->prepare('UPDATE login_lockouts SET fails = 0, lock_until = 0 WHERE ip_hash = ? AND lock_until > 0 AND lock_until <= ?');
        $rst->bind_param('si', $ipHash, $now);
        $rst->execute();
        $rst->close();
        $lk = $db->prepare('SELECT fails, lock_until FROM login_lockouts WHERE ip_hash = ? LIMIT 1');
        $lk->bind_param('s', $ipHash);
        $lk->execute();
        $lockData = $lk->get_result()->fetch_assoc() ?: ['fails' => 0, 'lock_until' => 0];
        $lk->close();
    } catch (Throwable $e) {
        // Tabel belum ada → fallback file-based
        if (is_file($lockFile)) {
            $lockData = json_decode((string) file_get_contents($lockFile), true) ?: $lockData;
        }
    }

    if ((int)($lockData['lock_until'] ?? 0) > time()) {
        $remaining = ceil(($lockData['lock_until'] - time()) / 60);
        setFlash('error', 'Terlalu banyak percobaan gagal dari IP Anda. Coba lagi dalam ' . $remaining . ' menit.');
        header('Location: ' . APP_URL . '/index.php?page=login');
        exit;
    }

    $stmt = $db->prepare('SELECT id, nama, username, password, role, aktif FROM users WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user && $user['aktif'] == 1 && password_verify($password, $user['password'])) {
        // Blokir login dengan password demo di lingkungan production
        $demoPwList = ['demo123', 'password'];
        if (APP_ENV !== 'development' && in_array($password, $demoPwList, true)) {
            logAktivitas('LOGIN_GAGAL', 'auth', null,
                'Percobaan login dengan demo password di production, username: ' . $username);
            setFlash('error', 'Username atau password salah.');
            header('Location: ' . APP_URL . '/index.php?page=login');
            exit;
        }

        // Regenerate session ID untuk keamanan
        session_regenerate_id(true);

        // Rotasi CSRF token setelah login sukses (cegah reuse token pre-login)
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $_SESSION['user_id']       = $user['id'];
        $_SESSION['user_nama']     = $user['nama'];
        $_SESSION['user_username'] = $user['username'];
        $_SESSION['user_role']     = $user['role'];
        $_SESSION['last_activity'] = time();
        $_SESSION['user_agent']    = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'; // Untuk perlindungan pembajakan sesi
        $_SESSION['theme']         = $_COOKIE['theme'] ?? 'light';

        // Reset percobaan login gagal (session + IP-based)
        unset($_SESSION['login_attempts']);
        unset($_SESSION['lockout_time']);
        if ($dbLockout) {
            $clr = $db->prepare('DELETE FROM login_lockouts WHERE ip_hash = ?');
            $clr->bind_param('s', $ipHash);
            $clr->execute();
            $clr->close();
        }
        if (is_file($lockFile)) @unlink($lockFile);

        logAktivitas('LOGIN', 'auth', null, 'User ' . $user['username'] . ' berhasil login');

        setFlash('success', 'Selamat datang, ' . $user['nama'] . '!');
        header('Location: ' . APP_URL . '/index.php?page=dashboard');
        exit;
    }

    // Jika gagal — increment session counter + IP counter (atomik di DB)
    $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
    $ipFails = 0;

    if ($dbLockout) {
        $now = time();
        $inc = $db->prepare(
            'INSERT INTO login_lockouts (ip_hash, fails, lock_until, updated_at) VALUES (?, 1, 0, ?)
             ON DUPLICATE KEY UPDATE fails = fails + 1, updated_at = VALUES(updated_at)'
        );
        $inc->bind_param('si', $ipHash, $now);
        $inc->execute();
        $inc->close();
        $lk = $db->prepare('SELECT fails FROM login_lockouts WHERE ip_hash = ? LIMIT 1');
        $lk->bind_param('s', $ipHash);
        $lk->execute();
        $ipFails = (int)($lk->get_result()->fetch_column() ?: 0);
        $lk->close();
    } else {
        $lockData['fails'] = ($lockData['fails'] ?? 0) + 1;
        $ipFails = (int)$lockData['fails'];
        file_put_contents($lockFile, json_encode($lockData), LOCK_EX);
    }

    if ($_SESSION['login_attempts'] >= 5 || $ipFails >= 10) {
        $lockDuration = 5 * 60; // 5 menit
        $_SESSION['lockout_time'] = time() + $lockDuration;
        if ($dbLockout) {
            $lockUntil = time() + $lockDuration;
            $lk = $db->prepare('UPDATE login_lockouts SET lock_until = ? WHERE ip_hash = ?');
            $lk->bind_param('is', $lockUntil, $ipHash);
            $lk->execute();
            $lk->close();
        } else {
            $lockData['lock_until'] = time() + $lockDuration;
            file_put_contents($lockFile, json_encode($lockData), LOCK_EX);
        }
        logAktivitas('LOGIN_LOCKED', 'auth', null, 'IP dikunci karena percobaan gagal: ' . $username);
        setFlash('error', 'Terlalu banyak percobaan gagal. Akses dikunci selama 5 menit.');
    } else {
        logAktivitas('LOGIN_GAGAL', 'auth', null, 'Percobaan login gagal untuk username: ' . $username);
        setFlash('error', 'Username atau password salah.');
    }
    
    header('Location: ' . APP_URL . '/index.php?page=login');
    exit;
}

// ── Proses Logout ──────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'logout') {
    // Token hanya divalidasi ketika user masih punya sesi aktif.
    // Jika sesi sudah expired/hilang (token otomatis tidak cocok),
    // logout tetap diproses dengan bersih tanpa warning 419.
    if (!verifyCsrf() && isset($_SESSION['user_id'])) {
        http_response_code(419);
        exit('Permintaan logout tidak valid.');
    }
    if (isset($_SESSION['user_id'])) {
        logAktivitas('LOGOUT', 'auth', null, 'User ' . $_SESSION['user_username'] . ' logout');
    }
    session_unset();
    session_destroy();
    
    // Hapus cookie sesi
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_start();
    setFlash('success', 'Anda telah berhasil logout.');
    header('Location: ' . APP_URL . '/');
    exit;
}
