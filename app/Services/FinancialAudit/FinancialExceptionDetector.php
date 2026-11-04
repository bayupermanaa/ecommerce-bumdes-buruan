<?php

namespace App\Services\FinancialAudit;

use App\Models\StoreOrder;
use App\Models\UmkmDisbursement;
use App\Models\UmkmEntitlement;
use App\Support\PaymentStatus;
use App\Support\UmkmEntitlementStatus;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;

/**
 * Read-only detector for financial inconsistencies.
 *
 * Results are generated in a stable category/reference order. This service does
 * not persist exceptions and never invokes a financial mutation service.
 */
final class FinancialExceptionDetector
{
    public const PAY_ENT_MISSING = 'PAY_ENT_MISSING';
    public const ENT_PAYMENT_INVALID = 'ENT_PAYMENT_INVALID';
    public const ENT_SNAPSHOT_MISMATCH = 'ENT_SNAPSHOT_MISMATCH';
    public const ENT_HELD_REVIEW = 'ENT_HELD_REVIEW';
    public const ENT_RESERVED_NO_ACTIVE_ALLOCATION = 'ENT_RESERVED_NO_ACTIVE_ALLOCATION';
    public const DISB_ACTIVE_ALLOCATION_MISMATCH = 'DISB_ACTIVE_ALLOCATION_MISMATCH';
    public const DISB_TERMINAL_LOCK_REMAINS = 'DISB_TERMINAL_LOCK_REMAINS';
    public const DISB_PAID_VERIFICATION_MISSING = 'DISB_PAID_VERIFICATION_MISSING';
    public const DISB_FAILURE_RELEASE_MISSING = 'DISB_FAILURE_RELEASE_MISSING';
    public const DISB_DUPLICATE_ACTIVE = 'DISB_DUPLICATE_ACTIVE';
    public const EVENT_TRAIL_GAP = 'EVENT_TRAIL_GAP';

    /** Records created before the event-table foundation are treated as historical. */
    private const EVENT_TRAIL_FOUNDATION_AT = '2026-10-27 00:00:00';

    /** @var list<string> */
    private const ACTIVE_DISBURSEMENT_STATUSES = ['requested', 'under_review', 'approved'];

    /** @var list<string> */
    private const TERMINAL_DISBURSEMENT_STATUSES = ['paid', 'rejected', 'cancelled', 'transfer_failed'];

    /** @var list<string> */
    private const CATEGORIES = [
        self::PAY_ENT_MISSING,
        self::ENT_PAYMENT_INVALID,
        self::ENT_SNAPSHOT_MISMATCH,
        self::ENT_HELD_REVIEW,
        self::ENT_RESERVED_NO_ACTIVE_ALLOCATION,
        self::DISB_ACTIVE_ALLOCATION_MISMATCH,
        self::DISB_TERMINAL_LOCK_REMAINS,
        self::DISB_PAID_VERIFICATION_MISSING,
        self::DISB_FAILURE_RELEASE_MISSING,
        self::DISB_DUPLICATE_ACTIVE,
        self::EVENT_TRAIL_GAP,
    ];

    /**
     * Stream exceptions without loading complete financial tables into memory.
     *
     * Supported filters: category/categories and umkm_store_id.
     * Cross-category pagination is intentionally not exposed: each category has
     * different source relations and a SQL UNION would make safe context and
     * reason-specific records materially harder to preserve. Use chunk() for a
     * stable bounded scan instead.
     *
     * @param  array{category?: string, categories?: string|list<string>, umkm_store_id?: int|string|null}  $filters
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function scan(array $filters = []): LazyCollection
    {
        $categories = $this->categories($filters);
        $storeId = $this->storeId($filters['umkm_store_id'] ?? null);
        $detectedAt = now()->toIso8601String();

        return LazyCollection::make(function () use ($categories, $storeId, $detectedAt): \Generator {
            foreach ($categories as $category) {
                $exceptions = match ($category) {
                    self::PAY_ENT_MISSING => $this->payEntMissing($storeId, $detectedAt),
                    self::ENT_PAYMENT_INVALID => $this->entPaymentInvalid($storeId, $detectedAt),
                    self::ENT_SNAPSHOT_MISMATCH => $this->entSnapshotMismatch($storeId, $detectedAt),
                    self::ENT_HELD_REVIEW => $this->entHeldReview($storeId, $detectedAt),
                    self::ENT_RESERVED_NO_ACTIVE_ALLOCATION => $this->entReservedNoActiveAllocation($storeId, $detectedAt),
                    self::DISB_ACTIVE_ALLOCATION_MISMATCH => $this->disbActiveAllocationMismatch($storeId, $detectedAt),
                    self::DISB_TERMINAL_LOCK_REMAINS => $this->disbTerminalLockRemains($storeId, $detectedAt),
                    self::DISB_PAID_VERIFICATION_MISSING => $this->disbPaidVerificationMissing($storeId, $detectedAt),
                    self::DISB_FAILURE_RELEASE_MISSING => $this->disbFailureReleaseMissing($storeId, $detectedAt),
                    self::DISB_DUPLICATE_ACTIVE => $this->disbDuplicateActive($storeId, $detectedAt),
                    self::EVENT_TRAIL_GAP => $this->eventTrailGap($storeId, $detectedAt),
                };

                foreach ($exceptions as $exception) {
                    yield $exception;
                }
            }
        });
    }

    /**
     * Process a stable stream in bounded in-memory groups.
     *
     * @param  array<string, mixed>  $filters
     * @param  callable(Collection<int, array<string, mixed>>): void  $callback
     */
    public function chunk(array $filters, int $size, callable $callback): void
    {
        if ($size < 1) {
            throw new InvalidArgumentException('Chunk size harus lebih besar dari nol.');
        }

        $this->scan($filters)
            ->chunk($size)
            ->each(static fn (LazyCollection $exceptions) => $callback($exceptions->collect()));
    }

