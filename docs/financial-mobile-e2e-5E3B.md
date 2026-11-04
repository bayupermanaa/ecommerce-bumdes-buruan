# Tahap 5E.3B — Mobile Financial E2E

Tanggal: 2026-11-02

## 1. Resource audit

Konfigurasi awal sudah menggunakan:

- `fullyParallel: false`
- `workers: 1`
- Chromium headless
- `video: off`
- screenshot hanya saat gagal
- trace hanya saat gagal
- timeout 30 detik

Ditemukan beberapa proses `chrome` dan `php` lama. Command line proses tidak dapat dibaca karena akses Windows ditolak, sehingga proses tersebut tidak dihentikan agar tidak mengganggu aplikasi lain.

## 2. Optimasi yang diterapkan

`playwright.config.js` sekarang mendukung `E2E_LIGHT=1`:

- reporter hanya `list`
- screenshot dimatikan
- trace dimatikan
- worker tetap satu

Runner E2E juga mendukung `E2E_PROJECT`, sehingga pengujian mobile dapat dijalankan terpisah:

```powershell
$env:E2E_GREP='synthetic pickup checkout'
$env:E2E_PROJECT='chromium-mobile'
$env:E2E_LIGHT='1'
npm run test:e2e
```

Database yang dipakai setiap run adalah file SQLite unik di `.e2e-tmp/run-*/database/e2e.sqlite`. Runner menolak koneksi ke `ecommerce_bumdes_restore`.

## 3. Hasil mobile pada audit awal

Status audit awal: **BLOCKED / FAIL**.

Mode ringan berhasil menghindari error virtual memory sebelumnya. Namun test mobile tetap gagal pada target cart:

```text
Tombol cart tidak dapat ditempatkan sebagai target klik pada viewport.
```

Beberapa pendekatan scroll normal dan hit-testing (`scrollIntoView`, wheel scroll, dan `window.scrollTo`) tetap tidak memperoleh `BUTTON` sebagai elemen pada titik klik. Tidak digunakan `force click` atau DOM `.click()`.

Karena tombol belum berhasil diklik secara normal pada mobile, checkout, pembayaran, dan konfirmasi Admin pada viewport mobile belum terbukti.

## 4. Status setelah Tahap 5E.3C

Root cause kemudian terbukti sebagai mismatch viewport preset `iPhone 13` dengan viewport CSS. Setelah mobile project memakai Chromium dengan viewport eksplisit `390x844`, workflow mobile lulus **3/3 run serial**. Rincian dan bukti final tersedia di `docs/mobile-cart-hit-test-fix-5E3C.md`.

## 5. Regression yang tersedia

- Desktop financial workflow: PASS pada run sebelumnya.
- Tablet financial workflow: PASS pada run sebelumnya.
- Mobile financial workflow pada audit awal: BLOCKED/FAIL; setelah 5E.3C: PASS 3/3.
- `npm run build`: PASS sebelum rerun mobile.
- SQLite E2E isolation: PASS.
- Migration hanya dijalankan pada SQLite temporary E2E.

Focused cart test, payment PHPUnit, private-storage security test, dan full regression belum dijalankan pada tahap ini.

## 6. File berubah

- `playwright.config.js`
- `scripts/e2e/run-isolated.mjs`
- `tests/e2e/financial-workflow.spec.js`
- `resources/views/catalog/index.blade.php`
- `resources/views/catalog/show.blade.php`
- `docs/financial-mobile-e2e-5E3B.md`

Tidak ada perubahan pada `.env` development, database development, service finansial, migration source, atau data transaksi development.

## 7. Masalah tersisa

1. Investigasi layout mobile masih diperlukan dengan browser inspection visual pada detail produk; hit-test menunjukkan target tidak stabil/tertutup pada viewport mobile.
2. Proses PHP/Chrome lama perlu ditutup oleh administrator setelah kepemilikannya dikonfirmasi, atau mesin E2E perlu direstart.
3. Setelah tombol mobile diperbaiki, ulangi checkout, pembayaran sintetis, dan konfirmasi Admin secara serial.
4. Jangan lanjut ke entitlement dan pencairan browser E2E sebelum mobile payment workflow PASS.
