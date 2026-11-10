<x-catalog-layout title="Mulai Berjualan">
    <section class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="overflow-hidden rounded-3xl bg-slate-950 p-7 text-white shadow-xl sm:p-10">
            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-300">Marketplace BUMDes Desa Buruan</p>
            <h1 class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl">Mulai Berjualan</h1>
            <p class="mt-4 max-w-2xl text-sm leading-6 text-slate-300">Pendaftaran terbuka untuk Penjual Desa Buruan dan penjual dari luar desa. Setiap toko diperiksa Admin BUMDes sebelum dapat menerbitkan produk.</p>
        </div>

        @if (session('status'))
            <div role="status" class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('status') }}</div>
        @endif

        @if (! $store)
            <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8">
                <h2 class="text-xl font-bold text-slate-950">Formulir pengajuan toko</h2>
                <p class="mt-2 text-sm text-slate-600">Lengkapi informasi toko. Akun Anda tetap menjadi pembeli sampai toko disetujui.</p>
                <form method="POST" action="{{ route('seller-application.store') }}" enctype="multipart/form-data" class="mt-6 space-y-5">
                    @csrf
                    @include('seller-applications._form', ['store' => null])
                    <button type="submit" class="inline-flex w-full justify-center rounded-xl bg-emerald-600 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-700 sm:w-auto">Kirim Pengajuan</button>
                </form>
            </div>
        @elseif ($store->status === 'pending')
            <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-6 shadow-sm sm:p-8">
                <span class="inline-flex rounded-full bg-amber-200 px-3 py-1 text-xs font-bold uppercase tracking-wide text-amber-900">Menunggu verifikasi</span>
                <h2 class="mt-4 text-2xl font-bold text-slate-950">{{ $store->name }}</h2>
                <p class="mt-3 text-sm leading-6 text-slate-700">Admin BUMDes sedang memeriksa pengajuan toko Anda. Pengelolaan produk baru tersedia setelah pengajuan disetujui.</p>
                <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-slate-500">Alamat</dt><dd class="mt-1 font-semibold text-slate-900">{{ $store->address }}</dd></div>
                    <div><dt class="text-slate-500">Telepon</dt><dd class="mt-1 font-semibold text-slate-900">{{ $store->phone }}</dd></div>
                </dl>
            </div>
        @elseif ($store->status === 'rejected')
            <div class="mt-6 rounded-2xl border border-red-200 bg-red-50 p-5 shadow-sm sm:p-8">
                <span class="inline-flex rounded-full bg-red-200 px-3 py-1 text-xs font-bold uppercase tracking-wide text-red-900">Perlu diperbaiki</span>
                <h2 class="mt-4 text-2xl font-bold text-slate-950">Pengajuan {{ $store->name }} belum disetujui</h2>
                <div class="mt-4 rounded-xl bg-white p-4 text-sm text-red-800">
                    <p class="font-semibold">Alasan Admin BUMDes</p>
                    <p class="mt-1">{{ $store->rejection_reason }}</p>
                </div>

                <form method="POST" action="{{ route('seller-application.resubmit', $store) }}" enctype="multipart/form-data" class="mt-6 space-y-5 rounded-2xl bg-white p-5 sm:p-6">
                    @csrf
                    @method('PATCH')
                    <h3 class="text-lg font-bold text-slate-950">Perbaiki dan ajukan ulang</h3>
                    @include('seller-applications._form', ['store' => $store])
                    <button type="submit" class="inline-flex w-full justify-center rounded-xl bg-emerald-600 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-700 sm:w-auto">Ajukan Ulang</button>
                </form>
            </div>
        @else
            <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-6 shadow-sm sm:p-8">
                <span class="inline-flex rounded-full bg-emerald-200 px-3 py-1 text-xs font-bold uppercase tracking-wide text-emerald-900">Toko disetujui</span>
                <h2 class="mt-4 text-2xl font-bold text-slate-950">{{ $store->name }}</h2>
                <p class="mt-3 text-sm text-slate-700">Toko Anda telah aktif. Gunakan Dashboard Penjual untuk mengelola profil, produk, dan pesanan.</p>
                <a href="{{ route('umkm.dashboard') }}" class="mt-5 inline-flex rounded-xl bg-slate-950 px-5 py-3 text-sm font-bold text-white hover:bg-slate-800">Buka Dashboard Penjual</a>
            </div>
        @endif
    </section>
</x-catalog-layout>
