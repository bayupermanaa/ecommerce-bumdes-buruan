<?php

namespace App\Http\Controllers\Umkm;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductRevision;
use App\Models\User;
use App\Notifications\MarketplaceNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class ProductController extends Controller
{
    /**
     * Display products belonging to the authenticated owner's store.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Product::class);

        $store = $request->user()->umkmStore;

        return view('umkm.products.index', [
            'store' => $store,
            'products' => $store->products()->with(['category', 'latestRevision'])->latest()->get(),
        ]);
    }

    /**
     * Display the product creation form for an approved store.
     */
    public function create(): View
    {
        Gate::authorize('create', Product::class);

        return view('umkm.products.create', [
            'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    /**
     * Store a pending product in the authenticated owner's approved store.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Product::class);

        $data = $this->validatedProductData($request);
        $image = $data['image'] ?? null;
        unset($data['image']);

        $imagePath = $image?->store('products', 'public');

        $createdProductId = null;
        try {
            DB::transaction(function () use ($request, $data, $imagePath, &$createdProductId): void {
                $product = $request->user()->umkmStore->products()->create([...$data, 'image_path' => $imagePath]);
                $createdProductId = $product->id;
                $this->syncVariants($product, $request);
            });
        } catch (Throwable $exception) {
            if ($imagePath !== null) {
                Storage::disk('public')->delete($imagePath);
            }

            throw $exception;
        }
        User::query()->where('role', 'admin_bumdes')->get()->each(fn (User $admin) => MarketplaceNotification::sendOnce($admin, 'Produk baru menunggu verifikasi', 'Ada produk baru yang menunggu verifikasi.', route('admin.products.index', ['status'=>'pending']), 'product.submitted.'.$createdProductId));

        return redirect()
            ->route('umkm.products.index')
            ->with('status', 'Produk berhasil dibuat dan menunggu verifikasi.');
    }

    /**
     * Display the edit form for the owner's product.
     */
    public function edit(Product $product): View
    {
        Gate::authorize('update', $product);

        $product->load(['pendingRevision', 'variants']);

        return view('umkm.products.edit', [
            'product' => $product,
            'categories' => Category::query()
                ->where('is_active', true)
                ->orWhere('id', $product->category_id)
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * Update the owner's product.
     */
    public function update(Request $request, Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);

        $data = $this->validatedProductData($request, $product);
        $image = $data['image'] ?? null;
        unset($data['image']);

        if ($product->status === 'approved') {
            return $this->submitApprovedProductRevision($product, $data, $image);
        }


        $newImagePath = $image?->store('products', 'public');
        $oldImagePath = $product->image_path;

        try {
            DB::transaction(function () use ($product, $data, $newImagePath, $request): void {
                $product->update([...$data, ...($newImagePath !== null ? ['image_path' => $newImagePath] : [])]);
                $this->syncVariants($product, $request);
            });
        } catch (Throwable $exception) {
            if ($newImagePath !== null) {
                Storage::disk('public')->delete($newImagePath);
            }

            throw $exception;
        }

        if ($newImagePath !== null && $oldImagePath !== null && $oldImagePath !== $newImagePath) {
            Storage::disk('public')->delete($oldImagePath);
        }

        return redirect()
            ->route('umkm.products.index')
            ->with('status', 'Produk berhasil diperbarui.');
    }

    /**
     * Keep an approved product public while its marketing changes await review.
     * Stock remains an operational field and is updated directly.
     *
     * @param  array<string, mixed>  $data
     */
    private function submitApprovedProductRevision(Product $product, array $data, mixed $image): RedirectResponse
    {
        $content = [
            'category_id' => $data['category_id'],
            'sku' => $data['sku'],
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'price' => $data['price'],
        ];

        $hasContentChanges = $this->hasContentChanges($product, $content) || $image !== null;

        if (! $hasContentChanges) {
            $product->update(['stock' => $data['stock']]);

            return redirect()
                ->route('umkm.products.index')
                ->with('status', 'Stok produk berhasil diperbarui.');
        }

        if ($product->pendingRevision()->exists()) {
            return redirect()
                ->route('umkm.products.index')
                ->with('error', 'Produk ini sudah memiliki revisi yang menunggu persetujuan.');
        }

        $imagePath = $image?->store('product-revisions', 'public');

        $revisionId = null;
        try {
            DB::transaction(function () use ($product, $content, $data, $imagePath, &$revisionId): void {
                $revision = ProductRevision::create([
                    'product_id' => $product->id,
                    ...$content,
                    'image_path' => $imagePath,
                    'status' => 'pending',
                    'pending_key' => 'pending',
                ]);
                $revisionId = $revision->id;

                $product->update(['stock' => $data['stock']]);
            });
        } catch (UniqueConstraintViolationException) {
            if ($imagePath !== null) {
                Storage::disk('public')->delete($imagePath);
            }

            return redirect()
                ->route('umkm.products.index')
                ->with('error', 'Produk ini sudah memiliki revisi yang menunggu persetujuan.');
        } catch (Throwable $exception) {
            if ($imagePath !== null) {
                Storage::disk('public')->delete($imagePath);
            }

            throw $exception;
        }

        User::query()->where('role', 'admin_bumdes')->get()->each(fn (User $admin) => MarketplaceNotification::sendOnce($admin, 'Revisi produk menunggu verifikasi', 'Ada revisi produk yang menunggu persetujuan.', route('admin.product-revisions.index', ['status'=>'pending']), 'product.revision.submitted.'.$revisionId));

        return redirect()
            ->route('umkm.products.index')
            ->with('status', 'Revisi produk telah dikirim dan menunggu persetujuan Admin BUMDes.');
    }

    /**
     * Determine whether submitted marketing content differs from the published product.
     *
     * @param  array<string, mixed>  $content
     */
    private function hasContentChanges(Product $product, array $content): bool
    {
        foreach ($content as $attribute => $value) {
            if ($attribute === 'price') {
                if ((float) $product->price !== (float) $value) {
                    return true;
                }

                continue;
            }

            if ((string) ($product->{$attribute} ?? '') !== (string) ($value ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Delete the owner's product.
     */
    public function destroy(Product $product): RedirectResponse
    {
        Gate::authorize('delete', $product);

        $product->delete();

        return redirect()
            ->route('umkm.products.index')
            ->with('status', 'Produk berhasil dihapus.');
    }

    /**
     * Validate only fields that the store owner may manage.
     *
     * @return array<string, mixed>
     */
    private function validatedProductData(Request $request, ?Product $product = null): array
    {
        $categoryRule = Rule::exists('categories', 'id')->where(function ($query) use ($product): void {
            $query->where('is_active', true);

            if ($product !== null) {
                $query->orWhere('id', $product->category_id);
            }
        });

        $data = $request->validate([
            'category_id' => ['required', 'integer', $categoryRule],
            'sku' => ['required', 'string', 'max:255', Rule::unique('products', 'sku')->ignore($product)],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('products', 'slug')->ignore($product)],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'price' => ['required', 'numeric', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'pricing_type' => ['nullable', Rule::in(['single', 'variants'])],
            'variants' => ['nullable', 'array'],
            'variants.*.label' => ['required', 'string', 'max:255'],
            'variants.*.sku' => ['required', 'string', 'max:255'],
            'variants.*.price' => ['required', 'numeric', 'min:0'],
            'variants.*.stock' => ['required', 'integer', 'min:0'],
        ]);

        $data['pricing_type'] = $data['pricing_type'] ?? 'single';
        if ($data['pricing_type'] === 'variants' && count($data['variants'] ?? []) === 0) {
            throw \Illuminate\Validation\ValidationException::withMessages(['variants' => 'Tambahkan minimal satu variasi ukuran.']);
        }
        $variants = collect($data['variants'] ?? []);
        if ($variants->pluck('label')->map(fn ($v) => mb_strtolower(trim($v)))->duplicates()->isNotEmpty()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['variants' => 'Label variasi tidak boleh duplikat.']);
        }
        if ($variants->pluck('sku')->map(fn ($v) => mb_strtolower(trim($v)))->duplicates()->isNotEmpty()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['variants' => 'SKU variasi tidak boleh duplikat.']);
        }
        $data['variants'] = $data['pricing_type'] === 'variants' ? $data['variants'] : [];
        return $data;
    }

    private function syncVariants(Product $product, Request $request): void
    {
        if ($product->status === 'approved') {
            return;
        }
        if ($product->variants()->exists() && ($product->cartItems()->exists() || $product->orderItems()->exists())) {
            throw \Illuminate\Validation\ValidationException::withMessages(['variants' => 'Variasi yang sudah digunakan transaksi tidak dapat diganti langsung.']);
        }
        $product->variants()->delete();
        foreach (($request->input('pricing_type') === 'variants' ? $request->input('variants', []) : []) as $variant) {
            $product->variants()->create(['label' => trim($variant['label']), 'sku' => trim($variant['sku']), 'price' => $variant['price'], 'stock' => $variant['stock'], 'is_active' => true]);
        }
    }
}
