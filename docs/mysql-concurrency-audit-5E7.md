# MySQL Concurrency & Financial Integrity Audit — Tahap 5E.7

Tanggal audit: 2026-11-02

## Verdict

**BLOCKED untuk pengujian runtime MySQL/InnoDB.** Tidak tersedia database MySQL disposable yang telah disediakan dan disetujui. Tidak ada database, migration, seeder, atau koneksi baru yang dibuat. Database development `ecommerce_bumdes_restore` tidak disentuh.

Audit source dan rencana pengujian telah diselesaikan. Hasil ini tidak boleh ditafsirkan sebagai bukti concurrency MySQL telah lulus.

## Pre-flight safety

| Pemeriksaan | Status | Bukti |
|---|---|---|
| PHPUnit memakai `APP_ENV=testing` | READY | `phpunit.xml` |
| PHPUnit memakai SQLite | READY | `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` |
| Override ke MySQL pada PHPUnit | Tidak ditemukan | `phpunit.xml` dan runner yang diaudit |
| MySQL client CLI | NOT READY | `mysql` tidak tersedia pada PATH |
| Service MySQL lokal | NOT READY | Service `mysql` terdeteksi `Stopped` |
| Database disposable yang disetujui | NOT VERIFIED | Tidak ada konfigurasi/credential testing yang disediakan |
| Database development disentuh | NO | Tidak ada koneksi atau operasi database development |

Tidak ada perintah `CREATE DATABASE`, `DROP DATABASE`, `migrate`, `db:seed`, atau restore yang dijalankan.

## Bukti dari source code

### Payment

`PaymentConfirmationService::confirm()` menggunakan transaksi database dan mengunci order lebih dahulu, kemudian payment. Pemeriksaan status sukses, `confirmed_order_id`, nominal fixed-scale, dan payment log berada di dalam transaksi.

Status source: **VERIFIED BY SOURCE**.

Risiko yang masih memerlukan MySQL: dua transaksi berbeda yang memeriksa payment/order bersamaan, lock wait, deadlock, dan perilaku rollback InnoDB.

### Entitlement

`UmkmEntitlementService` menggunakan `DB::transaction()` dan `lockForUpdate()` pada order, payment, store order, serta entitlement sesuai alurnya. Entitlement memiliki unique constraint pada `store_order_id`, dan event memiliki unique `idempotency_key`.

Status source: **VERIFIED BY SOURCE**.

### Disbursement review dan approval

`UmkmDisbursementApprovalService` mengunci toko lalu disbursement, kemudian allocation dan entitlement. Approval idempoten pada status `approved`; event dibuat dengan unique idempotency key.

Status source: **VERIFIED BY SOURCE**.

### Transfer dan transfer failure

Transfer verification serta failure release mengunci toko lalu disbursement, kemudian allocation dan entitlement. Verifikasi menolak actor pencatat yang sama; event dan release menggunakan idempotency key.

Status source: **VERIFIED BY SOURCE**.

Catatan: urutan lock utama terlihat konsisten pada service disbursement, tetapi potensi deadlock lintas workflow tetap harus diuji dengan dua koneksi MySQL yang benar-benar bersamaan.

## Constraint dan idempotency yang diaudit

- `payments.idempotency_key` unique nullable.
- `payments.confirmed_order_id` unique nullable.
- `umkm_entitlements.store_order_id` unique.
- `umkm_disbursements.active_request_lock` unique nullable.
- `umkm_disbursements.idempotency_key` unique.
- `umkm_disbursement_allocations.entitlement_reservation_lock` unique nullable.
- Pair disbursement-entitlement pada allocation unique.
- Entitlement event idempotency key unique.
- Disbursement event idempotency key unique.

Status: **VERIFIED BY SOURCE**. Perilaku blocking dan isolation level pada MySQL belum diuji.

## Skenario concurrency

| Skenario | Expected | Status |
|---|---|---|
| Dua verifikasi payment | Satu payment/order paid, satu entitlement per store order | REQUIRES MYSQL TEST |
| Dua settlement entitlement | Satu transisi final, tidak ada event/nominal ganda | REQUIRES MYSQL TEST |
| Dua approval disbursement | Satu approval sah, retry idempoten | REQUIRES MYSQL TEST |
| Dua pencatatan transfer | Satu metadata transfer dan satu event | REQUIRES MYSQL TEST |
| Dua verifikasi transfer | Satu paid/settled transition dan event final | REQUIRES MYSQL TEST |
| Admin pencatat memverifikasi sendiri | Selalu ditolak | VERIFIED BY SOURCE + SQLITE TEST |
| Admin kedua memverifikasi | Berhasil sekali bila syarat lengkap | VERIFIED BY SOURCE + SQLITE TEST |
| Retry failure/cancellation | Release sekali, allocation historis tetap | VERIFIED BY SQLITE TEST |
| Konflik transaksi/deadlock | Rollback aman atau retry terkontrol tanpa partial write | REQUIRES MYSQL TEST |

## Test plan yang disiapkan

Pengujian berikut harus dilakukan hanya pada MySQL disposable yang allowlisted:

1. Administrator menyediakan database staging/disposable terpisah, akun khusus test, dan kredensial melalui environment test—bukan `.env` development.
2. Runner memverifikasi host, database name, dan marker environment sebelum migration. Target selain allowlist harus langsung gagal.
3. Jalankan migration hanya pada database disposable.
4. Buat fixture sintetis yang tidak memuat rekening atau bukti nyata.
5. Buka dua koneksi database independen.
6. Gunakan barrier aplikasi/IPC untuk menahan kedua transaksi pada titik yang sama; hindari `sleep` arbitrer.
7. Lepaskan barrier, tunggu kedua transaksi selesai, lalu verifikasi status, count, nominal DECIMAL, locks, allocation, event, dan unique key.
8. Ulangi skenario beberapa kali untuk mendeteksi deadlock/interleaving yang tidak stabil.
9. Simulasikan exception setelah sebagian operasi untuk memastikan rollback dan tidak ada partial write.
10. Hapus database disposable hanya setelah ada izin eksplisit dan hasil sudah diarsipkan.

## Risiko dan keterbatasan

- SQLite `:memory:` tidak membuktikan row locking dan isolation InnoDB.
- Service MySQL lokal ada tetapi stopped; tidak boleh dinyalakan atau dipakai tanpa persetujuan.
- Tidak ada retry deadlock yang dapat dibuktikan dari audit ini; perlu diputuskan apakah retry berada pada runner, queue, atau service.
- Perbedaan timezone, isolation level, dan konfigurasi SQL mode MySQL belum diverifikasi.
- Tidak ada concurrency test terhadap database development.

## File berubah

- `docs/mysql-concurrency-audit-5E7.md` dibuat.
- Tidak ada source code, migration, `.env`, database, atau data transaksi yang diubah.

Kesimpulan: concurrency MySQL/InnoDB tetap **BLOCKED**, sementara locking, transaksi, unique constraint, dan idempotency telah terdokumentasi berdasarkan source serta regresi SQLite yang sudah ada.
