<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UmkmStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SellerDirectoryController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', UmkmStore::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'name_asc', 'name_desc'])],
        ]);

        $search = trim($filters['search'] ?? '');
        $status = $filters['status'] ?? '';
        $sort = $filters['sort'] ?? 'newest';

        $stores = UmkmStore::query()
            ->with('user:id,name,email')
            ->withCount('products')
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function ($query) use ($like): void {
                    $query->where('umkm_stores.name', 'like', $like)
                        ->orWhereHas('user', function ($user) use ($like): void {
                            $user->where('name', 'like', $like)
                                ->orWhere('email', 'like', $like);
                        });
                });
            })
            ->when($status !== '', fn ($query) => $query->where('umkm_stores.status', $status));

        match ($sort) {
            'oldest' => $stores->orderBy('umkm_stores.created_at')->orderBy('umkm_stores.id'),
            'name_asc' => $stores->orderBy('umkm_stores.name')->orderBy('umkm_stores.id'),
            'name_desc' => $stores->orderByDesc('umkm_stores.name')->orderByDesc('umkm_stores.id'),
            default => $stores->orderByDesc('umkm_stores.created_at')->orderByDesc('umkm_stores.id'),
        };

        return view('admin.sellers.index', [
            'stores' => $stores->paginate(15)->withQueryString(),
            'filters' => compact('search', 'status', 'sort'),
        ]);
    }

    public function show(Request $request, UmkmStore $store): View
    {
        Gate::authorize('view', $store);

        $filters = $request->validate([
            'return_status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
            'return_search' => ['nullable', 'string', 'max:100'],
            'return_sort' => ['nullable', Rule::in(['newest', 'oldest'])],
            'return_page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        $returnFilters = [];
        foreach (['status', 'search', 'sort', 'page'] as $key) {
            $value = $filters['return_'.$key] ?? null;
            if ($value !== null && $value !== '') {
                $returnFilters[$key] = $value;
            }
        }

        $store->load('user:id,name,email')->loadCount('products');
        $recentProducts = $store->products()
            ->select(['id', 'umkm_store_id', 'name', 'status', 'created_at'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        return view('admin.sellers.show', compact('store', 'recentProducts', 'returnFilters'));
    }
}
