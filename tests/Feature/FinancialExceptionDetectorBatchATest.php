<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreOrder;
use App\Models\UmkmDisbursement;
use App\Models\UmkmDisbursementEvent;
use App\Models\UmkmEntitlement;
use App\Models\UmkmEntitlementEvent;
use App\Models\UmkmStore;
use App\Models\User;
use App\Services\FinancialAudit\FinancialExceptionDetector;
use App\Support\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialExceptionDetectorBatchATest extends TestCase
{
    use RefreshDatabase;

    public function test_pay_ent_missing_only_reports_confirmed_paid_store_orders_without_entitlements(): void
    {
        $detector = app(FinancialExceptionDetector::class);
        $storeA = $this->makeStore('Toko Entitlement Hilang A');
        $storeB = $this->makeStore('Toko Entitlement Hilang B');
        $paidOrder = $this->makeOrder(PaymentStatus::PAID);
        $missing = $this->makeStoreOrder($storeA, $paidOrder);
        $covered = $this->makeStoreOrder($storeB, $paidOrder);
        $coveredPayment = $this->makePayment($paidOrder, PaymentStatus::PAID, $paidOrder->id);
        $this->makeEntitlement($storeB, $paidOrder, $covered, $coveredPayment);

        $unpaidOrder = $this->makeOrder(PaymentStatus::UNPAID);
        $unpaidStoreOrder = $this->makeStoreOrder($storeA, $unpaidOrder);
        $this->makePayment($unpaidOrder, PaymentStatus::PAID, $unpaidOrder->id);

        $unconfirmedOrder = $this->makeOrder(PaymentStatus::PAID);
        $unconfirmedStoreOrder = $this->makeStoreOrder($storeA, $unconfirmedOrder);
        $this->makePayment($unconfirmedOrder, PaymentStatus::PAID, null);

        $exceptions = $detector->scan(['category' => FinancialExceptionDetector::PAY_ENT_MISSING])->all();

        $this->assertInstanceOf(LazyCollection::class, $detector->scan());
        $this->assertCount(1, $exceptions);
        $this->assertSame(FinancialExceptionDetector::PAY_ENT_MISSING, $exceptions[0]['code']);
        $this->assertSame($missing->id, $exceptions[0]['reference_id']);
        $this->assertSame('confirmed_paid_store_order_has_no_entitlement', $exceptions[0]['context']['reason']);
        $this->assertTrue($exceptions[0]['context']['possible_legacy_data']);
        $this->assertNotSame($unpaidStoreOrder->id, $exceptions[0]['reference_id']);
        $this->assertNotSame($unconfirmedStoreOrder->id, $exceptions[0]['reference_id']);
    }

    public function test_ent_payment_invalid_reports_each_supported_reason_without_flagging_valid_sources(): void
    {
        $detector = app(FinancialExceptionDetector::class);
        $storeA = $this->makeStore('Toko Integritas A');
        $storeB = $this->makeStore('Toko Integritas B');

        $unpaidOrder = $this->makeOrder(PaymentStatus::UNPAID);
        $unpaidStoreOrder = $this->makeStoreOrder($storeA, $unpaidOrder);
        $unpaidOrderPayment = $this->makePayment($unpaidOrder, PaymentStatus::PAID, $unpaidOrder->id);
        $this->makeEntitlement($storeA, $unpaidOrder, $unpaidStoreOrder, $unpaidOrderPayment);

        $paymentPendingOrder = $this->makeOrder(PaymentStatus::PAID);
        $paymentPendingStoreOrder = $this->makeStoreOrder($storeA, $paymentPendingOrder);
        $pendingPayment = $this->makePayment($paymentPendingOrder, PaymentStatus::PENDING_CONFIRMATION, null);
        $this->makeEntitlement($storeA, $paymentPendingOrder, $paymentPendingStoreOrder, $pendingPayment);

        $confirmedMismatchOrder = $this->makeOrder(PaymentStatus::PAID);
        $confirmedMismatchStoreOrder = $this->makeStoreOrder($storeA, $confirmedMismatchOrder);
        $otherOrder = $this->makeOrder(PaymentStatus::PAID);
        $wrongConfirmedPayment = $this->makePayment($confirmedMismatchOrder, PaymentStatus::PAID, $otherOrder->id);
        $this->makeEntitlement($storeA, $confirmedMismatchOrder, $confirmedMismatchStoreOrder, $wrongConfirmedPayment);

        $paymentOrderMismatchOrder = $this->makeOrder(PaymentStatus::PAID);
        $paymentOrderMismatchStoreOrder = $this->makeStoreOrder($storeA, $paymentOrderMismatchOrder);
        $foreignPayment = $this->makePayment($otherOrder, PaymentStatus::PAID, $paymentOrderMismatchOrder->id);
        $this->makeEntitlement($storeA, $paymentOrderMismatchOrder, $paymentOrderMismatchStoreOrder, $foreignPayment);

        $storeMismatchOrder = $this->makeOrder(PaymentStatus::PAID);
        $storeMismatchStoreOrder = $this->makeStoreOrder($storeB, $storeMismatchOrder);
        $storeMismatchPayment = $this->makePayment($storeMismatchOrder, PaymentStatus::PAID, $storeMismatchOrder->id);
        $this->makeEntitlement($storeA, $storeMismatchOrder, $storeMismatchStoreOrder, $storeMismatchPayment);

        $paidWithoutValidPayment = $this->makeOrder(PaymentStatus::PAID);
        $missingPaymentStoreOrder = $this->makeStoreOrder($storeA, $paidWithoutValidPayment);

        $validOrder = $this->makeOrder(PaymentStatus::PAID);
        $validStoreOrder = $this->makeStoreOrder($storeA, $validOrder);
        $validPayment = $this->makePayment($validOrder, PaymentStatus::PAID, $validOrder->id);
        $validEntitlement = $this->makeEntitlement($storeA, $validOrder, $validStoreOrder, $validPayment);

        $exceptions = $detector->scan(['category' => FinancialExceptionDetector::ENT_PAYMENT_INVALID])->all();
        $reasons = collect($exceptions)->pluck('context.reason')->all();

        $this->assertContains('order_not_paid', $reasons);
        $this->assertContains('payment_not_paid', $reasons);
        $this->assertContains('payment_order_mismatch', $reasons);
        $this->assertContains('payment_not_confirmed_for_entitlement_order', $reasons);
        $this->assertContains('store_order_store_mismatch', $reasons);
        $this->assertContains('paid_order_without_valid_confirmed_payment', $reasons);
        $this->assertNotContains($validEntitlement->id, collect($exceptions)->where('reference_type', 'umkm_entitlement')->pluck('reference_id')->all());
        $this->assertContains($missingPaymentStoreOrder->id, collect($exceptions)->where('context.reason', 'paid_order_without_valid_confirmed_payment')->pluck('reference_id')->all());
    }

    public function test_snapshot_and_held_detectors_preserve_decimal_precision_and_allow_held_null_amounts(): void
    {
        $detector = app(FinancialExceptionDetector::class);
        $store = $this->makeStore('Toko Snapshot');
        $order = $this->makeOrder(PaymentStatus::PAID);
        $payment = $this->makePayment($order, PaymentStatus::PAID, $order->id);

        $matchingStoreOrder = $this->makeStoreOrder($store, $order, [
            'umkm_amount' => '150000.50',
            'commission_amount' => '123456.70',
            'commission_percentage' => '10.25',
            'commission_status' => 'calculated',
        ]);
        $matching = $this->makeEntitlement($store, $order, $matchingStoreOrder, $payment, [
            'source_umkm_amount' => '150000.50',
            'source_commission_amount' => '123456.70',
            'source_commission_percentage' => '10.25',
        ]);

        $umkmMismatchStoreOrder = $this->makeStoreOrder($store, $order, ['umkm_amount' => '150000.51']);
        $this->makeEntitlement($store, $order, $umkmMismatchStoreOrder, $payment, ['source_umkm_amount' => '150000.50']);
        $commissionMismatchStoreOrder = $this->makeStoreOrder($store, $order, ['commission_amount' => '123456.71']);
        $this->makeEntitlement($store, $order, $commissionMismatchStoreOrder, $payment, ['source_commission_amount' => '123456.70']);
        $percentageMismatchStoreOrder = $this->makeStoreOrder($store, $order, ['commission_percentage' => '10.26']);
        $this->makeEntitlement($store, $order, $percentageMismatchStoreOrder, $payment, ['source_commission_percentage' => '10.25']);
        $nullSnapshotStoreOrder = $this->makeStoreOrder($store, $order);
        $this->makeEntitlement($store, $order, $nullSnapshotStoreOrder, $payment, ['source_umkm_amount' => null]);
        $statusMismatchStoreOrder = $this->makeStoreOrder($store, $order, ['commission_status' => 'unconfigured']);
        $this->makeEntitlement($store, $order, $statusMismatchStoreOrder, $payment);

        $heldStoreOrder = $this->makeStoreOrder($store, $order, ['commission_status' => 'unconfigured']);
        $heldWithReason = $this->makeEntitlement($store, $order, $heldStoreOrder, $payment, [
            'status' => 'held',
            'commission_status_snapshot' => 'unconfigured',
            'source_umkm_amount' => null,
            'source_commission_amount' => null,
            'source_commission_percentage' => null,
            'held_reason' => 'Snapshot belum dihitung.',
        ]);
        $heldWithoutReasonStoreOrder = $this->makeStoreOrder($store, $order, ['commission_status' => 'unconfigured']);
        $heldWithoutReason = $this->makeEntitlement($store, $order, $heldWithoutReasonStoreOrder, $payment, [
            'status' => 'held',
            'commission_status_snapshot' => 'unconfigured',
            'source_umkm_amount' => null,
            'source_commission_amount' => null,
            'source_commission_percentage' => null,
            'held_reason' => null,
        ]);

        $snapshotExceptions = $detector->scan(['category' => FinancialExceptionDetector::ENT_SNAPSHOT_MISMATCH])->all();
        $snapshotIds = collect($snapshotExceptions)->pluck('reference_id')->all();
        $snapshotReasons = collect($snapshotExceptions)->pluck('context.reason')->all();
        $heldExceptions = $detector->scan(['category' => FinancialExceptionDetector::ENT_HELD_REVIEW])->all();

        $this->assertNotContains($matching->id, $snapshotIds);
        $this->assertCount(5, $snapshotExceptions);
        $this->assertContains('calculated_snapshot_value_mismatch', $snapshotReasons);
        $this->assertContains('calculated_snapshot_incomplete', $snapshotReasons);
        $this->assertContains('commission_status_mismatch', $snapshotReasons);
        $this->assertSame('150000.5', $snapshotExceptions[0]['context']['entitlement_amounts']['source_umkm_amount']);
        $this->assertSame('150000.51', $snapshotExceptions[0]['context']['store_order_amounts']['umkm_amount']);
        $this->assertSame([$heldWithReason->id, $heldWithoutReason->id], collect($heldExceptions)->pluck('reference_id')->all());
        $this->assertSame(['held_pending_review', 'held_reason_missing'], collect($heldExceptions)->pluck('context.reason')->all());
    }

    public function test_filters_stable_keys_chunking_and_read_only_contract_are_preserved(): void
    {
        $detector = app(FinancialExceptionDetector::class);
        $storeA = $this->makeStore('Toko Filter A');
        $storeB = $this->makeStore('Toko Filter B');

        foreach ([$storeA, $storeB, $storeA] as $store) {
            $order = $this->makeOrder(PaymentStatus::PAID);
            $this->makeStoreOrder($store, $order);
            $this->makePayment($order, PaymentStatus::PAID, $order->id);
        }

        $heldOrder = $this->makeOrder(PaymentStatus::PAID);
        $heldStoreOrder = $this->makeStoreOrder($storeA, $heldOrder, ['commission_status' => 'unconfigured']);
        $heldPayment = $this->makePayment($heldOrder, PaymentStatus::PAID, $heldOrder->id);
        $this->makeEntitlement($storeA, $heldOrder, $heldStoreOrder, $heldPayment, [
            'status' => 'held',
            'commission_status_snapshot' => 'unconfigured',
            'source_umkm_amount' => null,
            'source_commission_amount' => null,
            'source_commission_percentage' => null,
        ]);

        $before = [
            'orders' => Order::query()->count(),
            'payments' => Payment::query()->count(),
            'entitlements' => UmkmEntitlement::query()->count(),
            'entitlement_events' => UmkmEntitlementEvent::query()->count(),
            'disbursements' => UmkmDisbursement::query()->count(),
            'disbursement_events' => UmkmDisbursementEvent::query()->count(),
            'statuses' => UmkmEntitlement::query()->pluck('status', 'id')->all(),
        ];

        $storeAExceptions = $detector->scan([
            'categories' => [FinancialExceptionDetector::PAY_ENT_MISSING, FinancialExceptionDetector::ENT_HELD_REVIEW],
            'umkm_store_id' => $storeA->id,
        ])->values()->all();
        $secondScan = $detector->scan([
            'categories' => [FinancialExceptionDetector::PAY_ENT_MISSING, FinancialExceptionDetector::ENT_HELD_REVIEW],
            'umkm_store_id' => $storeA->id,
        ])->values()->all();

        $chunks = [];
        $detector->chunk([
            'categories' => [FinancialExceptionDetector::PAY_ENT_MISSING, FinancialExceptionDetector::ENT_HELD_REVIEW],
            'umkm_store_id' => $storeA->id,
        ], 2, function (Collection $exceptions) use (&$chunks): void {
            $chunks[] = $exceptions->all();
        });

        $keys = array_column($storeAExceptions, 'stable_exception_key');
        $chunkedKeys = array_merge(...array_map(fn (array $chunk) => array_column($chunk, 'stable_exception_key'), $chunks));

        $this->assertCount(3, $storeAExceptions);
        $this->assertSame($keys, array_column($secondScan, 'stable_exception_key'));
        $this->assertCount(count($keys), array_unique($keys));
        $this->assertNotSame($keys[0], $keys[2]);
        $this->assertSame($keys, $chunkedKeys);
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(2, count($chunk));
        }
        foreach ($storeAExceptions as $exception) {
            $context = json_encode($exception['context'], JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('account_number', $context);
            $this->assertStringNotContainsString('account_holder', $context);
            $this->assertStringNotContainsString('proof_path', $context);
            $this->assertStringNotContainsString('recipient_', $context);
        }

        $this->assertSame($before['orders'], Order::query()->count());
        $this->assertSame($before['payments'], Payment::query()->count());
        $this->assertSame($before['entitlements'], UmkmEntitlement::query()->count());
        $this->assertSame($before['entitlement_events'], UmkmEntitlementEvent::query()->count());
        $this->assertSame($before['disbursements'], UmkmDisbursement::query()->count());
        $this->assertSame($before['disbursement_events'], UmkmDisbursementEvent::query()->count());
        $this->assertSame($before['statuses'], UmkmEntitlement::query()->pluck('status', 'id')->all());
    }

    private function makeStore(string $name): UmkmStore
    {
        $owner = User::factory()->create(['role' => 'penjual']);

        return $owner->umkmStore()->create([
            'name' => $name,
            'slug' => 'audit-'.Str::lower(Str::random(12)),
            'status' => 'approved',
        ]);
    }

    private function makeOrder(string $paymentStatus): Order
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);

        return Order::create([
            'order_number' => 'ORD-AUDIT-'.Str::upper(Str::random(10)),
            'checkout_token' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'fulfillment_method' => 'pickup',
            'recipient_name' => 'Pelanggan Audit',
            'recipient_phone' => '081234567890',
            'subtotal' => '300000.00',
            'shipping_total' => '0.00',
            'grand_total' => '300000.00',
            'payment_status' => $paymentStatus,
            'order_status' => 'completed',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeStoreOrder(UmkmStore $store, Order $order, array $overrides = []): StoreOrder
    {
        return StoreOrder::create(array_merge([
            'order_id' => $order->id,
            'umkm_store_id' => $store->id,
            'store_order_number' => 'SO-AUDIT-'.Str::upper(Str::random(10)),
            'store_name_snapshot' => $store->name,
            'subtotal' => '273457.20',
            'shipping_amount' => '0.00',
            'total' => '273457.20',
            'fulfillment_status' => 'completed',
            'commission_percentage' => '10.25',
            'commission_amount' => '123456.70',
            'umkm_amount' => '150000.50',
            'commission_status' => 'calculated',
        ], $overrides));
    }

    private function makePayment(Order $order, string $status, ?int $confirmedOrderId): Payment
    {
        return Payment::create([
            'order_id' => $order->id,
            'confirmed_order_id' => $confirmedOrderId,
            'payment_number' => 'PAY-AUDIT-'.Str::upper(Str::random(10)),
            'payment_method' => 'cash_pickup',
            'amount' => '300000.00',
            'status' => $status,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeEntitlement(UmkmStore $store, Order $order, StoreOrder $storeOrder, Payment $payment, array $overrides = []): UmkmEntitlement
    {
        return UmkmEntitlement::create(array_merge([
            'umkm_store_id' => $store->id,
            'order_id' => $order->id,
            'store_order_id' => $storeOrder->id,
            'payment_id' => $payment->id,
            'source_umkm_amount' => '150000.50',
            'source_commission_amount' => '123456.70',
            'source_commission_percentage' => '10.25',
            'commission_status_snapshot' => 'calculated',
            'status' => 'accrued',
            'accrued_at' => now(),
        ], $overrides));
    }
}
