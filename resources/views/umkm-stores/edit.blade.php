<x-umkm-layout title="Edit Profil Toko">
    <div class="mx-auto max-w-3xl">
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <form method="POST" action="{{ route('umkm.stores.update', $store) }}" class="space-y-6 p-6 sm:p-8">
                    @csrf
                    @method('PUT')
                    @include('umkm-stores.partials.form', ['store' => $store])

                    <x-primary-button>Simpan Perubahan</x-primary-button>
                </form>
    </div>
</x-umkm-layout>
