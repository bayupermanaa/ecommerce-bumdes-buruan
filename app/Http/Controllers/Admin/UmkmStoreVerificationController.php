<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UmkmStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UmkmStoreVerificationController extends Controller
{
    /**
     * Display all stores, optionally filtered by verification status.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', UmkmStore::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
        ]);

        $stores = UmkmStore::query()
            ->with('user')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->get();

        return view('admin.umkm-stores.index', [
            'stores' => $stores,
            'selectedStatus' => $filters['status'] ?? '',
        ]);
    }

    /**
     * Approve a pending UMKM store.
     */
    public function approve(Request $request, UmkmStore $store): RedirectResponse
    {
        Gate::authorize('approve', $store);

        if (! $this->isPending($store)) {
            return $this->redirectToIndex($request)
                ->with('error', 'Toko ini sudah diproses dan tidak dapat disetujui kembali.');
        }

        $store->status = 'approved';
        $store->verified_at = now();
        $store->rejection_reason = null;
        $store->save();

        return $this->redirectToIndex($request)
            ->with('status', 'Toko berhasil disetujui.');
    }

    /**
     * Reject a pending UMKM store and retain the review reason.
     */
    public function reject(Request $request, UmkmStore $store): RedirectResponse
    {
        Gate::authorize('reject', $store);

        if (! $this->isPending($store)) {
            return $this->redirectToIndex($request)
                ->with('error', 'Toko ini sudah diproses dan tidak dapat ditolak kembali.');
        }

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ]);

        $store->status = 'rejected';
        $store->rejection_reason = $validated['rejection_reason'];
        $store->verified_at = now();
        $store->save();

        return $this->redirectToIndex($request)
            ->with('status', 'Toko ditolak.');
    }

    /**
     * Determine whether the store can still be verified.
     */
    private function isPending(UmkmStore $store): bool
    {
        return $store->status === 'pending';
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

        return redirect()->route('admin.umkm-stores.index', $parameters);
    }
}
