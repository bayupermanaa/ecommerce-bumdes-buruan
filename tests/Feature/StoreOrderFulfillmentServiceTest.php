<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreOrder;
use App\Models\StoreOrderStatusLog;
use App\Models\User;
use App\Services\StoreOrderFulfillmentService;
use App\Support\OrderFulfillmentStatus;
use App\Support\PaymentStatus;
use App\Support\StoreOrderFulfillmentStatus;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class StoreOrderFulfillmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_advance_a_paid_store_order_through_valid_statuses(): void
    {
        [$order, $storeOrder, $owner] = $this->paidStoreOrder(StoreOrderFulfillmentStatus::AWAITING_PROCESSING);

        $this->service()->transition($storeOrder, $owner, StoreOrderFulfillmentStatus::PROCESSING, 'Produk mulai disiapkan.');
        $this->assertSame(StoreOrderFulfillmentStatus::PROCESSING, $storeOrder->fresh()->fulfillment_status);
        $this->assertSame(OrderFulfillmentStatus::IN_FULFILLMENT, $order->fresh()->order_status);

        $this->service()->transition($storeOrder, $owner, StoreOrderFulfillmentStatus::READY_FOR_PICKUP);
        $this->service()->transition($storeOrder, $owner, StoreOrderFulfillmentStatus::COMPLETED);

        $this->assertSame(StoreOrderFulfillmentStatus::COMPLETED, $storeOrder->fresh()->fulfillment_status);
        $this->assertSame(OrderFulfillmentStatus::COMPLETED, $order->fresh()->order_status);
        $this->assertDatabaseCount('store_order_status_logs', 3);
    }

    public function test_non_sequential_transition_is_rejected(): void
    {
        [, $storeOrder, $owner] = $this->paidStoreOrder(StoreOrderFulfillmentStatus::AWAITING_PROCESSING);

        $this->expectException(ValidationException::class);
        $this->service()->transition($storeOrder, $owner, StoreOrderFulfillmentStatus::READY_FOR_PICKUP);
    }

    public function test_unpaid_order_cannot_be_processed(): void
    {
        [, $storeOrder, $owner] = $this->paidStoreOrder(
            StoreOrderFulfillmentStatus::AWAITING_PROCESSING,
            PaymentStatus::UNPAID,
        );

        try {
            $this->service()->transition($storeOrder, $owner, StoreOrderFulfillmentStatus::PROCESSING);
            $this->fail('An unpaid order must not enter fulfillment.');
        } catch (ValidationException) {
            $this->assertSame(StoreOrderFulfillmentStatus::AWAITING_PROCESSING, $storeOrder->fresh()->fulfillment_status);
            $this->assertDatabaseCount('store_order_status_logs', 0);
        }
    }

    public function test_only_the_store_owner_can_view_or_manage_a_store_order(): void
    {
        [, $storeOrder, $owner] = $this->paidStoreOrder(StoreOrderFulfillmentStatus::AWAITING_PROCESSING);
        $otherOwner = User::factory()->create(['role' => 'pemilik_umkm']);
        $otherOwner->umkmStore()->create(['name' => 'Toko Lain', 'slug' => 'toko-lain-'.Str::lower(Str::random(6))]);

        $this->assertTrue(Gate::forUser($owner)->allows('view', $storeOrder));
        $this->assertTrue(Gate::forUser($owner)->allows('update', $storeOrder));
        $this->assertFalse(Gate::forUser($otherOwner)->allows('view', $storeOrder));
        $this->assertFalse(Gate::forUser($otherOwner)->allows('update', $storeOrder));

        foreach (['pelanggan', 'admin_bumdes', 'kurir', 'kepala_bumdes'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->assertFalse(Gate::forUser($user)->allows('update', $storeOrder));
        }
    }

    public function test_repeated_transition_is_idempotent_and_does_not_duplicate_the_log(): void
    {
        [, $storeOrder, $owner] = $this->paidStoreOrder(StoreOrderFulfillmentStatus::AWAITING_PROCESSING);

        $this->service()->transition($storeOrder, $owner, StoreOrderFulfillmentStatus::PROCESSING);
        $this->service()->transition($storeOrder->fresh(), $owner, StoreOrderFulfillmentStatus::PROCESSING);

        $this->assertSame(StoreOrderFulfillmentStatus::PROCESSING, $storeOrder->fresh()->fulfillment_status);
        $this->assertDatabaseCount('store_order_status_logs', 1);
    }

    public function test_completed_and_cancelled_store_orders_cannot_be_changed(): void
    {
        [, $completed, $owner] = $this->paidStoreOrder(StoreOrderFulfillmentStatus::COMPLETED);
        [, $cancelled, $cancelledOwner] = $this->paidStoreOrder(StoreOrderFulfillmentStatus::CANCELLED);

        foreach ([[$completed, $owner], [$cancelled, $cancelledOwner]] as [$storeOrder, $storeOwner]) {
            try {
                $this->service()->transition($storeOrder, $storeOwner, StoreOrderFulfillmentStatus::PROCESSING);
                $this->fail('A final fulfillment state must not change.');
            } catch (ValidationException) {
                $this->assertDatabaseMissing('store_order_status_logs', ['store_order_id' => $storeOrder->id]);
            }
        }
    }

    public function test_multi_store_order_is_not_completed_until_every_store_order_is_completed(): void
    {
        [$order, $firstStoreOrder, $firstOwner] = $this->paidStoreOrder(StoreOrderFulfillmentStatus::AWAITING_PROCESSING);
        [$secondStoreOrder, $secondOwner] = $this->paidStoreOrderForOrder($order, StoreOrderFulfillmentStatus::AWAITING_PROCESSING);

        $this->advanceToCompleted($firstStoreOrder, $firstOwner);
        $this->assertSame(OrderFulfillmentStatus::IN_FULFILLMENT, $order->fresh()->order_status);

        $this->advanceToCompleted($secondStoreOrder, $secondOwner);
        $this->assertSame(OrderFulfillmentStatus::COMPLETED, $order->fresh()->order_status);
    }

    public function test_fulfillment_preserves_stock_price_and_commission_snapshots(): void
    {
        [$order, $storeOrder, $owner, $product] = $this->paidStoreOrder(StoreOrderFulfillmentStatus::AWAITING_PROCESSING);
        $item = $storeOrder->items()->sole();

        $this->service()->transition($storeOrder, $owner, StoreOrderFulfillmentStatus::PROCESSING);

        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame('25000.00', $item->fresh()->unit_price);
        $this->assertSame('12.50', $storeOrder->fresh()->commission_percentage);
        $this->assertSame('3125.00', $storeOrder->fresh()->commission_amount);
        $this->assertSame('21875.00', $storeOrder->fresh()->umkm_amount);
        $this->assertSame(PaymentStatus::PAID, $order->fresh()->payment_status);
    }

    public function test_status_log_records_the_actor_statuses_and_notes(): void
    {
        [, $storeOrder, $owner] = $this->paidStoreOrder(StoreOrderFulfillmentStatus::AWAITING_PROCESSING);

        $this->service()->transition($storeOrder, $owner, StoreOrderFulfillmentStatus::PROCESSING, '  Mulai disiapkan  ');

        $this->assertDatabaseHas('store_order_status_logs', [
            'store_order_id' => $storeOrder->id,
            'actor_id' => $owner->id,
            'from_status' => StoreOrderFulfillmentStatus::AWAITING_PROCESSING,
            'to_status' => StoreOrderFulfillmentStatus::PROCESSING,
            'notes' => 'Mulai disiapkan',
        ]);
    }

    public function test_log_failure_rolls_back_store_and_aggregate_order_statuses(): void
    {
        [$order, $storeOrder, $owner] = $this->paidStoreOrder(StoreOrderFulfillmentStatus::AWAITING_PROCESSING);
        $originalDispatcher = StoreOrderStatusLog::getEventDispatcher();

        StoreOrderStatusLog::setEventDispatcher(new Dispatcher($this->app));
        StoreOrderStatusLog::creating(static function (): void {
            throw new RuntimeException('Simulated fulfillment log failure.');
        });

        try {
            $this->service()->transition($storeOrder, $owner, StoreOrderFulfillmentStatus::PROCESSING);
            $this->fail('The simulated log failure should roll back the transition.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated fulfillment log failure.', $exception->getMessage());
        } finally {
            StoreOrderStatusLog::setEventDispatcher($originalDispatcher);
        }

        $this->assertSame(StoreOrderFulfillmentStatus::AWAITING_PROCESSING, $storeOrder->fresh()->fulfillment_status);
        $this->assertSame(OrderFulfillmentStatus::PENDING_PAYMENT, $order->fresh()->order_status);
        $this->assertDatabaseCount('store_order_status_logs', 0);
    }

    private function service(): StoreOrderFulfillmentService
    {
        return app(StoreOrderFulfillmentService::class);
    }

    private function advanceToCompleted(StoreOrder $storeOrder, User $owner): void
    {
        $this->service()->transition($storeOrder, $owner, StoreOrderFulfillmentStatus::PROCESSING);
        $this->service()->transition($storeOrder->fresh(), $owner, StoreOrderFulfillmentStatus::READY_FOR_PICKUP);
        $this->service()->transition($storeOrder->fresh(), $owner, StoreOrderFulfillmentStatus::COMPLETED);
    }

    /**
     * @return array{Order, StoreOrder, User, Product}
     */
    private function paidStoreOrder(string $status, string $paymentStatus = PaymentStatus::PAID): array
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $order = Order::create([
            'order_number' => 'ORD-'.Str::upper(Str::random(12)),
            'checkout_token' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'fulfillment_method' => 'pickup',
            'recipient_name' => 'Pelanggan Test',
            'recipient_phone' => '08123456789',
            'subtotal' => '25000.00',
            'shipping_total' => '0.00',
            'grand_total' => '25000.00',
            'payment_status' => $paymentStatus,
            'order_status' => OrderFulfillmentStatus::PENDING_PAYMENT,
        ]);

        return [$order, ...$this->paidStoreOrderForOrder($order, $status)];
    }

    /**
     * @return array{StoreOrder, User, Product}
     */
    private function paidStoreOrderForOrder(Order $order, string $status): array
    {
        $owner = User::factory()->create(['role' => 'pemilik_umkm']);
        $store = $owner->umkmStore()->create([
            'name' => 'Toko '.Str::upper(Str::random(6)),
            'slug' => 'toko-'.Str::lower(Str::random(12)),
        ]);
        $store->forceFill(['status' => 'approved'])->save();
        $category = Category::create([
            'name' => 'Kategori '.Str::upper(Str::random(6)),
            'slug' => 'kategori-'.Str::lower(Str::random(12)),
        ]);
        $product = $store->products()->create([
            'category_id' => $category->id,
            'sku' => 'SKU-'.Str::upper(Str::random(8)),
            'name' => 'Produk Snapshot',
            'slug' => 'produk-'.Str::lower(Str::random(12)),
            'price' => '25000.00',
            'stock' => 10,
        ]);
        $product->forceFill(['status' => 'approved'])->save();
        $storeOrder = $order->storeOrders()->create([
            'umkm_store_id' => $store->id,
            'store_order_number' => 'STO-'.Str::upper(Str::random(12)),
            'store_name_snapshot' => $store->name,
            'store_address_snapshot' => $store->address,
            'store_phone_snapshot' => $store->phone,
            'subtotal' => '25000.00',
            'shipping_amount' => '0.00',
            'total' => '25000.00',
            'fulfillment_status' => $status,
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

        return [$storeOrder, $owner, $product];
    }
}
