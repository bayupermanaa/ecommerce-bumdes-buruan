<x-catalog-layout title="Keranjang Belanja">
    <section class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">Belanja Anda</p>
                <h1 class="mt-1 text-3xl font-bold tracking-tight text-slate-950">Keranjang Belanja</h1>
            </div>
            <a href="{{ route('catalog.index') }}" class="text-sm font-semibold text-gray-700 hover:text-gray-950">&larr; Lanjut Belanja</a>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-md bg-green-50 p-4 text-sm text-green-800">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="mt-6 rounded-md bg-red-50 p-4 text-sm text-red-800">
                {{ $errors->first() }}
            </div>
        @endif

        @if (! $cart || $cart->items->isEmpty())
            <div class="mt-8 rounded-lg border border-dashed border-gray-300 bg-white px-6 py-16 text-center shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">Keranjang Anda masih kosong</h2>
                <p class="mt-2 text-sm text-gray-600">Temukan produk Penjual Desa Buruan yang Anda sukai.</p>
                <a href="{{ route('catalog.index') }}" class="mt-6 inline-flex rounded-md bg-gray-800 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700">Lihat Katalog</a>
            </div>
        @else
            @php($total = 0)
            <div class="mt-8 grid gap-6 lg:grid-cols-[1fr_20rem]">
                <div class="space-y-4">
                    @foreach ($cart->items as $item)
                        @php($stockSource = $item->variant ?? $item->product)
                        @php($available = $item->product->status === 'approved' && $item->product->umkmStore?->status === 'approved' && ($item->product_variant_id === null ? ! $item->product->variants_exists : ($item->variant?->product_id === $item->product_id && $item->variant?->is_active)) && $stockSource->stock >= $item->quantity)
                        @php($subtotal = (float) $stockSource->price * $item->quantity)
                        @php($total += $subtotal)
                        <article class="flex gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                            @if ($item->product->image_path)
                                <img src="{{ \Illuminate\Support\Facades\Storage::url($item->product->image_path) }}" alt="{{ $item->product->name }}" class="h-20 w-20 rounded-md object-cover sm:h-24 sm:w-24">
                            @else
                                <div class="flex h-20 w-20 shrink-0 items-center justify-center rounded-md bg-gray-100 text-center text-xs text-gray-500 sm:h-24 sm:w-24">Tanpa foto</div>
                            @endif
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h2 class="font-semibold text-gray-900">{{ $item->product->name }}</h2>
                                        @if($item->product_variant_id)<p class="mt-1 text-sm text-emerald-700">Ukuran: {{ $item->variant?->label ?? 'Tidak tersedia' }}</p>@endif
                                        <p class="mt-1 text-sm text-gray-600">{{ $item->product->umkmStore?->name ?? 'Toko tidak tersedia' }}</p>
                                    </div>
                                    <p class="font-semibold text-gray-900">Rp {{ number_format($subtotal, 0, ',', '.') }}</p>
                                </div>
                                <p class="mt-2 text-sm text-gray-700">Harga saat ini: Rp {{ number_format((float) $stockSource->price, 0, ',', '.') }}</p>
                                @if ($available)
                                    <p class="mt-1 text-xs text-green-700">Stok tersedia: {{ $stockSource->stock }}</p>
                                @else
                                    <p class="mt-1 text-xs font-medium text-red-700">Produk tidak tersedia saat ini. Hapus item ini sebelum checkout.</p>
                                @endif
                                <div class="mt-4 flex flex-wrap items-end gap-3">
                                    @if ($available)
                                        <form method="POST" action="{{ route('cart.items.update', $item) }}" class="flex items-end gap-2">
                                            @csrf
                                            @method('PATCH')
                                            <div>
                                                <label for="quantity-{{ $item->id }}" class="text-xs font-medium text-gray-700">Jumlah</label>
                                                <input id="quantity-{{ $item->id }}" name="quantity" type="number" min="1" max="{{ $stockSource->stock }}" value="{{ $item->quantity }}" class="mt-1 block w-20 rounded-md border-gray-300 text-sm shadow-sm focus:border-green-600 focus:ring-green-600" required>
                                            </div>
                                            <button type="submit" class="rounded-md border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50">Perbarui</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('cart.items.destroy', $item) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="rounded-md px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-50">Hapus</button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>

                <aside class="h-fit rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">Ringkasan Keranjang</h2>
                    <div class="mt-5 flex items-center justify-between border-t border-gray-200 pt-4 text-lg font-bold text-gray-900">
                        <span>Total</span>
                        <span>Rp {{ number_format($total, 0, ',', '.') }}</span>
                    </div>
                    <p class="mt-4 text-sm text-gray-600">Harga dan stok akan diperiksa kembali saat checkout.</p>
                    <a href="{{ route('checkout.index') }}" class="mt-5 inline-flex w-full items-center justify-center rounded-xl bg-slate-950 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-800">Lanjut ke Checkout</a>
                    <form method="POST" action="{{ route('cart.clear') }}" class="mt-6">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-sm font-semibold text-red-700 hover:text-red-900">Kosongkan Keranjang</button>
                    </form>
                </aside>
            </div>
        @endif
    </section>
</x-catalog-layout>
