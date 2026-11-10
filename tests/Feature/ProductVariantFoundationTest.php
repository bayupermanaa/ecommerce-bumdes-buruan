<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductVariantFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_variants_retain_decimal_prices_and_legacy_cart_rows_remain_unique(): void
    {
        $seller = User::factory()->create(['role' => 'penjual']);
        $store = $seller->umkmStore()->create(['name' => 'Toko Fixture', 'slug' => 'toko-fixture']);
        $category = Category::create(['name' => 'Kue', 'slug' => 'kue']);
        $product = $store->products()->create([
            'category_id' => $category->id,
            'sku' => 'BASE-1',
            'name' => 'Produk Fixture',
            'slug' => 'produk-fixture',
            'price' => '10000.00',
            'stock' => 4,
        ]);

        $variant = $product->variants()->create([
            'label' => 'Ukuran A',
            'sku' => 'BASE-1-A',
            'price' => '35000.25',
            'stock' => 2,
        ]);

        $this->assertSame('35000.25', $variant->fresh()->price);
        $this->assertTrue($variant->fresh()->is_active);
        $this->assertSame($product->id, $variant->product->id);
        $this->assertSame($variant->id, $product->variants->first()->id);
        $this->assertSame('10000.00', $product->fresh()->price);

        $buyer = User::factory()->create(['role' => 'pelanggan']);
        $cart = $buyer->cart()->create();
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 1]);
        $this->assertNull($cart->items()->first()->product_variant_id);
        $this->expectException(QueryException::class);
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 1]);
    }

    public function test_variant_foreign_key_rejects_missing_product(): void
    {
        $this->expectException(QueryException::class);
        DB::table('product_variants')->insert([
            'product_id' => 999999,
            'label' => 'Tidak valid',
            'sku' => 'INVALID-999',
            'price' => '1.25',
            'stock' => 0,
            'is_active' => true,
        ]);
    }
}
