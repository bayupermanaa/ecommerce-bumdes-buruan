<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_an_approved_product_creates_a_pending_revision_and_keeps_the_catalog_version(): void
    {
        [$owner, $product, $category] = $this->approvedProduct();
        $newCategory = Category::create(['name' => 'Minuman', 'slug' => 'minuman-revision']);

        $this->actingAs($owner)
            ->put(route('umkm.products.update', $product), $this->payload($product, [
                'category_id' => $newCategory->id,
                'name' => 'Kopi Revisi',
                'slug' => 'kopi-revisi',
                'price' => 32000,
                'stock' => 12,
            ]))
            ->assertRedirect(route('umkm.products.index'))
            ->assertSessionHas('status', 'Revisi produk telah dikirim dan menunggu persetujuan Admin BUMDes.');

        $product->refresh();
        $this->assertSame('Kopi Lama', $product->name);
        $this->assertSame('kopi-lama', $product->slug);
        $this->assertSame(12, $product->stock);

        $revision = ProductRevision::where('product_id', $product->id)->sole();
        $this->assertSame('pending', $revision->status);
        $this->assertSame('Kopi Revisi', $revision->name);
        $this->assertSame($newCategory->id, $revision->category_id);

        $this->get(route('catalog.show', $product->slug))
            ->assertOk()
            ->assertSee('Kopi Lama')
            ->assertDontSee('Kopi Revisi');
        $this->get(route('catalog.show', 'kopi-revisi'))->assertNotFound();
    }

    public function test_admin_can_approve_a_revision_and_publish_its_new_photo(): void
    {
        Storage::fake('public');
        [$owner, $product] = $this->approvedProduct('foto-approve');
        Storage::disk('public')->put('products/foto-lama.png', 'old-photo');
        $product->update(['image_path' => 'products/foto-lama.png']);

        $this->actingAs($owner)
            ->put(route('umkm.products.update', $product), $this->payload($product, [
                'name' => 'Kopi Foto Baru',
                'image' => UploadedFile::fake()->image('foto-baru.png'),
            ]))
            ->assertRedirect(route('umkm.products.index'));

        $revision = ProductRevision::where('product_id', $product->id)->sole();
        $this->assertNotNull($revision->image_path);
        Storage::disk('public')->assertExists('products/foto-lama.png');
        Storage::disk('public')->assertExists($revision->image_path);

        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $this->actingAs($admin)
            ->patch(route('admin.product-revisions.approve', $revision))
            ->assertRedirect(route('admin.product-revisions.index'));

        $product->refresh();
        $revision->refresh();
        $this->assertSame('approved', $revision->status);
        $this->assertSame('Kopi Foto Baru', $product->name);
        $this->assertSame($revision->image_path, $product->image_path);
        Storage::disk('public')->assertMissing('products/foto-lama.png');
        Storage::disk('public')->assertExists($product->image_path);
    }

    public function test_rejected_revision_does_not_change_the_published_product_or_its_photo(): void
    {
        Storage::fake('public');
        [$owner, $product] = $this->approvedProduct('foto-reject');
        Storage::disk('public')->put('products/foto-publik.png', 'published-photo');
        $product->update(['image_path' => 'products/foto-publik.png']);

        $this->actingAs($owner)
            ->put(route('umkm.products.update', $product), $this->payload($product, [
                'name' => 'Kopi Tidak Jadi',
                'image' => UploadedFile::fake()->image('foto-usulan.png'),
            ]));

        $revision = ProductRevision::where('product_id', $product->id)->sole();
        $admin = User::factory()->create(['role' => 'admin_bumdes']);

        $this->actingAs($admin)
            ->patch(route('admin.product-revisions.reject', $revision), [
                'rejection_reason' => 'Informasi foto perlu diperbaiki.',
            ])
            ->assertRedirect(route('admin.product-revisions.index'));

        $product->refresh();
        $revision->refresh();
        $this->assertSame('Kopi Lama', $product->name);
        $this->assertSame('products/foto-publik.png', $product->image_path);
        $this->assertSame('rejected', $revision->status);
        $this->assertSame('Informasi foto perlu diperbaiki.', $revision->rejection_reason);
        Storage::disk('public')->assertExists('products/foto-publik.png');
        Storage::disk('public')->assertExists($revision->image_path);
    }

    public function test_only_one_pending_revision_can_exist_for_a_product(): void
    {
        [$owner, $product] = $this->approvedProduct('single-pending');

        $this->actingAs($owner)
            ->put(route('umkm.products.update', $product), $this->payload($product, ['name' => 'Revisi Pertama']))
            ->assertSessionHas('status');

        $this->actingAs($owner)
            ->put(route('umkm.products.update', $product), $this->payload($product, ['name' => 'Revisi Kedua']))
            ->assertRedirect(route('umkm.products.index'))
            ->assertSessionHas('error', 'Produk ini sudah memiliki revisi yang menunggu persetujuan.');

        $this->assertSame(1, ProductRevision::where('product_id', $product->id)->where('status', 'pending')->count());
    }

    public function test_other_owner_cannot_create_a_revision_for_another_stores_product(): void
    {
        [, $product] = $this->approvedProduct('other-owner');
        $otherOwner = User::factory()->create(['role' => 'pemilik_umkm']);
        $otherOwner->umkmStore()->create(['name' => 'Toko Pemilik Lain', 'slug' => 'toko-pemilik-lain-revision']);

        $this->actingAs($otherOwner)
            ->put(route('umkm.products.update', $product), $this->payload($product, ['name' => 'Tidak Diizinkan']))
            ->assertForbidden();
    }

    public function test_non_admin_cannot_review_a_revision_and_a_revision_cannot_be_processed_twice(): void
    {
        [$owner, $product] = $this->approvedProduct('review-access');
        $this->actingAs($owner)
            ->put(route('umkm.products.update', $product), $this->payload($product, ['name' => 'Perlu Review']));
        $revision = ProductRevision::where('product_id', $product->id)->sole();

        $this->actingAs($owner)
            ->patch(route('admin.product-revisions.approve', $revision))
            ->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $this->actingAs($admin)
            ->patch(route('admin.product-revisions.approve', $revision))
            ->assertSessionHas('status');

        $this->actingAs($admin)
            ->patch(route('admin.product-revisions.approve', $revision))
            ->assertSessionHas('error', 'Revisi ini sudah diproses dan tidak dapat disetujui kembali.');
    }

    /**
     * @return array{0: User, 1: Product, 2: Category}
     */
    private function approvedProduct(string $suffix = 'default'): array
    {
        $owner = User::factory()->create(['role' => 'pemilik_umkm']);
        $store = $owner->umkmStore()->create([
            'name' => "Toko {$suffix}",
            'slug' => "toko-{$suffix}",
        ]);
        $store->forceFill(['status' => 'approved'])->save();
        $category = Category::create(['name' => "Makanan {$suffix}", 'slug' => "makanan-{$suffix}"]);
        $product = $store->products()->create([
            'category_id' => $category->id,
            'sku' => "SKU-{$suffix}",
            'name' => 'Kopi Lama',
            'slug' => 'kopi-lama'.($suffix === 'default' ? '' : "-{$suffix}"),
            'description' => 'Deskripsi lama',
            'price' => 25000,
            'stock' => 8,
        ]);
        $product->forceFill(['status' => 'approved', 'verified_at' => now()])->save();

        return [$owner, $product, $category];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Product $product, array $overrides = []): array
    {
        return array_merge([
            'category_id' => $product->category_id,
            'sku' => $product->sku,
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'price' => $product->price,
            'stock' => $product->stock,
        ], $overrides);
    }
}
