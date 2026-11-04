# Production Deployment Runbook

## E-Commerce BUMDes Desa Buruan

Dokumen ini adalah panduan provisioning, deployment, pemulihan, dan serah terima untuk lingkungan production. Dokumen ini tidak menjalankan deployment dan tidak menggantikan prosedur persetujuan perubahan (change approval).

## Status audit dan batasan

### Terbukti dari source code / validasi development

- Aplikasi menggunakan Laravel `12.70.2` dan membutuhkan PHP `^8.2`.
- Dependency PHP telah lolos `composer audit --locked`, `composer validate`, dan pemeriksaan platform pada lingkungan development saat audit.
- Suite regression terakhir yang tercatat lulus: `269 passed`, `2121 assertions`, dengan database testing SQLite in-memory.
- Aplikasi memiliki endpoint health dasar `GET /up`. Endpoint ini membuktikan aplikasi dapat merespons, tetapi **bukan** pemeriksaan koneksi database atau dependency eksternal.
- Asset frontend hasil build tersedia di `public/build/manifest.json`.
- Public upload berada di `storage/app/public`; data privat, termasuk bukti transfer, berada di `storage/app/private` dan tidak boleh dipublikasikan.
- Backup script tersedia di `scripts/backup-production.ps1`, dengan manifest dan checksum SHA-256. Uji fixture lokal script berhasil, namun backup production dan restore drill nyata belum dibuktikan.
- Saat audit, tidak ditemukan jobs, events, listeners, notifications, scheduler bisnis, atau konfigurasi process manager khusus di source project.

### Wajib dilakukan atau dibuktikan administrator server

- Menyediakan host production, DNS, TLS/HTTPS, firewall, dan web server.
- Menyediakan database MySQL production, akun least-privilege, backup nyata, serta target restore yang terisolasi.
- Mengisi environment production dengan secret yang dikelola aman.
- Menentukan dan menjalankan process manager queue bila queue database dipakai.
- Menjadwalkan backup dan memverifikasi restore secara berkala.
- Menetapkan monitoring, rotasi log, alerting, retention, dan prosedur respons insiden.

> Jangan menggunakan database development sebagai database production atau sebagai target restore. Jangan menjalankan `migrate:fresh`, `migrate:refresh`, `db:wipe`, atau rollback migration destruktif pada data transaksi.

## 1. Prasyarat server

Sediakan server Linux atau Windows Server yang dikelola administrator, dengan web root aplikasi terpisah dari direktori data dan backup.

| Komponen | Kebutuhan |
| --- | --- |
| PHP | PHP 8.2 atau lebih baru yang kompatibel dengan Laravel 12 |
| Ekstensi PHP | `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`, `gd`, `intl`, `bcmath`, dan `zip` |
| Composer | Composer 2 terbaru yang kompatibel dengan PHP server |
| Node.js | Node.js LTS dan npm sesuai lockfile proyek untuk build asset |
| Database | MySQL 8 yang dikelola dan dibackup |
| Web server | Apache HTTP Server atau Nginx + PHP-FPM |
| Utilitas backup | `mysqldump`, PowerShell 7/Windows PowerShell untuk script backup saat ini, serta kemampuan ZIP |

Ekstensi `zip` tidak tersedia pada mesin audit development. Aktifkan pada build/deployment host agar Composer dapat memakai distribusi arsip secara andal; jangan bergantung pada fallback source checkout.

Sebelum provisioning, administrator harus membuktikan versi PHP dan ekstensi dengan perintah seperti berikut pada server target:

```sh
php -v
php -m
composer --version
node --version
npm --version
mysqldump --version
```

## 2. Persiapan artefak dan akses deployment

1. Ambil artefak source dari release/repository yang telah ditinjau, bukan dari direktori development aktif.
2. Simpan aplikasi, storage privat, storage publik, log, dan backup pada lokasi yang hak aksesnya dipisahkan.
3. Pastikan account deployment tidak memiliki hak akses MySQL untuk membaca data transaksi; gunakan account aplikasi khusus saat runtime.
4. Jangan menyalin `.env` development ke server production. Buat environment baru dari template di bawah dan isi secret melalui secret manager atau mekanisme administrator.
5. Buat backup yang tervalidasi sebelum migration production. Backup harus mencakup database, `storage/app/private`, dan `storage/app/public`.

