# Full Browser End-to-End Audit — Tahap 9E.5D

Tanggal: 2 November 2026
Project: E-Commerce BUMDes Desa Buruan

## 1. Ringkasan hasil

Audit ini menjalankan browser hanya terhadap runtime E2E terisolasi dari scripts/e2e/run-isolated.mjs.

Bukti isolasi:

- DB_CONNECTION dipaksa dan diverifikasi sebagai sqlite.
- DB_DATABASE berupa file unik di bawah .e2e-tmp/run-<random>/database/e2e.sqlite.
- Path database development ecommerce_bumdes_restore ditolak oleh runner.
- Migration dijalankan hanya setelah pemeriksaan tersebut dan hanya ke file SQLite temporary.
- Aplikasi disalin ke runtime temporary tanpa .env, storage development, dan node_modules.
- Server hanya listen pada 127.0.0.1 dengan port temporary.
- Queue sync, mail array, cache array, session file, serta private/public storage berada di runtime temporary.
- Run selesai dengan cleanup runtime aktif; direktori .e2e-tmp lama tidak dihapus.

### Angka pengujian browser

| Kategori | Hasil |
| --- | ---: |
| Test instance yang dijalankan | 15 |
| PASS | 15 |
| FAIL final | 0 |
| BLOCKED | Alur login role, bisnis, dan finansial yang membutuhkan fixture akun/data |
| NOT TESTED | Interaksi authenticated dan financial end-to-end |
| Viewport | Desktop 1366x768, tablet 768x1024, mobile 390x844 |
| Durasi final | 20,0 detik |

Angka 15 berasal dari 5 test aman yang dijalankan pada 3 project Chromium. Ini belum berarti seluruh aplikasi telah lulus browser E2E. Cakupan yang memerlukan akun dan data transaksi diberi BLOCKED/NOT TESTED di bawah.

## 2. Perubahan test dan konfigurasi

File yang diubah/dibuat pada tahap ini:

- playwright.config.js — menambahkan viewport tablet dan menetapkan ukuran desktop/mobile sesuai permintaan.
- tests/e2e/public-audit.spec.js — test browser aman untuk katalog, form auth guest, invalid login, dan redirect guest.
- docs/full-browser-e2e-audit-9E-5D.md — laporan ini.
- Screenshot dan HTML report diperbarui oleh test runner.

Tidak ada controller, model, service finansial, migration, .env, package production, atau database development yang diubah.

Kesalahan pertama pada harness menggunakan URL /umkm/pesanan yang tidak ada. Route aktual adalah /umkm/orders. Test harness diperbaiki hanya pada tests/e2e/public-audit.spec.js, lalu dijalankan ulang dan seluruh 15 test lulus. Ini bukan bug produksi.

## 3. PASS — skenario browser yang benar-benar diuji

| Modul | Role | URL | Skenario dan hasil aktual | Expected | Screenshot | Prioritas |
| --- | --- | --- | --- | --- | --- | --- |
| Katalog publik kosong | Guest | /, /katalog | Halaman dapat dibuka; root mengarah ke /katalog; link Katalog terlihat; tidak ada nama database development di HTML. | Katalog publik aman dirender dan empty state dapat diakses. | chromium-*-catalog-empty.png, chromium-*-catalog.png | Tinggi |
| Form login/registrasi | Guest | /login, /register | Label Email dan Password tersedia pada desktop/tablet/mobile; screenshot tersimpan. | Form dapat ditemukan dan responsif. | chromium-*-login.png, chromium-*-register.png | Tinggi |
| Login invalid | Guest | /login | Kredensial sintetis invalid dikirim; browser tetap berada pada flow login; tidak membuat akun/transaksi. | Validasi/auth failure tidak login dan tidak mutasi data. | Trace tidak diperlukan karena lulus | Tinggi |
| Unauthorized guest navigation | Guest | /checkout, /pesanan, /admin/dashboard, /umkm/orders, /kepala-bumdes/dashboard | Semua URL diarahkan ke /login. | Guest tidak dapat membuka halaman terlindungi. | Tidak diperlukan | Kritis |
| Responsive public UI | Guest | /katalog, /login, /register | Skenario di atas lulus pada 1366x768, 768x1024, dan 390x844. | Tidak ada kegagalan navigasi pada tiga viewport yang diuji. | 12 screenshot public | Tinggi |

Tidak ada payment, order, file upload, atau data finansial dibuat pada PASS set ini.

## 4. AUTHENTICATION DAN ROLE — BLOCKED/NOT TESTED

