@php
    $role = auth()->user()?->role;
    $buyer = [
        ['Akun dan produk', 'Daftar sebagai pelanggan atau masuk. Cari produk di katalog, buka detail, dan pilih ukuran aktif jika produk mempunyai variasi.', 'catalog.index', 'Buka katalog'],
        ['Keranjang dan checkout', 'Masukkan produk ke keranjang. Periksa ukuran, jumlah, dan harga sebelum checkout. Pengambilan di toko tersedia; tarif pengiriman belum tersedia sehingga opsi pengiriman belum dapat digunakan.', 'cart.index', 'Buka keranjang'],
        ['Pembayaran', 'Dari detail pesanan, ajukan pembayaran melalui transfer bank dengan bukti yang diminta, atau tunai saat pickup sesuai pilihan yang tersedia. Pembayaran baru dianggap berhasil setelah verifikasi Admin.', 'orders.index', 'Lihat pesanan'],
        ['Pesanan dan pembatalan', 'Pantau status pembayaran serta timeline tiap toko pada detail pesanan. Pembatalan pelanggan hanya tersedia sebelum pemrosesan pembayaran dimulai.', 'orders.index', 'Riwayat pesanan'],
        ['Chat dan penawaran custom', 'Dari detail produk, buka chat Penjual. Anda dapat menerima atau menolak penawaran yang masih berlaku; penawaran yang diterima dapat dilanjutkan ke checkout custom. Harga katalog tidak berubah.', 'catalog.index', 'Cari produk'],
        ['Notifikasi', 'Setelah masuk, buka Pusat Notifikasi untuk melihat pembaruan akun Anda. Klik notifikasi untuk membuka halaman terkait.', 'notifications.index', 'Lihat notifikasi'],
    ];
    $seller = [
        ['Mulai berjualan', 'Daftar sebagai pelanggan, lalu buka Mulai Berjualan dan isi informasi toko. Tunggu verifikasi Admin; pengajuan yang ditolak dapat diperbaiki dan diajukan ulang.', 'seller-application.show', 'Status toko'],
        ['Toko dan produk', 'Setelah toko disetujui, kelola profil toko dan tambah produk. Produk baru menunggu verifikasi. Perubahan produk yang telah disetujui melalui pengajuan revisi; perubahan variasi produk approved belum didukung langsung.', 'umkm.products.index', 'Produk Saya'],
        ['Stok dan variasi', 'Untuk produk baru yang mendukung variasi, isi label ukuran, SKU, harga, dan stok tiap variasi. Stok produk yang sudah disetujui dapat dikelola sesuai form yang tersedia.', 'umkm.products.index', 'Kelola produk'],
        ['Pesanan dan chat', 'Lihat pesanan toko Anda. Pemrosesan dimulai setelah pembayaran dikonfirmasi. Gunakan chat untuk membalas pembeli dan membuat penawaran custom dari produk toko yang disetujui.', 'umkm.orders.index', 'Pesanan masuk'],
        ['Rekening dan pencairan', 'Kelola rekening pencairan, periksa perjanjian komisi dan pengajuan pencairan. Persetujuan serta verifikasi transfer dilakukan oleh petugas sesuai alur yang berlaku.', 'umkm.disbursements.index', 'Pencairan Dana'],
        ['Notifikasi', 'Buka Pusat Notifikasi untuk melihat pengajuan, chat, dan pembaruan pesanan yang ditujukan kepada akun Anda.', 'notifications.index', 'Lihat notifikasi'],
    ];
    $admin = [
        ['Verifikasi toko dan produk', 'Tinjau pengajuan toko, produk baru, dan revisi. Periksa detail pengajuan sebelum menyetujui atau menolak melalui halaman masing-masing.', 'admin.umkm-stores.index', 'Verifikasi toko'],
        ['Pembayaran', 'Periksa pengajuan dan bukti pembayaran pada halaman pembayaran. Konfirmasi hanya setelah pemeriksaan sesuai prosedur.', 'admin.payments.index', 'Verifikasi pembayaran'],
        ['Pengaturan operasional', 'Kelola kategori, rekening BUMDes, dan perjanjian komisi melalui menu Admin yang tersedia.', 'admin.categories.index', 'Kategori Produk'],
        ['Keuangan dan pencairan', 'Tinjau rekonsiliasi hak Penjual, proses administrasi pencairan, dan pemeriksaan audit keuangan. Persetujuan Kepala BUMDes dan verifikasi transfer mengikuti kewenangan terpisah.', 'admin.disbursements.index', 'Administrasi pencairan'],
        ['Notifikasi', 'Lihat pengajuan baru dan pembaruan yang memerlukan perhatian pada Pusat Notifikasi.', 'notifications.index', 'Lihat notifikasi'],
    ];
    $head = [
        ['Monitoring', 'Dashboard Kepala BUMDes menampilkan ringkasan, laporan transaksi, komisi, hak Penjual, dan pencairan sesuai data aplikasi.', 'kepala-bumdes.dashboard', 'Dashboard eksekutif'],
        ['Persetujuan pencairan', 'Tinjau pengajuan pencairan melalui halaman persetujuan. Pencatatan dan verifikasi transfer tetap menjadi proses Admin yang terpisah.', 'kepala-bumdes.disbursements.index', 'Persetujuan pencairan'],
    ];
    $sections = match ($role) {
        'admin_bumdes' => ['Admin BUMDes' => $admin],
        'penjual', 'pemilik_umkm' => ['Penjual' => $seller, 'Berbelanja' => $buyer],
        'kepala_bumdes' => ['Kepala BUMDes' => $head],
        'kurir' => ['Kurir' => []],
        default => ['Pelanggan' => $buyer],
    };
