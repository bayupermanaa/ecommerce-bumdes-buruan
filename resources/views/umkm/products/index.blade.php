<x-umkm-layout title="Produk Saya">
        <div class="mb-6 flex items-center justify-between gap-4">
            <div><p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-600">Katalog toko</p><h2 class="mt-1 text-2xl font-bold text-slate-900">Produk Toko Saya</h2></div>
            @if ($store->status === 'approved')
                <a href="{{ route('umkm.products.create') }}" class="inline-flex items-center rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700">
                    Tambah Produk
                </a>
            @endif
        </div>
    <div class="mx-auto max-w-7xl space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            @if (session('error'))
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">{{ session('error') }}</div>
            @endif

            @if ($store->status !== 'approved')
                <div class="rounded-md bg-yellow-50 p-4 text-sm text-yellow-800">
                    Toko Anda masih berstatus {{ $store->status }}. Produk dapat ditambahkan setelah toko disetujui.
                </div>
            @endif

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="p-6 overflow-x-auto">
                    @if ($products->isEmpty())
                        <p class="text-gray-600">Belum ada produk.</p>
                    @else
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="text-left text-gray-600">
                                <tr>
                                    <th class="px-3 py-2">Produk</th>
                                    <th class="px-3 py-2">SKU</th>
                                    <th class="px-3 py-2">Kategori</th>
                                    <th class="px-3 py-2">Harga</th>
                                    <th class="px-3 py-2">Stok</th>
                                    <th class="px-3 py-2">Status</th>
                                    <th class="px-3 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 text-gray-900">
                                @foreach ($products as $product)
                                    <tr>
                                        <td class="px-3 py-3">{{ $product->name }}</td>
                                        <td class="px-3 py-3">{{ $product->sku }}</td>
                                        <td class="px-3 py-3">{{ $product->category->name }}</td>
                                        <td class="px-3 py-3">Rp {{ number_format((float) $product->price, 2, ',', '.') }}</td>
                                        <td class="px-3 py-3">{{ $product->stock }}</td>
                                        <td class="px-3 py-3">
                                            <div>{{ ucfirst($product->status) }}</div>
                                            @if ($product->status === 'rejected' && $product->rejection_reason)
                                                <div class="mt-1 text-xs text-red-700">{{ $product->rejection_reason }}</div>
                                            @endif
                                            @if ($product->verified_at)
                                                <div class="mt-1 text-xs text-gray-600">Diverifikasi {{ $product->verified_at->format('d-m-Y H:i') }}</div>
                                            @endif
                                            @if ($product->latestRevision?->status === 'pending')
                                                <div class="mt-1 text-xs font-medium text-amber-700">Revisi menunggu persetujuan Admin BUMDes.</div>
                                            @elseif ($product->latestRevision?->status === 'rejected')
                                                <div class="mt-1 text-xs font-medium text-red-700">Revisi terakhir ditolak: {{ $product->latestRevision->rejection_reason }}</div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3 whitespace-nowrap">
                                            <a href="{{ route('umkm.products.edit', $product) }}" class="text-indigo-600 hover:text-indigo-900">Edit</a>
                                            <form method="POST" action="{{ route('umkm.products.destroy', $product) }}" class="inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="ml-3 text-red-600 hover:text-red-900">Hapus</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
    </div>
</x-umkm-layout>
