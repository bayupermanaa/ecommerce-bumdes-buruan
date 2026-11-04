# Entitlement & Disbursement Browser E2E — Tahap 5E.3D

Tanggal: 2026-11-02
Environment: SQLite file sementara unik melalui `scripts/e2e/run-isolated.mjs`
Browser: Chromium headless, `workers=1`, `fullyParallel=false`

## Ringkasan

| Cakupan | Hasil |
|---|---|
| Alur entitlement dan pencairan paid — desktop | PASS (1/1) |
| Alur entitlement dan pencairan paid — mobile 390x844 | PASS (1/1) |
| Cancellation melalui browser | NOT TESTED |
| Transfer-failed melalui browser | NOT TESTED |
| Database development `ecommerce_bumdes_restore` | Tidak diakses |

Tidak ada perubahan pada controller, service finansial, model, route, migration, `.env`, atau database development.

## Workflow yang benar-benar diuji

Spec `tests/e2e/entitlement-disbursement-workflow.spec.js` menjalankan alur berikut dengan klik Playwright normal:

1. Pemilik UMKM membuka `/umkm/pencairan` dan melihat saldo tersedia Rp90.
2. Pemilik mengajukan seluruh saldo tersedia.
3. Admin A memulai dan menyelesaikan pemeriksaan.
4. Kepala BUMDes menyetujui pengajuan.
5. Admin A mencatat transfer dengan bukti PNG sintetis dan timestamp UTC yang valid.
6. Admin A tidak mendapatkan tombol verifikasi untuk transfer yang dicatatnya sendiri.
7. Admin B memverifikasi transfer.
8. UMKM melihat status `Selesai Dibayarkan` dan saldo tersedia kembali Rp0.
9. Laporan hak UMKM menampilkan status `Selesai Dicairkan` dan nominal snapshot.
10. Laporan pencairan menampilkan `Berhasil Dicairkan`.

Desktop dan mobile menyelesaikan seluruh langkah tanpa `force click`, DOM `.click()`, atau transfer bank nyata.

## Fixture dan integritas finansial

Fixture sintetis dibuat setelah runner menyalin aplikasi ke direktori `.e2e-tmp`:

- dua Admin BUMDes berbeda untuk independent verification;
- satu UMKM, pelanggan, Kepala BUMDes, produk, dan rekening penerima sintetis terverifikasi;
- order/payment paid Rp100;
- StoreOrder completed dengan snapshot komisi 10,00%, komisi Rp10, dan hak UMKM Rp90;
- entitlement available Rp90 dengan event accrued, eligible, dan reconciled.

Alur browser membuktikan reservation, allocation, approval, recording, independent verification, settlement, dan status paid melalui UI yang sudah ada. Tidak ada perhitungan nominal baru di test.

## Authorization yang terbukti

- Pemilik UMKM dapat mengajukan saldo tokonya sendiri.
- Kepala BUMDes dapat menyetujui pengajuan yang telah diperiksa Admin.
- Admin A dapat mencatat transfer.
- Admin A tidak dapat memverifikasi transfernya sendiri karena tombol tidak ditampilkan.
- Admin B dapat melakukan verifikasi final.

## Cancellation dan transfer failure

Kedua alur belum dijalankan melalui browser. Fixture terisolasi saat ini disiapkan untuk satu alur entitlement paid; alur kedua memerlukan entitlement/store fixture terpisah agar unique constraint dan saldo tidak tercampur.

- **Cancellation:** NOT TESTED melalui browser; service dan focused feature test existing menjadi bukti non-browser.
- **Transfer failed:** NOT TESTED melalui browser; service, controller, policy, dan focused feature test existing menjadi bukti non-browser.
- Tidak ada klaim bahwa cancellation atau transfer-failed telah lulus E2E.

## Temuan dan penyesuaian test

Kegagalan awal berasal dari kontrak test dan diperbaiki minimal:

- selector awal memakai nama route internal, sedangkan action HTML memakai slug Bahasa Indonesia;
- timestamp `datetime-local` awal memakai timezone lokal Windows dan menjadi future relatif terhadap Laravel UTC;
- assertion laporan disesuaikan dengan format nominal/status aktual (`Rp90` dan `Berhasil Dicairkan`).

Tidak ada bug produksi yang diubah.

## Regression dan isolasi

```text
E2E_PROJECT=chromium-desktop, E2E_LIGHT=1 => 1 passed (19.4s)
E2E_PROJECT=chromium-mobile, E2E_LIGHT=1  => 1 passed (18.0s)
```

Setiap run membuat SQLite file unik di `.e2e-tmp/run-*/database/e2e.sqlite`, menjalankan migration hanya pada salinan aplikasi, dan menggunakan storage/session/cache/queue/mail terisolasi. Runner menolak MySQL atau nama database development.

Focused PHPUnit/private-storage regression tidak dijalankan ulang pada tahap ini dan tidak digabungkan sebagai hasil browser E2E.

## File dibuat/diubah

- `scripts/e2e/seed-authenticated-fixtures.php` — akun Admin verifier dan fixture transaksi/entitlement/rekening sintetis.
- `tests/e2e/entitlement-disbursement-workflow.spec.js` — spec browser paid flow dan independent verification.
- `docs/entitlement-disbursement-browser-e2e-5E3D.md` — laporan ini.

Tidak ada screenshot khusus dibuat karena `E2E_LIGHT=1` mematikan screenshot/trace untuk mengurangi resource; hasil PASS berasal dari browser execution aktual.

## Risiko tersisa

- cancellation dan transfer-failed belum terbukti melalui browser;
- satu alur browser tidak membuktikan concurrency MySQL/InnoDB;
- SQLite E2E tidak membuktikan seluruh perilaku locking MySQL;
- tidak ada klaim transfer bank nyata atau penerimaan dana nyata;
- full PHPUnit suite tidak dijalankan ulang pada tahap ini.