Contoh struktur yang disarankan:

```text
/srv/ecommerce-bumdes/releases/<release-id>/
/srv/ecommerce-bumdes/current -> releases/<release-id>
/srv/ecommerce-bumdes/shared/storage/app/private/
/srv/ecommerce-bumdes/shared/storage/app/public/
/srv/ecommerce-bumdes/shared/storage/logs/
/srv/ecommerce-bumdes/backups/
```

Pada Windows, gunakan direktori ekuivalen di luar document root dan ACL service account yang setara. Direktori private dan backup tidak boleh dapat dibaca oleh identitas web publik.

## 3. Konfigurasi web server

Document root harus menunjuk **hanya** ke direktori `public/`. Jangan pernah menunjuk ke root proyek Laravel.

### Apache

Contoh virtual host; administrator perlu menyesuaikan versi PHP-FPM/mod_php, path, sertifikat, dan kebijakan host.

```apache
<VirtualHost *:80>
    ServerName shop.example.invalid
    DocumentRoot /srv/ecommerce-bumdes/current/public

    <Directory /srv/ecommerce-bumdes/current/public>
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/ecommerce-bumdes-error.log
    CustomLog ${APACHE_LOG_DIR}/ecommerce-bumdes-access.log combined
</VirtualHost>
```

Aktifkan virtual host TLS terpisah pada port 443, arahkan HTTP ke HTTPS setelah sertifikat tervalidasi, dan blokir akses langsung ke `.env`, `storage`, `vendor`, `database`, `docs`, serta file backup.

### Nginx + PHP-FPM

```nginx
server {
    listen 80;
    server_name shop.example.invalid;
    root /srv/ecommerce-bumdes/current/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location ~ /\. {
        deny all;
    }
}
```

Tambahkan server TLS/443 sesuai kebijakan sertifikat. Konfigurasikan redirect HTTP ke HTTPS, `X-Content-Type-Options`, pembatasan upload di web server yang selaras dengan validasi aplikasi, serta access/error log. Jangan mengaktifkan HSTS sebelum domain, subdomain, dan HTTPS telah benar-benar siap.

### HTTPS, proxy, dan cookie aman

- `APP_URL` harus memakai `https://` dan nama domain production nyata.
- Set `SESSION_SECURE_COOKIE=true`; konfigurasi Laravel sudah memakai cookie HTTP-only dan SameSite `lax` secara default, tetapi tetap verifikasi hasil runtime setelah deployment.
- Jika TLS dihentikan pada load balancer/reverse proxy, hanya percayai header `X-Forwarded-*` dari IP/range proxy yang dikendalikan. Source saat audit tidak memiliki `config/trustedproxy.php`; administrator/developer harus menambahkan konfigurasi trusted proxy yang eksplisit sebelum memakai proxy tersebut.
- Jangan membuka private storage melalui alias web server, symlink, atau CDN publik.

## 4. Template environment production

Gunakan placeholder berikut. Nilai secret, host internal, dan email aktual harus diprovisikan administrator; jangan ditulis dalam repository atau manifest backup.

```dotenv
APP_NAME="E-Commerce BUMDes Desa Buruan"
APP_ENV=production
APP_KEY=<PROVISIONED_UNIQUE_SECRET>
APP_DEBUG=false
APP_URL=https://<DOMAIN_PRODUCTION>
APP_TIMEZONE=<APPROVED_BUSINESS_TIMEZONE>

LOG_CHANNEL=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=30

DB_CONNECTION=mysql
DB_HOST=<MYSQL_HOST>
DB_PORT=3306
DB_DATABASE=<PRODUCTION_DATABASE>
DB_USERNAME=<APPLICATION_DB_USER>
DB_PASSWORD=<APPLICATION_DB_PASSWORD>

CACHE_STORE=<database_or_redis>
SESSION_DRIVER=<database_or_redis>
SESSION_COOKIE=<UNIQUE_PRODUCTION_COOKIE_NAME>
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax

QUEUE_CONNECTION=<database_or_redis>

FILESYSTEM_DISK=local

MAIL_MAILER=<production_mailer>
MAIL_HOST=<MAIL_HOST>
MAIL_PORT=<MAIL_PORT>
MAIL_USERNAME=<MAIL_USERNAME>
MAIL_PASSWORD=<MAIL_PASSWORD>
MAIL_ENCRYPTION=<tls_or_null>
MAIL_FROM_ADDRESS=<NO_REPLY_ADDRESS>
MAIL_FROM_NAME="E-Commerce BUMDes Desa Buruan"
```

