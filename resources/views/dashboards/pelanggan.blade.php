<x-catalog-layout title="Dashboard Pelanggan">
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <section class="rounded-3xl bg-slate-950 p-7 text-white shadow-xl sm:p-10">
            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-300">Ruang belanja Anda</p>
            <h1 class="mt-3 text-3xl font-bold">Halo, {{ auth()->user()->name }}.</h1>
            <p class="mt-3 max-w-xl text-sm leading-6 text-slate-300">Temukan produk lokal, pantau pesanan, dan lanjutkan belanja dari marketplace BUMDes Desa Buruan.</p>
            <div class="mt-7 flex flex-wrap gap-3"><a href="{{ route('catalog.index') }}" class="rounded-xl bg-emerald-400 px-4 py-2.5 text-sm font-bold text-slate-950 hover:bg-emerald-300">Jelajahi Katalog</a><a href="{{ route('orders.index') }}" class="rounded-xl border border-white/20 px-4 py-2.5 text-sm font-semibold text-white hover:bg-white/10">Lihat Pesanan</a></div>
        </section>
        <section class="mt-6 grid gap-4 sm:grid-cols-2"><a href="{{ route('cart.index') }}" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm hover:border-emerald-200"><p class="text-sm font-semibold text-slate-500">Keranjang Saya</p><p class="mt-2 text-lg font-bold text-slate-900">Lanjutkan belanja <span class="text-emerald-600">→</span></p></a><a href="{{ route('orders.index') }}" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm hover:border-emerald-200"><p class="text-sm font-semibold text-slate-500">Riwayat Pesanan</p><p class="mt-2 text-lg font-bold text-slate-900">Pantau status pickup <span class="text-emerald-600">→</span></p></a></section>
    </div>
</x-catalog-layout>
