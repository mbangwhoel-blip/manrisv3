# Design Document

## Security Bugfix — SEMAR (Sistem Informasi Manajemen Risiko)

---

## Overview

Dokumen ini menjelaskan desain teknis untuk perbaikan delapan temuan keamanan di aplikasi SEMAR. Semua perubahan bersifat **minimal, bedah, dan non-regresi**: hanya file yang disebutkan dalam requirements yang dimodifikasi, menggunakan pola kode yang sudah ada di kodebase.

Aplikasi berjalan di atas PHP 8.x + MySQLi di XAMPP (Apache). Tidak ada library eksternal yang digunakan; pola otorisasi sudah tersedia di `includes/functions.php` (`ownsRecord()`, `ownsKkpr()`, `canAccessAllRecords()`, `saveTtdBase64()`).

---

## Architecture

### Lapisan yang Terpengaruh

```
┌─────────────────────────────────────────────────────┐
│  Apache Layer  (.htaccess)                          │  ← Req 1
├─────────────────────────────────────────────────────┤
│  Auth Layer    (includes/auth.php)                  │  ← Req 2
├─────────────────────────────────────────────────────┤
│  Module Layer  (modules/*.php)                      │
│  ├── mitigasi.php          (hapus)                  │  ← Req 3
│  ├── kkpmr.php             (simpan/update_header)   │  ← Req 4, 5
│  ├── kkpr.php              (TTD)                    │  ← Req 7
│  ├── profil_risiko.php     (TTD)                    │  ← Req 7
│  ├── backup_restore.php    (verify)                 │  ← Req 6
│  └── ikk.php               (verify)                 │  ← Req 8
├─────────────────────────────────────────────────────┤
│  Helper Layer  (includes/functions.php)             │
│  └── ownsRecord(), ownsKkpr(), saveTtdBase64()      │
└─────────────────────────────────────────────────────┘
```

Setiap perbaikan bersifat **independent** — tidak ada perbaikan yang bergantung pada perbaikan lain kecuali Req 7 yang bersifat lintas-modul.

---

## Components and Interfaces

### Component 1 — `.htaccess` FilesMatch Extension

**File:** `.htaccess`

**Masalah:** FilesMatch block ke-3 mencantumkan nama file tanpa `check_users.php`, `dbcheck.php`, `scratch.php`, `scratch2.php`, `scratch3.php`, `temp.php`.

**Solusi:** Tambahkan keenam nama tersebut ke dalam FilesMatch block yang sudah ada. Tidak perlu membuat block baru.

**Sebelum (existing block):**
```apache
<FilesMatch "^(alter_users|check|check_schema|check_schema2|check_schema3|check_users|check_users_schema|check_user_cols|clear_locks|dbcheck|fix_risiko|get_schema|migrate_risiko|schema|scratch|scratch2|scratch3|temp|update_dashboard|update_db|update_kkpmr_1|update_kkpmr_2|update_kkpmr_combined|update_kkpr|update_risiko)\.php$">
  Require all denied
</FilesMatch>
```

> **Perhatian:** Setelah membaca file `.htaccess` yang ada, terlihat bahwa `check_users`, `dbcheck`, `scratch`, `scratch2`, `scratch3`, dan `temp` **sudah ada** dalam FilesMatch block ini. Requirement 1 meminta konfirmasi bahwa nama-nama tersebut tercakup. Verifikasi ini mengkonfirmasi bahwa blok sudah benar dan tidak perlu ada perubahan pada bagian ini. Namun demikian, task implementasi tetap perlu **memverifikasi dan mendokumentasikan** keberadaan blok tersebut serta memastikan tidak ada nama yang hilang dari daftar.

**Catatan penting tentang `.py`:** FilesMatch `\.(env|sql|md|log|txt|zip|py|bak)$` sudah ada dan mencakup `add_pimpinan.py`. Tidak ada perubahan yang diperlukan untuk ini.

---

### Component 2 — Demo Password Block di `includes/auth.php`

**File:** `includes/auth.php`

**Masalah:** Blok yang ada di `auth.php` sudah memiliki satu pemeriksaan untuk `$password === 'password'`. Requirement 2 memperluas ini menjadi daftar (array) resmi bernama `DEMO_PASSWORDS` yang minimal mencakup `'demo123'`, dan melakukan pemeriksaan ini setelah `password_verify()` berhasil.

**Alur Login yang Diperbarui:**

