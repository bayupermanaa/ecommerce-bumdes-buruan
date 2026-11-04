<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_add_an_approved_product_to_their_cart(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $product = $this->availableProduct();

        $this->actingAs($customer)
            ->post(route('cart.items.store', $product), ['quantity' => 2])
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('status');

        $cart = Cart::where('user_id', $customer->id)->sole();
        $this->assertDatabaseHas('cart_items', [
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);
    }

    public function test_adding_the_same_product_increments_quantity_without_duplicate_items(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $product = $this->availableProduct(stock: 10);

        $this->actingAs($customer)->post(route('cart.items.store', $product), ['quantity' => 2]);
        $this->actingAs($customer)->post(route('cart.items.store', $product), ['quantity' => 3]);

        $cart = Cart::where('user_id', $customer->id)->sole();
        $this->assertSame(1, $cart->items()->count());
        $this->assertSame(5, $cart->items()->sole()->quantity);
    }

    public function test_customer_can_update_and_remove_an_owned_cart_item(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $product = $this->availableProduct(stock: 8);
        $item = $this->cartItem($customer, $product, 1);

        $this->actingAs($customer)
            ->patch(route('cart.items.update', $item), ['quantity' => 4])
            ->assertRedirect(route('cart.index'));
        $this->assertSame(4, $item->fresh()->quantity);

        $this->actingAs($customer)
            ->delete(route('cart.items.destroy', $item))
            ->assertRedirect(route('cart.index'));
        $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);
    }

    public function test_cart_total_uses_current_database_prices_and_ignores_browser_price(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $product = $this->availableProduct(price: 17500, stock: 8);

        $this->actingAs($customer)
            ->post(route('cart.items.store', $product), [
                'quantity' => 2,
                'price' => 1,
                'total' => 1,
            ]);

        $this->actingAs($customer)
            ->get(route('cart.index'))
            ->assertOk()
            ->assertSee('Rp 35.000')
            ->assertDontSee('>Rp 1<', false);
    }

    public function test_quantity_above_stock_is_rejected_and_stock_is_not_reduced(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $product = $this->availableProduct(stock: 3);

        $this->actingAs($customer)
            ->from(route('catalog.show', $product->slug))
            ->post(route('cart.items.store', $product), ['quantity' => 4])
            ->assertRedirect(route('catalog.show', $product->slug))
            ->assertSessionHasErrors('quantity');

        $this->assertSame(3, $product->fresh()->stock);
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_pending_or_rejected_products_and_unapproved_stores_are_rejected(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $pendingProduct = $this->availableProduct(status: 'pending');
        $rejectedProduct = $this->availableProduct(status: 'rejected');
        $pendingStoreProduct = $this->availableProduct(storeStatus: 'pending');

        foreach ([$pendingProduct, $rejectedProduct, $pendingStoreProduct] as $product) {
            $this->actingAs($customer)
                ->from(route('catalog.index'))
                ->post(route('cart.items.store', $product), ['quantity' => 1])
                ->assertRedirect(route('catalog.index'))
                ->assertSessionHasErrors('product');
        }

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_non_customers_cannot_manage_carts_and_customers_cannot_manage_others_items(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $product = $this->availableProduct();

        $this->actingAs($admin)
            ->post(route('cart.items.store', $product), ['quantity' => 1])
            ->assertForbidden();

        $owner = User::factory()->create(['role' => 'pelanggan']);
        $otherCustomer = User::factory()->create(['role' => 'pelanggan']);
        $item = $this->cartItem($owner, $product, 1);

        $this->actingAs($otherCustomer)
            ->patch(route('cart.items.update', $item), ['quantity' => 2])
            ->assertForbidden();

        $this->actingAs($otherCustomer)
            ->delete(route('cart.items.destroy', $item))
            ->assertForbidden();
    }

    public function test_cart_persists_after_logout_and_login_again(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $product = $this->availableProduct();

        $this->actingAs($customer)
            ->post(route('cart.items.store', $product), ['quantity' => 1]);
        $this->post(route('logout'));

        $this->actingAs($customer)
            ->get(route('cart.index'))
            ->assertOk()
            ->assertSee($product->name);
    }

    private function cartItem(User $customer, Product $product, int $quantity): CartItem
    {
        $cart = Cart::create(['user_id' => $customer->id]);

        return $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => $quantity,
        ]);
    }

    private function availableProduct(
        string $status = 'approved',
        string $storeStatus = 'approved',
        int $stock = 5,
        int $price = 15000,
    ): Product {
        $owner = User::factory()->create(['role' => 'pemilik_umkm']);
        $store = $owner->umkmStore()->create([
            'name' => 'Toko '.fake()->unique()->word(),
            'slug' => 'toko-'.fake()->unique()->slug(),
        ]);
        $store->forceFill(['status' => $storeStatus])->save();
        $category = Category::create([
            'name' => 'Kategori '.fake()->unique()->word(),
            'slug' => 'kategori-'.fake()->unique()->slug(),
        ]);
        $product = $store->products()->create([
            'category_id' => $category->id,
            'sku' => 'SKU-'.fake()->unique()->bothify('???###'),
            'name' => 'Produk '.fake()->unique()->words(2, true),
            'slug' => 'produk-'.fake()->unique()->slug(),
            'price' => $price,
            'stock' => $stock,
        ]);
        $product->forceFill(['status' => $status])->save();

        return $product;
    }
}
