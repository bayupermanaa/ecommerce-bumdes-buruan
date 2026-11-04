<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreOrder;
use App\Models\UmkmDisbursement;
use App\Models\UmkmDisbursementAllocation;
use App\Models\UmkmDisbursementEvent;
use App\Models\UmkmEntitlement;
use App\Models\UmkmEntitlementEvent;
use App\Models\UmkmStore;
use App\Models\User;
use App\Services\FinancialAudit\FinancialExceptionDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialExceptionDetectorBatchBTest extends TestCase
{
    use RefreshDatabase;

    public function test_reserved_entitlement_detector_distinguishes_valid_and_invalid_active_allocations(): void
    {
        $detector = app(FinancialExceptionDetector::class);
        $storeA = $this->makeStore('Toko Reservasi A');
        $storeB = $this->makeStore('Toko Reservasi B');

        $valid = $this->makeEntitlement($storeA, 'reserved');
        $validDisbursement = $this->makeDisbursement($storeA, 'requested', '150000.50');
        $this->allocate($validDisbursement, $valid, '150000.50', 'entitlement:'.$valid->id);

        $withoutAllocation = $this->makeEntitlement($storeA, 'reserved');
        $historical = $this->makeEntitlement($storeA, 'reserved');
        $historicalDisbursement = $this->makeDisbursement($storeA, 'cancelled', '150000.50');
        $this->allocate($historicalDisbursement, $historical, '150000.50', null);

        $terminal = $this->makeEntitlement($storeA, 'reserved');
        $terminalDisbursement = $this->makeDisbursement($storeA, 'cancelled', '150000.50');
        $this->allocate($terminalDisbursement, $terminal, '150000.50', 'entitlement:'.$terminal->id);

        $foreignStore = $this->makeEntitlement($storeB, 'reserved');
        $foreignDisbursement = $this->makeDisbursement($storeA, 'requested', '150000.50', 'store:foreign');
        $this->allocate($foreignDisbursement, $foreignStore, '150000.50', 'entitlement:'.$foreignStore->id);

        $wrongRequestLock = $this->makeEntitlement($storeA, 'reserved');
        $wrongRequestDisbursement = $this->makeDisbursement($storeA, 'approved', '150000.50', 'store:999');
        $this->allocate($wrongRequestDisbursement, $wrongRequestLock, '150000.50', 'entitlement:'.$wrongRequestLock->id);

        $wrongReservationLock = $this->makeEntitlement($storeA, 'reserved');
        $wrongReservationDisbursement = $this->makeDisbursement($storeA, 'under_review', '150000.50', 'store:wrong-reservation');
        $this->allocate($wrongReservationDisbursement, $wrongReservationLock, '150000.50', 'entitlement:999999');

        $exceptions = $detector->scan(['category' => FinancialExceptionDetector::ENT_RESERVED_NO_ACTIVE_ALLOCATION])->all();
        $byReference = collect($exceptions)->groupBy('reference_id');
        $reasons = collect($exceptions)->pluck('context.reason')->all();

        $this->assertArrayNotHasKey($valid->id, $byReference->all());
        $this->assertContains($withoutAllocation->id, $byReference->keys()->all());
        $this->assertContains($historical->id, $byReference->keys()->all());
        $this->assertContains('reserved_without_active_allocation', $reasons);
        $this->assertContains('allocation_not_on_active_disbursement', $reasons);
        $this->assertContains('allocation_disbursement_store_mismatch', $reasons);
        $this->assertContains('active_request_lock_mismatch', $reasons);
        $this->assertContains('entitlement_reservation_lock_mismatch', $reasons);
    }

    public function test_active_disbursement_detector_validates_locks_allocations_totals_and_terminal_scope(): void
    {
        $detector = app(FinancialExceptionDetector::class);
        $storeA = $this->makeStore('Toko Aktif A');
        $storeB = $this->makeStore('Toko Aktif B');

        $validFirst = $this->makeEntitlement($storeA, 'reserved', '150000.50');
        $validSecond = $this->makeEntitlement($storeA, 'reserved', '1.25');
        $valid = $this->makeDisbursement($storeA, 'requested', '150001.75');
        $this->allocate($valid, $validFirst, '150000.50', 'entitlement:'.$validFirst->id);
        $this->allocate($valid, $validSecond, '1.25', 'entitlement:'.$validSecond->id);

        $wrongActiveLock = $this->makeDisbursement($storeA, 'approved', '150000.50', null);
        $wrongActiveLockEntitlement = $this->makeEntitlement($storeA, 'reserved');
        $this->allocate($wrongActiveLock, $wrongActiveLockEntitlement, '150000.50', 'entitlement:'.$wrongActiveLockEntitlement->id);

        $withoutAllocation = $this->makeDisbursement($storeA, 'under_review', '150000.50', null);
        $totalMismatch = $this->makeDisbursement($storeA, 'requested', '150000.50', 'store:total');
        $totalMismatchEntitlement = $this->makeEntitlement($storeA, 'reserved');
        $this->allocate($totalMismatch, $totalMismatchEntitlement, '100000.00', 'entitlement:'.$totalMismatchEntitlement->id);

        $snapshotMismatch = $this->makeDisbursement($storeA, 'approved', '150000.50', 'store:snapshot');
        $snapshotMismatchEntitlement = $this->makeEntitlement($storeA, 'reserved');
        $this->allocate($snapshotMismatch, $snapshotMismatchEntitlement, '150000.49', 'entitlement:'.$snapshotMismatchEntitlement->id);

        $notReserved = $this->makeDisbursement($storeA, 'requested', '150000.50', 'store:not-reserved');
        $notReservedEntitlement = $this->makeEntitlement($storeA, 'available');
        $this->allocate($notReserved, $notReservedEntitlement, '150000.50', 'entitlement:'.$notReservedEntitlement->id);

        $foreignStore = $this->makeDisbursement($storeA, 'under_review', '150000.50', 'store:foreign');
        $foreignEntitlement = $this->makeEntitlement($storeB, 'reserved');
        $this->allocate($foreignStore, $foreignEntitlement, '150000.50', 'entitlement:'.$foreignEntitlement->id);

        $wrongReservation = $this->makeDisbursement($storeA, 'approved', '150000.50', 'store:wrong-reservation');
        $wrongReservationEntitlement = $this->makeEntitlement($storeA, 'reserved');
        $this->allocate($wrongReservation, $wrongReservationEntitlement, '150000.50', null);

        $transferFailed = $this->makeDisbursement($storeA, 'transfer_failed', '150000.50', null);
        $transferFailedEntitlement = $this->makeEntitlement($storeA, 'reserved');
        $this->allocate($transferFailed, $transferFailedEntitlement, '150000.50', 'entitlement:'.$transferFailedEntitlement->id);

        $exceptions = $detector->scan(['category' => FinancialExceptionDetector::DISB_ACTIVE_ALLOCATION_MISMATCH])->all();
        $references = collect($exceptions)->pluck('reference_id')->all();
        $reasons = collect($exceptions)->pluck('context.reason')->all();

        $this->assertNotContains($valid->id, $references);
        $this->assertNotContains($transferFailed->id, $references);
        $this->assertContains($wrongActiveLock->id, $references);
        $this->assertContains($withoutAllocation->id, $references);
        $this->assertContains('active_request_lock_mismatch', $reasons);
        $this->assertContains('active_disbursement_without_allocation', $reasons);
        $this->assertContains('allocation_total_mismatch', $reasons);
        $this->assertContains('allocation_amount_snapshot_mismatch', $reasons);
        $this->assertContains('allocation_entitlement_not_reserved', $reasons);
        $this->assertContains('allocation_entitlement_store_mismatch', $reasons);
        $this->assertContains('entitlement_reservation_lock_mismatch', $reasons);
    }

    public function test_terminal_detector_ignores_released_historical_allocations_and_flags_remaining_locks(): void
    {
        $detector = app(FinancialExceptionDetector::class);
        $store = $this->makeStore('Toko Terminal');

        foreach ([['paid', 'settled'], ['rejected', 'available'], ['cancelled', 'available'], ['transfer_failed', 'available']] as [$status, $entitlementStatus]) {
            $entitlement = $this->makeEntitlement($store, $entitlementStatus);
            $disbursement = $this->makeDisbursement($store, $status, '150000.50', null);
            $this->allocate($disbursement, $entitlement, '150000.50', null);
        }

        $activeLockRemains = $this->makeDisbursement($store, 'cancelled', '150000.50', 'stale:'.$store->id);
        $lockedEntitlement = $this->makeEntitlement($store, 'reserved');
        $lockedPaid = $this->makeDisbursement($store, 'paid', '150000.50', null);
        $this->allocate($lockedPaid, $lockedEntitlement, '150000.50', 'entitlement:'.$lockedEntitlement->id);

        $reused = $this->makeEntitlement($store, 'reserved');
        $oldCancelled = $this->makeDisbursement($store, 'cancelled', '150000.50', null);
        $this->allocate($oldCancelled, $reused, '150000.50', null);
        $newActive = $this->makeDisbursement($store, 'requested', '150000.50');
        $this->allocate($newActive, $reused, '150000.50', 'entitlement:'.$reused->id);

        $exceptions = $detector->scan(['category' => FinancialExceptionDetector::DISB_TERMINAL_LOCK_REMAINS])->all();
        $references = collect($exceptions)->pluck('reference_id')->all();
        $reasons = collect($exceptions)->pluck('context.reason')->all();

        $this->assertContains($activeLockRemains->id, $references);
        $this->assertContains($lockedPaid->id, $references);
        $this->assertNotContains($oldCancelled->id, $references);
        $this->assertContains('terminal_active_request_lock_remains', $reasons);
        $this->assertContains('terminal_allocation_reservation_lock_remains', $reasons);
        $this->assertContains('terminal_locked_entitlement_status_mismatch', $reasons);
    }

    public function test_duplicate_active_filter_chunking_stable_keys_and_read_only_contract(): void
    {
        $detector = app(FinancialExceptionDetector::class);
        $duplicateStore = $this->makeStore('Toko Duplikat');
        $otherStore = $this->makeStore('Toko Tunggal');

        $this->makeDisbursement($duplicateStore, 'requested', '10.00', null);
        $this->makeDisbursement($duplicateStore, 'approved', '20.00', null);
        $this->makeDisbursement($duplicateStore, 'cancelled', '30.00', null);
        $this->makeDisbursement($duplicateStore, 'transfer_failed', '40.00', null);
        $this->makeDisbursement($otherStore, 'requested', '10.00');

        $before = [
            'entitlements' => UmkmEntitlement::query()->get(['id', 'status'])->map->getAttributes()->all(),
            'disbursements' => UmkmDisbursement::query()->get(['id', 'status', 'active_request_lock'])->map->getAttributes()->all(),
            'allocations' => UmkmDisbursementAllocation::query()->get(['id', 'entitlement_reservation_lock'])->map->getAttributes()->all(),
            'entitlement_events' => UmkmEntitlementEvent::query()->count(),
            'disbursement_events' => UmkmDisbursementEvent::query()->count(),
        ];

        $scan = $detector->scan([
            'categories' => [FinancialExceptionDetector::DISB_DUPLICATE_ACTIVE, FinancialExceptionDetector::DISB_TERMINAL_LOCK_REMAINS],
            'umkm_store_id' => $duplicateStore->id,
        ]);
        $first = $scan->values()->all();
        $second = $detector->scan([
            'categories' => [FinancialExceptionDetector::DISB_DUPLICATE_ACTIVE, FinancialExceptionDetector::DISB_TERMINAL_LOCK_REMAINS],
            'umkm_store_id' => $duplicateStore->id,
        ])->values()->all();

        $chunks = [];
        $detector->chunk([
            'category' => FinancialExceptionDetector::DISB_DUPLICATE_ACTIVE,
            'umkm_store_id' => $duplicateStore->id,
        ], 1, function (Collection $exceptions) use (&$chunks): void {
            $chunks[] = $exceptions->all();
        });

        $this->assertInstanceOf(LazyCollection::class, $detector->scan());
        $this->assertCount(1, $first);
        $this->assertSame(FinancialExceptionDetector::DISB_DUPLICATE_ACTIVE, $first[0]['code']);
        $this->assertSame($duplicateStore->id, $first[0]['reference_id']);
        $this->assertSame($first[0]['stable_exception_key'], $second[0]['stable_exception_key']);
        $this->assertCount(1, array_unique(array_column($first, 'stable_exception_key')));
        $this->assertSame([$first[0]['stable_exception_key']], array_column($chunks[0], 'stable_exception_key'));
        $this->assertLessThanOrEqual(1, count($chunks[0]));
        $this->assertSame($before['entitlements'], UmkmEntitlement::query()->get(['id', 'status'])->map->getAttributes()->all());
        $this->assertSame($before['disbursements'], UmkmDisbursement::query()->get(['id', 'status', 'active_request_lock'])->map->getAttributes()->all());
        $this->assertSame($before['allocations'], UmkmDisbursementAllocation::query()->get(['id', 'entitlement_reservation_lock'])->map->getAttributes()->all());
        $this->assertSame($before['entitlement_events'], UmkmEntitlementEvent::query()->count());
        $this->assertSame($before['disbursement_events'], UmkmDisbursementEvent::query()->count());
        $this->assertStringNotContainsString('account_number', json_encode($first[0]['context'], JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('proof_path', json_encode($first[0]['context'], JSON_THROW_ON_ERROR));
    }

    private function makeStore(string $name): UmkmStore
    {
        $owner = User::factory()->create(['role' => 'pemilik_umkm']);

        return $owner->umkmStore()->create([
            'name' => $name,
            'slug' => 'batch-b-'.Str::lower(Str::random(10)),
        ]);
    }

    private function makeEntitlement(UmkmStore $store, string $status, string $amount = '150000.50'): UmkmEntitlement
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $order = Order::create([
            'order_number' => 'ORD-B-'.Str::upper(Str::random(10)),
            'checkout_token' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'fulfillment_method' => 'pickup',
            'recipient_name' => 'Pelanggan Test',
            'recipient_phone' => '081234567890',
            'subtotal' => $amount,
            'shipping_total' => '0.00',
            'grand_total' => $amount,
            'payment_status' => 'paid',
            'order_status' => 'completed',
        ]);
        $storeOrder = StoreOrder::create([
            'order_id' => $order->id,
            'umkm_store_id' => $store->id,
            'store_order_number' => 'SO-B-'.Str::upper(Str::random(10)),
            'store_name_snapshot' => $store->name,
            'subtotal' => $amount,
            'shipping_amount' => '0.00',
            'total' => $amount,
            'fulfillment_status' => 'completed',
            'commission_percentage' => '0.00',
            'commission_amount' => '0.00',
            'umkm_amount' => $amount,
            'commission_status' => 'calculated',
        ]);
        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_number' => 'PAY-B-'.Str::upper(Str::random(10)),
            'payment_method' => 'cash_pickup',
            'amount' => $amount,
            'status' => 'paid',
        ]);

        return UmkmEntitlement::create([
            'umkm_store_id' => $store->id,
            'order_id' => $order->id,
            'store_order_id' => $storeOrder->id,
            'payment_id' => $payment->id,
            'source_umkm_amount' => $amount,
            'source_commission_amount' => '0.00',
            'source_commission_percentage' => '0.00',
            'commission_status_snapshot' => 'calculated',
            'status' => $status,
            'accrued_at' => now(),
        ]);
    }

    private function makeDisbursement(UmkmStore $store, string $status, string $amount, ?string $activeRequestLock = '__auto__'): UmkmDisbursement
    {
        $account = $store->recipientBankAccounts()->first();
        if (! $account) {
            $account = $store->recipientBankAccounts()->create([
                'bank_name' => 'Bank Test',
                'account_holder_name' => 'Pemilik Test',
                'account_number' => '001'.Str::random(8),
                'status' => 'active',
                'active_store_lock' => 'store:'.$store->id,
                'verified_at' => now(),
                'created_by' => $store->user_id,
                'updated_by' => $store->user_id,
            ]);
        }

        $active = in_array($status, ['requested', 'under_review', 'approved'], true);
        $lock = $activeRequestLock === '__auto__' ? ($active ? 'store:'.$store->id : null) : $activeRequestLock;

        return UmkmDisbursement::create([
            'umkm_store_id' => $store->id,
            'recipient_bank_account_id' => $account->id,
            'recipient_bank_name' => $account->bank_name,
            'recipient_account_holder_name' => $account->account_holder_name,
            'recipient_account_number' => $account->account_number,
            'requested_amount' => $amount,
            'status' => $status,
            'active_request_lock' => $lock,
            'requested_by' => $store->user_id,
            'requested_at' => now(),
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function allocate(UmkmDisbursement $disbursement, UmkmEntitlement $entitlement, string $amount, ?string $lock): UmkmDisbursementAllocation
    {
        return UmkmDisbursementAllocation::create([
            'umkm_disbursement_id' => $disbursement->id,
            'umkm_entitlement_id' => $entitlement->id,
            'amount' => $amount,
            'entitlement_reservation_lock' => $lock,
        ]);
    }
}
