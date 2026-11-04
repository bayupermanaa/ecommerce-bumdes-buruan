<x-catalog-layout :title="$order->order_number">
    <section class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
        <a href="{{ route('orders.index') }}" class="inline-flex rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600 hover:border-emerald-200 hover:text-emerald-700">&larr; Pesanan Saya</a>

        @if (session('status'))
            <div class="mt-6 rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
        @endif

        @if ($errors->has('order'))
            <div class="mt-6 rounded-md bg-red-50 p-4 text-sm text-red-800">{{ $errors->first('order') }}</div>
        @endif

        <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <div class="space-y-6">
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">Pesanan Berhasil Dibuat</p>
                    <h1 class="mt-2 text-2xl font-bold text-gray-900">{{ $order->order_number }}</h1>
                    <p class="mt-2 text-sm text-gray-600">Dibuat {{ $order->created_at->format('d-m-Y H:i') }} | Metode: Ambil di Toko UMKM</p>
                    <p class="mt-2 text-sm font-medium {{ $order->order_status === 'cancelled' ? 'text-red-700' : ($order->order_status === 'completed' ? 'text-green-700' : 'text-amber-700') }}">Status pesanan: {{ $order->orderStatusLabel() }}</p>
                    @if ($order->cancelled_at)
                        <p class="mt-1 text-sm text-gray-600">Dibatalkan pada {{ $order->cancelled_at->format('d-m-Y H:i') }}.</p>
                    @endif
                    @if ($order->cancellation_reason)
                        <p class="mt-1 text-sm text-gray-600">Alasan pembatalan: {{ $order->cancellation_reason }}</p>
                    @endif
                    <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-2">
                        <div><dt class="text-gray-500">Penerima</dt><dd class="mt-1 font-medium text-gray-900">{{ $order->recipient_name }}</dd></div>
                        <div><dt class="text-gray-500">Nomor telepon</dt><dd class="mt-1 font-medium text-gray-900">{{ $order->recipient_phone }}</dd></div>
                    </dl>
                </section>

                @foreach ($order->storeOrders as $storeOrder)
                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="font-semibold text-gray-900">{{ $storeOrder->store_name_snapshot }}</h2>
                                <p class="mt-1 text-sm text-gray-600">{{ $storeOrder->store_address_snapshot ?: 'Alamat toko tidak tersedia.' }}</p>
                                @if ($storeOrder->store_phone_snapshot)
                                    <p class="mt-1 text-sm text-gray-600">{{ $storeOrder->store_phone_snapshot }}</p>
                                @endif
                            </div>
                            <p class="text-sm font-medium uppercase {{ $storeOrder->fulfillment_status === 'cancelled' ? 'text-red-700' : ($storeOrder->fulfillment_status === 'completed' ? 'text-green-700' : 'text-amber-700') }}">{{ $storeOrder->fulfillmentStatusLabel() }}</p>
                        </div>
                        <div class="mt-5 divide-y divide-gray-100 border-y border-gray-100">
                            @foreach ($storeOrder->items as $item)
                                <div class="flex items-start justify-between gap-4 py-4 text-sm">
                                    <div>
                                        <p class="font-medium text-gray-900">{{ $item->product_name_snapshot }}</p>
                                        <p class="mt-1 text-gray-600">{{ $item->sku_snapshot }} | {{ $item->quantity }} x Rp {{ number_format((float) $item->unit_price, 0, ',', '.') }}</p>
                                    </div>
                                    <p class="shrink-0 font-medium text-gray-900">Rp {{ number_format((float) $item->line_total, 0, ',', '.') }}</p>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-4 flex justify-between text-sm font-semibold text-gray-900"><span>Total toko</span><span>Rp {{ number_format((float) $storeOrder->total, 0, ',', '.') }}</span></div>
                        @if ($order->payment_status === 'paid' && $storeOrder->fulfillment_status === 'pending_payment')
                            <p class="mt-4 rounded-md bg-amber-50 p-3 text-sm text-amber-800">Pembayaran telah dikonfirmasi. Status pemrosesan toko ini masih perlu diperbarui.</p>
                        @elseif ($storeOrder->fulfillment_status === 'ready_for_pickup')
                            <p class="mt-4 rounded-md bg-green-50 p-3 text-sm text-green-800">Pesanan dari toko ini siap diambil di lokasi toko yang tercantum di atas.</p>
                        @elseif ($storeOrder->fulfillment_status === 'processing')
                            <p class="mt-4 rounded-md bg-amber-50 p-3 text-sm text-amber-800">Pesanan dari toko ini sedang diproses.</p>
                        @endif
                        @if ($storeOrder->statusLogs->isNotEmpty())
                            <div class="mt-4 border-t border-gray-100 pt-4">
                                <h3 class="text-sm font-semibold text-gray-900">Riwayat Status Toko</h3>
                                <div class="mt-2 space-y-2 text-sm text-gray-600">
                                    @foreach ($storeOrder->statusLogs as $log)
                                        <p>{{ \App\Models\StoreOrder::fulfillmentStatusLabelFor($log->from_status) }} &rarr; {{ \App\Models\StoreOrder::fulfillmentStatusLabelFor($log->to_status) }} <span class="text-gray-500">({{ $log->created_at->format('d-m-Y H:i') }})</span></p>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </section>
                @endforeach
            </div>

            <aside class="h-fit rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Ringkasan Pesanan</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex justify-between"><dt class="text-gray-600">Total produk</dt><dd class="font-medium text-gray-900">Rp {{ number_format((float) $order->subtotal, 0, ',', '.') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-600">Pengambilan toko</dt><dd class="font-medium text-gray-900">Rp 0</dd></div>
                    <div class="flex justify-between border-t border-gray-200 pt-3 text-base"><dt class="font-semibold text-gray-900">Total</dt><dd class="font-bold text-gray-900">Rp {{ number_format((float) $order->grand_total, 0, ',', '.') }}</dd></div>
                </dl>
                @php
                    $latestRejectedPayment = $order->payments->firstWhere('status', 'rejected');
                @endphp
                <div class="mt-5 rounded-md {{ $order->order_status === 'cancelled' ? 'bg-red-50 text-red-800' : ($order->payment_status === 'paid' ? 'bg-green-50 text-green-800' : 'bg-amber-50 text-amber-800') }} p-3 text-sm">
                    <p>Status pembayaran: {{ $order->paymentStatusLabel() }}.</p>
                    @if ($order->order_status === 'cancelled')
                        <p class="mt-1">Pesanan telah dibatalkan.</p>
                    @elseif ($order->payment_status === 'pending_confirmation')
                        <p class="mt-1">Pengajuan pembayaran sedang menunggu verifikasi Admin BUMDes.</p>
                    @elseif ($order->payment_status === 'paid' && $order->order_status === 'pending_payment')
                        <p class="mt-1">Pembayaran telah dikonfirmasi. Status pemrosesan pesanan masih perlu diperbarui.</p>
                    @elseif ($latestRejectedPayment)
                        <p class="mt-1">Pengajuan sebelumnya ditolak: {{ $latestRejectedPayment->rejection_reason }}</p>
                    @elseif ($order->payment_status === 'unpaid')
                        <p class="mt-1">Silakan ajukan pembayaran transfer atau pilih tunai saat pickup.</p>
                    @endif
                </div>

                @if ($order->canSubmitPayment())
                    <a href="{{ route('orders.payments.create', $order) }}" class="mt-5 inline-flex w-full justify-center rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-2">Ajukan Pembayaran</a>
                @endif

                @if ($order->canBeCancelled())
                    <form method="POST" action="{{ route('orders.cancel', $order) }}" class="mt-5 border-t border-gray-200 pt-5" onsubmit="return confirm('Batalkan pesanan ini? Stok produk akan dikembalikan.')">
                        @csrf
                        @method('PATCH')
                        <label for="cancellation_reason" class="block text-sm font-medium text-gray-700">Alasan pembatalan <span class="font-normal text-gray-500">(opsional)</span></label>
                        <textarea id="cancellation_reason" name="cancellation_reason" rows="3" maxlength="1000" class="mt-2 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-600 focus:ring-red-600">{{ old('cancellation_reason') }}</textarea>
                        @error('cancellation_reason')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                        <button type="submit" class="mt-3 inline-flex w-full justify-center rounded-md bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800 focus:outline-none focus:ring-2 focus:ring-red-600 focus:ring-offset-2">Batalkan Pesanan</button>
                    </form>
                @endif
            </aside>
        </div>
    </section>
</x-catalog-layout>
