@props(['title' => 'Portal Kurir'])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $title }} | {{ config('app.name', 'BUMDes Desa Buruan') }}</title>
        <link rel="icon" type="image/png" href="{{ asset('images/logo-bumdes.png') }}">
        <link rel="preconnect" href="https://fonts.bunny.net"><link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
        <style>[x-cloak]{display:none!important}</style>@vite(['resources/css/app.css','resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-100 font-sans text-slate-900">
        <div class="min-h-screen lg:flex">
            <aside class="flex w-full flex-col bg-slate-950 text-slate-200 lg:fixed lg:inset-y-0 lg:w-72">
                <div class="flex h-20 items-center gap-3 border-b border-white/10 px-6"><div class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white shadow-sm"><x-bumdes-logo class="h-11 w-11" /></div><div><p class="text-sm font-bold text-white">BUMDes Desa Buruan</p><p class="text-xs text-cyan-300">Portal Kurir</p></div></div>
                <nav class="flex-1 px-4 py-6" aria-label="Navigasi Kurir"><a href="{{ route('kurir.dashboard') }}" class="flex items-center gap-3 rounded-xl bg-cyan-300 px-3 py-2.5 text-sm font-semibold text-slate-950"><span class="flex h-7 w-7 items-center justify-center rounded-lg bg-slate-800 text-xs text-white">01</span>Dashboard</a></nav>
                <div class="border-t border-white/10 p-4"><div class="flex items-center gap-3 rounded-xl bg-white/5 p-3"><div class="flex h-9 w-9 items-center justify-center rounded-full bg-cyan-300 text-sm font-bold text-slate-950">{{ str(auth()->user()->name)->substr(0,1)->upper() }}</div><div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold text-white">{{ auth()->user()->name }}</p><p class="text-xs text-slate-400">Kurir BUMDes</p></div><a href="{{ route('profile.edit') }}" class="text-xs text-slate-400 hover:text-white">Profil</a></div></div>
            </aside>
            <main class="w-full lg:ml-72"><header class="border-b border-slate-200 bg-white"><div class="flex min-h-20 items-center justify-between px-4 sm:px-6 lg:px-8"><div><p class="text-xs font-semibold uppercase tracking-[0.18em] text-cyan-700">Portal Kurir</p><h1 class="text-xl font-bold text-slate-950">{{ $title }}</h1></div><form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50">Keluar</button></form></div></header><div class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">{{ $slot }}</div></main>
        </div>
    </body>
</html>
