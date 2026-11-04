# Audit UI/UX Dashboard Admin BUMDes

## Ruang lingkup

Audit ini mencakup dashboard Admin BUMDes, layout aplikasi, navigasi, seluruh halaman Admin, route terkait, serta aset CSS/JavaScript yang digunakan. Audit bersifat read-only terhadap kode aplikasi. Tidak ada controller, service, route, database, autentikasi, middleware, atau perhitungan finansial yang diubah.

## 1. Kondisi tampilan saat ini

### Dashboard

Dashboard berada di `resources/views/dashboards/admin.blade.php` dan menggunakan `<x-app-layout>`. Struktur saat ini terdiri dari:

- Header judul “Dashboard Admin BUMDes”.
- Satu kartu putih utama dengan identitas nama dan role pengguna.
- Lima kartu ringkasan entitlement: accrued, eligible, available, held, dan jumlah eligible.
- Kumpulan tautan aksi dalam bentuk tombol gelap yang dirender dalam satu area flex-wrap.
- Tombol logout terpisah di bawah kartu.

Temuan utama:

- Belum ada sidebar khusus Admin BUMDes.
- Navigasi global pada `resources/views/layouts/navigation.blade.php` hanya menampilkan tautan Dashboard; menu operasional Admin tidak tersedia sebagai navigasi persisten.
- Semua fungsi bercampur dalam satu kumpulan tombol tanpa pengelompokan berdasarkan pekerjaan: pembayaran, toko/produk, komisi, hak UMKM, pencairan, dan audit.
- Hierarki visual antara informasi monitoring dan aksi operasional masih lemah.
- Label kartu entitlement belum menyajikan seluruh konteks status secara konsisten.
- Nilai nominal pada dashboard menggunakan `number_format((float) ...)`; ini adalah risiko presisi presentasi untuk nilai DECIMAL dan perlu ditangani dalam tahap implementasi UI secara hati-hati, tanpa mengubah sumber atau rumus keuangan.
- Tidak terlihat indikator waktu pembaruan data, status pekerjaan, atau empty state khusus dashboard.

### Layout dan visual dasar

`resources/views/layouts/app.blade.php` memakai layout Breeze dengan:

- Latar `bg-gray-100`.
- Header putih dengan shadow.
- Font Figtree dari `fonts.bunny.net`.
- Tailwind melalui Vite.
- Navigasi horizontal dengan menu mobile Alpine.js.

`resources/css/app.css` hanya memuat layer Tailwind. Tidak ada token warna, komponen dashboard, kelas utilitas aplikasi, atau sistem desain Admin khusus yang teridentifikasi. Akibatnya, banyak halaman mengulang kelas Tailwind inline.

### Pola halaman Admin

Halaman Admin umumnya sudah memakai pola yang sama secara teknis: header slot, container `max-w-7xl`, kartu putih, shadow ringan, `rounded-lg`, tabel dengan `overflow-x-auto`, dan form Tailwind. Namun implementasinya belum membentuk komponen visual bersama untuk:

- Breadcrumb dan konteks halaman.
- Page header dengan aksi utama.
- Filter panel.
- Status badge.
- Alert sukses/gagal.
- Table toolbar dan pagination.
- Empty state.
- Confirmation/action area.

## 2. Peta menu dan halaman Admin

Seluruh route di bawah berada dalam grup middleware `auth` dan `role:admin_bumdes` pada `routes/web.php`, kecuali dinyatakan lain oleh route existing.

