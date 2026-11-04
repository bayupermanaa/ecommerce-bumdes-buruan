<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentLog;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\PaymentConfirmationService;
use App\Support\PaymentMethod;
use App\Support\PaymentStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class PaymentConfirmationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_confirm_a_valid_cash_payment_once(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $order = $this->order();
        $payment = $this->cashPayment($order);

        $confirmed = app(PaymentConfirmationService::class)->confirm($payment, $admin);

        $this->assertSame(PaymentStatus::PAID, $confirmed->status);
        $this->assertSame($order->id, $confirmed->confirmed_order_id);
        $this->assertSame($admin->id, $confirmed->confirmed_by);
        $this->assertSame(PaymentStatus::PAID, $order->fresh()->payment_status);
        $this->assertSame(PaymentMethod::CASH_PICKUP, $order->fresh()->payment_method);
        $this->assertSame($admin->id, $order->fresh()->payment_confirmed_by);
        $this->assertDatabaseHas('payment_logs', [
            'payment_id' => $payment->id,
            'action' => 'confirmed',
            'to_status' => PaymentStatus::PAID,
            'actor_id' => $admin->id,
        ]);
    }

    public function test_only_admin_bumdes_can_confirm_or_reject_payment(): void
    {
        $payment = $this->cashPayment($this->order());

        foreach (['pelanggan', 'pemilik_umkm', 'kurir', 'kepala_bumdes'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->assertFalse(Gate::forUser($user)->allows('confirm', $payment));
            $this->assertFalse(Gate::forUser($user)->allows('reject', $payment));
            try {
                app(PaymentConfirmationService::class)->confirm($payment, $user);
                $this->fail("Role {$role} must not confirm payments.");
            } catch (AuthorizationException) {
                $this->assertSame(PaymentStatus::PENDING_CONFIRMATION, $payment->fresh()->status);
            }
        }
    }

    public function test_cancelled_order_and_incorrect_amount_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $cancelledOrder = $this->order(['order_status' => 'cancelled']);
        $cancelledPayment = $this->cashPayment($cancelledOrder);

        $this->expectException(ValidationException::class);
        app(PaymentConfirmationService::class)->confirm($cancelledPayment, $admin);
    }

    public function test_payment_with_incorrect_amount_is_rejected_without_changes(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $order = $this->order();
        $payment = $this->cashPayment($order, ['amount' => '24999.99']);

        try {
            app(PaymentConfirmationService::class)->confirm($payment, $admin);
            $this->fail('Mismatched payment amount should be rejected.');
        } catch (ValidationException) {
            $this->assertSame(PaymentStatus::UNPAID, $order->fresh()->payment_status);
            $this->assertSame(PaymentStatus::PENDING_CONFIRMATION, $payment->fresh()->status);
            $this->assertDatabaseCount('payment_logs', 0);
        }
    }

    public function test_duplicate_successful_payment_is_prevented_and_retry_has_no_duplicate_log(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $order = $this->order();
        $firstPayment = $this->cashPayment($order);
        $secondPayment = $this->cashPayment($order);
        $service = app(PaymentConfirmationService::class);

        $service->confirm($firstPayment, $admin);
        $service->confirm($firstPayment->fresh(), $admin);

        $this->assertDatabaseCount('payment_logs', 1);

        try {
            $service->confirm($secondPayment, $admin);
            $this->fail('A second payment must not confirm the same order.');
        } catch (ValidationException) {
            $this->assertSame(PaymentStatus::PENDING_CONFIRMATION, $secondPayment->fresh()->status);
            $this->assertDatabaseCount('payment_logs', 1);
        }
    }

    public function test_rejection_records_reason_actor_and_keeps_order_unpaid(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $order = $this->order();
        $payment = $this->cashPayment($order);

        app(PaymentConfirmationService::class)->reject($payment, $admin, 'Nominal setoran belum sesuai.');

        $payment->refresh();
        $this->assertSame(PaymentStatus::REJECTED, $payment->status);
        $this->assertSame($admin->id, $payment->rejected_by);
        $this->assertNotNull($payment->rejected_at);
        $this->assertSame('Nominal setoran belum sesuai.', $payment->rejection_reason);
        $this->assertSame(PaymentStatus::UNPAID, $order->fresh()->payment_status);
        $this->assertDatabaseHas('payment_logs', [
            'payment_id' => $payment->id,
            'action' => 'rejected',
            'actor_id' => $admin->id,
        ]);
    }

    public function test_rejection_reopens_an_order_that_was_waiting_for_confirmation(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $order = $this->order(['payment_status' => PaymentStatus::PENDING_CONFIRMATION]);
        $payment = $this->cashPayment($order);

        app(PaymentConfirmationService::class)->reject($payment, $admin, 'Bukti pembayaran tidak dapat diverifikasi.');

        $this->assertSame(PaymentStatus::UNPAID, $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->payment_method);
    }

    public function test_transaction_failure_rolls_back_payment_order_and_log_changes(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $order = $this->order();
        $payment = $this->cashPayment($order);
        $originalDispatcher = PaymentLog::getEventDispatcher();

        PaymentLog::setEventDispatcher(new Dispatcher($this->app));
        PaymentLog::creating(static function (): void {
            throw new RuntimeException('Simulated payment-log failure.');
        });

        try {
            app(PaymentConfirmationService::class)->confirm($payment, $admin);
            $this->fail('The simulated log failure should stop confirmation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated payment-log failure.', $exception->getMessage());
        } finally {
            PaymentLog::setEventDispatcher($originalDispatcher);
        }

        $this->assertSame(PaymentStatus::UNPAID, $order->fresh()->payment_status);
        $this->assertSame(PaymentStatus::PENDING_CONFIRMATION, $payment->fresh()->status);
        $this->assertNull($payment->fresh()->confirmed_order_id);
        $this->assertDatabaseCount('payment_logs', 0);
    }

    public function test_confirmation_does_not_change_store_order_commission_snapshot(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $order = $this->order();
        $storeOrder = $this->storeOrder($order);
        $payment = $this->cashPayment($order);

        app(PaymentConfirmationService::class)->confirm($payment, $admin);

        $storeOrder->refresh();
        $this->assertSame('12.50', $storeOrder->commission_percentage);
        $this->assertSame('3125.00', $storeOrder->commission_amount);
        $this->assertSame('21875.00', $storeOrder->umkm_amount);
        $this->assertSame('calculated', $storeOrder->commission_status);
    }

    public function test_cash_payment_requires_physical_receiver_and_received_time(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $order = $this->order();
        $payment = $this->cashPayment($order, [
            'cash_received_by_name' => null,
            'cash_received_at' => null,
        ]);

        $this->expectException(ValidationException::class);
        app(PaymentConfirmationService::class)->confirm($payment, $admin);
    }

    public function test_bank_transfer_requires_active_official_account_and_transfer_identity(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $order = $this->order();
        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_number' => 'PAY-'.Str::upper(Str::random(12)),
            'payment_method' => PaymentMethod::BANK_TRANSFER,
            'amount' => $order->grand_total,
            'status' => PaymentStatus::PENDING_CONFIRMATION,
        ]);

        $this->expectException(ValidationException::class);
        app(PaymentConfirmationService::class)->confirm($payment, $admin);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function order(array $overrides = []): Order
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);

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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function cashPayment(Order $order, array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'order_id' => $order->id,
            'payment_number' => 'PAY-'.Str::upper(Str::random(12)),
            'idempotency_key' => (string) Str::uuid(),
            'payment_method' => PaymentMethod::CASH_PICKUP,
            'amount' => $order->grand_total,
            'status' => PaymentStatus::PENDING_CONFIRMATION,
            'cash_received_by_name' => 'Petugas Penerima BUMDes',
            'cash_received_at' => now(),
        ], $overrides));
    }

    private function storeOrder(Order $order): StoreOrder
    {
        $owner = User::factory()->create(['role' => 'pemilik_umkm']);
        $store = $owner->umkmStore()->create([
            'name' => 'Toko Snapshot',
            'slug' => fake()->unique()->slug(),
        ]);

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
