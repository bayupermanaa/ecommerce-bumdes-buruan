@props(['title' => 'Katalog Produk'])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title }} | {{ config('app.name', 'BUMDes Buruan') }}</title>
        <link rel="icon" type="image/png" href="{{ asset('images/logo-bumdes.png') }}">
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
        <style>[x-cloak] { display: none !important; }</style>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-50 font-sans text-slate-900">
        <header x-data="{ marketplaceMenuOpen: false }" class="sticky top-0 z-40 border-b border-slate-200 bg-white/95 shadow-sm backdrop-blur">
            <div class="mx-auto flex min-h-20 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
                <a href="{{ route('catalog.index') }}" class="flex items-center gap-3">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200"><x-bumdes-logo class="h-11 w-11" /></span>
                    <span class="hidden sm:block"><span class="block text-sm font-bold tracking-tight text-slate-950">BUMDes Desa Buruan</span><span class="block text-xs text-slate-500">Marketplace produk lokal</span></span>
                </a>

                <nav class="hidden items-center gap-2 text-sm font-semibold md:flex lg:gap-4" aria-label="Navigasi marketplace desktop">
                    <a href="{{ route('catalog.index') }}" class="inline text-slate-600 hover:text-emerald-700">Katalog</a>
                    @auth
                        @if (auth()->user()->role === 'pelanggan')
                            <a href="{{ route('cart.index') }}" class="rounded-xl px-2.5 py-2 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">Keranjang</a>
                            <a href="{{ route('orders.index') }}" class="rounded-xl px-2.5 py-2 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">Pesanan</a>
                        @endif
                        <a href="{{ route('dashboard') }}" class="rounded-xl bg-slate-950 px-3 py-2 text-white hover:bg-slate-800">Dashboard</a>
                        <form method="POST" action="{{ route('logout') }}" data-testid="customer-logout-desktop">
                            @csrf
                            <button type="submit" class="rounded-xl border border-slate-300 px-3 py-2 text-slate-700 transition hover:border-red-200 hover:bg-red-50 hover:text-red-700">Keluar</button>
                        </form>
                    @else
                        <a href="{{ route('login') }}" class="rounded-xl bg-slate-950 px-3 py-2 text-white hover:bg-slate-800">Masuk</a>
                    @endauth
                </nav>

                <button type="button" class="inline-flex items-center gap-2 rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 md:hidden" @click="marketplaceMenuOpen = ! marketplaceMenuOpen" :aria-expanded="marketplaceMenuOpen.toString()" aria-controls="marketplace-mobile-menu">
                    <span>Menu</span>
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" /></svg>
                </button>
            </div>

            <nav id="marketplace-mobile-menu" x-cloak x-show="marketplaceMenuOpen" @click.outside="marketplaceMenuOpen = false" class="border-t border-slate-200 bg-white px-4 py-4 md:hidden" aria-label="Navigasi marketplace mobile">
                <div class="mx-auto grid max-w-7xl gap-2 text-sm font-semibold">
                    <a href="{{ route('catalog.index') }}" class="rounded-xl px-3 py-2.5 text-slate-700 hover:bg-emerald-50 hover:text-emerald-700">Katalog</a>
                    @auth
                        @if (auth()->user()->role === 'pelanggan')
                            <a href="{{ route('cart.index') }}" class="rounded-xl px-3 py-2.5 text-slate-700 hover:bg-emerald-50 hover:text-emerald-700">Keranjang</a>
                            <a href="{{ route('orders.index') }}" class="rounded-xl px-3 py-2.5 text-slate-700 hover:bg-emerald-50 hover:text-emerald-700">Pesanan</a>
                        @endif
                        <a href="{{ route('dashboard') }}" class="rounded-xl px-3 py-2.5 text-slate-700 hover:bg-slate-100">Dashboard</a>
                        <form method="POST" action="{{ route('logout') }}" data-testid="customer-logout-mobile">
                            @csrf
                            <button type="submit" class="w-full rounded-xl border border-red-200 px-3 py-2.5 text-left text-red-700 hover:bg-red-50">Keluar</button>
                        </form>
                    @else
                        <a href="{{ route('login') }}" class="rounded-xl bg-slate-950 px-3 py-2.5 text-center text-white hover:bg-slate-800">Masuk</a>
                    @endauth
                </div>
            </nav>
        </header>

        <main>
            {{ $slot }}
        </main>
    </body>
</html>
