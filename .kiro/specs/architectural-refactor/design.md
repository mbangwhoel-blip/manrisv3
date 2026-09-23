# Design Document — Architectural Refactor (manrisv2)

## Overview

Refaktor inkremental terhadap aplikasi **Sistem Informasi Manajemen Risiko (manrisv2)** yang saat ini berjalan sebagai flat-PHP modules. Desain ini mencakup enam area: (1) pemisahan arsitektur MVC berlapis dengan PSR-4 autoloading, (2) validasi server-side terpusat, (3) lokalisasi semua aset CDN, (4) setup PHPUnit minimal, (5) pembersihan file sementara dan migrasi formal, dan (6) streaming backup via `mysqldump`. Semua perubahan dilakukan secara inkremental — `modules/*.php` yang belum dimigrasi tetap berfungsi tanpa modifikasi.

---

## Architecture

### Pendekatan Inkremental

Arsitektur saat ini adalah flat-PHP dengan `index.php` sebagai satu-satunya entry point yang mem-`include` file di `modules/`. Desain ini mempertahankan pola tersebut sambil memperkenalkan lapisan MVC di atasnya.

```
index.php (entry point, tetap ada)
├── includes/config.php        ← tidak diubah
├── includes/functions.php     ← tidak diubah
├── includes/auth.php          ← tidak diubah
├── includes/header.php        ← diperbarui (CDN → lokal)
├── includes/footer.php        ← tidak diubah
├── modules/*.php              ← tetap berfungsi (kecuali risiko.php & backup_restore.php)
└── app/                       ← BARU: lapisan MVC
    ├── autoload.php
    ├── Controllers/
    │   ├── RisikoController.php
    │   └── BackupController.php
    ├── Services/
    │   ├── RisikoService.php
    │   └── BackupService.php
    ├── Repositories/
    │   └── RisikoRepository.php
    ├── Validators/
    │   └── Validator.php
    └── Views/
        ├── risiko/
        │   ├── index.php
        │   └── form.php
        └── backup/
            └── index.php
```

### Routing Strategy

`index.php` mempertahankan `$modules[]` array yang ada. Untuk `?page=risiko` dan `?page=backup`, router diarahkan ke Controller MVC baru. Semua halaman lain tetap menggunakan include lama.

```php
// index.php — augmented router (tambahan di atas kode yang ada)
$mvcControllers = [
    'risiko' => App\Controllers\RisikoController::class,
    'backup' => App\Controllers\BackupController::class,
];
if (isset($mvcControllers[$page])) {
    require_once __DIR__ . '/app/autoload.php';
    $controller = new $mvcControllers[$page]($db);
    $controller->handle();
    // handle() bertanggung jawab terhadap output — include header/footer sendiri
    exit;
}
// fallback ke include lama untuk modul yang belum dimigrasi
```

### PSR-4 Autoloading

`app/autoload.php` menyediakan autoloader sederhana yang dapat digunakan sebelum Composer tersedia, dan diintegrasikan ke dalam Composer setelah `composer install` dijalankan.

```php
// app/autoload.php
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/' . $relative . '.php';
    if (file_exists($file)) require_once $file;
});
```

`composer.json` mendefinisikan PSR-4 sehingga setelah `composer install`, `vendor/autoload.php` menggantikan `app/autoload.php`:

```json
{
    "require-dev": { "phpunit/phpunit": "^11.0" },
    "autoload": { "psr-4": { "App\\": "app/" } }
}
```

---

## Components and Interfaces

### 1. RisikoController (`app/Controllers/RisikoController.php`)

Bertanggung jawab menerima HTTP request dan mendelegasikan ke `RisikoService`. Tidak boleh mengandung logika bisnis.

