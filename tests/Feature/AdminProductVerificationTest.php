<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProductVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_and_filter_products_by_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $category = Category::create(['name' => 'Makanan', 'slug' => 'makanan-admin-filter']);
        $pending = $this->createProduct('Produk Pending', 'pending-product', $category);
        $approved = $this->createProduct('Produk Approved', 'approved-product', $category, 'approved');
        $rejected = $this->createProduct('Produk Rejected', 'rejected-product', $category, 'rejected');

        $this->actingAs($admin)
            ->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee([$pending->name, $approved->name, $rejected->name]);

        $this->actingAs($admin)
            ->get(route('admin.products.index', ['status' => 'pending']))
            ->assertOk()
            ->assertSee($pending->name)
            ->assertDontSee([$approved->name, $rejected->name]);

        $this->actingAs($admin)
            ->get(route('admin.products.index', ['status' => 'approved']))
            ->assertOk()
            ->assertSee($approved->name)
            ->assertDontSee([$pending->name, $rejected->name]);

        $this->actingAs($admin)
            ->get(route('admin.products.index', ['status' => 'rejected']))
            ->assertOk()
            ->assertSee($rejected->name)
            ->assertDontSee([$pending->name, $approved->name]);
    }

    public function test_pending_product_renders_approve_and_reject_actions_in_the_html(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $category = Category::create(['name' => 'Minuman', 'slug' => 'minuman-admin-actions']);
        $product = $this->createProduct('Kopi Bubuk Desa Buruan', 'kopi-bubuk-actions', $category);

        $this->actingAs($admin)
            ->get(route('admin.products.index', ['status' => 'pending']))
            ->assertOk()
            ->assertSee('data-testid="approve-product-'.$product->id.'"', false)
            ->assertSee('data-testid="reject-product-'.$product->id.'"', false)
            ->assertSee('APPROVE')
            ->assertSee('REJECT')
            ->assertSee(route('admin.products.approve', $product), false)
            ->assertSee(route('admin.products.reject', $product), false);
    }

    public function test_processed_products_do_not_render_approve_or_reject_actions(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $category = Category::create(['name' => 'Oleh-Oleh', 'slug' => 'oleh-oleh-admin-actions']);
        $approved = $this->createProduct('Produk Selesai Approved', 'approved-actions', $category, 'approved');
        $rejected = $this->createProduct('Produk Selesai Rejected', 'rejected-actions', $category, 'rejected');

        $this->actingAs($admin)
            ->get(route('admin.products.index'))
            ->assertOk()
            ->assertDontSee('data-testid="approve-product-'.$approved->id.'"', false)
            ->assertDontSee('data-testid="reject-product-'.$approved->id.'"', false)
            ->assertDontSee('data-testid="approve-product-'.$rejected->id.'"', false)
            ->assertDontSee('data-testid="reject-product-'.$rejected->id.'"', false);
    }

    public function test_admin_can_approve_a_pending_product(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $category = Category::create(['name' => 'Kerajinan', 'slug' => 'kerajinan-admin-approve']);
        $product = $this->createProduct('Produk Untuk Disetujui', 'product-approve', $category);

        $this->actingAs($admin)
            ->patch(route('admin.products.approve', $product), ['return_status' => 'pending'])
            ->assertRedirect(route('admin.products.index', ['status' => 'pending']))
            ->assertSessionHas('status', 'Produk berhasil disetujui.');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'status' => 'approved',
            'rejection_reason' => null,
        ]);
        $this->assertNotNull($product->fresh()->verified_at);
    }

    public function test_admin_can_reject_a_pending_product_with_a_reason(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $category = Category::create(['name' => 'Fashion', 'slug' => 'fashion-admin-reject']);
        $product = $this->createProduct('Produk Untuk Ditolak', 'product-reject', $category);

        $this->actingAs($admin)
            ->patch(route('admin.products.reject', $product), [
                'rejection_reason' => 'Foto produk belum memenuhi ketentuan.',
            ])
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHas('status', 'Produk ditolak.');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'status' => 'rejected',
            'rejection_reason' => 'Foto produk belum memenuhi ketentuan.',
        ]);
    }

    public function test_admin_cannot_process_an_already_approved_product_again(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $category = Category::create(['name' => 'Pertanian', 'slug' => 'pertanian-admin-repeat']);
        $product = $this->createProduct('Produk Sudah Approved', 'product-repeat', $category, 'approved');

        $this->actingAs($admin)
            ->patch(route('admin.products.approve', $product))
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHas('error', 'Produk ini sudah diproses dan tidak dapat disetujui kembali.');

        $this->assertSame('approved', $product->fresh()->status);
    }

    public function test_non_admin_cannot_verify_products(): void
    {
        $owner = User::factory()->create(['role' => 'penjual']);
        $store = $owner->umkmStore()->create([
            'name' => 'Toko Pemilik Produk',
            'slug' => 'toko-pemilik-produk-admin',
        ]);
        $category = Category::create(['name' => 'Jasa', 'slug' => 'jasa-admin-access']);
        $product = $store->products()->create([
            'category_id' => $category->id,
            'sku' => 'ACCESS-001',
            'name' => 'Produk Pemilik',
            'slug' => 'produk-pemilik',
            'price' => 10000,
            'stock' => 1,
        ]);

        $this->actingAs($owner)
            ->get(route('admin.products.index'))
            ->assertForbidden();

        $this->actingAs($owner)
            ->patch(route('admin.products.approve', $product))
            ->assertForbidden();

        $this->actingAs($owner)
            ->patch(route('admin.products.reject', $product), [
                'rejection_reason' => 'Tidak berwenang.',
            ])
            ->assertForbidden();
    }

    private function createProduct(string $name, string $suffix, Category $category, string $status = 'pending'): Product
    {
        $owner = User::factory()->create(['role' => 'penjual']);
        $store = $owner->umkmStore()->create([
            'name' => "Toko {$suffix}",
            'slug' => "toko-{$suffix}",
        ]);
        $product = $store->products()->create([
            'category_id' => $category->id,
            'sku' => "SKU-{$suffix}",
            'name' => $name,
            'slug' => "slug-{$suffix}",
            'price' => 15000,
            'stock' => 5,
        ]);

        if ($status !== 'pending') {
            $product->forceFill([
                'status' => $status,
                'verified_at' => now(),
                'rejection_reason' => $status === 'rejected' ? 'Dokumen belum lengkap.' : null,
            ])->save();
        }

        return $product;
    }
}
