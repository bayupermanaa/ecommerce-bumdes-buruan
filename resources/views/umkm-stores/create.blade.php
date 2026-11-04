<x-umkm-layout title="Buat Toko">
    <div class="mx-auto max-w-3xl">
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <form method="POST" action="{{ route('umkm.stores.store') }}" class="space-y-6 p-6 sm:p-8">
                    @csrf
                    @include('umkm-stores.partials.form')

                    <x-primary-button>Simpan Toko</x-primary-button>
                </form>
    </div>
</x-umkm-layout>