| Kelompok | Halaman/menu | Route | Sifat |
|---|---|---|---|
| Overview | Dashboard Admin | `admin.dashboard` / `/admin/dashboard` | Read-only monitoring dan tautan operasional |
| Pembayaran | Daftar pembayaran | `admin.payments.index` / `/admin/pembayaran` | Filter, daftar, aksi verifikasi |
| Pembayaran | Detail pembayaran | `admin.payments.show` / `/admin/pembayaran/{payment}` | Detail dan aksi pembayaran |
| Pembayaran | Bukti transfer | `admin.payments.proof` | Private file access terotorisasi |
| Pembayaran | Konfirmasi/reject | `admin.payments.confirm`, `admin.payments.reject` | Mutasi pembayaran |
| Pembayaran | Penerimaan tunai | `admin.payments.cash-receipt` | Mutasi penerimaan fisik |
| Toko | Verifikasi toko UMKM | `admin.umkm-stores.index` dan approve/reject | Mutasi status toko |
| Produk | Verifikasi produk | `admin.products.index` dan approve/reject | Mutasi status produk |
| Produk | Verifikasi revisi | `admin.product-revisions.index` dan approve/reject | Mutasi revisi |
| Komisi | Daftar perjanjian | `admin.commission-agreements.index` | Daftar dan navigasi |
| Komisi | Buat/detail perjanjian | `admin.commission-agreements.create`, `.show`, `.store` | Mutasi perjanjian |
| Komisi | Aktivasi/pembatalan/pengakhiran | `.activate`, `.cancel`, `.end` | Mutasi perjanjian |
| Rekening | Rekening resmi BUMDes | `admin.bumdes-bank-accounts.index` dan route terkait | Konfigurasi sensitif |
| Rekening | Verifikasi rekening UMKM | `admin.recipient-bank-accounts.index`, `.verify` | Pemeriksaan dan mutasi verifikasi |
| Hak UMKM | Rekonsiliasi entitlement | `admin.umkm-entitlements.index`, `.show`, `.reconcile` | Daftar, detail, mutasi rekonsiliasi |
| Pencairan | Pemeriksaan pencairan | `admin.disbursements.index` dan route review/transfer/failure | Workflow finansial sensitif |
| Audit | Audit keuangan | `admin.financial-audit.index` | Read-only detector |
| Audit | Export audit | `admin.financial-audit.export` | Read-only CSV berbatas |

Catatan: route yang diaudit menunjukkan bahwa dashboard telah memiliki cakupan operasional luas, tetapi cakupan tersebut belum dipetakan ke navigasi persisten. Tautan dashboard saat ini menjadi satu-satunya titik navigasi langsung untuk sebagian besar modul.

## 3. Audit komponen

### Sidebar dan navbar

Kondisi sekarang:

- Navbar Breeze horizontal dan generik.
- Hanya satu tautan Dashboard pada desktop dan mobile.
- Dropdown pengguna hanya memuat Profile dan Log Out.
- Tidak ada penanda menu aktif untuk modul Admin.
- Tidak ada pengelompokan menu atau badge jumlah pekerjaan.

Rekomendasi:

- Pertahankan navbar mobile yang sudah ada, tetapi tambahkan shell Admin dengan sidebar desktop dan drawer mobile.
- Kelompokkan menu menjadi Overview, Operasional, Keuangan, dan Audit.
- Gunakan route name existing untuk active state; jangan membuat URL baru hanya untuk redesign.
- Sediakan area identitas role dan akses Profile/Logout yang tetap kompatibel dengan Breeze.

### Cards

Kondisi sekarang:

- Kartu ringkasan seragam tetapi sangat minimal.
- Tidak ada ikon, aksen status, sublabel definisi, atau tautan detail.
- Ringkasan belum mencakup pembayaran, pesanan, pencairan, dan audit exception.

Rekomendasi:

- Buat kartu statistik dengan label, nilai utama, definisi singkat, dan tautan “Lihat detail”.
- Bedakan metrik operasional dan finansial secara visual.
- Gunakan navy/slate sebagai warna dasar, dengan aksen status amber/green/red/blue yang konsisten.
- Nominal harus memakai formatter string aman; jangan melakukan kalkulasi baru di Blade.

### Tabel dan daftar

Kondisi sekarang:

- Tabel umumnya menggunakan `overflow-x-auto`, tetapi belum memiliki sistem header/row/action yang seragam.
- Status sering dirender sebagai teks atau kelas lokal per halaman.
- Aksi mutasi dapat bercampur dengan informasi utama.
- Detail pembayaran dan revisi memiliki kepadatan informasi tinggi.

Rekomendasi:

- Standarkan table header, row hover, alignment nominal, kolom status, dan action menu.
- Pertahankan overflow horizontal pada mobile; jangan memaksa tabel lebar menjadi kartu tanpa kebutuhan.
- Gunakan badge status dengan mapping label Bahasa Indonesia dan warna konsisten.
- Pisahkan aksi berisiko dari aksi navigasi dan gunakan confirmation yang sudah ada.

### Form dan alert

Kondisi sekarang:

- Form memakai kelas Tailwind dan komponen Breeze secara campuran.
- Alert session/error tersedia pada banyak halaman, tetapi gaya dan jaraknya tidak sepenuhnya seragam.
- Filter belum memiliki pola panel yang sama di semua modul.

Rekomendasi:

