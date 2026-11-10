<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_dashboard_shows_only_own_store_product_counts_and_empty_state(): void
    {
        $seller = User::factory()->create(['role' => 'penjual']);
        $store = $seller->umkmStore()->create(['name' => 'Toko Sendiri', 'slug' => 'toko-sendiri']);
        $store->forceFill(['status' => 'approved'])->save();
        $other = User::factory()->create(['role' => 'penjual']);
        $otherStore = $other->umkmStore()->create(['name' => 'Toko Lain', 'slug' => 'toko-lain']);
        $category = Category::create(['name' => 'Makanan', 'slug' => 'makanan']);
        $otherStore->products()->create(['category_id' => $category->id, 'sku' => 'OTHER-1', 'name' => 'Produk Lain', 'slug' => 'produk-lain', 'price' => '1000.00', 'stock' => 1]);

        $this->actingAs($seller)->get(route('umkm.dashboard'))
            ->assertOk()->assertSee('Toko Sendiri')->assertDontSee('Toko Lain')
            ->assertSee('Belum ada produk di toko')->assertSee('Katalog / Belanja');

        $store->products()->create(['category_id' => $category->id, 'sku' => 'OWN-1', 'name' => 'Produk Sendiri', 'slug' => 'produk-sendiri', 'price' => '1000.00', 'stock' => 1]);
        $this->get(route('umkm.dashboard'))->assertOk()->assertDontSee('Belum ada produk di toko')->assertSee('Menunggu Verifikasi');
    }

    public function test_other_roles_cannot_open_seller_dashboard(): void
    {
        $this->get(route('umkm.dashboard'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'pelanggan']))->get(route('umkm.dashboard'))->assertForbidden();
    }
}
