# UI/UX Tahap 5C — Responsive Browser Testing

Tanggal pengujian: 2026-11-02
Project: E-Commerce BUMDes Desa Buruan

## Ringkasan

Pengujian dijalankan melalui Playwright pada tiga viewport. Setelah perbaikan Tahap 5C.1:

- Desktop: 1366×768
- Tablet: 768×1024
- Mobile: 390×844

Suite yang tersedia saat ini mencakup smoke test dan audit publik/guest. Infrastruktur role-specific belum memiliki bootstrap fixture akun sintetis untuk browser. Karena itu, pengujian lima role tidak boleh diklaim selesai.

## Keamanan environment

Status: PASS untuk isolasi browser test.

- Script `scripts/e2e/run-isolated.mjs` membuat database SQLite file sementara dengan nama unik di `.e2e-tmp/run-*/database/e2e.sqlite`.
- Script menolak koneksi non-SQLite, database `:memory:`, path di luar direktori temporary, dan nama `ecommerce_bumdes_restore`.
- Migrasi selama run hanya diarahkan ke SQLite temporary tersebut.
- Session, cache, queue, mail, dan storage menggunakan konfigurasi sementara.
- `.env` development tidak diubah.
- Database MySQL `ecommerce_bumdes_restore` tidak diakses atau diubah.
- Artefak `.e2e-tmp` lama diperiksa secara read-only dan tidak dihapus.

Playwright yang digunakan: 1.64.1. Konfigurasi sudah memiliki project Chromium desktop, tablet, dan mobile serta screenshot/trace saat gagal.

## Hasil automated browser test

| Viewport | Hasil |
|---|---:|
| Desktop 1366×768 | 5 passed |
| Tablet 768×1024 | 5 passed |
| Mobile 390×844 | 5 passed |
| **Total** | **15 passed, 0 failed** |

Skenario yang lulus:

- Katalog publik kosong dapat dibuka.
- Form login dan registrasi dapat dibuka.
- Login invalid tetap berada pada alur validasi dan tidak membuat data.
- Guest yang membuka halaman bisnis terlindungi diarahkan ke login.
- Smoke test katalog publik.

Kegagalan sebelumnya pada mobile telah diperbaiki. Penyebabnya adalah link `Katalog` menggunakan class `hidden sm:inline`, sementara tidak ada hamburger menu sebagai jalur alternatif. Link tersebut sekarang ditampilkan pada semua viewport.

## Perbaikan 5C.1

File `resources/views/components/catalog-layout.blade.php` diubah secara minimal: link `Katalog` tidak lagi disembunyikan pada viewport di bawah `sm`. Locator Playwright tetap sama karena kini mencerminkan elemen yang benar-benar tersedia bagi pengguna mobile.

## Cakupan lima role

| Role | Login/dashboard | Status |
|---|---|---|
| `admin_bumdes` | Belum diuji melalui browser | BLOCKED |
| `pemilik_umkm` | Belum diuji melalui browser | BLOCKED |
| `pelanggan` | Hanya alur guest/public diuji; login role belum diuji | BLOCKED |
| `kepala_bumdes` | Belum diuji melalui browser | BLOCKED |
| `kurir` | Belum diuji melalui browser | BLOCKED |

Hambatan: belum tersedia fixture/bootstrap akun sintetis lima role untuk database E2E temporary. Akun development tidak digunakan karena dilarang oleh scope keamanan.

Dengan demikian, hal berikut masih NOT TESTED/BLOCKED:

- Dashboard dan sidebar tiap role.
- Active navigation role-specific.
- Tabel, filter, form, modal, pagination, dan empty state internal.
- Checkout dan fulfillment melalui browser.
- Laporan finansial dan export CSV melalui browser.
- Interaksi sentuh dan pemeriksaan overflow pada seluruh halaman role.

## Artefak

Screenshot yang tersedia di `tests/e2e/screenshots/` untuk katalog, login, dan registrasi pada desktop, tablet, dan mobile. HTML report tersedia di `test-results/playwright-html/index.html`; artefak error dan trace tersedia di `test-results/playwright-artifacts/`.

Screenshot yang tersedia tidak dianggap sebagai bukti pengujian dashboard role karena halaman tersebut belum dibuka dengan akun sintetis.

## Perubahan file dan rekomendasi

Pada tahap 5C.1, hanya komponen navbar marketplace dan dua dokumen audit yang berubah. Tidak ada perubahan backend, route, migration, `.env`, data development, atau akun pengguna.

Langkah berikutnya:

1. Tambahkan fixture/bootstrap akun sintetis untuk lima role hanya pada database E2E temporary.
2. Tambahkan data katalog dan data transaksi sintetis yang aman.
3. Perbaiki atau sesuaikan assertion mobile untuk menu navigasi setelah struktur UI diverifikasi.
4. Jalankan ulang seluruh role dan viewport sebelum menyatakan responsive testing selesai.
