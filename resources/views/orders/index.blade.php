<x-catalog-layout title="Pesanan Saya">
    <section class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">Riwayat Belanja</p>
                <h1 class="mt-1 text-3xl font-bold tracking-tight text-slate-950">Pesanan Saya</h1>
            </div>
            <a href="{{ route('catalog.index') }}" class="text-sm font-semibold text-gray-700 hover:text-gray-950">Lihat Katalog</a>
        </div>

        @if ($orders->isEmpty())
            <div class="mt-8 rounded-lg border border-dashed border-gray-300 bg-white px-6 py-16 text-center shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Belum ada pesanan</h2>
                <p class="mt-2 text-sm text-gray-600">Pesanan pickup Anda akan muncul di halaman ini.</p>
            </div>
        @else
            <div class="mt-8 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="divide-y divide-gray-200">
                    @foreach ($orders as $order)
                        <a href="{{ route('orders.show', $order) }}" class="block p-5 transition hover:bg-emerald-50/40">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="font-semibold text-gray-900">{{ $order->order_number }}</p>
                                    <p class="mt-1 text-sm text-gray-600">{{ $order->created_at->format('d-m-Y H:i') }} | {{ $order->store_orders_count }} toko</p>
                                </div>
                                <div class="text-right">
                                    <p class="font-semibold text-gray-900">Rp {{ number_format((float) $order->grand_total, 0, ',', '.') }}</p>
                                    <p class="mt-1 text-xs font-medium uppercase {{ $order->order_status === 'cancelled' ? 'text-red-700' : ($order->order_status === 'completed' ? 'text-green-700' : 'text-amber-700') }}">{{ $order->paymentStatusLabel() }} / {{ $order->orderStatusLabel() }}</p>
                                    @php
                                        $completedStores = $order->storeOrders->where('fulfillment_status', 'completed')->count();
                                        $readyStores = $order->storeOrders->where('fulfillment_status', 'ready_for_pickup')->count();
                                    @endphp
                                    @if ($order->payment_status === 'paid' && $order->order_status === 'pending_payment')
                                        <p class="mt-1 text-xs text-amber-700">Pembayaran sudah dikonfirmasi; pemrosesan toko masih perlu diperbarui.</p>
                                    @elseif ($order->store_orders_count > 0)
                                        <p class="mt-1 text-xs text-gray-600">{{ $completedStores }}/{{ $order->store_orders_count }} toko selesai{{ $readyStores ? ", {$readyStores} siap diambil" : '' }}.</p>
                                    @endif
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
            <div class="mt-6">{{ $orders->links() }}</div>
        @endif
    </section>
</x-catalog-layout>
