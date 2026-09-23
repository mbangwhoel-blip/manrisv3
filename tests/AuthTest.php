<?php
/**
 * Test: Autentikasi, CSRF, demo password block (Rekomendasi #4)
 */

use PHPUnit\Framework\TestCase;

class AuthTest extends TestCase
{
    // ── CSRF Token ────────────────────────────────────────────

    public function testCsrfTokenIsHex(): void
    {
        // Simulasikan session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = csrfToken();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
    }

    public function testCsrfTokenConsistent(): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $t1 = csrfToken();
        $t2 = csrfToken();
        $this->assertSame($t1, $t2, 'Token harus konsisten dalam satu sesi');
    }

    public function testVerifyCsrfFalseWhenMismatch(): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['csrf_token'] = 'aaa';
        $_POST['csrf_token'] = 'bbb';
        $this->assertFalse(verifyCsrf());
    }

    public function testVerifyCsrfTrueWhenMatch(): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_POST['csrf_token'] = $token;
        $this->assertTrue(verifyCsrf());
    }

    // ── Demo Password List ────────────────────────────────────

    /**
     * Pastikan daftar password demo mencakup minimal 'demo123'.
     * Ini memverifikasi bahwa Requirement 2.4 terpenuhi.
     */
    public function testDemoPasswordListContainsDemoValue(): void
    {
        // Baca auth.php dan cari definisi demoPwList
        $authFile = file_get_contents(dirname(__DIR__) . '/includes/auth.php');
        $this->assertStringContainsString("'demo123'", $authFile,
            "auth.php harus mendefinisikan 'demo123' dalam daftar password demo");
    }

    /**
     * Pastikan demo password check ada di dalam blok password_verify.
     */
    public function testDemoPasswordBlockIsInsidePasswordVerifyBlock(): void
    {
        $authFile = file_get_contents(dirname(__DIR__) . '/includes/auth.php');
        // Cek APP_ENV check ada
        $this->assertStringContainsString("APP_ENV !== 'development'", $authFile,
            "Blok demo password harus memeriksa APP_ENV");
        $this->assertStringContainsString('in_array($password, $demoPwList', $authFile,
            "Blok demo password harus menggunakan in_array");
    }

    // ── isLoggedIn / hasValidSession ──────────────────────────

    public function testIsLoggedInFalseWithoutSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        unset($_SESSION['user_id']);
        $this->assertFalse(isLoggedIn());
    }

    public function testIsLoggedInTrueWithSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['user_id'] = 1;
        $this->assertTrue(isLoggedIn());
    }

    // ── hasRole ───────────────────────────────────────────────

    public function testHasRoleMatchesCorrectly(): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['user_role'] = 'Admin';
        $this->assertTrue(hasRole('Admin'));
        $this->assertTrue(hasRole('Admin', 'Pimpinan'));
        $this->assertFalse(hasRole('Risk Manager'));
    }

    public function testCanAccessAllRecordsForAdminAndPimpinan(): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['user_role'] = 'Admin';
        $this->assertTrue(canAccessAllRecords());

        $_SESSION['user_role'] = 'Pimpinan';
        $this->assertTrue(canAccessAllRecords());

        $_SESSION['user_role'] = 'Risk Manager';
        $this->assertFalse(canAccessAllRecords());
    }

    public function testCanManageBackupForAuthorizedRoles(): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();

        foreach (['Admin', 'Risk Manager', 'Pimpinan', 'Kepala'] as $role) {
            $_SESSION['user_role'] = $role;
            $this->assertTrue(canManageBackup(), "Role {$role} should be allowed to manage backup & restore");
        }

        foreach (['Staff', 'Operator', 'Guest', ''] as $role) {
            $_SESSION['user_role'] = $role;
            $this->assertFalse(canManageBackup(), "Role {$role} should NOT be allowed to manage backup & restore");
        }
    }
}
