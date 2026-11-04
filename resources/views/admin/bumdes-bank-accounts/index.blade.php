<x-admin-layout title="Rekening Resmi BUMDes">

    <div>
        <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto p-6">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="text-left text-gray-600">
                            <tr><th class="px-3 py-2">Bank</th><th class="px-3 py-2">Pemilik</th><th class="px-3 py-2">Nomor Rekening</th><th class="px-3 py-2">Status</th><th class="px-3 py-2">Riwayat</th><th class="px-3 py-2">Aksi</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 text-gray-900">
                            @forelse ($accounts as $account)
                                <tr>
                                    <td class="px-3 py-3 font-medium">{{ $account->bank_name }}</td>
                                    <td class="px-3 py-3">{{ $account->account_holder_name }}</td>
                                    <td class="px-3 py-3 font-mono">{{ $account->account_number }}</td>
                                    <td class="px-3 py-3">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $account->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700' }}">{{ $account->status === 'active' ? 'Aktif' : 'Nonaktif' }}</span>
                                    </td>
                                    <td class="px-3 py-3 text-gray-600">{{ $account->payments_count > 0 ? 'Digunakan '.$account->payments_count.' pembayaran' : 'Belum digunakan' }}</td>
                                    <td class="px-3 py-3">
                                        <div class="flex flex-wrap gap-2">
                                            <a href="{{ route('admin.bumdes-bank-accounts.edit', $account) }}" class="inline-flex rounded-md border border-gray-300 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 hover:bg-gray-50">Edit</a>
                                            @if ($account->status === 'active')
                                                <form method="POST" action="{{ route('admin.bumdes-bank-accounts.deactivate', $account) }}" onsubmit="return confirm('Nonaktifkan rekening ini? Riwayat pembayaran tidak akan dihapus.')">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="inline-flex rounded-md bg-red-700 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-red-800">Nonaktifkan</button>
                                                </form>
                                            @else
                                                <form method="POST" action="{{ route('admin.bumdes-bank-accounts.activate', $account) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="inline-flex rounded-md bg-green-700 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-green-800">Aktifkan</button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-3 py-8 text-center text-gray-600">Belum ada rekening resmi BUMDes.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-admin-layout>
