<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UmkmEntitlement;
use App\Services\UmkmEntitlementService;
use App\Support\UmkmEntitlementStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UmkmEntitlementController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', UmkmEntitlement::class);
        $filters = $request->validate([
            'store' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(UmkmEntitlementStatus::values())],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $entitlements = UmkmEntitlement::query()
            ->with(['umkmStore:id,name', 'order:id,order_number,payment_status', 'storeOrder:id,fulfillment_status'])
            ->when($filters['store'] ?? null, fn ($query, $store) => $query->whereHas('umkmStore', fn ($stores) => $stores->where('name', 'like', '%'.$store.'%')))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('accrued_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('accrued_at', '<=', $date))
            ->latest('accrued_at')->paginate(15)->withQueryString();

        return view('admin.umkm-entitlements.index', ['entitlements' => $entitlements, 'filters' => [
            'store' => $filters['store'] ?? '', 'status' => $filters['status'] ?? '',
            'date_from' => $filters['date_from'] ?? '', 'date_to' => $filters['date_to'] ?? '',
        ]]);
    }

    public function show(UmkmEntitlement $entitlement): View
    {
        Gate::authorize('view', $entitlement);
        $entitlement->load(['umkmStore', 'order', 'storeOrder', 'payment', 'events' => fn ($query) => $query->with('actor')->oldest('created_at')]);

        return view('admin.umkm-entitlements.show', compact('entitlement'));
    }

    public function reconcile(Request $request, UmkmEntitlement $entitlement, UmkmEntitlementService $service): RedirectResponse
    {
        Gate::authorize('reconcile', $entitlement);
        $notes = $request->validate(['notes' => ['nullable', 'string', 'max:1000']])['notes'] ?? null;

        try {
            $service->reconcile($entitlement, $request->user(), $notes);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return redirect()->route('admin.umkm-entitlements.show', $entitlement)->with('status', 'Hak UMKM berhasil direkonsiliasi.');
    }
}
