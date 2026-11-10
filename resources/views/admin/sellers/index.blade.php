<x-admin-layout title="Data Penjual">
    <div class="mx-auto max-w-7xl space-y-6">
        <nav aria-label="Breadcrumb" class="text-sm text-slate-500"><a href="{{ route('admin.dashboard') }}" class="hover:text-blue-700">Dashboard</a><span class="mx-2">/</span><span class="font-medium text-slate-700">Data Penjual</span></nav>

        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
            <div><p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-600">Operasional</p><h2 class="mt-1 text-2xl font-bold tracking-tight text-slate-950">Data Penjual</h2><p class="mt-2 text-sm text-slate-500">Direktori seluruh toko terdaftar. Persetujuan dan penolakan tetap dilakukan di halaman verifikasi.</p></div>
            <a href="{{ route('admin.umkm-stores.index') }}" class="inline-flex w-fit items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Buka Verifikasi Toko</a>
        </div>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="seller-filters-heading">
            <h3 id="seller-filters-heading" class="text-sm font-bold text-slate-900">Cari dan filter toko</h3>
            <form method="GET" action="{{ route('admin.sellers.index') }}" class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,2fr)_1fr_1fr_auto] xl:items-end">
                <div><label for="search" class="block text-sm font-medium text-slate-700">Nama toko, pemilik, atau email</label><input id="search" name="search" type="search" value="{{ $filters['search'] }}" maxlength="100" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Cari penjual"></div>
                <div><label for="status" class="block text-sm font-medium text-slate-700">Status toko</label><select id="status" name="status" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"><option value="">Semua status</option>@foreach (['pending' => 'Menunggu verifikasi', 'approved' => 'Aktif', 'rejected' => 'Ditolak'] as $value => $label)<option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>@endforeach</select></div>
                <div><label for="sort" class="block text-sm font-medium text-slate-700">Urutkan</label><select id="sort" name="sort" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"><option value="newest" @selected($filters['sort'] === 'newest')>Terbaru</option><option value="oldest" @selected($filters['sort'] === 'oldest')>Terlama</option><option value="name_asc" @selected($filters['sort'] === 'name_asc')>Nama A–Z</option><option value="name_desc" @selected($filters['sort'] === 'name_desc')>Nama Z–A</option></select></div>
                <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Terapkan</button>
            </form>
            @if ($errors->any())<p role="alert" class="mt-3 text-sm text-red-700">Filter tidak valid. Periksa pilihan Anda.</p>@endif
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="seller-list-heading">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-4 sm:px-6"><div><h3 id="seller-list-heading" class="text-sm font-bold text-slate-900">Daftar toko</h3><p class="mt-1 text-xs text-slate-500">{{ number_format($stores->total(), 0, ',', '.') }} hasil sesuai filter</p></div><span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">Halaman {{ $stores->currentPage() }} dari {{ $stores->lastPage() }}</span></div>
            @if ($stores->isEmpty())
                <div class="px-6 py-16 text-center"><div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-xl text-slate-500" aria-hidden="true">&#128269;</div><p class="mt-4 text-sm font-semibold text-slate-800">{{ $filters['search'] !== '' || $filters['status'] !== '' ? 'Tidak ada toko yang cocok' : 'Belum ada penjual terdaftar' }}</p><p class="mt-1 text-sm text-slate-500">{{ $filters['search'] !== '' || $filters['status'] !== '' ? 'Ubah kata kunci atau filter untuk melihat hasil lain.' : 'Pengajuan toko baru akan tampil di sini.' }}</p></div>
            @else
                <div class="overflow-x-auto"><table class="min-w-[980px] w-full divide-y divide-slate-200 text-left text-sm"><thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th scope="col" class="px-5 py-3 font-semibold">Toko dan pemilik</th><th scope="col" class="px-5 py-3 font-semibold">Kontak dan alamat</th><th scope="col" class="px-5 py-3 font-semibold">Terdaftar</th><th scope="col" class="px-5 py-3 font-semibold">Status</th><th scope="col" class="px-5 py-3 text-center font-semibold">Produk</th><th scope="col" class="px-5 py-3 font-semibold">Aksi</th></tr></thead><tbody class="divide-y divide-slate-100">
                    @foreach ($stores as $store)
                        @php $statusClass = ['pending' => 'bg-amber-100 text-amber-800', 'approved' => 'bg-emerald-100 text-emerald-800', 'rejected' => 'bg-red-100 text-red-800'][$store->status] ?? 'bg-slate-100 text-slate-700'; $statusLabel = ['pending' => 'Menunggu', 'approved' => 'Aktif', 'rejected' => 'Ditolak'][$store->status] ?? $store->status; @endphp
                        <tr class="align-top hover:bg-slate-50"><td class="px-5 py-4"><p class="font-semibold text-slate-900">{{ $store->name }}</p><p class="mt-1 text-slate-700">{{ $store->user->name }}</p><p class="mt-1 text-xs text-slate-500">{{ $store->user->email }}</p></td><td class="max-w-xs px-5 py-4 text-slate-700"><p>{{ $store->phone ?: 'Kontak belum tersedia' }}</p><p class="mt-1 text-xs text-slate-500">{{ $store->address ?: 'Alamat belum tersedia' }}</p></td><td class="whitespace-nowrap px-5 py-4 text-slate-700">{{ $store->created_at?->format('d M Y') ?? '-' }}</td><td class="px-5 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClass }}">{{ $statusLabel }}</span></td><td class="px-5 py-4 text-center font-semibold text-slate-800">{{ $store->products_count }}</td><td class="px-5 py-4"><a href="{{ route('admin.sellers.show', $store) }}" class="font-semibold text-blue-700 hover:text-blue-900">Lihat Detail</a></td></tr>
                    @endforeach
                </tbody></table></div>
            @endif
            @if ($stores->hasPages())<div class="border-t border-slate-100 px-5 py-4 sm:px-6">{{ $stores->links() }}</div>@endif
        </section>
    </div>
</x-admin-layout>
