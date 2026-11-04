# Financial & Business Scenario Testing — Tahap 9E.5B

Tanggal: 31 Oktober 2026
Metode: automated feature/service test pada SQLite `:memory:`; audit source/test tanpa akses MySQL development, transfer uang nyata, email nyata, atau notifikasi nyata.

## 1. Batas lingkungan dan hasil eksekusi

`phpunit.xml` diverifikasi menetapkan `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `MAIL_MAILER=array`, dan `QUEUE_CONNECTION=sync`.

Kelompok pengujian bisnis/finansial berikut dijalankan:

```text
OrderCheckoutTest, CheckoutTest, AdminPaymentVerificationTest,
CashPaymentReceiptTest, PaymentFulfillmentIntegrationTest,
StoreOrderFulfillmentServiceTest, CommissionAgreementTest,
UmkmEntitlementFoundationTest, UmkmEntitlementServiceTest,
AdminUmkmEntitlementReconciliationTest, UmkmDisbursementRequestTest,
UmkmDisbursementApprovalTest, UmkmDisbursementCancellationTest,
UmkmDisbursementFailureEvidenceTest,
UmkmDisbursementFailureConfirmationTest,
KepalaBumdesCommissionReportTest,
KepalaBumdesUmkmFinancialReportTest,
FinancialExceptionDetectorIntegrationTest
```

Hasil: **119 passed, 980 assertions, 4,44 detik**.

Fixture yang disebutkan di bawah hanya berada di SQLite in-memory dan dihancurkan setelah test. Nilai nominal ditulis sebagai string DECIMAL, bukan float; tidak ada nilai atau transaksi database development yang dipakai.

## 2. Aktor dan status utama

| Aktor | Kewenangan yang diuji |
| --- | --- |
| Pelanggan | Checkout pickup, mengajukan metode pembayaran, melihat pesanan sendiri |
| Pemilik UMKM | Menyiapkan/menyelesaikan StoreOrder milik toko, mengajukan/membatalkan pencairan milik toko |
| Admin BUMDes | Verifikasi/penolakan pembayaran, mencatat penerimaan tunai, rekonsiliasi hak, review pengajuan, pencatatan/konfirmasi tertentu atas transfer |
| Kepala BUMDes | Persetujuan/penolakan pengajuan pencairan setelah review dan melihat laporan read-only |
| Admin BUMDes kedua | Verifikasi transfer atau konfirmasi kegagalan, berbeda dari Admin pencatat bukti |

Ringkasan transisi yang diuji:

```text
Order: pending_payment -> in_fulfillment -> completed
StoreOrder: pending_payment -> awaiting_processing -> processing
            -> ready_for_pickup -> completed
Payment: pending_confirmation -> paid | rejected
Entitlement: accrued -> eligible -> available -> reserved -> settled
             (held dipertahankan; rejected/cancelled/transfer_failed melepas reserved -> available)
Disbursement: requested -> under_review -> approved -> paid
              requested/under_review -> rejected | cancelled
              approved -> transfer_failed
