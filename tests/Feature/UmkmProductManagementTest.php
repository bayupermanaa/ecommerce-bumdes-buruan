<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UmkmProductManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_store_owner_can_create_a_pending_product(): void
    {
        $owner = User::factory()->create(['role' => 'penjual']);
        $store = $owner->umkmStore()->create([
            'name' => 'Toko Disetujui',
            'slug' => 'toko-disetujui',
        ]);
        $store->forceFill(['status' => 'approved'])->save();
        $category = Category::create(['name' => 'Makanan', 'slug' => 'makanan']);

        $this->actingAs($owner)
            ->post(route('umkm.products.store'), [
                'category_id' => $category->id,
                'sku' => 'MKN-001',
                'name' => 'Kopi Buruan',
                'slug' => 'kopi-buruan',
                'description' => 'Kopi lokal',
                'price' => '15000.50',
                'stock' => 10,
            ])
            ->assertRedirect(route('umkm.products.index'));

        $this->assertDatabaseHas('products', [
            'umkm_store_id' => $store->id,
            'category_id' => $category->id,
            'sku' => 'MKN-001',
            'status' => 'pending',
        ]);
    }

    public function test_pending_store_owner_cannot_create_products(): void
    {
        $owner = User::factory()->create(['role' => 'penjual']);
        $owner->umkmStore()->create([
            'name' => 'Toko Menunggu',
            'slug' => 'toko-menunggu',
        ]);

        $this->actingAs($owner)
            ->get(route('umkm.products.create'))
            ->assertForbidden();
    }

    public function test_owner_cannot_manage_products_from_another_store(): void
    {
        $owner = User::factory()->create(['role' => 'penjual']);
        $otherOwner = User::factory()->create(['role' => 'penjual']);
        $otherStore = $otherOwner->umkmStore()->create([
            'name' => 'Toko Lain',
            'slug' => 'toko-lain-produk',
        ]);
        $otherStore->forceFill(['status' => 'approved'])->save();
        $category = Category::create(['name' => 'Kerajinan', 'slug' => 'kerajinan-produk']);
        $product = $otherStore->products()->create([
            'category_id' => $category->id,
            'sku' => 'KRY-001',
            'name' => 'Anyaman',
            'slug' => 'anyaman',
            'price' => 25000,
            'stock' => 3,
        ]);

        $this->actingAs($owner)
            ->get(route('umkm.products.edit', $product))
            ->assertForbidden();

        $this->actingAs($owner)
            ->delete(route('umkm.products.destroy', $product))
            ->assertForbidden();
    }
}
