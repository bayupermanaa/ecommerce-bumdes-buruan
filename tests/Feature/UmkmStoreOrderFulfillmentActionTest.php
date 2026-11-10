<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreOrder;
use App\Models\StoreOrderStatusLog;
use App\Models\User;
use App\Support\OrderFulfillmentStatus;
use App\Support\PaymentStatus;
use App\Support\StoreOrderFulfillmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UmkmStoreOrderFulfillmentActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_advance_each_fulfillment_action_in_sequence(): void
    {
        [$order, $storeOrder, $owner] = $this->storeOrder(StoreOrderFulfillmentStatus::AWAITING_PROCESSING);

        $this->actingAs($owner)->patch(route('umkm.orders.start-processing', $storeOrder))
            ->assertRedirect(route('umkm.orders.show', $storeOrder));
        $this->assertSame(StoreOrderFulfillmentStatus::PROCESSING, $storeOrder->fresh()->fulfillment_status);

        $this->actingAs($owner)->patch(route('umkm.orders.ready-for-pickup', $storeOrder))
            ->assertRedirect(route('umkm.orders.show', $storeOrder));
        $this->assertSame(StoreOrderFulfillmentStatus::READY_FOR_PICKUP, $storeOrder->fresh()->fulfillment_status);

        $this->actingAs($owner)->patch(route('umkm.orders.complete', $storeOrder))
            ->assertRedirect(route('umkm.orders.show', $storeOrder));
        $this->assertSame(StoreOrderFulfillmentStatus::COMPLETED, $storeOrder->fresh()->fulfillment_status);
        $this->assertSame(OrderFulfillmentStatus::COMPLETED, $order->fresh()->order_status);
        $this->assertDatabaseCount('store_order_status_logs', 3);
    }

    public function test_action_buttons_are_conditional_and_include_csrf_forms(): void
    {
        [, $awaiting, $owner] = $this->storeOrder(StoreOrderFulfillmentStatus::AWAITING_PROCESSING);
        [, $processing, $processingOwner] = $this->storeOrder(StoreOrderFulfillmentStatus::PROCESSING);
        [, $ready, $readyOwner] = $this->storeOrder(StoreOrderFulfillmentStatus::READY_FOR_PICKUP);

        $this->actingAs($owner)->get(route('umkm.orders.show', $awaiting))
            ->assertSee(['Mulai Diproses', route('umkm.orders.start-processing', $awaiting), 'name="_token"', 'name="_method" value="PATCH"'], false)
            ->assertDontSee('Tandai Siap Diambil');
        $this->actingAs($processingOwner)->get(route('umkm.orders.show', $processing))
            ->assertSee('Tandai Siap Diambil')
            ->assertDontSee('Konfirmasi Barang Diserahkan');
        $this->actingAs($readyOwner)->get(route('umkm.orders.show', $ready))
            ->assertSee(['Konfirmasi Barang Diserahkan', 'Pastikan barang benar-benar sudah diserahkan'], false);
    }

    public function test_other_owners_and_non_umkm_roles_cannot_change_fulfillment(): void
    {
        [, $storeOrder, $owner] = $this->storeOrder(StoreOrderFulfillmentStatus::AWAITING_PROCESSING);
        $otherOwner = $this->owner('Toko Lain');

        $this->actingAs($otherOwner)->patch(route('umkm.orders.start-processing', $storeOrder))->assertForbidden();

        foreach (['pelanggan', 'admin_bumdes', 'kurir', 'kepala_bumdes'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->patch(route('umkm.orders.start-processing', $storeOrder))
                ->assertForbidden();
        }

        $this->assertSame(StoreOrderFulfillmentStatus::AWAITING_PROCESSING, $storeOrder->fresh()->fulfillment_status);
        $this->assertDatabaseCount('store_order_status_logs', 0);
    }

    public function test_unpaid_and_non_sequential_requests_are_rejected_without_changes(): void
    {
        [, $unpaid, $owner] = $this->storeOrder(StoreOrderFulfillmentStatus::AWAITING_PROCESSING, PaymentStatus::UNPAID);
        [, $awaiting, $awaitingOwner] = $this->storeOrder(StoreOrderFulfillmentStatus::AWAITING_PROCESSING);

        $this->actingAs($owner)->from(route('umkm.orders.show', $unpaid))
            ->patch(route('umkm.orders.start-processing', $unpaid))
            ->assertRedirect(route('umkm.orders.show', $unpaid))
            ->assertSessionHasErrors('order');
        $this->actingAs($awaitingOwner)->from(route('umkm.orders.show', $awaiting))
            ->patch(route('umkm.orders.ready-for-pickup', $awaiting))
            ->assertRedirect(route('umkm.orders.show', $awaiting))
            ->assertSessionHasErrors('fulfillment_status');

        $this->assertSame(StoreOrderFulfillmentStatus::AWAITING_PROCESSING, $unpaid->fresh()->fulfillment_status);
        $this->assertSame(StoreOrderFulfillmentStatus::AWAITING_PROCESSING, $awaiting->fresh()->fulfillment_status);
        $this->assertDatabaseCount('store_order_status_logs', 0);
    }

    public function test_repeated_request_is_idempotent_and_multi_store_completion_is_aggregated(): void
    {
        [$order, $first, $firstOwner] = $this->storeOrder(StoreOrderFulfillmentStatus::READY_FOR_PICKUP);
        [, $second, $secondOwner] = $this->storeOrder(StoreOrderFulfillmentStatus::READY_FOR_PICKUP, PaymentStatus::PAID, $order);

        $this->actingAs($firstOwner)->patch(route('umkm.orders.complete', $first));
        $this->actingAs($firstOwner)->patch(route('umkm.orders.complete', $first));
        $this->assertSame(OrderFulfillmentStatus::IN_FULFILLMENT, $order->fresh()->order_status);
        $this->assertDatabaseCount('store_order_status_logs', 1);

        $this->actingAs($secondOwner)->patch(route('umkm.orders.complete', $second));
        $this->assertSame(OrderFulfillmentStatus::COMPLETED, $order->fresh()->order_status);
        $this->assertDatabaseCount('store_order_status_logs', 2);
    }

    public function test_action_preserves_stock_and_financial_snapshots_and_records_audit_actor(): void
    {
        [, $storeOrder, $owner, $product] = $this->storeOrder(StoreOrderFulfillmentStatus::AWAITING_PROCESSING);
        $item = $storeOrder->items()->sole();

        $this->actingAs($owner)->patch(route('umkm.orders.start-processing', $storeOrder));

        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame('25000.00', $item->fresh()->unit_price);
        $this->assertSame('12.50', $storeOrder->fresh()->commission_percentage);
        $this->assertSame('3125.00', $storeOrder->fresh()->commission_amount);
        $this->assertDatabaseHas('store_order_status_logs', [
            'store_order_id' => $storeOrder->id,
            'actor_id' => $owner->id,
            'from_status' => StoreOrderFulfillmentStatus::AWAITING_PROCESSING,
            'to_status' => StoreOrderFulfillmentStatus::PROCESSING,
        ]);
    }

    /** @return array{Order, StoreOrder, User, Product} */
    private function storeOrder(string $status, string $paymentStatus = PaymentStatus::PAID, ?Order $order = null): array
    {
        $owner = $this->owner('Toko '.Str::upper(Str::random(6)));
        $store = $owner->umkmStore;
        $store->forceFill(['status' => 'approved'])->save();
        $order ??= $this->order($paymentStatus);
        $category = Category::create(['name' => 'Kategori '.Str::random(6), 'slug' => 'kategori-'.Str::lower(Str::random(12))]);
        $product = $store->products()->create([
            'category_id' => $category->id, 'sku' => 'SKU-'.Str::upper(Str::random(8)), 'name' => 'Produk Snapshot',
            'slug' => 'produk-'.Str::lower(Str::random(12)), 'price' => '25000.00', 'stock' => 10,
        ]);
        $product->forceFill(['status' => 'approved'])->save();
        $storeOrder = $order->storeOrders()->create([
            'umkm_store_id' => $store->id, 'store_order_number' => 'STO-'.Str::upper(Str::random(12)),
            'store_name_snapshot' => $store->name, 'subtotal' => '25000.00', 'shipping_amount' => '0.00', 'total' => '25000.00',
            'fulfillment_status' => $status, 'commission_percentage' => '12.50', 'commission_amount' => '3125.00',
            'umkm_amount' => '21875.00', 'commission_status' => 'calculated',
        ]);
        $storeOrder->items()->create([
            'product_id' => $product->id, 'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku,
            'unit_price' => '25000.00', 'quantity' => 1, 'line_total' => '25000.00',
        ]);

        return [$order, $storeOrder, $owner, $product];
    }

    private function owner(string $name): User
    {
        $owner = User::factory()->create(['role' => 'penjual']);
        $owner->umkmStore()->create(['name' => $name, 'slug' => 'toko-'.Str::lower(Str::random(12))]);

        return $owner;
    }

    private function order(string $paymentStatus): Order
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);

        return Order::create([
            'order_number' => 'ORD-'.Str::upper(Str::random(12)), 'checkout_token' => (string) Str::uuid(), 'user_id' => $customer->id,
            'fulfillment_method' => 'pickup', 'recipient_name' => 'Pelanggan Test', 'recipient_phone' => '08123456789',
            'subtotal' => '25000.00', 'shipping_total' => '0.00', 'grand_total' => '25000.00', 'payment_status' => $paymentStatus,
            'order_status' => $paymentStatus === PaymentStatus::PAID ? OrderFulfillmentStatus::IN_FULFILLMENT : OrderFulfillmentStatus::PENDING_PAYMENT,
        ]);
    }
}
