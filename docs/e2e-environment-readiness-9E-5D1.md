# E2E Environment Readiness — Tahap 9E.5D.1

Tanggal audit: 1 November 2026
Jenis kegiatan: read-only readiness audit. Tidak ada server Laravel, migration, seeder, browser automation, database MySQL, atau transaksi yang dijalankan.

## 1. Ringkasan status

| Area | Status | Bukti / keterangan |
| --- | --- | --- |
| Google Chrome | READY (binary tersedia) | Ditemukan di `C:\Program Files\Google\Chrome\Application\chrome.exe`. Tidak diluncurkan. |
| Microsoft Edge | READY (binary tersedia) | Ditemukan di `C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe`. Tidak diluncurkan. |
| Screenshot browser | NOT VERIFIED | Browser tidak diluncurkan dan tidak ada runner automation yang dikonfigurasi. |
| Playwright | NOT READY | Tidak ditemukan pada `composer.json`, `composer.lock`, `package.json`, atau `package-lock.json`; command Playwright juga tidak tersedia. |
| Alternatif E2E runner | NOT READY | Laravel Dusk, Cypress, Puppeteer, Selenium, dan WebDriver tidak ditemukan. |
| PHP/Node runtime | READY untuk evaluasi | PHP `8.2.12`, Node `v20.20.2`, npm `10.8.2` tersedia. |
| SQLite PHP | READY | Ekstensi `pdo_sqlite` dan `sqlite3` tersedia. |
| PHPUnit isolation | READY untuk automated test | `phpunit.xml`: `APP_ENV=testing`, SQLite `:memory:`, cache/session array, mail array, queue sync. |
| Browser server isolation | NOT READY | Tidak ada konfigurasi server E2E/`.env.testing`/runner yang membuktikan server HTTP tidak akan memakai MySQL development. |
| Test accounts/fixture E2E | NOT VERIFIED | Tidak ada akun aktual diperiksa, dibuat, atau diubah. |

## 2. Browser dan kemampuan screenshot

### Browser yang tersedia

| Browser | Lokasi yang terdeteksi | Status penggunaan |
| --- | --- | --- |
| Google Chrome | `C:\Program Files\Google\Chrome\Application\chrome.exe` | Binary tersedia, belum diluncurkan. |
| Microsoft Edge | `C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe` | Binary tersedia, belum diluncurkan. |

Keberadaan binary memungkinkan penggunaan runner yang kompatibel pada tahap berikutnya. Namun, ini bukan bukti bahwa browser dapat mengakses aplikasi, menghasilkan screenshot, menyimpan download, atau menjalankan automation headless pada environment saat ini.

### Playwright dan runner automation

Pemeriksaan dependency tidak menemukan `playwright`, `@playwright/test`, Dusk, Cypress, Puppeteer, Selenium, atau WebDriver. `npx` tersedia sebagai bagian dari Node.js, tetapi tidak digunakan karena dapat mengunduh/menjalankan package dari jaringan dan tahap ini melarang instalasi dependency tanpa persetujuan.

Status screenshot: **NOT VERIFIED**. Screenshot tidak boleh diuji dengan membuka aplikasi lokal sampai isolasi database dan storage terbukti.

## 3. Kesiapan aplikasi dan isolasi database

### Konfigurasi PHPUnit yang sudah aman

`phpunit.xml` menyediakan isolasi untuk test Laravel yang sudah ada:

```text
APP_ENV=testing
DB_CONNECTION=sqlite
DB_DATABASE=:memory:
CACHE_STORE=array
SESSION_DRIVER=array
MAIL_MAILER=array
QUEUE_CONNECTION=sync
```

Konfigurasi tersebut aman untuk proses PHPUnit tunggal dan telah dipakai oleh feature/unit test. Konfigurasi ini tidak mengubah `.env` dan tidak menyentuh MySQL development.

### Mengapa SQLite `:memory:` belum siap untuk browser E2E

Browser E2E membutuhkan setidaknya dua proses: runner/browser dan server Laravel HTTP. SQLite `:memory:` terikat pada koneksi/proses sehingga fixture yang disiapkan oleh proses test tidak otomatis tersedia pada server Laravel yang berjalan dalam proses lain.

