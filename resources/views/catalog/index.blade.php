<x-catalog-layout title="Katalog Produk">
    <section class="overflow-hidden bg-slate-950">
        <div class="mx-auto grid max-w-7xl gap-8 px-4 py-14 sm:px-6 lg:grid-cols-[1.2fr_.8fr] lg:items-center lg:px-8 lg:py-20">
            <div><p class="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-300">Belanja dari desa, dukung UMKM</p><h1 class="mt-3 max-w-3xl text-4xl font-bold tracking-tight text-white sm:text-5xl">Produk pilihan lokal, sampai ke tangan Anda.</h1><p class="mt-5 max-w-2xl text-base leading-7 text-slate-300">Temukan produk dari UMKM Desa Buruan yang telah diverifikasi BUMDes. Pesan dengan mudah dan pilih pengambilan langsung di toko.</p><a href="#produk" class="mt-7 inline-flex rounded-xl bg-emerald-400 px-5 py-3 text-sm font-bold text-slate-950 hover:bg-emerald-300">Jelajahi Produk <span class="ml-2">↓</span></a></div>
            <div class="hidden justify-end lg:flex"><div class="w-64 rounded-3xl border border-white/10 bg-white/10 p-6 shadow-2xl"><p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Marketplace lokal</p><p class="mt-8 text-6xl font-bold text-emerald-300">01</p><p class="mt-2 text-sm text-slate-300">Satu tempat untuk produk terbaik dari pelaku UMKM Desa Buruan.</p></div></div>
        </div>
    </section>

    <section id="produk" class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <form method="GET" action="{{ route('catalog.index') }}" class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:grid-cols-4">
            <div class="md:col-span-2">
                <label for="search" class="text-sm font-medium text-gray-700">Cari Produk</label>
                <input id="search" name="search" value="{{ $filters['search'] }}" type="search" placeholder="Nama produk" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-600 focus:ring-green-600">
            </div>
            <div>
                <label for="category" class="text-sm font-medium text-gray-700">Kategori</label>
                <select id="category" name="category" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-600 focus:ring-green-600">
                    <option value="">Semua kategori</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) $filters['category'] === (string) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="sort" class="text-sm font-medium text-gray-700">Urutkan</label>
                <select id="sort" name="sort" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-600 focus:ring-green-600">
                    <option value="latest" @selected($filters['sort'] === 'latest')>Produk terbaru</option>
                    <option value="price_asc" @selected($filters['sort'] === 'price_asc')>Harga terendah</option>
                    <option value="price_desc" @selected($filters['sort'] === 'price_desc')>Harga tertinggi</option>
                </select>
            </div>
            <div class="md:col-span-4">
                <button type="submit" class="rounded-md bg-gray-800 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700">Terapkan Filter</button>
                @if ($filters['search'] !== '' || $filters['category'] !== '' || $filters['sort'] !== 'latest')
                    <a href="{{ route('catalog.index') }}" class="ml-3 text-sm font-medium text-gray-600 hover:text-gray-900">Reset</a>
                @endif
            </div>
        </form>

        @if ($products->isEmpty())
            <div class="mt-8 rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center">
                <h2 class="text-lg font-semibold text-gray-900">Produk belum ditemukan</h2>
                <p class="mt-2 text-sm text-gray-600">Coba ubah kata kunci pencarian atau filter kategori Anda.</p>
            </div>
        @else
            <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($products as $product)
                    <article class="group overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-1 hover:border-emerald-200 hover:shadow-lg">
                        @if ($product->image_path)
                            <img src="{{ \Illuminate\Support\Facades\Storage::url($product->image_path) }}" alt="{{ $product->name }}" class="h-52 w-full object-cover transition duration-300 group-hover:scale-105">
                        @else
                            <div class="flex h-48 items-center justify-center bg-gray-100 text-sm font-medium text-gray-500">Foto produk belum tersedia</div>
                        @endif
                        <div class="p-5">
                            <p class="text-sm text-green-700">{{ $product->category->name }}</p>
                            <h2 class="mt-1 text-lg font-semibold text-gray-900">{{ $product->name }}</h2>
                            <p class="mt-1 text-sm text-gray-600">{{ $product->umkmStore->name }}</p>
                            <p class="mt-4 text-lg font-bold text-gray-900">Rp {{ number_format((float) $product->price, 0, ',', '.') }}</p>
                            <p class="mt-1 text-sm {{ $product->stock > 0 ? 'text-green-700' : 'text-red-700' }}">{{ $product->stock > 0 ? "Stok tersedia: {$product->stock}" : 'Stok habis' }}</p>
                            <a href="{{ route('catalog.show', $product->slug) }}" class="mt-5 inline-flex w-full items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Lihat Detail</a>
                            @if ($product->stock > 0)
                                @auth
                                    @if (auth()->user()->role === 'pelanggan')
                        <form method="POST" action="{{ route('cart.items.store', $product) }}" class="relative z-20 isolate mt-2 pointer-events-auto">
                                            @csrf
                                            <input type="hidden" name="quantity" value="1">
                                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">Tambah ke Keranjang</button>
                                        </form>
                                    @endif
                                @else
                                    <a href="{{ route('login') }}" class="mt-2 inline-flex w-full items-center justify-center rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Masuk untuk membeli</a>
                                @endauth
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="mt-8">
                {{ $products->links() }}
            </div>
        @endif
    </section>
</x-catalog-layout>
