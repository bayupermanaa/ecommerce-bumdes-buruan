# Playwright E2E Infrastructure — Tahap 9E.5D.2

Tanggal: 2 November 2026
Project: E-Commerce BUMDes Desa Buruan

## 1. Ringkasan implementasi

| Komponen | Hasil |
| --- | --- |
| Playwright package | @playwright/test 1.64.1 sudah tersedia sebagai devDependency dan tercatat pada package-lock.json. |
| Playwright Chromium | Smoke test berhasil menggunakan Chromium cache Playwright. Dry-run mengidentifikasi Chrome for Testing 156.0.8078.4, build 1248. |
| Config | playwright.config.js sudah menyediakan project desktop/mobile, timeout, HTML report, screenshot failure, dan trace failure. |
| Smoke test | 2 passed: Chromium desktop dan Chromium mobile. |
| Database | Migration hanya dijalankan pada SQLite file temporary unik; tidak memakai :memory: lintas proses dan tidak memakai MySQL. |
| Server | PHP built-in server pada 127.0.0.1 dengan port temporary dan environment testing eksplisit. |
| Screenshot | Screenshot desktop dan mobile berhasil dibuat. |
| Cleanup | Run saat ini dibersihkan; direktori run lama masih ada dan tidak dihapus karena batasan keamanan. |

Seluruh komponen tersebut sudah tersedia sebelum validasi tahap ini. Tidak dilakukan instalasi ulang atau perubahan dependency karena package sudah ter-resolve pada versi yang dikunci. Dependency production tidak diubah.

## 2. File dan struktur

Komponen yang digunakan:

    playwright.config.js
    scripts/e2e/run-isolated.mjs
    tests/e2e/smoke.spec.js
    tests/e2e/fixtures/.gitkeep
    tests/e2e/screenshots/.gitkeep
    tests/e2e/screenshots/chromium-desktop-catalog.png
    tests/e2e/screenshots/chromium-mobile-catalog.png
    test-results/playwright-html/index.html

Runner membuat runtime temporary dengan struktur:

    .e2e-tmp/run-<random>/
    ├── app/
    │   ├── storage/app/private/
    │   ├── storage/app/public/
    │   ├── storage/framework/sessions/
    │   ├── storage/framework/cache/data/
    │   └── storage/logs/
    └── database/e2e.sqlite

Salinan runtime mengecualikan .env, .git, node_modules, storage aplikasi, dan hasil test. Public asset disalin tanpa symlink public/storage dari development.

## 3. Isolasi database

scripts/e2e/run-isolated.mjs melakukan fail-closed check sebelum migration:

- DB_CONNECTION harus tepat sqlite.
- DB_DATABASE harus berupa path file, bukan :memory:.
- Path harus berada di bawah directory run temporary.
- Path tidak boleh mengandung ecommerce_bumdes_restore.
- QUEUE_CONNECTION harus sync.
- MAIL_MAILER harus array.

Environment yang diteruskan ke migration dan server:

    APP_ENV=testing
    APP_DEBUG=false
    APP_URL=http://127.0.0.1:<port-temporary>
    DB_CONNECTION=sqlite
    DB_DATABASE=<temporary-run>/database/e2e.sqlite
    DB_URL=
    CACHE_STORE=array
    SESSION_DRIVER=file
    QUEUE_CONNECTION=sync
    MAIL_MAILER=array
    FILESYSTEM_DISK=local
    E2E_ISOLATED=1

APP_KEY dibuat random untuk proses test dan tidak ditulis ke repository atau .env. Pada smoke run, seluruh 32 migration berhasil diterapkan hanya pada database temporary, termasuk migration 000970. Tidak ada koneksi, query, migration, atau seeder ke MySQL development.

## 4. Storage, session, cache, mail, dan queue

- Private storage berada pada app/storage/app/private di salinan runtime.
- Public storage berada pada app/storage/app/public di salinan runtime.
- Session memakai file directory temporary.
- Cache memakai array.
- Queue memakai sync tanpa worker eksternal.
- Mail memakai array tanpa SMTP.
- Log memakai storage temporary dengan level error.
- Tidak ada file bukti atau file pengguna development yang disalin.
- Server memakai router PHP temporary yang menunjuk ke public/index.php.

## 5. Konfigurasi Playwright

playwright.config.js menyediakan:

