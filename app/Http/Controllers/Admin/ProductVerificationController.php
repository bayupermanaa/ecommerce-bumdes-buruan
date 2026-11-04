<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductVerificationController extends Controller
{
    /**
     * Display all UMKM products, optionally filtered by status.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Product::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
        ]);

        $products = Product::query()
            ->with(['category', 'umkmStore.user'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->get();

        return view('admin.products.index', [
            'products' => $products,
            'selectedStatus' => $filters['status'] ?? '',
        ]);
    }

    /**
     * Approve a pending product.
     */
    public function approve(Request $request, Product $product): RedirectResponse
    {
        Gate::authorize('approve', $product);

        if (! $this->isPending($product)) {
            return $this->redirectToIndex($request)
                ->with('error', 'Produk ini sudah diproses dan tidak dapat disetujui kembali.');
        }

        $product->status = 'approved';
        $product->verified_at = now();
        $product->rejection_reason = null;
        $product->save();

        return $this->redirectToIndex($request)
            ->with('status', 'Produk berhasil disetujui.');
    }

    /**
     * Reject a pending product and record the reason.
     */
    public function reject(Request $request, Product $product): RedirectResponse
    {
        Gate::authorize('reject', $product);

        if (! $this->isPending($product)) {
            return $this->redirectToIndex($request)
                ->with('error', 'Produk ini sudah diproses dan tidak dapat ditolak kembali.');
        }

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ]);

        $product->status = 'rejected';
        $product->rejection_reason = $validated['rejection_reason'];
        $product->verified_at = now();
        $product->save();

        return $this->redirectToIndex($request)
            ->with('status', 'Produk ditolak.');
    }

    /**
     * Determine whether the product can still be verified.
     */
    private function isPending(Product $product): bool
    {
        return $product->status === 'pending';
    }

    /**
     * Return to the list while preserving a valid status filter.
     */
    private function redirectToIndex(Request $request): RedirectResponse
    {
        $status = $request->input('return_status');
        $parameters = is_string($status) && in_array($status, ['pending', 'approved', 'rejected'], true)
            ? ['status' => $status]
            : [];

        return redirect()->route('admin.products.index', $parameters);
    }
}
