<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ProductRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_bumdes_can_access_category_management(): void
    {
        $this->get(route('admin.categories.index'))
            ->assertRedirect(route('login'));

        $admin = User::factory()->create(['role' => 'admin_bumdes']);

        $this->actingAs($admin)
            ->get(route('admin.categories.index'))
            ->assertOk()
            ->assertSee('Manajemen Kategori Produk');

        foreach (['pelanggan', 'penjual', 'kepala_bumdes', 'kurir'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('admin.categories.index'))
                ->assertForbidden();
        }
    }

    public function test_admin_can_create_update_and_delete_an_unused_category(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);

        $this->actingAs($admin)
            ->post(route('admin.categories.store'), [
                'name' => 'Makanan Lokal',
                'slug' => 'makanan-lokal',
                'description' => 'Produk pangan desa.',
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.categories.index'));

        $category = Category::query()->where('slug', 'makanan-lokal')->firstOrFail();
        $this->assertTrue((bool) $category->is_active);

        $this->actingAs($admin)
            ->put(route('admin.categories.update', $category), [
                'name' => 'Makanan Desa',
                'slug' => 'makanan-desa',
                'description' => 'Kategori diperbarui.',
            ])
            ->assertRedirect(route('admin.categories.index'));

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'Makanan Desa',
            'slug' => 'makanan-desa',
            'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.categories.destroy', $category))
            ->assertRedirect(route('admin.categories.index'));

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_category_name_is_unique_ignoring_letter_case_and_slug_is_unique(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        Category::create(['name' => 'Kerajinan', 'slug' => 'kerajinan']);

        $this->actingAs($admin)
            ->from(route('admin.categories.create'))
            ->post(route('admin.categories.store'), [
                'name' => 'kErAjInAn',
                'slug' => 'kerajinan-baru',
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.categories.create'))
            ->assertSessionHasErrors('name');

        $this->actingAs($admin)
            ->from(route('admin.categories.create'))
            ->post(route('admin.categories.store'), [
                'name' => 'Kategori Baru',
                'slug' => 'kerajinan',
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.categories.create'))
            ->assertSessionHasErrors('slug');
    }

    public function test_category_used_by_a_product_or_revision_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $owner = User::factory()->create(['role' => 'penjual']);
        $store = $owner->umkmStore()->create(['name' => 'Toko Uji', 'slug' => 'toko-uji-kategori']);
        $productCategory = Category::create(['name' => 'Produk', 'slug' => 'produk']);
        $revisionCategory = Category::create(['name' => 'Revisi', 'slug' => 'revisi']);
        $product = $store->products()->create([
            'category_id' => $productCategory->id,
            'sku' => 'KAT-001',
            'name' => 'Produk Uji',
            'slug' => 'produk-uji-kategori',
            'price' => '10000.00',
            'stock' => 1,
        ]);
        ProductRevision::create([
            'product_id' => $product->id,
            'category_id' => $revisionCategory->id,
            'sku' => 'KAT-001',
            'name' => 'Produk Uji Revisi',
            'slug' => 'produk-uji-revisi',
            'price' => '10000.00',
            'status' => 'rejected',
        ]);

        foreach ([$productCategory, $revisionCategory] as $category) {
            $this->actingAs($admin)
                ->delete(route('admin.categories.destroy', $category))
                ->assertRedirect(route('admin.categories.index'))
                ->assertSessionHas('error');

            $this->assertDatabaseHas('categories', ['id' => $category->id]);
        }
    }

    public function test_only_active_categories_are_available_for_new_products_while_current_inactive_category_remains_editable(): void
    {
        $owner = User::factory()->create(['role' => 'penjual']);
        $store = $owner->umkmStore()->create(['name' => 'Toko Produk', 'slug' => 'toko-produk-kategori']);
        $store->forceFill(['status' => 'approved'])->save();
        $active = Category::create(['name' => 'Kategori Aktif', 'slug' => 'kategori-aktif', 'is_active' => true]);
        $inactive = Category::create(['name' => 'Kategori Lama', 'slug' => 'kategori-lama', 'is_active' => false]);
        $product = $store->products()->create([
            'category_id' => $inactive->id,
            'sku' => 'OLD-001',
            'name' => 'Produk Lama',
            'slug' => 'produk-lama-kategori',
            'price' => '12500.00',
            'stock' => 2,
        ]);

        $this->actingAs($owner)
            ->get(route('umkm.products.create'))
            ->assertOk()
            ->assertSee($active->name)
            ->assertDontSee($inactive->name);

        $this->actingAs($owner)
            ->post(route('umkm.products.store'), [
                'category_id' => $inactive->id,
                'sku' => 'NEW-001',
                'name' => 'Produk Baru',
                'slug' => 'produk-baru-kategori',
                'price' => '10000.00',
                'stock' => 1,
            ])
            ->assertSessionHasErrors('category_id');

        $this->actingAs($owner)
            ->get(route('umkm.products.edit', $product))
            ->assertOk()
            ->assertSee('Kategori Lama')
            ->assertSee('nonaktif - kategori saat ini');

        $this->actingAs($owner)
            ->put(route('umkm.products.update', $product), [
                'category_id' => $inactive->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'slug' => $product->slug,
                'price' => '12500.00',
                'stock' => 3,
            ])
            ->assertRedirect(route('umkm.products.index'))
            ->assertSessionDoesntHaveErrors();

        $this->assertSame($inactive->id, $product->fresh()->category_id);
    }
}
