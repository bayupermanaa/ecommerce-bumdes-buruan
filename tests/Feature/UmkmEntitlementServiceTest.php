<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreOrder;
use App\Models\UmkmEntitlement;
use App\Models\UmkmEntitlementEvent;
use App\Models\User;
use App\Services\PaymentConfirmationService;
use App\Services\StoreOrderFulfillmentService;
use App\Services\UmkmEntitlementService;
use App\Support\OrderFulfillmentStatus;
use App\Support\PaymentStatus;
use App\Support\StoreOrderFulfillmentStatus;
use App\Support\UmkmEntitlementStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class UmkmEntitlementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_payment_accrues_one_snapshot_entitlement_for_every_store_and_retry_is_idempotent(): void
    {
        $admin = $this->admin();
        [$order, $storeOrders] = $this->orderWithStores(2);
        $payment = $this->pendingPayment($order);

        $this->paymentService()->confirm($payment, $admin);
        $this->paymentService()->confirm($payment->fresh(), $admin);

        $this->assertSame(PaymentStatus::PAID, $order->fresh()->payment_status);
        $this->assertDatabaseCount('umkm_entitlements', 2);
        $this->assertDatabaseCount('umkm_entitlement_events', 2);
        foreach ($storeOrders as $storeOrder) {
            $entitlement = $storeOrder->fresh()->umkmEntitlement;
            $this->assertSame(UmkmEntitlementStatus::ACCRUED, $entitlement->status);
            $this->assertSame('21875.00', $entitlement->source_umkm_amount);
            $this->assertSame('3125.00', $entitlement->source_commission_amount);
            $this->assertSame('12.50', $entitlement->source_commission_percentage);
        }
    }

    public function test_pending_or_inconsistent_payments_cannot_create_entitlements(): void
    {
        $admin = $this->admin();
        [$order] = $this->orderWithStores(1);
        $pendingPayment = $this->pendingPayment($order);

        try {
            $this->entitlementService()->accrueForConfirmedPayment($order, $pendingPayment, $admin);
            $this->fail('Pending payment must not accrue an entitlement.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('umkm_entitlements', 0);
        }

        [$otherOrder] = $this->orderWithStores(1);
        $otherPayment = $this->paidPayment($otherOrder);
        try {
            $this->entitlementService()->accrueForConfirmedPayment($order, $otherPayment, $admin);
            $this->fail('Payment for another order must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('umkm_entitlements', 0);
        }
    }

    public function test_entitlement_creation_failure_rolls_back_payment_and_fulfillment(): void
    {
        $admin = $this->admin();
        [$order, $storeOrders] = $this->orderWithStores(1);
        $payment = $this->pendingPayment($order);
        $dispatcher = UmkmEntitlementEvent::getEventDispatcher();
        UmkmEntitlementEvent::setEventDispatcher(new Dispatcher($this->app));
        UmkmEntitlementEvent::creating(static function (): void { throw new RuntimeException('Simulated entitlement failure.'); });

        try {
            $this->paymentService()->confirm($payment, $admin);
            $this->fail('Entitlement failure must roll back payment confirmation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated entitlement failure.', $exception->getMessage());
        } finally {
            UmkmEntitlementEvent::setEventDispatcher($dispatcher);
        }

        $this->assertSame(PaymentStatus::PENDING_CONFIRMATION, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::PENDING_CONFIRMATION, $order->fresh()->payment_status);
        $this->assertSame(StoreOrderFulfillmentStatus::PENDING_PAYMENT, $storeOrders->sole()->fresh()->fulfillment_status);
        $this->assertDatabaseCount('umkm_entitlements', 0);
    }

    public function test_completed_store_order_becomes_eligible_but_held_entitlement_does_not(): void
    {
        $admin = $this->admin();
        [$order, $storeOrders, $owners] = $this->orderWithStores(2, [true, false]);
        $payment = $this->pendingPayment($order);
        $this->paymentService()->confirm($payment, $admin);

        $this->complete($storeOrders[0], $owners[0]);
        $this->complete($storeOrders[1], $owners[1]);

        $this->assertSame(UmkmEntitlementStatus::ELIGIBLE, $storeOrders[0]->fresh()->umkmEntitlement->status);
        $this->assertSame(UmkmEntitlementStatus::HELD, $storeOrders[1]->fresh()->umkmEntitlement->status);
        $this->assertDatabaseCount('umkm_entitlement_events', 3);
    }

    public function test_only_admin_can_reconcile_eligible_entitlement_and_retry_does_not_duplicate_event(): void
    {
        $admin = $this->admin();
        [$order, $storeOrders, $owners] = $this->orderWithStores(1);
        $payment = $this->pendingPayment($order);
        $this->paymentService()->confirm($payment, $admin);
        $this->complete($storeOrders->sole(), $owners->sole());
        $entitlement = $storeOrders->sole()->fresh()->umkmEntitlement;

        try {
            $this->entitlementService()->reconcile($entitlement, $owners->sole());
            $this->fail('Non-admin reconciliation must be forbidden.');
        } catch (AuthorizationException) {
            $this->assertSame(UmkmEntitlementStatus::ELIGIBLE, $entitlement->fresh()->status);
        }

        $this->entitlementService()->reconcile($entitlement, $admin, 'Sesuai mutasi pembayaran.');
        $this->entitlementService()->reconcile($entitlement->fresh(), $admin);

        $this->assertSame(UmkmEntitlementStatus::AVAILABLE, $entitlement->fresh()->status);
        $this->assertDatabaseCount('umkm_entitlement_events', 3);
    }

    private function admin(): User { return User::factory()->create(['role' => 'admin_bumdes']); }
    private function paymentService(): PaymentConfirmationService { return app(PaymentConfirmationService::class); }
    private function entitlementService(): UmkmEntitlementService { return app(UmkmEntitlementService::class); }
    private function fulfillmentService(): StoreOrderFulfillmentService { return app(StoreOrderFulfillmentService::class); }

    /** @return array{Order, \Illuminate\Support\Collection<int, StoreOrder>, \Illuminate\Support\Collection<int, User>} */
    private function orderWithStores(int $count, array $calculated = []): array
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $total = number_format(25000 * $count, 2, '.', '');
        $order = Order::create([
            'order_number' => 'ORD-'.Str::upper(Str::random(12)), 'checkout_token' => (string) Str::uuid(), 'user_id' => $customer->id,
            'fulfillment_method' => 'pickup', 'recipient_name' => 'Pelanggan', 'recipient_phone' => '08123456789',
            'subtotal' => $total, 'shipping_total' => '0.00', 'grand_total' => $total,
            'payment_status' => PaymentStatus::PENDING_CONFIRMATION, 'payment_method' => 'cash_pickup', 'order_status' => OrderFulfillmentStatus::PENDING_PAYMENT,
        ]);
        $storeOrders = collect();
        $owners = collect();
        for ($index = 0; $index < $count; $index++) {
            $owner = User::factory()->create(['role' => 'penjual']);
            $store = $owner->umkmStore()->create(['name' => 'Toko '.Str::random(6), 'slug' => 'toko-'.Str::lower(Str::random(12)), 'status' => 'approved']);
            $isCalculated = $calculated[$index] ?? true;
            $storeOrders->push($order->storeOrders()->create([
                'umkm_store_id' => $store->id, 'store_order_number' => 'STO-'.Str::upper(Str::random(12)), 'store_name_snapshot' => $store->name,
                'subtotal' => '25000.00', 'shipping_amount' => '0.00', 'total' => '25000.00', 'fulfillment_status' => StoreOrderFulfillmentStatus::PENDING_PAYMENT,
                'commission_percentage' => $isCalculated ? '12.50' : null, 'commission_amount' => $isCalculated ? '3125.00' : null,
                'umkm_amount' => $isCalculated ? '21875.00' : null, 'commission_status' => $isCalculated ? 'calculated' : 'unconfigured',
            ]));
            $owners->push($owner);
        }

        return [$order, $storeOrders, $owners];
    }

    private function pendingPayment(Order $order): Payment
    {
        return Payment::create([
            'order_id' => $order->id, 'payment_number' => 'PAY-'.Str::upper(Str::random(12)), 'idempotency_key' => (string) Str::uuid(),
            'payment_method' => 'cash_pickup', 'amount' => $order->grand_total, 'status' => PaymentStatus::PENDING_CONFIRMATION,
            'cash_received_by_name' => 'Petugas BUMDes', 'cash_received_at' => now()->subMinute(),
        ]);
    }

    private function paidPayment(Order $order): Payment
    {
        $order->forceFill(['payment_status' => PaymentStatus::PAID])->save();

        return Payment::create([
            'order_id' => $order->id, 'confirmed_order_id' => $order->id, 'payment_number' => 'PAY-'.Str::upper(Str::random(12)),
            'payment_method' => 'cash_pickup', 'amount' => $order->grand_total, 'status' => PaymentStatus::PAID,
            'cash_received_by_name' => 'Petugas BUMDes', 'cash_received_at' => now()->subMinute(), 'confirmed_at' => now(),
        ]);
    }

    private function complete(StoreOrder $storeOrder, User $owner): void
    {
        $this->fulfillmentService()->transition($storeOrder, $owner, StoreOrderFulfillmentStatus::PROCESSING);
        $this->fulfillmentService()->transition($storeOrder->fresh(), $owner, StoreOrderFulfillmentStatus::READY_FOR_PICKUP);
        $this->fulfillmentService()->transition($storeOrder->fresh(), $owner, StoreOrderFulfillmentStatus::COMPLETED);
    }
}