| Modul | Role | URL | Hasil aktual | Expected | Status | Prioritas | Rekomendasi |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Login valid dan dashboard redirect | pelanggan, pemilik_umkm, admin_bumdes, kepala_bumdes, kurir | /login | Belum diuji karena fixture akun E2E belum dibuat. | Login berhasil dan redirect ke dashboard role yang tepat. | BLOCKED | Kritis | Siapkan fixture lima role pada SQLite file temporary. |
| Logout/session expiry | Semua role | /logout, route protected | Belum diuji melalui browser. | Sesi berakhir dan URL protected kembali ke login. | NOT TESTED | Tinggi | Tambahkan browser context per role dan uji logout/back navigation. |
| Akses lintas role | Semua role | dashboard dan route modul | Belum diuji dengan sesi terautentikasi. | Role salah menerima 403; tidak ada IDOR. | BLOCKED | Kritis | Siapkan matriks role dan URL dari route:list. |
| Validasi registrasi/password | Guest | /register, /forgot-password | Invalid login diuji; registrasi/password reset penuh belum diuji. | Validasi jelas tanpa email nyata. | NOT TESTED | Sedang | Gunakan mail sink/array dan email fixture. |

## 5. BUSINESS FLOW — BLOCKED/NOT TESTED

| Modul | Role | URL | Hasil aktual | Expected | Status | Prioritas | Rekomendasi |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Detail, pencarian, filter produk | Guest/pelanggan | /katalog/{slug}, /katalog | Katalog kosong; tidak ada fixture produk untuk detail/filter. | Produk approved tampil, non-approved tidak tampil, filter/pagination benar. | BLOCKED | Tinggi | Fixture kategori, toko, produk approved/non-approved. |
| Keranjang | pelanggan | /keranjang | Belum diuji karena memerlukan sesi pelanggan dan produk. | Tambah/ubah/hapus/empty state benar. | BLOCKED | Tinggi | Fixture pelanggan + produk dan cek stock validation. |
| Checkout pickup | pelanggan | /checkout | Belum diuji karena memerlukan cart/order fixture. | Pickup menampilkan form sesuai state, subtotal/snapshot benar, tidak ada delivery fee dummy. | BLOCKED | Kritis | Jalankan dengan fixture single-store dan multi-store. |
| Riwayat/detail order | pelanggan | /pesanan | Belum diuji dengan order fixture. | Pemilik hanya melihat order sendiri dan status multi-toko akurat. | BLOCKED | Tinggi | Fixture order paid/unpaid/completed/cancelled. |
| Toko dan produk UMKM | pemilik_umkm | /umkm/stores, /umkm/products | Belum diuji dengan akun pemilik/toko. | CRUD hanya toko sendiri dan validasi status sesuai. | BLOCKED | Tinggi | Fixture dua toko untuk IDOR. |
| Product verification/revision | admin_bumdes/pemilik_umkm | /admin/products, /admin/product-revisions | Belum diuji melalui browser. | Approved product tetap aman sampai revision disetujui. | BLOCKED | Tinggi | Fixture pending/approved/rejected/revision. |
| Pesanan dan fulfillment | pemilik_umkm | /umkm/orders | Belum diuji dengan StoreOrder paid. | Status hanya maju berurutan; toko lain tidak terlihat. | BLOCKED | Kritis | Fixture multi-store paid dan uji tiga action. |

## 6. FINANCIAL FLOW — BLOCKED/NOT TESTED

