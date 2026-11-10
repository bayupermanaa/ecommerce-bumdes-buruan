<?php

namespace Tests\Feature;

use App\Models\BumdesBankAccount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentLog;
use App\Models\StoreOrder;
use App\Models\User;
use App\Support\PaymentMethod;
use App\Support\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminPaymentVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_filter_search_paginate_and_view_payment_detail(): void
    {
        $admin = $this->admin();
        $target = $this->transferPayment();
        $storeOrder = $this->storeOrder($target->order);

        for ($index = 0; $index < 16; $index++) {
            $this->cashPayment();
        }

        $this->actingAs($admin)
            ->get(route('admin.payments.index', ['status' => PaymentStatus::PENDING_CONFIRMATION, 'method' => PaymentMethod::BANK_TRANSFER, 'search' => $target->order->order_number]))
            ->assertOk()
            ->assertSee($target->order->order_number)
            ->assertDontSee('Tunai Pickup');

        $this->actingAs($admin)
            ->get(route('admin.payments.index'))
            ->assertOk()
            ->assertSee('Pagination Navigation');

        $this->actingAs($admin)
            ->get(route('admin.payments.show', $target))
            ->assertOk()
            ->assertSee($target->order->order_number)
            ->assertSee($storeOrder->store_name_snapshot)
            ->assertSee('Audit Payment');
    }

    public function test_admin_can_access_only_the_private_proof_belonging_to_the_requested_payment(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $payment = $this->transferPayment(['transfer_proof_path' => 'payment-proofs/123e4567-e89b-12d3-a456-426614174000.pdf']);
        Storage::disk('local')->put($payment->transfer_proof_path, 'private-proof');

        $this->actingAs($admin)
            ->get(route('admin.payments.proof', $payment))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $payment->forceFill(['transfer_proof_path' => '../.env'])->save();
        $this->actingAs($admin)
            ->get(route('admin.payments.proof', $payment))
            ->assertNotFound();
    }

    public function test_non_admin_roles_cannot_access_payment_dashboard_or_proof(): void
    {
        Storage::fake('local');
        $payment = $this->transferPayment(['transfer_proof_path' => 'payment-proofs/123e4567-e89b-12d3-a456-426614174000.pdf']);
        Storage::disk('local')->put($payment->transfer_proof_path, 'private-proof');

        $this->get(route('admin.payments.index'))->assertRedirect(route('login'));

        foreach (['pelanggan', 'penjual', 'kurir', 'kepala_bumdes'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get(route('admin.payments.index'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.payments.proof', $payment))->assertForbidden();
        }
    }

    public function test_admin_can_confirm_a_valid_transfer_once_without_changing_commission_snapshot(): void
    {
        $admin = $this->admin();
        $payment = $this->transferPayment();
        $storeOrder = $this->storeOrder($payment->order);

        $this->actingAs($admin)
            ->patch(route('admin.payments.confirm', $payment))
            ->assertRedirect(route('admin.payments.show', $payment));

        $this->assertSame(PaymentStatus::PAID, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::PAID, $payment->order->fresh()->payment_status);
        $this->assertDatabaseCount('payment_logs', 1);
        $this->assertSame('12.50', $storeOrder->fresh()->commission_percentage);
        $this->assertSame('3125.00', $storeOrder->fresh()->commission_amount);
        $this->assertSame('21875.00', $storeOrder->fresh()->umkm_amount);

        $this->actingAs($admin)->patch(route('admin.payments.confirm', $payment))->assertRedirect();
        $this->assertDatabaseCount('payment_logs', 1);
    }

    public function test_invalid_or_cancelled_transfer_cannot_be_confirmed(): void
    {
        $admin = $this->admin();
        $invalid = $this->transferPayment(['amount' => '999.00']);
        $cancelled = $this->transferPayment(['order_status' => 'cancelled']);

        $this->actingAs($admin)
            ->patch(route('admin.payments.confirm', $invalid))
            ->assertSessionHasErrors('amount');
        $this->assertSame(PaymentStatus::PENDING_CONFIRMATION, $invalid->fresh()->status);

        $this->actingAs($admin)
            ->patch(route('admin.payments.confirm', $cancelled))
            ->assertForbidden();
    }

    public function test_admin_can_reject_transfer_but_reason_is_required_and_history_is_preserved(): void
    {
        $admin = $this->admin();
        $payment = $this->transferPayment();

        $this->actingAs($admin)
            ->patch(route('admin.payments.reject', $payment), ['rejection_reason' => ''])
            ->assertSessionHasErrors('rejection_reason');

        $this->actingAs($admin)
            ->patch(route('admin.payments.reject', $payment), ['rejection_reason' => 'Nominal belum ditemukan di rekening BUMDes.'])
            ->assertRedirect(route('admin.payments.show', $payment));

        $this->assertSame(PaymentStatus::REJECTED, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::UNPAID, $payment->order->fresh()->payment_status);
        $this->assertDatabaseHas('payment_logs', ['payment_id' => $payment->id, 'action' => 'rejected']);
    }

    public function test_cash_pickup_without_physical_receipt_has_no_verification_action(): void
    {
        $admin = $this->admin();
        $payment = $this->cashPayment();

        $this->actingAs($admin)
            ->get(route('admin.payments.show', $payment))
            ->assertOk()
            ->assertSee('Menunggu Penerimaan Tunai')
            ->assertDontSee('Konfirmasi Pembayaran');

        $this->actingAs($admin)
            ->patch(route('admin.payments.confirm', $payment))
            ->assertForbidden();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin_bumdes']);
    }

    /** @param array<string, mixed> $overrides */
    private function transferPayment(array $overrides = []): Payment
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $order = $this->order($customer, [
            'payment_status' => PaymentStatus::PENDING_CONFIRMATION,
            'payment_method' => PaymentMethod::BANK_TRANSFER,
        ]);
        $account = BumdesBankAccount::create([
            'bank_name' => 'Bank BUMDes',
            'account_holder_name' => 'BUMDes Desa Buruan',
            'account_number' => Str::random(12),
            'status' => 'active',
        ]);

        $paymentAttributes = array_merge([
            'order_id' => $order->id,
            'bumdes_bank_account_id' => $account->id,
            'payment_number' => 'PAY-'.Str::upper(Str::random(12)),
            'idempotency_key' => (string) Str::uuid(),
            'payment_method' => PaymentMethod::BANK_TRANSFER,
            'amount' => $order->grand_total,
            'status' => PaymentStatus::PENDING_CONFIRMATION,
            'payer_name' => 'Pelanggan Test',
            'transfer_reference' => 'REF-TEST',
        ], $overrides);

        if (array_key_exists('order_status', $paymentAttributes)) {
            $order->forceFill(['order_status' => $paymentAttributes['order_status']])->save();
            unset($paymentAttributes['order_status']);
        }

        return Payment::create($paymentAttributes);
    }

    private function cashPayment(): Payment
    {
        $order = $this->order(User::factory()->create(['role' => 'pelanggan']), [
            'payment_status' => PaymentStatus::PENDING_CONFIRMATION,
            'payment_method' => PaymentMethod::CASH_PICKUP,
        ]);

        return Payment::create([
            'order_id' => $order->id,
            'payment_number' => 'PAY-'.Str::upper(Str::random(12)),
            'idempotency_key' => (string) Str::uuid(),
            'payment_method' => PaymentMethod::CASH_PICKUP,
            'amount' => $order->grand_total,
            'status' => PaymentStatus::PENDING_CONFIRMATION,
        ]);
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
