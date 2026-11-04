@csrf

@if (isset($category))
    @method('PUT')
@endif

<div class="grid gap-6">
    <div>
        <label for="name" class="block text-sm font-semibold text-slate-700">Nama kategori</label>
        <input id="name" name="name" type="text" value="{{ old('name', $category->name ?? '') }}" required autofocus
            class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
        @error('name')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="slug" class="block text-sm font-semibold text-slate-700">Slug</label>
        <input id="slug" name="slug" type="text" value="{{ old('slug', $category->slug ?? '') }}" required
            class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
        <p class="mt-1 text-xs text-slate-500">Gunakan huruf, angka, tanda hubung, atau garis bawah tanpa spasi.</p>
        @error('slug')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="description" class="block text-sm font-semibold text-slate-700">Deskripsi</label>
        <textarea id="description" name="description" rows="4"
            class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('description', $category->description ?? '') }}</textarea>
        @error('description')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <label class="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
        <input name="is_active" type="checkbox" value="1" @checked(old('is_active', $category->is_active ?? true))
            class="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
        <span>
            <span class="block text-sm font-semibold text-slate-800">Kategori aktif</span>
            <span class="mt-1 block text-xs text-slate-500">Hanya kategori aktif yang dapat dipilih untuk produk baru.</span>
        </span>
    </label>

    <div class="flex flex-wrap items-center gap-3 border-t border-slate-200 pt-5">
        <button type="submit" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">
            {{ isset($category) ? 'Simpan Perubahan' : 'Tambah Kategori' }}
        </button>
        <a href="{{ route('admin.categories.index') }}" class="rounded-xl border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Batal</a>
    </div>
</div>
