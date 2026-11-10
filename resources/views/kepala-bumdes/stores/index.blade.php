<x-kepala-bumdes-layout title="Laporan Per Penjual">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Laporan Per Penjual</h2>
            <a href="{{ route('kepala-bumdes.dashboard') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Kembali ke Dashboard</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto space-y-6 sm:px-6 lg:px-8">
            <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                <form method="GET" action="{{ route('kepala-bumdes.stores.index') }}" class="grid gap-4 md:grid-cols-2 lg:grid-cols-5">
                    <div>
                        <x-input-label for="date_from" value="Tanggal pesanan dari" />
                        <x-text-input id="date_from" name="date_from" type="date" class="mt-1 block w-full" :value="$filters['date_from']" />
                    </div>
                    <div>
                        <x-input-label for="date_to" value="Tanggal pesanan sampai" />
                        <x-text-input id="date_to" name="date_to" type="date" class="mt-1 block w-full" :value="$filters['date_to']" />
                    </div>
                    <div>
                        <x-input-label for="store_id" value="Penjual" />
                        <select id="store_id" name="store_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Semua Penjual</option>
                            @foreach ($storeOptions as $option)
                                <option value="{{ $option->id }}" @selected((string) ($filters['store_id'] ?? '') === (string) $option->id)>{{ $option->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="fulfillment_status" value="Status fulfillment toko" />
                        <select id="fulfillment_status" name="fulfillment_status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Semua</option>
                            <option value="pending_payment" @selected($filters['fulfillment_status'] === 'pending_payment')>Menunggu Pembayaran</option>
                            <option value="awaiting_processing" @selected($filters['fulfillment_status'] === 'awaiting_processing')>Menunggu Diproses</option>
                            <option value="processing" @selected($filters['fulfillment_status'] === 'processing')>Sedang Diproses</option>
                            <option value="ready_for_pickup" @selected($filters['fulfillment_status'] === 'ready_for_pickup')>Siap Diambil</option>
                            <option value="completed" @selected($filters['fulfillment_status'] === 'completed')>Selesai</option>
                            <option value="cancelled" @selected($filters['fulfillment_status'] === 'cancelled')>Dibatalkan</option>
                        </select>
                    </div>
                    <div class="flex items-end gap-2">
                        <x-primary-button>Filter</x-primary-button>
                        <a href="{{ route('kepala-bumdes.stores.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 hover:bg-gray-50">Reset</a>
                    </div>
                </form>
                @if ($errors->any())
                    <div class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>
                @endif
            </div>

            <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500">
                            <tr>
                                <th class="px-6 py-3">Penjual</th>
                                <th class="px-6 py-3 text-right">Pesanan Toko</th>
                                <th class="px-6 py-3 text-right">Paid / Selesai</th>
                                <th class="px-6 py-3 text-right">Penjualan Terkonfirmasi</th>
                                <th class="px-6 py-3 text-right">Komisi Snapshot</th>
                                <th class="px-6 py-3 text-right">Estimasi Hak Penjual</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 text-gray-700">
                            @forelse ($stores as $store)
                                <tr>
                                    <td class="px-6 py-4"><a href="{{ route('kepala-bumdes.stores.show', array_filter(['store' => $store, 'date_from' => $filters['date_from'], 'date_to' => $filters['date_to'], 'fulfillment_status' => $filters['fulfillment_status']])) }}" class="font-medium text-indigo-600 hover:text-indigo-800">{{ $store->name }}</a></td>
                                    <td class="px-6 py-4 text-right">{{ number_format($store->store_orders_count) }}</td>
                                    <td class="px-6 py-4 text-right">{{ number_format($store->paid_store_orders_count) }} / {{ number_format($store->completed_store_orders_count) }}</td>
                                    <td class="px-6 py-4 text-right">Rp {{ number_format((float) ($store->confirmed_sales_total ?? 0), 2, ',', '.') }}</td>
                                    <td class="px-6 py-4 text-right">Rp {{ number_format((float) ($store->commission_snapshot_total ?? 0), 2, ',', '.') }}</td>
                                    <td class="px-6 py-4 text-right">
                                        Rp {{ number_format((float) ($store->estimated_umkm_total ?? 0), 2, ',', '.') }}
                                        @if ($store->paid_without_commission_snapshot_count > 0)
                                            <p class="mt-1 text-xs text-amber-700">{{ $store->paid_without_commission_snapshot_count }} paid tanpa snapshot komisi</p>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-6 py-8 text-center text-gray-500">Tidak ada Penjual sesuai filter.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($stores->hasPages())
                    <div class="border-t border-gray-200 px-6 py-4">{{ $stores->links() }}</div>
                @endif
            </div>
            <p class="text-xs text-gray-500">Periode memakai tanggal pesanan. Penjualan, komisi, dan estimasi hak hanya menghitung pesanan dengan pembayaran terkonfirmasi. Estimasi hak memakai snapshot transaksi dan bukan saldo atau dana siap dicairkan.</p>
        </div>
    </div>
</x-kepala-bumdes-layout>
