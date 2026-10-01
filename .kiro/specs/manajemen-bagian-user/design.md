# Design Document — Manajemen Bagian User

## Overview

Fitur ini menambahkan entitas **Bagian** (unit organisasi) ke dalam aplikasi manrisv3 yang sudah berjalan berbasis PHP + MySQLi. Implementasi memperluas halaman `?page=user` yang sudah ada dengan dua area perubahan utama:

1. **Section baru Manajemen Bagian** — card di bawah tabel User berisi tabel daftar Bagian + CRUD via modal overlay.
2. **Perbaikan tampilan kolom Aksi** — penerapan CSS `white-space:nowrap`, `vertical-align:middle`, fixed-width, dan `.act-btn-group` flex agar tombol selalu sejajar.

Seluruh operasi CRUD Bagian ditangani oleh handler POST yang sudah ada di `modules/user.php`, diperluas dengan action string baru (`bagian_simpan`, `bagian_hapus`). Keamanan ditangani oleh mekanisme yang sudah ada: `requireRole('Admin')`, `verifyCsrf()`, dan `csrfField()`.

---

## Architecture

Aplikasi menggunakan pola **Single-File Module** — satu file PHP (`modules/user.php`) menangani request POST dan rendering HTML. Tidak ada framework MVC; routing dilakukan oleh `index.php` melalui query string `?page=`.

```
Browser
  │  POST ?page=user  (aksi = bagian_simpan | bagian_hapus | simpan | hapus | toggle)
  ▼
index.php  →  require modules/user.php
                │
                ├── requireRole('Admin')      [auth guard]
                ├── verifyCsrf()              [CSRF guard — POST only]
                ├── Handler bagian_simpan     [INSERT/UPDATE bagian]
                ├── Handler bagian_hapus      [DELETE bagian]
                ├── Handler simpan            [INSERT/UPDATE users + id_bagian]
                ├── Handler hapus             [DELETE users]
                ├── Handler toggle            [UPDATE users.aktif]
                │
                └── Render HTML
                      ├── Tabel User (+ kolom Bagian)
                      ├── Section Manajemen Bagian (card + tabel + modal)
                      └── Modal User (dropdown Bagian)
```

---

## Components

### 1. Database Schema

#### Tabel `bagian` (baru)

```sql
CREATE TABLE IF NOT EXISTS bagian (
    id           INT          NOT NULL AUTO_INCREMENT,
    nama_bagian  VARCHAR(100) NOT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### Kolom `id_bagian` di tabel `users` (baru)

```sql
ALTER TABLE users
    ADD COLUMN id_bagian INT NULL DEFAULT NULL,
    ADD CONSTRAINT fk_users_bagian
        FOREIGN KEY (id_bagian) REFERENCES bagian(id)
        ON DELETE SET NULL ON UPDATE CASCADE;
