<x-catalog-layout :title="$product->name">
    <section class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
        <a href="{{ route('catalog.index') }}" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600 hover:border-emerald-200 hover:text-emerald-700">&larr; Kembali ke katalog</a>

        <div class="mt-6 grid gap-8 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8 lg:grid-cols-2 lg:p-10">
            <div>
                @if ($product->image_path)
                    <img src="{{ \Illuminate\Support\Facades\Storage::url($product->image_path) }}" alt="{{ $product->name }}" class="aspect-square w-full rounded-2xl object-cover">
                @else
                    <div class="flex aspect-square items-center justify-center rounded-lg bg-gray-100 text-sm font-medium text-gray-500">Foto produk belum tersedia</div>
                @endif
            </div>
            <div class="flex flex-col">
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-emerald-700">{{ $product->category->name }}</p>
                <h1 class="mt-2 text-3xl font-bold tracking-tight text-gray-900">{{ $product->name }}</h1>
                <p class="mt-3 text-sm text-gray-600">Dijual oleh {{ $product->umkmStore->name }}</p>
                @php($activeVariants = $product->activeVariants->values())
                @php($min = $activeVariants->min('price') ?: $product->price)
                @php($max = $activeVariants->max('price') ?: $product->price)
                <p id="effective-price" class="mt-6 text-3xl font-bold text-slate-950">Rp {{ number_format((float) $min, 0, ',', '.') }}@if ((float) $min !== (float) $max) – Rp {{ number_format((float) $max, 0, ',', '.') }}@endif</p>
                @if ($activeVariants->isNotEmpty())
                    <div class="mt-5"><h2 class="font-semibold text-gray-900">Pilih ukuran</h2><div class="mt-2 grid gap-2 sm:grid-cols-2">@foreach ($activeVariants as $variant)<button type="button" data-price="{{ $variant->price }}" data-stock="{{ $variant->stock }}" data-variant-id="{{ $variant->id }}" class="variant-choice rounded-xl border border-slate-200 p-3 text-left hover:border-emerald-400 disabled:cursor-not-allowed disabled:opacity-50" @disabled($variant->stock < 1)><span class="block font-semibold">{{ $variant->label }}</span><span class="text-sm text-slate-600">Rp {{ number_format((float) $variant->price, 0, ',', '.') }} · {{ $variant->stock > 0 ? 'stok '.$variant->stock : 'stok habis' }}</span></button>@endforeach</div></div>
                @endif
                <p class="mt-2 font-medium {{ $product->stock > 0 ? 'text-green-700' : 'text-red-700' }}">
                    {{ $product->stock > 0 ? "Tersedia, stok {$product->stock}" : 'Stok habis' }}
                </p>
                <div class="mt-6 border-t border-gray-200 pt-6">
                    <h2 class="font-semibold text-gray-900">Deskripsi Produk</h2>
                    <p class="mt-2 whitespace-pre-line text-gray-700">{{ $product->description ?: 'Deskripsi produk belum tersedia.' }}</p>
                </div>
                @if ($activeVariants->isNotEmpty())
                    @auth
                        @if (\App\Support\UserRoles::canBuy(auth()->user()->role))
                            <form method="POST" action="{{ route('cart.items.store', $product) }}" class="mt-8 flex flex-wrap items-end gap-3">
                                @csrf
                                <input type="hidden" name="product_variant_id" id="product_variant_id">
                                <div><label for="quantity" class="text-sm font-medium text-gray-700">Jumlah</label><input id="quantity" name="quantity" type="number" min="1" value="1" class="mt-1 block w-24 rounded-md border-gray-300 shadow-sm" required></div>
                                <button id="add-variant-button" type="submit" disabled class="rounded-xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">Pilih ukuran terlebih dahulu</button>
                            </form>
                        @endif
                    @else
                        <a href="{{ route('login') }}" class="mt-8 inline-flex rounded-md bg-green-700 px-5 py-2 text-sm font-semibold text-white">Masuk untuk membeli</a>
                    @endauth
                @elseif ($product->stock > 0)
                    @auth
                        @if (\App\Support\UserRoles::canBuy(auth()->user()->role))
                            <form method="POST" action="{{ route('cart.items.store', $product) }}" class="relative z-20 isolate mt-8 flex flex-wrap items-end gap-3 pointer-events-auto">
                                @csrf
                                <div>
                                    <label for="quantity" class="text-sm font-medium text-gray-700">Jumlah</label>
                                    <input id="quantity" name="quantity" type="number" min="1" max="{{ $product->stock }}" value="1" class="mt-1 block w-24 rounded-md border-gray-300 shadow-sm focus:border-green-600 focus:ring-green-600" required>
                                </div>
                                <button type="submit" class="relative z-30 rounded-xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-700 pointer-events-auto">Tambah ke Keranjang</button>
                            </form>
                        @endif
                    @else
                        <a href="{{ route('login') }}" class="mt-8 inline-flex rounded-md bg-green-700 px-5 py-2 text-sm font-semibold text-white hover:bg-green-600">Masuk untuk membeli</a>
                    @endauth
                @endif
                <p class="mt-auto pt-8 text-sm text-gray-500">Harga dan ketersediaan stok akan diperiksa kembali saat checkout.</p>
            </div>
        </div>
    </section>
    @if ($activeVariants->isNotEmpty())
        <script>
            document.querySelectorAll('.variant-choice').forEach(button => button.addEventListener('click', () => {
                document.querySelectorAll('.variant-choice').forEach(item => item.classList.remove('border-emerald-500', 'bg-emerald-50'));
                button.classList.add('border-emerald-500', 'bg-emerald-50');
                document.getElementById('product_variant_id').value = button.dataset.variantId;
                document.getElementById('quantity').max = button.dataset.stock;
                document.getElementById('effective-price').textContent = 'Rp ' + Number(button.dataset.price).toLocaleString('id-ID');
                const submit = document.getElementById('add-variant-button'); submit.disabled = false; submit.textContent = 'Tambah ke Keranjang';
            }));
        </script>
    @endif
</x-catalog-layout>