    /** @return iterable<int, array<string, mixed>> */
    private function payEntMissing(?int $storeId, string $detectedAt): iterable
    {
        $rows = StoreOrder::query()
            ->join('orders', 'orders.id', '=', 'store_orders.order_id')
            ->select([
                'store_orders.id as store_order_id',
                'store_orders.umkm_store_id',
                'store_orders.fulfillment_status',
                'orders.id as order_id',
                'orders.payment_confirmed_at',
            ])
            ->where('orders.payment_status', PaymentStatus::PAID)
            ->when($storeId !== null, fn ($query) => $query->where('store_orders.umkm_store_id', $storeId))
            ->whereExists(function (QueryBuilder $query): void {
                $query->selectRaw('1')
                    ->from('payments')
                    ->whereColumn('payments.order_id', 'orders.id')
                    ->whereColumn('payments.confirmed_order_id', 'orders.id')
                    ->where('payments.status', PaymentStatus::PAID);
            })
            ->whereNotExists(function (QueryBuilder $query): void {
                $query->selectRaw('1')
                    ->from('umkm_entitlements')
                    ->whereColumn('umkm_entitlements.store_order_id', 'store_orders.id');
            })
            ->orderBy('store_orders.id')
            ->cursor();

        foreach ($rows as $row) {
            yield $this->exception(
                self::PAY_ENT_MISSING,
                'high',
                (int) $row->umkm_store_id,
                'store_order',
                (int) $row->store_order_id,
                (string) $row->fulfillment_status,
                'StoreOrder pada order berbayar terkonfirmasi belum memiliki entitlement.',
                $detectedAt,
                $row->payment_confirmed_at,
                'confirmed_paid_store_order_has_no_entitlement',
                [
                    'reason' => 'confirmed_paid_store_order_has_no_entitlement',
                    'order_id' => (int) $row->order_id,
                    'possible_legacy_data' => true,
                ],
            );
        }
    }

    /** @return iterable<int, array<string, mixed>> */
    private function entPaymentInvalid(?int $storeId, string $detectedAt): iterable
    {
        $entitlements = UmkmEntitlement::query()
            ->join('orders', 'orders.id', '=', 'umkm_entitlements.order_id')
            ->join('payments', 'payments.id', '=', 'umkm_entitlements.payment_id')
            ->join('store_orders', 'store_orders.id', '=', 'umkm_entitlements.store_order_id')
            ->select([
                'umkm_entitlements.id as entitlement_id',
                'umkm_entitlements.umkm_store_id as entitlement_store_id',
                'umkm_entitlements.order_id as entitlement_order_id',
                'umkm_entitlements.payment_id as entitlement_payment_id',
                'umkm_entitlements.status as entitlement_status',
                'umkm_entitlements.accrued_at',
                'orders.id as order_id',
                'orders.payment_status as order_payment_status',
                'payments.order_id as payment_order_id',
                'payments.confirmed_order_id',
                'payments.status as payment_status',
                'store_orders.order_id as store_order_order_id',
                'store_orders.umkm_store_id as store_order_store_id',
            ])
            ->when($storeId !== null, fn ($query) => $query->where('umkm_entitlements.umkm_store_id', $storeId))
            ->orderBy('umkm_entitlements.id')
            ->cursor();

        foreach ($entitlements as $row) {
            $reasons = [];

            if ($row->order_payment_status !== PaymentStatus::PAID) {
                $reasons[] = 'order_not_paid';
            }
            if ($row->payment_status !== PaymentStatus::PAID) {
                $reasons[] = 'payment_not_paid';
            }
            if ((int) $row->payment_order_id !== (int) $row->entitlement_order_id) {
                $reasons[] = 'payment_order_mismatch';
            }
            if ((int) $row->confirmed_order_id !== (int) $row->entitlement_order_id) {
                $reasons[] = 'payment_not_confirmed_for_entitlement_order';
            }
            if ((int) $row->store_order_order_id !== (int) $row->entitlement_order_id) {
                $reasons[] = 'store_order_order_mismatch';
            }
            if ((int) $row->store_order_store_id !== (int) $row->entitlement_store_id) {
                $reasons[] = 'store_order_store_mismatch';
            }

            foreach ($reasons as $reason) {
                yield $this->exception(
                    self::ENT_PAYMENT_INVALID,
                    'critical',
                    (int) $row->entitlement_store_id,
                    'umkm_entitlement',
                    (int) $row->entitlement_id,
                    (string) $row->entitlement_status,
                    'Sumber pembayaran atau relasi entitlement tidak konsisten.',
                    $detectedAt,
                    $row->accrued_at,
                    $reason,
                    [
                        'reason' => $reason,
                        'order_id' => (int) $row->entitlement_order_id,
                        'payment_id' => (int) $row->entitlement_payment_id,
                    ],
                );
            }
        }

        $invalidPaidOrders = StoreOrder::query()
            ->join('orders', 'orders.id', '=', 'store_orders.order_id')
            ->select([
                'store_orders.id as store_order_id',
                'store_orders.umkm_store_id',
                'store_orders.fulfillment_status',
                'orders.id as order_id',
                'orders.payment_confirmed_at',
            ])
            ->where('orders.payment_status', PaymentStatus::PAID)
            ->when($storeId !== null, fn ($query) => $query->where('store_orders.umkm_store_id', $storeId))
            ->whereNotExists(function (QueryBuilder $query): void {
                $query->selectRaw('1')
                    ->from('payments')
                    ->whereColumn('payments.order_id', 'orders.id')
                    ->whereColumn('payments.confirmed_order_id', 'orders.id')
                    ->where('payments.status', PaymentStatus::PAID);
            })
            ->orderBy('store_orders.id')
            ->cursor();

        foreach ($invalidPaidOrders as $row) {
            yield $this->exception(
                self::ENT_PAYMENT_INVALID,
                'critical',
                (int) $row->umkm_store_id,
                'store_order',
                (int) $row->store_order_id,
                (string) $row->fulfillment_status,
                'Order ditandai paid tetapi tidak memiliki payment sukses terkonfirmasi yang konsisten.',
                $detectedAt,
                $row->payment_confirmed_at,
                'paid_order_without_valid_confirmed_payment',
                [
                    'reason' => 'paid_order_without_valid_confirmed_payment',
                    'order_id' => (int) $row->order_id,
                ],
            );
        }
    }

