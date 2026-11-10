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

class FinancialExceptionDetectorBatchCTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_detector_requires_complete_transfer_metadata_and_keeps_it_private(): void
    {
        $detector = app(FinancialExceptionDetector::class);
        $store = $this->makeStore('Toko Transfer Paid');
        [$recorder, $verifier] = $this->admins();

        $valid = $this->makePaid($store, $recorder, $verifier, '150000.50');
        $missing = [];
        foreach ([
            'transfer_reference' => 'transfer_reference_missing',
            'transfer_proof_path' => 'transfer_proof_missing',
            'transfer_amount' => 'transfer_amount_missing',
            'transfer_recorded_by' => 'transfer_recorder_missing',
            'transfer_recorded_at' => 'transfer_recorded_at_missing',
            'transfer_verified_by' => 'transfer_verifier_missing',
            'transfer_verified_at' => 'transfer_verified_at_missing',
        ] as $column => $reason) {
            $missing[$reason] = $this->makePaid($store, $recorder, $verifier, '150000.50', [$column => null]);
        }
        $sameActor = $this->makePaid($store, $recorder, $recorder);
        $approvedWithMetadata = $this->makeDisbursement($store, 'approved', '150000.50', [
            ...$this->paidMetadata($recorder, $verifier, '150000.50'),
            'active_request_lock' => 'approved:'.$store->id,
        ]);

        $exceptions = $detector->scan(['category' => FinancialExceptionDetector::DISB_PAID_VERIFICATION_MISSING])->all();
        $byReference = collect($exceptions)->groupBy('reference_id');

        $this->assertArrayNotHasKey($valid->id, $byReference->all());
        $this->assertArrayNotHasKey($approvedWithMetadata->id, $byReference->all());
        foreach ($missing as $reason => $disbursement) {
            $this->assertContains($reason, $byReference[$disbursement->id]->pluck('context.reason')->all());
        }
        $this->assertContains('same_transfer_actor', $byReference[$sameActor->id]->pluck('context.reason')->all());

        $context = $byReference[$missing['transfer_reference_missing']->id]->first()['context'];
        $encodedContext = json_encode($context, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('REF-PAID-SECRET', $encodedContext);
        $this->assertStringNotContainsString('paid-proof-secret', $encodedContext);
        $this->assertStringNotContainsString('Pemilik Rekening Rahasia', $encodedContext);
        $this->assertStringNotContainsString('00123456789', $encodedContext);
    }

    public function test_failure_detector_requires_final_metadata_and_released_locks_without_historical_false_positives(): void
    {
        $detector = app(FinancialExceptionDetector::class);
        $store = $this->makeStore('Toko Transfer Gagal');
        [$recorder, $verifier] = $this->admins();

        $valid = $this->makeFailed($store, $recorder, $verifier);
        $missing = [];
        foreach ([
            'transfer_failure_reason' => 'failure_reason_missing',
            'transfer_failure_proof_path' => 'failure_proof_missing',
            'transfer_failure_recorded_by' => 'failure_recorder_missing',
            'transfer_failure_verified_by' => 'failure_verifier_missing',
            'transfer_failure_recorded_at' => 'failure_recorded_at_missing',
            'transfer_failure_verified_at' => 'failure_verified_at_missing',
        ] as $column => $reason) {
            $missing[$reason] = $this->makeFailed($store, $recorder, $verifier, [$column => null]);
        }
        $sameActor = $this->makeFailed($store, $recorder, $recorder);
        $staleRequestLock = $this->makeFailed($store, $recorder, $verifier, ['active_request_lock' => 'stale:'.$store->id]);
        $lockedAllocation = $this->makeFailed($store, $recorder, $verifier);
        $lockedEntitlement = $this->makeEntitlement($store, 'reserved');
        $this->allocate($lockedAllocation, $lockedEntitlement, '150000.50', 'entitlement:'.$lockedEntitlement->id);

        $approvedEvidence = $this->makeDisbursement($this->makeStore('Toko Bukti Awal'), 'approved', '150000.50', [
            ...$this->failureMetadata($recorder, $verifier),
        ]);

        $reusedEntitlement = $this->makeEntitlement($store, 'reserved');
        $historicalFailed = $this->makeFailed($store, $recorder, $verifier);
        $this->allocate($historicalFailed, $reusedEntitlement, '150000.50', null);
        $newRequest = $this->makeDisbursement($store, 'requested', '150000.50');
        $this->allocate($newRequest, $reusedEntitlement, '150000.50', 'entitlement:'.$reusedEntitlement->id);

        $exceptions = $detector->scan(['category' => FinancialExceptionDetector::DISB_FAILURE_RELEASE_MISSING])->all();
        $byReference = collect($exceptions)->groupBy('reference_id');

        $this->assertArrayNotHasKey($valid->id, $byReference->all());
        $this->assertArrayNotHasKey($approvedEvidence->id, $byReference->all());
        $this->assertArrayNotHasKey($historicalFailed->id, $byReference->all());
        foreach ($missing as $reason => $disbursement) {
            $this->assertContains($reason, $byReference[$disbursement->id]->pluck('context.reason')->all());
        }
        $this->assertContains('same_failure_actor', $byReference[$sameActor->id]->pluck('context.reason')->all());
        $this->assertContains('active_request_lock_remains', $byReference[$staleRequestLock->id]->pluck('context.reason')->all());
        $this->assertContains('allocation_reservation_lock_remains', $byReference[$lockedAllocation->id]->pluck('context.reason')->all());
    }

    public function test_batch_c_filters_keys_chunking_decimal_read_only_and_privacy_contract(): void
    {
        $detector = app(FinancialExceptionDetector::class);
        $store = $this->makeStore('Toko Filter Batch C');
        $otherStore = $this->makeStore('Toko Lain Batch C');
        [$recorder, $verifier] = $this->admins();

        $paid = $this->makePaid($store, $recorder, $verifier, '123456.70', ['transfer_reference' => null]);
        $failed = $this->makeFailed($store, $recorder, $verifier, ['transfer_failure_reason' => null]);
        $this->makePaid($otherStore, $recorder, $verifier, '999.99', ['transfer_proof_path' => null]);
        $historicalEntitlement = $this->makeEntitlement($store, 'available', '123456.70');
        $this->allocate($failed, $historicalEntitlement, '123456.70', null);

        $before = [
            'disbursements' => UmkmDisbursement::query()->get(['id', 'status', 'requested_amount', 'active_request_lock'])->map->getAttributes()->all(),
            'allocations' => UmkmDisbursementAllocation::query()->get(['id', 'amount', 'entitlement_reservation_lock'])->map->getAttributes()->all(),
            'entitlements' => UmkmEntitlement::query()->get(['id', 'status', 'source_umkm_amount'])->map->getAttributes()->all(),
            'disbursement_events' => UmkmDisbursementEvent::query()->count(),
            'entitlement_events' => UmkmEntitlementEvent::query()->count(),
        ];

        $scan = $detector->scan([
            'categories' => [FinancialExceptionDetector::DISB_FAILURE_RELEASE_MISSING, FinancialExceptionDetector::DISB_PAID_VERIFICATION_MISSING],
            'umkm_store_id' => $store->id,
        ]);
        $first = $scan->values()->all();
        $second = $detector->scan([
            'category' => FinancialExceptionDetector::DISB_PAID_VERIFICATION_MISSING,
            'umkm_store_id' => $store->id,
        ])->values()->all();
        $chunks = [];
        $detector->chunk([
            'categories' => [FinancialExceptionDetector::DISB_PAID_VERIFICATION_MISSING, FinancialExceptionDetector::DISB_FAILURE_RELEASE_MISSING],
            'umkm_store_id' => $store->id,
        ], 1, function (Collection $exceptions) use (&$chunks): void {
            $chunks[] = $exceptions->all();
        });

        $this->assertInstanceOf(LazyCollection::class, $detector->scan());
        $this->assertCount(2, $first);
        $this->assertSame([FinancialExceptionDetector::DISB_PAID_VERIFICATION_MISSING, FinancialExceptionDetector::DISB_FAILURE_RELEASE_MISSING], array_column($first, 'code'));
        $this->assertSame([$paid->id], array_column($second, 'reference_id'));
        $this->assertSame(array_column($first, 'stable_exception_key'), array_column($detector->scan([
            'categories' => [FinancialExceptionDetector::DISB_FAILURE_RELEASE_MISSING, FinancialExceptionDetector::DISB_PAID_VERIFICATION_MISSING],
            'umkm_store_id' => $store->id,
        ])->values()->all(), 'stable_exception_key'));
        $this->assertCount(2, array_unique(array_column($first, 'stable_exception_key')));
        $this->assertCount(2, $chunks);
        $this->assertTrue(collect($chunks)->every(fn (array $chunk): bool => count($chunk) <= 1));
        $this->assertSame('123456.70', UmkmDisbursement::query()->findOrFail($paid->id)->requested_amount);
        $this->assertSame($before['disbursements'], UmkmDisbursement::query()->get(['id', 'status', 'requested_amount', 'active_request_lock'])->map->getAttributes()->all());
        $this->assertSame($before['allocations'], UmkmDisbursementAllocation::query()->get(['id', 'amount', 'entitlement_reservation_lock'])->map->getAttributes()->all());
        $this->assertSame($before['entitlements'], UmkmEntitlement::query()->get(['id', 'status', 'source_umkm_amount'])->map->getAttributes()->all());
        $this->assertSame($before['disbursement_events'], UmkmDisbursementEvent::query()->count());
        $this->assertSame($before['entitlement_events'], UmkmEntitlementEvent::query()->count());

        $context = json_encode($first, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('REF-PAID-SECRET', $context);
        $this->assertStringNotContainsString('failure-proof-secret', $context);
        $this->assertStringNotContainsString('00123456789', $context);
        $this->assertStringNotContainsString('Pemilik Rekening Rahasia', $context);
    }

    /** @return array{0: User, 1: User} */
    private function admins(): array
    {
        return [
            User::factory()->create(['role' => 'admin_bumdes']),
            User::factory()->create(['role' => 'admin_bumdes']),
        ];
    }

    private function makeStore(string $name): UmkmStore
    {
        $owner = User::factory()->create(['role' => 'penjual']);

        return $owner->umkmStore()->create([
            'name' => $name,
            'slug' => 'batch-c-'.Str::lower(Str::random(10)),
        ]);
    }

    private function makePaid(UmkmStore $store, User $recorder, User $verifier, string $amount = '150000.50', array $overrides = []): UmkmDisbursement
    {
        return $this->makeDisbursement($store, 'paid', $amount, [
            ...$this->paidMetadata($recorder, $verifier, $amount),
            ...$overrides,
        ]);
    }

    private function makeFailed(UmkmStore $store, User $recorder, User $verifier, array $overrides = []): UmkmDisbursement
    {
        return $this->makeDisbursement($store, 'transfer_failed', '150000.50', [
            ...$this->failureMetadata($recorder, $verifier),
            ...$overrides,
        ]);
    }

    /** @return array<string, mixed> */
    private function paidMetadata(User $recorder, User $verifier, string $amount): array
    {
        return [
            'transfer_reference' => 'REF-PAID-SECRET',
            'transfer_amount' => $amount,
            'transfer_proof_path' => 'umkm-disbursement-proofs/paid-proof-secret.pdf',
            'transfer_recorded_by' => $recorder->id,
            'transfer_recorded_at' => now(),
            'transfer_verified_by' => $verifier->id,
            'transfer_verified_at' => now(),
        ];
    }

    /** @return array<string, mixed> */
    private function failureMetadata(User $recorder, User $verifier): array
    {
        return [
            'transfer_failure_reason' => 'Bank menyatakan transfer gagal.',
            'transfer_failure_proof_path' => 'umkm-disbursement-failure-proofs/failure-proof-secret.pdf',
            'transfer_failure_recorded_by' => $recorder->id,
            'transfer_failure_recorded_at' => now(),
            'transfer_failure_verified_by' => $verifier->id,
            'transfer_failure_verified_at' => now(),
        ];
    }

    private function makeDisbursement(UmkmStore $store, string $status, string $amount, array $overrides = []): UmkmDisbursement
    {
        $account = $store->recipientBankAccounts()->first();
        if (! $account) {
            $account = $store->recipientBankAccounts()->create([
                'bank_name' => 'Bank Test',
                'account_holder_name' => 'Pemilik Rekening Rahasia',
                'account_number' => '00123456789',
                'status' => 'active',
                'active_store_lock' => 'store:'.$store->id,
                'verified_at' => now(),
                'created_by' => $store->user_id,
                'updated_by' => $store->user_id,
            ]);
        }

        $active = in_array($status, ['requested', 'under_review', 'approved'], true);

        return UmkmDisbursement::create([
            'umkm_store_id' => $store->id,
            'recipient_bank_account_id' => $account->id,
            'recipient_bank_name' => $account->bank_name,
            'recipient_account_holder_name' => $account->account_holder_name,
            'recipient_account_number' => $account->account_number,
            'requested_amount' => $amount,
            'status' => $status,
            'active_request_lock' => $active ? 'store:'.$store->id : null,
            'requested_by' => $store->user_id,
            'requested_at' => now(),
            'idempotency_key' => (string) Str::uuid(),
            ...$overrides,
        ]);
    }

    private function makeEntitlement(UmkmStore $store, string $status, string $amount = '150000.50'): UmkmEntitlement
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $order = Order::create([
            'order_number' => 'ORD-C-'.Str::upper(Str::random(10)),
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
            'store_order_number' => 'SO-C-'.Str::upper(Str::random(10)),
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
            'payment_number' => 'PAY-C-'.Str::upper(Str::random(10)),
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
