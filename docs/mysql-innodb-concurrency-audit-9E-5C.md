# MySQL/InnoDB Compatibility & Concurrency Audit — Tahap 9E.5C

Tanggal audit: 1 November 2026
Ruang lingkup: inspeksi source/migration dan bukti automated test SQLite. Tidak ada koneksi ke MySQL development `ecommerce_bumdes_restore`, database baru, migration, seeder, atau uji concurrency yang dijalankan.

## Klasifikasi bukti

- **VERIFIED BY SOURCE** — ditemukan langsung pada service, migration, atau konfigurasi.
- **VERIFIED BY SQLITE TEST** — tercakup oleh test SQLite in-memory; tidak menyatakan kesetaraan perilaku InnoDB.
- **REQUIRES MYSQL TEST** — hanya dapat dibuktikan pada MySQL/InnoDB terisolasi.
- **NOT VERIFIED** — tidak dapat dibuktikan dari source maupun test yang diperiksa.

## 1. Ringkasan eksekutif

| Area | Status | Kesimpulan |
| --- | --- | --- |
| Transaksi finansial | VERIFIED BY SOURCE | Service inti memakai `DB::transaction()` dan memvalidasi state setelah row lock. |
| Serialisasi order/payment | VERIFIED BY SOURCE + SQLITE TEST | Payment confirmation mengunci `orders` sebelum `payments`; retry confirmation diuji idempoten. |
| Serialisasi pencairan per toko | VERIFIED BY SOURCE + SQLITE TEST | Service request/review/transfer/failure mengunci `umkm_stores` terlebih dahulu, lalu resource pencairan terkait. |
| Unique/idempotency constraints | VERIFIED BY SOURCE + SQLITE TEST | Ada unique key untuk payment sukses, entitlement per StoreOrder, lock aktif, allocation pair, dan event/request idempotency. |
| Engine InnoDB aktual | REQUIRES MYSQL TEST | `config/database.php` memakai `engine => null`; source tidak memaksa semua tabel memakai InnoDB. |
| Deadlock retry otomatis | NOT VERIFIED | Tidak ditemukan argument retry pada `DB::transaction(..., attempts)` atau handler khusus SQLSTATE deadlock/serialization failure. |
| Race condition MySQL | REQUIRES MYSQL TEST | SQLite tidak mereplikasi row/gap lock, isolation, deadlock, dan semantics concurrent write MySQL/InnoDB. |

Tidak ada partial write yang disengaja pada path service yang diperiksa: mutasi status, lock, allocation, dan event penting berada dalam transaction. Bila database melempar deadlock/constraint exception, expectation source adalah rollback transaction; kemampuan retry otomatis dan pengalaman pengguna tetap perlu diuji di MySQL.

## 2. Audit transaction dan locking

### 2.1 PaymentConfirmationService

| Aspek | Bukti | Status |
| --- | --- | --- |
| Transaction | `confirm()` dan `reject()` memakai `DB::transaction()`. | VERIFIED BY SOURCE |
| Urutan lock | `lockOrderAndPayment()` membaca ID order, lalu mengunci `orders` dan `payments` target. | VERIFIED BY SOURCE |
| Satu payment sukses/order | Mengecek payment sukses lain di bawah lock; migration menetapkan unique `payments.confirmed_order_id`. | VERIFIED BY SOURCE + SQLITE TEST |
| Idempotensi | `isConfirmedRetry()` mengembalikan payment paid yang sama tanpa reaktivasi fulfillment/entitlement. | VERIFIED BY SOURCE + SQLITE TEST |
| Atomic downstream | Confirmation memanggil activation fulfillment dan accrual entitlement dalam transaction bersarang Laravel. Test simulasi kegagalan entitlement membuktikan payment/fulfillment rollback di SQLite. | VERIFIED BY SOURCE + SQLITE TEST |
| Deadlock retry | Tidak terlihat retry explicit. | NOT VERIFIED |

