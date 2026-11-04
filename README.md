# E-Commerce BUMDes Desa Buruan

Aplikasi marketplace dan administrasi keuangan BUMDes Desa Buruan berbasis Laravel 12. Sistem ini menghubungkan pelanggan, pemilik UMKM, Admin BUMDes, Kepala BUMDes, dan kurir.

## Fitur utama

- Katalog produk, keranjang, checkout pickup, pembayaran, dan riwayat pesanan.
- Pengelolaan toko/produk UMKM, verifikasi, revisi, dan fulfillment.
- Snapshot komisi, entitlement hak UMKM, rekonsiliasi, dan pencairan.
- Approval, transfer verification, transfer failure, cancellation, dan audit trail.
- Laporan komisi, hak pendapatan, pencairan, monitoring, export CSV, dan financial exception detector.

## Role aplikasi

- `pelanggan`: katalog, checkout, pembayaran, dan pesanan milik sendiri.
- `pemilik_umkm`: toko, produk, fulfillment, hak, dan pencairan toko sendiri.
- `admin_bumdes`: verifikasi operasional, pembayaran, rekonsiliasi, review, dan audit.
- `kepala_bumdes`: approval serta monitoring/laporan read-only.
- `kurir`: fitur pengiriman yang tersedia untuk role kurir.

## Kebutuhan sistem

- PHP 8.2+ dan Laravel 12.
- Composer.
- MySQL 8/MariaDB untuk deployment, atau SQLite untuk testing terisolasi.
- Node.js/npm untuk Vite.
- Ekstensi PHP: PDO, `pdo_mysql` untuk MySQL, mbstring, openssl, tokenizer, xml, ctype, json, fileinfo, dan intl.

## Instalasi lokal

```bash
git clone <REPOSITORY_URL>
cd ecommerce-bumdes-buruan
composer install
copy .env.example .env
php artisan key:generate
npm ci
npm run build
```

Jangan menyalin `.env` atau credential dari environment lain. Isi konfigurasi database, session, cache, queue, mail, dan filesystem sesuai environment lokal.

## Database dan migration

Gunakan database MySQL khusus aplikasi untuk deployment dan akun least-privilege. Contoh variabel tersedia di `.env.example`.

Setelah database dan environment siap, operator berwenang dapat menjalankan:

```bash
php artisan migrate --force
```

Seeder demo hanya boleh dijalankan pada environment fixture/testing, bukan production.

## Menjalankan aplikasi

```bash
php artisan serve
npm run dev
```

Document root web server harus menunjuk ke `public/`, bukan root repository. Private financial proof harus tetap pada private disk dan hanya disajikan melalui controller terotorisasi.

## Testing

PHPUnit menggunakan SQLite `:memory:` melalui `phpunit.xml`:

```bash
php artisan test
```

Browser E2E memakai database SQLite file sementara dan storage terisolasi:

```bash
npm run test:e2e
```

Jangan menjalankan test terhadap database development atau production. Fixture E2E dan password test hanya sintetis.

## Build asset

```bash
npm run build
```

`public/build/` adalah output build dan dapat dibuat ulang oleh pipeline deployment.

## Keamanan repository

`.gitignore` melindungi `.env`, SQL dump/archive backup, `storage/app/private`, upload runtime, bukti pembayaran/transfer, log, cache, session, E2E temporary, test results, `vendor`, dan `node_modules`.

Backup harus disimpan di lokasi terpisah dan diverifikasi melalui manifest/checksum. Jangan memasukkan credential, data pelanggan, rekening, atau bukti privat ke repository.

## Dokumentasi

Dokumen audit, deployment, backup, keamanan, dan E2E tersedia pada `docs/`. Status production tetap harus diverifikasi administrator server.

## Lisensi

Lisensi dan kebijakan publikasi proyek ditetapkan oleh pemilik BUMDes Desa Buruan sebelum repository dipublikasikan.
