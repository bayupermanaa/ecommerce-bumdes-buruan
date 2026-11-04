# UI/UX Tahap 5C.1 — Mobile Responsive Test Fix

Tanggal: 2026-11-02

## Diagnosis

Dua test mobile sebelumnya gagal karena locator `getByRole('link', { name: 'Katalog' })` tidak menemukan link pada viewport 390×844. Pemeriksaan markup menunjukkan penyebabnya adalah:

```html
class="hidden text-slate-600 ... sm:inline"
```

Navbar tidak menyediakan hamburger menu. Jadi link Katalog memang tidak dapat diakses melalui navbar mobile; ini adalah bug UI, bukan kesalahan locator.

## Perbaikan

File yang diubah:

- `resources/views/components/catalog-layout.blade.php`

Perubahan hanya menghapus class `hidden sm:inline` dan menjadikan link Katalog selalu tampil. Route, URL, backend, autentikasi, checkout, pembayaran, dan database tidak diubah.

## Validasi browser

Command yang dijalankan:

```text
npm run test:e2e
```

Environment dijalankan melalui `scripts/e2e/run-isolated.mjs` dengan SQLite file sementara:

```text
.e2e-tmp/run-vFX55n/database/e2e.sqlite
```

Hasil:

- Desktop 1366×768: 5 passed
- Tablet 768×1024: 5 passed
- Mobile 390×844: 5 passed
- Total: **15 passed, 0 failed**
- Durasi suite: 24,3 detik

Screenshot katalog, login, dan registrasi dibuat untuk ketiga viewport di `tests/e2e/screenshots/`. HTML report tersedia di `test-results/playwright-html/index.html`.

## Cakupan yang belum diuji

Dashboard dan login authenticated untuk role `admin_bumdes`, `pemilik_umkm`, `pelanggan`, `kepala_bumdes`, dan `kurir` tetap BLOCKED karena fixture akun sintetis browser belum tersedia. Test ini tidak menggunakan akun atau database development.

Dengan demikian, hasil 15/15 hanya berlaku untuk smoke/public/guest flow yang tersedia, bukan bukti seluruh dashboard role atau alur finansial browser.

## Keamanan

- SQLite temporary digunakan; MySQL `ecommerce_bumdes_restore` tidak diakses.
- Tidak ada migration atau seeder pada database development.
- `.env` development tidak diubah.
- Tidak ada transaksi finansial nyata.
- Tidak ada perubahan backend atau route.
