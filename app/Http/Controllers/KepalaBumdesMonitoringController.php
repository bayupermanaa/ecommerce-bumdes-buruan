<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\StoreOrder;
use App\Models\UmkmDisbursement;
use App\Models\UmkmEntitlement;
use App\Models\UmkmStore;
use App\Support\OrderFulfillmentStatus;
use App\Support\PaymentStatus;
use App\Support\StoreOrderFulfillmentStatus;
use App\Support\UmkmDisbursementStatus;
use App\Support\UmkmEntitlementStatus;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class KepalaBumdesMonitoringController extends Controller
{
    /**
     * Show read-only operational metrics derived from persisted transaction data.
     */
    public function dashboard(): View
    {
        return view('dashboards.kepala-bumdes', [
            'metrics' => [
                'registered_stores' => UmkmStore::query()->count(),
                'approved_stores' => UmkmStore::query()->where('status', 'approved')->count(),
                'approved_products' => Product::query()->where('status', 'approved')->count(),
                'total_orders' => Order::query()->count(),
                'completed_orders' => Order::query()->where('order_status', OrderFulfillmentStatus::COMPLETED)->count(),
                'in_fulfillment_orders' => Order::query()->where('order_status', OrderFulfillmentStatus::IN_FULFILLMENT)->count(),
                'cancelled_orders' => Order::query()->where('order_status', OrderFulfillmentStatus::CANCELLED)->count(),
                // An order has at most one successful payment, so its grand total is counted once.
                'confirmed_payment_total' => Order::query()
                    ->where('payment_status', PaymentStatus::PAID)
                    ->sum('grand_total'),
                // This is a transaction snapshot, not cash on hand or a withdrawable balance.
                'commission_snapshot_total' => StoreOrder::query()
                    ->where('commission_status', 'calculated')
                    ->whereHas('order', fn ($query) => $query->where('payment_status', PaymentStatus::PAID))
                    ->sum('commission_amount'),
            ],
            'entitlementMonitoring' => $this->entitlementMonitoring(),
            'disbursementMonitoring' => $this->disbursementMonitoring(),
        ]);
    }

    /**
     * Current-state entitlement aggregates. Amounts with a NULL source remain
     * explicitly unknown; they are never converted into a known zero amount.
     *
     * @return array{summary: array<string, int|string>, statuses: array<string, array<string, int|string>>}
     */
    private function entitlementMonitoring(): array
    {
        $summary = $this->normaliseEntitlementAggregate(UmkmEntitlement::query()
            ->selectRaw(
                'COUNT(*) as entitlement_count, '
                .'SUM(source_umkm_amount) as known_amount_total, '
                .'SUM(CASE WHEN source_umkm_amount IS NULL THEN 1 ELSE 0 END) as unknown_amount_count'
            )
            ->first());

        $rows = UmkmEntitlement::query()
            ->selectRaw(
                'status, COUNT(*) as entitlement_count, '
                .'SUM(source_umkm_amount) as known_amount_total, '
                .'SUM(CASE WHEN source_umkm_amount IS NULL THEN 1 ELSE 0 END) as unknown_amount_count'
            )
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn (object $row): array => [$row->status => $this->normaliseEntitlementAggregate($row)]);

        $statuses = [];
        foreach (UmkmEntitlementStatus::values() as $status) {
            $statuses[$status] = $rows->get($status, [
                'entitlement_count' => 0,
                'known_amount_total' => '0',
                'unknown_amount_count' => 0,
            ]);
        }

        return compact('summary', 'statuses');
    }

    /**
     * Current-state disbursement aggregates. Only paid is a final completed
     * disbursement; transfer_failed is deliberately outside active totals.
     *
     * @return array{summary: array<string, int|string>, statuses: array<string, array<string, int|string>>}
     */
    private function disbursementMonitoring(): array
    {
        $inProgress = [
            UmkmDisbursementStatus::REQUESTED,
            UmkmDisbursementStatus::UNDER_REVIEW,
            UmkmDisbursementStatus::APPROVED,
        ];
        $nonFinal = [
            UmkmDisbursementStatus::REJECTED,
            UmkmDisbursementStatus::CANCELLED,
            UmkmDisbursementStatus::TRANSFER_FAILED,
        ];

        $summary = $this->normaliseDisbursementAggregate(UmkmDisbursement::query()
            ->selectRaw(
                'COUNT(*) as disbursement_count, '
                .'SUM(requested_amount) as historical_requested_total, '
                .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as paid_count, '
                .'SUM(CASE WHEN status = ? THEN requested_amount ELSE 0 END) as paid_total, '
                .'SUM(CASE WHEN status IN (?, ?, ?) THEN 1 ELSE 0 END) as in_progress_count, '
                .'SUM(CASE WHEN status IN (?, ?, ?) THEN requested_amount ELSE 0 END) as in_progress_total, '
                .'SUM(CASE WHEN status IN (?, ?, ?) THEN 1 ELSE 0 END) as non_final_count, '
                .'SUM(CASE WHEN status IN (?, ?, ?) THEN requested_amount ELSE 0 END) as non_final_total',
                [
                    UmkmDisbursementStatus::PAID,
                    UmkmDisbursementStatus::PAID,
                    ...$inProgress,
                    ...$inProgress,
                    ...$nonFinal,
                    ...$nonFinal,
                ],
            )
            ->first());

        $rows = UmkmDisbursement::query()
            ->selectRaw('status, COUNT(*) as disbursement_count, SUM(requested_amount) as requested_amount_total')
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn (object $row): array => [$row->status => $this->normaliseDisbursementStatusAggregate($row)]);

        $statuses = [];
        foreach (UmkmDisbursementStatus::values() as $status) {
            $statuses[$status] = $rows->get($status, [
                'disbursement_count' => 0,
                'requested_amount_total' => '0',
            ]);
        }

        return compact('summary', 'statuses');
    }

    /** @return array{entitlement_count: int, known_amount_total: string, unknown_amount_count: int} */
    private function normaliseEntitlementAggregate(object $aggregate): array
    {
        return [
            'entitlement_count' => (int) ($aggregate->entitlement_count ?? 0),
            'known_amount_total' => $this->decimalString($aggregate->known_amount_total ?? null),
            'unknown_amount_count' => (int) ($aggregate->unknown_amount_count ?? 0),
        ];
    }

    /** @return array<string, int|string> */
    private function normaliseDisbursementAggregate(object $aggregate): array
    {
        return [
            'disbursement_count' => (int) ($aggregate->disbursement_count ?? 0),
            'historical_requested_total' => $this->decimalString($aggregate->historical_requested_total ?? null),
            'paid_count' => (int) ($aggregate->paid_count ?? 0),
            'paid_total' => $this->decimalString($aggregate->paid_total ?? null),
            'in_progress_count' => (int) ($aggregate->in_progress_count ?? 0),
            'in_progress_total' => $this->decimalString($aggregate->in_progress_total ?? null),
            'non_final_count' => (int) ($aggregate->non_final_count ?? 0),
            'non_final_total' => $this->decimalString($aggregate->non_final_total ?? null),
        ];
    }

    /** @return array{disbursement_count: int, requested_amount_total: string} */
    private function normaliseDisbursementStatusAggregate(object $aggregate): array
    {
        return [
            'disbursement_count' => (int) ($aggregate->disbursement_count ?? 0),
            'requested_amount_total' => $this->decimalString($aggregate->requested_amount_total ?? null),
        ];
    }

    /** Normalise only a database DECIMAL result; never use it for arithmetic. */
    private function decimalString(mixed $amount): string
    {
        if ($amount === null || $amount === '') {
            return '0';
        }

        // SQLite may return DECIMAL aggregates as floats; MySQL preserves them
        // as strings. This is a driver-boundary presentation normalisation only.
        if (is_float($amount)) {
            return sprintf('%.2F', $amount);
        }

        return (string) $amount;
    }

    /**
     * List parent orders once, with a child-store commission aggregate for each order.
     */
    public function transactions(Request $request): View
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'payment_status' => ['nullable', Rule::in(PaymentStatus::orderValues())],
            'order_status' => ['nullable', Rule::in(OrderFulfillmentStatus::values())],
        ]);

        $orders = Order::query()
            ->withCount('storeOrders')
            ->withSum([
                'storeOrders as commission_snapshot_total' => fn ($query) => $query
                    ->where('commission_status', 'calculated'),
            ], 'commission_amount')
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->when($filters['payment_status'] ?? null, fn ($query, $status) => $query->where('payment_status', $status))
            ->when($filters['order_status'] ?? null, fn ($query, $status) => $query->where('order_status', $status))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('kepala-bumdes.transactions.index', [
            'orders' => $orders,
            'filters' => [
                'date_from' => $filters['date_from'] ?? '',
                'date_to' => $filters['date_to'] ?? '',
                'payment_status' => $filters['payment_status'] ?? '',
                'order_status' => $filters['order_status'] ?? '',
            ],
        ]);
    }

    /**
     * Summarize each store from immutable store-order and commission snapshots.
     */
    public function stores(Request $request): View
    {
        $filters = $this->validatedStoreReportFilters($request);

        $stores = UmkmStore::query()
            ->when($filters['store_id'] ?? null, fn ($query, $storeId) => $query->whereKey($storeId))
            ->withCount([
                'storeOrders as store_orders_count' => fn ($query) => $this->applyStoreOrderFilters($query, $filters),
                'storeOrders as paid_store_orders_count' => fn ($query) => $this->applyPaidStoreOrderFilters($query, $filters),
                'storeOrders as completed_store_orders_count' => fn ($query) => $this->applyStoreOrderFilters($query, $filters)
                    ->where('fulfillment_status', StoreOrderFulfillmentStatus::COMPLETED),
                'storeOrders as paid_without_commission_snapshot_count' => fn ($query) => $this->applyPaidStoreOrderFilters($query, $filters)
                    ->where(function ($snapshotQuery): void {
                        $snapshotQuery->whereNull('commission_status')
                            ->orWhere('commission_status', '!=', 'calculated');
                    }),
            ])
            ->withSum([
                'storeOrders as confirmed_sales_total' => fn ($query) => $this->applyPaidStoreOrderFilters($query, $filters),
            ], 'total')
            ->withSum([
                'storeOrders as commission_snapshot_total' => fn ($query) => $this->applyPaidStoreOrderFilters($query, $filters)
                    ->where('commission_status', 'calculated'),
            ], 'commission_amount')
            ->withSum([
                'storeOrders as estimated_umkm_total' => fn ($query) => $this->applyPaidStoreOrderFilters($query, $filters)
                    ->where('commission_status', 'calculated'),
            ], 'umkm_amount')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('kepala-bumdes.stores.index', [
            'stores' => $stores,
            'filters' => $filters,
            'storeOptions' => UmkmStore::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Show one store's individual transaction snapshots without exposing payment evidence.
     */
    public function storeDetail(Request $request, UmkmStore $store): View
    {
        $filters = $this->validatedStoreReportFilters($request, false);
        $storeOrders = $store->storeOrders()
            ->with('order:id,order_number,created_at,payment_status,order_status')
            ->when($filters['fulfillment_status'] ?? null, fn ($query, $status) => $query->where('fulfillment_status', $status))
            ->whereHas('order', function ($query) use ($filters): void {
                $query
                    ->when($filters['date_from'] ?? null, fn ($orderQuery, $date) => $orderQuery->whereDate('created_at', '>=', $date))
                    ->when($filters['date_to'] ?? null, fn ($orderQuery, $date) => $orderQuery->whereDate('created_at', '<=', $date));
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('kepala-bumdes.stores.show', compact('store', 'storeOrders', 'filters'));
    }

    /**
     * @return array{date_from: string, date_to: string, store_id?: int, fulfillment_status: string}
     */
    private function validatedStoreReportFilters(Request $request, bool $includeStore = true): array
    {
        $rules = [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'fulfillment_status' => ['nullable', Rule::in(StoreOrderFulfillmentStatus::values())],
        ];

        if ($includeStore) {
            $rules['store_id'] = ['nullable', 'integer', Rule::exists('umkm_stores', 'id')];
        }

        $validated = $request->validate($rules);

        return [
            'date_from' => $validated['date_from'] ?? '',
            'date_to' => $validated['date_to'] ?? '',
            'fulfillment_status' => $validated['fulfillment_status'] ?? '',
            ...($includeStore && isset($validated['store_id']) ? ['store_id' => $validated['store_id']] : []),
        ];
    }

    private function applyStoreOrderFilters($query, array $filters)
    {
        return $query
            ->when($filters['fulfillment_status'] ?? null, fn ($storeOrderQuery, $status) => $storeOrderQuery->where('fulfillment_status', $status))
            ->whereHas('order', function ($orderQuery) use ($filters): void {
                $orderQuery
                    ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
                    ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date));
            });
    }

    private function applyPaidStoreOrderFilters($query, array $filters)
    {
        return $this->applyStoreOrderFilters($query, $filters)
            ->whereHas('order', fn ($orderQuery) => $orderQuery->where('payment_status', PaymentStatus::PAID));
    }
}