Karena itu, menjalankan `php artisan serve` dengan asumsi `phpunit.xml` akan otomatis mengisolasi database adalah **tidak aman** dan tidak boleh dilakukan. Server standalone dapat memakai konfigurasi default/environment yang berbeda, termasuk berpotensi memakai MySQL development.

### Session, cache, queue, dan storage

| Komponen | Konfigurasi source default | Konfigurasi PHPUnit | Implikasi E2E |
| --- | --- | --- | --- |
| Session | Default `database` | `array` | Browser membutuhkan session yang bertahan antar request; `array` tidak cocok untuk server HTTP terpisah. Gunakan session `file` pada direktori temporary khusus E2E atau database SQLite file test, setelah disetujui. |
| Cache | Default `database` | `array` | `array` dapat dipakai jika tidak ada kebutuhan cache lintas request; jika perlu persistence, gunakan file/SQLite khusus E2E. |
| Queue | Default bergantung env, source mendukung database | `sync` | Gunakan `sync` agar tidak ada worker external pada E2E. |
| Mail | Default bergantung env | `array` | Gunakan `array` atau mail sink lokal; jangan SMTP nyata. |
| Private storage | `storage/app/private` | Tidak diisolasi khusus oleh phpunit config | Upload proof/produk tidak boleh diarahkan ke storage aplikasi/development. Diperlukan root temporary khusus E2E. |
| Public storage | `storage/app/public` | Tidak diisolasi khusus oleh phpunit config | Perlu lokasi temporary dan cleanup runner yang terkontrol. |

## 4. Pendekatan E2E paling aman

### Rekomendasi: runner Playwright + Laravel server test-only + SQLite file sementara

Pendekatan yang direkomendasikan untuk tahap implementasi berikutnya:

1. Tambahkan Playwright sebagai `devDependency` **hanya setelah persetujuan eksplisit**; gunakan Chrome/Edge yang sudah tersedia atau browser binary yang disediakan Playwright secara terkendali.
2. Buat konfigurasi E2E khusus yang tidak mengubah `.env` development, misalnya environment process/konfigurasi test-only yang diset oleh runner.
3. Gunakan **file SQLite sementara unik per run** di direktori temporary yang tidak dilacak dan tidak berada di MySQL development. File database diperlukan agar server Laravel dan fixture setup melihat data yang sama.
4. Gunakan root session file sementara, cache array/file sementara, queue sync, mail array/sink lokal, serta root private/public storage sementara.
5. Runner membuat schema dan fixture hanya pada SQLite temporary tersebut, menjalankan server Laravel dengan environment E2E, menjalankan browser tests, mengumpulkan screenshot/download/log, lalu menghapus artefak temporary melalui cleanup yang eksplisit dan aman.
6. Jangan mengizinkan base URL E2E menunjuk ke domain Laragon, production, atau endpoint dengan database yang tidak dapat diverifikasi.

Pendekatan ini belum diterapkan pada tahap audit karena akan memerlukan dependency, file konfigurasi/test runner, bootstrap schema/fixture, dan proses server yang belum diotorisasi.

### Alternatif: Laravel Dusk

Laravel Dusk dapat dipilih bila tim lebih nyaman dengan integrasi Laravel. Saat ini Dusk belum terpasang. Dusk tetap memerlukan database dan storage terisolasi; pemasangan Dusk saja tidak menyelesaikan isolasi data.

### Tidak direkomendasikan

- Membuka `http://ecommerce-bumdes-buruan.test` untuk E2E tanpa membuktikan environment/database proses web.
- Menggunakan `ecommerce_bumdes_restore` sebagai database E2E.
- Mengandalkan SQLite `:memory:` lintas proses browser/server.
- Menggunakan `.env` development atau SMTP/rekening/storage development.
- Menggunakan `migrate:fresh`, `db:wipe`, atau reset pada database yang tidak disposable.

## 5. Status Laravel dan hambatan teknis

