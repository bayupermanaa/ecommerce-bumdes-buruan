<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ProductRevision;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(): View
    {
        return view('admin.categories.index', [
            'categories' => Category::query()
                ->withCount('products')
                ->orderBy('name')
                ->paginate(15),
        ]);
    }

    public function create(): View
    {
        return view('admin.categories.create');
    }

    public function store(Request $request): RedirectResponse
    {
        Category::create($this->validatedData($request));

        return redirect()
            ->route('admin.categories.index')
            ->with('status', 'Kategori produk berhasil ditambahkan.');
    }

    public function edit(Category $category): View
    {
        return view('admin.categories.edit', compact('category'));
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $category->update($this->validatedData($request, $category));

        return redirect()
            ->route('admin.categories.index')
            ->with('status', 'Kategori produk berhasil diperbarui.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        if ($category->products()->exists()
            || ProductRevision::query()->where('category_id', $category->id)->exists()) {
            return redirect()
                ->route('admin.categories.index')
                ->with('error', 'Kategori tidak dapat dihapus karena masih digunakan oleh produk atau riwayat revisi produk.');
        }

        try {
            $category->delete();
        } catch (QueryException) {
            return redirect()
                ->route('admin.categories.index')
                ->with('error', 'Kategori tidak dapat dihapus karena masih terhubung dengan data lain.');
        }

        return redirect()
            ->route('admin.categories.index')
            ->with('status', 'Kategori produk berhasil dihapus.');
    }

    /**
     * @return array{name: string, slug: string, description: string|null, is_active: bool}
     */
    private function validatedData(Request $request, ?Category $category = null): array
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
            'slug' => trim((string) $request->input('slug')),
            'is_active' => $request->boolean('is_active'),
        ]);

        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail) use ($category): void {
                    $exists = Category::query()
                        ->whereRaw('LOWER(name) = LOWER(?)', [(string) $value])
                        ->when($category, fn ($query) => $query->where('id', '!=', $category->getKey()))
                        ->exists();

                    if ($exists) {
                        $fail('Nama kategori sudah digunakan.');
                    }
                },
            ],
            'slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('categories', 'slug')->ignore($category),
            ],
            'description' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
        ], [
            'slug.alpha_dash' => 'Slug hanya boleh berisi huruf, angka, tanda hubung, dan garis bawah.',
        ]);
    }
}
