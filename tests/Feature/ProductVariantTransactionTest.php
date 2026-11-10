<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Category;
use App\Models\CommissionAgreement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductVariantTransactionTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $slug)
    {
        $seller = User::factory()->create(['role' => 'penjual']);
        $store = $seller->umkmStore()->create(['name' => $slug, 'slug' => $slug]);
        $store->forceFill(['status' => 'approved'])->save();
        $category = Category::create(['name' => $slug, 'slug' => $slug]);
        $product = $store->products()->create(['category_id' => $category->id, 'sku' => $slug, 'name' => $slug, 'slug' => $slug, 'price' => '10000.00', 'stock' => 5]);
        $product->forceFill(['status' => 'approved'])->save();

        return $product;
    }

    private function checkout(User $buyer, ?string $token = null)
    {
        $token ??= (string) Str::uuid();

        return $this->actingAs($buyer)->withSession(['checkout_token' => $token])->from(route('checkout.index'))
            ->post(route('checkout.store'), [
                'checkout_token' => $token,
                'fulfillment_method' => 'pickup',
                'recipient_name' => 'Fixture Customer',
                'recipient_phone' => '0800000000',
                'price' => '1.00',
            ]);
    }

    public function test_two_variants_keep_separate_cart_lines_and_checkout_snapshots_current_prices(): void
    {
        $buyer = User::factory()->create(['role' => 'pelanggan']);
        $product = $this->product('brownies-fixture');
        $small = $product->variants()->create(['label' => 'Small', 'sku' => 'BR-S', 'price' => '35000.25', 'stock' => 4]);
        $large = $product->variants()->create(['label' => 'Large', 'sku' => 'BR-L', 'price' => '85000.50', 'stock' => 3]);

        foreach ([$small, $large] as $variant) {
            $this->actingAs($buyer)->post(route('cart.items.store', $product), [
                'quantity' => 1, 'product_variant_id' => $variant->id, 'price' => '1.00',
            ])->assertRedirect(route('cart.index'));
        }

        $cart = Cart::where('user_id', $buyer->id)->sole();
        $this->assertSame(2, $cart->items()->count());
        $this->assertEqualsCanonicalizing([$small->id, $large->id], $cart->items()->pluck('product_variant_id')->all());
        $small->update(['price' => '40000.25']);

        $this->checkout($buyer)->assertRedirect();
        $order = Order::sole();
        $items = $order->storeOrders()->sole()->items()->orderBy('unit_price')->get();
        $this->assertSame('125000.75', $order->grand_total);
        $this->assertSame('40000.25', $items[0]->unit_price);
        $this->assertSame('Small', $items[0]->variant_label_snapshot);
        $this->assertSame('BR-S', $items[0]->variant_sku_snapshot);
        $this->assertSame('85000.50', $items[1]->unit_price);
        $this->assertSame(3, $small->fresh()->stock);
        $this->assertSame(2, $large->fresh()->stock);
        $this->assertSame(5, $product->fresh()->stock);

        $this->actingAs($buyer)->patch(route('orders.cancel', $order))->assertRedirect();
        $this->assertSame(4, $small->fresh()->stock);
        $this->assertSame(3, $large->fresh()->stock);
        $this->actingAs($buyer)->patch(route('orders.cancel', $order))->assertSessionHasErrors('order');
        $this->assertSame(4, $small->fresh()->stock);
    }

    public function test_invalid_inactive_and_insufficient_variants_are_rejected(): void
    {
        $buyer = User::factory()->create(['role' => 'pelanggan']);
        $product = $this->product('main-fixture');
        $other = $this->product('other-fixture');
        $foreign = $other->variants()->create(['label' => 'Other', 'sku' => 'OTHER-V', 'price' => '100.00', 'stock' => 3]);
        $inactive = $product->variants()->create(['label' => 'Inactive', 'sku' => 'INACTIVE-V', 'price' => '200.00', 'stock' => 3, 'is_active' => false]);
        $active = $product->variants()->create(['label' => 'Active', 'sku' => 'ACTIVE-V', 'price' => '300.00', 'stock' => 1]);

        foreach ([null, $foreign->id, $inactive->id] as $variantId) {
            $payload = ['quantity' => 1];
            if ($variantId !== null) $payload['product_variant_id'] = $variantId;
            $this->actingAs($buyer)->from(route('catalog.show', $product->slug))
                ->post(route('cart.items.store', $product), $payload)->assertSessionHasErrors('product_variant_id');
        }
        $this->actingAs($buyer)->post(route('cart.items.store', $product), ['quantity' => 2, 'product_variant_id' => $active->id])->assertSessionHasErrors('quantity');
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_variant_checkout_rechecks_activity_and_stock_without_mutation(): void
    {
        $buyer = User::factory()->create(['role' => 'pelanggan']);
        $product = $this->product('changed-fixture');
        $variant = $product->variants()->create(['label' => 'Size', 'sku' => 'CHANGED-V', 'price' => '12500.00', 'stock' => 2]);
        $this->actingAs($buyer)->post(route('cart.items.store', $product), ['quantity' => 2, 'product_variant_id' => $variant->id]);
        $variant->update(['stock' => 1]);
        $this->checkout($buyer)->assertSessionHasErrors('cart');
        $this->assertDatabaseCount('orders', 0);
        $variant->update(['stock' => 2, 'is_active' => false]);
        $this->checkout($buyer)->assertSessionHasErrors('cart');
        $this->assertSame(2, $variant->fresh()->stock);
    }

    public function test_variant_totals_feed_existing_commission_and_payment_snapshots(): void
    {
        $buyer = User::factory()->create(['role' => 'pelanggan']);
        $product = $this->product('financial-fixture');
        $variant = $product->variants()->create(['label' => 'Medium', 'sku' => 'FIN-V', 'price' => '55000.25', 'stock' => 2]);
        CommissionAgreement::create([
            'umkm_store_id' => $product->umkm_store_id,
            'commission_percentage' => '10.00',
            'effective_start_date' => today()->subDay(),
            'status' => 'active',
            'created_by' => User::factory()->create(['role' => 'admin_bumdes'])->id,
            'activated_at' => now(),
        ]);

        $this->actingAs($buyer)->post(route('cart.items.store', $product), ['quantity' => 2, 'product_variant_id' => $variant->id]);
        $this->checkout($buyer)->assertRedirect();
        $order = Order::sole();
        $storeOrder = $order->storeOrders()->sole();
        $this->assertSame('110000.50', $order->grand_total);
        $this->assertSame($order->grand_total, $storeOrder->subtotal);
        $this->assertSame('11000.05', $storeOrder->commission_amount);
        $this->assertSame('99000.45', $storeOrder->umkm_amount);
        $this->assertSame('110000.50', $storeOrder->items()->sole()->line_total);

        $this->actingAs($buyer)->post(route('orders.payments.store', $order), [
            'payment_method' => \App\Support\PaymentMethod::CASH_PICKUP,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect(route('orders.show', $order));
        $this->assertSame($order->grand_total, Payment::sole()->amount);
    }
}
