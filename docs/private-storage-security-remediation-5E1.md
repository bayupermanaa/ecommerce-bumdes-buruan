# Tahap 5E.1 — Private Storage Security Remediation

Tanggal: 2026-11-02

## Root cause

Disk `local` menunjuk ke `storage/app/private` tetapi sebelumnya memiliki konfigurasi `serve => true`. Laravel kemudian mendaftarkan route otomatis `storage/{path}` tanpa middleware auth/role. Kondisi ini berpotensi menyajikan bukti pembayaran dan pencairan apabila path file diketahui.

Produk tidak menggunakan disk tersebut. Upload produk menggunakan disk `public`, sedangkan bukti finansial menggunakan disk `local` dan endpoint controller yang telah memiliki policy.

## Perbaikan

File yang diubah:

- `config/filesystems.php`

Perubahan:

```php
'local' => [
    'root' => storage_path('app/private'),
    'serve' => false,
],
```

Dengan `serve=false`, route otomatis `storage.local` dan `storage.local.upload` tidak lagi terdaftar. File bukti tidak dihapus. Endpoint resmi berikut tetap menjadi jalur akses yang terotorisasi:

- Bukti pembayaran Admin BUMDes.
- Bukti transfer pencairan Admin BUMDes.
- Bukti kegagalan transfer Admin BUMDes.

Controller memeriksa policy dan keberadaan file sebelum melakukan streaming. Disk `public` tetap digunakan untuk foto produk dan symbolic link publik tidak diubah.

## Security test baru

File:

- `tests/Feature/PrivateStorageSecurityTest.php`

Skenario:

1. Disk local private tidak auto-served.
2. Route `storage.local` dan `storage.local.upload` tidak terdaftar.
3. Path private yang diketahui melalui `/storage/...` menghasilkan 404.
4. Disk public tetap tersedia untuk foto produk.

Hasil focused test:

```text
3 passed, 7 assertions
```

Test existing juga mencakup authorization private proof Admin dan role lain, validasi upload, private storage proof, serta public product image melalui `AdminPaymentVerificationTest`, `UmkmDisbursementApprovalTest`, `UmkmDisbursementFailureEvidenceTest`, dan `CatalogTest`.

## Regression

Focused:

- Private storage: 3 passed, 7 assertions.
- Payment confirmation: 11 passed, 45 assertions.
- Disbursement group: 12 passed, 91 assertions.

Full suite setelah perubahan:

```text
Tests:    272 passed (2128 assertions)
Failed:   0
Duration: 24.19s
```

Validasi tambahan:

- PHP syntax check config dan test: lulus.
- `php artisan view:cache`: lulus.
- Route listing: tidak ada route `storage.local` atau `storage.local.upload`.
- MySQL development tidak diakses.
- Tidak ada migration, seeder, atau perubahan data development.
- `.env` tidak diubah.

## Risiko tersisa

- Endpoint controller `UmkmDisbursementReviewController::proof` dan `failureProof` masih perlu dipertahankan dengan policy dan validasi path yang ketat pada perubahan berikutnya; aksesnya saat ini tetap berada di bawah role Admin.
- Pengujian concurrency MySQL/InnoDB belum dibuktikan oleh SQLite.
- Deployment production harus memastikan `public/storage` hanya mengarah ke `storage/app/public`, bukan `storage/app/private`.
- Browser E2E authenticated lima role masih memerlukan fixture akun sintetis.

## Status

Auto-serving private storage telah dinonaktifkan dan seluruh regression test lulus. Risiko publik yang teridentifikasi pada audit sebelumnya sudah ditutup pada level konfigurasi aplikasi. Deployment tetap memerlukan verifikasi filesystem/web-server bahwa private directory tidak berada di document root.
