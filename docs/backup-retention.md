# Retensi dan verifikasi backup

Script backup tidak menghapus arsip apa pun. Penghapusan hanya boleh diaktifkan setelah restore drill berhasil dan disetujui pemilik operasional.

- Daily: simpan 35 hari.
- Weekly: simpan 12 minggu.
- Monthly: simpan 12 bulan.

Simpan satu salinan terenkripsi di lokasi off-site dengan akses terpisah dari operator aplikasi. Setiap backup harus memiliki checksum dan manifest. Lakukan restore drill minimal setiap kuartal ke database staging terisolasi, lalu verifikasi foreign key, snapshot komisi, entitlement, allocation, disbursement, dan event audit.

Target awal yang perlu disetujui pemilik bisnis: RPO maksimal 24 jam dan RTO maksimal 4 jam.
