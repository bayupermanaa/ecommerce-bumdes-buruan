# Main Database Cleanup Preflight — Tahap 6C

Tanggal: 2026-11-03

## Keputusan

**BLOCKED — belum boleh menjalankan cleanup.** Preflight database tidak dapat dijalankan pada sesi ini karena helper shell gagal sebelum proses dibuat dengan `helper_unknown_error: setup refresh had errors`.

Tidak ada `DELETE`, `TRUNCATE`, `DROP`, `migrate:fresh`, `db:wipe`, migration, seeder, restore, atau perubahan data yang dilakukan.

## Kondisi belum terverifikasi

- Nama database aktif harus tepat `ecommerce_bumdes_restore`.
- Jumlah akun `users`: **UNKNOWN**.
- Jumlah record seluruh tabel: **UNKNOWN**.
- Foreign key dan dependensi aktual: **UNKNOWN**.
- Ada/tidaknya transaksi nyata yang wajib dipertahankan: **UNKNOWN**.
- Kesesuaian backup dengan database utama: **NOT VERIFIED**.

Backup yang diketahui tersedia dari audit sebelumnya adalah SQL dump `D:\Backup_Ecommerce\ecommerce_bumdes_restore_2026-11-03.sql`, checksum CSV, dan arsip storage. Restore terpisah dilaporkan berhasil, tetapi kesesuaian seluruh data dengan database utama belum terbukti.

## Data yang direncanakan dipertahankan

- `users`, termasuk seluruh ID, password hash, role, dan informasi akun.
- `migrations`.
- Struktur schema, foreign key, index, dan konfigurasi database.

## Kandidat operasional

Masih kandidat, bukan izin eksekusi:

- UMKM/toko, produk, kategori operasional, revisi produk.
- Cart dan cart items.
- Orders, order items, store orders, dan status log.
- Payments dan payment logs.
- Commission agreements dan data komisi.
- Entitlements dan entitlement events.
- Disbursements, allocations, dan disbursement events.
- Rekening BUMDes, rekening penerima UMKM, dan log operasional lain.

Nama tabel final, row count, foreign key, dan dependensi harus dibaca dari database aktif. Financial audit trail tidak boleh dianggap aman dihapus tanpa persetujuan pemilik data.

## Syarat sebelum READY

1. Koneksi read-only berhasil dan `SELECT DATABASE()` tepat menghasilkan `ecommerce_bumdes_restore`.
2. Inventaris tabel, row count, foreign key, dan constraint selesai.
3. Backup SQL, public/private storage, manifest, dan checksum lengkap/valid.
4. Restore ke database terpisah telah diuji.
5. Backup dibandingkan dengan sumber untuk schema dan data penting.
6. Tidak ada transaksi nyata yang wajib dipertahankan, atau ada keputusan tertulis tentang pengecualiannya.
7. Rencana urutan cleanup ditinjau dengan foreign key tetap aktif.
8. Pengguna memberi persetujuan eksekusi terakhir.

## Rencana eksekusi

Tidak ada skrip cleanup yang dibuat karena syarat preflight belum terbukti. Setelah READY, eksekusi harus memeriksa nama database, melindungi `users`/`migrations`, mencatat count sebelum/sesudah, dan menjalankan verifikasi post-cleanup.

## File/database berubah

- `docs/main-database-cleanup-preflight-6C.md` dibuat.
- Tidak ada database, record, `.env`, backup, storage, source, migration, atau seeder yang diubah.