- Standarkan label, helper text, error text, disabled/read-only state, dan tombol utama/sekunder.
- Pertahankan CSRF, method spoofing, validasi server-side, dan policy.
- Gunakan panel filter yang bisa dibedakan dari tabel tanpa mengubah parameter request existing.

## 4. Ketidakkonsistenan visual

| Area | Kondisi ditemukan | Dampak | Arah perbaikan |
|---|---|---|---|
| Warna | Dominan gray-800/gray-700 dengan aksen lokal per halaman | Hierarki dan status sulit dipindai | Token navy/slate + status semantic |
| Spacing | `py-12`, `p-6`, `p-4`, dan gap bervariasi | Ritme halaman tidak konsisten | Skala spacing bersama |
| Radius/shadow | `rounded`, `rounded-md`, `rounded-lg`, shadow berbeda | Komponen terasa berasal dari template berbeda | Standar radius dan elevation |
| Typography | Judul, label, dan tombol menggunakan ukuran berbeda-beda | Hierarki informasi kurang jelas | Skala heading/body/metadata |
| Tombol | Banyak tombol uppercase kecil dengan gaya sama | Aksi penting tidak menonjol | Primary, secondary, danger, link |
| Status | Sebagian badge, sebagian teks biasa | Status operasional lambat dipahami | Komponen badge terpusat |
| Tabel | Padding/header/action tidak seragam | Pemindaian data kurang efisien | Table pattern bersama |
| Responsif | Tailwind responsif tersedia, tetapi tabel/form padat tetap berisiko | Mobile memerlukan scroll dan konteks lebih banyak | Uji layout per breakpoint |

## 5. Evaluasi responsif

### Desktop

Layout `max-w-7xl` dan grid breakpoint yang ada cukup untuk dasar desktop. Masalah utama bukan lebar, melainkan kepadatan navigasi dan kurangnya shell Admin yang tetap terlihat saat berpindah halaman.

### Tablet

Breakpoint `sm`, `md`, dan `lg` sudah digunakan pada beberapa halaman. Tabel dan kumpulan tombol dapat menjadi padat pada lebar tablet; filter dan action bar perlu diuji dengan wrapping yang konsisten.

### Mobile

Navbar Breeze memiliki menu hamburger Alpine.js. Namun halaman Admin belum mempunyai pola mobile-first yang jelas untuk:

- Sidebar/drawer modul.
- Tabel dengan banyak kolom.
- Form filter bertingkat.
- Tombol aksi berisiko.
- Header dengan judul dan aksi bersamaan.

Redesign perlu mempertahankan aksesibilitas keyboard, focus state, target sentuh yang cukup, dan teks status yang tetap terbaca tanpa hanya mengandalkan warna.

## 6. File yang perlu diperhatikan

### File utama

- `resources/views/dashboards/admin.blade.php` — dashboard yang akan direvamp.
- `resources/views/layouts/app.blade.php` — shell aplikasi.
- `resources/views/layouts/navigation.blade.php` — navbar dan navigasi responsif.
- `resources/css/app.css` — entry CSS Tailwind.
- `resources/js/app.js` — bootstrap/alur Alpine/Vite.
- `tailwind.config.js` — sumber token dan content scanning.

### Blade Admin

- `resources/views/admin/payments/index.blade.php`
- `resources/views/admin/payments/show.blade.php`
- `resources/views/admin/umkm-stores/index.blade.php`
- `resources/views/admin/products/index.blade.php`
- `resources/views/admin/product-revisions/index.blade.php`
- `resources/views/admin/commission-agreements/index.blade.php`
- `resources/views/admin/commission-agreements/create.blade.php`
- `resources/views/admin/commission-agreements/show.blade.php`
- `resources/views/admin/bumdes-bank-accounts/index.blade.php`
- `resources/views/admin/bumdes-bank-accounts/create.blade.php`
- `resources/views/admin/bumdes-bank-accounts/edit.blade.php`
- `resources/views/admin/bumdes-bank-accounts/_form.blade.php`
- `resources/views/admin/recipient-bank-accounts/index.blade.php`
- `resources/views/admin/umkm-entitlements/index.blade.php`
- `resources/views/admin/umkm-entitlements/show.blade.php`
- `resources/views/admin/disbursements/index.blade.php`
- `resources/views/admin/financial-audit/index.blade.php`

### Backend yang menjadi kontrak, bukan target redesign

