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
use App\Models\UmkmRecipientBankAccount;
use App\Models\UmkmStore;
use App\Models\User;
use App\Support\UmkmDisbursementEventType;
use App\Support\UmkmDisbursementStatus;
use App\Support\UmkmEntitlementEventType;
use App\Support\UmkmEntitlementStatus;
use App\Support\UmkmRecipientBankAccountStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class UmkmEntitlementFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_six_foundation_tables_and_status_constants_are_available(): void
    {
        foreach ([
            'umkm_recipient_bank_accounts', 'umkm_entitlements', 'umkm_entitlement_events',
            'umkm_disbursements', 'umkm_disbursement_allocations', 'umkm_disbursement_events',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        $this->assertContains(UmkmEntitlementStatus::HELD, UmkmEntitlementStatus::values());
        $this->assertContains(UmkmEntitlementEventType::SETTLED, UmkmEntitlementEventType::values());
        $this->assertContains(UmkmDisbursementStatus::TRANSFER_FAILED, UmkmDisbursementStatus::values());
        $this->assertContains(UmkmDisbursementEventType::TRANSFER_VERIFIED, UmkmDisbursementEventType::values());
        $this->assertSame(['active', 'inactive'], UmkmRecipientBankAccountStatus::values());
    }

    public function test_unique_keys_and_foreign_keys_protect_foundation_records(): void
    {
        [$store, $order, $payment, $storeOrder] = $this->source();
        $account = $this->account($store);
        $entitlement = $this->entitlement($store, $order, $payment, $storeOrder);
        $disbursement = $this->disbursement($store, $account);

        $this->expectException(QueryException::class);
        UmkmEntitlement::create([
            'umkm_store_id' => $store->id, 'order_id' => $order->id, 'store_order_id' => $storeOrder->id,
            'payment_id' => $payment->id, 'source_umkm_amount' => '90000.00', 'source_commission_amount' => '10000.00',
            'source_commission_percentage' => '10.00', 'commission_status_snapshot' => 'calculated', 'status' => 'accrued', 'accrued_at' => now(),
        ]);

        // The remaining uniqueness and FK checks are exercised in separate, isolated requests below.
        $this->assertNotNull($entitlement);
        $this->assertNotNull($disbursement);
    }

    public function test_foreign_keys_reject_orphan_financial_records(): void
    {
        $this->expectException(QueryException::class);

        UmkmRecipientBankAccount::create([
            'umkm_store_id' => 999999, 'bank_name' => 'Bank Test', 'account_holder_name' => 'Pemilik',
            'account_number' => '001', 'status' => 'active', 'active_store_lock' => 'store:999999',
        ]);
    }

    public function test_unique_active_locks_idempotency_and_allocation_pair_are_enforced(): void
    {
        [$store, $order, $payment, $storeOrder] = $this->source();
        $account = $this->account($store);
        $entitlement = $this->entitlement($store, $order, $payment, $storeOrder);
        $disbursement = $this->disbursement($store, $account);
        $allocation = UmkmDisbursementAllocation::create([
            'umkm_disbursement_id' => $disbursement->id, 'umkm_entitlement_id' => $entitlement->id,
            'amount' => '90000.00', 'entitlement_reservation_lock' => 'entitlement:'.$entitlement->id,
        ]);
        $eventKey = (string) Str::uuid();
        UmkmEntitlementEvent::create([
            'umkm_entitlement_id' => $entitlement->id, 'event_type' => UmkmEntitlementEventType::ACCRUED,
            'amount' => '90000.00', 'idempotency_key' => $eventKey,
        ]);

        $this->assertDatabaseCount('umkm_disbursement_allocations', 1);
        $this->assertNotNull($allocation);

        [$otherStore] = $this->source();
        $otherAccount = $this->account($otherStore);
        try {
            $this->disbursement($otherStore, $otherAccount, $disbursement->idempotency_key);
            $this->fail('Expected disbursement idempotency key violation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        foreach ([
            fn () => $store->recipientBankAccounts()->create([
                'bank_name' => 'Bank Lain', 'account_holder_name' => 'Pemilik', 'account_number' => '222',
                'status' => 'active', 'active_store_lock' => 'store:'.$store->id,
            ]),
            fn () => $this->disbursement($store, $account),
            fn () => UmkmDisbursementAllocation::create([
                'umkm_disbursement_id' => $disbursement->id, 'umkm_entitlement_id' => $entitlement->id, 'amount' => '90000.00',
            ]),
            fn () => UmkmEntitlementEvent::create([
                'umkm_entitlement_id' => $entitlement->id, 'event_type' => UmkmEntitlementEventType::ACCRUED,
                'amount' => '90000.00', 'idempotency_key' => $eventKey,
            ]),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected unique constraint violation.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_models_expose_relations_decimal_casts_and_do_not_mutate_transaction_sources(): void
    {
        [$store, $order, $payment, $storeOrder] = $this->source();
        $account = $this->account($store);
        $entitlement = $this->entitlement($store, $order, $payment, $storeOrder);
        $disbursement = $this->disbursement($store, $account);
        $allocation = UmkmDisbursementAllocation::create([
            'umkm_disbursement_id' => $disbursement->id, 'umkm_entitlement_id' => $entitlement->id, 'amount' => '90000.00',
        ]);
        $event = UmkmDisbursementEvent::create([
            'umkm_disbursement_id' => $disbursement->id, 'event_type' => UmkmDisbursementEventType::REQUESTED,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->assertSame('90000.00', $entitlement->source_umkm_amount);
        $this->assertSame('10000.00', $entitlement->source_commission_amount);
        $this->assertSame('90000.00', $allocation->amount);
        $this->assertTrue($entitlement->storeOrder->is($storeOrder));
        $this->assertTrue($entitlement->payment->is($payment));
        $this->assertTrue($disbursement->recipientBankAccount->is($account));
        $this->assertTrue($event->disbursement->is($disbursement));
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('completed', $storeOrder->fresh()->fulfillment_status);
    }

    /** @return array{UmkmStore, Order, Payment, StoreOrder} */
    private function source(): array
    {
        $owner = User::factory()->create(['role' => 'penjual']);
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $store = $owner->umkmStore()->create(['name' => 'Toko Hak', 'slug' => 'toko-hak-'.Str::lower(Str::random(8)), 'status' => 'approved']);
        $order = Order::create([
            'order_number' => 'ORD-'.Str::upper(Str::random(12)), 'checkout_token' => (string) Str::uuid(), 'user_id' => $customer->id,
            'fulfillment_method' => 'pickup', 'recipient_name' => 'Pelanggan', 'recipient_phone' => '08123456789',
            'subtotal' => '100000.00', 'shipping_total' => '0.00', 'grand_total' => '100000.00',
            'payment_status' => 'paid', 'order_status' => 'completed',
        ]);
        $payment = Payment::create([
            'order_id' => $order->id, 'confirmed_order_id' => $order->id, 'payment_number' => 'PAY-'.Str::upper(Str::random(12)),
            'payment_method' => 'bank_transfer', 'amount' => '100000.00', 'status' => 'paid', 'confirmed_at' => now(),
        ]);
        $storeOrder = $order->storeOrders()->create([
            'umkm_store_id' => $store->id, 'store_order_number' => 'STO-'.Str::upper(Str::random(12)), 'store_name_snapshot' => $store->name,
            'subtotal' => '100000.00', 'shipping_amount' => '0.00', 'total' => '100000.00', 'fulfillment_status' => 'completed',
            'commission_percentage' => '10.00', 'commission_amount' => '10000.00', 'umkm_amount' => '90000.00', 'commission_status' => 'calculated',
        ]);

        return [$store, $order, $payment, $storeOrder];
    }

    private function account(UmkmStore $store): UmkmRecipientBankAccount
    {
        return $store->recipientBankAccounts()->create([
            'bank_name' => 'Bank Test', 'account_holder_name' => 'Pemilik Toko', 'account_number' => '00123',
            'status' => 'active', 'active_store_lock' => 'store:'.$store->id,
        ]);
    }

    private function entitlement(UmkmStore $store, Order $order, Payment $payment, StoreOrder $storeOrder): UmkmEntitlement
    {
        return UmkmEntitlement::create([
            'umkm_store_id' => $store->id, 'order_id' => $order->id, 'store_order_id' => $storeOrder->id, 'payment_id' => $payment->id,
            'source_umkm_amount' => '90000.00', 'source_commission_amount' => '10000.00', 'source_commission_percentage' => '10.00',
            'commission_status_snapshot' => 'calculated', 'status' => UmkmEntitlementStatus::ACCRUED, 'accrued_at' => now(),
        ]);
    }

    private function disbursement(UmkmStore $store, UmkmRecipientBankAccount $account, ?string $idempotencyKey = null): UmkmDisbursement
    {
        return UmkmDisbursement::create([
            'umkm_store_id' => $store->id, 'recipient_bank_account_id' => $account->id,
            'recipient_bank_name' => $account->bank_name, 'recipient_account_holder_name' => $account->account_holder_name,
            'recipient_account_number' => $account->account_number, 'requested_amount' => '90000.00',
            'status' => UmkmDisbursementStatus::REQUESTED, 'active_request_lock' => 'store:'.$store->id,
            'requested_by' => $store->user_id, 'requested_at' => now(), 'idempotency_key' => $idempotencyKey ?? (string) Str::uuid(),
        ]);
    }
}
