<x-umkm-layout title="Edit Produk">
    <div class="mx-auto max-w-3xl space-y-6">
            @if ($product->status === 'approved')
                <div class="mb-6 rounded-md bg-blue-50 p-4 text-sm text-blue-800">
                    Perubahan informasi produk akan dikirim sebagai revisi untuk diverifikasi. Produk yang tampil di katalog tidak berubah sampai revisi disetujui. Perubahan stok tetap diterapkan langsung.
                </div>
            @endif

            @if ($product->pendingRevision)
                <div class="mb-6 rounded-md bg-amber-50 p-4 text-sm text-amber-800">
                    Ada revisi yang sedang menunggu persetujuan. Anda masih dapat memperbarui stok, tetapi perubahan informasi produk baru tidak dapat diajukan sampai revisi tersebut diproses.
                </div>
            @endif

            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <form method="POST" action="{{ route('umkm.products.update', $product) }}" enctype="multipart/form-data" class="space-y-6 p-6 sm:p-8">
                    @csrf
                    @method('PUT')
                    @include('umkm.products.partials.form', ['product' => $product])
                    <x-primary-button>Simpan Perubahan</x-primary-button>
                </form>
            </div>
    </div>
</x-umkm-layout>
