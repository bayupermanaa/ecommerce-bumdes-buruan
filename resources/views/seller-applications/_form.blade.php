@if ($errors->any())
    <div role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
        <p class="font-semibold">Periksa kembali data pengajuan.</p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div>
    <x-input-label for="name" value="Nama toko" />
    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $store?->name)" required autofocus maxlength="255" />
    <x-input-error class="mt-2" :messages="$errors->get('name')" />
</div>
<div>
    <x-input-label for="description" value="Deskripsi toko" />
    <textarea id="description" name="description" rows="5" required maxlength="5000" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">{{ old('description', $store?->description) }}</textarea>
    <p class="mt-1 text-xs text-slate-500">Jelaskan produk atau usaha yang akan ditawarkan.</p>
    <x-input-error class="mt-2" :messages="$errors->get('description')" />
</div>
<div>
    <x-input-label for="address" value="Alamat lengkap" />
    <textarea id="address" name="address" rows="4" required maxlength="2000" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">{{ old('address', $store?->address) }}</textarea>
    <p class="mt-1 text-xs text-slate-500">Pendaftaran terbuka untuk penjual dari dalam maupun luar Desa Buruan.</p>
    <x-input-error class="mt-2" :messages="$errors->get('address')" />
</div>

<div>
    <x-input-label for="phone" value="Nomor telepon" />
    <x-text-input id="phone" name="phone" type="tel" class="mt-1 block w-full" :value="old('phone', $store?->phone)" required maxlength="30" autocomplete="tel" />
    <x-input-error class="mt-2" :messages="$errors->get('phone')" />
</div>

<div>
    <x-input-label for="logo" value="Logo toko (opsional)" />
    @if ($store?->logo)
        <img src="{{ \Illuminate\Support\Facades\Storage::url($store->logo) }}" alt="Logo {{ $store->name }}" class="mt-2 h-20 w-20 rounded-xl border border-slate-200 object-contain">
    @endif
    <input id="logo" name="logo" type="file" accept="image/jpeg,image/png,image/webp" class="mt-2 block w-full text-sm text-slate-700 file:mr-3 file:rounded-xl file:border-0 file:bg-emerald-50 file:px-4 file:py-2 file:font-semibold file:text-emerald-800 hover:file:bg-emerald-100">
    <p class="mt-1 text-xs text-slate-500">JPG, PNG, atau WebP, maksimal 2 MB.</p>
    <x-input-error class="mt-2" :messages="$errors->get('logo')" />
</div>
