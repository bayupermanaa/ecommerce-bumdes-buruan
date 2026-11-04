<x-admin-layout title="Edit Kategori Produk">
    <div class="mx-auto max-w-3xl space-y-6">
        <div>
            <a href="{{ route('admin.categories.index') }}" class="text-sm font-semibold text-blue-700 hover:text-blue-900">&larr; Kembali ke kategori</a>
            <h2 class="mt-3 text-2xl font-bold text-slate-950">Edit Kategori Produk</h2>
            <p class="mt-2 text-sm text-slate-600">Kategori nonaktif tetap tersimpan pada produk lama, tetapi tidak dapat dipilih untuk produk baru.</p>
        </div>

        <form method="POST" action="{{ route('admin.categories.update', $category) }}" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            @include('admin.categories._form', ['category' => $category])
        </form>
    </div>
</x-admin-layout>