Lock utama untuk satu order adalah `orders` terlebih dahulu. Ini menyerialkan confirmation/rejection/fulfillment pada order yang sama sebelum lock payment/store order yang lebih detail diperoleh.

### 2.2 StoreOrderFulfillmentService dan UmkmEntitlementService

| Aspek | Bukti | Status |
| --- | --- | --- |
| Aktivasi setelah payment | Mengunci `orders`, lalu semua `store_orders` order demi `id ASC`; menulis status log dan agregat order dalam satu transaction. | VERIFIED BY SOURCE + SQLITE TEST |
| Transisi UMKM | `transition()` mengunci `orders`, lalu seluruh `store_orders` untuk order dengan `id ASC`, memverifikasi policy/payment/status, menulis log, lalu sinkronisasi Order. | VERIFIED BY SOURCE + SQLITE TEST |
| Accrual | Mengunci `orders → payments → store_orders` untuk order; entitlement eksisting dikunci berdasar `store_order_id`. | VERIFIED BY SOURCE + SQLITE TEST |
| Eligible | Saat StoreOrder completed, entitlement service mengunci `orders → store_orders → entitlements → payments`. | VERIFIED BY SOURCE + SQLITE TEST |
| Reconcile | Mengunci `umkm_stores → orders → payments → store_orders → entitlements`. | VERIFIED BY SOURCE + SQLITE TEST |
| Constraint entitlement | `umkm_entitlements.store_order_id` unique; event memiliki unique `idempotency_key`. | VERIFIED BY SOURCE + SQLITE TEST |

Catatan urutan: alur order memiliki titik serialisasi `orders` terlebih dahulu. Ini mengurangi risiko deadlock pada satu order, termasuk saat transition StoreOrder memanggil eligibility di transaction bersarang. Namun urutan detail payment/store order/entitlement berbeda antar service; pembuktian deadlock tetap membutuhkan MySQL dengan request paralel.

### 2.3 Request, review, approval, transfer, cancellation, dan transfer failure

| Service | Urutan lock yang terlihat | Pengaman |
| --- | --- | --- |
| `UmkmDisbursementRequestService` | `umkm_stores → existing idempotency request → recipient bank account aktif → entitlements available (id ASC) → active disbursement` | `active_request_lock` unique, `entitlement_reservation_lock` unique, allocation pair unique, key idempotency request/event |
| `UmkmDisbursementApprovalService` | `umkm_stores → umkm_disbursements → recipient bank account → allocations (id ASC) → entitlements` | State machine review/approval/reject, checks snapshot rekening dan jumlah allocation |
| `UmkmDisbursementTransferService` | `umkm_stores → umkm_disbursements → allocations (id ASC) → entitlements` | Admin berbeda dari recorder; status/nominal/bukti wajib; paid retry no-op |
| `UmkmDisbursementFailureService` | `umkm_stores → umkm_disbursements → allocations (id ASC) → entitlements` | Bukti gagal terpisah dari final failure; Admin verifier berbeda; release reservation idempoten |

`umkm_stores` dipakai sebagai titik serialisasi bersama untuk operasi pencairan toko yang sama. Allocation selalu diurutkan `id ASC` pada service yang melepaskan/settle reservation; ini adalah pengendalian penting untuk multi-entitlement request.

Status ini **VERIFIED BY SOURCE**. Request aktif, cancellation/release, transfer verification, dan failure confirmation juga **VERIFIED BY SQLITE TEST** pada test pencairan yang ada. Konkurensi InnoDB dan gap locking tetap **REQUIRES MYSQL TEST**.

## 3. MySQL compatibility audit

