<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Category;
use App\Models\CommissionAgreement;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class OrderCancellationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_cancel_an_unpaid_multi_store_order_and_all_stock_is_restored(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $coffee = $this->availableProduct('Kopi Buruan', 5, 'Toko Kopi');
        $craft = $this->availableProduct('Anyaman Buruan', 4, 'Toko Anyaman');
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        CommissionAgreement::create([
            'umkm_store_id' => $coffee->umkm_store_id,
            'commission_percentage' => '12.50',
            'effective_start_date' => today()->subDay(),
            'effective_end_date' => null,
            'status' => 'active',
            'created_by' => $admin->id,
            'activated_at' => now(),
        ]);
        $order = $this->createOrder($customer, [$coffee->id => 2, $craft->id => 3]);

        $this->actingAs($customer)
            ->patch(route('orders.cancel', $order), ['cancellation_reason' => 'Tidak jadi mengambil pesanan.'])
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('status', 'Pesanan berhasil dibatalkan dan stok telah dikembalikan.');

        $order->refresh();
        $this->assertSame('cancelled', $order->order_status);
        $this->assertNotNull($order->cancelled_at);
        $this->assertSame('Tidak jadi mengambil pesanan.', $order->cancellation_reason);
        $this->assertSame(5, $coffee->fresh()->stock);
        $this->assertSame(4, $craft->fresh()->stock);
        $this->assertSame(['cancelled', 'cancelled'], $order->storeOrders()->pluck('fulfillment_status')->all());
        $coffeeStoreOrder = $order->storeOrders()->where('umkm_store_id', $coffee->umkm_store_id)->sole();
        $this->assertSame('calculated', $coffeeStoreOrder->commission_status);
        $this->assertSame('2500.00', $coffeeStoreOrder->commission_amount);
        $this->assertSame('17500.00', $coffeeStoreOrder->umkm_amount);

        $this->actingAs($customer)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertSee([$order->order_number, 'Dibatalkan']);
        $this->actingAs($customer)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee(['Dibatalkan', 'Tidak jadi mengambil pesanan.'])
            ->assertDontSee('Batalkan Pesanan');
    }

    public function test_second_cancellation_is_rejected_without_restoring_stock_twice(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $product = $this->availableProduct('Produk Tunggal', 5, 'Toko Tunggal');
        $order = $this->createOrder($customer, [$product->id => 2]);

        $this->actingAs($customer)->patch(route('orders.cancel', $order));
        $this->assertSame(5, $product->fresh()->stock);

        $this->actingAs($customer)
            ->from(route('orders.show', $order))
            ->patch(route('orders.cancel', $order))
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHasErrors('order');

        $this->assertSame(5, $product->fresh()->stock);
        $this->assertSame('cancelled', $order->fresh()->order_status);
    }

    public function test_other_customer_cannot_cancel_an_order(): void
    {
        $owner = User::factory()->create(['role' => 'pelanggan']);
        $otherCustomer = User::factory()->create(['role' => 'pelanggan']);
        $product = $this->availableProduct('Produk Privat', 5, 'Toko Privat');
        $order = $this->createOrder($owner, [$product->id => 2]);

        $this->actingAs($otherCustomer)
            ->patch(route('orders.cancel', $order))
            ->assertForbidden();

        $this->assertSame('pending_payment', $order->fresh()->order_status);
        $this->assertSame(3, $product->fresh()->stock);
    }

    public function test_paid_order_cannot_be_cancelled(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $product = $this->availableProduct('Produk Lunas', 5, 'Toko Lunas');
        $order = $this->createOrder($customer, [$product->id => 2]);
        $order->update(['payment_status' => 'paid']);

        $this->actingAs($customer)
            ->from(route('orders.show', $order))
            ->patch(route('orders.cancel', $order))
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHasErrors('order');

        $this->assertSame('pending_payment', $order->fresh()->order_status);
        $this->assertSame(3, $product->fresh()->stock);
    }

    public function test_a_failed_cancellation_transaction_does_not_partially_restore_stock(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $product = $this->availableProduct('Produk Rollback', 5, 'Toko Rollback');
        $order = $this->createOrder($customer, [$product->id => 2]);
        $originalDispatcher = Order::getEventDispatcher();

        Order::setEventDispatcher(new Dispatcher($this->app));
        Order::updating(static function (): void {
            throw new RuntimeException('Simulated cancellation failure.');
        });

        try {
            $this->withoutExceptionHandling();
            $this->actingAs($customer)->patch(route('orders.cancel', $order));
            $this->fail('Cancellation should throw the simulated failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated cancellation failure.', $exception->getMessage());
        } finally {
            Order::setEventDispatcher($originalDispatcher);
        }

        $this->assertSame('pending_payment', $order->fresh()->order_status);
        $this->assertSame(['pending_payment'], $order->storeOrders()->pluck('fulfillment_status')->all());
        $this->assertSame(3, $product->fresh()->stock);
    }

    /**
     * @param  array<int, int>  $items
     */
    private function createOrder(User $customer, array $items): Order
    {
        $cart = Cart::firstOrCreate(['user_id' => $customer->id]);

        foreach ($items as $productId => $quantity) {
            $cart->items()->create(['product_id' => $productId, 'quantity' => $quantity]);
        }

        $token = (string) Str::uuid();
        $this->actingAs($customer)
            ->withSession(['checkout_token' => $token])
            ->post(route('checkout.store'), [
                'checkout_token' => $token,
                'fulfillment_method' => 'pickup',
                'recipient_name' => 'Pelanggan Test',
                'recipient_phone' => '08123456789',
            ])
            ->assertRedirect();

        return Order::sole();
    }

    private function availableProduct(string $name, int $stock, string $storeName): Product
    {
        $owner = User::factory()->create(['role' => 'pemilik_umkm']);
        $store = $owner->umkmStore()->create([
            'name' => $storeName,
            'slug' => fake()->unique()->slug(),
        ]);
        $store->forceFill(['status' => 'approved'])->save();
        $category = Category::create([
            'name' => 'Kategori '.fake()->unique()->word(),
            'slug' => fake()->unique()->slug(),
        ]);
        $product = $store->products()->create([
            'category_id' => $category->id,
            'sku' => 'SKU-'.fake()->unique()->bothify('???###'),
            'name' => $name,
            'slug' => fake()->unique()->slug(),
            'price' => 10000,
            'stock' => $stock,
        ]);
        $product->forceFill(['status' => 'approved'])->save();

        return $product;
    }
}
