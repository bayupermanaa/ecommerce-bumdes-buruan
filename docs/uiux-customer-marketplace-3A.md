# UI/UX Tahap 3A — Customer Marketplace Redesign

## Ringkasan

Pengalaman pelanggan diperbarui menjadi marketplace yang lebih modern dengan navigasi sticky, identitas BUMDes, hero katalog, kartu produk, panel checkout, dan hierarchy transaksi yang lebih jelas. Semua perubahan berada pada Blade/layout pelanggan; backend, database, route, authorization, harga, snapshot, dan alur pembayaran tidak diubah.

## Pemetaan halaman

| Halaman | Route | Hasil |
|---|---|---|
| Homepage | `/` → `catalog.index` | Katalog publik menjadi landing marketplace |
| Katalog | `catalog.index` | Hero, pencarian, kategori, sorting, kartu produk, pagination |
| Detail produk | `catalog.show` | Foto, harga, stok, deskripsi, tambah ke keranjang |
| Keranjang | `cart.index` | Item, kuantitas, stok, subtotal, total, checkout |
| Checkout | `checkout.index` | Identitas, pickup/delivery state existing, ringkasan, validasi frontend |
| Pembayaran | `orders.payments.create` | Transfer bank/tunai pickup, bukti transfer, idempotency key |
| Riwayat pesanan | `orders.index` | Status pembayaran, fulfillment multi-toko, pagination |
| Detail pesanan | `orders.show` | Snapshot toko/item, status, riwayat, pembayaran, pembatalan |
| Profil pelanggan | `profile.edit` | Tetap memakai layout umum karena route dipakai lintas role |
| Dashboard pelanggan | `pelanggan.dashboard` | Hero personal dan shortcut katalog, cart, pesanan |

## Perubahan implementasi

- Memperbarui `resources/views/components/catalog-layout.blade.php` menjadi shell marketplace responsive dengan branding BUMDes, navigasi Katalog/Keranjang/Pesanan/Dashboard, dan header sticky.
- Memperbarui `resources/views/catalog/index.blade.php` dengan hero marketplace, CTA, filter panel, kartu produk modern, dan state stok.
- Memperbarui `resources/views/catalog/show.blade.php` dengan panel detail produk, CTA emerald, dan layout informasi yang lebih terstruktur.
- Memperbarui `resources/views/cart/index.blade.php` dengan panel item dan ringkasan yang lebih tegas.
- Memperbarui `resources/views/checkout/index.blade.php` secara visual tanpa mengubah field, `data-*` contract, JavaScript fulfillment, token, atau endpoint.
- Memperbarui `resources/views/orders/index.blade.php`, `orders/show.blade.php`, dan `orders/payment.blade.php` dengan panel transaksi, badge/warna status, dan CTA yang konsisten.
- Memperbarui `resources/views/dashboards/pelanggan.blade.php` menjadi dashboard pelanggan berbasis marketplace.

## Fitur yang dipertahankan

- Route existing dan middleware role pelanggan.
- CSRF pada seluruh form.
- Cart add/update/remove/clear.
- Validasi checkout, pickup, stok, harga database, dan snapshot transaksi.
- Pembayaran transfer bank dan tunai pickup.
- Upload bukti transfer dan idempotency key.
- Batas kepemilikan order pelanggan.
- Pembatalan order sesuai aturan existing.
- Status fulfillment independen per toko dan riwayat status.
- Pesan lama pada detail produk: `Harga dan ketersediaan stok akan diperiksa kembali saat checkout.`

## File berubah

- `resources/views/components/catalog-layout.blade.php`
- `resources/views/catalog/index.blade.php`
- `resources/views/catalog/show.blade.php`
- `resources/views/cart/index.blade.php`
- `resources/views/checkout/index.blade.php`
- `resources/views/orders/index.blade.php`
- `resources/views/orders/show.blade.php`
- `resources/views/orders/payment.blade.php`
- `resources/views/dashboards/pelanggan.blade.php`
- `docs/uiux-customer-marketplace-3A.md`

Tidak ada controller, service, model, route, migration, database, Admin, atau halaman UMKM yang diubah.

## Validasi

- `php artisan view:cache` — lulus.
- Route katalog, cart, checkout, pesanan, pembayaran — tetap terdaftar.
- `npm run build` — lulus.
- Test pelanggan terfokus — **54 passed, 318 assertions**.
  - CatalogTest
  - CartTest
  - CheckoutTest
  - OrderCheckoutTest
  - CustomerOrderTrackingTest
  - OrderCancellationTest
  - PaymentSubmissionServiceTest

## Catatan dan kekurangan

- Pengujian browser responsive belum dijalankan; validasi tahap ini memakai Blade compilation, build frontend, route audit, dan feature test SQLite.
- Profil pelanggan masih menggunakan layout umum karena route profil yang sama juga digunakan oleh role Admin dan UMKM. Mengubahnya khusus pelanggan memerlukan keputusan arsitektur view terpisah agar role lain tidak terdampak.
- Harga pada beberapa template masih diformat dengan `number_format((float) ...)`, mengikuti implementasi existing. Tidak ada perhitungan database atau snapshot yang diubah pada tahap UI ini.
- Konfigurasi delivery tetap mengikuti perilaku backend existing; redesign tidak mengaktifkan atau mengarang tarif pengiriman.
