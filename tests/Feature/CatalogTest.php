<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_customer_sees_post_logout_actions_while_guest_does_not(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);

        $this->actingAs($customer)
            ->get(route('catalog.index'))
            ->assertOk()
            ->assertSee('data-testid="customer-logout-desktop"', false)
            ->assertSee('data-testid="customer-logout-mobile"', false)
            ->assertSee('action="'.route('logout').'"', false)
            ->assertSeeText('Keluar');

        $this->post(route('logout'));

        $this->get(route('catalog.index'))
            ->assertOk()
            ->assertDontSee('data-testid="customer-logout-desktop"', false)
            ->assertDontSee('data-testid="customer-logout-mobile"', false)
            ->assertDontSeeText('Keluar');
    }

    public function test_homepage_redirects_to_the_public_catalog(): void
    {
        $this->get('/')
            ->assertRedirect(route('catalog.index'));
    }

    public function test_catalog_only_shows_approved_products_from_approved_stores(): void
    {
        $category = Category::create(['name' => 'Makanan', 'slug' => 'makanan-catalog']);
        $visible = $this->createProduct('Kopi Bubuk Desa Buruan', 'kopi-visible', $category, 'approved', 'approved');
        $pendingProduct = $this->createProduct('Produk Pending', 'produk-pending', $category, 'approved', 'pending');
        $rejectedProduct = $this->createProduct('Produk Rejected', 'produk-rejected', $category, 'approved', 'rejected');
        $pendingStoreProduct = $this->createProduct('Produk Toko Pending', 'store-pending', $category, 'pending', 'approved');
        $rejectedStoreProduct = $this->createProduct('Produk Toko Rejected', 'store-rejected', $category, 'rejected', 'approved');

        $this->get(route('catalog.index'))
            ->assertOk()
            ->assertSee($visible->name)
            ->assertDontSee([$pendingProduct->name, $rejectedProduct->name, $pendingStoreProduct->name, $rejectedStoreProduct->name]);
    }

    public function test_catalog_can_search_and_filter_by_category(): void
    {
        $food = Category::create(['name' => 'Makanan', 'slug' => 'makanan-search']);
        $craft = Category::create(['name' => 'Kerajinan', 'slug' => 'kerajinan-search']);
        $coffee = $this->createProduct('Kopi Buruan', 'kopi-search', $food, 'approved', 'approved');
        $basket = $this->createProduct('Anyaman Bambu', 'bambu-search', $craft, 'approved', 'approved');

        $this->get(route('catalog.index', ['search' => 'Kopi']))
            ->assertOk()
            ->assertSee($coffee->name)
            ->assertDontSee($basket->name);

        $this->get(route('catalog.index', ['category' => $craft->id]))
            ->assertOk()
            ->assertSee($basket->name)
            ->assertDontSee($coffee->name);
    }

    public function test_approved_product_has_a_public_detail_page(): void
    {
        $category = Category::create(['name' => 'Minuman', 'slug' => 'minuman-detail']);
        $product = $this->createProduct('Teh Desa Buruan', 'teh-detail', $category, 'approved', 'approved');

        $this->get(route('catalog.show', $product->slug))
            ->assertOk()
            ->assertSee([
                $product->name,
                $product->umkmStore->name,
                $category->name,
                'Harga dan ketersediaan stok akan diperiksa kembali saat checkout.',
            ])
            ->assertDontSee('Checkout akan tersedia pada Tahap 7B.');
    }

    public function test_unapproved_product_or_store_cannot_be_opened_through_catalog_url(): void
    {
        $category = Category::create(['name' => 'Jasa', 'slug' => 'jasa-direct']);
        $pendingProduct = $this->createProduct('Produk Rahasia', 'pending-direct', $category, 'approved', 'pending');
        $rejectedProduct = $this->createProduct('Produk Ditolak', 'rejected-direct', $category, 'approved', 'rejected');
        $storePendingProduct = $this->createProduct('Produk Toko Rahasia', 'store-pending-direct', $category, 'pending', 'approved');

        $this->get(route('catalog.show', $pendingProduct->slug))->assertNotFound();
        $this->get(route('catalog.show', $rejectedProduct->slug))->assertNotFound();
        $this->get(route('catalog.show', $storePendingProduct->slug))->assertNotFound();
    }

    public function test_owner_can_upload_a_valid_product_photo(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create(['role' => 'pemilik_umkm']);
        $store = $owner->umkmStore()->create(['name' => 'Toko Foto', 'slug' => 'toko-foto']);
        $store->forceFill(['status' => 'approved'])->save();
        $category = Category::create(['name' => 'Fashion', 'slug' => 'fashion-photo']);
        $image = UploadedFile::fake()->image('kopi.png', 600, 600);

        $this->actingAs($owner)
            ->post(route('umkm.products.store'), [
                'category_id' => $category->id,
                'sku' => 'FOTO-001',
                'name' => 'Produk Dengan Foto',
                'slug' => 'produk-dengan-foto',
                'price' => 20000,
                'stock' => 4,
                'image' => $image,
            ])
            ->assertRedirect(route('umkm.products.index'));

        $product = Product::where('sku', 'FOTO-001')->firstOrFail();

        $this->assertNotNull($product->image_path);
        Storage::disk('public')->assertExists($product->image_path);
    }

    public function test_invalid_product_photo_is_rejected(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create(['role' => 'pemilik_umkm']);
        $store = $owner->umkmStore()->create(['name' => 'Toko File', 'slug' => 'toko-file']);
        $store->forceFill(['status' => 'approved'])->save();
        $category = Category::create(['name' => 'Pertanian', 'slug' => 'pertanian-photo']);

        $this->actingAs($owner)
            ->from(route('umkm.products.create'))
            ->post(route('umkm.products.store'), [
                'category_id' => $category->id,
                'sku' => 'FILE-001',
                'name' => 'Produk File Salah',
                'slug' => 'produk-file-salah',
                'price' => 20000,
                'stock' => 4,
                'image' => UploadedFile::fake()->create('dokumen.pdf', 100, 'application/pdf'),
            ])
            ->assertSessionHasErrors('image');
    }

    public function test_owner_cannot_upload_a_photo_to_another_stores_product(): void
    {
        Storage::fake('public');
        $category = Category::create(['name' => 'Kerajinan', 'slug' => 'kerajinan-photo-access']);
        $product = $this->createProduct('Produk Toko Lain', 'other-photo', $category, 'approved', 'approved');
        $otherOwner = User::factory()->create(['role' => 'pemilik_umkm']);
        $otherStore = $otherOwner->umkmStore()->create(['name' => 'Toko Lain', 'slug' => 'toko-lain-photo']);
        $otherStore->forceFill(['status' => 'approved'])->save();

        $this->actingAs($otherOwner)
            ->put(route('umkm.products.update', $product), [
                'category_id' => $category->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'slug' => $product->slug,
                'price' => $product->price,
                'stock' => $product->stock,
                'image' => UploadedFile::fake()->image('not-allowed.png'),
            ])
            ->assertForbidden();
    }

    private function createProduct(string $name, string $suffix, Category $category, string $storeStatus, string $productStatus): Product
    {
        $owner = User::factory()->create(['role' => 'pemilik_umkm']);
        $store = $owner->umkmStore()->create([
            'name' => "Toko {$suffix}",
            'slug' => "toko-{$suffix}",
        ]);
        $store->forceFill(['status' => $storeStatus])->save();

        $product = $store->products()->create([
            'category_id' => $category->id,
            'sku' => "SKU-{$suffix}",
            'name' => $name,
            'slug' => "produk-{$suffix}",
            'description' => 'Deskripsi produk untuk katalog.',
            'price' => 27000,
            'stock' => 20,
        ]);
        $product->forceFill(['status' => $productStatus])->save();

        return $product;
    }
}
