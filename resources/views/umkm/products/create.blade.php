<x-umkm-layout title="Tambah Produk">
    <div class="mx-auto max-w-3xl">
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <form method="POST" action="{{ route('umkm.products.store') }}" enctype="multipart/form-data" class="space-y-6 p-6 sm:p-8">
                    @csrf
                    @include('umkm.products.partials.form')
                    <x-primary-button>Simpan Produk</x-primary-button>
                </form>
    </div>
</x-umkm-layout>