```php
class RisikoController {
    public function __construct(private mysqli $db) {}

    public function handle(): void {
        requireLogin();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handlePost();
        } else {
            $this->handleGet();
        }
    }

    private function handlePost(): void {
        if (!verifyCsrf()) { /* redirect with error */ return; }
        requireRole('Admin', 'Risk Manager');

        $errors = Validator::validate($_POST, $this->rulesForRisiko());
        if (!empty($errors)) {
            setFlash('error', implode('; ', $errors));
            header('Location: ' . APP_URL . '/?page=risiko');
            exit;
        }
        $aksi = $_POST['aksi'] ?? '';
        $service = new RisikoService(new RisikoRepository($this->db));
        if ($aksi === 'simpan') $service->save($_POST, (int)$_SESSION['user_id']);
        elseif ($aksi === 'hapus') $service->delete((int)$_POST['id']);
        header('Location: ' . APP_URL . '/?page=risiko');
        exit;
    }
    // handleGet() renders daftar dengan pagination, sama seperti modules/risiko.php
}
```

### 2. RisikoService (`app/Services/RisikoService.php`)

Mengandung kalkulasi bisnis. Tidak bergantung pada superglobal atau database secara langsung.

```php
class RisikoService {
    public function __construct(private RisikoRepository $repo) {}

    public function getBobot(int $p, int $d): float { /* matriks bobot */ }
    public function getLevelRisiko(int $skor): string { /* match skor ke level */ }
    public function generateKodeRisiko(?int $userId): string { /* via repository */ }
    public function save(array $data, int $userId): int { /* validasi & simpan */ }
    public function delete(int $id): void { /* soft delete atau hard delete */ }
}
```

`getBobot()` dan `getLevelRisiko()` adalah fungsi murni (pure functions) — tidak ada side effect, tidak bergantung pada state global. `generateKodeRisiko()` bergantung pada `RisikoRepository` untuk query kode yang sudah dipakai.

### 3. RisikoRepository (`app/Repositories/RisikoRepository.php`)

Seluruh query database untuk entitas `risiko`. Selalu menggunakan prepared statements.

```php
class RisikoRepository {
    public function __construct(private mysqli $db) {}

    public function findAll(array $filters, int $limit, int $offset): array { /* ... */ }
    public function findById(int $id): ?array { /* ... */ }
    public function create(array $fields): int { /* INSERT, returns ID */ }
    public function update(int $id, array $fields): void { /* UPDATE */ }
    public function softDelete(int $id, int $deletedBy): void { /* UPDATE deleted_at */ }
    public function hardDelete(int $id): void { /* DELETE */ }
    public function countUsedCodes(string $prefix): array { /* kode yang sudah dipakai */ }
}
```

### 4. Validator (`app/Validators/Validator.php`)

Kelas statis reusable. Menerima `$data` array dan `$rules` array, mengembalikan `['field' => 'pesan error']`.

```php
class Validator {
    public static function validate(array $data, array $rules): array {
        $errors = [];
        foreach ($rules as $field => $rule) {
            $value = $data[$field] ?? null;
            foreach (explode('|', $rule) as $r) {
                $error = self::applyRule($field, $value, $r, $data);
                if ($error !== null) { $errors[$field] = $error; break; }
            }
        }
        return $errors;
    }

    private static function applyRule(string $field, mixed $value, string $rule, array $data): ?string {
        return match(true) {
            $rule === 'required'   => self::ruleRequired($field, $value),
            $rule === 'date'       => self::ruleDate($field, $value),
            $rule === 'riskStatus' => self::ruleRiskStatus($field, $value),
            str_starts_with($rule, 'range:') => self::ruleRange($field, $value, $rule),
            $rule === 'positiveNum' => self::rulePositiveNum($field, $value),
            default => null,
        };
    }

    public static function ruleRequired(string $field, mixed $value): ?string {
        if ($value === null || $value === '') return "$field wajib diisi.";
        return null;
    }

    public static function ruleDate(string $field, mixed $value): ?string {
        if ($value === null || $value === '') return null; // combined with required
        $d = DateTime::createFromFormat('Y-m-d', (string)$value);
        if (!$d || $d->format('Y-m-d') !== (string)$value)
            return "$field harus berformat Y-m-d (contoh: 2024-01-31).";
        return null;
    }

    public static function ruleRiskStatus(string $field, mixed $value): ?string {
        $allowed = ['Teridentifikasi', 'Ditangani', 'Dimonitor', 'Ditutup'];
        if (!in_array($value, $allowed, true))
            return "$field harus salah satu dari: " . implode(', ', $allowed) . ".";
        return null;
    }

    public static function ruleRange(string $field, mixed $value, string $rule): ?string {
        [, $range] = explode(':', $rule, 2);
        [$min, $max] = array_map('intval', explode(',', $range));
        if (!is_numeric($value) || (int)$value < $min || (int)$value > $max)
            return "$field harus integer antara $min dan $max.";
        return null;
    }

    public static function rulePositiveNum(string $field, mixed $value): ?string {
        if (!is_numeric($value) || (float)$value <= 0)
            return "$field harus angka positif lebih dari nol.";
        return null;
    }
}
```