| Area | Temuan | Klasifikasi | Dampak / tindakan |
| --- | --- | --- | --- |
| DECIMAL | Nominal payment, commission, entitlement, allocation, disbursement, dan transfer memakai `DECIMAL(15,2)`; persentase memakai `DECIMAL(5,2)`. | VERIFIED BY SOURCE | Nilai service dibandingkan/diagregasi sebagai string/minor unit, bukan float. Verifikasi DDL aktual dan hasil SUM di MySQL. |
| SQL aggregate | Laporan komisi memakai `COALESCE(SUM(...), 0)`; laporan entitlement/disbursement memakai SUM/CASE lalu normalisasi string. | VERIFIED BY SOURCE + SQLITE TEST | Uji type/result MySQL terutama empty aggregate dan nominal besar. |
| `whereDate` | Filter laporan memakai `whereDate()` pada `payment_confirmed_at`, `accrued_at`, dan `requested_at`. | VERIFIED BY SOURCE | Periode mengikuti tanggal session/database; timezone bisnis dan session MySQL perlu ditetapkan/diuji. |
| Foreign key | Migration membentuk FK untuk order, payment, user, store, account, entitlement, allocation, dan event. | VERIFIED BY SOURCE + SQLITE TEST | Efektivitas FK di production mensyaratkan InnoDB; periksa `SHOW TABLE STATUS` pada MySQL terisolasi. |
| Unique index | Unique: confirmed order payment, idempotency payment/disbursement/event, `store_order_id` entitlement, active locks, allocation pair. | VERIFIED BY SOURCE + SQLITE TEST | Uji duplicate concurrent insert pada InnoDB. MySQL mengizinkan beberapa `NULL` pada unique index; desain memanfaatkan ini untuk record historis setelah lock di-null-kan. |
| JSON metadata | Event metadata menggunakan `$table->json('metadata')->nullable()`. | VERIFIED BY SOURCE | Uji MySQL JSON validity/query behavior; SQLite test tidak membuktikan storage native JSON MySQL. |
| Engine | Konfigurasi MySQL Laravel menyetel `engine => null`; migration tidak menyebut `ENGINE=InnoDB`. | REQUIRES MYSQL TEST | **Prasyarat deployment:** database target/default table engine harus InnoDB. Jangan mengasumsikan dari source. |
| Strict SQL mode | Config MySQL memakai `strict => true`. | VERIFIED BY SOURCE | Nilai runtime server dan SQL mode aktual masih REQUIRES MYSQL TEST. |
| Nested transaction | Laravel akan menggunakan transaction nesting/savepoint sesuai driver. | VERIFIED BY SOURCE | Uji commit/rollback nested transaction di MySQL target; SQLite tidak menyamai semua behavior. |

## 4. Skenario concurrency