| Item | Status | Hambatan / catatan |
| --- | --- | --- |
| Laravel dapat diuji melalui PHPUnit | READY | Sudah ada test SQLite in-memory; bukan server browser. |
| Laravel server test-only | NOT READY | Belum ada launch script/configuration yang menetapkan DB/session/storage E2E secara eksplisit. |
| Shared browser database | NOT READY | Memerlukan SQLite file temporary atau MySQL disposable, bukan `:memory:`. |
| Fixture lifecycle | NOT READY | Belum ada factory/bootstrap E2E untuk lima role dan data finansial aman. |
| Storage isolation | NOT READY | Disk source mengarah ke storage aplikasi; diperlukan override/root test-only. |
| Screenshot/download artifact path | NOT READY | Belum ada Playwright/Dusk artifact configuration. |
| Network/email isolation | NOT READY | Perlu assert mailer array/sink pada process server, bukan hanya PHPUnit. |

## 6. Kebutuhan akun dan fixture

Tidak ada akun aktual yang diperiksa, dibuat, atau diubah. Browser E2E berikutnya membutuhkan fixture sintetis berikut:

| Identitas fixture | Role | Data minimum |
| --- | --- | --- |
| `customer_e2e` | pelanggan | Cart kosong dan cart siap checkout; riwayat order sendiri; tidak memakai email/rekening nyata. |
| `owner_e2e_a` | pemilik_umkm | Toko approved, produk pending/approved/rejected/revision, pesanan toko A, rekening penerima dummy terverifikasi. |
| `owner_e2e_b` | pemilik_umkm | Toko kedua untuk test IDOR/multi-toko; data terpisah dari toko A. |
| `admin_e2e_a` | admin_bumdes | Verifikasi toko/produk/payment dan pencatatan bukti transfer/failure dummy. |
| `admin_e2e_b` | admin_bumdes | Verifier kedua untuk aturan four-eyes transfer/failure. |
| `head_e2e` | kepala_bumdes | Persetujuan/penolakan pencairan dan seluruh laporan read-only. |
| `courier_e2e` | kurir | Verifikasi dashboard/penolakan akses role lain; tidak memerlukan delivery flow. |

Fixture data minimum tambahan:

- kategori dan dua toko approved;
- produk approved dengan stok cukup dan satu produk non-approved untuk penolakan;
- order single-store dan multi-store pickup dengan snapshot harga/komisi;
- payment pending/paid/rejected dummy;
- entitlement accrued/eligible/available/reserved/held/settled/reversed;
- disbursement requested/under_review/approved/paid/rejected/cancelled/transfer_failed;
- file JPG/PNG/PDF dummy untuk proof serta file invalid untuk validasi upload;
- data laporan/filter/pagination yang cukup tanpa rekening atau identitas pelanggan nyata.

## 7. Rekomendasi langkah berikutnya

1. Minta persetujuan untuk menambahkan satu framework E2E (direkomendasikan Playwright) sebagai dependency development.
2. Rancang dan tinjau launcher E2E yang menetapkan environment process non-development, SQLite file temporary unik, session/cache/mail/queue terisolasi, dan root storage temporary.
3. Buat runner yang dapat membuktikan sebelum server start bahwa `DB_CONNECTION=sqlite` dan path database mengarah ke file temporary yang diharapkan; jika tidak, runner harus fail-closed.
4. Buat fixture lifecycle otomatis menggunakan data sintetis; tidak boleh menggunakan seeder/manual data development.
5. Tambahkan smoke E2E lebih dahulu: guest catalog, login per role, 403 lintas role, dan private proof denial.
6. Setelah isolasi terbukti, tambah skenario mutasi satu per satu dengan screenshot/artifact yang disanitasi.
7. Uji MySQL/InnoDB concurrency secara terpisah mengikuti `docs/mysql-innodb-concurrency-audit-9E-5C.md`; Playwright tidak membuktikan locking database.

## 8. Kesimpulan

Browser binary dan runtime dasar tersedia, tetapi lingkungan E2E aman belum siap. Hambatan utama bukan browser, melainkan tidak adanya runner automation dan tidak adanya konfigurasi server/database/session/storage yang dapat membuktikan isolasi dari MySQL development.

Tidak ada perubahan sistem, source code, `.env`, database, user, migration, seeder, dependency, atau transaksi yang dilakukan pada tahap ini.