Contoh penggunaan di controller:

```php
$errors = Validator::validate($_POST, [
    'tanggal_identifikasi' => 'required|date',
    'status'               => 'required|riskStatus',
    'probabilitas'         => 'required|range:1,5',
    'dampak_level'         => 'required|range:1,5',
    'bobot'                => 'required|positiveNum',
]);
```

### 5. BackupController & BackupService

#### BackupController (`app/Controllers/BackupController.php`)

```php
class BackupController {
    public function __construct(private mysqli $db) {}

    public function handle(): void {
        requireLogin();
        requireRole('Admin', 'Risk Manager');

        $action = $_GET['action'] ?? ($_POST['action'] ?? '');
        if ($action === 'download') $this->download();
        elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'restore') $this->restore();
        else $this->renderView();
    }

    private function download(): void {
        $service = new BackupService($this->db);
        $tmpFile = $service->createBackupFile();
        $size = filesize($tmpFile);
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="backup_manris_' . date('Y-m-d_H-i-s') . '.sql"');
        header('Content-Length: ' . $size);
        header('Cache-Control: no-store');
        readfile($tmpFile);
        $service->cleanupTempFile($tmpFile);
        exit;
    }

    private function restore(): void {
        if (!verifyCsrf()) { setFlash('error', 'Token tidak valid.'); $this->redirect(); }
        // re-authenticate Admin password
        $service = new BackupService($this->db);
        $service->restore($_POST, $_FILES['backup_file'] ?? []);
        $this->redirect();
    }
}
```

#### BackupService (`app/Services/BackupService.php`)