| # | Kondisi awal dan operasi paralel | Potensi race | Pengaman source | Expected database state | Bukti tersedia | Pengujian yang masih dibutuhkan |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | Satu Order, satu Payment `pending_confirmation`; dua Admin mengonfirmasi bersamaan. | Kedua request dapat mencoba membuat payment paid, fulfillment, dan entitlement. | Lock `orders → payments`; unique `confirmed_order_id`; status retry; transaction. | Satu Payment paid/confirmed; satu log confirmation; satu entitlement per StoreOrder; StoreOrder hanya sekali `awaiting_processing`. | Source + SQLite retry/rollback test. | Dua koneksi MySQL sinkron; verifikasi satu request sukses/no-op dan tidak ada duplicate key/partial event. |
| 2 | Satu StoreOrder paid/calculated; dua proses accrual entitlement. | Dua insert entitlement untuk StoreOrder sama. | Lock order/payment/store orders; unique `ue_store_order_unique`; existing entitlement lock; event idempotency. | Tepat satu entitlement dengan snapshot asal; satu event accrual. | Source + SQLite idempotency test. | MySQL concurrent confirmation/accrual dengan barrier setelah read sebelum insert. |
| 3 | Satu UMKM, beberapa entitlement available; dua request pencairan berbeda. | Dua request memilih saldo sama atau membuat dua active request. | Lock Store sebagai serialization point; lock entitlements; `ud_active_request_lock_unique`; idempotency key. | Satu disbursement aktif; entitlement hanya ter-reserve sekali; request kedua ditolak/menunggu lalu gagal validasi. | Source + SQLite second active request test. | Dua koneksi MySQL dengan request key berbeda; periksa lock wait/deadlock dan rollback. |
| 4 | Satu entitlement available; dua reservation request bersaing. | Double allocation/reservation atau lost update. | Lock store + entitlement; `uda_entitlement_reservation_lock_unique`; allocation pair unique; status `available` dicek di bawah lock. | Satu allocation aktif dan status reserved, atau seluruh perubahan rollback. | Source + SQLite foundation/request rollback test. | MySQL concurrent reservation, termasuk allocation historis dengan lock NULL. |
| 5 | Disbursement approved, bukti transfer sudah dicatat; dua Admin verifier berbeda. | Keduanya dapat settle entitlement dan menyetel paid. | Lock Store → Disbursement → Allocation → Entitlement; state check; paid retry no-op; event keys/unique. | Satu paid transition; entitlement settled sekali; allocation lock dan active lock NULL; event tidak ganda. | Source + SQLite retry/actor separation test. | Dua koneksi MySQL simultan; validasi salah satu menunggu/no-op dan tidak ada duplicate event. |
| 6 | Disbursement requested/under_review; satu proses cancel dan satu proses approval/review. | Cancel dapat melepas reservation ketika approval sedang menahan/menyetujui request. | Lock Store → Disbursement, state transition guard, allocation/entitlement locks; terminal state validation. | Hanya satu terminal/valid state menang. Jika cancelled, entitlement available dan lock NULL; jika approved, tetap reserved/locks aktif. | Source; SQLite menguji state invalid dan idempotensi terpisah. | MySQL race cancel vs start/complete review dan cancel vs approval; klasifikasikan outcome/exception. |
| 7 | Disbursement approved dengan transfer tercatat; satu proses failure final dan satu proses transfer verify/review terkait. | `paid` dan `transfer_failed` dapat bersaing; release versus settle entitlement. | Lock Store → Disbursement → allocations → entitlements; state check, `paid_at`, evidence/actor guard, unique event keys. | Tepat satu hasil terminal: paid + settled **atau** transfer_failed + available. Tidak boleh kombinasi. | Source; SQLite menguji actor separation dan normal path. | MySQL race confirm failure vs verify transfer dengan barrier; ulang berkali-kali, cek event/lock/nilai. |

## 5. Deadlock, rollback, dan recovery audit

### Temuan

1. **VERIFIED BY SOURCE:** service memakai `DB::transaction()` untuk mutasi finansial dan `lockForUpdate()` terhadap resource inti. Exception sebelum commit seharusnya me-roll back mutasi database dalam scope transaction.
2. **VERIFIED BY SQLITE TEST:** test mensimulasikan kegagalan entitlement, reservation, dan status log; payment/fulfillment/reservation yang telah berubah di dalam transaction kembali ke state awal pada fixture SQLite.
3. **VERIFIED BY SOURCE:** beberapa service mengurutkan koleksi `store_orders` atau allocation dengan `id ASC`, mengurangi deadlock antar operasi multi-baris yang mengunci set sama.
4. **REQUIRES MYSQL TEST:** nested transaction Laravel dan savepoint/rollback pada MySQL untuk path confirmation → fulfillment → entitlement serta completion → eligible.
5. **NOT VERIFIED:** tidak ada kebijakan retry deadlock eksplisit yang ditemukan (`DB::transaction` dengan jumlah attempts lebih dari default atau retry terhadap SQLSTATE `1213`/`40001`). Saat deadlock nyata terjadi, exception kemungkinan dikembalikan ke request; data semestinya rollback, tetapi availability/UX retry perlu SOP atau implementasi yang disetujui.
6. **REQUIRES MYSQL TEST:** query `SELECT ... FOR UPDATE` pada index unique/null lock dapat memperoleh next-key/gap locks berbeda dari SQLite. Uji dengan index dan isolation production aktual.

