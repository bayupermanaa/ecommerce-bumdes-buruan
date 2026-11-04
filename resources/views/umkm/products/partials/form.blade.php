<div>
    <x-input-label for="category_id" value="Kategori" />
    <select id="category_id" name="category_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
        <option value="">Pilih kategori</option>
        @foreach ($categories as $category)
            <option value="{{ $category->id }}" @selected(old('category_id', $product->category_id ?? '') == $category->id)>
                {{ $category->name }}{{ ! $category->is_active ? ' (nonaktif - kategori saat ini)' : '' }}
            </option>
        @endforeach
    </select>
    @if ($categories->isEmpty())
        <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
            Kategori belum tersedia. Hubungi Admin BUMDes.
        </p>
    @endif
    <x-input-error class="mt-2" :messages="$errors->get('category_id')" />
</div>

<div>
    <x-input-label for="sku" value="SKU" />
    <x-text-input id="sku" name="sku" type="text" class="mt-1 block w-full" :value="old('sku', $product->sku ?? '')" required />
    <x-input-error class="mt-2" :messages="$errors->get('sku')" />
</div>

<div>
    <x-input-label for="name" value="Nama Produk" />
    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $product->name ?? '')" required />
    <x-input-error class="mt-2" :messages="$errors->get('name')" />
</div>

<div>
    <x-input-label for="slug" value="Slug Produk" />
    <x-text-input id="slug" name="slug" type="text" class="mt-1 block w-full" :value="old('slug', $product->slug ?? '')" required />
    <x-input-error class="mt-2" :messages="$errors->get('slug')" />
</div>

<div>
    <x-input-label for="description" value="Deskripsi" />
    <textarea id="description" name="description" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description', $product->description ?? '') }}</textarea>
    <x-input-error class="mt-2" :messages="$errors->get('description')" />
</div>

<div>
    <x-input-label for="image" value="Foto Produk" />
    @if (isset($product) && $product->image_path)
        <img src="{{ \Illuminate\Support\Facades\Storage::url($product->image_path) }}" alt="Foto {{ $product->name }}" class="mt-2 h-24 w-24 rounded-md object-cover">
    @endif
    <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full text-sm text-gray-700">
    <p class="mt-1 text-sm text-gray-600">JPEG, PNG, atau WebP. Maksimal 2 MB.</p>
    <x-input-error class="mt-2" :messages="$errors->get('image')" />
</div>

<div>
    <x-input-label for="price" value="Harga" />
    <x-text-input id="price" name="price" type="number" min="0" step="0.01" class="mt-1 block w-full" :value="old('price', $product->price ?? '')" required />
    <x-input-error class="mt-2" :messages="$errors->get('price')" />
</div>

<div>
    <x-input-label for="stock" value="Stok" />
    <x-text-input id="stock" name="stock" type="number" min="0" step="1" class="mt-1 block w-full" :value="old('stock', $product->stock ?? 0)" required />
    <x-input-error class="mt-2" :messages="$errors->get('stock')" />
</div>