```
POST action=login
    │
    ├─ verifyCsrf() → gagal? → flash error + redirect
    │
    ├─ Rate limiting check
    │
    ├─ DB lookup: SELECT … WHERE username = ?
    │
    ├─ password_verify($password, $user['password'])
    │   ├─ false → increment fail counter → flash error + redirect
    │   └─ true ↓
    │
    ├─ [NEW] Demo password block:
    │   IF APP_ENV !== 'development'
    │      AND in_array($password, DEMO_PASSWORDS, true)
    │   THEN → flash 'Username atau password salah.' + redirect
    │
    └─ Buat session → redirect dashboard
```

**Kode (lokasi penambahan di auth.php):**

```php
// Di dalam blok: if ($user && $user['aktif'] == 1 && password_verify($password, $user['password'])) {
// Tambahkan SEBELUM session creation:

$demoPwList = ['demo123', 'password'];
if (APP_ENV !== 'development' && in_array($password, $demoPwList, true)) {
    logAktivitas('LOGIN_GAGAL', 'auth', null,
        'Percobaan login dengan demo password di production, username: ' . $username);
    setFlash('error', 'Username atau password salah.');
    header('Location: ' . APP_URL . '/index.php?page=login');
    exit;
}
```

**Catatan:** Password `'password'` sudah ditangani oleh blok lama yang berada *sebelum* DB lookup. Blok lama tersebut **dipertahankan** (tidak dihapus). Blok baru di dalam blok `password_verify()` menangani `'demo123'` dan melengkapi perlindungan dengan konteks pengguna yang valid.

---

### Component 3 — IDOR Guard di `modules/mitigasi.php` (aksi hapus)

**File:** `modules/mitigasi.php`

**Masalah:** Kode saat ini untuk aksi `hapus` sudah menggunakan pola JOIN yang benar:

```php
$ownerSql = 'SELECT m.bukti_file, m.ttd_file, r.id_user_input 
             FROM mitigasi m JOIN risiko r ON r.id=m.id_risiko WHERE m.id=?';
```

Dan sudah memeriksa `canAccessAllRecords()` vs `id_user_input`. Namun **tidak ada** `error_log()` untuk mencatat percobaan IDOR yang gagal.

**Perubahan yang diperlukan:** Tambahkan `error_log()` pada kondisi gagal ownership:

```php
if (!$row || (!canAccessAllRecords() && (int)$row['id_user_input'] !== (int)$_SESSION['user_id'])) {
    error_log('[manris] IDOR attempt blocked: User ID ' . (int)$_SESSION['user_id']
        . ' tried to delete mitigasi ID ' . $id);
    setFlash('error', 'Anda tidak memiliki hak untuk menghapus mitigasi ini.');
    header('Location: ' . APP_URL . '/?page=mitigasi'); exit;
}
```

**Verifikasi:** Kode ownership check yang sudah ada sudah memenuhi Requirement 3.1, 3.2, 3.4. Hanya Requirement 3.3 yang memerlukan perubahan aktif (penambahan `error_log()`).

---

### Component 4 — ownsKkpr() Guard di `modules/kkpmr.php`

**File:** `modules/kkpmr.php`

**Masalah:** Kode saat ini sudah memanggil `ownsKkpr()` untuk `simpan_pemantauan` dan `update_header_pemantauan`. Ini terlihat di file yang dibaca:

```php
// Untuk simpan_pemantauan (sudah ada):
if (!ownsKkpr($db, $idKkpr)) {
    setFlash('error', 'Anda tidak memiliki hak untuk memperbarui pemantauan KKPR ini.');
    header('Location: ' . APP_URL . '/?page=kkpmr'); exit;
}

// Untuk update_header_pemantauan (sudah ada):
if (!ownsKkpr($db, $idKkpr)) {
    setFlash('error', 'Anda tidak memiliki hak untuk memperbarui KKPR ini.');
    header('Location: ' . APP_URL . '/?page=kkpmr'); exit;
}
```

**Verifikasi:** Requirement 4 sudah terpenuhi oleh kode yang ada. Task implementasi perlu **memverifikasi** bahwa kedua blok ini ada, ditempatkan setelah `verifyCsrf()` dan sebelum query UPDATE, serta tidak ada modifikasi kode yang menghapus blok-blok ini.

---

### Component 5 — Data Isolation di `modules/kkpmr.php` (read access)

**File:** `modules/kkpmr.php`

**Masalah:** `$kkpr_cond` sudah diterapkan pada query list. Task adalah memverifikasi tidak ada regresi.