### Risiko partial write

Tidak ditemukan path mutasi finansial utama yang sengaja commit sebagian. Namun file proof ditulis ke storage sebelum beberapa transaction diselesaikan dan dibersihkan pada catch untuk service transfer/failure; ketahanan terhadap crash proses antara upload, transaction, dan cleanup tetap perlu prosedur operasional/reconciliation. Ini bukan bukti bahwa database financial parsial, tetapi dapat menghasilkan file orphan bila proses mati mendadak. Status: **REQUIRES MYSQL TEST** untuk transaksi database dan **NOT VERIFIED** untuk fault-injection filesystem/proses nyata.

## 6. Rekomendasi uji MySQL/InnoDB terisolasi

Jangan jalankan rencana berikut terhadap `ecommerce_bumdes_restore`, production, atau database yang memuat data klien.

### Prasyarat administrator

1. Sediakan instance/schema MySQL 8 terisolasi dengan default engine InnoDB dan account disposable least-privilege.
2. Gunakan salinan source/environment testing khusus; jangan mengubah `.env` development. Injeksi variabel koneksi hanya pada process test terisolasi.
3. Jalankan migration hanya di schema disposable sesuai prosedur yang disetujui, lalu pastikan `SHOW TABLE STATUS` memperlihatkan `Engine=InnoDB` untuk tabel finansial.
4. Aktifkan log deadlock/monitoring database untuk window test dan catat isolation level serta SQL mode.
5. Gunakan fixture sintetis tanpa rekening/bukti/data pelanggan nyata.

### Pola eksekusi yang direkomendasikan

1. Buat dua koneksi independen dan barrier deterministik setelah kedua transaksi membaca fixture namun sebelum row target diperbarui.
2. Jalankan tujuh skenario pada tabel concurrency di atas, minimal beberapa iterasi untuk setiap ordering proses.
3. Setelah tiap iterasi, validasi read-only:
   - jumlah payment paid dan `confirmed_order_id`;
   - jumlah entitlement per StoreOrder;
   - status/order/StoreOrder/payment;
   - allocation pair, `active_request_lock`, dan `entitlement_reservation_lock`;
   - jumlah/type event dan idempotency key;
   - DECIMAL snapshot serta tidak ada nominal ganda.
4. Catat apakah outcome adalah success, no-op idempoten, validation conflict, unique conflict, lock wait timeout, atau deadlock.
5. Jika deadlock muncul, jangan langsung mengubah constraint/data. Dokumentasikan SQLSTATE, urutan statement, index yang digunakan, dan state setelah rollback; perubahan retry/ordering harus ditinjau pada tahap implementasi terpisah.

### Kriteria lulus minimum

- Tidak ada negative stock, double payment sukses, duplicate entitlement, double reservation, duplicate allocation aktif, atau kombinasi paid + transfer_failed.
- Semua transaction yang gagal meninggalkan status, lock, snapshot nominal, dan audit event konsisten.
- MySQL menggunakan InnoDB dan foreign key/unique constraints benar-benar diterapkan.
- Aggregate `DECIMAL`, COALESCE/SUM, JSON metadata, filter `whereDate`, serta timezone periode memenuhi kontrak laporan.

## 7. Kesimpulan

Fondasi source untuk atomicity, lock, unique constraint, dan idempotensi tersedia dan banyak kontrak bisnisnya telah lulus SQLite. Akan tetapi, kesiapan **MySQL/InnoDB concurrency belum terbukti** karena tidak ada server/schema MySQL terisolasi yang dipakai pada tahap ini.

Risiko paling penting sebelum deployment adalah memastikan engine tabel production benar-benar InnoDB dan menjalankan uji paralel terisolasi untuk tujuh skenario di atas. Tidak ada source code, struktur database, `.env`, migration, seeder, data MySQL development, atau transaksi nyata yang diubah selama audit ini.