```php
class BackupService {
    public function __construct(private mysqli $db) {}

    public function isMysqldumpAvailable(): bool {
        $path = '';
        if (PHP_OS_FAMILY === 'Windows') {
            exec('where mysqldump 2>NUL', $out, $code);
        } else {
            exec('which mysqldump 2>/dev/null', $out, $code);
        }
        return $code === 0 && !empty($out[0]);
    }

    public function createBackupFile(): string {
        $tmpFile = sys_get_temp_dir() . '/manris_backup_' . time() . '_' . bin2hex(random_bytes(4)) . '.sql';
        if ($this->isMysqldumpAvailable()) {
            $this->runMysqldump($tmpFile);
        } else {
            $content = $this->buildSqlInMemory();
            file_put_contents($tmpFile, $content);
        }
        $this->prependHmac($tmpFile);
        return $tmpFile;
    }

    private function runMysqldump(string $outFile): void {
        $host   = escapeshellarg(DB_HOST);
        $user   = escapeshellarg(DB_USER);
        $pass   = escapeshellarg(DB_PASS);
        $dbName = escapeshellarg(DB_NAME);
        $port   = escapeshellarg((string)DB_PORT);
        $dest   = escapeshellarg($outFile);
        exec("mysqldump --host=$host --port=$port --user=$user --password=$pass $dbName > $dest", $out, $code);
        if ($code !== 0) throw new \RuntimeException('mysqldump failed with code ' . $code);
    }

    private function buildSqlInMemory(): string {
        // Fallback in-memory menggunakan SHOW TABLES + SHOW CREATE TABLE + SELECT *
        // (logika yang sudah ada di modules/backup_restore.php)
        $sql = "-- Backup Database Manris\n-- Tanggal: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";
        $tables = [];
        $result = $this->db->query('SHOW TABLES');
        while ($row = $result->fetch_row()) $tables[] = $row[0];
        foreach ($tables as $table) {
            $res = $this->db->query("SHOW CREATE TABLE `$table`");
            $row = $res->fetch_row();
            $sql .= "DROP TABLE IF EXISTS `$table`;\n" . $row[1] . ";\n\n";
            $res2 = $this->db->query("SELECT * FROM `$table`");
            if ($res2->num_rows > 0) {
                $sql .= "INSERT INTO `$table` VALUES \n";
                $rows = [];
                while ($r = $res2->fetch_assoc()) {
                    $vals = array_map(fn($v) => $v === null ? 'NULL' : "'" . $this->db->real_escape_string($v) . "'", $r);
                    $rows[] = '(' . implode(',', $vals) . ')';
                }
                $sql .= implode(",\n", $rows) . ";\n\n";
            }
        }
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        return $sql;
    }

    private function prependHmac(string $file): void {
        $body      = file_get_contents($file);
        $signature = hash_hmac('sha256', $body, APP_KEY);
        file_put_contents($file, "-- HMAC-SHA256: $signature\n" . $body);
    }

    public function cleanupTempFile(string $path): void {
        if (file_exists($path)) unlink($path);
    }

    public function restore(array $post, array $fileInfo): void {
        // password re-auth
        $uid   = (int)$_SESSION['user_id'];
        $stmt  = $this->db->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $uid); $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$admin || !password_verify($post['confirm_password'] ?? '', $admin['password'])) {
            setFlash('error', 'Konfirmasi password tidak valid. Restore dibatalkan.');
            return;
        }
        if (!isset($fileInfo['tmp_name']) || $fileInfo['error'] !== UPLOAD_ERR_OK) {
            setFlash('error', 'File upload gagal.'); return;
        }
        if ($fileInfo['size'] > 50 * 1024 * 1024) {
            setFlash('error', 'Ukuran file terlalu besar. Maksimal 50MB.'); return;
        }
        $content = file_get_contents($fileInfo['tmp_name']);
        [$hmacLine, $body] = array_pad(explode("\n", str_replace("\r", "", $content), 2), 2, '');
        if (!str_starts_with($hmacLine, '-- HMAC-SHA256: ')) {
            setFlash('error', 'HMAC tidak ditemukan.'); return;
        }
        $fileSignature     = trim(substr($hmacLine, 16));
        $expectedSignature = hash_hmac('sha256', $body, APP_KEY);
        if (!hash_equals($expectedSignature, $fileSignature)) {
            setFlash('error', 'Integritas file gagal diverifikasi.'); return;
        }
        // Execute SQL
        $this->db->begin_transaction();
        try {
            $this->db->query("SET FOREIGN_KEY_CHECKS=0");
            if ($this->db->multi_query($body)) {
                do { if ($r = $this->db->store_result()) $r->free(); } while ($this->db->more_results() && $this->db->next_result());
            } else throw new \Exception($this->db->error);
            $this->db->query("SET FOREIGN_KEY_CHECKS=1");
            $this->db->commit();
            logAktivitas('RESTORE', 'system', 0, 'Restore dari: ' . $fileInfo['name']);
            setFlash('success', 'Database berhasil di-restore!');
        } catch (\Exception $e) {
            $this->db->rollback();
            $this->db->query("SET FOREIGN_KEY_CHECKS=1");
            error_log('[manris] Restore failed: ' . $e->getMessage());
            setFlash('error', 'Gagal memulihkan database.');
        }
    }
}
```

---

## Data Models

### Entitas Risiko (tabel `risiko`)

Tidak ada perubahan skema. Repository membungkus akses ke tabel yang sudah ada.

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | INT AUTO_INCREMENT | PK |
| kode_risiko | VARCHAR(20) UNIQUE | Dihasilkan oleh `generateKodeRisiko()` |
| nama_risiko | VARCHAR(255) | Wajib diisi |
| sumber | ENUM('Internal','Eksternal') | — |
| probabilitas | TINYINT(1) | 1–5 |
| dampak_level | TINYINT(1) | 1–5 |
| bobot | DECIMAL(5,2) | Dihitung oleh `getBobot()` |
| skor_risiko | INT | `round(p × d × bobot)` |
| level_risiko | VARCHAR(20) | Dihitung oleh `getLevelRisiko()` |
| status | ENUM | Teridentifikasi / Ditangani / Dimonitor / Ditutup |
| tanggal_identifikasi | DATE | Format Y-m-d |
| id_user_input | INT FK | Referensi ke `users.id` |
| deleted_at | DATETIME NULL | Soft delete |
| deleted_by | INT NULL | FK ke `users.id` |

