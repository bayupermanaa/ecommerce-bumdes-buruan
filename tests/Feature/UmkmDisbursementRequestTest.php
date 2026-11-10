<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreOrder;
use App\Models\UmkmEntitlement;
use App\Models\UmkmEntitlementEvent;
use App\Models\User;
use App\Services\UmkmDisbursementRequestService;
use App\Support\UmkmEntitlementStatus;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class UmkmDisbursementRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_requests_every_available_entitlement_once_and_keeps_bank_snapshot(): void
    {
        [$owner, $store, $account, $available, $otherStatus] = $this->storeWithEntitlements();
        $this->actingAs($owner)->get(route('umkm.disbursements.index'))->assertOk()
            ->assertSee('Saldo tersedia')->assertSee('123.45');

        $key = (string) Str::uuid();
        $this->actingAs($owner)->post(route('umkm.disbursements.store'), ['idempotency_key' => $key])
            ->assertRedirect(route('umkm.disbursements.index'));

        $disbursement = $store->disbursements()->sole();
        $this->assertSame('requested', $disbursement->status);
        $this->assertSame('123.45', $disbursement->requested_amount);
        $this->assertSame($account->account_number, $disbursement->recipient_account_number);
        $this->assertDatabaseCount('umkm_disbursement_allocations', 1);
        $this->assertSame(UmkmEntitlementStatus::RESERVED, $available->fresh()->status);
        $this->assertSame(UmkmEntitlementStatus::ELIGIBLE, $otherStatus->fresh()->status);
        $this->assertDatabaseCount('umkm_entitlement_events', 1);
        $this->assertDatabaseCount('umkm_disbursement_events', 1);
        $this->assertDatabaseHas('orders', ['id' => $available->order_id, 'payment_status' => 'paid', 'order_status' => 'completed']);
        $this->assertDatabaseHas('store_orders', ['id' => $available->store_order_id, 'umkm_amount' => '135.00', 'commission_amount' => '15.00']);
        $this->assertDatabaseHas('payments', ['id' => $available->payment_id, 'status' => 'paid']);

        $this->service()->request($store, $owner, $key);
        $this->assertDatabaseCount('umkm_disbursements', 1);
        $this->assertDatabaseCount('umkm_disbursement_allocations', 1);

        $account->forceFill(['account_number' => '00987654321'])->save();
        $this->assertSame('00123456789', $disbursement->fresh()->recipient_account_number);
    }

    public function test_unverified_or_empty_accounts_and_other_roles_are_rejected(): void
    {
        [$owner, $store, $account] = $this->storeWithEntitlements(false);
        $key = (string) Str::uuid();
        try {
            $this->service()->request($store, $owner, $key);
            $this->fail('Unverified account must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('umkm_disbursements', 0);
        }

        $customer = User::factory()->create(['role' => 'pelanggan']);
        $this->actingAs($customer)->get(route('umkm.disbursements.index'))->assertForbidden();
        $this->actingAs($customer)->post(route('umkm.disbursements.store'), ['idempotency_key' => (string) Str::uuid()])->assertForbidden();

        $account->forceFill(['verified_at' => now()])->save();
        UmkmEntitlement::query()->update(['status' => UmkmEntitlementStatus::ELIGIBLE]);
        $this->actingAs($owner)->post(route('umkm.disbursements.store'), ['idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('entitlements');
    }

    public function test_failure_rolls_back_reservation_and_prevents_a_second_active_request(): void
    {
        [$owner, $store, $account, $available] = $this->storeWithEntitlements();
        $dispatcher = UmkmEntitlementEvent::getEventDispatcher();
        UmkmEntitlementEvent::setEventDispatcher(new Dispatcher($this->app));
        UmkmEntitlementEvent::creating(static function (): void { throw new RuntimeException('Simulated reservation failure.'); });
        try {
            $this->service()->request($store, $owner, (string) Str::uuid());
            $this->fail('Reservation failure must bubble up.');
        } catch (RuntimeException) {
            $this->assertSame(UmkmEntitlementStatus::AVAILABLE, $available->fresh()->status);
            $this->assertDatabaseCount('umkm_disbursements', 0);
        } finally {
            UmkmEntitlementEvent::setEventDispatcher($dispatcher);
        }

        $this->service()->request($store, $owner, (string) Str::uuid());
        try {
            $this->service()->request($store, $owner, (string) Str::uuid());
            $this->fail('A second active request must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('umkm_disbursements', 1);
        }
    }

    /** @return array{User, \App\Models\UmkmStore, \App\Models\UmkmRecipientBankAccount, UmkmEntitlement, UmkmEntitlement} */
    private function storeWithEntitlements(bool $verified = true): array
    {
        $owner = User::factory()->create(['role' => 'penjual']);
        $store = $owner->umkmStore()->create(['name' => 'Toko Uji', 'slug' => 'toko-'.Str::lower(Str::random(10)), 'status' => 'approved']);
        $account = $store->recipientBankAccounts()->create([
            'bank_name' => 'Bank Uji', 'account_holder_name' => $owner->name, 'account_number' => '00123456789',
            'status' => 'active', 'active_store_lock' => 'store:'.$store->id, 'verified_at' => $verified ? now() : null,
            'created_by' => $owner->id, 'updated_by' => $owner->id,
        ]);
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $order = Order::create(['order_number' => 'ORD-'.Str::upper(Str::random(10)), 'checkout_token' => (string) Str::uuid(), 'user_id' => $customer->id, 'fulfillment_method' => 'pickup', 'recipient_name' => 'Pelanggan', 'recipient_phone' => '08123456789', 'subtotal' => '150.00', 'shipping_total' => '0.00', 'grand_total' => '150.00', 'payment_status' => 'paid', 'order_status' => 'completed']);
        $payment = Payment::create(['order_id' => $order->id, 'confirmed_order_id' => $order->id, 'payment_number' => 'PAY-'.Str::upper(Str::random(10)), 'payment_method' => 'cash_pickup', 'amount' => '150.00', 'status' => 'paid']);
        $storeOrder = $order->storeOrders()->create(['umkm_store_id' => $store->id, 'store_order_number' => 'STO-'.Str::upper(Str::random(10)), 'store_name_snapshot' => $store->name, 'subtotal' => '150.00', 'shipping_amount' => '0.00', 'total' => '150.00', 'fulfillment_status' => 'completed', 'commission_status' => 'calculated', 'commission_percentage' => '10.00', 'commission_amount' => '15.00', 'umkm_amount' => '135.00']);
        $available = $this->entitlement($store->id, $order->id, $storeOrder->id, $payment->id, '123.45', UmkmEntitlementStatus::AVAILABLE);
        $eligible = $this->entitlement($store->id, $order->id, $storeOrder->id, $payment->id, '22.00', UmkmEntitlementStatus::ELIGIBLE, true);

        return [$owner, $store, $account, $available, $eligible];
    }

    private function entitlement(int $storeId, int $orderId, int $storeOrderId, int $paymentId, string $amount, string $status, bool $alternate = false): UmkmEntitlement
    {
        if ($alternate) {
            $order = Order::findOrFail($orderId);
            $storeOrder = $order->storeOrders()->firstOrFail();
            // Each entitlement must map to a distinct store order by schema contract.
            $storeOrder = StoreOrder::create([...$storeOrder->only(['order_id', 'umkm_store_id', 'store_name_snapshot', 'subtotal', 'shipping_amount', 'total', 'fulfillment_status', 'commission_percentage', 'commission_amount', 'umkm_amount', 'commission_status']), 'store_order_number' => 'STO-'.Str::upper(Str::random(10))]);
            $storeOrderId = $storeOrder->id;
        }
        return UmkmEntitlement::create(['umkm_store_id' => $storeId, 'order_id' => $orderId, 'store_order_id' => $storeOrderId, 'payment_id' => $paymentId, 'source_umkm_amount' => $amount, 'source_commission_amount' => '0.00', 'source_commission_percentage' => '0.00', 'commission_status_snapshot' => 'calculated', 'status' => $status, 'accrued_at' => now()]);
    }

    private function service(): UmkmDisbursementRequestService { return app(UmkmDisbursementRequestService::class); }
}
