@props(['title' => 'Portal Penjual'])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="admin-scrollbar-hidden">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $title }} | {{ config('app.name', 'BUMDes Desa Buruan') }}</title>
        <link rel="icon" type="image/png" href="{{ asset('images/logo-bumdes.png') }}">
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
        <style>[x-cloak] { display: none !important; }</style>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="seller-portal bg-slate-100 font-sans antialiased text-slate-900">
        <div x-data="{ umkmSidebarOpen: false }" class="min-h-screen">
            <div x-cloak x-show="umkmSidebarOpen" class="fixed inset-0 z-40 bg-slate-950/50 lg:hidden" @click="umkmSidebarOpen = false"></div>

            <aside class="fixed inset-y-0 left-0 z-50 flex w-72 -translate-x-full flex-col bg-slate-950 text-slate-200 shadow-2xl transition-transform duration-200 lg:translate-x-0" :class="{ 'translate-x-0': umkmSidebarOpen }">
                <div class="flex h-20 shrink-0 items-center gap-3 border-b border-white/10 px-6">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white shadow-sm">
                        <x-bumdes-logo class="h-11 w-11" />
                    </div>
                    <div>
                        <p class="text-sm font-semibold tracking-wide text-white">BUMDes Desa Buruan</p>
                        <p class="text-xs text-emerald-300">Portal Penjual</p>
                    </div>
                    <button type="button" class="ml-auto rounded-lg p-2 text-slate-400 hover:bg-white/10 hover:text-white lg:hidden" @click="umkmSidebarOpen = false" aria-label="Tutup menu"><span class="text-xl leading-none">&times;</span></button>
                </div>

                <nav class="admin-scrollbar-hidden min-h-0 flex-1 space-y-6 overflow-y-auto px-4 py-6" aria-label="Navigasi Penjual">
                    <div>
                        <p class="px-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Ringkasan</p>
                        <a href="{{ route('umkm.dashboard') }}" @if(request()->routeIs('umkm.dashboard')) aria-current="page" @endif class="mt-2 flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('umkm.dashboard') ? 'bg-white text-slate-950 shadow-lg' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}"><x-admin-icon name="layout-dashboard" /><span>Dashboard</span></a>
                    </div>

                    <div>
                        <p class="px-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Operasional</p>
                        <div class="mt-2 space-y-1">
                            @foreach ([
                                ['umkm.stores.*', 'umkm.stores.index', 'Profil Toko', 'store'],
                                ['umkm.products.*', 'umkm.products.index', 'Produk Saya', 'package-check'],
                                ['umkm.orders.*', 'umkm.orders.index', 'Pesanan Masuk', 'credit-card'],
                            ] as [$activeRoute, $routeName, $label, $icon])
                                <a href="{{ route($routeName) }}" @if(request()->routeIs($activeRoute)) aria-current="page" @endif class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs($activeRoute) ? 'bg-white text-slate-950 shadow-lg' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}"><x-admin-icon :name="$icon" /><span>{{ $label }}</span></a>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <p class="px-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Keuangan</p>
                        <div class="mt-2 space-y-1">
                            @foreach ([
                                ['umkm.commission-agreements.*', 'umkm.commission-agreements.index', 'Perjanjian Komisi', 'handshake'],
                                ['umkm.recipient-bank-accounts.*', 'umkm.recipient-bank-accounts.index', 'Rekening Pencairan', 'wallet'],
                                ['umkm.disbursements.*', 'umkm.disbursements.index', 'Pencairan Dana', 'banknote'],
                            ] as [$activeRoute, $routeName, $label, $icon])
                                <a href="{{ route($routeName) }}" @if(request()->routeIs($activeRoute)) aria-current="page" @endif class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs($activeRoute) ? 'bg-white text-slate-950 shadow-lg' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}"><x-admin-icon :name="$icon" /><span>{{ $label }}</span></a>
                            @endforeach
                        </div>
                    </div>
                </nav>

                <div class="shrink-0 border-t border-white/10 p-4">
                    <div class="flex items-center gap-3 rounded-xl bg-white/5 p-3">
                        <div class="flex h-9 w-9 items-center justify-center rounded-full bg-emerald-400 text-sm font-bold text-slate-950">{{ str(auth()->user()->name)->substr(0, 1)->upper() }}</div>
                        <div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold text-white">{{ auth()->user()->name }}</p><p class="text-xs text-slate-400">Penjual</p></div>
                        <a href="{{ route('profile.edit') }}" class="text-xs text-slate-400 hover:text-white" aria-label="Buka profil">Profil</a>
                    </div>
                </div>
            </aside>

            <div class="lg:pl-72">
                <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
                    <div class="flex h-20 items-center justify-between px-4 sm:px-6 lg:px-8">
                        <div class="flex items-center gap-3">
                            <button type="button" class="rounded-xl border border-slate-200 p-2.5 text-slate-600 hover:bg-slate-50 lg:hidden" @click="umkmSidebarOpen = true" aria-label="Buka menu"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" /></svg></button>
                            <div><p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-600">Portal Penjual</p><h1 class="text-lg font-bold text-slate-900 sm:text-xl">{{ $title }}</h1></div>
                        </div>
                        <div class="flex items-center gap-2 sm:gap-3"><a href="{{ route('catalog.index') }}" class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800 transition hover:bg-emerald-100">Katalog / Belanja</a><span class="hidden text-right sm:block"><span class="block text-sm font-semibold text-slate-800">{{ auth()->user()->name }}</span><span class="block text-xs text-slate-500">Kelola toko dan pesanan</span></span><form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-slate-300 hover:bg-slate-50">Keluar</button></form></div>
                    </div>
                </header>
                <main class="min-h-[calc(100vh-5rem)] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">{{ $slot }}</main>
            </div>
        </div>
    </body>
</html>
