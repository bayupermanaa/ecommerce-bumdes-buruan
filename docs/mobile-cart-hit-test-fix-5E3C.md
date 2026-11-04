# Tahap 5E.3C — Mobile Add to Cart Root Cause & Fix

Tanggal: 2026-11-02

## Root cause terbukti

Masalah bukan overlay aplikasi. Project Playwright mobile sebelumnya memakai preset `iPhone 13` sekaligus override viewport. Browser melaporkan viewport CSS `472x1020`, bukan `390x844`. Akibat mismatch skala/koordinat, bounding box Playwright dan koordinat `document.elementFromPoint()` tidak menunjuk lokasi yang sama.

Bukti sebelum perbaikan:

- `innerWidth/innerHeight`: `472x1020`.
- Bounding box Playwright: sekitar `y=675`.
- DOM rect tombol: sekitar `y=851`.
- `elementFromPoint()` pada koordinat bounding box mengenai `DIV.flex.flex-col`, bukan tombol.

Setelah konfigurasi diperbaiki, browser melaporkan `390x844` dan hit-test menghasilkan:

```text
BUTTON -> FORM -> DIV.flex.flex-col -> detail card
```

Tidak ada header sticky, bottom navigation, overlay, `pointer-events`, transform, atau overflow yang menutupi tombol.

## Perbaikan

- `playwright.config.js`: project mobile sekarang memakai Chromium headless dengan viewport eksplisit `390x844`, tanpa preset iPhone yang menyebabkan mismatch koordinat.
- `resources/views/catalog/index.blade.php`: form cart memiliki stacking context dan pointer-events eksplisit.
- `resources/views/catalog/show.blade.php`: form dan tombol cart memiliki stacking context terisolasi dengan z-index yang sesuai.
- `tests/e2e/financial-workflow.spec.js`: hit-test aktual dipastikan sebelum klik normal; diagnostik sementara dibersihkan.

Tidak digunakan `force click`, DOM `.click()`, timeout arbitrer sebagai solusi, atau z-index acak.

## Hasil E2E mobile

Focused command dijalankan dengan satu worker dan SQLite temporary:

```powershell
$env:E2E_GREP='synthetic pickup checkout'
$env:E2E_PROJECT='chromium-mobile'
$env:E2E_LIGHT='1'
npm run test:e2e
```

Hasil: **3/3 PASS** secara serial.

Setiap run membuktikan:

- Login pelanggan sintetis.
- Tombol `Tambah ke Keranjang` dapat diklik normal.
- Checkout pickup.
- Upload bukti transfer PNG sintetis.
- Login Admin BUMDes.
- Konfirmasi pembayaran.

## Regression viewport

- Desktop financial workflow: PASS pada validasi sebelumnya.
- Tablet financial workflow: PASS pada validasi sebelumnya.
- Mobile financial workflow: PASS 3 run serial.

## Environment dan keamanan

- Database setiap run adalah SQLite unik di `.e2e-tmp/run-*/database/e2e.sqlite`.
- Migration dan fixture hanya dijalankan pada database temporary tersebut.
- Storage, session, cache, queue, dan mail terisolasi.
- Tidak ada akses ke `ecommerce_bumdes_restore`.
- Tidak ada perubahan `.env` development.
- Tidak ada pembayaran atau transfer nyata.

## Masalah tersisa

Lifecycle entitlement, rekonsiliasi, pengajuan pencairan, approval, transfer, transfer gagal, dan cancellation belum diuji melalui browser pada tahap ini. Tahap berikutnya dapat melanjutkan workflow tersebut setelah fixture finansial sintetis disiapkan.
