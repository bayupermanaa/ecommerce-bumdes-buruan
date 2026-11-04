# UI/UX Tahap 5A — Courier Dashboard

## Hasil audit

Role `kurir` saat ini hanya memiliki satu route:

- `GET|HEAD /kurir/dashboard` — `kurir.dashboard`

Tidak ditemukan controller kurir, model delivery, service pengiriman, route daftar tugas, detail pengiriman, update status, atau riwayat pengiriman. Checkout juga secara eksplisit menyatakan tarif delivery belum tersedia dan alur aktif yang didukung adalah pickup.

## Implementasi

Ditambahkan layout reusable:

- `resources/views/components/kurir-layout.blade.php`

Layout berisi:

- Sidebar dan navbar responsive.
- Identitas Portal Kurir.
- Menu dashboard yang sesuai route aktual.
- Profil dan logout existing.
- Identitas visual cyan/slate yang konsisten dengan design system aplikasi.

Dashboard kurir diubah menjadi halaman informatif yang menampilkan:

- Status akun kurir.
- Alur aktif pickup di toko UMKM.
- Pemberitahuan bahwa fitur delivery belum tersedia.
- Penjelasan modul yang belum ada: tugas, detail penerima, update status, dan riwayat pengiriman.

Tidak dibuat statistik, daftar pengiriman, alamat penerima, tombol update status, atau timeline fiktif.

## File berubah

- `resources/views/components/kurir-layout.blade.php` (baru)
- `resources/views/dashboards/kurir.blade.php`
- `docs/uiux-courier-5A.md` (dokumen ini)

Tidak ada controller, service, model, route, middleware, migration, database, atau role lain yang diubah.

## Validasi

- `php artisan view:cache` — lulus.
- Route audit — hanya `kurir.dashboard` tetap tersedia.
- `npm run build` — lulus.
- `tests/Feature/Auth/RoleAccessTest.php` — **2 passed, 9 assertions**.
- Tidak ada endpoint mutasi baru.

## Keterbatasan backend

Implementasi delivery berikut masih memerlukan keputusan dan pengembangan backend terpisah:

1. Model dan migration pengiriman/tugas kurir.
2. Relasi tugas dengan order/store order dan kurir.
3. Policy kepemilikan tugas dan pembatasan data penerima.
4. Controller dan route daftar/detail/update status.
5. Status transition dan audit trail pengiriman.
6. Penentuan tarif, wilayah, dan alur delivery pada checkout.
7. Test feature dan browser untuk workflow kurir.

Tahap ini tidak mengaktifkan delivery atau mengubah checkout pickup.
