<x-kepala-bumdes-layout title="Detail Laporan UMKM">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Rincian Laporan UMKM</h2>
                <p class="mt-1 text-sm text-gray-600">{{ $store->name }}</p>
            </div>
            <a href="{{ route('kepala-bumdes.stores.index', array_filter(['date_from' => $filters['date_from'], 'date_to' => $filters['date_to'], 'fulfillment_status' => $filters['fulfillment_status']])) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Kembali ke Laporan Per UMKM</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto space-y-6 sm:px-6 lg:px-8">
            <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                <p class="font-medium text-gray-900">{{ $store->name }}</p>
                @if ($store->address)<p class="mt-1 text-sm text-gray-600">{{ $store->address }}</p>@endif
                <p class="mt-2 text-xs text-gray-500">Rincian memakai snapshot nilai toko. Pesanan belum paid ditampilkan untuk monitoring, tetapi tidak termasuk penjualan terkonfirmasi, komisi snapshot, atau estimasi hak UMKM.</p>
            </div>

            <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500">
                            <tr>
                                <th class="px-6 py-3">Nomor / Tanggal</th>
                                <th class="px-6 py-3">Pembayaran</th>
                                <th class="px-6 py-3">Fulfillment</th>
                                <th class="px-6 py-3 text-right">Nilai Toko</th>
                                <th class="px-6 py-3 text-right">Komisi Snapshot</th>
                                <th class="px-6 py-3 text-right">Estimasi Hak UMKM</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 text-gray-700">
                            @forelse ($storeOrders as $storeOrder)
                                <tr>
                                    <td class="px-6 py-4"><p class="font-medium text-gray-900">{{ $storeOrder->order->order_number }}</p><p class="text-xs text-gray-500">{{ $storeOrder->order->created_at->translatedFormat('d M Y H:i') }}</p></td>
                                    <td class="px-6 py-4">{{ $storeOrder->order->paymentStatusLabel() }}</td>
                                    <td class="px-6 py-4">{{ $storeOrder->fulfillmentStatusLabel() }}</td>
                                    <td class="px-6 py-4 text-right">Rp {{ number_format((float) $storeOrder->total, 2, ',', '.') }}</td>
                                    @if ($storeOrder->order->payment_status === 'paid' && $storeOrder->commission_status === 'calculated')
                                        <td class="px-6 py-4 text-right">Rp {{ number_format((float) $storeOrder->commission_amount, 2, ',', '.') }}</td>
                                        <td class="px-6 py-4 text-right">Rp {{ number_format((float) $storeOrder->umkm_amount, 2, ',', '.') }}</td>
                                    @else
                                        <td class="px-6 py-4 text-right text-gray-500">—</td>
                                        <td class="px-6 py-4 text-right text-gray-500">—</td>
                                    @endif
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-6 py-8 text-center text-gray-500">Tidak ada pesanan toko sesuai filter.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($storeOrders->hasPages())
                    <div class="border-t border-gray-200 px-6 py-4">{{ $storeOrders->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</x-kepala-bumdes-layout>