- project chromium-desktop dengan viewport 1440x900;
- project chromium-mobile berbasis iPhone 13 dengan browser Chromium;
- timeout test 30 detik dan expect timeout 10 detik;
- satu worker dan fullyParallel=false;
- screenshot only-on-failure;
- trace retain-on-failure;
- HTML report di test-results/playwright-html;
- artifact output di test-results/playwright-artifacts;
- base URL dari E2E_BASE_URL yang diisi runner ke localhost temporary.

Smoke test membuka /, memastikan redirect ke /katalog, memastikan link Katalog terlihat, dan menyimpan screenshot full page untuk kedua project.

## 6. Validasi yang dijalankan

Pemeriksaan dependency:

    npm ls @playwright/test --depth=0
    @playwright/test@1.64.1

    npx playwright --version
    Version 1.64.1

    npx playwright install --dry-run chromium
    Chrome for Testing 156.0.8078.4 (playwright chromium v1248)

Dry-run hanya memeriksa resolusi browser. Smoke test berhasil menggunakan Chromium yang tersedia; tidak ada instalasi dependency tambahan yang diperlukan.

Smoke test:

    npm run test:e2e

    Running 2 tests using 1 worker
    ok 1 [chromium-desktop] public catalog is reachable in the isolated E2E environment
    ok 2 [chromium-mobile] public catalog is reachable in the isolated E2E environment
    2 passed (10.5s)

HTTP smoke hanya menuju server temporary 127.0.0.1; asset CSS/JS merespons 200. Server dihentikan setelah test.

Screenshot yang tersedia:

- tests/e2e/screenshots/chromium-desktop-catalog.png
- tests/e2e/screenshots/chromium-mobile-catalog.png

HTML report tersedia pada test-results/playwright-html/index.html.

## 7. Cara menjalankan E2E

Prasyarat: PHP, Node/npm, dependency project, dan browser Playwright tersedia.

    npm run test:e2e

Perintah tersebut membuat runtime temporary unik, memverifikasi isolasi SQLite, menyalin aplikasi tanpa .env dan storage development, menjalankan migration hanya pada SQLite temporary, memulai server localhost, menunggu /up sehat, menjalankan Playwright desktop/mobile, lalu menghentikan server dan membersihkan run tersebut.

Untuk mempertahankan artefak run yang sedang diperiksa:

    $env:E2E_KEEP_ARTIFACTS='1'
    npm run test:e2e
    Remove-Item Env:E2E_KEEP_ARTIFACTS

Penghapusan direktori temporary lama harus dilakukan manual setelah inspeksi dan persetujuan; tidak dilakukan pada tahap ini.

## 8. Fixture akun dan batas smoke test

Smoke test hanya menguji katalog publik. Belum ada akun atau fixture role yang dibuat. Tahap berikutnya membutuhkan fixture sintetis untuk:

- pelanggan;
- pemilik UMKM toko A dan toko B;
- Admin BUMDes A dan B untuk aturan dua aktor;
- Kepala BUMDes;
- kurir.

Fixture tidak boleh memakai akun development/production, rekening nyata, bukti transfer asli, atau SMTP nyata. Data order, payment, entitlement, disbursement, dan file proof harus dibuat hanya pada runtime temporary.

## 9. Risiko tersisa

1. Direktori .e2e-tmp/run-* lama masih ada dari run sebelumnya. Isinya belum dihapus karena tahap ini melarang penghapusan tanpa inspeksi/konfirmasi.
2. Smoke test belum membuktikan login, checkout, payment, fulfillment, entitlement, disbursement, private proof, atau seluruh role.
3. SQLite temporary tidak membuktikan concurrency MySQL/InnoDB.
4. Browser version CI perlu dipin dan diverifikasi untuk reproducibility.
5. Dependency package sudah ada pada workspace, tetapi pipeline CI harus memastikan npm ci dan browser provisioning memakai lockfile yang sama.

## 10. Kesimpulan

Infrastruktur Playwright siap untuk smoke test terisolasi. Dua project Chromium lulus, screenshot dan HTML report tersedia, dan migration hanya berjalan pada SQLite temporary yang diverifikasi fail-closed.

Belum boleh melanjutkan ke browser business scenarios sebelum fixture role dan isolasi tambahan disetujui. Tidak ada MySQL development, .env, transaksi finansial, akun pengguna, controller, atau logika bisnis yang diubah.