```php
// Sudah ada:
$kkpr_cond = hasRole('Admin', 'Pimpinan') ? "" : "WHERE created_by = " . (int)$_SESSION['user_id'];
$kkprList = $db->query("SELECT ... FROM kkpr_header $kkpr_cond ORDER BY ...");

// Sudah ada untuk single-row fetch:
$scope = canAccessAllRecords() ? '' : ' AND created_by = ?';
```

**Verifikasi:** Requirement 5 adalah non-regresi. Tidak ada perubahan kode yang diperlukan; hanya verifikasi.

---

### Component 6 — Verifikasi `modules/backup_restore.php`

**File:** `modules/backup_restore.php`

Setelah membaca file, semua kontrol keamanan **sudah ada**:

| Requirement | Status | Lokasi di kode |
|---|---|---|
| `requireRole('Admin')` di baris 7 | ✅ Ada | Baris setelah `requireLogin()` |
| `verifyCsrf()` sebelum proses restore | ✅ Ada | Di dalam blok `action === 'restore'` |
| `password_verify()` untuk Admin | ✅ Ada | Sebelum eksekusi SQL |
| Reject dengan flash + redirect jika gagal | ✅ Ada | `setFlash('error', '...')` + redirect |

**Verifikasi:** Requirement 6 adalah non-regresi/konfirmasi. Tidak ada perubahan kode diperlukan.

---

### Component 7 — TTD Handling: Lewati Validasi GD untuk Path Lama

**File:** `modules/profil_risiko.php`, `modules/kkpr.php`, `modules/mitigasi.php`

**Masalah:** Pola yang ada sudah menggunakan `str_starts_with($f['ttd_pemilik'], 'data:image')` di `profil_risiko.php` dan `kkpr.php`. Pola ini **sudah benar** untuk meng-skip `saveTtdBase64()` ketika nilai tidak dimulai dengan `data:image`.

Untuk `modules/mitigasi.php`, kode saat ini sudah menggunakan:
```php
$ttdData = trim($_POST['ttd_data'] ?? '');
if (!empty($ttdData) && str_starts_with($ttdData, 'data:image/png;base64,')) {
```

Ini lebih spesifik (`data:image/png;base64,`) dibanding pola di modul lain (`data:image`). Perlu disamakan menjadi `str_starts_with($ttdData, 'data:image')` agar konsisten dengan Requirement 7 dan mendukung JPEG juga.

**Pola TTD yang Benar (digunakan di semua modul):**

```php
// Pattern untuk field ttd_pemilik / ttd_pengelola / ttd_data:
$ttdValue = trim($_POST['ttd_data'] ?? '');  // atau ttd_pemilik, ttd_pengelola
$ttdFn = null;

if (!empty($ttdValue) && str_starts_with($ttdValue, 'data:image')) {
    // [A] Nilai baru — gambar yang baru digambar user
    $ttdKey = 'nama_prefix_' . time();
    $ttdRes = saveTtdBase64($ttdValue, $ttdKey);
    if (!$ttdRes['valid']) {
        setFlash('error', $ttdRes['error'] ?? 'Tanda tangan tidak valid.');
        header('Location: ...'); exit;
    }
    $ttdValue = $ttdRes['path'];  // simpan path relatif ke DB
    $ttdFn = basename($ttdValue);
} elseif (!empty($ttdValue)) {
    // [B] Nilai lama — path relatif yang sudah tersimpan, lewati saveTtdBase64()
    // $ttdValue tidak diubah; dipakai langsung
}
// Jika $ttdValue kosong, tidak ada TTD yang disimpan
```

**Inventaris perubahan per file:**

| File | Field | Status saat ini | Perubahan yang diperlukan |
|---|---|---|---|
| `modules/profil_risiko.php` | `ttd_pemilik`, `ttd_pengelola` | `str_starts_with(..., 'data:image')` ✅ | Verifikasi saja |
| `modules/kkpr.php` | `ttd_pemilik`, `ttd_pengelola` | `str_starts_with(..., 'data:image')` ✅ | Verifikasi saja |
| `modules/mitigasi.php` | `ttd_data` | `str_starts_with(..., 'data:image/png;base64,')` ⚠️ | Perluas prefix ke `'data:image'` agar konsisten |

---

### Component 8 — Verifikasi `modules/ikk.php`

**File:** `modules/ikk.php`

Setelah membaca file, `ownsRecord()` sudah ada di kedua aksi:

```php
// aksi 'simpan' dengan $id > 0:
if (!ownsRecord($db, 'ikk', $id)) {
    setFlash('error', 'Anda tidak memiliki hak untuk mengubah data IKK ini.');
    header('Location: ' . APP_URL . '/?page=ikk'); exit;
}

// aksi 'hapus':
if (!ownsRecord($db, 'ikk', $id)) {
    setFlash('error', 'Anda tidak memiliki hak untuk menghapus data IKK ini.');
    header('Location: ' . APP_URL . '/?page=ikk'); exit;
}
```

