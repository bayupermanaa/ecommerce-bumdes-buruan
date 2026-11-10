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

class FinancialExceptionDetectorBatchDTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_trail_gap_reports_each_required_transition_and_ignores_valid_trails(): void
    {
        $detector = app(FinancialExceptionDetector::class);
        $store = $this->makeStore('Toko Event Trail');
        $actor = User::factory()->create(['role' => 'admin_bumdes']);

        $settledMissing = $this->makeEntitlement($store, 'settled');
        $reservedMissing = $this->makeEntitlement($store, 'reserved');
        $paidMissing = $this->makeDisbursement($store, 'paid');
        $failedMissing = $this->makeDisbursement($store, 'transfer_failed');
        $rejectedMissing = $this->makeDisbursement($store, 'rejected');
        $cancelledMissing = $this->makeDisbursement($store, 'cancelled');

        $validSettled = $this->makeEntitlement($store, 'settled');
        $this->entitlementEvent($validSettled, $actor, 'settled', 'reserved', 'settled');
        $validReserved = $this->makeEntitlement($store, 'reserved');
        $this->entitlementEvent($validReserved, $actor, 'reserved', 'available', 'reserved');
        $validPaid = $this->makeDisbursement($store, 'paid');
        $this->disbursementEvent($validPaid, $actor, 'transfer_verified', 'approved', 'paid');
        $this->disbursementEvent($validPaid, $actor, 'paid', 'approved', 'paid');
        // A legitimate idempotent retry may leave an equivalent audit event.
        $this->disbursementEvent($validPaid, $actor, 'paid', 'approved', 'paid');
        $validFailed = $this->makeDisbursement($store, 'transfer_failed');
        $this->disbursementEvent($validFailed, $actor, 'transfer_failed', 'approved', 'transfer_failed');
        $validRejected = $this->makeDisbursement($store, 'rejected');
        $this->disbursementEvent($validRejected, $actor, 'rejected', 'under_review', 'rejected');
        $validCancelled = $this->makeDisbursement($store, 'cancelled');
        $this->disbursementEvent($validCancelled, $actor, 'cancelled', 'requested', 'cancelled');

        $exceptions = $detector->scan(['category' => FinancialExceptionDetector::EVENT_TRAIL_GAP])->all();
        $byReference = collect($exceptions)->groupBy(fn (array $exception): string => $this->referenceKey($exception['reference_type'], $exception['reference_id']));

        $this->assertContains('settled_event_missing', $byReference[$this->referenceKey('umkm_entitlement', $settledMissing->id)]->pluck('context.reason')->all());
        $this->assertContains('reserved_event_missing', $byReference[$this->referenceKey('umkm_entitlement', $reservedMissing->id)]->pluck('context.reason')->all());
        $this->assertContains('paid_transfer_verified_event_missing', $byReference[$this->referenceKey('umkm_disbursement', $paidMissing->id)]->pluck('context.reason')->all());
        $this->assertContains('paid_event_missing', $byReference[$this->referenceKey('umkm_disbursement', $paidMissing->id)]->pluck('context.reason')->all());
        $this->assertContains('transfer_failed_event_missing', $byReference[$this->referenceKey('umkm_disbursement', $failedMissing->id)]->pluck('context.reason')->all());
        $this->assertContains('rejected_event_missing', $byReference[$this->referenceKey('umkm_disbursement', $rejectedMissing->id)]->pluck('context.reason')->all());
        $this->assertContains('cancelled_event_missing', $byReference[$this->referenceKey('umkm_disbursement', $cancelledMissing->id)]->pluck('context.reason')->all());
        foreach ([["umkm_entitlement", $validSettled], ["umkm_entitlement", $validReserved], ["umkm_disbursement", $validPaid], ["umkm_disbursement", $validFailed], ["umkm_disbursement", $validRejected], ["umkm_disbursement", $validCancelled]] as [$type, $valid]) {
            $this->assertArrayNotHasKey($this->referenceKey($type, $valid->id), $byReference->all());
        }
    }

    public function test_event_trail_cutoff_and_historical_records_avoid_false_positives(): void
    {
        $detector = app(FinancialExceptionDetector::class);
        $store = $this->makeStore('Toko Cutoff Event');
        $actor = User::factory()->create(['role' => 'admin_bumdes']);

        $beforeCutoff = $this->makeDisbursement($store, 'paid', '2026-10-26 23:59:59');
        $atCutoff = $this->makeDisbursement($store, 'paid', '2026-10-27 00:00:00');
        $optionalHistoricalEvent = $this->makeEntitlement($store, 'available', '150000.50', '2026-10-28 12:00:00');
        $this->entitlementEvent($optionalHistoricalEvent, $actor, 'accrued', null, 'available');

        $historicalAllocationEntitlement = $this->makeEntitlement($store, 'available');
        $historicalFailed = $this->makeDisbursement($store, 'transfer_failed');
        $this->allocate($historicalFailed, $historicalAllocationEntitlement, '150000.50', null);
        $this->disbursementEvent($historicalFailed, $actor, 'transfer_failed', 'approved', 'transfer_failed');

        $this->assertSame('2026-10-26 23:59:59', $beforeCutoff->fresh()->getRawOriginal('created_at'));
        $this->assertSame('2026-10-27 00:00:00', $atCutoff->fresh()->getRawOriginal('created_at'));

        $exceptions = $detector->scan(['category' => FinancialExceptionDetector::EVENT_TRAIL_GAP])->all();
        $disbursementReferences = collect($exceptions)
            ->where('reference_type', 'umkm_disbursement')
            ->pluck('reference_id')
            ->all();
        $entitlementReferences = collect($exceptions)
            ->where('reference_type', 'umkm_entitlement')
            ->pluck('reference_id')
            ->all();

        $this->assertNotContains($beforeCutoff->id, $disbursementReferences);
        $this->assertContains($atCutoff->id, $disbursementReferences);
        $this->assertNotContains($optionalHistoricalEvent->id, $entitlementReferences);
        $this->assertNotContains($historicalFailed->id, $disbursementReferences);
    }

    public function test_event_trail_filters_keys_chunking_read_only_and_privacy_contract(): void
    {
        $detector = app(FinancialExceptionDetector::class);
        $store = $this->makeStore('Toko Filter Event');
        $otherStore = $this->makeStore('Toko Lain Event');
        $paid = $this->makeDisbursement($store, 'paid');
        $failed = $this->makeDisbursement($store, 'transfer_failed');
        $otherPaid = $this->makeDisbursement($otherStore, 'paid');
        $entitlement = $this->makeEntitlement($store, 'available', '123456.70');
        $this->allocate($failed, $entitlement, '123456.70', null);

        $before = [
            'disbursements' => UmkmDisbursement::query()->get(['id', 'status', 'requested_amount', 'active_request_lock'])->map->getAttributes()->all(),
            'entitlements' => UmkmEntitlement::query()->get(['id', 'status', 'source_umkm_amount'])->map->getAttributes()->all(),
            'allocations' => UmkmDisbursementAllocation::query()->get(['id', 'amount', 'entitlement_reservation_lock'])->map->getAttributes()->all(),
            'disbursement_events' => UmkmDisbursementEvent::query()->count(),
            'entitlement_events' => UmkmEntitlementEvent::query()->count(),
        ];

        $scan = $detector->scan([
            'categories' => [FinancialExceptionDetector::EVENT_TRAIL_GAP],
            'umkm_store_id' => $store->id,
        ]);
        $first = $scan->values()->all();
        $second = $detector->scan([
            'category' => FinancialExceptionDetector::EVENT_TRAIL_GAP,
            'umkm_store_id' => $store->id,
        ])->values()->all();
        $chunks = [];
        $detector->chunk([
            'category' => FinancialExceptionDetector::EVENT_TRAIL_GAP,
            'umkm_store_id' => $store->id,
        ], 1, function (Collection $exceptions) use (&$chunks): void {
            $chunks[] = $exceptions->all();
        });

        $this->assertInstanceOf(LazyCollection::class, $detector->scan());
        $this->assertCount(3, $first);
        $this->assertSame([$paid->id, $paid->id, $failed->id], array_column($first, 'reference_id'));
        $this->assertSame(array_column($first, 'stable_exception_key'), array_column($second, 'stable_exception_key'));
        $this->assertCount(3, array_unique(array_column($first, 'stable_exception_key')));
        $this->assertCount(3, $chunks);
        $this->assertTrue(collect($chunks)->every(fn (array $chunk): bool => count($chunk) <= 1));
        $this->assertNotContains($otherPaid->id, array_column($first, 'reference_id'));
        $this->assertSame('123456.70', UmkmEntitlement::query()->findOrFail($entitlement->id)->source_umkm_amount);
        $this->assertSame($before['disbursements'], UmkmDisbursement::query()->get(['id', 'status', 'requested_amount', 'active_request_lock'])->map->getAttributes()->all());
        $this->assertSame($before['entitlements'], UmkmEntitlement::query()->get(['id', 'status', 'source_umkm_amount'])->map->getAttributes()->all());
        $this->assertSame($before['allocations'], UmkmDisbursementAllocation::query()->get(['id', 'amount', 'entitlement_reservation_lock'])->map->getAttributes()->all());
        $this->assertSame($before['disbursement_events'], UmkmDisbursementEvent::query()->count());
        $this->assertSame($before['entitlement_events'], UmkmEntitlementEvent::query()->count());

        $context = json_encode($first, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('00123456789', $context);
        $this->assertStringNotContainsString('Pemilik Rekening Rahasia', $context);
        $this->assertStringNotContainsString('REF-PRIVATE-SECRET', $context);
        $this->assertStringNotContainsString('private-proof-secret', $context);
        $this->assertStringNotContainsString('event-metadata-secret', $context);
    }

    private function makeStore(string $name): UmkmStore
    {
        $owner = User::factory()->create(['role' => 'penjual']);

        return $owner->umkmStore()->create(['name' => $name, 'slug' => 'batch-d-'.Str::lower(Str::random(10))]);
    }

    private function makeDisbursement(UmkmStore $store, string $status, ?string $createdAt = null): UmkmDisbursement
    {
        $account = $store->recipientBankAccounts()->firstOrCreate(['active_store_lock' => 'store:'.$store->id], [
            'bank_name' => 'Bank Test', 'account_holder_name' => 'Pemilik Rekening Rahasia', 'account_number' => '00123456789',
            'status' => 'active', 'verified_at' => now(), 'created_by' => $store->user_id, 'updated_by' => $store->user_id,
        ]);

        $disbursement = UmkmDisbursement::create([
            'umkm_store_id' => $store->id,
            'recipient_bank_account_id' => $account->id,
            'recipient_bank_name' => $account->bank_name,
            'recipient_account_holder_name' => $account->account_holder_name,
            'recipient_account_number' => $account->account_number,
            'requested_amount' => '150000.50',
            'status' => $status,
            'requested_by' => $store->user_id,
            'requested_at' => $createdAt ?? now(),
            'paid_at' => $status === 'paid' ? ($createdAt ?? now()) : null,
            'transfer_failure_verified_at' => $status === 'transfer_failed' ? ($createdAt ?? now()) : null,
            'idempotency_key' => (string) Str::uuid(),
            'created_at' => $createdAt ?? now(),
            'updated_at' => $createdAt ?? now(),
        ]);

        if ($createdAt !== null) {
            UmkmDisbursement::query()->whereKey($disbursement->id)->update([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            return $disbursement->fresh();
        }

        return $disbursement;
    }

    private function makeEntitlement(UmkmStore $store, string $status, string $amount = '150000.50', ?string $createdAt = null): UmkmEntitlement
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $order = Order::create([
            'order_number' => 'ORD-D-'.Str::upper(Str::random(10)), 'checkout_token' => (string) Str::uuid(), 'user_id' => $customer->id,
            'fulfillment_method' => 'pickup', 'recipient_name' => 'Pelanggan Test', 'recipient_phone' => '081234567890',
            'subtotal' => $amount, 'shipping_total' => '0.00', 'grand_total' => $amount, 'payment_status' => 'paid', 'order_status' => 'completed',
        ]);
        $storeOrder = StoreOrder::create([
            'order_id' => $order->id, 'umkm_store_id' => $store->id, 'store_order_number' => 'SO-D-'.Str::upper(Str::random(10)),
            'store_name_snapshot' => $store->name, 'subtotal' => $amount, 'shipping_amount' => '0.00', 'total' => $amount,
            'fulfillment_status' => 'completed', 'commission_percentage' => '0.00', 'commission_amount' => '0.00', 'umkm_amount' => $amount, 'commission_status' => 'calculated',
        ]);
        $payment = Payment::create(['order_id' => $order->id, 'payment_number' => 'PAY-D-'.Str::upper(Str::random(10)), 'payment_method' => 'cash_pickup', 'amount' => $amount, 'status' => 'paid']);

        $entitlement = UmkmEntitlement::create([
            'umkm_store_id' => $store->id, 'order_id' => $order->id, 'store_order_id' => $storeOrder->id, 'payment_id' => $payment->id,
            'source_umkm_amount' => $amount, 'source_commission_amount' => '0.00', 'source_commission_percentage' => '0.00',
            'commission_status_snapshot' => 'calculated', 'status' => $status, 'accrued_at' => $createdAt ?? now(),
            'settled_at' => $status === 'settled' ? ($createdAt ?? now()) : null, 'created_at' => $createdAt ?? now(), 'updated_at' => $createdAt ?? now(),
        ]);

        if ($createdAt !== null) {
            UmkmEntitlement::query()->whereKey($entitlement->id)->update([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            return $entitlement->fresh();
        }

        return $entitlement;
    }

    private function entitlementEvent(UmkmEntitlement $entitlement, User $actor, string $type, ?string $from, string $to): void
    {
        UmkmEntitlementEvent::create([
            'umkm_entitlement_id' => $entitlement->id, 'actor_id' => $actor->id, 'event_type' => $type, 'amount' => $entitlement->source_umkm_amount,
            'from_status' => $from, 'to_status' => $to, 'metadata' => ['private' => 'event-metadata-secret'], 'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function disbursementEvent(UmkmDisbursement $disbursement, User $actor, string $type, ?string $from, string $to): void
    {
        UmkmDisbursementEvent::create([
            'umkm_disbursement_id' => $disbursement->id, 'actor_id' => $actor->id, 'event_type' => $type,
            'from_status' => $from, 'to_status' => $to, 'metadata' => ['private' => 'event-metadata-secret'], 'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function allocate(UmkmDisbursement $disbursement, UmkmEntitlement $entitlement, string $amount, ?string $lock): void
    {
        UmkmDisbursementAllocation::create([
            'umkm_disbursement_id' => $disbursement->id, 'umkm_entitlement_id' => $entitlement->id,
            'amount' => $amount, 'entitlement_reservation_lock' => $lock,
        ]);
    }

    private function referenceKey(string $type, int $id): string
    {
        return $type.':'.$id;
    }
}
