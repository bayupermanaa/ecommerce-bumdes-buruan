<x-umkm-layout title="Profil Toko">
    <div class="mx-auto max-w-5xl space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            @if ($store)
                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="p-6 space-y-4 text-gray-900">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-semibold">{{ $store->name }}</h3>
                                <p class="text-sm text-gray-600">{{ $store->slug }}</p>
                            </div>
                            <span class="rounded-full bg-yellow-100 px-3 py-1 text-sm font-medium text-yellow-800">
                                {{ ucfirst($store->status) }}
                            </span>
                        </div>

                        <dl class="space-y-2 text-sm">
                            <div><dt class="font-medium">Deskripsi</dt><dd>{{ $store->description ?: '-' }}</dd></div>
                            <div><dt class="font-medium">Alamat</dt><dd>{{ $store->address ?: '-' }}</dd></div>
                            <div><dt class="font-medium">Nomor Telepon</dt><dd>{{ $store->phone ?: '-' }}</dd></div>
                        </dl>

                        <a href="{{ route('umkm.stores.edit', $store) }}" class="inline-flex items-center rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700">
                            Edit Profil Toko
                        </a>
                    </div>
                </div>
            @else
                <div class="rounded-2xl border border-dashed border-slate-300 bg-white shadow-sm">
                    <div class="p-6 text-gray-900 space-y-4">
                        <p>Anda belum memiliki toko.</p>
                        <a href="{{ route('umkm.stores.create') }}" class="inline-flex items-center rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700">
                            Buat Toko
                        </a>
                    </div>
                </div>
            @endif
    </div>
</x-umkm-layout>