| Modul | Role | URL | Hasil aktual | Expected | Status | Prioritas | Rekomendasi |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Pengajuan payment | pelanggan | /pesanan/{order}/pembayaran | Belum diuji melalui browser. | Transfer/tunai sintetis tervalidasi tanpa gateway/email nyata. | BLOCKED | Kritis | Gunakan order fixture dan file dummy. |
| Verifikasi payment | admin_bumdes | /admin/pembayaran | Belum diuji melalui browser. | Payment paid sekali; fulfillment diaktifkan atomik. | BLOCKED | Kritis | Fixture payment pending dan Admin test. |
| Snapshot komisi | pelanggan/admin/kepala | checkout/payment/laporan | Belum diuji browser. | Nilai snapshot DECIMAL tidak dihitung ulang. | BLOCKED | Kritis | Fixture agreement + perubahan agreement historis. |
| Entitlement/reconciliation | admin_bumdes/kepala | /admin/umkm-entitlements, laporan | Belum diuji browser. | Accrued/eligible/available/held/null amount tepat. | BLOCKED | Kritis | Fixture semua status dan Admin reconcile. |
| Pengajuan/approval pencairan | pemilik_umkm/admin_bumdes/kepala_bumdes | /umkm/pencairan, /admin/pencairan-umkm, /kepala-bumdes/persetujuan-pencairan | Belum diuji browser. | Pengajuan seluruh saldo, review, approval, allocation/lock sesuai. | BLOCKED | Kritis | Fixture rekening dummy terverifikasi dan entitlement available. |
| Transfer dua Admin | admin_bumdes | /admin/pencairan-umkm | Belum diuji browser. | Bukti private; recorder tidak dapat verify sendiri; Admin kedua dapat verify. | BLOCKED | Kritis | Dua Admin fixture dan Storage temporary. |
| Transfer gagal/cancel | pemilik_umkm/admin_bumdes | pencairan route | Belum diuji browser. | Reservation release idempoten; allocation historis tetap. | BLOCKED | Kritis | Fixture disbursement requested/approved + proof dummy. |
| Audit detector | admin_bumdes | /admin/audit-keuangan | Belum diuji browser. | 11 kategori, filter, limit, context privat, read-only. | BLOCKED | Tinggi | Fixture exception sintetis dan cek HTML. |
| Laporan/export | kepala_bumdes | /kepala-bumdes/laporan-* dan /export | Belum diuji browser. | Snapshot, DECIMAL, NULL amount, paid-only, CSV privacy benar. | BLOCKED | Kritis | Fixture financial lengkap dan inspeksi download. |

## 7. RESPONSIVE UI

PASS terbatas pada katalog, login, registrasi, invalid login, dan guest redirect pada:

- Desktop: 1366x768.
- Tablet: 768x1024.
- Mobile: 390x844.

Dashboard authenticated, checkout, tabel UMKM/Admin, laporan finansial, modal, dropdown, pagination populated, upload form, dan overflow tabel belum diuji karena fixture role/data belum tersedia. Status: **NOT TESTED**, prioritas tinggi.

## 8. Screenshot, trace, dan report

Screenshot public yang tersedia:

- tests/e2e/screenshots/chromium-desktop-catalog-empty.png
- tests/e2e/screenshots/chromium-tablet-catalog-empty.png
- tests/e2e/screenshots/chromium-mobile-catalog-empty.png
- tests/e2e/screenshots/chromium-desktop-login.png
- tests/e2e/screenshots/chromium-tablet-login.png
- tests/e2e/screenshots/chromium-mobile-login.png
- tests/e2e/screenshots/chromium-desktop-register.png
- tests/e2e/screenshots/chromium-tablet-register.png
- tests/e2e/screenshots/chromium-mobile-register.png

Screenshot katalog root dari smoke test juga tersedia dengan suffix catalog.png. HTML report tersedia di test-results/playwright-html/index.html.

Percobaan pertama menghasilkan failure pada test harness URL yang salah. Playwright otomatis menyimpan screenshot/trace failure pada test-results/playwright-artifacts. Setelah URL diperbaiki ke /umkm/orders, rerun final lulus 15/15. Failure awal diklasifikasikan sebagai **FAIL test harness sementara**, bukan bug produksi, dan tidak ikut hasil final.

## 9. Checkpoint lanjutan

Checkpoint aman untuk melanjutkan tanpa mengulang 15 test yang sudah lulus:

1. Pertahankan runner isolated dan base URL fail-closed.
2. Tambahkan fixture role/data ke SQLite file temporary, bukan database development.
3. Tambahkan smoke login satu role dahulu.
4. Tambahkan test IDOR/403 lintas role.
5. Tambahkan customer catalog → cart → pickup checkout dengan data sintetis.
6. Tambahkan payment/fulfillment satu order, lalu multi-store.
7. Tambahkan entitlement/reconciliation/disbursement/failure.
8. Tambahkan report/export dan inspeksi file download.
9. Jalankan responsive dashboard/laporan pada tiga viewport.
10. Pertahankan screenshot/trace setiap failure; jangan menghapus .e2e-tmp lama tanpa inspeksi dan persetujuan.

## 10. Kesimpulan

Browser E2E sudah terbukti berjalan aman untuk subset publik/guest: 15 test instance lulus pada tiga viewport dengan SQLite file temporary dan tanpa MySQL development.

Tahap full browser E2E aplikasi **belum selesai secara keseluruhan**. Seluruh skenario authenticated, business transaction, financial flow, role dashboard, dan report/export masih BLOCKED atau NOT TESTED karena fixture akun/data E2E belum dibuat. Tidak ada bug produksi yang dapat disimpulkan dari coverage yang belum dijalankan.

Tidak ada controller/service bisnis, migration, .env, database development, akun development, transfer, email, atau notifikasi nyata yang diubah.
