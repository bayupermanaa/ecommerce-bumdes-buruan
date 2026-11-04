<x-admin-layout title="Edit Rekening Resmi BUMDes">
    <div><div class="mx-auto max-w-3xl"><div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <form method="POST" action="{{ route('admin.bumdes-bank-accounts.update', $account) }}" class="space-y-6">
            @csrf
            @method('PATCH')
            @include('admin.bumdes-bank-accounts._form')
            <div class="flex flex-wrap gap-3"><button type="submit" class="inline-flex rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700">Simpan Perubahan</button><a href="{{ route('admin.bumdes-bank-accounts.index') }}" class="inline-flex rounded-md border border-gray-300 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 hover:bg-gray-50">Kembali</a></div>
        </form>
    </div></div></div>
</x-admin-layout>