    /** @return iterable<int, array<string, mixed>> */
    private function entSnapshotMismatch(?int $storeId, string $detectedAt): iterable
    {
        $rows = UmkmEntitlement::query()
            ->join('store_orders', 'store_orders.id', '=', 'umkm_entitlements.store_order_id')
            ->select([
                'umkm_entitlements.id as entitlement_id',
                'umkm_entitlements.umkm_store_id',
                'umkm_entitlements.status as entitlement_status',
                'umkm_entitlements.accrued_at',
                'umkm_entitlements.commission_status_snapshot',
                'umkm_entitlements.source_umkm_amount',
                'umkm_entitlements.source_commission_amount',
                'umkm_entitlements.source_commission_percentage',
                'store_orders.commission_status as store_commission_status',
                'store_orders.umkm_amount as store_umkm_amount',
                'store_orders.commission_amount as store_commission_amount',
                'store_orders.commission_percentage as store_commission_percentage',
            ])
            ->where('umkm_entitlements.commission_status_snapshot', 'calculated')
            ->when($storeId !== null, fn ($query) => $query->where('umkm_entitlements.umkm_store_id', $storeId))
            ->orderBy('umkm_entitlements.id')
            ->cursor();

        foreach ($rows as $row) {
            $entitlementAmounts = [
                'source_umkm_amount' => $row->source_umkm_amount,
                'source_commission_amount' => $row->source_commission_amount,
                'source_commission_percentage' => $row->source_commission_percentage,
            ];
            $storeAmounts = [
                'umkm_amount' => $row->store_umkm_amount,
                'commission_amount' => $row->store_commission_amount,
                'commission_percentage' => $row->store_commission_percentage,
            ];

            if (in_array(null, $entitlementAmounts, true) || in_array(null, $storeAmounts, true)) {
                yield $this->snapshotException($row, $detectedAt, 'calculated_snapshot_incomplete', $entitlementAmounts, $storeAmounts);
                continue;
            }

            if ($row->store_commission_status !== 'calculated') {
                yield $this->snapshotException($row, $detectedAt, 'commission_status_mismatch', $entitlementAmounts, $storeAmounts);
            }

            if (! $this->sameDecimal($row->source_umkm_amount, $row->store_umkm_amount)
                || ! $this->sameDecimal($row->source_commission_amount, $row->store_commission_amount)
                || ! $this->sameDecimal($row->source_commission_percentage, $row->store_commission_percentage)) {
                yield $this->snapshotException($row, $detectedAt, 'calculated_snapshot_value_mismatch', $entitlementAmounts, $storeAmounts);
            }
        }
    }

    /** @return iterable<int, array<string, mixed>> */
    private function entHeldReview(?int $storeId, string $detectedAt): iterable
    {
        $rows = UmkmEntitlement::query()
            ->select(['id', 'umkm_store_id', 'status', 'accrued_at', 'held_reason'])
            ->where('status', UmkmEntitlementStatus::HELD)
            ->when($storeId !== null, fn ($query) => $query->where('umkm_store_id', $storeId))
            ->orderBy('id')
            ->cursor();

        foreach ($rows as $row) {
            $reason = blank($row->held_reason) ? 'held_reason_missing' : 'held_pending_review';

            yield $this->exception(
                self::ENT_HELD_REVIEW,
                'medium',
                (int) $row->umkm_store_id,
                'umkm_entitlement',
                (int) $row->id,
                (string) $row->status,
                'Entitlement berstatus held memerlukan pemeriksaan manual.',
                $detectedAt,
                $row->accrued_at,
                $reason,
                ['reason' => $reason],
            );
        }
    }

    /** @return iterable<int, array<string, mixed>> */
    private function entReservedNoActiveAllocation(?int $storeId, string $detectedAt): iterable
    {
        $rows = UmkmEntitlement::query()
            ->leftJoin('umkm_disbursement_allocations as allocations', function ($join): void {
                $join->on('allocations.umkm_entitlement_id', '=', 'umkm_entitlements.id')
                    ->whereNotNull('allocations.entitlement_reservation_lock');
            })
            ->leftJoin('umkm_disbursements as disbursements', 'disbursements.id', '=', 'allocations.umkm_disbursement_id')
            ->select([
                'umkm_entitlements.id as entitlement_id',
                'umkm_entitlements.umkm_store_id as entitlement_store_id',
                'umkm_entitlements.status as entitlement_status',
                'umkm_entitlements.accrued_at',
                'allocations.id as allocation_id',
                'allocations.entitlement_reservation_lock',
                'disbursements.id as disbursement_id',
                'disbursements.umkm_store_id as disbursement_store_id',
                'disbursements.status as disbursement_status',
                'disbursements.active_request_lock',
            ])
            ->where('umkm_entitlements.status', UmkmEntitlementStatus::RESERVED)
            ->when($storeId !== null, fn ($query) => $query->where('umkm_entitlements.umkm_store_id', $storeId))
            ->orderBy('umkm_entitlements.id')
            ->orderBy('allocations.id')
            ->cursor();

        $currentId = null;
        $group = [];
        foreach ($rows as $row) {
            if ($currentId !== null && $currentId !== (int) $row->entitlement_id) {
                yield from $this->reservedEntitlementExceptions($group, $detectedAt);
                $group = [];
            }
            $currentId = (int) $row->entitlement_id;
            $group[] = $row;
        }
        if ($group !== []) {
            yield from $this->reservedEntitlementExceptions($group, $detectedAt);
        }
    }

