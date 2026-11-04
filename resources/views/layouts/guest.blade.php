<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'BUMDes Desa Buruan') }}</title>
        <link rel="icon" type="image/png" href="{{ asset('images/logo-bumdes.png') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-slate-100 font-sans text-slate-900 antialiased">
        <main class="flex min-h-screen items-center justify-center px-4 py-8 sm:px-6 sm:py-12">
            <div class="w-full max-w-md">
                <div class="text-center">
                    <a href="/" class="inline-flex flex-col items-center gap-3" aria-label="BUMDes Desa Buruan">
                        <x-bumdes-logo class="h-auto w-28 max-w-full" />
                        <span class="text-xl font-bold tracking-tight text-slate-900">BUMDes Desa Buruan</span>
                    </a>
                    <p class="mt-2 text-sm text-slate-500">E-Commerce produk lokal Desa Buruan</p>
                </div>

                <div class="mt-6 w-full overflow-hidden rounded-2xl border border-slate-200 bg-white px-6 py-7 shadow-xl shadow-slate-200/60 sm:px-8 sm:py-8">
                    {{ $slot }}
                </div>
            </div>
        </main>
    </body>
</html>
