<x-admin-layout title="Perjanjian Komisi Penjual">

    <div>
        <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto p-6">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="text-left text-gray-600">
                            <tr><th class="px-3 py-2">Toko</th><th class="px-3 py-2">Pemilik</th><th class="px-3 py-2">Status toko</th><th class="px-3 py-2">Perjanjian terbaru</th><th class="px-3 py-2">Aksi</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 text-gray-900">
                            @forelse ($stores as $store)
                                @php($latestAgreement = $store->commissionAgreements->first())
                                <tr>
                                    <td class="px-3 py-3 font-medium">{{ $store->name }}</td>
                                    <td class="px-3 py-3">{{ $store->user->name }}</td>
                                    <td class="px-3 py-3">{{ ucfirst($store->status) }}</td>
                                    <td class="px-3 py-3">
                                        @if ($latestAgreement)
                                            <a href="{{ route('admin.commission-agreements.show', $latestAgreement) }}" class="font-medium text-indigo-700 hover:text-indigo-900">{{ $latestAgreement->commission_percentage }}% | {{ $latestAgreement->statusLabel() }}</a>
                                            <p class="mt-1 text-xs text-gray-500">Mulai {{ $latestAgreement->effective_start_date->format('d-m-Y') }}</p>
                                        @else
                                            <span class="text-gray-500">Belum ada perjanjian</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3">
                                        @if ($store->status === 'approved')
                                            <a href="{{ route('admin.commission-agreements.create', $store) }}" class="inline-flex rounded-md bg-gray-800 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700">Buat Perjanjian</a>
                                        @else
                                            <span class="text-xs text-gray-500">Toko belum disetujui</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-3 py-8 text-center text-gray-600">Belum ada toko Penjual.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-admin-layout>