### DTO Transfer antar Lapisan

Controller membentuk array DTO sederhana sebelum memanggil Service:

```php
$dto = [
    'nama_risiko'          => trim($_POST['nama_risiko']),
    'sumber'               => $_POST['sumber'],
    'tanggal_identifikasi' => $_POST['tanggal_identifikasi'],
    'status'               => $_POST['status'],
    // dst.
];
$service->save($dto, (int)$_SESSION['user_id']);
```

---

### Req 1 — Router Interface (index.php augmentation)

```php
// Kontrak minimal: controller harus mengimplementasikan handle()
interface ControllerInterface {
    public function handle(): void;
}
```

### Req 3 — Local Asset Paths (header.php)

Setelah migrasi CDN ke lokal, `includes/header.php` menggunakan path berikut:

| Library | CDN Lama | Path Lokal Baru |
|---|---|---|
| Chart.js 4.4.3 | `cdn.jsdelivr.net/npm/chart.js@4.4.0/...` | `assets/vendors/chart.js/chart.umd.min.js` |
| Font Awesome 6.5.2 | `cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/...` | `assets/vendors/font-awesome/css/all.min.css` |
| SweetAlert2 11.12.4 | `cdn.jsdelivr.net/npm/sweetalert2@11` | `assets/vendors/sweetalert2/sweetalert2.min.js` |
| simple-datatables 2.1.8 | `cdn.jsdelivr.net/npm/simple-datatables@9.0.3/...` | `assets/vendors/datatables/style.css` + `simple-datatables.js` |

### Req 4 — Test Suite Structure

```
tests/
├── Auth/
│   └── LoginTest.php
├── Security/
│   ├── CsrfTest.php
│   ├── AccessControlTest.php
│   └── FileUploadTest.php
├── Risiko/
│   └── RisikoCrudTest.php
└── Backup/
    └── RestoreTest.php
```

---

## Error Handling

### Router: halaman tidak dikenal

```php
// index.php: fallback 404
if (!$moduleFile || !file_exists(__DIR__ . '/' . $moduleFile)) {
    http_response_code(404);
    echo '<div class="empty-state">...404...</div>';
}
```

### Controller: POST gagal validasi

Jika `Validator::validate()` mengembalikan array non-kosong, `RisikoController` memanggil `setFlash('error', ...)` dan me-redirect ke halaman sebelumnya — tidak memanggil `RisikoService` atau `RisikoRepository`.

### BackupService: mysqldump gagal

`exec()` mengembalikan exit code non-zero → `BackupService` melempar `RuntimeException` → `BackupController` menangkapnya, me-log, dan menampilkan pesan error kepada pengguna tanpa detail teknis di production mode.

### Restore: HMAC tidak cocok

`hash_equals()` gagal → restore dihentikan sebelum `multi_query()` dijalankan → database tidak dimodifikasi sama sekali → Flash error ditampilkan.

### File Sementara: Upload melewati batas

`$fileInfo['size'] > 50 * 1024 * 1024` → `setFlash('error', 'Ukuran file terlalu besar. Maksimal 50MB.')` sebelum memproses konten file apapun.

---

## Req 5 — File Cleanup & Migrations

### File yang Dihapus

File-file berikut dihapus dari root direktori. `.htaccess` mempertahankan dan memperluas blok `FilesMatch` untuk semua nama ini sebagai lapisan pertahanan mendalam.

**PHP sementara:** `scratch.php`, `scratch2.php`, `scratch3.php`, `temp.php`, `alter_users.php`, `check_users.php`, `check_users_schema.php`, `check_user_cols.php`, `dbcheck.php`, `get_schema.php`, `check.php`, `check_schema.php`, `check_schema2.php`, `check_schema3.php`, `clear_locks.php`, `update_dashboard.php`, `update_db.php`, `update_kkpmr_1.php`, `update_kkpmr_2.php`, `update_kkpmr_combined.php`, `update_kkpr.php`, `update_risiko.php`, `fix_risiko.php`, `migrate_risiko.php`, `schema.php`

