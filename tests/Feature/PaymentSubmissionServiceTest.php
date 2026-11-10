<?php

namespace Tests\Feature;

use App\Models\BumdesBankAccount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\PaymentConfirmationService;
use App\Services\PaymentSubmissionService;
use App\Support\PaymentMethod;
use App\Support\PaymentStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentSubmissionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_only_open_the_payment_form_for_their_own_order(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $ownOrder = $this->order($customer);
        $otherOrder = $this->order(User::factory()->create(['role' => 'pelanggan']));
        $this->bankAccount();

        $this->actingAs($customer)
            ->get(route('orders.payments.create', $ownOrder))
            ->assertOk()
            ->assertSee('Pengajuan Pembayaran')
            ->assertSee('name="transfer_proof"', false)
            ->assertSee('enctype="multipart/form-data"', false);

        $this->actingAs($customer)
            ->get(route('orders.payments.create', $otherOrder))
            ->assertForbidden();
    }

    public function test_customer_can_submit_a_valid_bank_transfer_to_an_active_account(): void
    {
        Storage::fake('local');
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $order = $this->order($customer);
        $account = $this->bankAccount();

        $payment = app(PaymentSubmissionService::class)->submitBankTransfer(
            $order,
            $customer,
            $this->transferData($account),
            UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf'),
        );

        $this->assertSame(PaymentStatus::PENDING_CONFIRMATION, $payment->status);
        $this->assertSame(PaymentMethod::BANK_TRANSFER, $payment->payment_method);
        $this->assertSame('25000.00', $payment->amount);
        $this->assertSame(PaymentStatus::PENDING_CONFIRMATION, $order->fresh()->payment_status);
        $this->assertSame(PaymentMethod::BANK_TRANSFER, $order->fresh()->payment_method);
        $this->assertNotNull($payment->transfer_proof_path);
        Storage::disk('local')->assertExists($payment->transfer_proof_path);
        $this->assertDatabaseHas('payment_logs', [
            'payment_id' => $payment->id,
            'actor_id' => $customer->id,
            'action' => 'submitted',
            'to_status' => PaymentStatus::PENDING_CONFIRMATION,
        ]);
    }

    public function test_new_seller_can_submit_payment_for_own_purchase_only(): void
    {
        Storage::fake('local');
        $seller = User::factory()->create(['role' => 'penjual']);
        $ownOrder = $this->order($seller);
        $otherOrder = $this->order(User::factory()->create(['role' => 'pelanggan']));
        $account = $this->bankAccount();

        $payment = app(PaymentSubmissionService::class)->submitBankTransfer(
            $ownOrder,
            $seller,
            $this->transferData($account),
            UploadedFile::fake()->create('seller-proof.pdf', 100, 'application/pdf'),
        );

        $this->assertSame(PaymentStatus::PENDING_CONFIRMATION, $payment->status);
        $this->assertSame($seller->id, $ownOrder->fresh()->user_id);
        $this->expectException(AuthorizationException::class);
        app(PaymentSubmissionService::class)->submitCashPickup($otherOrder, $seller, (string) Str::uuid());
    }

    public function test_customer_cannot_submit_payment_for_another_customers_order(): void
    {
        $order = $this->order(User::factory()->create(['role' => 'pelanggan']));
        $otherCustomer = User::factory()->create(['role' => 'pelanggan']);

        $this->expectException(AuthorizationException::class);
        app(PaymentSubmissionService::class)->submitCashPickup($order, $otherCustomer, (string) Str::uuid());
    }

    public function test_cancelled_or_paid_order_cannot_receive_a_payment_submission(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);

        foreach ([
            ['order_status' => 'cancelled'],
            ['payment_status' => PaymentStatus::PAID],
        ] as $state) {
            $order = $this->order($customer, $state);

            try {
                app(PaymentSubmissionService::class)->submitCashPickup($order, $customer, (string) Str::uuid());
                $this->fail('A cancelled or paid order must reject payment submission.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('payments', 0);
            }
        }
    }

    public function test_double_submit_with_the_same_token_creates_only_one_payment_and_one_proof(): void
    {
        Storage::fake('local');
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $order = $this->order($customer);
        $account = $this->bankAccount();
        $data = $this->transferData($account);
        $service = app(PaymentSubmissionService::class);

        $first = $service->submitBankTransfer($order, $customer, $data, UploadedFile::fake()->create('first.pdf', 100, 'application/pdf'));
        $second = $service->submitBankTransfer($order, $customer, $data, UploadedFile::fake()->create('second.pdf', 100, 'application/pdf'));

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_logs', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('payment-proofs'));
    }

    public function test_bank_transfer_requires_an_active_account_and_valid_proof(): void
    {
        Storage::fake('local');
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $order = $this->order($customer);
        $inactiveAccount = $this->bankAccount(['status' => 'inactive']);
        $service = app(PaymentSubmissionService::class);

        try {
            $service->submitBankTransfer($order, $customer, $this->transferData($inactiveAccount), UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf'));
            $this->fail('An inactive account must not accept submissions.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('payments', 0);
        }

        $activeAccount = $this->bankAccount();
        $this->expectException(ValidationException::class);
        $service->submitBankTransfer($order, $customer, $this->transferData($activeAccount), UploadedFile::fake()->create('proof.txt', 100, 'text/plain'));
    }

    public function test_pending_payment_blocks_cancellation_and_cash_pickup_is_not_paid_or_received(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $order = $this->order($customer);

        $payment = app(PaymentSubmissionService::class)->submitCashPickup($order, $customer, (string) Str::uuid());

        $this->assertSame(PaymentStatus::PENDING_CONFIRMATION, $payment->status);
        $this->assertNull($payment->cash_received_by_name);
        $this->assertNull($payment->cash_received_at);
        $this->assertFalse($order->fresh()->canBeCancelled());
    }

    public function test_customer_can_resubmit_with_a_new_payment_after_rejection_without_changing_commission_snapshot(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $order = $this->order($customer);
        $storeOrder = $this->storeOrder($order);
        $service = app(PaymentSubmissionService::class);
        $first = $service->submitCashPickup($order, $customer, (string) Str::uuid());

        app(PaymentConfirmationService::class)->reject($first, $admin, 'Pembayaran belum diterima di loket.');
        $second = $service->submitCashPickup($order->fresh(), $customer, (string) Str::uuid());

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(PaymentStatus::REJECTED, $first->fresh()->status);
        $this->assertSame(PaymentStatus::PENDING_CONFIRMATION, $second->status);
        $this->assertSame(PaymentStatus::PENDING_CONFIRMATION, $order->fresh()->payment_status);
        $this->assertSame('12.50', $storeOrder->fresh()->commission_percentage);
        $this->assertSame('3125.00', $storeOrder->fresh()->commission_amount);
        $this->assertSame('21875.00', $storeOrder->fresh()->umkm_amount);
    }

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

    private function bankAccount(array $overrides = []): BumdesBankAccount
    {
        return BumdesBankAccount::create(array_merge([
            'bank_name' => 'Bank BUMDes',
            'account_holder_name' => 'BUMDes Desa Buruan',
            'account_number' => Str::random(12),
            'status' => 'active',
        ], $overrides));
    }

    /**
     * @return array{bumdes_bank_account_id: int, payer_name: string, transfer_reference: string, idempotency_key: string}
     */
    private function transferData(BumdesBankAccount $account): array
    {
        return [
            'bumdes_bank_account_id' => $account->id,
            'payer_name' => 'Pelanggan Test',
            'transfer_reference' => 'REF-'.Str::upper(Str::random(8)),
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    private function storeOrder(Order $order): StoreOrder
    {
        $owner = User::factory()->create(['role' => 'penjual']);
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
