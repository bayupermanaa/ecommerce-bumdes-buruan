<x-umkm-layout title="Pesanan Masuk">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-600">Operasional pickup</p>
                <h2 class="mt-1 text-2xl font-bold text-slate-900">Pesanan Masuk</h2>
                <p class="mt-1 text-sm text-slate-500">{{ $store->name }}</p>
            </div>
            <a href="{{ route('umkm.dashboard') }}" class="text-sm font-semibold text-gray-700 hover:text-gray-950">Kembali ke Dashboard</a>
        </div>
    <div class="mx-auto max-w-7xl space-y-6">
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <form method="GET" action="{{ route('umkm.orders.index') }}" class="flex flex-wrap items-end gap-3">
                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700">Status fulfillment</label>
                        <select id="status" name="status" class="mt-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-700 focus:ring-gray-700">
                            <option value="all" @selected($selectedStatus === 'all')>Semua Status</option>
                            <option value="pending_payment" @selected($selectedStatus === 'pending_payment')>Menunggu Pembayaran</option>
                            <option value="awaiting_processing" @selected($selectedStatus === 'awaiting_processing')>Menunggu Diproses</option>
                            <option value="processing" @selected($selectedStatus === 'processing')>Sedang Diproses</option>
                            <option value="ready_for_pickup" @selected($selectedStatus === 'ready_for_pickup')>Siap Diambil</option>
                            <option value="completed" @selected($selectedStatus === 'completed')>Selesai</option>
                            <option value="cancelled" @selected($selectedStatus === 'cancelled')>Dibatalkan</option>
                        </select>
                    </div>
                    <button type="submit" class="rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700">Filter</button>
                </form>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto p-6">
                    @if ($storeOrders->isEmpty())
                        <p class="text-sm text-gray-600">Belum ada pesanan untuk toko Anda.</p>
                    @else
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="text-left text-gray-600">
                                <tr>
                                    <th class="px-3 py-2">Pesanan</th>
                                    <th class="px-3 py-2">Pelanggan</th>
                                    <th class="px-3 py-2">Total Toko</th>
                                    <th class="px-3 py-2">Pembayaran</th>
                                    <th class="px-3 py-2">Fulfillment</th>
                                    <th class="px-3 py-2">Item</th>
                                    <th class="px-3 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 text-gray-900">
                                @foreach ($storeOrders as $storeOrder)
                                    <tr>
                                        <td class="px-3 py-3">
                                            <p class="font-semibold">{{ $storeOrder->store_order_number }}</p>
                                            <p class="mt-1 text-xs text-gray-500">{{ $storeOrder->order->created_at->format('d-m-Y H:i') }}</p>
                                        </td>
                                        <td class="px-3 py-3">{{ $storeOrder->order->recipient_name }}</td>
                                        <td class="px-3 py-3 whitespace-nowrap">Rp {{ number_format((float) $storeOrder->total, 0, ',', '.') }}</td>
                                        <td class="px-3 py-3">{{ $storeOrder->order->paymentStatusLabel() }}</td>
                                        <td class="px-3 py-3">
                                            <p>{{ $storeOrder->fulfillmentStatusLabel() }}</p>
                                            @if ($storeOrder->order->payment_status === 'paid' && $storeOrder->fulfillment_status === 'pending_payment')
                                                <p class="mt-1 text-xs text-amber-700">Pembayaran sudah dikonfirmasi; status fulfillment lama belum direkonsiliasi.</p>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3 text-center">{{ $storeOrder->items_count }}</td>
                                        <td class="px-3 py-3 text-right"><a href="{{ route('umkm.orders.show', $storeOrder) }}" class="font-semibold text-indigo-600 hover:text-indigo-900">Detail</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>

            <div>{{ $storeOrders->links() }}</div>
    </div>
</x-umkm-layout>
