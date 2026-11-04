# UI/UX Tahap 2A — Audit dan Implementasi Pemilik UMKM

## Ringkasan

Halaman pemilik UMKM kini menggunakan layout khusus `x-umkm-layout` dengan identitas Portal UMKM. Visualnya mengikuti design system Admin BUMDes—navy/slate, kartu putih, rounded panel, badge status, dan responsive spacing—tetapi navigasinya dipisahkan untuk kewenangan pemilik toko.

Tidak ada controller, service, model, route, migration, database, atau logika nominal yang diubah.

## Pemetaan fitur

| Modul | Route utama | Status implementasi UI |
|---|---|---|
| Dashboard | `umkm.dashboard` | Selesai, ringkasan navigasi dan identitas akun |
| Profil toko | `umkm.stores.*` | Selesai, detail profil, status, create/edit |
| Produk | `umkm.products.*` | Selesai, tabel, status, revisi, create/edit, hapus |
| Pesanan | `umkm.orders.index` | Selesai, filter status, tabel, pagination |
| Detail/fulfillment | `umkm.orders.show` | Selesai, snapshot item, status, aksi PATCH, riwayat |
| Perjanjian komisi | `umkm.commission-agreements.index` | Selesai, perjanjian aktif dan riwayat |
| Rekening pencairan | `umkm.recipient-bank-accounts.*` | Selesai, masking nomor, create/update/aktifkan/nonaktifkan |
| Pencairan | `umkm.disbursements.*` | Selesai, saldo, status pengajuan, pembatalan requested |

Monitoring entitlement ditampilkan melalui saldo dan status pada halaman pencairan. Belum ada route pemilik UMKM untuk laporan entitlement terpisah; tidak dibuat pada tahap ini karena backend belum menyediakan endpoint tersebut.

## Perubahan tampilan

- Menambahkan `resources/views/components/umkm-layout.blade.php`.
- Sidebar responsive dengan grup Ringkasan, Operasional, dan Keuangan.
- Navbar sticky dengan identitas pemilik UMKM, profil, dan logout.
- Dashboard dengan hero panel, kartu pintas, identitas akun, dan bantuan operasional.
- Halaman toko, produk, pesanan, komisi, rekening, dan pencairan memakai panel konsisten.
- Detail pesanan mempertahankan tombol fulfillment, CSRF, konfirmasi penyelesaian, snapshot item, dan status log.
- Nomor rekening tetap ditampilkan tersamar; tidak ada bukti transfer atau data toko lain yang ditambahkan.
- Empty state, alert, filter, pagination, dan action form existing tetap dipertahankan.

## File berubah

- `resources/views/components/umkm-layout.blade.php` (baru)
- `resources/views/dashboards/umkm.blade.php`
- `resources/views/umkm-stores/index.blade.php`
- `resources/views/umkm-stores/create.blade.php`
- `resources/views/umkm-stores/edit.blade.php`
- `resources/views/umkm/products/index.blade.php`
- `resources/views/umkm/products/create.blade.php`
- `resources/views/umkm/products/edit.blade.php`
- `resources/views/umkm/orders/index.blade.php`
- `resources/views/umkm/orders/show.blade.php`
- `resources/views/umkm/commission-agreements/index.blade.php`
- `resources/views/umkm/recipient-bank-accounts/index.blade.php`
- `resources/views/umkm/disbursements/index.blade.php`
- `docs/uiux-umkm-audit-and-implementation-2A.md` (dokumen ini)

Partial form produk dan toko tidak diubah agar nama field, validasi, dan kontrak controller tetap persis.

## Validasi

- `php artisan view:cache` — lulus.
- `php artisan route:list --path=umkm` — seluruh route UMKM tetap terdaftar, termasuk endpoint GET, POST, PUT/PATCH, dan DELETE existing.
- Test UMKM terkait — **35 passed, 224 assertions**.
  - Store management: 3 passed.
  - Product management: 3 passed.
  - Store order read: 6 passed.
  - Store order fulfillment action: 6 passed.
  - Commission agreement: 12 passed.
  - Recipient bank account: 2 passed.
  - Disbursement request: 3 passed.
- `tests/Feature/Auth/RoleAccessTest.php` — **2 passed, 9 assertions**.
- `npm run build` — lulus dengan Vite.
- PHP syntax check controller existing — lulus.

## Catatan dan risiko tersisa

- Product listing masih memakai `get()` dari controller, bukan pagination; redesign ini tidak mengubah backend sesuai batasan.
- Profil toko dan produk memiliki fungsi backend lengkap, tetapi status verifikasi tetap bergantung pada proses Admin.
- Monitoring hak UMKM pemilik masih berupa saldo/status pada halaman pencairan; laporan entitlement khusus belum tersedia di route UMKM.
- Pengujian responsive visual melalui browser belum dilakukan pada tahap ini; validasi yang dilakukan adalah kompilasi, route, build, dan feature test terisolasi.

Seluruh perubahan berada pada presentasi Blade/layout. Admin BUMDes dan role lain tidak diubah.
