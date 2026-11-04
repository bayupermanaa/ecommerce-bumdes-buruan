# Authenticated Browser E2E — Tahap 5E.2

Tanggal pengujian: 2 November 2026

## Ringkasan

Pengujian Playwright dijalankan terhadap salinan aplikasi dengan database SQLite sementara dan akun sintetis. Hasil akhir:

- 36 passed
- 0 failed
- 0 blocked pada suite yang dijalankan
- Tiga viewport: desktop, tablet, mobile
- Durasi: sekitar 1,5 menit

Perbaikan yang dilakukan selama pengujian hanya pada locator/alur test logout. Tidak ada perubahan pada layout produksi, controller, service, route, atau database development.

## Bukti isolasi

Runner membuat direktori unik `.e2e-tmp/run-<id>/` dan database SQLite file di dalamnya. Eksekusi terakhir menggunakan:

`\.e2e-tmp\run-zDTeby\database\e2e.sqlite`

Migration dan fixture hanya dijalankan pada database tersebut. Runner menolak target `ecommerce_bumdes_restore`; tidak ada koneksi atau operasi ke database development. Mail, queue, session, cache, storage, dan log juga diarahkan ke lingkungan sementara E2E.

Fixture sintetis berisi lima akun role, satu toko UMKM approved, satu kategori, dan satu produk approved. Tidak ada transaksi finansial, rekening nyata, bukti transfer, atau data pengguna development yang disalin.

## Cakupan test

| Area | Cakupan |
|---|---:|
| Lima role terautentikasi × tiga viewport | 15 |
| Guest dan pembatasan dashboard lintas role × tiga viewport | 3 |
| Logout pelanggan × tiga viewport | 3 |
| Audit halaman publik × tiga viewport | 12 |
| Smoke test × tiga viewport | 3 |
| Total | **36** |

Viewport yang digunakan:

- Desktop: 1366×768
- Tablet: 768×1024
- Mobile: 390×844

## Hasil per role

| Role | Hasil | Halaman yang diuji |
|---|---|---|
| Admin BUMDes | PASS | Login, dashboard, toko UMKM, produk, pembayaran, pencairan, audit keuangan |
| Pemilik UMKM | PASS | Login, dashboard, toko, produk, pesanan, perjanjian komisi, pencairan |
| Pelanggan | PASS | Login, dashboard, katalog, detail produk, keranjang, checkout, pesanan |
| Kepala BUMDes | PASS | Login, dashboard, laporan komisi, hak UMKM, pencairan |
| Kurir | PASS | Login, dashboard placeholder |

Kurir menampilkan placeholder bahwa fitur pengiriman belum tersedia dan tidak menampilkan tautan delivery/pengiriman palsu.

## Keamanan yang diuji

- Guest diarahkan ke login saat membuka dashboard role.
- Admin tidak dapat membuka dashboard role lain.
- Logout mengakhiri session pelanggan pada semua viewport.
- Navigasi halaman role dapat dibuka dengan akun sintetis yang sesuai.
- Tidak ada transaksi atau email eksternal.

Pengujian browser private proof tidak termasuk suite ini; perlindungan endpoint tersebut tetap menjadi tanggung jawab regression test keamanan aplikasi yang terpisah.

## Screenshot

Screenshot dashboard berhasil dibuat untuk seluruh role dan viewport:

- [Admin desktop](screenshots/admin/chromium-desktop-dashboard.png), [tablet](screenshots/admin/chromium-tablet-dashboard.png), [mobile](screenshots/admin/chromium-mobile-dashboard.png)
- [UMKM desktop](screenshots/umkm/chromium-desktop-dashboard.png), [tablet](screenshots/umkm/chromium-tablet-dashboard.png), [mobile](screenshots/umkm/chromium-mobile-dashboard.png)
- [Pelanggan desktop](screenshots/customer/chromium-desktop-dashboard.png), [tablet](screenshots/customer/chromium-tablet-dashboard.png), [mobile](screenshots/customer/chromium-mobile-dashboard.png)
- [Kepala BUMDes desktop](screenshots/kepala-bumdes/chromium-desktop-dashboard.png), [tablet](screenshots/kepala-bumdes/chromium-tablet-dashboard.png), [mobile](screenshots/kepala-bumdes/chromium-mobile-dashboard.png)
- [Kurir desktop](screenshots/kurir/chromium-desktop-dashboard.png), [tablet](screenshots/kurir/chromium-tablet-dashboard.png), [mobile](screenshots/kurir/chromium-mobile-dashboard.png)

## Belum diuji atau diblokir

Hal-hal berikut tidak diklaim berhasil karena memerlukan transaksi atau infrastruktur yang tidak boleh dibuat pada tahap ini:

- Registrasi pelanggan sampai penyimpanan akun baru.
- Checkout dan pembayaran nyata/simulasi penuh.
- Konfirmasi pembayaran Admin.
- Fulfillment sampai selesai.
- Pembentukan dan rekonsiliasi entitlement.
- Pengajuan, approval, transfer, transfer gagal, dan pembatalan pencairan.
- Verifikasi private proof melalui browser.
- Integrasi payment gateway, transfer bank, email, dan notifikasi eksternal.

Skenario tersebut memerlukan fixture finansial khusus dan/atau pengujian terkontrol lanjutan pada lingkungan E2E yang tetap terisolasi.

## File terkait

- `scripts/e2e/run-isolated.mjs` — bootstrap aplikasi, database, storage, dan server terisolasi.
- `scripts/e2e/seed-authenticated-fixtures.php` — fixture lima role dan katalog sintetis.
- `tests/e2e/authenticated-roles.spec.js` — test login, akses role, security, logout, dan screenshot.
- `docs/screenshots/` — artefak screenshot dashboard.

Database development `ecommerce_bumdes_restore` tidak diubah. Tidak ada migration, seeder, atau perubahan `.env` development yang dijalankan.
