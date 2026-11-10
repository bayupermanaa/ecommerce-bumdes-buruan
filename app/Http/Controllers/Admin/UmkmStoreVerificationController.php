<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UmkmStore;
use App\Models\User;
use App\Support\UserRoles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UmkmStoreVerificationController extends Controller
{
    /** Display a paginated, read-only review queue. */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', UmkmStore::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest'])],
        ]);

        $search = trim($filters['search'] ?? '');
        $status = $filters['status'] ?? '';
        $sort = $filters['sort'] ?? 'newest';

        $statusCounts = UmkmStore::query()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $stores = UmkmStore::query()
            ->with('user:id,name,email')
            ->when($status !== '', fn ($query) => $query->where('umkm_stores.status', $status))
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function ($query) use ($like): void {
                    $query->where('umkm_stores.name', 'like', $like)
                        ->orWhereHas('user', function ($user) use ($like): void {
                            $user->where('name', 'like', $like)
                                ->orWhere('email', 'like', $like);
                        });
                });
            });

        if ($sort === 'oldest') {
            $stores->orderBy('umkm_stores.created_at')->orderBy('umkm_stores.id');
        } else {
            $stores->orderByDesc('umkm_stores.created_at')->orderByDesc('umkm_stores.id');
        }

        return view('admin.umkm-stores.index', [
            'stores' => $stores->paginate(15)->withQueryString(),
            'selectedStatus' => $status,
            'filters' => compact('search', 'status', 'sort'),
            'statusCounts' => $statusCounts,
        ]);
    }

    /**
     * Approve a pending Penjual store.
     */
    public function approve(Request $request, UmkmStore $store): RedirectResponse
    {
        Gate::authorize('approve', $store);

        $approved = DB::transaction(function () use ($store): bool {
            // Lock user first everywhere this workflow mutates both records.
            // This keeps approval and resubmission on one deterministic lock order.
            $user = User::query()->whereKey($store->user_id)->lockForUpdate()->firstOrFail();
            $lockedStore = UmkmStore::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();

            if (! $this->isPending($lockedStore)) {
                return false;
            }

            if (! UserRoles::canBuy($user->role)) {
                throw ValidationException::withMessages([
                    'store' => 'Role pemilik akun tidak valid untuk aktivasi toko penjual.',
                ]);
            }

            $lockedStore->forceFill([
                'status' => 'approved',
                'verified_at' => now(),
                'rejection_reason' => null,
            ])->save();
            $user->forceFill(['role' => UserRoles::SELLER])->save();

            return true;
        });

        if (! $approved) {
            return $this->redirectToIndex($request)
                ->with('error', 'Toko ini sudah diproses dan tidak dapat disetujui kembali.');
        }

        return $this->redirectToIndex($request)
            ->with('status', 'Toko berhasil disetujui.');
    }

    /**
     * Reject a pending Penjual store and retain the review reason.
     */
    public function reject(Request $request, UmkmStore $store): RedirectResponse
    {
        Gate::authorize('reject', $store);

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ]);

        $rejected = DB::transaction(function () use ($store, $validated): bool {
            // Use the same user-then-store lock order as approval and resubmission.
            User::query()->whereKey($store->user_id)->lockForUpdate()->firstOrFail();
            $lockedStore = UmkmStore::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();

            if (! $this->isPending($lockedStore)) {
                return false;
            }

            $lockedStore->forceFill([
                'status' => 'rejected',
                'rejection_reason' => $validated['rejection_reason'],
                'verified_at' => now(),
            ])->save();

            return true;
        });

        if (! $rejected) {
            return $this->redirectToIndex($request)
                ->with('error', 'Toko ini sudah diproses dan tidak dapat ditolak kembali.');
        }

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

    /** Return to the review queue with only validated navigation context. */
    private function redirectToIndex(Request $request): RedirectResponse
    {
        $status = $request->input('return_status');
        $search = $request->input('return_search');
        $sort = $request->input('return_sort');
        $page = $request->input('return_page');
        $parameters = [];

        if (is_string($status) && in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $parameters['status'] = $status;
        }
        if (is_string($search) && trim($search) !== '' && mb_strlen($search) <= 100) {
            $parameters['search'] = trim($search);
        }
        if (is_string($sort) && in_array($sort, ['newest', 'oldest'], true)) {
            $parameters['sort'] = $sort;
        }
        if (is_scalar($page) && ctype_digit((string) $page) && (int) $page > 1 && (int) $page <= 10000) {
            $parameters['page'] = (int) $page;
        }

        return redirect()->route('admin.umkm-stores.index', $parameters);
    }
}