```

> **Catatan migrasi:** SQL di atas dibuat idempotent dengan `IF NOT EXISTS` / `IF NOT EXISTS kolom` atau cukup dijalankan sekali sebagai migration script terpisah (`add_bagian.sql`). Tidak ada auto-migration di aplikasi; Admin menjalankan script SQL secara manual.

---

### 2. POST Handler Extension (`modules/user.php`)

Handler POST yang ada diperluas dengan dua action baru **sebelum** action `simpan`/`hapus`/`toggle` yang sudah ada:

#### `bagian_simpan` (INSERT dan UPDATE)

```php
if ($aksi === 'bagian_simpan') {
    $namaBagian = trim($_POST['nama_bagian'] ?? '');
    $idBagian   = (int)($_POST['id_bagian_edit'] ?? 0);

    // Validasi: tidak boleh kosong
    if ($namaBagian === '') {
        setFlash('error', 'Nama bagian wajib diisi');
        header('Location: ' . APP_URL . '/?page=user'); exit;
    }

    // Cek duplikat (case-insensitive, exclude id jika edit)
    $dupSql = $idBagian > 0
        ? 'SELECT id FROM bagian WHERE LOWER(nama_bagian) = LOWER(?) AND id <> ?'
        : 'SELECT id FROM bagian WHERE LOWER(nama_bagian) = LOWER(?)';
    $dupStmt = $db->prepare($dupSql);
    if ($idBagian > 0) {
        $dupStmt->bind_param('si', $namaBagian, $idBagian);
    } else {
        $dupStmt->bind_param('s', $namaBagian);
    }
    $dupStmt->execute();
    $dupStmt->store_result();
    if ($dupStmt->num_rows > 0) {
        $dupStmt->close();
        setFlash('error', 'Nama bagian sudah ada');
        header('Location: ' . APP_URL . '/?page=user'); exit;
    }
    $dupStmt->close();

    if ($idBagian > 0) {
        $stmt = $db->prepare('UPDATE bagian SET nama_bagian = ? WHERE id = ?');
        $stmt->bind_param('si', $namaBagian, $idBagian);
        $stmt->execute(); $stmt->close();
        logAktivitas('UPDATE', 'bagian', $idBagian, 'Edit bagian: ' . $namaBagian);
        setFlash('success', 'Bagian berhasil diperbarui');
    } else {
        $stmt = $db->prepare('INSERT INTO bagian (nama_bagian) VALUES (?)');
        $stmt->bind_param('s', $namaBagian);
        $stmt->execute(); $newIdBagian = $db->insert_id; $stmt->close();
        logAktivitas('CREATE', 'bagian', $newIdBagian, 'Tambah bagian: ' . $namaBagian);
        setFlash('success', 'Bagian berhasil ditambahkan');
    }
    header('Location: ' . APP_URL . '/?page=user'); exit;
}
```

#### `bagian_hapus` (DELETE)

```php
if ($aksi === 'bagian_hapus') {
    $idBagian = (int)($_POST['id_bagian'] ?? 0);
    if ($idBagian > 0) {
        $stmt = $db->prepare('DELETE FROM bagian WHERE id = ?');
        $stmt->bind_param('i', $idBagian);
        $stmt->execute(); $stmt->close();
        // ON DELETE SET NULL di constraint FK menangani users.id_bagian secara otomatis
        logAktivitas('DELETE', 'bagian', $idBagian, 'Hapus bagian ID ' . $idBagian);
        setFlash('success', 'Bagian berhasil dihapus');
    }
    header('Location: ' . APP_URL . '/?page=user'); exit;
}
```

#### Perluasan handler `simpan` (User) — tambah `id_bagian`

Query INSERT dan UPDATE user diperluas dengan kolom `id_bagian`:

```php
// Ambil id_bagian dari POST (NULL jika kosong/tidak dipilih)
$idBagianUser = !empty($_POST['id_bagian_user']) ? (int)$_POST['id_bagian_user'] : null;

// INSERT baru:
$s = $db->prepare('INSERT INTO users (nama,nip,username,email,password,role,aktif,id_bagian) VALUES (?,?,?,?,?,?,?,?)');
$s->bind_param('ssssssii', $nama, $nip, $username, $email, $hash, $role, $aktif, $idBagianUser);