Catatan:

- Nilai `APP_KEY` wajib ada dan unik untuk production, tetapi tidak boleh dibuat atau dicetak oleh runbook ini.
- Audit development menemukan `APP_ENV=local`, `APP_DEBUG=true`, mailer `log`, cache/session/queue `database`, dan filesystem `local`; itu bukan konfigurasi production yang dapat langsung dipakai.
- Pilih `database` atau Redis untuk cache/session/queue berdasarkan kapasitas operasional. Jika memilih Redis, provisioning, backup, keamanan jaringan, dan pemantauan Redis adalah pekerjaan administrator tambahan.
- Tentukan timezone bisnis secara sadar. Source menggunakan UTC; jangan mengubah timezone hanya untuk tampilan laporan tanpa analisis dampak periode transaksi.

## 5. Database dan migration

### Least privilege

Pisahkan minimal tiga akun MySQL:

| Akun | Tujuan | Hak minimum |
| --- | --- | --- |
| aplikasi runtime | koneksi Laravel runtime | `SELECT`, `INSERT`, `UPDATE`, `DELETE` hanya pada schema aplikasi; tanpa `DROP`, `CREATE`, `ALTER`, `GRANT` |
| deploy/migration | menjalankan migration terverifikasi | Hak DDL terbatas pada schema aplikasi selama window deployment |
| backup/restore | dump dan restore terkontrol | Hak dibatasi sesuai tugas; jangan gunakan root aplikasi sehari-hari |

Batasi koneksi menurut host/IP, pakai TLS database jika jaringan tidak sepenuhnya privat, dan simpan password di secret manager. Akun tidak boleh memiliki akses global atau ke database lain.

### Migration aman

1. Inventaris status migration pada database target menggunakan account deployment, setelah backup tervalidasi.
2. Review migration yang benar-benar pending beserta dependency-nya.
3. Jalankan hanya `php artisan migrate --force` dalam window perubahan yang disetujui.
4. Jangan gunakan `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`, atau `migrate:rollback` sebagai cara memulihkan transaksi.

Migration `2026_10_27_000970_add_transfer_failure_fields_to_umkm_disbursements_table.php` bersifat aditif di source: menambah metadata kegagalan transfer yang nullable dan foreign key aktor. Status penerapannya pada database production harus diverifikasi oleh administrator; runbook ini tidak mengasumsikan migration tersebut sudah diterapkan.

Financial schema memakai foreign key, unique constraint, decimal snapshot, idempotency key, dan audit event. Jangan memodifikasi tabel/nominal manual untuk mengatasi kegagalan deployment atau mengulang proses entitlement/pencairan.

## 6. Storage publik dan privat

| Lokasi | Kegunaan | Aturan |
| --- | --- | --- |
| `storage/app/public` | Foto produk/aset yang memang publik | Hanya lokasi ini yang boleh dihubungkan ke `public/storage` |
| `storage/app/private` | Bukti transfer, bukti kegagalan transfer, data privat | Tidak boleh memiliki symlink/alias URL publik |
| `storage/logs` | Log Laravel | Hanya dapat ditulis/dibaca service account dan operator berwenang |

Langkah administrator:

1. Buat direktori shared storage dan berikan hak tulis hanya kepada service account web/worker.
2. Hubungkan release ke storage shared sesuai strategi release.
3. Jalankan `php artisan storage:link` hanya untuk public storage, setelah memastikan link belum ada dan target benar.
4. Pastikan backup mencakup dua storage tersebut, namun `.env`, secret, dan backup lain tidak dimasukkan ke arsip aplikasi.
5. Uji bahwa bukti privat hanya dapat diakses melalui endpoint terotorisasi aplikasi, bukan URL file.