```

## 3. Matriks skenario yang terbukti oleh automated test

| # | Skenario, aktor, dan kondisi awal | Tindakan serta status sebelum → sesudah | Nominal, event, allocation/lock, idempotensi | Bukti test |
| --- | --- | --- | --- | --- |
| 1 | Pelanggan; produk/toko approved, stok tersedia, cart satu UMKM. | Checkout pickup membuat Order `unpaid/pending_payment`, StoreOrder `pending_payment`, lalu pesanan menunggu pengajuan pembayaran. | Harga/total diambil dari database dan disimpan sebagai snapshot DECIMAL; stok berkurang sekali ketika order berhasil dibuat. Token checkout sama tidak membuat order kedua dan tidak mengurangi stok dua kali. | `OrderCheckoutTest`, `CheckoutTest` |
| 2 | Pelanggan; dua produk dari dua UMKM approved. | Checkout pickup membuat satu Order dengan dua StoreOrder, masing-masing subtotal/total sendiri. | Fixture: 2 × Rp15.000,00 + 1 × Rp30.000,00 = grand total `60000.00`; dua StoreOrder dan dua item; stok menjadi 3 dan 3. Tidak ada penjumlahan ulang grand total per toko. | `test_customer_can_create_a_multi_store_pickup_order_and_stock_is_allocated`, `test_multi_store_checkout_calculates_each_store_separately...` |
| 3 | Pelanggan memiliki Order unpaid. | Pelanggan mengajukan pembayaran; Admin mengonfirmasi hanya bila valid. Payment `pending_confirmation → paid`, Order payment status menjadi `paid`; fulfillment diaktifkan setelah konfirmasi. | Fixture cash: `30000.00`; transfer/tunai tervalidasi terpisah. Konfirmasi ulang tidak membuat log fulfillment ganda dan tidak mengubah stok/snapshot. | `AdminPaymentVerificationTest`, `CashPaymentReceiptTest`, `PaymentFulfillmentIntegrationTest` |
| 4 | Payment pending, ditolak, cancelled, atau belum memenuhi syarat penerimaan tunai. | Pembayaran tidak dikonfirmasi/ditolak; StoreOrder tetap `pending_payment`, tidak masuk fulfillment. | Tidak terbentuk entitlement dari payment pending/inkonsisten. Riwayat penolakan dipertahankan; penerimaan tunai fisik harus tercatat sebelum konfirmasi. | `test_rejected_or_unconfirmed_payment_does_not_activate_fulfillment`, `test_pending_or_inconsistent_payments_cannot_create_entitlements`, `CashPaymentReceiptTest` |
| 5 | Admin BUMDes dan payment transfer valid, atau tunai yang penerimaan fisiknya sudah tercatat. | Admin mengonfirmasi sekali; seluruh StoreOrder `pending_payment → awaiting_processing`; Order menjadi `in_fulfillment`. | Proses transaksi atomik; kegagalan fulfillment mensimulasikan rollback payment dan semua StoreOrder. Stock, harga, dan snapshot komisi tidak berubah saat konfirmasi. | `PaymentFulfillmentIntegrationTest`, `AdminPaymentVerificationTest` |
| 6 | Pemilik toko; StoreOrder telah paid/`awaiting_processing`. | Transisi berurutan: `awaiting_processing → processing → ready_for_pickup → completed`. Order utama baru `completed` bila semua StoreOrder selesai. | Status lompat/unpaid/terminal ditolak. Setiap perubahan menulis `store_order_status_logs`; retry transisi tidak menduplikasi log. | `StoreOrderFulfillmentServiceTest`, `UmkmStoreOrderFulfillmentActionTest` |
| 7 | Perjanjian komisi aktif pada tanggal checkout. | Checkout menyimpan snapshot per StoreOrder; perubahan perjanjian setelah itu tidak mengubah snapshot. | Fixture: subtotal `25000.00`, persentase `12.50`, komisi `3125.00`, hak UMKM `21875.00`, status `calculated`. Tanpa perjanjian aktif: `unconfigured` dan nominal snapshot `NULL`, bukan persentase asumsi. | `OrderCheckoutTest`, `CommissionAgreementTest`, laporan komisi |
| 8 | Order paid sah dengan StoreOrder berkomisi `calculated`. | Konfirmasi payment membentuk tepat satu entitlement per StoreOrder, `accrued`. | Fixture dua toko: per entitlement `source_umkm_amount=21875.00`, `source_commission_amount=3125.00`, `source_commission_percentage=12.50`. Retry payment confirmation tetap dua entitlement/dua event, bukan duplikasi. | `UmkmEntitlementServiceTest` |
| 9 | Entitlement accrued dengan payment paid dan StoreOrder selesai. | Completion mengubah `accrued → eligible`; Admin merekonsiliasi `eligible → available`. Held tidak dipromosikan otomatis. | Event entitlement ditulis idempoten; retry rekonsiliasi tidak menggandakan event. Non-Admin tidak dapat rekonsiliasi. | `UmkmEntitlementServiceTest`, `AdminUmkmEntitlementReconciliationTest` |
| 10 | Pemilik UMKM, rekening penerima aktif dan terverifikasi, seluruh entitlement yang tersedia. | Pengajuan seluruh saldo membuat Disbursement `requested`; entitlement `available → reserved`. | Fixture: requested amount `123.45`, satu allocation dan snapshot rekening. `active_request_lock=store:<id>` dan `entitlement_reservation_lock=entitlement:<id>` aktif. Key sama mengembalikan request yang sama; request aktif kedua ditolak. | `UmkmDisbursementRequestTest` |
| 11 | Disbursement `requested`, entitlement reserved. | Admin mulai/selesai review `requested → under_review`; Kepala menyetujui `under_review → approved`. | Event review/approval idempoten; entitlement tetap `reserved`, allocation dan kedua lock tetap aktif selama menunggu realisasi transfer. Approval sebelum review selesai ditolak. | `UmkmDisbursementApprovalTest` |
| 12 | Disbursement `approved`, snapshot rekening tersedia. | Admin pencatat menyimpan referensi, nominal sama persis dengan `requested_amount`, waktu, dan bukti privat; status tetap `approved`. | Fixture `requested_amount=90.00`, `transfer_amount=90.00`. Event `transfer_recorded`; bukti disimpan pada disk lokal/private. Tidak ada settlement atau release hanya karena transfer dicatat. | `UmkmDisbursementApprovalTest`, `UmkmDisbursementFailureEvidenceTest` |
| 13 | Transfer telah dicatat oleh Admin A dan Disbursement `approved`. | Admin B yang berbeda memverifikasi: `approved → paid`; entitlement `reserved → settled`. | `active_request_lock` dan allocation `entitlement_reservation_lock` dilepas. Event transfer verified/paid dan entitlement settled tercatat; Admin A ditolak sebagai verifier. Re-verify tidak memindahkan nilai dua kali. | `UmkmDisbursementApprovalTest`, `UmkmDisbursementFailureConfirmationTest` |
| 14 | Transfer telah dicatat, lalu bukti kegagalan bank direkam oleh Admin A. | Pencatatan bukti mempertahankan `approved/reserved`; Admin B mengonfirmasi final `approved → transfer_failed`. | Event `failure_evidence_recorded`, lalu `transfer_failed` dan `reservation_released`. Lock aktif dilepas, entitlement kembali `available`, allocation historis tetap ada. Retry tidak double release; Admin pencatat tidak boleh konfirmasi sendiri. | `UmkmDisbursementFailureEvidenceTest`, `UmkmDisbursementFailureConfirmationTest` |
| 15 | Pemilik toko, Disbursement `requested`, belum ada transfer tercatat. | Pembatalan `requested → cancelled`. | `active_request_lock` dan reservation lock dilepas; entitlement `reserved → available`; allocation historis tetap satu. Event `cancelled` dan `reservation_released` ada; retry aman. Approved/paid atau transfer tercatat ditolak. | `UmkmDisbursementCancellationTest` |
| 16 | Kepala BUMDes dengan data paid/commission calculated, entitlement berbagai status, dan disbursement berbagai status. | Membuka laporan/read-only export dengan filter. | Laporan komisi hanya memakai paid + calculated snapshot; entitlement memakai `source_umkm_amount` dan memisahkan NULL; pencairan memakai `requested_amount` dan hanya `paid` final. Pagination tidak mengubah ringkasan dan allocation historis tidak menggandakan total. | `KepalaBumdesCommissionReportTest`, `KepalaBumdesUmkmFinancialReportTest` |
| 17 | Data finansial valid dan sengaja dibuat inkonsisten di fixture SQLite. | Detector scan read-only memeriksa 11 kategori Batch A–D. | Stable key, filter, chunking, urutan, privasi context, dan tidak ada mutasi diuji. Detector tidak melakukan reconcile/release otomatis dan tidak membocorkan rekening/path proof. | `FinancialExceptionDetectorIntegrationTest`, `FinancialExceptionDetectorBatchA-DTest` |

## 4. Detail event dan lock yang diuji

| Proses | Event/audit yang dibuktikan | Status allocation/lock setelah proses |
| --- | --- | --- |
| Pembentukan hak | Entitlement event per entitlement; unique `store_order_id` dan idempotency key | Belum ada allocation; entitlement `accrued` |
| Completion dan rekonsiliasi | Event transisi eligible dan available | Belum ada reservation lock sebelum request |
| Request pencairan | Entitlement event `reserved`; disbursement event request | Allocation dibuat; `entitlement_reservation_lock` dan `active_request_lock` aktif |
| Review/approval | Event review dan approval | Allocation/lock tetap aktif; entitlement tetap reserved |
| Transfer sukses | Event `transfer_recorded`, `transfer_verified`, `paid`; entitlement settled | Allocation tetap historis tetapi lock dilepas; entitlement settled; active lock dilepas |
| Transfer gagal final | `failure_evidence_recorded`, `transfer_failed`, `reservation_released` | Allocation tetap historis; entitlement kembali available; kedua lock dilepas |
| Cancel/reject | Event terminal dan `reservation_released` | Allocation tetap historis; entitlement available; kedua lock dilepas |

Event model menggunakan `idempotency_key` dan layanan memakai transaksi/row locking. Constraint foundation juga diuji untuk foreign key, unique active lock, pair allocation, dan idempotency record.

## 5. Skenario yang memerlukan uji manual/UAT

Hal berikut tidak boleh dinyatakan sudah diuji hanya dari SQLite automation:

1. Pengalaman browser lengkap pelanggan: memilih pickup, validasi field, upload file, pesan flash, responsivitas, dan aksesibilitas keyboard.
2. Operasional Admin BUMDes: mencocokkan bukti transfer dengan mutasi bank asli, mencatat penerimaan tunai fisik, dan menjalankan prinsip empat mata untuk transfer/kegagalan.
3. Operasional Pemilik UMKM: kesiapan barang, penyerahan barang di pickup, serta persetujuan bahwa pesanan benar-benar selesai.
4. Kebenaran data rekening BUMDes dan rekening penerima UMKM yang akan dipakai klien.
5. Kualitas file bukti nyata, virus scanning pada infrastruktur, kapasitas storage, permission, dan retensi dokumen privat.
6. Validasi tampilan laporan/export dengan aplikasi spreadsheet yang dipakai operator, meskipun proteksi formula CSV sudah diuji otomatis.
7. UAT hak akses seluruh role dengan akun non-produksi/terkontrol, termasuk pembuktian bahwa menu dan redirect benar pada browser.

Tidak ada transfer bank, pembayaran tunai fisik, email pelanggan, maupun notifikasi pelanggan nyata yang dilakukan pada tahap ini.

## 6. Skenario yang memerlukan MySQL/InnoDB terisolasi

SQLite in-memory memadai untuk kontrak aplikasi tetapi tidak membuktikan seluruh perilaku MySQL. Sebelum production, lakukan uji terisolasi untuk:

1. Konkurensi checkout produk stok rendah: dua request bersamaan tidak boleh menghasilkan stok negatif atau dua Order untuk checkout token sama.
2. Konkurensi konfirmasi pembayaran yang sama: payment/fulfillment/entitlement harus tetap sekali dan log tidak ganda.
3. Konkurensi request pencairan pada toko yang sama: hanya satu `active_request_lock` aktif dan tidak ada entitlement terreservasi dua kali.
4. Konkurensi transfer verify versus failure confirm: hanya satu status terminal yang dapat menang (`paid` atau `transfer_failed`).
5. Urutan row lock dan potensi deadlock pada order multi-toko dan multi-allocation.
6. Perilaku MySQL terhadap DECIMAL, unique index, foreign key, nullable audit fields, timezone, dan query agregasi laporan pada volume data realistis.
7. Restore backup yang mencakup database serta storage private/public, lalu verifikasi event, allocation, snapshot, dan idempotency key secara read-only.

Uji tersebut harus memakai database MySQL non-production/non-development yang terisolasi. Jangan memakai `ecommerce_bumdes_restore` atau data transaksi klien.

## 7. Risiko yang belum dapat dibuktikan

| Risiko | Dampak | Pengendalian yang direkomendasikan |
| --- | --- | --- |
| Semantik locking SQLite berbeda dengan MySQL/InnoDB | Race condition/deadlock baru dapat terlihat saat load paralel | Uji concurrency pada MySQL terisolasi dan pantau error/deadlock setelah go-live |
| Bukti transfer adalah dokumen, bukan konfirmasi bank absolut | Risiko paid/failed salah dicatat oleh operator | SOP empat mata, rekonsiliasi mutasi bank, audit log, dan pemisahan Admin pencatat/verifier |
| Nilai snapshot atau entitlement historis yang telah rusak sebelum sistem event | Laporan/detector dapat menandai exception atau membutuhkan investigasi | Gunakan Financial Exception Detector read-only; jangan auto-fix tanpa prosedur audit |
| Upload/private storage bergantung pada ACL server | Kebocoran bukti privat jika web server salah konfigurasi | UAT URL langsung, ACL, backup encrypted, dan review document root production |
| Export CSV bergantung pada aplikasi pembuka | Formula/format dapat berperilaku berbeda antar spreadsheet | Gunakan export hanya dari role berwenang; pertahankan sanitasi formula dan edukasi operator |
| Periode laporan dan timezone operasional | Pengelompokan tanggal dapat berbeda di batas hari | Tetapkan timezone bisnis dan prosedur cutoff sebelum deployment |

## 8. Kesimpulan

Semua 17 skenario memiliki bukti automated test yang relevan pada SQLite in-memory, termasuk status penting, nominal DECIMAL fixture, isolation role, idempotency, event audit, allocation/lock, dan pencegahan double counting. Tidak ada kegagalan dalam kelompok test terarah.

Status ini adalah **siap untuk melanjutkan UAT terisolasi dan uji MySQL/InnoDB**, bukan pernyataan bahwa pembayaran/transfer nyata atau production end-to-end telah diuji. Tidak ada kode produksi, MySQL development, `.env`, migration, seeder, atau file aplikasi selain laporan ini yang diubah.
