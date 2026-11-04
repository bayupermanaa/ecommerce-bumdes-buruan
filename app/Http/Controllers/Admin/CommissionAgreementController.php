<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommissionAgreement;
use App\Models\UmkmStore;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CommissionAgreementController extends Controller
{
    /**
     * List each UMKM and its commission agreement history.
     */
    public function index(): View
    {
        Gate::authorize('viewAny', CommissionAgreement::class);

        $stores = UmkmStore::query()
            ->with([
                'user',
                'commissionAgreements' => fn ($query) => $query->latest('effective_start_date'),
            ])
            ->orderBy('name')
            ->get();

        return view('admin.commission-agreements.index', compact('stores'));
    }

    /**
     * Show a form that records a new immutable agreement draft.
     */
    public function create(UmkmStore $store): View
    {
        Gate::authorize('create', CommissionAgreement::class);

        abort_unless($store->status === 'approved', 404);

        return view('admin.commission-agreements.create', [
            'store' => $store,
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    /**
     * Create a draft. A separate action activates it after overlap checks.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', CommissionAgreement::class);

        $validated = $this->validatedAgreementData($request);

        try {
            $agreement = DB::transaction(function () use ($request, $validated): CommissionAgreement {
                $existingAgreement = CommissionAgreement::query()
                    ->where('idempotency_key', $validated['idempotency_key'])
                    ->lockForUpdate()
                    ->first();

                if ($existingAgreement) {
                    return $existingAgreement;
                }

                $store = UmkmStore::query()
                    ->whereKey($validated['umkm_store_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($store->status !== 'approved') {
                    throw ValidationException::withMessages([
                        'umkm_store_id' => 'Perjanjian komisi hanya dapat dibuat untuk toko yang telah disetujui.',
                    ]);
                }

                return CommissionAgreement::create([
                    ...$validated,
                    'status' => 'draft',
                    'created_by' => $request->user()->id,
                ]);
            });
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent request may win the unique-key race after both checks pass.
            $agreement = CommissionAgreement::query()
                ->where('idempotency_key', $validated['idempotency_key'])
                ->first();

            if (! $agreement) {
                throw $exception;
            }
        }

        $statusMessage = $agreement->wasRecentlyCreated
            ? 'Draft perjanjian komisi berhasil dibuat.'
            : 'Draft perjanjian komisi sudah dibuat sebelumnya.';

        return redirect()
            ->route('admin.commission-agreements.show', $agreement)
            ->with('status', $statusMessage);
    }

    /**
     * Show one agreement together with the store's immutable history.
     */
    public function show(CommissionAgreement $agreement): View
    {
        Gate::authorize('view', $agreement);

        $agreement->load(['umkmStore.user', 'creator']);
        $history = $agreement->umkmStore->commissionAgreements()
            ->with('creator')
            ->latest('effective_start_date')
            ->get();

        return view('admin.commission-agreements.show', compact('agreement', 'history'));
    }

    /**
     * Activate a draft only when it currently applies and cannot overlap another active agreement.
     */
    public function activate(CommissionAgreement $agreement): RedirectResponse
    {
        Gate::authorize('activate', $agreement);

        DB::transaction(function () use ($agreement): void {
            $store = UmkmStore::query()
                ->whereKey($agreement->umkm_store_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedAgreement = CommissionAgreement::query()
                ->whereKey($agreement->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedAgreement->status !== 'draft') {
                throw ValidationException::withMessages([
                    'agreement' => 'Hanya draft perjanjian yang dapat diaktifkan.',
                ]);
            }

            $today = today();

            if ($lockedAgreement->effective_start_date->gt($today)
                || ($lockedAgreement->effective_end_date && $lockedAgreement->effective_end_date->lt($today))) {
                throw ValidationException::withMessages([
                    'agreement' => 'Perjanjian hanya dapat diaktifkan selama masa berlakunya.',
                ]);
            }

            if ($this->hasOverlappingActiveAgreement($lockedAgreement)) {
                throw ValidationException::withMessages([
                    'agreement' => 'Masih ada perjanjian aktif dengan periode yang bertabrakan untuk toko ini.',
                ]);
            }

            $lockedAgreement->forceFill([
                'status' => 'active',
                'activated_at' => now(),
            ])->save();
        });

        return redirect()
            ->route('admin.commission-agreements.show', $agreement)
            ->with('status', 'Perjanjian komisi berhasil diaktifkan.');
    }

    /**
     * Cancel a draft without deleting its historical record.
     */
    public function cancel(CommissionAgreement $agreement): RedirectResponse
    {
        Gate::authorize('cancel', $agreement);

        DB::transaction(function () use ($agreement): void {
            UmkmStore::query()
                ->whereKey($agreement->umkm_store_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedAgreement = CommissionAgreement::query()
                ->whereKey($agreement->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedAgreement->status !== 'draft') {
                throw ValidationException::withMessages([
                    'agreement' => 'Hanya draft perjanjian yang dapat dibatalkan.',
                ]);
            }

            $lockedAgreement->forceFill([
                'status' => 'cancelled',
            ])->save();
        });

        return redirect()
            ->route('admin.commission-agreements.show', $agreement)
            ->with('status', 'Draft perjanjian komisi berhasil dibatalkan.');
    }

    /**
     * End an active agreement without deleting its historical terms.
     */
    public function end(Request $request, CommissionAgreement $agreement): RedirectResponse
    {
        Gate::authorize('end', $agreement);

        $validated = $request->validate([
            'effective_end_date' => ['required', 'date', 'before_or_equal:today'],
        ]);

        DB::transaction(function () use ($agreement, $validated): void {
            UmkmStore::query()
                ->whereKey($agreement->umkm_store_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedAgreement = CommissionAgreement::query()
                ->whereKey($agreement->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedAgreement->status !== 'active') {
                throw ValidationException::withMessages([
                    'agreement' => 'Hanya perjanjian aktif yang dapat diakhiri.',
                ]);
            }

            if ($validated['effective_end_date'] < $lockedAgreement->effective_start_date->toDateString()) {
                throw ValidationException::withMessages([
                    'effective_end_date' => 'Tanggal berakhir tidak boleh lebih awal dari tanggal mulai berlaku.',
                ]);
            }

            $lockedAgreement->forceFill([
                'status' => 'ended',
                'effective_end_date' => $validated['effective_end_date'],
                'ended_at' => now(),
            ])->save();
        });

        return redirect()
            ->route('admin.commission-agreements.show', $agreement)
            ->with('status', 'Perjanjian komisi telah diakhiri.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedAgreementData(Request $request): array
    {
        return $request->validate([
            'umkm_store_id' => ['required', 'integer', 'exists:umkm_stores,id'],
            'idempotency_key' => ['required', 'uuid'],
            'commission_percentage' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'effective_start_date' => ['required', 'date'],
            'effective_end_date' => ['nullable', 'date', 'after_or_equal:effective_start_date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    /**
     * The locked store serializes activation attempts for the same UMKM.
     */
    private function hasOverlappingActiveAgreement(CommissionAgreement $agreement): bool
    {
        $endDate = $agreement->effective_end_date?->toDateString() ?? '9999-12-31';

        return CommissionAgreement::query()
            ->where('umkm_store_id', $agreement->umkm_store_id)
            ->where('status', 'active')
            ->where('id', '!=', $agreement->id)
            ->whereDate('effective_start_date', '<=', $endDate)
            ->where(function ($query) use ($agreement): void {
                $query->whereNull('effective_end_date')
                    ->orWhereDate('effective_end_date', '>=', $agreement->effective_start_date->toDateString());
            })
            ->lockForUpdate()
            ->exists();
    }
}
