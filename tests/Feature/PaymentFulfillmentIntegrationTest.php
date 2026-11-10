<?php

namespace Tests\Feature;

use App\Models\BumdesBankAccount;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StoreOrder;
use App\Models\StoreOrderStatusLog;
use App\Models\User;
use App\Services\PaymentConfirmationService;
use App\Support\OrderFulfillmentStatus;
use App\Support\PaymentMethod;
use App\Support\PaymentStatus;
use App\Support\StoreOrderFulfillmentStatus;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PaymentFulfillmentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_bank_transfer_activates_fulfillment_for_every_store(): void
    {
        $admin = $this->admin();
        [$order, $storeOrders] = $this->orderWithStoreOrders(2, PaymentMethod::BANK_TRANSFER);
        $payment = $this->transferPayment($order, $admin);

        app(PaymentConfirmationService::class)->confirm($payment, $admin);

        $this->assertSame(PaymentStatus::PAID, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::PAID, $order->fresh()->payment_status);
        $this->assertSame(OrderFulfillmentStatus::IN_FULFILLMENT, $order->fresh()->order_status);
        $this->assertSame([
            StoreOrderFulfillmentStatus::AWAITING_PROCESSING,
            StoreOrderFulfillmentStatus::AWAITING_PROCESSING,
        ], $storeOrders->map(fn (StoreOrder $storeOrder) => $storeOrder->fresh()->fulfillment_status)->all());
        $this->assertDatabaseCount('store_order_status_logs', 2);
        $this->assertDatabaseHas('store_order_status_logs', [
            'store_order_id' => $storeOrders->first()->id,
            'actor_id' => $admin->id,
            'from_status' => StoreOrderFulfillmentStatus::PENDING_PAYMENT,
            'to_status' => StoreOrderFulfillmentStatus::AWAITING_PROCESSING,
        ]);
    }

    public function test_confirmed_cash_payment_activates_fulfillment_only_after_physical_receipt(): void
    {
        $admin = $this->admin();
        [$order, $storeOrders] = $this->orderWithStoreOrders(1, PaymentMethod::CASH_PICKUP);
        $payment = $this->cashPayment($order);

        app(PaymentConfirmationService::class)->confirm($payment, $admin);

        $this->assertSame(PaymentStatus::PAID, $order->fresh()->payment_status);
        $this->assertSame(OrderFulfillmentStatus::IN_FULFILLMENT, $order->fresh()->order_status);
        $this->assertSame(StoreOrderFulfillmentStatus::AWAITING_PROCESSING, $storeOrders->sole()->fresh()->fulfillment_status);
    }

    public function test_confirm_retry_does_not_duplicate_fulfillment_logs(): void
    {
        $admin = $this->admin();
        [$order] = $this->orderWithStoreOrders(1, PaymentMethod::CASH_PICKUP);
        $payment = $this->cashPayment($order);
        $service = app(PaymentConfirmationService::class);

        $service->confirm($payment, $admin);
        $service->confirm($payment->fresh(), $admin);

        $this->assertDatabaseCount('store_order_status_logs', 1);
        $this->assertDatabaseCount('payment_logs', 1);
    }

    public function test_rejected_or_unconfirmed_payment_does_not_activate_fulfillment(): void
    {
        $admin = $this->admin();
        [$pendingOrder, $pendingStoreOrders] = $this->orderWithStoreOrders(1, PaymentMethod::BANK_TRANSFER);
        $pendingPayment = $this->transferPayment($pendingOrder, $admin);
        [$rejectedOrder, $rejectedStoreOrders] = $this->orderWithStoreOrders(1, PaymentMethod::BANK_TRANSFER);
        $rejectedPayment = $this->transferPayment($rejectedOrder, $admin);

        app(PaymentConfirmationService::class)->reject($rejectedPayment, $admin, 'Bukti transfer tidak sesuai.');

        $this->assertSame(PaymentStatus::PENDING_CONFIRMATION, $pendingPayment->fresh()->status);
        $this->assertSame(OrderFulfillmentStatus::PENDING_PAYMENT, $pendingOrder->fresh()->order_status);
        $this->assertSame(StoreOrderFulfillmentStatus::PENDING_PAYMENT, $pendingStoreOrders->sole()->fresh()->fulfillment_status);
        $this->assertSame(PaymentStatus::REJECTED, $rejectedPayment->fresh()->status);
        $this->assertSame(PaymentStatus::UNPAID, $rejectedOrder->fresh()->payment_status);
        $this->assertSame(StoreOrderFulfillmentStatus::PENDING_PAYMENT, $rejectedStoreOrders->sole()->fresh()->fulfillment_status);
        $this->assertDatabaseCount('store_order_status_logs', 0);
    }

    public function test_fulfillment_failure_rolls_back_payment_and_every_store_order(): void
    {
        $admin = $this->admin();
        [$order, $storeOrders] = $this->orderWithStoreOrders(2, PaymentMethod::CASH_PICKUP);
        $payment = $this->cashPayment($order);
        $originalDispatcher = StoreOrderStatusLog::getEventDispatcher();

        StoreOrderStatusLog::setEventDispatcher(new Dispatcher($this->app));
        StoreOrderStatusLog::creating(static function (): void {
            throw new RuntimeException('Simulated fulfillment activation failure.');
        });

        try {
            app(PaymentConfirmationService::class)->confirm($payment, $admin);
            $this->fail('A fulfillment failure must roll back payment confirmation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated fulfillment activation failure.', $exception->getMessage());
        } finally {
            StoreOrderStatusLog::setEventDispatcher($originalDispatcher);
        }

        $this->assertSame(PaymentStatus::PENDING_CONFIRMATION, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::PENDING_CONFIRMATION, $order->fresh()->payment_status);
        $this->assertSame(OrderFulfillmentStatus::PENDING_PAYMENT, $order->fresh()->order_status);
        $this->assertSame([
            StoreOrderFulfillmentStatus::PENDING_PAYMENT,
            StoreOrderFulfillmentStatus::PENDING_PAYMENT,
        ], $storeOrders->map(fn (StoreOrder $storeOrder) => $storeOrder->fresh()->fulfillment_status)->all());
        $this->assertDatabaseCount('payment_logs', 0);
        $this->assertDatabaseCount('store_order_status_logs', 0);
    }

    public function test_payment_confirmation_does_not_change_stock_or_order_snapshots(): void
    {
        $admin = $this->admin();
        [$order, $storeOrders, $products] = $this->orderWithStoreOrders(1, PaymentMethod::BANK_TRANSFER);
        $payment = $this->transferPayment($order, $admin);
        $storeOrder = $storeOrders->sole();
        $item = $storeOrder->items()->sole();

        app(PaymentConfirmationService::class)->confirm($payment, $admin);

        $this->assertSame(10, $products->sole()->fresh()->stock);
        $this->assertSame('25000.00', $item->fresh()->unit_price);
        $this->assertSame('12.50', $storeOrder->fresh()->commission_percentage);
        $this->assertSame('3125.00', $storeOrder->fresh()->commission_amount);
        $this->assertSame('21875.00', $storeOrder->fresh()->umkm_amount);
        $this->assertSame(OrderFulfillmentStatus::IN_FULFILLMENT, $order->fresh()->order_status);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin_bumdes']);
    }

    /**
     * @return array{Order, \Illuminate\Database\Eloquent\Collection<int, StoreOrder>, \Illuminate\Database\Eloquent\Collection<int, Product>}
     */
    private function orderWithStoreOrders(int $count, string $paymentMethod): array
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $total = number_format(25000 * $count, 2, '.', '');
        $order = Order::create([
            'order_number' => 'ORD-'.Str::upper(Str::random(12)),
            'checkout_token' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'fulfillment_method' => 'pickup',
            'recipient_name' => 'Pelanggan Test',
            'recipient_phone' => '08123456789',
            'subtotal' => $total,
            'shipping_total' => '0.00',
            'grand_total' => $total,
            'payment_status' => PaymentStatus::PENDING_CONFIRMATION,
            'payment_method' => $paymentMethod,
            'order_status' => OrderFulfillmentStatus::PENDING_PAYMENT,
        ]);
        $storeOrders = collect();
        $products = collect();

        for ($index = 1; $index <= $count; $index++) {
            $owner = User::factory()->create(['role' => 'penjual']);
            $store = $owner->umkmStore()->create([
                'name' => "Toko {$index}",
                'slug' => 'toko-'.Str::lower(Str::random(12)),
            ]);
            $store->forceFill(['status' => 'approved'])->save();
            $category = Category::create([
                'name' => "Kategori {$index}",
                'slug' => 'kategori-'.Str::lower(Str::random(12)),
            ]);
            $product = $store->products()->create([
                'category_id' => $category->id,
                'sku' => 'SKU-'.Str::upper(Str::random(8)),
                'name' => "Produk {$index}",
                'slug' => 'produk-'.Str::lower(Str::random(12)),
                'price' => '25000.00',
                'stock' => 10,
            ]);
            $product->forceFill(['status' => 'approved'])->save();
            $storeOrder = $order->storeOrders()->create([
                'umkm_store_id' => $store->id,
                'store_order_number' => 'STO-'.Str::upper(Str::random(12)),
                'store_name_snapshot' => $store->name,
                'subtotal' => '25000.00',
                'shipping_amount' => '0.00',
                'total' => '25000.00',
                'fulfillment_status' => StoreOrderFulfillmentStatus::PENDING_PAYMENT,
                'commission_percentage' => '12.50',
                'commission_amount' => '3125.00',
                'umkm_amount' => '21875.00',
                'commission_status' => 'calculated',
            ]);
            $storeOrder->items()->create([
                'product_id' => $product->id,
                'product_name_snapshot' => $product->name,
                'sku_snapshot' => $product->sku,
                'unit_price' => '25000.00',
                'quantity' => 1,
                'line_total' => '25000.00',
            ]);
            $storeOrders->push($storeOrder);
            $products->push($product);
        }

        return [$order, $storeOrders, $products];
    }

    private function transferPayment(Order $order, User $admin): Payment
    {
        $account = BumdesBankAccount::create([
            'bank_name' => 'Bank BUMDes',
            'account_holder_name' => 'BUMDes Desa Buruan',
            'account_number' => Str::random(12),
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        return Payment::create([
            'order_id' => $order->id,
            'bumdes_bank_account_id' => $account->id,
            'payment_number' => 'PAY-'.Str::upper(Str::random(12)),
            'idempotency_key' => (string) Str::uuid(),
            'payment_method' => PaymentMethod::BANK_TRANSFER,
            'amount' => $order->grand_total,
            'status' => PaymentStatus::PENDING_CONFIRMATION,
            'payer_name' => 'Pelanggan Test',
            'transfer_reference' => 'REF-'.Str::upper(Str::random(8)),
        ]);
    }

    private function cashPayment(Order $order): Payment
    {
        return Payment::create([
            'order_id' => $order->id,
            'payment_number' => 'PAY-'.Str::upper(Str::random(12)),
            'idempotency_key' => (string) Str::uuid(),
            'payment_method' => PaymentMethod::CASH_PICKUP,
            'amount' => $order->grand_total,
            'status' => PaymentStatus::PENDING_CONFIRMATION,
            'cash_received_by_name' => 'Petugas BUMDes',
            'cash_received_at' => now()->subMinute(),
        ]);
    }
}