    /** @param list<object> $rows @return iterable<int, array<string, mixed>> */
    private function reservedEntitlementExceptions(array $rows, string $detectedAt): iterable
    {
        $entitlement = $rows[0];
        $allocations = array_values(array_filter($rows, fn (object $row): bool => $row->allocation_id !== null));
        $reasons = [];

        if ($allocations === []) {
            $reasons[] = 'reserved_without_active_allocation';
        }

        foreach ($allocations as $allocation) {
            if (! in_array($allocation->disbursement_status, self::ACTIVE_DISBURSEMENT_STATUSES, true)) {
                $reasons[] = 'allocation_not_on_active_disbursement';
            }
            if ((int) $allocation->disbursement_store_id !== (int) $entitlement->entitlement_store_id) {
                $reasons[] = 'allocation_disbursement_store_mismatch';
            }
            if ($allocation->active_request_lock !== 'store:'.$entitlement->entitlement_store_id) {
                $reasons[] = 'active_request_lock_mismatch';
            }
            if ($allocation->entitlement_reservation_lock !== 'entitlement:'.$entitlement->entitlement_id) {
                $reasons[] = 'entitlement_reservation_lock_mismatch';
            }
        }
        if (count($allocations) > 1) {
            $reasons[] = 'multiple_active_allocation_locks';
        }

        foreach (array_values(array_unique($reasons)) as $reason) {
            yield $this->exception(
                self::ENT_RESERVED_NO_ACTIVE_ALLOCATION,
                'critical',
                (int) $entitlement->entitlement_store_id,
                'umkm_entitlement',
                (int) $entitlement->entitlement_id,
                (string) $entitlement->entitlement_status,
                'Entitlement reserved tidak memiliki allocation aktif yang konsisten.',
                $detectedAt,
                $entitlement->accrued_at,
                $reason,
                [
                    'reason' => $reason,
                    'active_allocation_count' => count($allocations),
                ],
            );
        }
    }

    /** @return iterable<int, array<string, mixed>> */
    private function disbActiveAllocationMismatch(?int $storeId, string $detectedAt): iterable
    {
        $rows = $this->disbursementAllocationRows(self::ACTIVE_DISBURSEMENT_STATUSES, $storeId);
        $currentId = null;
        $group = [];
        foreach ($rows as $row) {
            if ($currentId !== null && $currentId !== (int) $row->disbursement_id) {
                yield from $this->activeDisbursementExceptions($group, $detectedAt);
                $group = [];
            }
            $currentId = (int) $row->disbursement_id;
            $group[] = $row;
        }
        if ($group !== []) {
            yield from $this->activeDisbursementExceptions($group, $detectedAt);
        }
    }

    /** @param list<object> $rows @return iterable<int, array<string, mixed>> */
    private function activeDisbursementExceptions(array $rows, string $detectedAt): iterable
    {
        $disbursement = $rows[0];
        $allocations = array_values(array_filter($rows, fn (object $row): bool => $row->allocation_id !== null));
        $reasons = [];
        $minorTotal = 0;
        $hasInvalidAmount = false;

        if ($disbursement->active_request_lock !== 'store:'.$disbursement->umkm_store_id) {
            $reasons[] = 'active_request_lock_mismatch';
        }
        if ($allocations === []) {
            $reasons[] = 'active_disbursement_without_allocation';
        }

        foreach ($allocations as $allocation) {
            $minorUnits = $this->minorUnits($allocation->allocation_amount);
            if ($minorUnits === null) {
                $hasInvalidAmount = true;
            } else {
                $minorTotal += $minorUnits;
            }
            if ($allocation->entitlement_reservation_lock !== 'entitlement:'.$allocation->entitlement_id) {
                $reasons[] = 'entitlement_reservation_lock_mismatch';
            }
            if ((int) $allocation->entitlement_store_id !== (int) $disbursement->umkm_store_id) {
                $reasons[] = 'allocation_entitlement_store_mismatch';
            }
            if ($allocation->entitlement_status !== UmkmEntitlementStatus::RESERVED) {
                $reasons[] = 'allocation_entitlement_not_reserved';
            }
            if (! $this->sameDecimal($allocation->allocation_amount, $allocation->source_umkm_amount)) {
                $reasons[] = 'allocation_amount_snapshot_mismatch';
            }
        }

        $requestedMinorUnits = $this->minorUnits($disbursement->requested_amount);
        if ($hasInvalidAmount || $requestedMinorUnits === null) {
            $reasons[] = 'allocation_amount_invalid';
        } elseif ($minorTotal !== $requestedMinorUnits) {
            $reasons[] = 'allocation_total_mismatch';
        }

        foreach (array_values(array_unique($reasons)) as $reason) {
            yield $this->exception(
                self::DISB_ACTIVE_ALLOCATION_MISMATCH,
                'critical',
                (int) $disbursement->umkm_store_id,
                'umkm_disbursement',
                (int) $disbursement->disbursement_id,
                (string) $disbursement->disbursement_status,
                'Disbursement aktif memiliki allocation atau reservation yang tidak konsisten.',
                $detectedAt,
                $disbursement->requested_at,
                $reason,
                [
                    'reason' => $reason,
                    'allocation_count' => count($allocations),
                    'requested_amount' => $this->normaliseDecimal((string) $disbursement->requested_amount),
                    'allocation_total' => $this->fromMinorUnits($minorTotal),
                ],
            );
        }
    }