**Verifikasi:** Requirement 8 adalah non-regresi. Tidak ada perubahan kode diperlukan.

---

## Data Models

Tidak ada perubahan schema database. Semua perubahan bersifat logika PHP dan konfigurasi server.

### Kolom yang Relevan untuk Otorisasi

| Tabel | Kolom ownership | Digunakan oleh |
|---|---|---|
| `risiko` | `id_user_input` | mitigasi.php (hapus join) |
| `kkpr_header` | `created_by` | ownsKkpr() → ownsRecord() |
| `mitigasi` | — (lewat JOIN risiko) | hapus aksi |
| `ikk` | `created_by` | ownsRecord() |
| `profil_risiko` | `created_by` | ownsRecord() |

---

### Shared Interfaces

#### `ownsRecord(mysqli $db, string $table, int $id): bool`

Sudah ada di `includes/functions.php`. Mendukung tabel: `risiko`, `profil_risiko`, `kkpr_header`, `ikk`.
- Mengembalikan `true` untuk Admin/Pimpinan (via `canAccessAllRecords()`)
- Mengembalikan `true` jika kolom owner = session user_id
- Mengembalikan `false` untuk kasus lainnya

#### `ownsKkpr(mysqli $db, int $kkprId): bool`

Wrapper untuk `ownsRecord($db, 'kkpr_header', $kkprId)`.

#### `saveTtdBase64(string $base64Data, string $filename): array`

Mengembalikan `['valid' => bool, 'path' => string, 'error' => string]`.
- `valid = true` → `path` berisi path relatif seperti `uploads/ttd/xxx.png`
- `valid = false` → `error` berisi pesan kesalahan

---

## Error Handling

| Skenario | Handler | Respons |
|---|---|---|
| IDOR hapus mitigasi | Ownership check gagal | `error_log()` + flash error + redirect `/?page=mitigasi` |
| IDOR simpan/update kkpmr | `ownsKkpr()` gagal | Flash error + redirect `/?page=kkpmr` |
| Login demo password di production | Demo check blok | Flash 'Username atau password salah.' + redirect login |
| TTD tidak valid (GD reject) | `saveTtdBase64()` → valid=false | Flash error + redirect tanpa save |
| TTD adalah path lama | Prefix check gagal `data:image` | Lewati `saveTtdBase64()`, gunakan value apa adanya |
| Restore dengan password salah | `password_verify()` gagal | Flash error + redirect tanpa eksekusi SQL |
| Akses HTTP ke maintenance script | `.htaccess` FilesMatch | HTTP 403 Forbidden |

---

## Security Considerations

1. **Defense in depth untuk demo password:** Blok lama (`$password === 'password'`) sebelum DB lookup dipertahankan. Blok baru (array `DEMO_PASSWORDS` setelah `password_verify()`) menambah lapisan kedua untuk skenario di mana username valid dan password hash cocok.

2. **Tidak menyimpan base64 di database:** `saveTtdBase64()` selalu mengembalikan path relatif. Kolom `ttd_data`, `ttd_pemilik`, `ttd_pengelola` di DB harus selalu berisi string seperti `uploads/ttd/xxx.png`, bukan string base64.

3. **Error log IDOR tidak mengekspos data sensitif:** Log hanya mencantumkan user_id dan mitigasi_id (integer), bukan data bisnis.

4. **CSRF diverifikasi sebelum semua pengecekan otorisasi:** Pola yang ada sudah konsisten — `verifyCsrf()` adalah pemeriksaan pertama di setiap blok POST handler.

5. **Privilege check ordering:** `requireRole()` → `verifyCsrf()` → ownership check → DB operation. Urutan ini memastikan pengguna tidak terotentikasi tidak pernah mencapai query DB.

---

## Testing Strategy

### Unit Tests

Unit test untuk komponen-komponen berikut:

- **Auth demo password block** (`includes/auth.php`): Test dengan berbagai kombinasi `APP_ENV` dan password — pastikan blok demo tidak aktif di development, aktif di production.
- **IDOR mitigasi hapus**: Test dengan user non-pemilik — pastikan response ditolak dan `error_log()` dipanggil.
- **TTD conditional logic**: Test dengan nilai `data:image/png;base64,...` (baru) dan `uploads/ttd/xxx.png` (lama) — pastikan `saveTtdBase64()` hanya dipanggil untuk nilai baru.
- **ownsKkpr non-regresi** (`kkpmr.php`): Test dengan user yang bukan pemilik — pastikan kedua aksi ditolak.
- **ownsRecord non-regresi** (`ikk.php`): Test dengan user yang bukan pemilik — pastikan simpan dan hapus ditolak.

