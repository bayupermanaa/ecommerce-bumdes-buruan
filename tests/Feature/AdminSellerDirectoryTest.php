<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\UmkmStore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminSellerDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_empty_directory_and_real_dashboard_counts(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);

        $this->actingAs($admin)->get(route('admin.sellers.index'))
            ->assertOk()
            ->assertSee('Belum ada penjual terdaftar')
            ->assertViewHas('stores', fn ($stores) => $stores->total() === 0);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Data Penjual')
            ->assertViewHas('operationalSummary', fn ($summary) => $summary === [
                'stores' => 0,
                'pending_stores' => 0,
                'approved_stores' => 0,
                'products' => 0,
                'orders' => 0,
            ]);
    }

    public function test_search_status_and_sort_can_be_combined_without_changing_verification(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $older = $this->store('Toko Alfa', 'Rina Penjual', 'rina@example.test', 'approved');
        $newer = $this->store('Toko Zeta', 'Rina Lain', 'rina.lain@example.test', 'approved');
        $other = $this->store('Toko Lain', 'Budi', 'budi@example.test', 'pending');

        $response = $this->actingAs($admin)->get(route('admin.sellers.index', [
            'search' => 'rina', 'status' => 'approved', 'sort' => 'name_desc',
        ]))->assertOk()->assertDontSee($other->name)->assertViewHas('stores', function ($stores) use ($older, $newer) {
            return $stores->total() === 2 && $stores->pluck('id')->all() === [$newer->id, $older->id];
        });

        $response->assertSee('2 hasil sesuai filter');
        $this->actingAs($admin)->get(route('admin.sellers.index', [
            'search' => 'rina', 'status' => 'approved', 'sort' => 'name_asc',
        ]))->assertViewHas('stores', fn ($stores) => $stores->pluck('id')->all() === [$older->id, $newer->id]);
        $this->actingAs($admin)->get(route('admin.sellers.index', [
            'search' => 'rina', 'status' => 'approved', 'sort' => 'oldest',
        ]))->assertViewHas('stores', fn ($stores) => $stores->pluck('id')->all() === [$older->id, $newer->id]);
        $this->assertSame('approved', $older->fresh()->status);
        $this->assertSame('approved', $newer->fresh()->status);
    }

    public function test_dashboard_operational_counts_reflect_persisted_rows(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $pending = $this->store('Toko Tunggu', 'Nina', 'nina@example.test', 'pending');
        $approved = $this->store('Toko Aktif', 'Doni', 'doni@example.test', 'approved');
        $category = Category::query()->create(['name' => 'Kategori Hitung', 'slug' => 'kategori-hitung', 'is_active' => true]);
        $approved->products()->create(['category_id' => $category->id, 'sku' => 'SKU-COUNT-1', 'name' => 'Produk Hitung', 'slug' => 'produk-hitung', 'price' => '10000.00', 'stock' => 1]);
        DB::table('orders')->insert([
            'order_number' => 'ORDER-COUNT-1', 'checkout_token' => 'TOKEN-COUNT-1', 'user_id' => $admin->id,
            'fulfillment_method' => 'pickup', 'recipient_name' => 'Penerima Sintetis', 'recipient_phone' => '0800000000',
            'subtotal' => '10000.00', 'shipping_total' => '0.00', 'grand_total' => '10000.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewHas('operationalSummary', fn ($summary) => $summary === [
                'stores' => 2,
                'pending_stores' => 1,
                'approved_stores' => 1,
                'products' => 1,
                'orders' => 1,
            ]);
        $this->assertSame('pending', $pending->fresh()->status);
    }

    public function test_search_by_store_and_email_and_all_statuses_are_available(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $pending = $this->store('Kedai Buruan', 'Dina', 'dina@example.test', 'pending');
        $approved = $this->store('Pusat Kerajinan', 'Sari', 'sari@example.test', 'approved');
        $rejected = $this->store('Toko Ditolak', 'Tina', 'tina@example.test', 'rejected');

        $this->actingAs($admin)->get(route('admin.sellers.index', ['search' => 'Kedai']))
            ->assertViewHas('stores', fn ($stores) => $stores->total() === 1 && $stores->first()->id === $pending->id);
        $this->actingAs($admin)->get(route('admin.sellers.index', ['search' => 'sari@example.test']))
            ->assertViewHas('stores', fn ($stores) => $stores->total() === 1 && $stores->first()->id === $approved->id);
        $this->actingAs($admin)->get(route('admin.sellers.index', ['status' => 'rejected']))
            ->assertViewHas('stores', fn ($stores) => $stores->total() === 1 && $stores->first()->id === $rejected->id);
        $this->actingAs($admin)->get(route('admin.sellers.index', ['search' => 'tidak-ada']))
            ->assertOk()->assertSee('Tidak ada toko yang cocok');
    }

    public function test_pagination_preserves_filters_and_orders_equal_timestamps_by_id(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $ids = [];

        foreach (range(1, 17) as $i) {
            $ids[] = $this->store('Toko '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'Pemilik '.$i, 'owner'.$i.'@example.test', 'approved')->id;
        }

        $this->actingAs($admin)->get(route('admin.sellers.index', ['status' => 'approved']))
            ->assertOk()
            ->assertViewHas('stores', fn ($stores) => $stores->total() === 17 && $stores->count() === 15 && $stores->first()->id === end($ids))
            ->assertSee('status=approved', false);

        $this->actingAs($admin)->get(route('admin.sellers.index', ['status' => 'approved', 'page' => 2]))
            ->assertOk()
            ->assertViewHas('stores', fn ($stores) => $stores->total() === 17 && $stores->count() === 2 && $stores->last()->id === $ids[0]);
    }

    public function test_detail_shows_owner_and_only_a_limited_product_summary(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $store = $this->store('Toko Bintang', 'Ayu', 'ayu@example.test', 'approved');
        $other = $this->store('Toko Sebelah', 'Bima', 'bima@example.test', 'approved');
        $category = Category::query()->create(['name' => 'Kategori Uji', 'slug' => 'kategori-uji', 'is_active' => true]);
        $store->products()->create(['category_id' => $category->id, 'sku' => 'SKU-DIR-1', 'name' => 'Produk Bintang', 'slug' => 'produk-bintang', 'price' => '150000.50', 'stock' => 1]);
        $other->products()->create(['category_id' => $category->id, 'sku' => 'SKU-DIR-2', 'name' => 'Produk Sebelah', 'slug' => 'produk-sebelah', 'price' => '20000.00', 'stock' => 1]);

        $this->actingAs($admin)->get(route('admin.sellers.show', $store))
            ->assertOk()->assertSee(['Toko Bintang', 'Ayu', 'ayu@example.test', 'Produk Bintang'])
            ->assertDontSee(['Produk Sebelah', 'bima@example.test'])
            ->assertViewHas('store', fn ($shown) => $shown->products_count === 1);
    }

    public function test_only_admin_can_read_directory_and_invalid_filters_are_rejected(): void
    {
        $store = $this->store('Toko Aman', 'Pemilik Aman', 'aman@example.test', 'pending');
        $this->get(route('admin.sellers.index'))->assertRedirect(route('login'));
        $this->get(route('admin.sellers.show', $store))->assertRedirect(route('login'));

        foreach (['pelanggan', 'penjual', 'kurir', 'kepala_bumdes'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('admin.sellers.index'))->assertForbidden();
            $this->get(route('admin.sellers.show', $store))->assertForbidden();
        }

        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $this->actingAs($admin)->get(route('admin.sellers.index', ['status' => 'invalid']))
            ->assertSessionHasErrors('status');
        $this->actingAs($admin)->get(route('admin.sellers.index', ['sort' => 'invalid']))
            ->assertSessionHasErrors('sort');
    }

    private function store(string $name, string $ownerName, string $email, string $status): UmkmStore
    {
        $owner = User::factory()->create(['name' => $ownerName, 'email' => $email, 'role' => 'penjual']);

        $store = $owner->umkmStore()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString().'-'.$owner->id,
            'phone' => '0800000000',
            'address' => 'Alamat sintetis',
        ]);
        $store->forceFill(['status' => $status])->save();

        return $store;
    }
}