    /** @return iterable<int, array<string, mixed>> */
    private function disbTerminalLockRemains(?int $storeId, string $detectedAt): iterable
    {
        $rows = $this->disbursementAllocationRows(self::TERMINAL_DISBURSEMENT_STATUSES, $storeId);
        $currentId = null;
        $group = [];
        foreach ($rows as $row) {
            if ($currentId !== null && $currentId !== (int) $row->disbursement_id) {
                yield from $this->terminalDisbursementExceptions($group, $detectedAt);
                $group = [];
            }
            $currentId = (int) $row->disbursement_id;
            $group[] = $row;
        }
        if ($group !== []) {
            yield from $this->terminalDisbursementExceptions($group, $detectedAt);
        }
    }

    /** @param list<object> $rows @return iterable<int, array<string, mixed>> */
    private function terminalDisbursementExceptions(array $rows, string $detectedAt): iterable
    {
        $disbursement = $rows[0];
        $lockedAllocations = array_values(array_filter($rows, fn (object $row): bool => $row->allocation_id !== null && $row->entitlement_reservation_lock !== null));
        $reasons = [];

        if ($disbursement->active_request_lock !== null) {
            $reasons[] = 'terminal_active_request_lock_remains';
        }
        if ($lockedAllocations !== []) {
            $reasons[] = 'terminal_allocation_reservation_lock_remains';
            $expectedStatus = $disbursement->disbursement_status === 'paid'
                ? UmkmEntitlementStatus::SETTLED
                : UmkmEntitlementStatus::AVAILABLE;
            foreach ($lockedAllocations as $allocation) {
                if ($allocation->entitlement_status !== $expectedStatus) {
                    $reasons[] = 'terminal_locked_entitlement_status_mismatch';
                    break;
                }
            }
        }

        foreach (array_values(array_unique($reasons)) as $reason) {
            yield $this->exception(
                self::DISB_TERMINAL_LOCK_REMAINS,
                'critical',
                (int) $disbursement->umkm_store_id,
                'umkm_disbursement',
                (int) $disbursement->disbursement_id,
                (string) $disbursement->disbursement_status,
                'Disbursement terminal masih memiliki lock reservation yang aktif.',
                $detectedAt,
                $disbursement->requested_at,
                $reason,
                [
                    'reason' => $reason,
                    'locked_allocation_count' => count($lockedAllocations),
                ],
            );
        }
    }

    /** @return iterable<int, array<string, mixed>> */
    private function disbPaidVerificationMissing(?int $storeId, string $detectedAt): iterable
    {
        $rows = UmkmDisbursement::query()
            ->select([
                'id',
                'umkm_store_id',
                'status',
                'requested_at',
                'transfer_reference',
                'transfer_amount',
                'transfer_proof_path',
                'transfer_recorded_by',
                'transfer_recorded_at',
                'transfer_verified_by',
                'transfer_verified_at',
            ])
            ->where('status', 'paid')
            ->when($storeId !== null, fn ($query) => $query->where('umkm_store_id', $storeId))
            ->orderBy('id')
            ->cursor();

        foreach ($rows as $row) {
            $reasons = [];
            if (blank($row->transfer_reference)) {
                $reasons[] = 'transfer_reference_missing';
            }
            if (blank($row->transfer_proof_path)) {
                $reasons[] = 'transfer_proof_missing';
            }
            if ($row->transfer_amount === null) {
                $reasons[] = 'transfer_amount_missing';
            }
            if ($row->transfer_recorded_by === null) {
                $reasons[] = 'transfer_recorder_missing';
            }
            if ($row->transfer_recorded_at === null) {
                $reasons[] = 'transfer_recorded_at_missing';
            }
            if ($row->transfer_verified_by === null) {
                $reasons[] = 'transfer_verifier_missing';
            }
            if ($row->transfer_verified_at === null) {
                $reasons[] = 'transfer_verified_at_missing';
            }
            if ($row->transfer_recorded_by !== null
                && $row->transfer_verified_by !== null
                && (int) $row->transfer_recorded_by === (int) $row->transfer_verified_by) {
                $reasons[] = 'same_transfer_actor';
            }

            foreach ($reasons as $reason) {
                yield $this->exception(
                    self::DISB_PAID_VERIFICATION_MISSING,
                    'critical',
                    (int) $row->umkm_store_id,
                    'umkm_disbursement',
                    (int) $row->id,
                    (string) $row->status,
                    'Disbursement paid tidak memiliki metadata verifikasi transfer yang lengkap.',
                    $detectedAt,
                    $row->transfer_verified_at ?? $row->requested_at,
                    $reason,
                    [
                        'reason' => $reason,
                        'has_transfer_reference' => filled($row->transfer_reference),
                        'has_transfer_proof' => filled($row->transfer_proof_path),
                        'has_transfer_amount' => $row->transfer_amount !== null,
                        'has_transfer_recorder' => $row->transfer_recorded_by !== null,
                        'has_transfer_recorded_at' => $row->transfer_recorded_at !== null,
                        'has_transfer_verifier' => $row->transfer_verified_by !== null,
                        'has_transfer_verified_at' => $row->transfer_verified_at !== null,
                    ],
                );
            }
        }
    }

