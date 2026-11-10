<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CatalogController extends Controller
{
    /**
     * Display publicly available products from approved Penjual stores.
     */
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'sort' => ['nullable', Rule::in(['latest', 'price_asc', 'price_desc'])],
        ]);

        $products = $this->approvedProducts()
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where('name', 'like', "%{$search}%"))
            ->when($filters['category'] ?? null, fn (Builder $query, int $categoryId) => $query->where('category_id', $categoryId))
            ->when(($filters['sort'] ?? 'latest') === 'price_asc', fn (Builder $query) => $query->orderByRaw('COALESCE(active_min_price, price) asc'))
            ->when(($filters['sort'] ?? 'latest') === 'price_desc', fn (Builder $query) => $query->orderByRaw('COALESCE(active_max_price, price) desc'))
            ->when(($filters['sort'] ?? 'latest') === 'latest', fn (Builder $query) => $query->latest())
            ->paginate(12)
            ->withQueryString();

        $categories = Category::query()
            ->where('is_active', true)
            ->whereHas('products', fn (Builder $query) => $this->applyCatalogVisibility($query))
            ->orderBy('name')
            ->get();

        return view('catalog.index', [
            'products' => $products,
            'categories' => $categories,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'category' => $filters['category'] ?? '',
                'sort' => $filters['sort'] ?? 'latest',
            ],
        ]);
    }

    /**
     * Display an approved product from an approved store.
     */
    public function show(string $slug): View
    {
        $product = $this->approvedProducts()
            ->where('slug', $slug)
            ->firstOrFail();

        return view('catalog.show', compact('product'));
    }

    /**
     * Build the base query for products safe to publish in the catalog.
     */
    private function approvedProducts(): Builder
    {
        return Product::query()
            ->with(['category', 'umkmStore', 'activeVariants'])
            ->withMin('activeVariants as active_min_price', 'price')
            ->withMax('activeVariants as active_max_price', 'price')
            ->tap(fn (Builder $query) => $this->applyCatalogVisibility($query));
    }

    /**
     * Restrict a product query to approved products owned by approved stores.
     */
    private function applyCatalogVisibility(Builder $query): Builder
    {
        return $query
            ->where('status', 'approved')
            ->whereHas('umkmStore', fn (Builder $storeQuery) => $storeQuery->where('status', 'approved'));
    }
}