@endphp
<x-catalog-layout title="Pusat Bantuan">
    <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="rounded-3xl bg-slate-950 px-5 py-8 text-white sm:px-8">
            <p class="text-sm font-semibold text-emerald-300">BUMDes Desa Buruan</p>
            <h1 class="mt-2 text-2xl font-bold sm:text-3xl">Pusat Bantuan</h1>
            <p class="mt-3 max-w-2xl text-sm text-slate-300">Panduan langkah utama sesuai fitur yang tersedia untuk akun Anda.</p>
            @guest
                <div class="mt-5 flex flex-wrap gap-3"><a href="{{ route('register') }}" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-400 px-4 text-sm font-semibold text-slate-950">Daftar</a><a href="{{ route('login') }}" class="inline-flex min-h-11 items-center rounded-xl border border-white/40 px-4 text-sm font-semibold text-white">Masuk</a></div>
            @endguest
        </div>
        @foreach ($sections as $heading => $items)
            <section class="mt-8" aria-label="Panduan {{ $heading }}">
                <h2 class="text-xl font-bold text-slate-950">{{ $heading }}</h2>
                @if ($items === [])
                    <p class="mt-4 rounded-2xl border border-slate-200 bg-white p-5 text-sm text-slate-600">Panduan tugas pengiriman belum tersedia karena fitur operasional Kurir belum dikembangkan.</p>
                @else
                    <div class="mt-4 space-y-3">
                        @foreach ($items as [$title, $description, $routeName, $linkLabel])
                            <details class="group rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                                <summary class="flex cursor-pointer items-center justify-between gap-4 text-sm font-semibold text-slate-900 marker:hidden sm:text-base">{{ $title }} <span class="text-emerald-700 group-open:rotate-45" aria-hidden="true">+</span></summary>
                                <p class="mt-3 text-sm leading-6 text-slate-600">{{ $description }}</p>
                                @if (auth()->check() || in_array($routeName, ['catalog.index'], true))
                                    <a href="{{ route($routeName) }}" class="mt-4 inline-flex min-h-11 items-center rounded-xl bg-emerald-50 px-4 text-sm font-semibold text-emerald-800 hover:bg-emerald-100">{{ $linkLabel }} <span class="ml-2" aria-hidden="true">→</span></a>
                                @else
                                    <a href="{{ route('login') }}" class="mt-4 inline-flex min-h-11 items-center rounded-xl bg-emerald-50 px-4 text-sm font-semibold text-emerald-800 hover:bg-emerald-100">Masuk untuk melanjutkan</a>
                                @endif
                            </details>
                        @endforeach
                    </div>
                @endif
            </section>
        @endforeach
    </main>
</x-catalog-layout>