    /** @return iterable<int, array<string, mixed>> */
    private function disbFailureReleaseMissing(?int $storeId, string $detectedAt): iterable
    {
        $rows = UmkmDisbursement::query()
            ->leftJoin('umkm_disbursement_allocations as allocations', 'allocations.umkm_disbursement_id', '=', 'umkm_disbursements.id')
            ->select([
                'umkm_disbursements.id as disbursement_id',
                'umkm_disbursements.umkm_store_id',
                'umkm_disbursements.status as disbursement_status',
                'umkm_disbursements.requested_at',
                'umkm_disbursements.active_request_lock',
                'umkm_disbursements.transfer_failure_reason',
                'umkm_disbursements.transfer_failure_proof_path',
                'umkm_disbursements.transfer_failure_recorded_by',
                'umkm_disbursements.transfer_failure_verified_by',
                'umkm_disbursements.transfer_failure_recorded_at',
                'umkm_disbursements.transfer_failure_verified_at',
                'allocations.id as allocation_id',
                'allocations.entitlement_reservation_lock',
            ])
            ->where('umkm_disbursements.status', 'transfer_failed')
            ->when($storeId !== null, fn ($query) => $query->where('umkm_disbursements.umkm_store_id', $storeId))
            ->orderBy('umkm_disbursements.id')
            ->orderBy('allocations.id')
            ->cursor();

        $currentId = null;
        $group = [];
        foreach ($rows as $row) {
            if ($currentId !== null && $currentId !== (int) $row->disbursement_id) {
                yield from $this->failureReleaseExceptions($group, $detectedAt);
                $group = [];
            }
            $currentId = (int) $row->disbursement_id;
            $group[] = $row;
        }
        if ($group !== []) {
            yield from $this->failureReleaseExceptions($group, $detectedAt);
        }
    }

    /** @param list<object> $rows @return iterable<int, array<string, mixed>> */
    private function failureReleaseExceptions(array $rows, string $detectedAt): iterable
    {
        $disbursement = $rows[0];
        $lockedAllocationCount = count(array_filter(
            $rows,
            fn (object $row): bool => $row->allocation_id !== null && $row->entitlement_reservation_lock !== null,
        ));
        $reasons = [];

        if (blank($disbursement->transfer_failure_reason)) {
            $reasons[] = 'failure_reason_missing';
        }
        if (blank($disbursement->transfer_failure_proof_path)) {
            $reasons[] = 'failure_proof_missing';
        }
        if ($disbursement->transfer_failure_recorded_by === null) {
            $reasons[] = 'failure_recorder_missing';
        }
        if ($disbursement->transfer_failure_verified_by === null) {
            $reasons[] = 'failure_verifier_missing';
        }
        if ($disbursement->transfer_failure_recorded_at === null) {
            $reasons[] = 'failure_recorded_at_missing';
        }
        if ($disbursement->transfer_failure_verified_at === null) {
            $reasons[] = 'failure_verified_at_missing';
        }
        if ($disbursement->transfer_failure_recorded_by !== null
            && $disbursement->transfer_failure_verified_by !== null
            && (int) $disbursement->transfer_failure_recorded_by === (int) $disbursement->transfer_failure_verified_by) {
            $reasons[] = 'same_failure_actor';
        }
        if ($disbursement->active_request_lock !== null) {
            $reasons[] = 'active_request_lock_remains';
        }
        if ($lockedAllocationCount > 0) {
            $reasons[] = 'allocation_reservation_lock_remains';
        }

        foreach ($reasons as $reason) {
            yield $this->exception(
                self::DISB_FAILURE_RELEASE_MISSING,
                'critical',
                (int) $disbursement->umkm_store_id,
                'umkm_disbursement',
                (int) $disbursement->disbursement_id,
                (string) $disbursement->disbursement_status,
                'Disbursement transfer_failed tidak memiliki metadata kegagalan atau pelepasan reservation yang lengkap.',
                $detectedAt,
                $disbursement->transfer_failure_verified_at ?? $disbursement->requested_at,
                $reason,
                [
                    'reason' => $reason,
                    'has_failure_reason' => filled($disbursement->transfer_failure_reason),
                    'has_failure_proof' => filled($disbursement->transfer_failure_proof_path),
                    'has_failure_recorder' => $disbursement->transfer_failure_recorded_by !== null,
                    'has_failure_verifier' => $disbursement->transfer_failure_verified_by !== null,
                    'has_failure_recorded_at' => $disbursement->transfer_failure_recorded_at !== null,
                    'has_failure_verified_at' => $disbursement->transfer_failure_verified_at !== null,
                    'has_active_request_lock' => $disbursement->active_request_lock !== null,
                    'locked_allocation_count' => $lockedAllocationCount,
                ],
            );
        }
    }

