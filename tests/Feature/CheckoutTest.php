<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_open_checkout_with_current_product_data(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $product = $this->availableProduct('Kopi Buruan', 17500, 4, 'Toko Kopi', 'Jalan Raya Buruan');
        $this->cartItem($customer, $product, 2);

        $this->actingAs($customer)
            ->get(route('checkout.index'))
            ->assertOk()
            ->assertSee([$customer->name, $product->name, 'Toko Kopi', 'Rp 35.000', 'Tarif pengiriman belum tersedia']);
    }

    public function test_guest_is_redirected_to_login_and_non_customer_is_forbidden(): void
    {
        $this->get(route('checkout.index'))->assertRedirect(route('login'));

        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $this->actingAs($admin)
            ->get(route('checkout.index'))
            ->assertForbidden();
    }

    public function test_empty_cart_shows_an_empty_state(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);

        $this->actingAs($customer)
            ->get(route('checkout.index'))
            ->assertOk()
            ->assertSee('Keranjang Anda masih kosong')
            ->assertSee(route('catalog.index'), false);
    }

    public function test_checkout_groups_multiple_stores_and_uses_database_prices(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $coffee = $this->availableProduct('Kopi Desa', 12000, 5, 'Toko Kopi', 'Alamat Kopi');
        $craft = $this->availableProduct('Anyaman Desa', 30000, 4, 'Toko Anyaman', 'Alamat Anyaman');
        $this->cartItem($customer, $coffee, 2);
        $this->cartItem($customer, $craft, 1);

        $this->actingAs($customer)
            ->get(route('checkout.index'))
            ->assertOk()
            ->assertSeeInOrder(['Toko Kopi', 'Toko Anyaman'])
            ->assertSee('Rp 24.000')
            ->assertSee('Rp 30.000')
            ->assertSee('Rp 54.000');
    }

    public function test_unavailable_product_or_store_and_insufficient_stock_are_flagged(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $pendingProduct = $this->availableProduct('Produk Pending', 10000, 5, 'Toko Pending', 'Alamat', 'pending');
        $storePendingProduct = $this->availableProduct('Produk Toko Pending', 10000, 5, 'Toko Belum Aktif', 'Alamat', 'approved', 'pending');
        $limitedProduct = $this->availableProduct('Produk Stok Terbatas', 10000, 1, 'Toko Stok', 'Alamat');
        $this->cartItem($customer, $pendingProduct, 1);
        $this->cartItem($customer, $storePendingProduct, 1);
        $this->cartItem($customer, $limitedProduct, 2);

        $this->actingAs($customer)
            ->get(route('checkout.index'))
            ->assertOk()
            ->assertSee('Ada produk yang tidak lagi tersedia atau stoknya tidak mencukupi.')
            ->assertSee('Tidak tersedia atau stok tidak cukup.');
    }

    public function test_checkout_shows_delivery_and_pickup_information_without_dummy_delivery_fee(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $product = $this->availableProduct('Produk Antar', 10000, 2, 'Toko Buruan', 'Jalan Buruan No. 1');
        $this->cartItem($customer, $product, 1);

        $this->actingAs($customer)
            ->get(route('checkout.index'))
            ->assertOk()
            ->assertSee([
                'Diantar Kurir BUMDes',
                'Ambil di Toko Penjual',
                'Blahbatuh',
                'Payangan',
                'Jalan Buruan No. 1',
                'Tarif pengiriman belum tersedia',
            ])
            ->assertDontSee('Ongkir Rp 0');
    }

    public function test_checkout_renders_frontend_fulfillment_state_with_disabled_hidden_delivery_fields(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $product = $this->availableProduct('Produk Metode', 10000, 2, 'Toko Metode', 'Jalan Metode');
        $this->cartItem($customer, $product, 1);

        $this->actingAs($customer)
            ->get(route('checkout.index'))
            ->assertOk()
            ->assertSee('data-fulfillment-control', false)
            ->assertSee('data-fulfillment-card="delivery"', false)
            ->assertSee('data-fulfillment-card="pickup"', false)
            ->assertSee('method="POST" action="'.route('checkout.store').'"', false)
            ->assertSee('name="checkout_token"', false)
            ->assertSee('name="recipient_name"', false)
            ->assertSee('name="recipient_phone"', false)
            ->assertSee('id="delivery-fields"', false)
            ->assertSee('id="pickup-fields"', false)
            ->assertSee('id="pickup-fields" data-fulfillment-section="pickup" class="mt-6 rounded-md bg-gray-50 p-4" hidden', false)
            ->assertSee('id="delivery-rate-notice"', false)
            ->assertSee('id="pickup-shipping-cost"', false)
            ->assertSee("field.disabled = ! deliverySelected", false)
            ->assertSee("pickupFields.hidden = deliverySelected", false)
            ->assertSee("syncCardState(deliveryCard, deliverySelected)", false)
            ->assertSee("syncCardState(pickupCard, ! deliverySelected)", false)
            ->assertSee("checkoutForm.checkValidity()", false)
            ->assertSee("checkoutForm.addEventListener('input', syncFulfillmentMethod)", false)
            ->assertSee("document.addEventListener('DOMContentLoaded', initializeCheckout)", false)
            ->assertSee('recipient_name', false)
            ->assertSee('recipient_phone', false)
            ->assertDontSee('Pemesanan akan tersedia pada Tahap 7C.');
    }

    public function test_checkout_does_not_reduce_stock_or_change_cart_items(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $product = $this->availableProduct('Produk Aman', 10000, 7, 'Toko Aman', 'Alamat Aman');
        $item = $this->cartItem($customer, $product, 3);

        $this->actingAs($customer)
            ->get(route('checkout.index'))
            ->assertOk();

        $this->assertSame(7, $product->fresh()->stock);
        $this->assertSame(3, $item->fresh()->quantity);
        $this->assertDatabaseCount('cart_items', 1);
    }

    private function cartItem(User $customer, Product $product, int $quantity): CartItem
    {
        $cart = Cart::firstOrCreate(['user_id' => $customer->id]);

        return $cart->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);
    }

    private function availableProduct(
        string $name,
        int $price,
        int $stock,
        string $storeName,
        ?string $address,
        string $productStatus = 'approved',
        string $storeStatus = 'approved',
    ): Product {
        $owner = User::factory()->create(['role' => 'penjual']);
        $store = $owner->umkmStore()->create([
            'name' => $storeName,
            'slug' => fake()->unique()->slug(),
            'address' => $address,
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
