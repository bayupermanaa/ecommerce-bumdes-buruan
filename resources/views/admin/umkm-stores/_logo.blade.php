<div class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-slate-50">
    @if ($store->logo)
        <div x-data="{ imageFailed: false }" class="flex h-full w-full items-center justify-center">
            <img x-show="!imageFailed" x-on:error="imageFailed = true" src="{{ '/storage/'.ltrim($store->logo, '/') }}" alt="Logo {{ $store->name }}" class="h-full w-full object-contain" loading="lazy">
            <x-admin-icon x-cloak x-show="imageFailed" name="store" class="text-slate-400" />
        </div>
    @else
        <x-admin-icon name="store" class="text-slate-400" />
    @endif
</div>
