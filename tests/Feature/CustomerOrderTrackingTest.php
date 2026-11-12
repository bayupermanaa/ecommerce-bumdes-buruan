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

class CustomerOrderTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_sees_owned_multi_store_progress_with_snapshot_data(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $order = $this->order($customer, PaymentStatus::PAID, OrderFulfillmentStatus::IN_FULFILLMENT);
        $ready = $this->storeOrder($order, 'Toko Siap', StoreOrderFulfillmentStatus::READY_FOR_PICKUP);
        $processing = $this->storeOrder($order, 'Toko Proses', StoreOrderFulfillmentStatus::PROCESSING);
        StoreOrderStatusLog::create([
            'store_order_id' => $ready->id,
            'from_status' => StoreOrderFulfillmentStatus::PROCESSING,
            'to_status' => StoreOrderFulfillmentStatus::READY_FOR_PICKUP,
        ]);

        $this->actingAs($customer)->get(route('orders.index'))
            ->assertOk()
            ->assertSee([$order->order_number, 'Sudah Dibayar', 'Dalam Proses', '1 siap diambil']);
        $this->actingAs($customer)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee([
                'Toko Siap', 'Toko Proses', 'Produk Toko Siap', 'SKU-Toko-Siap',
                'Siap Diambil', 'Sedang Diproses', 'Pesanan dari toko ini siap diambil', 'Riwayat Status Toko',
            ]);

        $this->assertSame(2, $order->storeOrders()->count());
        $this->assertSame(StoreOrderFulfillmentStatus::PROCESSING, $processing->fresh()->fulfillment_status);
    }

    public function test_customer_cannot_view_another_customers_order(): void
    {
        $owner = User::factory()->create(['role' => 'pelanggan']);
        $other = User::factory()->create(['role' => 'pelanggan']);
        $order = $this->order($owner, PaymentStatus::PAID, OrderFulfillmentStatus::IN_FULFILLMENT);
        $this->storeOrder($order, 'Toko Privat', StoreOrderFulfillmentStatus::PROCESSING);

        $this->actingAs($other)->get(route('orders.show', $order))->assertForbidden();
    }

    public function test_timeline_keeps_unverified_payment_pending_and_shows_each_store_separately(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $order = $this->order($customer, PaymentStatus::PENDING_CONFIRMATION, OrderFulfillmentStatus::PENDING_PAYMENT);
        $first = $this->storeOrder($order, 'Toko Pertama', StoreOrderFulfillmentStatus::PENDING_PAYMENT);
        $second = $this->storeOrder($order, 'Toko Kedua', StoreOrderFulfillmentStatus::PENDING_PAYMENT);

        $this->actingAs($customer)->get(route('orders.show', $order))
            ->assertOk()->assertSee('Perjalanan pesanan')
            ->assertSee($first->store_order_number)->assertSee($second->store_order_number)
            ->assertSee('masih menunggu verifikasi Admin BUMDes')
            ->assertDontSee('Pembayaran dikonfirmasi</p>', false);
    }

    public function test_cancelled_order_timeline_uses_actual_cancellation_time(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $order = $this->order($customer, PaymentStatus::UNPAID, OrderFulfillmentStatus::CANCELLED);
        $order->forceFill(['cancelled_at' => now()])->save();
        $this->storeOrder($order, 'Toko Batal', StoreOrderFulfillmentStatus::CANCELLED);

        $this->actingAs($customer)->get(route('orders.show', $order))
            ->assertOk()->assertSee('Pesanan dibatalkan')->assertSee($order->cancelled_at->format('d-m-Y H:i'));
    }

    public function test_order_is_only_presented_as_completed_after_all_stores_complete(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $inProgress = $this->order($customer, PaymentStatus::PAID, OrderFulfillmentStatus::IN_FULFILLMENT);
        $this->storeOrder($inProgress, 'Toko Selesai', StoreOrderFulfillmentStatus::COMPLETED);
        $this->storeOrder($inProgress, 'Toko Belum Selesai', StoreOrderFulfillmentStatus::PROCESSING);
        $completed = $this->order($customer, PaymentStatus::PAID, OrderFulfillmentStatus::COMPLETED);
        $this->storeOrder($completed, 'Toko Final', StoreOrderFulfillmentStatus::COMPLETED);

        $this->actingAs($customer)->get(route('orders.index'))
            ->assertSee([$inProgress->order_number, 'Dalam Proses', $completed->order_number, 'Selesai']);
    }

    public function test_legacy_paid_pending_status_is_neutral_and_read_only(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $order = $this->order($customer, PaymentStatus::PAID, OrderFulfillmentStatus::PENDING_PAYMENT);
        $storeOrder = $this->storeOrder($order, 'Toko Lama', StoreOrderFulfillmentStatus::PENDING_PAYMENT);
        $before = [Order::count(), StoreOrder::count(), StoreOrderStatusLog::count(), $storeOrder->items()->count()];

        $this->actingAs($customer)->get(route('orders.index'))
            ->assertOk()
            ->assertSee(['Sudah Dibayar', 'pemrosesan toko masih perlu diperbarui']);
        $this->actingAs($customer)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee(['Pembayaran telah dikonfirmasi', 'Status pemrosesan toko ini masih perlu diperbarui']);

        $this->assertSame($before, [Order::count(), StoreOrder::count(), StoreOrderStatusLog::count(), $storeOrder->items()->count()]);
    }

    private function order(User $customer, string $paymentStatus, string $orderStatus): Order
    {
        return Order::create([
            'order_number' => 'ORD-'.Str::upper(Str::random(12)), 'checkout_token' => (string) Str::uuid(), 'user_id' => $customer->id,
            'fulfillment_method' => 'pickup', 'recipient_name' => 'Pelanggan Test', 'recipient_phone' => '08123456789',
            'subtotal' => '25000.00', 'shipping_total' => '0.00', 'grand_total' => '25000.00',
            'payment_status' => $paymentStatus, 'order_status' => $orderStatus,
        ]);
    }

    private function storeOrder(Order $order, string $storeName, string $status): StoreOrder
    {
        $owner = User::factory()->create(['role' => 'penjual']);
        $store = $owner->umkmStore()->create(['name' => $storeName, 'slug' => 'toko-'.Str::lower(Str::random(12))]);
        $category = Category::create(['name' => 'Kategori '.Str::random(6), 'slug' => 'kategori-'.Str::lower(Str::random(12))]);
        $product = $store->products()->create([
            'category_id' => $category->id, 'sku' => 'SKU-'.str_replace(' ', '-', $storeName), 'name' => 'Produk '.$storeName,
            'slug' => 'produk-'.Str::lower(Str::random(12)), 'price' => '25000.00', 'stock' => 10,
        ]);
        $storeOrder = $order->storeOrders()->create([
            'umkm_store_id' => $store->id, 'store_order_number' => 'STO-'.Str::upper(Str::random(12)), 'store_name_snapshot' => $storeName,
            'subtotal' => '25000.00', 'shipping_amount' => '0.00', 'total' => '25000.00', 'fulfillment_status' => $status,
        ]);
        $storeOrder->items()->create([
            'product_id' => $product->id, 'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku,
            'unit_price' => '25000.00', 'quantity' => 1, 'line_total' => '25000.00',
        ]);

        return $storeOrder;
    }
}
