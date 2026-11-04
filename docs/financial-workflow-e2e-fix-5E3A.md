# Tahap 5E.3A — Perbaikan Add to Cart dan Financial E2E

Tanggal: 2026-11-02

## Ringkasan

Perbaikan dilakukan pada tombol `Tambah ke Keranjang` di katalog dan detail produk. Pengujian browser memakai runner E2E terisolasi dengan SQLite file sementara; database `ecommerce_bumdes_restore` tidak digunakan.

## Root cause

Investigasi Playwright menunjukkan dua penyebab yang berbeda:

1. Asset Vite yang disalin ke environment E2E masih versi lama, sehingga class stacking context dari Blade belum ada di browser. `npm run build` memperbarui asset.
2. Pada mobile, posisi tombol berubah ketika scroll/layout selesai sehingga koordinat awal test menjadi stale. Setelah sinkronisasi font/gambar dan scroll manual, hit-test desktop/tablet mengenai `BUTTON`. Pada rerun terakhir mobile terhenti oleh keterbatasan memori Windows sebelum validasi selesai.

Tidak digunakan `force click`.

## Perubahan

- `resources/views/catalog/index.blade.php`: form cart diberi stacking context dan pointer-events eksplisit.
- `resources/views/catalog/show.blade.php`: form dan tombol cart diberi stacking context terisolasi, z-index, dan pointer-events eksplisit.
- `tests/e2e/financial-workflow.spec.js`: menunggu asset/layout stabil, memilih rekening dengan locator exact, dan mencari pembayaran berdasarkan nomor pesanan aktual.
- `scripts/e2e/run-isolated.mjs`: mendukung `E2E_GREP` dan `E2E_PROJECT` untuk focused E2E.
- `scripts/e2e/seed-authenticated-fixtures.php`: menambahkan rekening BUMDes sintetis untuk fixture.
- `public/build/*`: dibangun ulang melalui Vite.

Tidak ada perubahan controller, service pembayaran, database development, migration, atau `.env` development.

## Hasil pengujian

### PASS

- Desktop: checkout pickup, pembuatan pembayaran transfer sintetis, upload PNG sintetis, login Admin, dan konfirmasi pembayaran berhasil pada focused rerun.
- Tablet: alur yang sama berhasil.
- Hit-test desktop dan tablet menemukan tombol `BUTTON`, bukan elemen overlay.
- Isolasi database diverifikasi pada path `.e2e-tmp/run-*/database/e2e.sqlite`.
- `npm run build` berhasil.
- `php -l scripts/e2e/seed-authenticated-fixtures.php` berhasil.
- `node --check tests/e2e/financial-workflow.spec.js` dan `scripts/e2e/run-isolated.mjs` berhasil.

### BLOCKED / BELUM SELESAI

- Mobile 390x844: masih gagal pada klik normal tombol cart dalam focused run pertama. Rerun setelah perubahan tambahan berhenti sebelum Playwright akibat PHP/Chromium kehabisan virtual memory Windows (`VirtualAlloc`, paging file terlalu kecil). Karena itu hasil mobile belum dapat dinyatakan PASS.
- Entitlement, rekonsiliasi, pengajuan pencairan, approval, transfer, transfer gagal, dan cancellation belum terbukti melalui browser pada tahap ini. Tidak ada klaim PASS untuk alur tersebut.
- Regression PHPUnit, full E2E seluruh suite, private-storage security, dan responsive regression belum dijalankan pada tahap ini.

## Screenshot dan artefak

Playwright membuat screenshot dan trace kegagalan pada `test-results/playwright-artifacts/` selama focused run. Artefak tersebut berasal dari environment sintetis; tidak ada bukti transfer nyata atau data development yang dipakai.

## Risiko tersisa dan rekomendasi

1. Naikkan paging file/virtual memory Windows atau tutup proses berat sebelum mengulang mobile E2E.
2. Ulangi focused mobile setelah environment stabil, lalu jalankan seluruh tiga viewport.
3. Tambahkan fixture commission agreement dan fixture entitlement/disbursement yang aman untuk melanjutkan financial workflow berikutnya.
4. Jalankan focused cart/payment/security tests dan PHPUnit regression suite setelah browser validation selesai.

## Kesimpulan

Perbaikan cart terbukti bekerja pada desktop dan tablet, tetapi Tahap 5E.3A belum dapat dinyatakan selesai karena mobile dan bagian lifecycle keuangan lanjutan belum tervalidasi penuh.
