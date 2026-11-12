<x-umkm-layout title="Detail Pesanan">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-600">Operasional pickup</p>
                <h2 class="mt-1 text-2xl font-bold text-slate-900">Detail Pesanan Masuk</h2>
                <p class="mt-1 text-sm text-slate-500">{{ $storeOrder->store_order_number }}</p>
            </div>
            <a href="{{ route('umkm.orders.index') }}" class="text-sm font-semibold text-gray-700 hover:text-gray-950">&larr; Pesanan Masuk</a>
        </div>
    <div class="mx-auto max-w-5xl space-y-6">
            <x-order-timeline :order="$storeOrder->order" :store-order="$storeOrder" />
            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            @if ($errors->has('order') || $errors->has('fulfillment_status'))
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">{{ $errors->first('order') ?: $errors->first('fulfillment_status') }}</div>
            @endif

            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-widest text-green-700">Pesanan Pickup</p>
                        <h3 class="mt-1 text-xl font-semibold text-gray-900">{{ $storeOrder->store_order_number }}</h3>
                        <p class="mt-1 text-sm text-gray-600">Pesanan pelanggan: {{ $storeOrder->order->order_number }}</p>
                    </div>
                    <p class="text-sm text-gray-600">{{ $storeOrder->order->created_at->format('d-m-Y H:i') }}</p>
                </div>
                <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-gray-500">Toko</dt><dd class="mt-1 font-medium text-gray-900">{{ $storeOrder->store_name_snapshot }}</dd></div>
                    <div><dt class="text-gray-500">Pelanggan</dt><dd class="mt-1 font-medium text-gray-900">{{ $storeOrder->order->recipient_name }}</dd></div>
                    <div><dt class="text-gray-500">Nomor telepon pickup</dt><dd class="mt-1 font-medium text-gray-900">{{ $storeOrder->order->recipient_phone }}</dd></div>
                    <div><dt class="text-gray-500">Status pembayaran</dt><dd class="mt-1 font-medium text-gray-900">{{ $storeOrder->order->paymentStatusLabel() }}</dd></div>
                    <div><dt class="text-gray-500">Status fulfillment</dt><dd class="mt-1 font-medium text-gray-900">{{ $storeOrder->fulfillmentStatusLabel() }}</dd></div>
                </dl>
                @if ($storeOrder->order->payment_status === 'paid' && $storeOrder->fulfillment_status === 'pending_payment')
                    <p class="mt-5 rounded-md bg-amber-50 p-3 text-sm text-amber-800">Pembayaran sudah dikonfirmasi, tetapi status fulfillment data lama belum direkonsiliasi. Pesanan ini belum dapat diproses melalui halaman ini.</p>
                @elseif ($storeOrder->order->payment_status !== 'paid')
                    <p class="mt-5 rounded-md bg-amber-50 p-3 text-sm text-amber-800">Pesanan belum dapat diproses sampai pembayaran dikonfirmasi Admin BUMDes.</p>
                @endif

                @if ($storeOrder->fulfillment_status === 'awaiting_processing')
                    <form method="POST" action="{{ route('umkm.orders.start-processing', $storeOrder) }}" class="mt-5">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="rounded-md bg-indigo-700 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-indigo-800">Mulai Diproses</button>
                    </form>
                @elseif ($storeOrder->fulfillment_status === 'processing')
                    <form method="POST" action="{{ route('umkm.orders.ready-for-pickup', $storeOrder) }}" class="mt-5">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="rounded-md bg-green-700 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-green-800">Tandai Siap Diambil</button>
                    </form>
                @elseif ($storeOrder->fulfillment_status === 'ready_for_pickup')
                    <form method="POST" action="{{ route('umkm.orders.complete', $storeOrder) }}" class="mt-5" onsubmit="return confirm('Pastikan barang benar-benar sudah diserahkan kepada pelanggan sebelum menyelesaikan pesanan ini.')">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700">Konfirmasi Barang Diserahkan</button>
                    </form>
                @endif
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <h3 class="text-lg font-semibold text-gray-900">Produk Pesanan</h3>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="text-left text-gray-600"><tr><th class="px-3 py-2">Produk</th><th class="px-3 py-2">SKU</th><th class="px-3 py-2">Harga</th><th class="px-3 py-2">Jumlah</th><th class="px-3 py-2 text-right">Subtotal</th></tr></thead>
                        <tbody class="divide-y divide-gray-200 text-gray-900">
                            @foreach ($storeOrder->items as $item)
                                <tr><td class="px-3 py-3">{{ $item->product_name_snapshot }}@if($item->custom_specifications)<p class="mt-1 text-xs text-emerald-700">Custom: {{ data_get($item->custom_specifications, 'notes') }}</p>@endif</td><td class="px-3 py-3">{{ $item->sku_snapshot }}</td><td class="px-3 py-3">Rp {{ number_format((float) $item->unit_price, 0, ',', '.') }}</td><td class="px-3 py-3">{{ $item->quantity }}</td><td class="px-3 py-3 text-right">Rp {{ number_format((float) $item->line_total, 0, ',', '.') }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-5 flex justify-between border-t border-gray-200 pt-4 text-sm font-semibold text-gray-900"><span>Total pesanan toko</span><span>Rp {{ number_format((float) $storeOrder->total, 0, ',', '.') }}</span></div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <h3 class="text-lg font-semibold text-gray-900">Riwayat Status</h3>
                @if ($storeOrder->statusLogs->isEmpty())
                    <p class="mt-4 text-sm text-gray-600">Belum ada perubahan status fulfillment.</p>
                @else
                    <div class="mt-4 space-y-3">
                        @foreach ($storeOrder->statusLogs as $log)
                            <div class="rounded-md border border-gray-200 p-4 text-sm">
                                <p class="font-medium text-gray-900">{{ \App\Models\StoreOrder::fulfillmentStatusLabelFor($log->from_status) }} &rarr; {{ \App\Models\StoreOrder::fulfillmentStatusLabelFor($log->to_status) }}</p>
                                <p class="mt-1 text-gray-600">{{ $log->created_at->format('d-m-Y H:i') }}{{ $log->actor ? ' oleh '.$log->actor->name : '' }}</p>
                                @if ($log->notes)<p class="mt-1 text-gray-600">{{ $log->notes }}</p>@endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
    </div>
</x-umkm-layout>