## 7. Queue, scheduler, dan proses latar belakang

### Queue

Source project mendukung driver `database`, tetapi audit tidak menemukan job aplikasi atau konfigurasi worker/supervisor yang sudah dipaketkan. Jika `QUEUE_CONNECTION=database` dipakai sekarang atau di masa depan, administrator harus menyediakan service worker yang diawasi.

Contoh perintah worker yang harus dijalankan oleh process manager, bukan terminal interaktif:

```sh
php /srv/ecommerce-bumdes/current/artisan queue:work --tries=3 --timeout=90 --sleep=3
```

Tentukan retry/backoff, timeout, alert gagal, dan owner proses berdasarkan SOP operasional. Setelah deploy, gunakan `php artisan queue:restart` agar worker memuat release baru. Jangan menjalankan worker jika tabel/driver queue belum diprovisikan melalui migration yang telah disetujui.

### Scheduler

Saat audit, `routes/console.php` hanya memuat command inspirasi dan tidak ada task terjadwal bisnis. Tetap siapkan satu scheduler Laravel bila task baru ditambahkan:

```cron
* * * * * cd /srv/ecommerce-bumdes/current && php artisan schedule:run >> /dev/null 2>&1
```

Backup harian saat ini lebih tepat dijadwalkan oleh cron/Task Scheduler ke script `scripts/backup-production.ps1`, bukan diasumsikan berjalan dari Laravel scheduler. Catat hasil, exit code, dan lokasi manifest untuk setiap eksekusi.

## 8. Backup dan recovery

### Backup harian

Script yang tersedia membuat struktur berikut dan menolak menimpa backup tanggal yang sama:

```text
backup/production/YYYY-MM-DD/
├── database.sql
├── private-storage.zip
├── public-storage.zip
└── manifest.json
```

