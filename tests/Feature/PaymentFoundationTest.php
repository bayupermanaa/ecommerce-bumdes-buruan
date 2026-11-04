<?php

namespace Tests\Feature;

use App\Models\BumdesBankAccount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentLog;
use App\Models\User;
use App\Support\PaymentMethod;
use App\Support\PaymentStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_schema_is_additive_and_legacy_order_data_remains_valid(): void
    {
        $this->assertTrue(Schema::hasTable('bumdes_bank_accounts'));
        $this->assertTrue(Schema::hasTable('payments'));
        $this->assertTrue(Schema::hasTable('payment_logs'));
        $this->assertTrue(Schema::hasColumns('orders', [
            'payment_method',
            'payment_confirmed_at',
            'payment_confirmed_by',
        ]));

        $customer = User::factory()->create(['role' => 'pelanggan']);
        $order = $this->orderFor($customer);

        $this->assertSame(PaymentStatus::UNPAID, $order->payment_status);
        $this->assertNull($order->payment_method);
        $this->assertNull($order->payment_confirmed_at);
        $this->assertNull($order->payment_confirmed_by);
    }

    public function test_payment_attempts_are_order_level_and_keep_their_own_audit_history(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $order = $this->orderFor($customer);
        $account = BumdesBankAccount::create([
            'bank_name' => 'Bank Resmi BUMDes',
            'account_holder_name' => 'BUMDes Desa Buruan',
            'account_number' => '00123456789',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);
        $rejectedAttempt = Payment::create([
            'order_id' => $order->id,
            'bumdes_bank_account_id' => $account->id,
            'payment_number' => 'PAY-ATTEMPT-001',
            'idempotency_key' => (string) Str::uuid(),
            'payment_method' => PaymentMethod::BANK_TRANSFER,
            'amount' => $order->grand_total,
            'status' => PaymentStatus::REJECTED,
            'payer_name' => 'Pelanggan Test',
            'transfer_reference' => 'REF-DITOLAK',
            'rejected_by' => $admin->id,
            'rejected_at' => now(),
            'rejection_reason' => 'Referensi transfer tidak cocok.',
        ]);
        $paidAttempt = Payment::create([
            'order_id' => $order->id,
            'confirmed_order_id' => $order->id,
            'payment_number' => 'PAY-ATTEMPT-002',
            'idempotency_key' => (string) Str::uuid(),
            'payment_method' => PaymentMethod::CASH_PICKUP,
            'amount' => $order->grand_total,
            'status' => PaymentStatus::PAID,
            'cash_received_by_name' => 'Petugas Loket BUMDes',
            'cash_received_at' => now(),
            'confirmed_by' => $admin->id,
            'confirmed_at' => now(),
        ]);
        $log = PaymentLog::create([
            'payment_id' => $paidAttempt->id,
            'actor_id' => $admin->id,
            'action' => 'confirmed',
            'from_status' => PaymentStatus::PENDING_CONFIRMATION,
            'to_status' => PaymentStatus::PAID,
            'metadata' => ['source' => 'cash_pickup'],
        ]);

        $order->refresh();
        $this->assertCount(2, $order->payments);
        $this->assertSame($paidAttempt->id, $order->confirmedPayment->id);
        $this->assertSame($account->id, $rejectedAttempt->bumdesBankAccount->id);
        $this->assertSame($admin->id, $paidAttempt->confirmedBy->id);
        $this->assertSame($log->id, $paidAttempt->logs()->sole()->id);
        $this->assertSame(['source' => 'cash_pickup'], $log->metadata);
    }

    public function test_database_allows_multiple_attempts_but_prevents_two_successful_payments_for_one_order(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $order = $this->orderFor($customer);

        Payment::create([
            'order_id' => $order->id,
            'confirmed_order_id' => $order->id,
            'payment_number' => 'PAY-SUCCESS-001',
            'payment_method' => PaymentMethod::CASH_PICKUP,
            'amount' => $order->grand_total,
            'status' => PaymentStatus::PAID,
        ]);

        $this->expectException(QueryException::class);

        Payment::create([
            'order_id' => $order->id,
            'confirmed_order_id' => $order->id,
            'payment_number' => 'PAY-SUCCESS-002',
            'payment_method' => PaymentMethod::CASH_PICKUP,
            'amount' => $order->grand_total,
            'status' => PaymentStatus::PAID,
        ]);
    }

    private function orderFor(User $customer): Order
    {
        return Order::create([
            'order_number' => 'ORD-'.Str::upper(Str::random(12)),
            'checkout_token' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'fulfillment_method' => 'pickup',
            'recipient_name' => 'Pelanggan Test',
            'recipient_phone' => '08123456789',
            'subtotal' => '25000.00',
            'shipping_total' => '0.00',
            'grand_total' => '25000.00',
            'payment_status' => PaymentStatus::UNPAID,
            'order_status' => 'pending_payment',
        ]);
    }
}
