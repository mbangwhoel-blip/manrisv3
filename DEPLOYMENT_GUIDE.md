# Panduan Deployment ManRIS

## Backup

1. Backup database sebelum upload.
2. Backup folder aplikasi sebelum menimpa file.
3. Jangan menimpa `.env`, `uploads/`, atau konfigurasi hosting.

## File Utama

Upload file yang berubah sesuai struktur folder:

- `index.php`
- `includes/functions.php`
- `includes/header.php`
- `modules/landing.php`
- `modules/login.php`
- `favicon-mark.png` (favicon browser; unggah ke root aplikasi online)
- `modules/dashboard.php`
- `modules/risiko.php`
- `modules/profil_risiko.php`
- `modules/mitigasi.php`
- `modules/monev.php`
- `modules/laporan_konsolidasi.php`
- `modules/master_indikator.php`
- `modules/saran_mitigasi.php`
- `cron_notif.php`
- `update_workflow_phase.sql`
- `migrate.php`

## Migrasi Database

Setelah backup, jalankan migration runner:

```text
php migrate.php
```

Atau gunakan request sementara dengan `MIGRATION_SECRET` (khusus saat deployment). Kirim secret via header agar tidak terekam di access log:

```text
curl -H "X-Migration-Key: MIGRATION_SECRET" https://domain/manrisv2/migrate.php
```

(`?key=MIGRATION_SECRET` pada URL juga masih didukung, namun tidak disarankan.)

Runner mencatat migration pada tabel `schema_migrations` sehingga migration yang sama tidak dijalankan ulang jika checksum tidak berubah. Request migration hanya dapat dipakai jika secret benar; setelah selesai, hapus/nonaktifkan `MIGRATION_SECRET`. Alternatif manualnya adalah menjalankan `update_workflow_phase.sql` melalui phpMyAdmin.

Migration ini menambahkan:

- `mitigasi.progress`
- `mitigasi_riwayat`
- `profil_risiko_detail.id_risiko`
- `master_indikator_kegiatan`
- `profil_risiko_indikator`
- `mitigasi.pic_user_id`
- `kkpr_risiko.monev_status`

Beberapa modul juga memiliki migrasi ringan otomatis sebagai pengaman untuk instalasi lama.

## Cron Notifikasi

Jalankan sekali sehari, contoh pukul 08:00:

```text
0 8 * * * /usr/bin/php /home/USER/public_html/manrisv2/cron_notif.php
```

Jika hosting tidak menyediakan cron CLI, gunakan URL dengan `CRON_SECRET_KEY` yang disimpan di environment, bukan ditulis langsung di source code. Kirim secret via header agar tidak terekam di access log:

```text
curl -H "X-Cron-Key: CRON_SECRET_KEY" https://domain/manrisv2/cron_notif.php
```

## Urutan Uji Setelah Deployment

1. Login sebagai Admin.
2. Buka Master Indikator dan jalankan Import Data Lama bila diperlukan.
3. Buat risiko baru dan pastikan statusnya Menunggu Review.
4. Login sebagai Risk Manager/Pimpinan dan setujui atau tolak risiko.
5. Buat Profil Risiko dan cek checklist kelengkapan.
6. Uji autosave pada Profil Risiko, Identifikasi Risiko, dan Aksi Mitigasi.
7. Buat Aksi Mitigasi, isi progress, dan cek riwayat perubahan.
8. Uji status mitigasi: progress 100% menjadi Selesai, deadline lewat menjadi Terlambat.
9. Cek Monev dan filter status.
10. Uji KKPMR: hasil pemantauan mengubah status menjadi Sudah Dipantau atau Eskalasi.
11. Jalankan Export PDF/Excel Konsolidasi.
12. Jalankan `cron_notif.php` dan pastikan notifikasi deadline tidak duplikat.

## Keamanan

- Pastikan `.env`, dump SQL, file debug, dan log tidak dapat diakses publik.
- **Lokasi `.env` yang disarankan adalah DI LUAR webroot** (mis. `/etc/manrisv2/.env`), lalu arahkan dengan environment variable `MANRIS_ENV_FILE`. Bila harus di dalam webroot, pastikan server memblokir aksesnya (`.htaccess` Apache / konfigurasi nginx).
- Ganti `APP_KEY`, `CRON_SECRET_KEY`, dan `MIGRATION_SECRET` di hosting.
- Isi `MIGRATION_SECRET` hanya saat diperlukan, lalu hapus/nonaktifkan setelah migration web selesai.
- Gunakan HTTPS.
- Jangan menyalin folder `temp_extract` ke document root produksi.
- Jangan menyalin skrip maintenance/fix/recover apa pun ke document root produksi.
- `.htaccess` memblokir akses web ke `temp_extract`, dump SQL, log, zip, file debug, dan script maintenance legacy.
- Jalankan validasi lokal sebelum upload: `php -l` untuk seluruh file PHP aktif.
- Jangan menjalankan script legacy `update_*.php`; gunakan `migrate.php`.