// UPDATE:
$s = $db->prepare('UPDATE users SET nama=?,nip=?,username=?,email=?,role=?,aktif=?,id_bagian=? WHERE id=?');
$s->bind_param('sssssiii', $nama, $nip, $username, $email, $role, $aktif, $idBagianUser, $id);
```

> **Catatan NULL binding:** MySQLi `bind_param` dengan tipe `i` dan nilai PHP `null` akan menyimpan NULL ke kolom.

---

### 3. Data Retrieval

#### Query Users (diperluas dengan JOIN ke bagian)

```php
$users = $db->query(
    "SELECT u.id, u.nama, u.nip, u.username, u.email, u.role,
            u.kode_prefix, u.aktif, u.created_at, u.id_bagian,
            b.nama_bagian
     FROM users u
     LEFT JOIN bagian b ON b.id = u.id_bagian
     ORDER BY u.role, u.nama"
)->fetch_all(MYSQLI_ASSOC);
```

#### Query Bagian (untuk section dan dropdown)

```php
$daftarBagian = $db->query(
    "SELECT id, nama_bagian FROM bagian ORDER BY nama_bagian ASC"
)->fetch_all(MYSQLI_ASSOC);
```

---

### 4. HTML / UI Components

#### 4.1 Tabel User — Perubahan

- Tambah kolom **Bagian** di antara kolom Role dan Kode Prefix.
- Output: `xss($u['nama_bagian'] ?? '—')`.
- Kolom `<th>Aksi</th>` diberi style `white-space:nowrap; width:130px`.
- Setiap `<td>` kolom aksi diberi `style="white-space:nowrap; vertical-align:middle; width:130px"`.
- `<div class="act-btn-group">` sudah ada di HTML; CSS `.act-btn-group` didefinisikan di `main.css`.

#### 4.2 Section Manajemen Bagian (baru, di bawah tabel User)

```html
<div class="card" style="margin-top:24px">
  <div class="card-header" style="...">
    <h2><i class="fas fa-building"></i> Manajemen Bagian</h2>
    <button class="btn btn-primary btn-sm" onclick="tambahBagian()">
      <i class="fas fa-plus"></i> Tambah Bagian
    </button>
  </div>
  <div class="table-responsive">
    <table class="data-table">
      <thead><tr><th>No</th><th>Nama Bagian</th><th style="width:110px">Aksi</th></tr></thead>
      <tbody>
        <?php foreach($daftarBagian as $i => $b): ?>
        <tr>
          <td style="color:var(--text-muted)"><?= $i+1 ?></td>
          <td><?= xss($b['nama_bagian']) ?></td>
          <td style="white-space:nowrap;vertical-align:middle;width:110px">
            <div class="act-btn-group">
              <button class="act-btn act-btn-edit" onclick="editBagian(<?= $b['id'] ?>, '<?= jsEncode($b['nama_bagian']) ?>')" title="Edit">
                <i class="fas fa-edit"></i>
              </button>
              <button class="act-btn act-btn-delete" onclick="hapusBagian(<?= $b['id'] ?>, '<?= jsEncode($b['nama_bagian']) ?>')" title="Hapus">
                <i class="fas fa-trash"></i>
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
```

#### 4.3 Modal Tambah/Edit Bagian

Satu modal digunakan untuk tambah dan edit (nilai `id_bagian_edit` = 0 untuk tambah):

```html
<div class="modal-overlay" id="modalBagian" style="display:none">
  <div class="modal" style="max-width:420px">
    <div class="modal-header">
      <h3 class="modal-title" id="modalBagianTitle">
        <i class="fas fa-building"></i> Tambah Bagian
      </h3>
      <button class="btn-close" onclick="closeModal('modalBagian')">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/?page=user">
      <?= csrfField() ?>
      <input type="hidden" name="aksi" value="bagian_simpan">
      <input type="hidden" name="id_bagian_edit" id="bId" value="0">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Nama Bagian <span class="required">*</span></label>
          <input type="text" name="nama_bagian" id="bNama" class="form-control"
                 maxlength="100" required placeholder="cth: Sub Bagian Umum">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" onclick="closeModal('modalBagian')" class="btn btn-outline">Batal</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan</button>
      </div>
    </form>
  </div>
</div>
```

#### 4.4 Modal Hapus Bagian

```html
<div class="modal-overlay" id="modalHapusBagian" style="display:none">
  <div class="modal" style="max-width:400px">
    <div class="modal-header">
      <h3 class="modal-title" style="color:var(--danger)">
        <i class="fas fa-trash"></i> Hapus Bagian
      </h3>
      <button class="btn-close" onclick="closeModal('modalHapusBagian')">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <div class="modal-body">
      <p>Yakin hapus bagian <strong id="hapusBagianNama"></strong>?<br>
      <small style="color:var(--text-muted)">
        User yang tergabung dalam bagian ini akan kehilangan kaitannya (id_bagian → NULL).
      </small></p>
    </div>
    <div class="modal-footer">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="aksi" value="bagian_hapus">
        <input type="hidden" name="id_bagian" id="hapusBagianId">
        <button type="button" onclick="closeModal('modalHapusBagian')" class="btn btn-outline">Batal</button>
        <button type="submit" class="btn btn-danger">Hapus</button>
      </form>
    </div>
  </div>
</div>
```

#### 4.5 Dropdown Bagian di Modal User

Ditambahkan di dalam `<div class="form-row-2">` pada modal User, setelah select Role:

```html
<div class="form-group">
  <label class="form-label">Bagian</label>
  <select name="id_bagian_user" id="uBagian" class="form-control">
    <option value="">— Pilih Bagian —</option>
    <?php foreach($daftarBagian as $b): ?>
    <option value="<?= $b['id'] ?>"><?= xss($b['nama_bagian']) ?></option>
    <?php endforeach; ?>
  </select>
</div>
```

---

### 5. JavaScript

```javascript
// ── Bagian ────────────────────────────────────────────────────
function tambahBagian() {
    document.getElementById('modalBagianTitle').innerHTML =
        '<i class="fas fa-building"></i> Tambah Bagian';
    document.getElementById('bId').value = 0;
    document.getElementById('bNama').value = '';
    openModal('modalBagian');
}