**Python & lain-lain (root & assets/):** `*.py`, `output.html`, `output2.html`, `target.txt`, `assets/css/*.bak`

### Struktur Migrasi

```
database/
├── migrations/
│   ├── README.md
│   └── 001_initial.sql    ← CREATE TABLE IF NOT EXISTS untuk seluruh skema
```

`001_initial.sql` menggunakan `CREATE TABLE IF NOT EXISTS` sehingga dapat dijalankan berulang kali tanpa error. File migrasi baru menggunakan pola `ALTER TABLE` atau `CREATE TABLE IF NOT EXISTS` untuk objek baru — tidak memodifikasi file yang sudah ada.

### Konvensi Penamaan Migrasi

- Format: `NNN_deskripsi_singkat.sql` (contoh: `002_add_kategori_risiko_index.sql`)
- `NNN` adalah tiga digit, diawali `001`
- `deskripsi_singkat` menggunakan huruf kecil dan underscore
- Nomor berurutan — tidak ada gap, tidak ada duplikat

---

## Req 3 — CSP Update di `.htaccess`

CSP baru menghapus `cdn.jsdelivr.net` dan `cdnjs.cloudflare.com` dari `script-src` dan `style-src`, tetapi mempertahankan `fonts.googleapis.com` (style) dan `fonts.gstatic.com` (font):

```apache
Header always set Content-Security-Policy "default-src 'self'; \
  script-src 'self' 'unsafe-inline'; \
  style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; \
  img-src 'self' data: blob: https://api.qrserver.com; \
  font-src 'self' data: https://fonts.gstatic.com; \
  connect-src 'self'; \
  frame-ancestors 'self'; \
  base-uri 'self'; \
  form-action 'self'; \
  object-src 'none'"
```

---

## Req 4 — PHPUnit Setup

### composer.json (root workspace)

```json
{
    "name": "kemenkes/manrisv2",
    "require": {},
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": { "App\\": "app/" }
    },
    "autoload-dev": {
        "psr-4": { "Tests\\": "tests/" }
    }
}
```

### phpunit.xml

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/11.0/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="Manrisv2 Test Suite">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory suffix=".php">app</directory>
        </include>
    </source>
