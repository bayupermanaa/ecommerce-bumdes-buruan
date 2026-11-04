# Repository Protection & Backup Preparation — Tahap 6A.1

Tanggal: 2026-11-03

## Status umum

Tahap ini hanya memperkuat perlindungan repository dan menyusun rencana backup.
Tidak ada file yang dihapus atau dipindahkan, tidak ada database yang diakses,
dan tidak ada migration, seeder, atau operasi MySQL yang dijalankan.

## 1. Perubahan `.gitignore`

File yang diubah: `.gitignore`.

Pola yang ditambahkan:

- `.env.*`, dengan pengecualian `!.env.example`.
- `/backup/` dan archive dump (`*.sql.gz`, `*.dump`).
- `/storage/app/private/*` dengan placeholder `.gitignore` tetap dipertahankan.
- `/storage/app/public/*` dengan placeholder `.gitignore` tetap dipertahankan.
- `/storage/logs/*`.
- Storage cache, session, dan testing framework.

Pola existing untuk `.env`, `node_modules`, `vendor`, `/public/storage`,
`/.e2e-tmp`, `/test-results`, build output, key, dan log tetap dipertahankan.

Source code, `database/migrations`, `database/seeders`, `tests`, `docs`,
`composer.json`, `composer.lock`, `package.json`, dan `package-lock.json` tidak
dikecualikan.

Catatan: public product uploads juga diperlakukan sebagai runtime data dan tidak
boleh masuk repository. Placeholder directory tetap dapat dipertahankan.

## 2. File berisiko yang ditemukan

- `backup/ecommerce_bumdes_restore_2026-10-21.sql`: SQL dump lama di dalam
  workspace. Sekarang terlindungi oleh `/backup/`, tetapi belum dihapus atau
  dipindahkan. File ini harus disimpan di backup vault terpisah sebelum repository
  dibuat atau di-push.
- `.env`: ada di workspace dan terlindungi oleh `.gitignore`; nilainya tidak
  dibaca atau dicatat.
- `storage/app/private`: lokasi bukti pembayaran dan pencairan; terlindungi.
- `storage/app/public`: berisi upload runtime; terlindungi.
- `.e2e-tmp/`: beberapa run sementara masih ada; terlindungi dan belum dihapus.
- `test-results/`: artefak Playwright masih ada; terlindungi dan belum dihapus.
- `docs/screenshots/`: harus direview manual sebelum GitHub untuk memastikan
  tidak memuat rekening, bukti privat, credential, atau data pribadi.
- `debug.log` dan log lain: pola `*.log` sudah terlindungi.

Pemeriksaan secret hanya mengidentifikasi lokasi/pola berisiko. Nilai secret,
credential, APP_KEY, token, dan data pelanggan tidak dicetak.

## 3. Rencana backup terbaru

Gunakan `scripts/backup-production.ps1` hanya pada server production dengan
environment proses khusus, bukan `.env` development.

Setiap backup harus menghasilkan satu folder tanggal di luar project dengan:

```text
YYYY-MM-DD/
  database.sql
  private-storage.zip
  public-storage.zip
  manifest.json
```

Isi dan kontrol minimum:

1. `database.sql` dibuat melalui `mysqldump --single-transaction` dengan
   routines, triggers, events, dan utf8mb4.
2. Private proof dan public upload diarsipkan terpisah.
3. Manifest menyimpan waktu UTC, ukuran, dan SHA-256 setiap artefak.
4. Credential hanya berasal dari environment server/secret manager dan tidak
   masuk command line, manifest, atau archive.
5. Folder tanggal yang sudah ada tidak ditimpa.
6. Kegagalan menghasilkan backup parsial yang tidak dipromosikan menjadi folder
   berhasil.
7. Salinan terenkripsi disimpan off-site dengan akses terpisah dari operator
   aplikasi.

Backup belum dijalankan pada tahap ini; tidak ada klaim backup baru berhasil.

## 4. Restore drill plan

Status: **BLOCKED** sampai environment disposable tersedia.

Prosedur yang harus dijalankan administrator pada environment terisolasi:

1. Sediakan database MySQL disposable dan storage staging terpisah.
2. Verifikasi target bukan `ecommerce_bumdes_restore` dan bukan production.
3. Validasi keberadaan, ukuran, SHA-256, dan timestamp manifest.
4. Restore `database.sql` ke database kosong disposable.
5. Restore private dan public archive ke direktori staging yang berbeda.
6. Verifikasi migration history, foreign key, unique constraint, DECIMAL, dan
   jumlah record tanpa membandingkan atau menyalin data development aktif.
7. Verifikasi order/payment, snapshot komisi, entitlement, allocation,
   disbursement, dan event audit.
8. Pastikan private proof tidak menjadi URL publik.
9. Jalankan smoke test read-only dan catat durasi restore.
10. Simpan hasil drill; jangan menghapus backup sumber.

## 5. Retention dan akses

Gunakan kebijakan yang telah didokumentasikan:

- Daily: 35 hari.
- Weekly: 12 minggu.
- Monthly: 12 bulan.
- RPO awal: maksimal 24 jam.
- RTO awal: maksimal 4 jam.

Retention deletion belum boleh diaktifkan sebelum restore drill berhasil dan
disetujui pemilik operasional. Backup harus terenkripsi saat transit dan saat
tersimpan, dengan akses least privilege dan audit akses.

## 6. Validasi perlindungan repository

- `.env` dan file environment rahasia: terlindungi.
- SQL dump dan backup archive: terlindungi oleh `/backup/` serta pola archive.
- Private proof: terlindungi oleh `storage/app/private/*`.
- Upload publik runtime: terlindungi oleh `storage/app/public/*`.
- Log, cache, session, E2E temporary, dan test results: terlindungi.
- Source, migration, seeder, test, dan docs: tetap dapat diikutkan repository.
- Git metadata belum tersedia; `git init`, `git add`, commit, dan push tidak
  dilakukan.

## 7. Risiko tersisa

- SQL dump lama masih berada di workspace secara fisik; `.gitignore` mencegah
  tracking baru, tetapi tidak menghapus risiko operasional atau file yang sudah
  pernah ter-track pada repository lain.
- Tidak ada bukti checksum/manifest untuk dump lama.
- Restore drill belum dapat dilakukan tanpa disposable environment.
- Isi screenshot dan dokumen perlu review manual sebelum publikasi ke GitHub.
- Status jumlah record database tetap tidak diketahui karena database development
  tidak diakses.

## 8. File berubah

- `.gitignore` diubah.
- `docs/repository-protection-backup-plan-6A1.md` dibuat.

Tidak ada perubahan pada `.env`, database, storage, source finansial, migration,
seeder, atau data transaksi.