Jalankan pada server production dengan environment process khusus backup yang menyediakan `MYSQLDUMP_PATH`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, dan `DB_PASSWORD`; script tidak membaca `.env` aplikasi.

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\backup-production.ps1 -ProjectRoot <PROJECT_ROOT> -BackupRoot <RESTRICTED_BACKUP_ROOT>
```

Script memeriksa file database tidak kosong, integritas ZIP, checksum SHA-256, dan manifest sebelum mempublikasikan backup akhir. Backup parsial tidak boleh disebut berhasil.

### Retensi dan perlindungan

Kebijakan yang direkomendasikan (perlu persetujuan operasional):

- harian: 35 hari;
- mingguan: 12 minggu;
- bulanan: 12 bulan;
- salinan terenkripsi off-site dengan akses terpisah;
- verifikasi checksum setiap pemindahan;
- restore drill minimal per kuartal.

Jangan aktifkan penghapusan otomatis sebelum restore berhasil diuji. Target RPO 24 jam dan RTO 4 jam adalah usulan awal, bukan SLA yang telah dibuktikan.

Backup SQL lama yang hanya memuat schema/data awal tidak cukup untuk pemulihan modul finansial saat ini. Backup pemulihan harus mencakup schema terbaru, termasuk entitlement, disbursement, allocation, event, dan metadata transfer failure, serta kedua storage.

### Pemulihan

1. Nyatakan insiden, hentikan mutasi aplikasi, dan lindungi bukti/log.
2. Pilih backup konsisten yang checksum dan manifest-nya valid.
3. Pulihkan terlebih dahulu ke database dan storage **terisolasi**, bukan database aktif.
4. Verifikasi schema, foreign key, unique constraint, snapshot komisi, order/payment, entitlement, disbursement, allocation, dan audit event secara read-only.
5. Verifikasi private storage tetap privat dan public storage tidak memuat file privat.
6. Dapatkan persetujuan pemilik sistem sebelum cutover recovery.

Jangan menjalankan seeder, membuat ulang entitlement, mengulang pencairan, atau menjalankan rollback migration untuk memperbaiki data finansial hasil restore.

## 9. Logging, monitoring, dan health check

- Pakai log channel harian dengan retensi yang disetujui; `LOG_LEVEL=warning` adalah baseline production yang disarankan.
- Kumpulkan access log, error log web server, log Laravel, status PHP-FPM/Apache/Nginx, status queue worker, kapasitas disk, dan status backup.
- Kirim alert untuk kegagalan backup, worker berhenti, 5xx meningkat, disk hampir penuh, sertifikat akan kedaluwarsa, dan kegagalan koneksi database.
- Pantau `GET /up` melalui HTTPS. Endpoint ini hanya health dasar aplikasi; tambahkan pemeriksaan database/dependency terotorisasi sebagai pekerjaan terpisah bila diperlukan.
- Jangan menulis password, token, nomor rekening, path bukti privat, isi bukti, atau metadata finansial mentah ke log/alert.

## 10. Urutan deployment Laravel 12

Lakukan dalam window perubahan dengan operator, backup terverifikasi, dan rencana rollback/cutover yang disetujui.

1. Verifikasi release artifact, checksum/commit release, `composer.lock`, dan hasil test CI.
2. Verifikasi backup baru database dan dua storage; simpan manifest/checksum.
3. Aktifkan mode pemeliharaan atau drain traffic bila diperlukan oleh strategi operasional.
4. Rilis source ke direktori release baru; jangan mengedit release lama.
5. Pasang dependency production:

   ```sh
   composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
   ```

6. Build asset dari lockfile yang ditinjau:

   ```sh
   npm ci
   npm run build
   ```

7. Hubungkan storage shared, set permission service account, dan verifikasi private storage tidak berada dalam document root.
8. Provision environment production melalui channel rahasia; jangan cache konfigurasi sebelum semua variabel production benar.
9. Review migration pending dan jalankan sekali:

   ```sh
   php artisan migrate --force
   ```

10. Bangun cache setelah environment dan migration tervalidasi:

    ```sh
    php artisan optimize:clear
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    ```

11. Pastikan public storage link benar, lalu arahkan symlink `current`/virtual host ke release baru sesuai strategi platform.
12. Restart PHP-FPM/Apache bila diperlukan dan jalankan `php artisan queue:restart` bila worker ada.
13. Jalankan smoke test tanpa mutasi: HTTPS, `/up`, login role yang berwenang, katalog publik, laporan read-only, dan akses file privat yang terotorisasi.
14. Nonaktifkan maintenance hanya setelah smoke test, monitoring, dan log bersih.

Jangan menjalankan test suite yang mengubah database pada production. Jangan menjalankan seeder kecuali ada prosedur khusus yang telah disetujui dan dipastikan tidak menyentuh data transaksi.

## 11. Rollback forward-only dan recovery

### Rollback aplikasi

Release artifact dapat dikembalikan ke release sebelumnya hanya bila database migration baru kompatibel mundur dan owner perubahan menyetujui. Pastikan worker dihentikan/drain sebelum perpindahan release.

### Rollback database

Jangan mengandalkan `migrate:rollback` untuk transaksi, payment, entitlement, disbursement, atau audit event. Down migration dapat menghapus kolom/tabel dan dapat merusak bukti historis.

Gunakan salah satu pendekatan berikut:

1. **Forward fix:** buat migration korektif/additive yang ditinjau untuk bug schema yang tidak memerlukan pemulihan data.
2. **Recovery terkontrol:** restore backup tervalidasi ke target terisolasi, rekonsiliasi read-only, lalu lakukan cutover yang disetujui.

Setiap langkah recovery finansial memerlukan catatan insiden dan persetujuan pemilik sistem. Jangan mengedit snapshot komisi, requested amount, allocation, atau event audit secara manual.

## 12. Checklist verifikasi pascadeployment

### Infrastruktur

- [ ] Domain production mengarah ke host yang benar.
- [ ] HTTPS valid; redirect HTTP aman setelah diuji.
- [ ] Document root menunjuk hanya ke `public/`.
- [ ] Directory listing dan akses ke `.env`, `vendor`, `storage`, `database`, backup, dan docs diblokir.
- [ ] PHP version dan ekstensi wajib tersedia, termasuk `zip`.
- [ ] Permission `storage` dan `bootstrap/cache` benar untuk service account.

### Aplikasi dan keamanan

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, dan `APP_URL` HTTPS sudah diverifikasi tanpa mencetak secret.
- [ ] Cookie session memakai Secure, HttpOnly, dan SameSite yang telah disetujui.
- [ ] Reverse proxy hanya dipercaya dari alamat yang dikendalikan.
- [ ] `GET /up` berhasil lewat HTTPS.
- [ ] Guest, pelanggan, pemilik UMKM, admin BUMDes, kurir, dan kepala BUMDes hanya memperoleh akses yang sesuai kewenangan.
- [ ] Bukti transfer/private proof tidak dapat diakses lewat URL publik.

### Data dan finansial

- [ ] Migration history dan migration pending diperiksa oleh account deployment.
- [ ] Semua migration yang disetujui berhasil dan dicatat.
- [ ] Katalog, checkout yang didukung, pembayaran, fulfillment, laporan komisi, laporan entitlement, laporan pencairan, dan audit finansial diuji melalui skenario aman.
- [ ] Tidak ada order/payment/entitlement/disbursement dummy dibuat pada production tanpa persetujuan.
- [ ] Laporan finansial tetap read-only; CSV export tidak mengekspos rekening, bukti privat, atau metadata mentah.

### Operasional

- [ ] Worker queue aktif bila driver queue dipakai; retry dan alert dikonfigurasi.
- [ ] Scheduler tersedia bila task terjadwal ditambahkan.
- [ ] Backup harian sukses, manifest dan checksum cocok, dan salinan off-site tercatat.
- [ ] Restore drill terisolasi telah dijadwalkan atau dibuktikan.
- [ ] Log, metrik, alert, dan kontak incident response sudah diserahkan.

## 13. Checklist serah terima klien

- [ ] Daftar pemilik sistem, administrator server, administrator database, dan kontak insiden tersedia.
- [ ] Credential diserahkan hanya melalui secret manager/channel aman, bukan dokumen ini.
- [ ] Prosedur persetujuan payment, fulfillment, rekonsiliasi, pencairan, dan transfer gagal dipahami oleh role berwenang.
- [ ] SOP backup, retensi, dan restore drill disetujui.
- [ ] SOP pembaruan dependency dan security patch disetujui.
- [ ] Log/audit trail dan batas akses bukti privat dipahami.
- [ ] Daftar risiko terbuka serta tindakan administrator diterima secara tertulis.

## 14. Risiko terbuka dan tindakan manual administrator

| Risiko / kondisi | Tindakan administrator |
| --- | --- |
| Server production belum dibuktikan tersedia | Provision host, DNS, TLS, firewall, service account, dan web server sebelum deployment |
| Environment production belum ada | Provision environment melalui secret manager; jangan menyalin `.env` development |
| Backup nyata dan restore drill belum terbukti | Jalankan backup production terjadwal, verifikasi manifest/checksum, lalu restore ke target terisolasi |
| PHP `zip` tidak tersedia pada mesin audit | Aktifkan `zip` pada build/deployment host dan verifikasi Composer install |
| Queue worker/process manager belum dikonfigurasi | Tentukan driver queue, buat service manager, retry policy, alerting, dan prosedur restart |
| Scheduler bisnis belum ada di source | Tetap jadwalkan backup eksternal; provision scheduler Laravel hanya saat task aplikasi ditambahkan |
| Trusted proxy belum dikonfigurasi di source | Tambahkan konfigurasi eksplisit sebelum deploy di belakang reverse proxy/load balancer |
| Endpoint `/up` bukan health database | Tambahkan pemantauan infrastruktur dan health check dependency yang aman jika dibutuhkan |
| Perbedaan SQLite test dan MySQL/InnoDB | Jalankan smoke test dan restore drill di lingkungan MySQL terisolasi sebelum cutover |
| Konkurensi transaksi keuangan | Pantau database MySQL dan operasional; test SQLite tidak membuktikan seluruh perilaku locking InnoDB |

## Referensi internal

- `docs/backup-retention.md`
- `scripts/backup-production.ps1`
- `scripts/test-backup-production.ps1`
- migration finansial di `database/migrations/`, terutama `2026_10_27_000970_add_transfer_failure_fields_to_umkm_disbursements_table.php`
