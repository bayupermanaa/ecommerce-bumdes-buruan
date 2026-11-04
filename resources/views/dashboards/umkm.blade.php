<x-umkm-layout title="Dashboard UMKM">
    <div class="space-y-8">
        <section class="rounded-2xl bg-gradient-to-r from-slate-950 to-slate-800 p-6 text-white shadow-xl sm:p-8">
            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-300">Selamat datang kembali</p>
            <h2 class="mt-2 text-2xl font-bold sm:text-3xl">{{ auth()->user()->name }}</h2>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">Kelola profil toko, produk, pesanan pickup, dan pencairan hak UMKM dari satu tempat.</p>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([['Toko Saya', 'Kelola profil dan status verifikasi', route('umkm.stores.index'), 'Profil Toko'], ['Produk Saya', 'Kelola katalog produk toko', route('umkm.products.index'), 'Buka Produk'], ['Pesanan Masuk', 'Lihat dan proses pesanan pickup', route('umkm.orders.index'), 'Lihat Pesanan'], ['Pencairan Dana', 'Pantau hak dan pengajuan pencairan', route('umkm.disbursements.index'), 'Buka Keuangan']] as [$label, $description, $url, $action])
                <a href="{{ $url }}" class="group rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-200 hover:shadow-md"><p class="text-sm font-semibold text-slate-500">{{ $label }}</p><p class="mt-2 min-h-10 text-sm text-slate-700">{{ $description }}</p><p class="mt-4 text-sm font-semibold text-emerald-700 group-hover:text-emerald-800">{{ $action }} <span aria-hidden="true">→</span></p></a>
            @endforeach
        </section>

        <section class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Identitas akun</p><dl class="mt-4 space-y-3 text-sm"><div class="flex justify-between gap-4"><dt class="text-slate-500">Nama</dt><dd class="font-semibold text-slate-900">{{ auth()->user()->name }}</dd></div><div class="flex justify-between gap-4"><dt class="text-slate-500">Email</dt><dd class="break-all text-right font-semibold text-slate-900">{{ auth()->user()->email }}</dd></div><div class="flex justify-between gap-4"><dt class="text-slate-500">Peran</dt><dd class="font-semibold text-slate-900">Pemilik UMKM</dd></div></dl></div>
            <div class="rounded-2xl border border-emerald-100 bg-emerald-50 p-6 shadow-sm"><p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">Pusat bantuan</p><h3 class="mt-2 text-lg font-bold text-slate-900">Siap menerima pesanan?</h3><p class="mt-2 text-sm leading-6 text-slate-700">Pastikan stok dan rekening penerima selalu diperbarui agar operasional pickup berjalan lancar.</p><a href="{{ route('umkm.recipient-bank-accounts.index') }}" class="mt-4 inline-flex rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">Kelola Rekening</a></div>
        </section>
    </div>
</x-umkm-layout>
