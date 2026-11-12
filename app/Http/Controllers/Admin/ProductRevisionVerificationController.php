<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductRevision;
use App\Notifications\MarketplaceNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductRevisionVerificationController extends Controller
{
    /**
     * Display pending revisions by default, with revision history available by filter.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', ProductRevision::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'all'])],
        ]);
        $selectedStatus = $filters['status'] ?? 'pending';

        $revisions = ProductRevision::query()
            ->with(['category', 'product.category', 'product.umkmStore.user'])
            ->when($selectedStatus !== 'all', fn ($query) => $query->where('status', $selectedStatus))
            ->latest()
            ->get();

        return view('admin.product-revisions.index', compact('revisions', 'selectedStatus'));
    }

    /**
     * Atomically publish a pending revision while retaining its audit history.
     */
    public function approve(Request $request, ProductRevision $revision): RedirectResponse
    {
        Gate::authorize('review', $revision);

        $oldImagePath = null;
        $published = false;

        DB::transaction(function () use ($revision, &$oldImagePath, &$published): void {
            $revision = ProductRevision::query()->lockForUpdate()->findOrFail($revision->id);

            if ($revision->status !== 'pending') {
                return;
            }

            $product = Product::query()->lockForUpdate()->findOrFail($revision->product_id);
            $oldImagePath = $product->image_path;

            $product->update([
                'category_id' => $revision->category_id,
                'sku' => $revision->sku,
                'name' => $revision->name,
                'slug' => $revision->slug,
                'description' => $revision->description,
                'price' => $revision->price,
                ...($revision->image_path !== null ? ['image_path' => $revision->image_path] : []),
            ]);

            $revision->update([
                'status' => 'approved',
                'pending_key' => null,
                'rejection_reason' => null,
                'reviewed_at' => now(),
            ]);

            $published = true;
        });

        if (! $published) {
            return $this->redirectToIndex($request)
                ->with('error', 'Revisi ini sudah diproses dan tidak dapat disetujui kembali.');
        }

        if ($revision->image_path !== null && $oldImagePath !== null && $oldImagePath !== $revision->image_path) {
            $this->deleteImageWhenUnreferenced($oldImagePath);
        }
        $revision->load('product.umkmStore.user');
        MarketplaceNotification::sendOnce($revision->product->umkmStore->user, 'Revisi produk disetujui', 'Revisi produk Anda telah disetujui dan dipublikasikan.', route('umkm.products.index'), 'revision.approved.'.$revision->id);

        return $this->redirectToIndex($request)
            ->with('status', 'Revisi produk berhasil disetujui dan dipublikasikan.');
    }

    /**
     * Reject a pending revision without changing the published product.
     */
    public function reject(Request $request, ProductRevision $revision): RedirectResponse
    {
        Gate::authorize('review', $revision);

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ]);
        $rejected = false;

        DB::transaction(function () use ($revision, $validated, &$rejected): void {
            $revision = ProductRevision::query()->lockForUpdate()->findOrFail($revision->id);

            if ($revision->status !== 'pending') {
                return;
            }

            $revision->update([
                'status' => 'rejected',
                'pending_key' => null,
                'rejection_reason' => $validated['rejection_reason'],
                'reviewed_at' => now(),
            ]);
            $rejected = true;
        });

        if (! $rejected) {
            return $this->redirectToIndex($request)
                ->with('error', 'Revisi ini sudah diproses dan tidak dapat ditolak kembali.');
        }
        $revision->load('product.umkmStore.user');
        MarketplaceNotification::sendOnce($revision->product->umkmStore->user, 'Revisi produk ditolak', 'Revisi produk Anda ditolak: '.$revision->rejection_reason, route('umkm.products.index'), 'revision.rejected.'.$revision->id);

        return $this->redirectToIndex($request)
            ->with('status', 'Revisi produk ditolak. Produk yang tampil di katalog tidak berubah.');
    }

    /**
     * Delete an old product image only once no product or revision references it.
     */
    private function deleteImageWhenUnreferenced(string $path): void
    {
        $isReferenced = Product::query()->where('image_path', $path)->exists()
            || ProductRevision::query()->where('image_path', $path)->exists();

        if (! $isReferenced) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * Return to the filtered revision list.
     */
    private function redirectToIndex(Request $request): RedirectResponse
    {
        $status = $request->input('return_status');
        $parameters = is_string($status) && in_array($status, ['pending', 'approved', 'rejected', 'all'], true)
            ? ['status' => $status]
            : [];

        return redirect()->route('admin.product-revisions.index', $parameters);
    }
}
