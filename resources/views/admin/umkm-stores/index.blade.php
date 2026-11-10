<x-admin-layout title="Verifikasi Toko Penjual">
    @php
        $labels = ['pending' => 'Menunggu', 'approved' => 'Disetujui', 'rejected' => 'Ditolak'];
        $badges = ['pending' => 'bg-amber-50 text-amber-800 ring-amber-200', 'approved' => 'bg-emerald-50 text-emerald-800 ring-emerald-200', 'rejected' => 'bg-rose-50 text-rose-800 ring-rose-200'];
        $returnParams = ['return_status' => $filters['status'], 'return_search' => $filters['search'], 'return_sort' => $filters['sort'], 'return_page' => $stores->currentPage()];
    @endphp
    <div class="mx-auto max-w-7xl space-y-6">
        <nav aria-label="Breadcrumb" class="text-sm text-slate-500"><a href="{{ route('admin.dashboard') }}" class="hover:text-blue-700">Dashboard</a><span class="mx-2">/</span><span class="font-medium text-slate-700">Verifikasi Toko Penjual</span></nav>
        <header class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
            <div><p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-600">Operasional / Pengajuan toko</p><h2 class="mt-1 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl">Verifikasi Toko Penjual</h2><p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">Tinjau data pengajuan sebelum memutuskan. Data yang diisi penjual belum berarti telah diverifikasi.</p></div>
            <a href="{{ route('admin.sellers.index') }}" class="inline-flex w-fit items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50"><x-admin-icon name="users" />Data Penjual</a>
        </header>
        @if (session('status'))<div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('status') }}</div>@endif
        @if (session('error'))<div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">{{ session('error') }}</div>@endif
        @if ($errors->any())<div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">{{ $errors->first() }}</div>@endif
        <section aria-label="Ringkasan status toko" class="grid gap-4 sm:grid-cols-3">
            @foreach ([['pending', 'Menunggu verifikasi', 'store', 'text-amber-700 bg-amber-50'], ['approved', 'Disetujui', 'badge-check', 'text-emerald-700 bg-emerald-50'], ['rejected', 'Ditolak', 'scan-search', 'text-rose-700 bg-rose-50']] as [$key, $label, $icon, $tone])
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="flex items-start justify-between gap-3"><div><p class="text-sm font-medium text-slate-500">{{ $label }}</p><p class="mt-3 text-3xl font-bold tracking-tight text-slate-950">{{ number_format((int) ($statusCounts[$key] ?? 0), 0, ',', '.') }}</p></div><span class="flex h-10 w-10 items-center justify-center rounded-xl {{ $tone }}"><x-admin-icon :name="$icon" /></span></div><p class="mt-3 text-xs text-slate-500">Seluruh toko dengan status saat ini.</p></div>
            @endforeach
        </section>
        <section aria-labelledby="store-filter-heading" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="mb-4"><h3 id="store-filter-heading" class="text-sm font-bold text-slate-900">Cari pengajuan</h3><p class="mt-1 text-xs text-slate-500">Gabungkan kata kunci dan status untuk mempersempit hasil.</p></div>
            <form method="GET" action="{{ route('admin.umkm-stores.index') }}" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,2fr)_1fr_1fr_auto] xl:items-end">
                <div><label for="search" class="block text-sm font-medium text-slate-700">Toko, pemilik, atau email</label><input id="search" name="search" type="search" maxlength="100" value="{{ $filters['search'] }}" placeholder="Cari pengajuan" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></div>
                <div><label for="status" class="block text-sm font-medium text-slate-700">Status toko</label><select id="status" name="status" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"><option value="" @selected($filters['status'] === '')>Semua status</option>@foreach ($labels as $value => $label)<option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>@endforeach</select></div>
                <div><label for="sort" class="block text-sm font-medium text-slate-700">Urutan pendaftaran</label><select id="sort" name="sort" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"><option value="newest" @selected($filters['sort'] === 'newest')>Terbaru</option><option value="oldest" @selected($filters['sort'] === 'oldest')>Terlama</option></select></div>
                <div class="flex flex-wrap gap-2"><button type="submit" class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Terapkan</button><a href="{{ route('admin.umkm-stores.index') }}" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Reset Filter</a></div>
            </form>
        </section>
        <section aria-labelledby="store-list-heading" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-4 sm:px-6"><div><h3 id="store-list-heading" class="text-sm font-bold text-slate-900">Daftar pengajuan</h3><p class="mt-1 text-xs text-slate-500">{{ number_format($stores->total(), 0, ',', '.') }} toko sesuai filter; {{ $stores->count() }} tampil di halaman ini.</p></div><span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">Halaman {{ $stores->currentPage() }} dari {{ $stores->lastPage() }}</span></div>
            @if ($stores->isEmpty())
                <div class="px-6 py-14 text-center"><div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-500"><x-admin-icon name="store" /></div><p class="mt-4 text-sm font-semibold text-slate-800">{{ $filters['search'] !== '' || $filters['status'] !== '' ? 'Tidak ada pengajuan yang cocok' : 'Belum ada pengajuan toko' }}</p><p class="mt-1 text-sm text-slate-500">Ubah filter atau tunggu pengajuan baru dari calon penjual.</p></div>
            @else
                <div class="space-y-3 p-4 lg:hidden">
                    @foreach ($stores as $store)
                        <article class="rounded-xl border border-slate-200 p-4">
                            <div class="flex items-start gap-3">@include('admin.umkm-stores._logo')<div class="min-w-0 flex-1"><h4 class="break-words font-semibold text-slate-900">{{ $store->name }}</h4><p class="mt-1 text-sm text-slate-700">{{ $store->user->name }}</p><p class="break-all text-xs text-slate-500">{{ $store->user->email }}</p></div><span class="inline-flex shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $badges[$store->status] ?? 'bg-slate-50 text-slate-700 ring-slate-200' }}">{{ $labels[$store->status] ?? $store->status }}</span></div>
                            <dl class="mt-4 grid grid-cols-2 gap-3 text-xs"><div><dt class="text-slate-500">Kontak</dt><dd class="mt-1 font-medium text-slate-800">{{ $store->phone ?: 'Belum tersedia' }}</dd></div><div><dt class="text-slate-500">Terdaftar</dt><dd class="mt-1 font-medium text-slate-800">{{ $store->created_at?->format('d M Y') ?? '-' }}</dd></div></dl>
                            <div class="mt-4 border-t border-slate-100 pt-4">@include('admin.umkm-stores._actions', ['surface' => 'card'])</div>
                        </article>
                    @endforeach
                </div>
                <div class="hidden overflow-x-auto lg:block">
                    <table class="min-w-[1080px] w-full divide-y divide-slate-100 text-left text-sm"><thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th scope="col" class="px-5 py-3">Toko</th><th scope="col" class="px-5 py-3">Pemilik</th><th scope="col" class="px-5 py-3">Kontak</th><th scope="col" class="px-5 py-3">Pendaftaran</th><th scope="col" class="px-5 py-3">Status</th><th scope="col" class="px-5 py-3">Aksi</th></tr></thead><tbody class="divide-y divide-slate-100">
                        @foreach ($stores as $store)
                            <tr class="align-top hover:bg-slate-50"><td class="px-5 py-4"><div class="flex items-center gap-3">@include('admin.umkm-stores._logo')<span class="font-semibold text-slate-900">{{ $store->name }}</span></div></td><td class="px-5 py-4"><p class="font-medium text-slate-800">{{ $store->user->name }}</p><p class="mt-1 text-xs text-slate-500">{{ $store->user->email }}</p></td><td class="px-5 py-4 text-slate-700">{{ $store->phone ?: 'Belum tersedia' }}</td><td class="whitespace-nowrap px-5 py-4 text-slate-700">{{ $store->created_at?->format('d M Y') ?? '-' }}</td><td class="px-5 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $badges[$store->status] ?? 'bg-slate-50 text-slate-700 ring-slate-200' }}">{{ $labels[$store->status] ?? $store->status }}</span></td><td class="min-w-[360px] px-5 py-4">@include('admin.umkm-stores._actions', ['surface' => 'table'])</td></tr>
                        @endforeach
                    </tbody></table>
                </div>
            @endif
            @if ($stores->hasPages())<div class="border-t border-slate-100 px-5 py-4 sm:px-6">{{ $stores->links() }}</div>@endif
        </section>
    </div>
</x-admin-layout>