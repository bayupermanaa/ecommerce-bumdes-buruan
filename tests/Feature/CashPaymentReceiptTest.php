<?php

namespace Tests\Feature;

use App\Models\BumdesBankAccount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\PaymentSubmissionService;
use App\Support\PaymentMethod;
use App\Support\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashPaymentReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_record_valid_cash_receipt_without_marking_payment_paid(): void
    {
        $admin = $this->admin();
        $payment = $this->cashPayment();
        $storeOrder = $this->storeOrder($payment->order);

        $this->actingAs($admin)
            ->patch(route('admin.payments.cash-receipt', $payment), $this->receiptData())
            ->assertRedirect(route('admin.payments.show', $payment));

        $payment->refresh();
        $this->assertSame('Petugas Loket BUMDes', $payment->cash_received_by_name);
        $this->assertNotNull($payment->cash_received_at);
        $this->assertSame(PaymentStatus::PENDING_CONFIRMATION, $payment->status);
        $this->assertSame(PaymentStatus::PENDING_CONFIRMATION, $payment->order->fresh()->payment_status);
        $this->assertDatabaseHas('payment_logs', ['payment_id' => $payment->id, 'action' => 'cash_received', 'actor_id' => $admin->id]);
        $this->assertSame('12.50', $storeOrder->fresh()->commission_percentage);
        $this->assertSame('3125.00', $storeOrder->fresh()->commission_amount);
        $this->assertSame('21875.00', $storeOrder->fresh()->umkm_amount);
    }

    public function test_only_admin_can_record_cash_receipt(): void
    {
        $payment = $this->cashPayment();

        foreach (['pelanggan', 'penjual', 'kurir', 'kepala_bumdes'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->patch(route('admin.payments.cash-receipt', $payment), $this->receiptData())
                ->assertForbidden();
        }
    }

    public function test_cash_receipt_requires_cash_payment_valid_fields_and_non_future_time(): void
    {
        $admin = $this->admin();
        $cashPayment = $this->cashPayment();

        $this->actingAs($admin)
            ->patch(route('admin.payments.cash-receipt', $cashPayment), [])
            ->assertSessionHasErrors(['cash_received_by_name', 'cash_received_at', 'cash_received_confirmation']);

        $this->actingAs($admin)
            ->patch(route('admin.payments.cash-receipt', $cashPayment), $this->receiptData(['cash_received_at' => now('Asia/Makassar')->addMinutes(2)->format('Y-m-d\TH:i')]))
            ->assertSessionHasErrors('cash_received_at');

        $transfer = $this->transferPayment();
        $this->actingAs($admin)
            ->patch(route('admin.payments.cash-receipt', $transfer), $this->receiptData())
            ->assertForbidden();
    }

    public function test_wita_cash_receipt_is_compared_as_utc_and_stored_without_changing_payment_status(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-12 00:30:00', 'UTC'));
        $admin = $this->admin();
        $payment = $this->cashPayment();

        $this->actingAs($admin)->patch(route('admin.payments.cash-receipt', $payment), $this->receiptData([
            'cash_received_at' => '2026-11-12T08:15',
        ]))->assertRedirect(route('admin.payments.show', $payment));

        $this->assertSame('2026-11-12 00:15:00', $payment->fresh()->cash_received_at->format('Y-m-d H:i:s'));
        $this->assertSame(PaymentStatus::PENDING_CONFIRMATION, $payment->fresh()->status);
    }

    public function test_future_wita_cash_receipt_is_rejected_without_financial_write(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-12 00:30:00', 'UTC'));
        $payment = $this->cashPayment();

        $this->actingAs($this->admin())->patch(route('admin.payments.cash-receipt', $payment), $this->receiptData([
            'cash_received_at' => '2026-11-12T08:31',
        ]))->assertSessionHasErrors('cash_received_at');

        $this->assertNull($payment->fresh()->cash_received_at);
        $this->assertSame(PaymentStatus::PENDING_CONFIRMATION, $payment->fresh()->status);
    }

    public function test_cancelled_paid_and_duplicate_cash_receipts_are_rejected(): void
    {
        $admin = $this->admin();
        $cancelled = $this->cashPayment(['order_status' => 'cancelled']);
        $paid = $this->cashPayment(['payment_status' => PaymentStatus::PAID]);

        $this->actingAs($admin)->patch(route('admin.payments.cash-receipt', $cancelled), $this->receiptData())->assertForbidden();
        $this->actingAs($admin)->patch(route('admin.payments.cash-receipt', $paid), $this->receiptData())->assertForbidden();

        $payment = $this->cashPayment();
        $this->actingAs($admin)->patch(route('admin.payments.cash-receipt', $payment), $this->receiptData())->assertRedirect();
        $this->actingAs($admin)->patch(route('admin.payments.cash-receipt', $payment), $this->receiptData())->assertForbidden();
        $this->assertDatabaseCount('payment_logs', 1);
    }

    public function test_cash_requires_receipt_before_confirmation_and_confirms_after_receipt(): void
    {
        $admin = $this->admin();
        $payment = $this->cashPayment();

        $this->actingAs($admin)->patch(route('admin.payments.confirm', $payment))->assertForbidden();
        $this->actingAs($admin)->patch(route('admin.payments.cash-receipt', $payment), $this->receiptData())->assertRedirect();
        $this->actingAs($admin)->patch(route('admin.payments.confirm', $payment))->assertRedirect(route('admin.payments.show', $payment));

        $this->assertSame(PaymentStatus::PAID, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::PAID, $payment->order->fresh()->payment_status);
        $this->assertDatabaseCount('payment_logs', 2);
    }

    public function test_cash_can_be_rejected_before_receipt_but_not_after_receipt(): void
    {
        $admin = $this->admin();
        $unreceived = $this->cashPayment();

        $this->actingAs($admin)
            ->patch(route('admin.payments.reject', $unreceived), ['rejection_reason' => 'Pelanggan membatalkan pilihan tunai.'])
            ->assertRedirect();
        $this->assertSame(PaymentStatus::REJECTED, $unreceived->fresh()->status);
        $this->assertSame(PaymentStatus::UNPAID, $unreceived->order->fresh()->payment_status);

        $received = $this->cashPayment();
        $this->actingAs($admin)->patch(route('admin.payments.cash-receipt', $received), $this->receiptData())->assertRedirect();
        $this->actingAs($admin)
            ->patch(route('admin.payments.reject', $received), ['rejection_reason' => 'Tidak boleh ditolak biasa.'])
            ->assertForbidden();
    }

    public function test_historical_transfer_to_inactive_account_can_be_confirmed_but_new_submission_cannot_use_it(): void
    {
        $admin = $this->admin();
        $payment = $this->transferPayment(['account_status' => 'inactive']);

        $this->actingAs($admin)->patch(route('admin.payments.confirm', $payment))->assertRedirect();
        $this->assertSame(PaymentStatus::PAID, $payment->fresh()->status);

        $customer = User::factory()->create(['role' => 'pelanggan']);
        $order = $this->order($customer);
        try {
            app(PaymentSubmissionService::class)->submitBankTransfer($order, $customer, [
                'bumdes_bank_account_id' => $payment->bumdes_bank_account_id,
                'payer_name' => 'Pelanggan Baru',
                'transfer_reference' => 'REF-BARU',
                'idempotency_key' => (string) Str::uuid(),
            ], UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf'));
            $this->fail('A new transfer must not use an inactive account.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertDatabaseCount('payments', 1);
        }
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin_bumdes']);
    }

    /** @param array<string, mixed> $overrides */
    private function cashPayment(array $overrides = []): Payment
    {
        $orderOverrides = [];
        if (isset($overrides['order_status'])) {
            $orderOverrides['order_status'] = $overrides['order_status'];
            unset($overrides['order_status']);
        }
        if (isset($overrides['payment_status'])) {
            $orderOverrides['payment_status'] = $overrides['payment_status'];
            unset($overrides['payment_status']);
        }
        $order = $this->order(User::factory()->create(['role' => 'pelanggan']), array_merge([
            'payment_status' => PaymentStatus::PENDING_CONFIRMATION,
            'payment_method' => PaymentMethod::CASH_PICKUP,
        ], $orderOverrides));

        return Payment::create(array_merge([
            'order_id' => $order->id,
            'payment_number' => 'PAY-'.Str::upper(Str::random(12)),
            'idempotency_key' => (string) Str::uuid(),
            'payment_method' => PaymentMethod::CASH_PICKUP,
            'amount' => $order->grand_total,
            'status' => PaymentStatus::PENDING_CONFIRMATION,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function transferPayment(array $overrides = []): Payment
    {
        $accountStatus = $overrides['account_status'] ?? 'active';
        unset($overrides['account_status']);
        $order = $this->order(User::factory()->create(['role' => 'pelanggan']), [
            'payment_status' => PaymentStatus::PENDING_CONFIRMATION,
            'payment_method' => PaymentMethod::BANK_TRANSFER,
        ]);
        $account = BumdesBankAccount::create([
            'bank_name' => 'Bank BUMDes',
            'account_holder_name' => 'BUMDes Desa Buruan',
            'account_number' => Str::random(12),
            'status' => $accountStatus,
        ]);

        return Payment::create(array_merge([
            'order_id' => $order->id,
            'bumdes_bank_account_id' => $account->id,
            'payment_number' => 'PAY-'.Str::upper(Str::random(12)),
            'idempotency_key' => (string) Str::uuid(),
            'payment_method' => PaymentMethod::BANK_TRANSFER,
            'amount' => $order->grand_total,
            'status' => PaymentStatus::PENDING_CONFIRMATION,
            'payer_name' => 'Pelanggan Test',
            'transfer_reference' => 'REF-TEST',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function receiptData(array $overrides = []): array
    {
        return array_merge([
            'cash_received_by_name' => 'Petugas Loket BUMDes',
            'cash_received_at' => now('Asia/Makassar')->subMinute()->format('Y-m-d\TH:i'),
            'cash_received_confirmation' => '1',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function order(User $customer, array $overrides = []): Order
    {
        return Order::create(array_merge([
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
        ], $overrides));
    }

    private function storeOrder(Order $order): StoreOrder
    {
        $owner = User::factory()->create(['role' => 'penjual']);
        $store = $owner->umkmStore()->create(['name' => 'Toko Snapshot', 'slug' => fake()->unique()->slug()]);

        return StoreOrder::create([
            'order_id' => $order->id,
            'umkm_store_id' => $store->id,
            'store_order_number' => 'STO-'.Str::upper(Str::random(12)),
            'store_name_snapshot' => 'Toko Snapshot',
            'subtotal' => '25000.00',
            'shipping_amount' => '0.00',
            'total' => '25000.00',
            'fulfillment_status' => 'pending_payment',
            'commission_percentage' => '12.50',
            'commission_amount' => '3125.00',
            'umkm_amount' => '21875.00',
            'commission_status' => 'calculated',
        ]);
    }
}