### Property Tests

Setiap property di bagian Correctness Properties di bawah diterjemahkan menjadi property-based test dengan minimum 100 iterasi menggunakan input yang di-generate secara acak.

Konfigurasi test: **100 iterasi per property**, menggunakan data mock untuk menghindari hit database nyata.

### Integration / Smoke Tests

- **HTTP 403 untuk maintenance scripts**: Request HTTP GET ke `check_users.php`, `dbcheck.php`, `scratch.php`, `scratch2.php`, `scratch3.php`, `temp.php` — assert status 403.
- **backup_restore.php access control**: Akses oleh non-Admin — assert redirect atau 403.
- **`.htaccess` directive preservation**: Assert bahwa security headers dan FilesMatch blocks yang ada tetap utuh.

### Pendekatan Testing

**Unit tests** fokus pada contoh spesifik dan kondisi batas. **Property tests** memvalidasi invariant universal di seluruh input yang di-generate. Keduanya diperlukan untuk coverage yang komprehensif.

---

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system — essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: Demo password selalu ditolak di production

*For any* username/password combination where the password is in the `DEMO_PASSWORDS` list, jika `APP_ENV !== 'development'`, maka login handler harus mengembalikan response penolakan (flash error + redirect) tanpa membuat session, terlepas dari apakah username tersebut valid di database.

**Validates: Requirements 2.1, 2.3**

---

### Property 2: Otorisasi hapus mitigasi mencakup semua pengguna non-pemilik

*For any* mitigasi record `m` yang dimiliki oleh user A (melalui `risiko.id_user_input`), aksi `hapus` yang diajukan oleh user B (bukan Admin/Pimpinan dan bukan user A) harus ditolak — database tidak boleh dimodifikasi dan error log harus dicatat.

**Validates: Requirements 3.1, 3.2, 3.3**

---

### Property 3: ownsKkpr() memblokir semua write KKPMR oleh non-pemilik

*For any* `kkpr_id` yang dimiliki oleh user A, aksi `simpan_pemantauan` maupun `update_header_pemantauan` yang diajukan oleh user B (bukan Admin/Pimpinan dan bukan user A) harus ditolak sebelum query UPDATE dieksekusi.

**Validates: Requirements 4.1, 4.2, 4.3, 4.4**

---

### Property 4: Data KKPMR yang ditampilkan selalu terisolasi per kepemilikan

*For any* user dengan role non-Admin dan non-Pimpinan, daftar KKPR yang ditampilkan di modul kkpmr.php harus hanya berisi record dengan `created_by = user_id` — tidak boleh ada record milik user lain yang muncul.

**Validates: Requirements 5.1, 5.2**

---

### Property 5: Restore database selalu gagal untuk password yang salah

*For any* POST restore request dengan `confirm_password` yang tidak cocok dengan hash password Admin di database, tidak boleh ada query SQL yang dieksekusi terhadap database, dan redirect harus dilakukan tanpa perubahan data.

**Validates: Requirements 6.3, 6.4**

---

### Property 6: Nilai TTD di database selalu berupa path relatif, bukan base64

*For any* form submission yang mengandung field TTD (baik `ttd_data`, `ttd_pemilik`, maupun `ttd_pengelola`), nilai yang disimpan ke kolom database harus berupa string path relatif (mis. `uploads/ttd/xxx.png`) — tidak boleh berupa string yang dimulai dengan `data:image`.

**Validates: Requirements 7.2, 7.5**

---

### Property 7: TTD lama (path relatif) tidak pernah dikirim ke saveTtdBase64()

*For any* TTD field value yang tidak dimulai dengan `'data:image'` (yaitu sudah berupa path relatif atau kosong), fungsi `saveTtdBase64()` tidak boleh dipanggil — nilai tersebut digunakan apa adanya atau diabaikan.

**Validates: Requirements 7.1, 7.4**

---

### Property 8: ownsRecord() di ikk.php memblokir semua write oleh non-pemilik

*For any* IKK record yang dimiliki oleh user A, aksi `simpan` (update) maupun `hapus` yang diajukan oleh user B (bukan Admin/Pimpinan dan bukan user A) harus ditolak — database tidak boleh dimodifikasi dan flash error harus di-set.

**Validates: Requirements 8.1, 8.2, 8.3**
