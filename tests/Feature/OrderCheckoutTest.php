<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Category;
use App\Models\CommissionAgreement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Support\PaymentMethod;
use App\Support\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_create_a_multi_store_pickup_order_and_stock_is_allocated(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $coffee = $this->availableProduct('Kopi Buruan', 15000, 5, 'Toko Kopi');
        $craft = $this->availableProduct('Anyaman Buruan', 30000, 4, 'Toko Anyaman');
        $this->cartItem($customer, $coffee, 2);
        $this->cartItem($customer, $craft, 1);

        $response = $this->submitPickup($customer);
        $order = Order::sole();

        $response->assertRedirect(route('orders.show', $order));
        $this->assertSame('pickup', $order->fulfillment_method);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame('pending_payment', $order->order_status);
        $this->assertSame('60000.00', $order->grand_total);
        $this->assertCount(2, $order->storeOrders);
        $this->assertDatabaseCount('order_items', 2);
        $this->assertSame(3, $coffee->fresh()->stock);
        $this->assertSame(3, $craft->fresh()->stock);
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_pickup_checkout_creates_unpaid_order_before_customer_submits_a_cash_payment_attempt(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $product = $this->availableProduct('Produk Pembayaran', 15000, 4, 'Toko Pembayaran');
        $this->cartItem($customer, $product, 2);

        $this->submitPickup($customer)->assertSessionHas('status', 'Pesanan berhasil dibuat. Silakan ajukan pembayaran untuk melanjutkan proses pesanan.');
        $order = Order::sole();
        $storeOrder = $order->storeOrders()->sole();

        $this->assertSame('pickup', $order->fulfillment_method);
        $this->assertSame(PaymentStatus::UNPAID, $order->payment_status);
        $this->assertNull($order->payment_method);
        $this->assertDatabaseCount('payments', 0);

        $this->actingAs($customer)
            ->post(route('orders.payments.store', $order), [
                'payment_method' => PaymentMethod::CASH_PICKUP,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('orders.show', $order));

        $payment = Payment::sole();
        $this->assertSame(PaymentMethod::CASH_PICKUP, $payment->payment_method);
        $this->assertSame(PaymentStatus::PENDING_CONFIRMATION, $payment->status);
        $this->assertSame('30000.00', $payment->amount);
        $this->assertSame(PaymentStatus::PENDING_CONFIRMATION, $order->fresh()->payment_status);
        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame('30000.00', $storeOrder->fresh()->total);
        $this->assertSame('unconfigured', $storeOrder->fresh()->commission_status);
    }

    public function test_store_order_snapshots_an_active_commission_agreement(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $product = $this->availableProduct('Kopi Komisi', 12500, 5, 'Toko Komisi');
        $agreement = CommissionAgreement::create([
            'umkm_store_id' => $product->umkm_store_id,
            'commission_percentage' => '12.50',
            'effective_start_date' => today(),
            'effective_end_date' => today()->addDay(),
            'status' => 'active',
            'created_by' => User::factory()->create(['role' => 'admin_bumdes'])->id,
            'activated_at' => now(),
        ]);
        $this->cartItem($customer, $product, 2);

        $this->submitPickup($customer);
        $storeOrder = Order::sole()->storeOrders()->sole();

        $this->assertSame($agreement->id, $storeOrder->commission_agreement_id);
        $this->assertSame('12.50', $storeOrder->commission_percentage);
        $this->assertSame('3125.00', $storeOrder->commission_amount);
        $this->assertSame('21875.00', $storeOrder->umkm_amount);
        $this->assertSame('calculated', $storeOrder->commission_status);
    }

    public function test_store_order_marks_commission_unconfigured_without_an_active_agreement(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $product = $this->availableProduct('Kopi Tanpa Komisi', 10000, 5, 'Toko Tanpa Komisi');
        $this->cartItem($customer, $product, 2);

        $this->submitPickup($customer);
        $storeOrder = Order::sole()->storeOrders()->sole();

        $this->assertSame('unconfigured', $storeOrder->commission_status);
        $this->assertNull($storeOrder->commission_agreement_id);
        $this->assertNull($storeOrder->commission_percentage);
        $this->assertNull($storeOrder->commission_amount);
        $this->assertNull($storeOrder->umkm_amount);
    }

    public function test_multi_store_checkout_calculates_each_store_separately_and_ignores_ended_agreements(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $configuredProduct = $this->availableProduct('Produk Ter konfigurasi', 10000, 5, 'Toko Aktif');
        $endedProduct = $this->availableProduct('Produk Berakhir', 20000, 5, 'Toko Berakhir');
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        CommissionAgreement::create([
            'umkm_store_id' => $configuredProduct->umkm_store_id,
            'commission_percentage' => '12.50',
            'effective_start_date' => today()->subDay(),
            'effective_end_date' => null,
            'status' => 'active',
            'created_by' => $admin->id,
            'activated_at' => now(),
        ]);
        CommissionAgreement::create([
            'umkm_store_id' => $endedProduct->umkm_store_id,
            'commission_percentage' => '20.00',
            'effective_start_date' => today()->subDay(),
            'effective_end_date' => today(),
            'status' => 'ended',
            'created_by' => $admin->id,
            'ended_at' => now(),
        ]);
        $this->cartItem($customer, $configuredProduct, 2);
        $this->cartItem($customer, $endedProduct, 1);

        $this->submitPickup($customer);
        $storeOrders = Order::sole()->storeOrders()->orderBy('umkm_store_id')->get()->keyBy('umkm_store_id');

        $configuredOrder = $storeOrders->get($configuredProduct->umkm_store_id);
        $endedOrder = $storeOrders->get($endedProduct->umkm_store_id);
        $this->assertSame('calculated', $configuredOrder->commission_status);
        $this->assertSame('2500.00', $configuredOrder->commission_amount);
        $this->assertSame('17500.00', $configuredOrder->umkm_amount);
        $this->assertSame('unconfigured', $endedOrder->commission_status);
        $this->assertNull($endedOrder->commission_amount);
    }

    public function test_guest_and_non_customer_cannot_create_orders(): void
    {
        $this->post(route('checkout.store'))->assertRedirect(route('login'));

        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $this->actingAs($admin)->post(route('checkout.store'))->assertForbidden();
    }

    public function test_empty_cart_and_delivery_checkout_are_rejected(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $token = (string) Str::uuid();

        $this->actingAs($customer)
            ->withSession(['checkout_token' => $token])
            ->from(route('checkout.index'))
            ->post(route('checkout.store'), $this->pickupPayload($token))
            ->assertRedirect(route('checkout.index'))
            ->assertSessionHasErrors('cart');

        $product = $this->availableProduct('Produk Delivery', 10000, 2, 'Toko Delivery');
        $this->cartItem($customer, $product, 1);
        $deliveryToken = (string) Str::uuid();

        $this->actingAs($customer)
            ->withSession(['checkout_token' => $deliveryToken])
            ->from(route('checkout.index'))
            ->post(route('checkout.store'), $this->pickupPayload($deliveryToken, ['fulfillment_method' => 'delivery']))
            ->assertRedirect(route('checkout.index'))
            ->assertSessionHasErrors('fulfillment_method');
    }

    public function test_unavailable_product_store_or_stock_rolls_back_and_keeps_cart(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $available = $this->availableProduct('Produk Aman', 10000, 5, 'Toko Aman');
        $limited = $this->availableProduct('Produk Terbatas', 15000, 1, 'Toko Terbatas');
        $this->cartItem($customer, $available, 2);
        $this->cartItem($customer, $limited, 2);

        $this->submitPickup($customer)
            ->assertRedirect(route('checkout.index'))
            ->assertSessionHasErrors('cart');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(5, $available->fresh()->stock);
        $this->assertSame(1, $limited->fresh()->stock);
        $this->assertDatabaseCount('cart_items', 2);
    }

    public function test_pending_product_and_unapproved_store_are_rejected(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $pending = $this->availableProduct('Produk Pending', 10000, 2, 'Toko Pending', 'pending');
        $this->cartItem($customer, $pending, 1);

        $this->submitPickup($customer)
            ->assertRedirect(route('checkout.index'))
            ->assertSessionHasErrors('cart');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_rejected_product_and_unapproved_store_are_rejected(): void
    {
        $rejectedCustomer = User::factory()->create(['role' => 'pelanggan']);
        $rejectedProduct = $this->availableProduct('Produk Rejected', 10000, 2, 'Toko Rejected', 'rejected');
        $this->cartItem($rejectedCustomer, $rejectedProduct, 1);

        $this->submitPickup($rejectedCustomer)
            ->assertRedirect(route('checkout.index'))
            ->assertSessionHasErrors('cart');

        $storeCustomer = User::factory()->create(['role' => 'pelanggan']);
        $storePendingProduct = $this->availableProduct('Produk Toko Pending', 10000, 2, 'Toko Belum Disetujui', 'approved', 'pending');
        $this->cartItem($storeCustomer, $storePendingProduct, 1);

        $this->submitPickup($storeCustomer)
            ->assertRedirect(route('checkout.index'))
            ->assertSessionHasErrors('cart');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(2, $rejectedProduct->fresh()->stock);
        $this->assertSame(2, $storePendingProduct->fresh()->stock);
    }

    public function test_order_uses_database_price_and_keeps_historical_snapshots(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $product = $this->availableProduct('Nama Produk Lama', 25000, 3, 'Nama Toko Lama');
        $this->cartItem($customer, $product, 2);

        $this->submitPickup($customer, ['price' => 1, 'total' => 1]);
        $order = Order::sole();
        $item = $order->storeOrders()->firstOrFail()->items()->sole();
        $store = $product->umkmStore;
        $product->update(['name' => 'Nama Produk Baru', 'price' => 1]);
        $store->update(['name' => 'Nama Toko Baru']);

        $this->assertSame('25000.00', $item->unit_price);
        $this->assertSame('50000.00', $item->line_total);
        $this->actingAs($customer)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee(['Nama Produk Lama', 'Nama Toko Lama'])
            ->assertDontSee(['Nama Produk Baru', 'Nama Toko Baru']);
    }

    public function test_duplicate_submit_with_the_same_token_creates_only_one_order_and_deducts_stock_once(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $product = $this->availableProduct('Produk Tunggal', 10000, 5, 'Toko Tunggal');
        $this->cartItem($customer, $product, 2);
        $token = (string) Str::uuid();
        $payload = $this->pickupPayload($token);

        $this->actingAs($customer)
            ->withSession(['checkout_token' => $token])
            ->post(route('checkout.store'), $payload);
        $order = Order::sole();

        $this->actingAs($customer)
            ->withSession(['checkout_token' => $token])
            ->post(route('checkout.store'), $payload)
            ->assertRedirect(route('orders.show', $order));

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(3, $product->fresh()->stock);
    }

    public function test_order_numbers_are_unique_and_customers_cannot_view_other_orders(): void
    {
        $firstCustomer = User::factory()->create(['role' => 'pelanggan']);
        $secondCustomer = User::factory()->create(['role' => 'pelanggan']);
        $firstProduct = $this->availableProduct('Produk Satu', 10000, 2, 'Toko Satu');
        $secondProduct = $this->availableProduct('Produk Dua', 10000, 2, 'Toko Dua');
        $this->cartItem($firstCustomer, $firstProduct, 1);
        $this->cartItem($secondCustomer, $secondProduct, 1);

        $this->submitPickup($firstCustomer);
        $this->submitPickup($secondCustomer);
        $orders = Order::orderBy('id')->get();

        $this->assertNotSame($orders[0]->order_number, $orders[1]->order_number);
        $this->actingAs($secondCustomer)
            ->get(route('orders.show', $orders[0]))
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function submitPickup(User $customer, array $overrides = [])
    {
        $token = (string) Str::uuid();

        return $this->actingAs($customer)
            ->withSession(['checkout_token' => $token])
            ->from(route('checkout.index'))
            ->post(route('checkout.store'), $this->pickupPayload($token, $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function pickupPayload(string $token, array $overrides = []): array
    {
        return array_merge([
            'checkout_token' => $token,
            'fulfillment_method' => 'pickup',
            'recipient_name' => 'Pelanggan Test',
            'recipient_phone' => '08123456789',
        ], $overrides);
    }

    private function cartItem(User $customer, Product $product, int $quantity): void
    {
        $cart = Cart::firstOrCreate(['user_id' => $customer->id]);
        $cart->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);
    }

    private function availableProduct(
        string $name,
        int $price,
        int $stock,
        string $storeName,
        string $productStatus = 'approved',
        string $storeStatus = 'approved',
    ): Product {
        $owner = User::factory()->create(['role' => 'pemilik_umkm']);
        $store = $owner->umkmStore()->create([
            'name' => $storeName,
            'slug' => fake()->unique()->slug(),
        ]);
        $store->forceFill(['status' => $storeStatus])->save();
        $category = Category::create([
            'name' => 'Kategori '.fake()->unique()->word(),
            'slug' => fake()->unique()->slug(),
        ]);
        $product = $store->products()->create([
            'category_id' => $category->id,
            'sku' => 'SKU-'.fake()->unique()->bothify('???###'),
            'name' => $name,
            'slug' => fake()->unique()->slug(),
            'price' => $price,
            'stock' => $stock,
        ]);
        $product->forceFill(['status' => $productStatus])->save();

        return $product;
    }
}