- `app/Http/Controllers/Admin/AdminDashboardController.php`
- `app/Http/Controllers/Admin/PaymentVerificationController.php`
- `app/Http/Controllers/Admin/FinancialAuditController.php`
- Controller Admin lain yang terhubung dengan route di atas.

Controller/service/model tersebut harus dipertahankan pada tahap UI, kecuali ada persetujuan terpisah untuk perubahan perilaku.

## 7. Rekomendasi desain target

### Arah visual

- Latar utama: putih/very light slate.
- Sidebar: navy/slate gelap dengan kontras aksesibilitas yang memadai.
- Konten: area terang dengan border tipis dan shadow ringan.
- Warna status: green untuk selesai/terverifikasi, amber untuk menunggu, red untuk ditolak/gagal, blue untuk informasi, slate untuk netral.
- Typography: Figtree tetap dipertahankan agar perubahan tidak memerlukan dependency baru.

### Struktur shell

1. Sidebar desktop tetap dengan logo, nama aplikasi, role, dan menu terkelompok.
2. Topbar berisi judul konteks halaman, breadcrumb sederhana, notifikasi/status bila memang sudah tersedia, dan menu pengguna.
3. Drawer sidebar pada tablet/mobile.
4. Content container responsif dengan page header konsisten.

### Struktur dashboard

1. Welcome/page header singkat.
2. Kartu monitoring utama.
3. Kartu pekerjaan yang membutuhkan tindakan: pembayaran, verifikasi toko/produk, rekonsiliasi, pencairan, dan audit.
4. Quick actions yang dikelompokkan, bukan satu daftar tombol panjang.
5. Link ke laporan read-only dan modul operasional.

Nominal, status pembayaran, komisi, entitlement, dan pencairan harus tetap berasal dari payload controller existing. Redesign tidak boleh menghitung ulang atau mengubah status.

## 8. Urutan implementasi yang aman

### Tahap UI-UX 1 — Design tokens dan shell

- Dokumentasikan token warna, spacing, radius, typography, dan elevation.
- Tambahkan komponen/layout Admin yang tidak mengubah route atau controller.
- Pastikan navbar desktop/mobile dan logout tetap berfungsi.

### Tahap UI-UX 2 — Dashboard Admin

- Refactor tampilan `dashboards/admin.blade.php` ke shell baru.
- Pertahankan seluruh route link dan payload existing.
- Ganti format nominal hanya pada lapisan presentasi dengan formatter presisi yang aman.

### Tahap UI-UX 3 — Navigasi dan halaman daftar

- Terapkan shell pada pembayaran, toko, produk, revisi, perjanjian, entitlement, pencairan, dan audit.
- Standarkan page header, filter, alert, badge, table, pagination, dan empty state.

### Tahap UI-UX 4 — Halaman detail dan form

- Terapkan pola detail pembayaran, perjanjian, entitlement, rekening, dan pencairan.
- Pertahankan warning, confirmation, CSRF, policy, dan validasi existing.

### Tahap UI-UX 5 — Responsive dan accessibility pass

- Uji desktop 1366px, tablet 768px, dan mobile 390px.
- Periksa keyboard navigation, focus, contrast, target sentuh, overflow tabel, dan pesan error.

### Tahap UI-UX 6 — Regression validation

- `php artisan view`.
- Focused feature tests Admin.
- Test role/authorization existing.
- Browser smoke test terisolasi bila fixture tersedia.
- Tidak menjalankan migration atau seeder database development.

## 9. Batasan yang harus dipertahankan

- URL dan route name existing tidak diubah.
- Middleware role dan policy tidak diubah.
- Controller/service/model tidak menjadi bagian redesign visual.
- Tidak ada perubahan perhitungan finansial, snapshot komisi, entitlement, allocation, atau pencairan.
- Tidak menampilkan rekening lengkap atau bukti privat pada dashboard/laporan umum.
- Tidak menambahkan dependency hanya untuk styling tanpa alasan yang disetujui.

## Kesimpulan

Dashboard Admin sudah memiliki fungsi operasional yang luas, tetapi presentasinya masih berupa halaman Breeze dasar dengan navigasi yang tersebar pada kumpulan tombol. Prioritas redesign adalah membangun shell Admin yang konsisten, mengelompokkan navigasi, menstandardisasi cards/tabel/status/form, lalu menerapkan pola tersebut secara bertahap ke halaman yang sudah ada. Perubahan dapat dilakukan sepenuhnya pada Blade/CSS/komponen presentasi dengan mempertahankan kontrak backend dan seluruh URL existing.
