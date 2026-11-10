@props(['title' => 'Admin BUMDes'])

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
    <body class="bg-slate-100 font-sans antialiased text-slate-900">
        <div x-data="{ adminSidebarOpen: false }" class="min-h-screen">
            <div x-cloak x-show="adminSidebarOpen" class="fixed inset-0 z-40 bg-slate-950/50 lg:hidden" @click="adminSidebarOpen = false"></div>

            <aside class="fixed inset-y-0 left-0 z-50 flex w-72 -translate-x-full flex-col bg-slate-950 text-slate-200 shadow-2xl transition-transform duration-200 lg:translate-x-0" :class="{ 'translate-x-0': adminSidebarOpen }">
                <div class="flex h-20 shrink-0 items-center gap-3 border-b border-white/10 px-6">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white shadow-sm">
                        <x-bumdes-logo class="h-11 w-11" />
                    </div>
                    <div>
                        <p class="text-sm font-semibold tracking-wide text-white">BUMDes Desa Buruan</p>
                        <p class="text-xs text-slate-400">Panel Admin</p>
                    </div>
                    <button type="button" class="ml-auto rounded-lg p-2 text-slate-400 hover:bg-white/10 hover:text-white lg:hidden" @click="adminSidebarOpen = false" aria-label="Tutup menu">
                        <span class="text-xl leading-none">&times;</span>
                    </button>
                </div>

                <nav class="admin-scrollbar-hidden min-h-0 flex-1 space-y-6 overflow-y-auto px-4 py-6" aria-label="Navigasi Admin BUMDes">
                    <div>
                        <p class="px-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Overview</p>
                        <a href="{{ route('admin.dashboard') }}" class="mt-2 flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('admin.dashboard') ? 'bg-white text-slate-950 shadow-lg shadow-slate-950/20' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}">
                            <x-admin-icon name="layout-dashboard" />
                            <span>Dashboard</span>
                        </a>
                    </div>

                    <div>
                        <p class="px-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Operasional</p>
                        <div class="mt-2 space-y-1">
                            @foreach([
                                ['admin.payments.index', 'Verifikasi Pembayaran', 'credit-card'],
                                ['admin.umkm-stores.index', 'Verifikasi Toko Penjual', 'store'],
                                ['admin.sellers.index', 'Data Penjual', 'users'],
                                ['admin.products.index', 'Verifikasi Produk', 'package-check'],
                                ['admin.product-revisions.index', 'Revisi Produk', 'file-pen'],
                                ['admin.categories.index', 'Kategori Produk', 'tags'],
                            ] as [$routeName, $label, $icon])
                                <a href="{{ route($routeName) }}" @if(request()->routeIs($routeName === 'admin.categories.index' ? 'admin.categories.*' : ($routeName === 'admin.sellers.index' ? 'admin.sellers.*' : $routeName))) aria-current="page" @endif class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs($routeName === 'admin.categories.index' ? 'admin.categories.*' : ($routeName === 'admin.sellers.index' ? 'admin.sellers.*' : $routeName)) ? 'bg-white text-slate-950 shadow-lg shadow-slate-950/20' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}">
                                    <x-admin-icon :name="$icon" />
                                    <span>{{ $label }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <p class="px-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Keuangan</p>
                        <div class="mt-2 space-y-1">
                            @foreach([
                                ['admin.bumdes-bank-accounts.index', 'Rekening BUMDes', 'landmark'],
                                ['admin.commission-agreements.index', 'Perjanjian Komisi', 'handshake'],
                                ['admin.umkm-entitlements.index', 'Rekonsiliasi Hak Penjual', 'badge-check'],
                                ['admin.recipient-bank-accounts.index', 'Rekening Penjual', 'wallet'],
                                ['admin.disbursements.index', 'Pencairan Penjual', 'banknote'],
                            ] as [$routeName, $label, $icon])
                                <a href="{{ route($routeName) }}" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs($routeName) ? 'bg-white text-slate-950 shadow-lg shadow-slate-950/20' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}">
                                    <x-admin-icon :name="$icon" />
                                    <span>{{ $label }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <p class="px-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Audit</p>
                        <a href="{{ route('admin.financial-audit.index') }}" class="mt-2 flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('admin.financial-audit.*') ? 'bg-white text-slate-950 shadow-lg shadow-slate-950/20' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}">
                            <x-admin-icon name="scan-search" />
                            <span>Audit Keuangan</span>
                        </a>
                    </div>
                </nav>

                <div class="shrink-0 border-t border-white/10 p-4">
                    <div class="flex items-center gap-3 rounded-xl bg-white/5 p-3">
                        <div class="flex h-9 w-9 items-center justify-center rounded-full bg-blue-500 text-sm font-bold text-white">{{ str(auth()->user()->name)->substr(0, 1)->upper() }}</div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-white">{{ auth()->user()->name }}</p>
                            <p class="text-xs text-slate-400">Admin BUMDes</p>
                        </div>
                        <a href="{{ route('profile.edit') }}" class="text-xs text-slate-400 hover:text-white" aria-label="Buka profil">Profil</a>
                    </div>
                </div>
            </aside>

            <div class="lg:pl-72">
                <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
                    <div class="flex h-20 items-center justify-between px-4 sm:px-6 lg:px-8">
                        <div class="flex items-center gap-3">
                            <button type="button" class="rounded-xl border border-slate-200 p-2.5 text-slate-600 hover:bg-slate-50 lg:hidden" @click="adminSidebarOpen = true" aria-label="Buka menu">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" /></svg>
                            </button>
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-600">Admin BUMDes</p>
                                <h1 class="text-lg font-bold text-slate-900 sm:text-xl">{{ $title }}</h1>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="hidden text-right sm:block"><span class="block text-sm font-semibold text-slate-800">{{ auth()->user()->name }}</span><span class="block text-xs text-slate-500">Pengelola operasional</span></span>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-slate-300 hover:bg-slate-50">Keluar</button>
                            </form>
                        </div>
                    </div>
                </header>

                <main class="min-h-[calc(100vh-5rem)] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
