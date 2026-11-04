# Negative Financial Browser E2E — Tahap 5E.6

Tanggal validasi: 2026-11-02

## Ringkasan

Workflow negatif yang tersedia dan dapat dijalankan melalui UI telah diuji pada Chromium headless dengan database SQLite sementara. Tidak ada koneksi ke `ecommerce_bumdes_restore`, tidak ada transfer bank atau email nyata, dan tidak ada perubahan pada `.env` development.

Status keseluruhan: **PASS untuk cakupan yang tersedia; sebagian skenario tetap NOT TESTED secara browser**.

## Environment dan isolasi

- Playwright: Chromium desktop, serial, satu worker.
- Database: SQLite file sementara di `.e2e-tmp/run-*`.
- Storage, session, cache, dan mail: direktori sementara E2E.
- Migration hanya dijalankan terhadap database SQLite sementara oleh runner E2E.
- Tidak ada migration, seeder, atau operasi terhadap MySQL development.
- Tidak menggunakan force click, DOM `.click()`, atau arbitrary sleep.

## Hasil pengujian

| Pemeriksaan | Hasil |
|---|---:|
| Negative financial Playwright | 1 passed |
| Assertion state SQLite pasca-E2E | 13 passed |
| Focused backend cancellation/failure tests | 5 passed, 39 assertions |
| Full PHPUnit suite | 272 passed, 0 failed, 2.128 assertions |

Focused backend tests yang dijalankan:

- `UmkmDisbursementCancellationTest`
- `UmkmDisbursementFailureEvidenceTest`
- `UmkmDisbursementFailureConfirmationTest`

## Skenario browser

### Cancellation

**PASS.** Pemilik UMKM mengajukan pencairan, membuka pengajuan berstatus `requested`, lalu membatalkannya melalui form UI dengan konfirmasi native browser. Status menjadi `cancelled`, aksi pembatalan tidak tersedia lagi, dan state SQLite menunjukkan entitlement kembali `available`.

Percobaan pada status terminal ditolak oleh state UI/backend. Allocation historis tetap dipertahankan dan pelepasan tidak digandakan.

### Transfer failed

**PASS.** Alur yang dijalankan:

1. Admin A melakukan review.
2. Kepala BUMDes menyetujui.
3. Admin A mencatat transfer dengan bukti sintetis.
4. Admin A tidak memperoleh tombol verifikasi transfer sendiri.
5. Admin A mencatat bukti kegagalan.
6. Admin B mengonfirmasi kegagalan.

Status akhir menjadi `transfer_failed`, entitlement kembali `available`, dan tidak ada status `paid`.

### Self-verification

**PASS.** Admin A yang mencatat transfer tidak dapat memverifikasi transfernya sendiri. Verifikasi final memerlukan Admin berbeda.

### Double-submit

**NOT TESTED secara eksplisit melalui browser.** UI menyembunyikan aksi setelah transisi terminal, sehingga pengujian tidak melakukan klik kedua secara paksa. Idempotency backend tetap terverifikasi oleh focused tests dan full suite: retry cancellation/failure tidak menggandakan release atau event.

### Cross-role dan cross-UMKM

**Backend PASS; browser cross-UMKM NOT TESTED.** Authorization lintas role dan kepemilikan diuji oleh feature tests. Fixture browser negatif ini memakai satu UMKM untuk menjaga alur tetap terisolasi dan deterministik.

## Verifikasi state SQLite

State setelah workflow negatif memverifikasi:

- 2 payment.
- 2 entitlement.
- 2 disbursement.
- 4 allocation historis.
- Status disbursement: `cancelled` dan `transfer_failed`.
- Status entitlement: seluruhnya `available`.
- Event `cancelled`, `failure_evidence_recorded`, dan `transfer_failed` tercatat.
- Reservation release tercatat 4 kali sesuai dua siklus reservasi historis.
- Tidak ada disbursement `paid`.
- Snapshot source entitlement dan allocation tetap `90.00`.

Tidak ada perubahan pada order, payment snapshot, nominal, atau allocation historis selain state yang memang dihasilkan workflow sah.

## Temuan dan perbaikan

Ditemukan bug UI pada halaman Admin pencairan: setelah status berubah menjadi `transfer_failed`, form konfirmasi kegagalan masih ditampilkan karena policy masih mengizinkan pemeriksaan idempoten oleh admin lain.

Perbaikan minimal dilakukan pada `resources/views/admin/disbursements/index.blade.php`.

Form konfirmasi kini hanya dirender ketika status masih `approved`; status `transfer_failed` menampilkan informasi terminal tanpa tombol mutasi. Policy backend tidak diperketat secara keliru sehingga retry idempoten existing tetap terjaga.

## File yang dibuat/diubah

- `tests/e2e/negative-financial-workflow.spec.js`
- `scripts/e2e/seed-authenticated-fixtures.php`
- `scripts/e2e/assert-negative-financial-state.php`
- `resources/views/admin/disbursements/index.blade.php`
- `docs/negative-financial-browser-e2e-5E6.md`

Tidak ada migration, route, service keuangan, model, `.env`, atau database development yang diubah.

## Risiko tersisa

- Concurrency MySQL/InnoDB belum dibuktikan oleh E2E SQLite.
- Double-submit eksplisit pada browser belum diuji sebagai dua submit aktual; perlindungan idempotency backend sudah lulus test.
- Cross-UMKM browser E2E belum dijalankan; backend authorization sudah lulus.
- Tidak ada transfer bank atau payment gateway nyata yang diuji.
- Screenshot/trace tidak menjadi artefak wajib pada run ringan ini.

Tahap 5E.6 selesai untuk cakupan E2E negatif yang tersedia, dengan gap browser di atas tetap didokumentasikan sebagai NOT TESTED.
