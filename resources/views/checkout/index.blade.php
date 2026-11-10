<x-catalog-layout title="Checkout">
    <section class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">Konfirmasi Belanja</p>
                <h1 class="mt-1 text-3xl font-bold tracking-tight text-slate-950">Checkout</h1>
            </div>
            <a href="{{ route('cart.index') }}" class="text-sm font-semibold text-gray-700 hover:text-gray-950">&larr; Kembali ke Keranjang</a>
        </div>

        @if (! $cart || $cart->items->isEmpty())
            <div class="mt-8 rounded-lg border border-dashed border-gray-300 bg-white px-6 py-16 text-center shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Keranjang Anda masih kosong</h2>
                <p class="mt-2 text-sm text-gray-600">Tambahkan produk terlebih dahulu sebelum membuka checkout.</p>
                <a href="{{ route('catalog.index') }}" class="mt-6 inline-flex rounded-md bg-gray-800 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700">Lihat Katalog</a>
            </div>
        @else
            @if ($hasUnavailableItems)
                <div class="mt-6 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                    Ada produk yang tidak lagi tersedia atau stoknya tidak mencukupi. Perbarui keranjang sebelum membuat pesanan.
                    <a href="{{ route('cart.index') }}" class="ml-1 font-semibold underline">Kembali ke keranjang</a>
                </div>
            @endif

            @if ($errors->any())
                <div class="mt-6 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                    {{ $errors->first() }}
                </div>
            @endif

            <div class="mt-8 grid gap-8 lg:grid-cols-[minmax(0,1fr)_23rem]">
                <div class="space-y-6">
                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                        <h2 class="text-lg font-semibold text-gray-900">Identitas Pelanggan</h2>
                        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                            <div><dt class="text-gray-500">Nama</dt><dd class="mt-1 font-medium text-gray-900">{{ auth()->user()->name }}</dd></div>
                            <div><dt class="text-gray-500">Email</dt><dd class="mt-1 font-medium text-gray-900">{{ auth()->user()->email }}</dd></div>
                        </dl>
                    </section>

                    <form id="checkout-preview" method="POST" action="{{ route('checkout.store') }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                        @csrf
                        <input type="hidden" name="checkout_token" value="{{ $checkoutToken }}">
                        <h2 class="text-lg font-semibold text-gray-900">Metode Penerimaan</h2>
                        <p class="mt-1 text-sm text-gray-600">Pesanan pickup dapat dibuat setelah informasi penerima dilengkapi.</p>
                        <div class="mt-5 grid gap-4 md:grid-cols-2">
                            <label data-fulfillment-card="delivery" class="rounded-lg border border-green-700 bg-green-50 p-4 transition-colors">
                                <input type="radio" name="fulfillment_method" value="delivery" checked data-fulfillment-control class="text-green-700 focus:ring-green-600">
                                <span class="ml-2 font-semibold text-gray-900">Diantar Kurir BUMDes</span>
                                <span class="mt-2 block text-sm text-gray-600">Layanan untuk Kabupaten Gianyar, Bali. Tarif pengiriman belum tersedia.</span>
                            </label>
                            <label data-fulfillment-card="pickup" class="rounded-lg border border-gray-200 bg-white p-4 transition-colors">
                                <input type="radio" name="fulfillment_method" value="pickup" data-fulfillment-control class="text-green-700 focus:ring-green-600">
                                <span class="ml-2 font-semibold text-gray-900">Ambil di Toko Penjual</span>
                                <span class="mt-2 block text-sm text-gray-600">Biaya pengiriman Rp 0 untuk pengambilan langsung.</span>
                            </label>
                        </div>

                        <div class="mt-6 rounded-md bg-gray-50 p-4">
                            <h3 class="font-semibold text-gray-900">Informasi Penerima</h3>
                            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="recipient_name" class="text-sm font-medium text-gray-700">Nama penerima</label>
                                    <input id="recipient_name" name="recipient_name" type="text" autocomplete="name" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-600 focus:ring-green-600">
                                </div>
                                <div>
                                    <label for="recipient_phone" class="text-sm font-medium text-gray-700">Nomor telepon</label>
                                    <input id="recipient_phone" name="recipient_phone" type="tel" autocomplete="tel" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-600 focus:ring-green-600">
                                </div>
                            </div>
                        </div>

                        <div id="delivery-fields" data-fulfillment-section="delivery" class="mt-6 rounded-md bg-gray-50 p-4">
                            <h3 class="font-semibold text-gray-900">Alamat Pengantaran</h3>
                            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="district" class="text-sm font-medium text-gray-700">Kecamatan</label>
                                    <select id="district" name="district" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-600 focus:ring-green-600">
                                        <option value="">Pilih kecamatan</option>
                                        @foreach ($districts as $district)
                                            <option value="{{ $district }}">{{ $district }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label for="village" class="text-sm font-medium text-gray-700">Desa/Kelurahan</label>
                                    <input id="village" name="village" type="text" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-600 focus:ring-green-600">
                                </div>
                                <div class="sm:col-span-2">
                                    <label for="address" class="text-sm font-medium text-gray-700">Alamat lengkap</label>
                                    <textarea id="address" name="address" rows="3" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-600 focus:ring-green-600"></textarea>
                                </div>
                                <div class="sm:col-span-2">
                                    <label for="delivery_note" class="text-sm font-medium text-gray-700">Catatan pengiriman <span class="font-normal text-gray-500">(opsional)</span></label>
                                    <textarea id="delivery_note" name="delivery_note" rows="2" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-600 focus:ring-green-600"></textarea>
                                </div>
                            </div>
                            <p class="mt-4 text-sm text-amber-800">Wilayah dan alamat akan divalidasi kembali saat pembuatan pesanan.</p>
                        </div>

                        <div id="pickup-fields" data-fulfillment-section="pickup" class="mt-6 rounded-md bg-gray-50 p-4" hidden>
                            <h3 class="font-semibold text-gray-900">Informasi Pengambilan di Toko</h3>
                            <p class="mt-1 text-sm text-gray-600">Nama dan nomor telepon penerima tetap diperlukan saat pesanan dibuat.</p>
                            <div class="mt-4 space-y-3 text-sm">
                                @foreach ($storeGroups as $group)
                                    <div class="rounded-md border border-gray-200 bg-white p-3">
                                        <p class="font-medium text-gray-900">{{ $group['store']->name }}</p>
                                        <p class="mt-1 text-gray-600">{{ $group['store']->address ?: 'Alamat toko belum tersedia.' }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </form>
                </div>

                <aside class="space-y-6">
                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 class="text-lg font-semibold text-gray-900">Ringkasan Belanja</h2>
                        <div class="mt-5 space-y-5">
                            @foreach ($storeGroups as $group)
                                <div class="border-b border-gray-100 pb-5 last:border-0 last:pb-0">
                                    <h3 class="font-medium text-gray-900">{{ $group['store']->name }}</h3>
                                    <div class="mt-3 space-y-3">
                                        @foreach ($group['items'] as $item)
                                            @php($stockSource = $item->variant ?? $item->product)
                                            @php($available = $item->product->status === 'approved' && $item->product->umkmStore?->status === 'approved' && ($item->product_variant_id === null ? ! $item->product->variants_exists : ($item->variant?->product_id === $item->product_id && $item->variant?->is_active)) && $stockSource->stock >= $item->quantity)
                                            <div class="flex gap-3 text-sm">
                                                @if ($item->product->image_path)
                                                    <img src="{{ \Illuminate\Support\Facades\Storage::url($item->product->image_path) }}" alt="{{ $item->product->name }}" class="h-12 w-12 rounded object-cover">
                                                @else
                                                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded bg-gray-100 text-[10px] text-gray-500">Tanpa foto</div>
                                                @endif
                                                <div class="min-w-0 flex-1">
                                                    <p class="font-medium text-gray-900">{{ $item->product->name }}</p>
                                                    @if($item->product_variant_id)<p class="text-sm text-emerald-700">Ukuran: {{ $item->variant?->label ?? 'Tidak tersedia' }}</p>@endif
                                                    <p class="text-gray-600">{{ $item->quantity }} x Rp {{ number_format((float) $stockSource->price, 0, ',', '.') }}</p>
                                                    @if (! $available)
                                                        <p class="mt-1 font-medium text-red-700">Tidak tersedia atau stok tidak cukup.</p>
                                                    @endif
                                                </div>
                                                <p class="shrink-0 font-medium text-gray-900">Rp {{ number_format((float) $stockSource->price * $item->quantity, 0, ',', '.') }}</p>
                                            </div>
                                        @endforeach
                                    </div>
                                    <div class="mt-4 flex justify-between text-sm font-semibold text-gray-800"><span>Subtotal toko</span><span>Rp {{ number_format($group['subtotal'], 0, ',', '.') }}</span></div>
                                </div>
                            @endforeach
                        </div>
                    </section>

                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 class="text-lg font-semibold text-gray-900">Ringkasan Biaya</h2>
                        <div class="mt-4 space-y-3 text-sm">
                            <div class="flex justify-between"><span class="text-gray-600">Total produk</span><span class="font-medium text-gray-900">Rp {{ number_format($total, 0, ',', '.') }}</span></div>
                            <div id="pickup-shipping-cost" data-fulfillment-cost="pickup" class="flex justify-between" hidden><span class="text-gray-600">Pengambilan di toko</span><span class="font-medium text-gray-900">Rp 0</span></div>
                            <p id="delivery-rate-notice" data-fulfillment-cost="delivery" class="rounded-md bg-amber-50 p-3 text-amber-800">Tarif pengiriman belum tersedia untuk layanan kurir BUMDes.</p>
                        </div>
                        <button type="submit" form="checkout-preview" data-order-button data-has-unavailable="{{ $hasUnavailableItems ? 'true' : 'false' }}" disabled class="mt-5 w-full rounded-xl bg-slate-950 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-gray-300 disabled:text-gray-600">Buat Pesanan</button>
                        <p id="checkout-order-note" class="mt-2 text-center text-xs text-gray-500">Pemesanan kurir menunggu konfigurasi tarif pengiriman.</p>
                    </section>
                </aside>
            </div>

            <script>
                const initializeCheckout = () => {
                    const controls = document.querySelectorAll('[data-fulfillment-control]');
                    const checkoutForm = document.getElementById('checkout-preview');
                    const deliveryFields = document.getElementById('delivery-fields');
                    const pickupFields = document.getElementById('pickup-fields');
                    const deliveryRateNotice = document.getElementById('delivery-rate-notice');
                    const pickupShippingCost = document.getElementById('pickup-shipping-cost');
                    const deliveryCard = document.querySelector('[data-fulfillment-card="delivery"]');
                    const pickupCard = document.querySelector('[data-fulfillment-card="pickup"]');
                    const orderButton = document.querySelector('[data-order-button]');
                    const orderNote = document.getElementById('checkout-order-note');

                    if (! checkoutForm || ! deliveryFields || ! pickupFields || ! deliveryRateNotice || ! pickupShippingCost || ! deliveryCard || ! pickupCard || ! orderButton || ! orderNote) {
                        return;
                    }

                    const syncCardState = (card, isActive) => {
                        card.classList.toggle('border-green-700', isActive);
                        card.classList.toggle('bg-green-50', isActive);
                        card.classList.toggle('border-gray-200', ! isActive);
                        card.classList.toggle('bg-white', ! isActive);
                    };

                    const syncFulfillmentMethod = () => {
                        const deliverySelected = document.querySelector('[data-fulfillment-control]:checked')?.value === 'delivery';

                        deliveryFields.hidden = ! deliverySelected;
                        pickupFields.hidden = deliverySelected;
                        deliveryRateNotice.hidden = ! deliverySelected;
                        pickupShippingCost.hidden = deliverySelected;
                        syncCardState(deliveryCard, deliverySelected);
                        syncCardState(pickupCard, ! deliverySelected);

                        deliveryFields.querySelectorAll('input, select, textarea').forEach((field) => {
                            field.disabled = ! deliverySelected;
                        });

                        const hasUnavailableItems = orderButton.dataset.hasUnavailable === 'true';
                        const pickupFormIsValid = checkoutForm.checkValidity();
                        orderButton.disabled = deliverySelected || hasUnavailableItems || ! pickupFormIsValid;
                        orderButton.setAttribute('aria-disabled', String(orderButton.disabled));

                        if (deliverySelected) {
                            orderNote.textContent = 'Pemesanan kurir menunggu konfigurasi tarif pengiriman.';
                        } else if (hasUnavailableItems) {
                            orderNote.textContent = 'Perbarui keranjang karena ada produk yang tidak tersedia atau stoknya tidak mencukupi.';
                        } else if (! pickupFormIsValid) {
                            orderNote.textContent = 'Lengkapi nama penerima dan nomor telepon untuk membuat pesanan pickup.';
                        } else {
                            orderNote.textContent = 'Pesanan pickup siap dibuat.';
                        }
                    };

                    controls.forEach((control) => control.addEventListener('change', syncFulfillmentMethod));
                    checkoutForm.addEventListener('input', syncFulfillmentMethod);
                    syncFulfillmentMethod();
                };

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', initializeCheckout);
                } else {
                    initializeCheckout();
                }
            </script>
        @endif
    </section>
</x-catalog-layout>