</phpunit>
```

### Test Bootstrap

`vendor/autoload.php` (setelah `composer install`) menjadi bootstrap. Untuk test yang memerlukan koneksi DB, file `tests/bootstrap.php` dapat memuat koneksi SQLite in-memory atau mock database menggunakan PHPUnit mock objects.

---

## Testing Strategy

Pendekatan pengujian menggunakan dua lapisan yang saling melengkapi:

**Unit Tests / Example-Based Tests** — untuk skenario spesifik dan edge cases:
- `LoginTest`: kredensial valid/tidak valid, demo account block
- `CsrfTest`: token valid vs tidak valid / tidak ada
- `AccessControlTest`: Risk Manager tidak dapat hapus record orang lain; Admin tidak dibatasi
- `RisikoCrudTest`: insert, update, soft-delete via `RisikoService` + `RisikoRepository`
- `FileUploadTest`: `.php` ditolak; `image/jpeg` dalam batas ukuran diterima
- `RestoreTest`: password salah → tolak; HMAC tidak valid → tolak
- `ValidatorTest`: satu test case per metode, masing-masing dengan kasus valid dan tidak valid

**Property-Based Tests** — untuk memvalidasi properti universal di banyak input:
- `RisikoServicePropertyTest`: `getBobot()` selalu positif; `getLevelRisiko()` exhaustive
- `ValidatorPropertyTest`: tanggal non-Y-m-d, status di luar enum, skala di luar 1–5, bobot non-positif
- `BackupServicePropertyTest`: HMAC round-trip, temp file cleanup, shellarg sanitization
- `HeaderCdnPropertyTest`: output header.php tidak mengandung CDN URL untuk library lokal
- `MigrationNamingPropertyTest`: semua file `.sql` di `database/migrations/` cocok dengan pola nama

Property tests menggunakan minimum 100 iterasi per property. Tag format: `Feature: architectural-refactor, Property {N}: {property_text}`.

---

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system — essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: Bobot Risiko Selalu Positif

*For any* valid probabilitas `p` dan dampak `d` masing-masing dalam rentang [1..5], fungsi `RisikoService::getBobot(p, d)` SHALL mengembalikan nilai float yang lebih besar dari nol.

**Validates: Requirements 1.6**

### Property 2: Level Risiko Exhaustive

*For any* integer `skor` (termasuk nilai negatif, nol, dan sangat besar), fungsi `RisikoService::getLevelRisiko(skor)` SHALL mengembalikan tepat salah satu dari: `'Sangat Rendah'`, `'Rendah'`, `'Sedang'`, `'Tinggi'`, `'Sangat Tinggi'`.

**Validates: Requirements 1.6**

### Property 3: Validator Menolak Semua Tanggal Non-Y-m-d

*For any* string yang bukan tanggal valid berformat `Y-m-d` (termasuk string acak, format tanggal lain, dan string kosong), `Validator::ruleDate()` SHALL mengembalikan pesan error non-null yang menyebutkan format yang diharapkan.

**Validates: Requirements 2.2, 2.3**

### Property 4: Validator Menolak Semua Status di Luar Enum

*For any* string yang bukan anggota `['Teridentifikasi', 'Ditangani', 'Dimonitor', 'Ditutup']`, `Validator::ruleRiskStatus()` SHALL mengembalikan pesan error non-null.

**Validates: Requirements 2.4**

### Property 5: Validator Menolak Skala Risiko di Luar 1–5

*For any* nilai integer di luar rentang [1, 5] atau nilai non-integer, `Validator::ruleRange()` untuk aturan `range:1,5` SHALL mengembalikan pesan error non-null.

**Validates: Requirements 2.5**

### Property 6: Validator Menolak Bobot Non-Positif

*For any* nilai yang bukan numerik atau bernilai kurang dari atau sama dengan nol, `Validator::rulePositiveNum()` SHALL mengembalikan pesan error non-null.

**Validates: Requirements 2.6**

### Property 7: Header HTML Tidak Mengandung URL CDN untuk Library Lokal

*For any* render dari `includes/header.php`, output HTML yang dihasilkan SHALL NOT mengandung string `cdn.jsdelivr.net` atau `cdnjs.cloudflare.com` sebagai sumber `<script src>` atau `<link href>` untuk Chart.js, Font Awesome, SweetAlert2, atau DataTables.

**Validates: Requirements 3.2**

### Property 8: Konvensi Nama File Migrasi

*For any* file di dalam direktori `database/migrations/` dengan ekstensi `.sql`, nama filenya SHALL cocok dengan pola regex `^\d{3}_[a-z0-9_]+\.sql$`.

**Validates: Requirements 5.4**

### Property 9: HMAC Backup Round-Trip

*For any* konten SQL yang dihasilkan oleh `BackupService::createBackupFile()`, ketika baris pertama file di-parse sebagai HMAC signature dan diverifikasi terhadap sisa konten dengan `APP_KEY`, `hash_equals()` SHALL mengembalikan `true`.

**Validates: Requirements 6.4, 6.8**

### Property 10: Shellarg Sanitizes Credentials

*For any* string kredensial database (host, user, password, dbname) yang berisi karakter shell-khusus (spasi, kutip, backtick, semicolon, `$`, `|`, dsb.), hasil `escapeshellarg()` pada nilai tersebut SHALL menghasilkan string yang — ketika disertakan dalam perintah shell — tidak mengeksekusi perintah tambahan dan tidak merusak argumen yang dikirimkan ke `mysqldump`.

**Validates: Requirements 6.2, 6.3**

### Property 11: Temp File Selalu Dihapus Setelah Streaming

*For any* operasi download backup yang berhasil diselesaikan oleh `BackupController`, path file temporary yang dikembalikan oleh `BackupService::createBackupFile()` SHALL tidak ada lagi di filesystem setelah `readfile()` selesai dipanggil (file dihapus oleh `cleanupTempFile()`).

**Validates: Requirements 6.6**