function editBagian(id, nama) {
    document.getElementById('modalBagianTitle').innerHTML =
        '<i class="fas fa-edit"></i> Edit Bagian';
    document.getElementById('bId').value = id;
    document.getElementById('bNama').value = nama;
    openModal('modalBagian');
}

function hapusBagian(id, nama) {
    document.getElementById('hapusBagianId').value = id;
    document.getElementById('hapusBagianNama').textContent = nama;
    openModal('modalHapusBagian');
}

// ── Perluasan editUser untuk dropdown Bagian ──────────────────
// Tambahkan baris ini di dalam fungsi editUser() yang sudah ada:
// document.getElementById('uBagian').value = u.id_bagian || '';
```

---

### 6. CSS Additions (`assets/css/main.css`)

Tambahan di bagian "Unified Icon Action Buttons" yang sudah ada (baris ~994):

```css
/* ── act-btn-group: flex wrapper untuk tombol aksi ────────────── */
.act-btn-group {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: nowrap;
}

/* ── Data table: cell alignment fixes ────────────────────────── */
.data-table td {
    vertical-align: middle;
}
.data-table th {
    vertical-align: middle;
}
.data-table td.action-cell,
.data-table th.action-cell {
    white-space: nowrap;
    width: 130px;
    min-width: 110px;
}
```

---

## Data Models

### Entity: `bagian`

| Kolom         | Tipe             | Constraint                | Keterangan                 |
|---------------|------------------|---------------------------|----------------------------|
| `id`          | INT              | PK, AUTO_INCREMENT        | Identifier unik            |
| `nama_bagian` | VARCHAR(100)     | NOT NULL                  | Nama unit organisasi       |
| `created_at`  | DATETIME         | DEFAULT CURRENT_TIMESTAMP | Waktu pembuatan            |

### Entity: `users` (perluasan)

| Kolom       | Tipe   | Constraint                               | Keterangan              |
|-------------|--------|------------------------------------------|-------------------------|
| `id_bagian` | INT    | NULL, FK → bagian(id) ON DELETE SET NULL | Referensi ke bagian     |

### DTO: Data Bagian ke JavaScript

Tidak ada objek global JS khusus untuk Bagian — data diteruskan langsung lewat parameter fungsi `editBagian(id, nama)` dan `hapusBagian(id, nama)`.

---

## Error Handling

| Kondisi                             | Penanganan                                                          |
|-------------------------------------|---------------------------------------------------------------------|
| CSRF token tidak valid              | `setFlash('error', 'Token tidak valid')` + redirect `?page=user`   |
| Nama Bagian kosong (post-trim)      | `setFlash('error', 'Nama bagian wajib diisi')` + redirect          |
| Nama Bagian duplikat (case-insens.) | `setFlash('error', 'Nama bagian sudah ada')` + redirect            |
| DB query error (exception MySQLi)   | Ditangani oleh `manrisHandleFatal()` di `config.php`               |
| Aksi dengan id tidak valid (≤ 0)   | Guard `$id > 0` sebelum query; handler diam redirect               |

---

## Security Considerations

- **Autentikasi & Otorisasi:** `requireLogin()` + `requireRole('Admin')` dipanggil di awal file, sebelum handler POST manapun.
- **CSRF:** `verifyCsrf()` dipanggil satu kali untuk semua POST. Setiap form menyertakan `csrfField()`.
- **SQL Injection:** Seluruh query menggunakan prepared statements (`$db->prepare()`). Tidak ada string interpolasi parameter user ke query.
- **XSS:** Semua output nama Bagian melalui `xss()`. Nilai yang diembed ke atribut `onclick` menggunakan `jsEncode()`.
- **Integer cast:** `id` dan `id_bagian` selalu di-cast ke `(int)` sebelum digunakan.

---

## Migration Artifact

File SQL yang perlu dibuat dan dijalankan satu kali oleh Admin:

**`add_bagian.sql`** (disimpan di root workspace):

```sql
-- Tabel bagian
CREATE TABLE IF NOT EXISTS bagian (
    id           INT          NOT NULL AUTO_INCREMENT,
    nama_bagian  VARCHAR(100) NOT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kolom id_bagian di users
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS id_bagian INT NULL DEFAULT NULL;

-- Foreign key (jalankan terpisah jika MySQL < 8.0 tidak support ADD COLUMN IF NOT EXISTS)
ALTER TABLE users
    ADD CONSTRAINT fk_users_bagian
        FOREIGN KEY (id_bagian) REFERENCES bagian(id)
        ON DELETE SET NULL ON UPDATE CASCADE;
```

---

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system — essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: ON DELETE SET NULL menyebarkan NULL ke semua user terkait

*For any* set of users yang memiliki `id_bagian` mereferensikan suatu Bagian, setelah Bagian tersebut dihapus, seluruh user dalam set tersebut **harus** memiliki `id_bagian = NULL`.

**Validates: Requirements 1.3, 4.2**

---

### Property 2: Tambah Bagian valid tersimpan dan dapat diquery kembali

*For any* string `nama_bagian` yang tidak kosong setelah `trim()` dan belum ada di tabel `bagian`, setelah operasi INSERT berhasil, query `SELECT * FROM bagian WHERE LOWER(nama_bagian) = LOWER(?)` **harus** mengembalikan tepat satu baris.

**Validates: Requirements 2.1**

---

### Property 3: Penolakan nama Bagian whitespace-only

*For any* string yang seluruhnya terdiri dari karakter whitespace (spasi, tab, newline), operasi tambah/edit Bagian **harus** ditolak dan tabel `bagian` **tidak boleh** bertambah barisnya.

**Validates: Requirements 2.2**

---

### Property 4: Penolakan duplikat nama Bagian (case-insensitive)

*For any* nama Bagian yang sudah ada di tabel `bagian`, operasi INSERT dengan nama yang sama dalam **variasi huruf besar/kecil apapun** **harus** ditolak dan tabel `bagian` **tidak boleh** bertambah barisnya.

**Validates: Requirements 2.3, 3.2**

---

### Property 5: Edit Bagian memperbarui nama dengan benar

*For any* Bagian yang ada dan string `nama_bagian` baru yang valid (tidak kosong, tidak duplikat dengan Bagian lain), setelah operasi UPDATE berhasil, query ulang ke tabel `bagian` dengan `id` yang sama **harus** mengembalikan `nama_bagian` baru.

**Validates: Requirements 3.1**

---

### Property 6: Hapus Bagian menghilangkan baris dari tabel

*For any* Bagian yang ada di tabel `bagian`, setelah operasi DELETE berhasil, query `SELECT id FROM bagian WHERE id = ?` **harus** mengembalikan nol baris.

**Validates: Requirements 4.1**

---

### Property 7: Penolakan POST tanpa CSRF token valid

*For any* POST request ke `?page=user` yang tidak menyertakan atau menyertakan CSRF token yang salah, **semua** operasi (bagian_simpan, bagian_hapus, simpan, hapus) **harus** ditolak tanpa memodifikasi database.

**Validates: Requirements 5.2, 5.3**

---

### Property 8: Daftar Bagian selalu terurut ascending

*For any* kondisi data pada tabel `bagian`, hasil query `SELECT … FROM bagian ORDER BY nama_bagian ASC` **harus** menghasilkan daftar yang setiap elemen ke-*i* memiliki `nama_bagian` ≤ (secara leksikografis) elemen ke-(*i*+1).

**Validates: Requirements 6.2**

---

### Property 9: Simpan user dengan Bagian menyimpan id_bagian dengan benar (round-trip)

*For any* Bagian yang valid, setelah menyimpan user dengan `id_bagian` tersebut, query `SELECT id_bagian FROM users WHERE id = ?` **harus** mengembalikan nilai `id_bagian` yang sama persis dengan yang dipilih.

**Validates: Requirements 7.2, 7.4**

---

### Property 10: Simpan user tanpa Bagian menyimpan NULL

*For any* user yang disimpan dengan `id_bagian_user` kosong (pilihan "— Pilih Bagian —"), kolom `id_bagian` pada baris user tersebut di tabel `users` **harus** bernilai `NULL`.

**Validates: Requirements 7.3**

---

### Property 11: Tampilan tabel User menampilkan nama Bagian, bukan id

*For any* user dengan `id_bagian` ≠ NULL, output HTML dari tabel User **harus** mengandung string `nama_bagian` dari tabel `bagian` yang bersesuaian, dan **tidak boleh** hanya menampilkan angka integer `id_bagian` tanpa label.

**Validates: Requirements 7.5**