    /** @return iterable<int, array<string, mixed>> */
    private function disbDuplicateActive(?int $storeId, string $detectedAt): iterable
    {
        $rows = UmkmDisbursement::query()
            ->selectRaw('umkm_store_id, COUNT(*) as active_disbursement_count, MIN(id) as first_disbursement_id, MIN(requested_at) as first_requested_at')
            ->whereIn('status', self::ACTIVE_DISBURSEMENT_STATUSES)
            ->when($storeId !== null, fn ($query) => $query->where('umkm_store_id', $storeId))
            ->groupBy('umkm_store_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('umkm_store_id')
            ->cursor();

        foreach ($rows as $row) {
            yield $this->exception(
                self::DISB_DUPLICATE_ACTIVE,
                'critical',
                (int) $row->umkm_store_id,
                'umkm_store',
                (int) $row->umkm_store_id,
                'multiple_active_disbursements',
                'Toko memiliki lebih dari satu disbursement berstatus aktif.',
                $detectedAt,
                $row->first_requested_at,
                'active_disbursement_count_gt_one',
                [
                    'reason' => 'active_disbursement_count_gt_one',
                    'active_disbursement_count' => (int) $row->active_disbursement_count,
                    'first_disbursement_id' => (int) $row->first_disbursement_id,
                ],
            );
        }
    }

    /**
     * Detect only transition events guaranteed by the current services.
     *
     * Rows created before the event-table foundation are intentionally ignored:
     * their missing audit records cannot be distinguished safely from legacy
     * data that pre-dates the foundation.
     *
     * @return iterable<int, array<string, mixed>>
     */
    private function eventTrailGap(?int $storeId, string $detectedAt): iterable
    {
        yield from $this->entitlementTransitionEventGaps($storeId, $detectedAt, UmkmEntitlementStatus::SETTLED, 'settled', 'settled_event_missing');
        yield from $this->entitlementTransitionEventGaps($storeId, $detectedAt, UmkmEntitlementStatus::RESERVED, 'reserved', 'reserved_event_missing');

        foreach ([
            ['paid', 'transfer_verified', 'paid_transfer_verified_event_missing'],
            ['paid', 'paid', 'paid_event_missing'],
            ['transfer_failed', 'transfer_failed', 'transfer_failed_event_missing'],
            ['rejected', 'rejected', 'rejected_event_missing'],
            ['cancelled', 'cancelled', 'cancelled_event_missing'],
        ] as [$status, $eventType, $reason]) {
            yield from $this->disbursementTransitionEventGaps($storeId, $detectedAt, $status, $eventType, $reason);
        }
    }

    /** @return iterable<int, array<string, mixed>> */
    private function entitlementTransitionEventGaps(?int $storeId, string $detectedAt, string $status, string $eventType, string $reason): iterable
    {
        $rows = UmkmEntitlement::query()
            ->select(['id', 'umkm_store_id', 'status', 'created_at', 'accrued_at', 'settled_at'])
            ->where('status', $status)
            ->where('created_at', '>=', self::EVENT_TRAIL_FOUNDATION_AT)
            ->when($storeId !== null, fn ($query) => $query->where('umkm_store_id', $storeId))
            ->whereNotExists(function (QueryBuilder $query) use ($eventType, $status): void {
                $query->selectRaw('1')
                    ->from('umkm_entitlement_events')
                    ->whereColumn('umkm_entitlement_events.umkm_entitlement_id', 'umkm_entitlements.id')
                    ->where('umkm_entitlement_events.event_type', $eventType)
                    ->where('umkm_entitlement_events.to_status', $status);
            })
            ->orderBy('id')
            ->cursor();

        foreach ($rows as $row) {
            yield $this->exception(
                self::EVENT_TRAIL_GAP,
                'medium',
                (int) $row->umkm_store_id,
                'umkm_entitlement',
                (int) $row->id,
                (string) $row->status,
                'Entitlement tidak memiliki event transisi wajib untuk status saat ini.',
                $detectedAt,
                $row->settled_at ?? $row->accrued_at ?? $row->created_at,
                $reason,
                [
                    'reason' => $reason,
                    'expected_event_type' => $eventType,
                    'expected_to_status' => $status,
                    'event_foundation_at' => self::EVENT_TRAIL_FOUNDATION_AT,
                ],
            );
        }
    }

    /** @return iterable<int, array<string, mixed>> */
    private function disbursementTransitionEventGaps(?int $storeId, string $detectedAt, string $status, string $eventType, string $reason): iterable
    {
        $rows = UmkmDisbursement::query()
            ->select(['id', 'umkm_store_id', 'status', 'created_at', 'requested_at', 'paid_at', 'transfer_failure_verified_at'])
            ->where('status', $status)
            ->where('created_at', '>=', self::EVENT_TRAIL_FOUNDATION_AT)
            ->when($storeId !== null, fn ($query) => $query->where('umkm_store_id', $storeId))
            ->whereNotExists(function (QueryBuilder $query) use ($eventType, $status): void {
                $query->selectRaw('1')
                    ->from('umkm_disbursement_events')
                    ->whereColumn('umkm_disbursement_events.umkm_disbursement_id', 'umkm_disbursements.id')
                    ->where('umkm_disbursement_events.event_type', $eventType)
                    ->where('umkm_disbursement_events.to_status', $status);
            })
            ->orderBy('id')
            ->cursor();

        foreach ($rows as $row) {
            yield $this->exception(
                self::EVENT_TRAIL_GAP,
                'medium',
                (int) $row->umkm_store_id,
                'umkm_disbursement',
                (int) $row->id,
                (string) $row->status,
                'Disbursement tidak memiliki event transisi wajib untuk status saat ini.',
                $detectedAt,
                $row->paid_at ?? $row->transfer_failure_verified_at ?? $row->requested_at ?? $row->created_at,
                $reason,
                [
                    'reason' => $reason,
                    'expected_event_type' => $eventType,
                    'expected_to_status' => $status,
                    'event_foundation_at' => self::EVENT_TRAIL_FOUNDATION_AT,
                ],
            );
        }
    }

    /** @return LazyCollection<int, object> */
    private function disbursementAllocationRows(array $statuses, ?int $storeId): LazyCollection
    {
        return UmkmDisbursement::query()
            ->leftJoin('umkm_disbursement_allocations as allocations', 'allocations.umkm_disbursement_id', '=', 'umkm_disbursements.id')
            ->leftJoin('umkm_entitlements as entitlements', 'entitlements.id', '=', 'allocations.umkm_entitlement_id')
            ->select([
                'umkm_disbursements.id as disbursement_id',
                'umkm_disbursements.umkm_store_id',
                'umkm_disbursements.status as disbursement_status',
                'umkm_disbursements.requested_amount',
                'umkm_disbursements.requested_at',
                'umkm_disbursements.active_request_lock',
                'allocations.id as allocation_id',
                'allocations.amount as allocation_amount',
                'allocations.entitlement_reservation_lock',
                'entitlements.id as entitlement_id',
                'entitlements.umkm_store_id as entitlement_store_id',
                'entitlements.status as entitlement_status',
                'entitlements.source_umkm_amount',
            ])
            ->whereIn('umkm_disbursements.status', $statuses)
            ->when($storeId !== null, fn ($query) => $query->where('umkm_disbursements.umkm_store_id', $storeId))
            ->orderBy('umkm_disbursements.id')
            ->orderBy('allocations.id')
            ->cursor();
    }

    /** @param array<string, mixed> $filters @return list<string> */
    private function categories(array $filters): array
    {
        $requested = $filters['categories'] ?? $filters['category'] ?? self::CATEGORIES;
        $categories = is_string($requested) ? [$requested] : $requested;

        if (! is_array($categories) || $categories === []) {
            throw new InvalidArgumentException('Filter kategori tidak valid.');
        }

        $categories = array_values(array_unique($categories));
        foreach ($categories as $category) {
            if (! is_string($category) || ! in_array($category, self::CATEGORIES, true)) {
                throw new InvalidArgumentException('Kategori financial exception tidak valid.');
            }
        }

        return array_values(array_filter(self::CATEGORIES, fn (string $category) => in_array($category, $categories, true)));
    }

    private function storeId(mixed $storeId): ?int
    {
        if ($storeId === null || $storeId === '') {
            return null;
        }

        if (filter_var($storeId, FILTER_VALIDATE_INT) === false || (int) $storeId < 1) {
            throw new InvalidArgumentException('Filter umkm_store_id tidak valid.');
        }

        return (int) $storeId;
    }

    /** @param array<string, mixed> $entitlementAmounts @param array<string, mixed> $storeAmounts @return array<string, mixed> */
    private function snapshotException(object $row, string $detectedAt, string $reason, array $entitlementAmounts, array $storeAmounts): array
    {
        return $this->exception(
            self::ENT_SNAPSHOT_MISMATCH,
            'critical',
            (int) $row->umkm_store_id,
            'umkm_entitlement',
            (int) $row->entitlement_id,
            (string) $row->entitlement_status,
            'Snapshot calculated entitlement tidak konsisten dengan snapshot StoreOrder.',
            $detectedAt,
            $row->accrued_at,
            $reason,
            [
                'reason' => $reason,
                'commission_status_snapshot' => (string) $row->commission_status_snapshot,
                'store_commission_status' => $row->store_commission_status,
                'entitlement_amounts' => $this->safeDecimals($entitlementAmounts),
                'store_order_amounts' => $this->safeDecimals($storeAmounts),
            ],
        );
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function exception(
        string $code,
        string $severity,
        int $storeId,
        string $referenceType,
        int $referenceId,
        string $currentStatus,
        string $description,
        string $detectedAt,
        mixed $sourceTimestamp,
        string $reason,
        array $context,
    ): array {
        return [
            'code' => $code,
            'severity' => $severity,
            'umkm_store_id' => $storeId,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'current_status' => $currentStatus,
            'description' => $description,
            'detected_at' => $detectedAt,
            'source_timestamp' => $sourceTimestamp === null ? null : (string) $sourceTimestamp,
            'stable_exception_key' => hash('sha256', implode('|', ['v1', $code, $referenceType, $referenceId, $reason])),
            'context' => $context,
        ];
    }

    /** Compare fixed-scale DECIMAL values without float conversion. */
    private function sameDecimal(mixed $left, mixed $right): bool
    {
        return $left !== null
            && $right !== null
            && $this->normaliseDecimal((string) $left) === $this->normaliseDecimal((string) $right);
    }

    /** @param array<string, mixed> $amounts @return array<string, string|null> */
    private function safeDecimals(array $amounts): array
    {
        return array_map(fn ($amount) => $amount === null ? null : $this->normaliseDecimal((string) $amount), $amounts);
    }

    /** Return fixed-scale minor units, or null for an invalid DECIMAL string. */
    private function minorUnits(mixed $amount): ?int
    {
        // SQLite may return DECIMAL query values as float. This only normalises
        // that database-driver boundary; all subsequent arithmetic is integer
        // minor-unit arithmetic and production MySQL DECIMAL strings are kept.
        if (is_float($amount)) {
            $amount = sprintf('%.2F', $amount);
        } elseif (is_int($amount)) {
            $amount = (string) $amount;
        }

        if (! is_string($amount) || ! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            return null;
        }

        $minor = ((int) $matches[2] * 100) + (int) str_pad($matches[3] ?? '', 2, '0');

        return $matches[1] === '-' ? -$minor : $minor;
    }

    private function fromMinorUnits(int $amount): string
    {
        return sprintf('%s%d.%02d', $amount < 0 ? '-' : '', intdiv(abs($amount), 100), abs($amount) % 100);
    }

    private function normaliseDecimal(string $amount): string
    {
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $amount, $matches)) {
            return $amount;
        }

        $integer = ltrim($matches[2], '0') ?: '0';
        $fraction = rtrim($matches[3] ?? '', '0');

        return ($matches[1] === '-' && $integer !== '0' ? '-' : '').$integer.($fraction === '' ? '' : '.'.$fraction);
    }
}
