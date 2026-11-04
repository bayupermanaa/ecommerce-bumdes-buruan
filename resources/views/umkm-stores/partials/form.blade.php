<div>
    <x-input-label for="name" value="Nama Toko" />
    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $store->name ?? '')" required autofocus />
    <x-input-error class="mt-2" :messages="$errors->get('name')" />
</div>

<div>
    <x-input-label for="slug" value="Slug Toko" />
    <x-text-input id="slug" name="slug" type="text" class="mt-1 block w-full" :value="old('slug', $store->slug ?? '')" required />
    <p class="mt-1 text-sm text-gray-600">Gunakan huruf kecil, angka, tanda hubung, atau underscore.</p>
    <x-input-error class="mt-2" :messages="$errors->get('slug')" />
</div>

<div>
    <x-input-label for="description" value="Deskripsi" />
    <textarea id="description" name="description" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description', $store->description ?? '') }}</textarea>
    <x-input-error class="mt-2" :messages="$errors->get('description')" />
</div>

<div>
    <x-input-label for="address" value="Alamat" />
    <textarea id="address" name="address" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('address', $store->address ?? '') }}</textarea>
    <x-input-error class="mt-2" :messages="$errors->get('address')" />
</div>

<div>
    <x-input-label for="phone" value="Nomor Telepon" />
    <x-text-input id="phone" name="phone" type="text" class="mt-1 block w-full" :value="old('phone', $store->phone ?? '')" />
    <x-input-error class="mt-2" :messages="$errors->get('phone')" />
</div>
