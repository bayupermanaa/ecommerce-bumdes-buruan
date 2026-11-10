<?php

namespace Tests\Feature;

use App\Models\UmkmStore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminUmkmStoreVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_store_detail_and_return_to_filtered_verification_list(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('store-logos/synthetic.png', 'synthetic logo');
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $owner = User::factory()->create(['role' => 'pelanggan', 'name' => 'Pemilik Sintetis', 'email' => 'pemilik@example.test']);
        $store = $owner->umkmStore()->create([
            'name' => 'Toko Sintetis', 'slug' => 'toko-sintetis',
            'phone' => '08000000123', 'address' => 'Alamat toko lengkap',
            'description' => 'Usaha kerajinan sintetis', 'logo' => 'store-logos/synthetic.png',
        ]);
        $detailUrl = route('admin.sellers.show', ['store' => $store, 'return_status' => 'pending']);

        $this->actingAs($admin)->get(route('admin.umkm-stores.index', ['status' => 'pending']))
            ->assertOk()->assertSee('Detail Toko')->assertSee($detailUrl, false);

        $this->actingAs($admin)->get($detailUrl)
            ->assertOk()
            ->assertSee(['Toko Sintetis', 'Pemilik Sintetis', 'pemilik@example.test', '08000000123', 'Alamat toko lengkap', 'Usaha kerajinan sintetis', 'Menunggu verifikasi'])
            ->assertSee('src="/storage/store-logos/synthetic.png"', false)
            ->assertSee(route('admin.umkm-stores.index', ['status' => 'pending']), false)
            ->assertSee('Kembali ke Verifikasi Toko')
            ->assertSee('Setujui Toko')
            ->assertSee('Tolak Toko');

        $this->assertSame('pending', $store->fresh()->status);
        $this->assertSame('pelanggan', $owner->fresh()->role);
    }

    public function test_logo_url_uses_the_current_website_host_and_has_a_load_failure_fallback(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('store-logos/host-test.jpg', 'synthetic logo');
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $owner = User::factory()->create(['role' => 'pelanggan']);
        $store = $owner->umkmStore()->create([
            'name' => 'Toko Host', 'slug' => 'toko-host', 'logo' => 'store-logos/host-test.jpg',
        ]);

        $this->actingAs($admin)
            ->withServerVariables(['HTTP_HOST' => 'ecommerce-bumdes-buruan.test'])
            ->get('/admin/penjual/'.$store->id)
            ->assertOk()
            ->assertSee('src="/storage/store-logos/host-test.jpg"', false)
            ->assertDontSee('src="http://localhost/storage/store-logos/host-test.jpg"', false)
            ->assertSee('Logo toko tidak dapat dimuat.');
    }

    public function test_detail_reuses_official_approval_and_rejection_routes(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $owner = User::factory()->create(['role' => 'pelanggan']);
        $store = $owner->umkmStore()->create(['name' => 'Toko Keputusan', 'slug' => 'toko-keputusan']);

        $this->actingAs($admin)->get(route('admin.sellers.show', $store))
            ->assertOk()
            ->assertSee(route('admin.umkm-stores.approve', $store), false)
            ->assertSee(route('admin.umkm-stores.reject', $store), false)
            ->assertSee("confirm('Setujui pengajuan toko ini?')", false);

        $this->actingAs($admin)->patch(route('admin.umkm-stores.approve', $store), ['return_status' => 'pending'])
            ->assertRedirect(route('admin.umkm-stores.index', ['status' => 'pending']));
        $this->assertSame('approved', $store->fresh()->status);
        $this->assertSame('penjual', $owner->fresh()->role);
        $this->actingAs($admin)->get(route('admin.sellers.show', $store))
            ->assertOk()->assertDontSee('Setujui Toko')->assertDontSee('Tolak Toko');
    }

    public function test_rejected_detail_shows_reason_and_missing_logo_without_actions(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $owner = User::factory()->create(['role' => 'pelanggan']);
        $store = $owner->umkmStore()->create(['name' => 'Toko Ditolak Detail', 'slug' => 'toko-ditolak-detail']);
        $store->forceFill(['status' => 'rejected', 'rejection_reason' => 'Alamat belum lengkap.'])->save();

        $this->actingAs($admin)->get(route('admin.sellers.show', ['store' => $store, 'return_status' => 'rejected']))
            ->assertOk()
            ->assertSee(['Ditolak', 'Alamat belum lengkap.', 'Logo toko belum tersedia.'])
            ->assertSee(route('admin.umkm-stores.index', ['status' => 'rejected']), false)
            ->assertDontSee('Setujui Toko');
    }

    public function test_summary_search_status_and_oldest_sort_use_actual_store_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $firstOwner = User::factory()->create(['role' => 'pelanggan', 'name' => 'Rina Pertama', 'email' => 'rina.first@example.test']);
        $first = $firstOwner->umkmStore()->create(['name' => 'Toko Alfa', 'slug' => 'toko-alfa-filter']);
        $secondOwner = User::factory()->create(['role' => 'pelanggan', 'name' => 'Rina Kedua', 'email' => 'rina.second@example.test']);
        $second = $secondOwner->umkmStore()->create(['name' => 'Toko Zeta', 'slug' => 'toko-zeta-filter']);
        $thirdOwner = User::factory()->create(['role' => 'pelanggan', 'name' => 'Budi', 'email' => 'budi@example.test']);
        $third = $thirdOwner->umkmStore()->create(['name' => 'Toko Lain', 'slug' => 'toko-lain-filter']);
        $third->forceFill(['status' => 'approved'])->save();
        $rejectedOwner = User::factory()->create(['role' => 'pelanggan']);
        $rejected = $rejectedOwner->umkmStore()->create(['name' => 'Toko Ditolak', 'slug' => 'toko-ditolak-filter']);
        $rejected->forceFill(['status' => 'rejected'])->save();

        $this->actingAs($admin)->get(route('admin.umkm-stores.index', [
            'search' => 'rina', 'status' => 'pending', 'sort' => 'oldest',
        ]))->assertOk()
            ->assertViewHas('statusCounts', fn ($counts) => (int) $counts['pending'] === 2 && (int) $counts['approved'] === 1 && (int) $counts['rejected'] === 1)
            ->assertViewHas('stores', fn ($stores) => $stores->total() === 2 && $stores->pluck('id')->all() === [$first->id, $second->id])
            ->assertDontSee(['Toko Lain', 'Toko Ditolak'])
            ->assertSee('2 toko sesuai filter')
            ->assertSee(['Menunggu verifikasi', 'Disetujui', 'Ditolak', 'Reset Filter']);

        $this->actingAs($admin)->get(route('admin.umkm-stores.index', ['search' => 'Toko Zeta']))
            ->assertViewHas('stores', fn ($stores) => $stores->total() === 1 && $stores->first()->id === $second->id);
        $this->actingAs($admin)->get(route('admin.umkm-stores.index', ['search' => 'rina.first@example.test']))
            ->assertViewHas('stores', fn ($stores) => $stores->total() === 1 && $stores->first()->id === $first->id);
        $this->actingAs($admin)->get(route('admin.umkm-stores.index', ['search' => 'tidak-ada']))
            ->assertOk()->assertSee('Tidak ada pengajuan yang cocok');
    }

    public function test_pagination_and_detail_return_preserve_search_status_sort_and_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $ids = [];
        foreach (range(1, 17) as $i) {
            $owner = User::factory()->create(['role' => 'pelanggan', 'name' => 'Pemilik Cari '.$i]);
            $ids[] = $owner->umkmStore()->create(['name' => 'Toko Cari '.$i, 'slug' => 'toko-cari-'.$i])->id;
        }
        $params = ['search' => 'Cari', 'status' => 'pending', 'sort' => 'oldest'];

        $this->actingAs($admin)->get(route('admin.umkm-stores.index', $params))
            ->assertOk()
            ->assertViewHas('stores', fn ($stores) => $stores->total() === 17 && $stores->count() === 15 && $stores->first()->id === $ids[0])
            ->assertSee('page=2', false);
        $pageTwo = $this->actingAs($admin)->get(route('admin.umkm-stores.index', $params + ['page' => 2]))
            ->assertOk()
            ->assertViewHas('stores', fn ($stores) => $stores->total() === 17 && $stores->count() === 2 && $stores->last()->id === $ids[16])
            ->assertSee('return_page=2', false);

        $store = UmkmStore::query()->findOrFail($ids[15]);
        $detail = $this->actingAs($admin)->get(route('admin.sellers.show', [
            'store' => $store, 'return_search' => 'Cari', 'return_status' => 'pending', 'return_sort' => 'oldest', 'return_page' => 2,
        ]))->assertOk()
            ->assertViewHas('returnFilters', fn ($filters) => $filters['search'] === 'Cari' && $filters['status'] === 'pending' && $filters['sort'] === 'oldest' && (int) $filters['page'] === 2)
            ->assertSee('name="return_search" value="Cari"', false)
            ->assertSee('name="return_page" value="2"', false);
        $detail->assertSee('Kembali ke Verifikasi Toko');

        $this->actingAs($admin)->patch(route('admin.umkm-stores.approve', $store), [
            'return_search' => 'Cari', 'return_status' => 'pending', 'return_sort' => 'oldest', 'return_page' => 2,
        ])->assertRedirect(route('admin.umkm-stores.index', [
            'status' => 'pending', 'search' => 'Cari', 'sort' => 'oldest', 'page' => 2,
        ]));
        $this->assertSame('approved', $store->fresh()->status);
    }

    public function test_invalid_review_filters_are_rejected_without_mutating_stores(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $owner = User::factory()->create(['role' => 'pelanggan']);
        $store = $owner->umkmStore()->create(['name' => 'Toko Aman', 'slug' => 'toko-aman-filter']);

        $this->actingAs($admin)->get(route('admin.umkm-stores.index', ['status' => 'invalid']))->assertSessionHasErrors('status');
        $this->actingAs($admin)->get(route('admin.umkm-stores.index', ['sort' => 'invalid']))->assertSessionHasErrors('sort');
        $this->actingAs($admin)->get(route('admin.umkm-stores.index', ['search' => str_repeat('x', 101)]))->assertSessionHasErrors('search');
        $this->assertSame('pending', $store->fresh()->status);
    }

    public function test_admin_can_filter_stores_by_each_available_status_and_approve_one(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $owner = User::factory()->create(['role' => 'penjual']);
        $store = $owner->umkmStore()->create([
            'name' => 'Toko Pending',
            'slug' => 'toko-pending-admin',
        ]);
        $approvedOwner = User::factory()->create(['role' => 'penjual']);
        $approvedStore = $approvedOwner->umkmStore()->create([
            'name' => 'Toko Approved',
            'slug' => 'toko-approved-admin',
        ]);
        $approvedStore->forceFill(['status' => 'approved', 'verified_at' => now()])->save();
        $rejectedOwner = User::factory()->create(['role' => 'penjual']);
        $rejectedStore = $rejectedOwner->umkmStore()->create([
            'name' => 'Toko Rejected',
            'slug' => 'toko-rejected-admin',
        ]);
        $rejectedStore->forceFill([
            'status' => 'rejected',
            'rejection_reason' => 'Dokumen belum lengkap.',
            'verified_at' => now(),
        ])->save();

        $this->actingAs($admin)
            ->get(route('admin.umkm-stores.index'))
            ->assertOk()
            ->assertSee(['Toko Pending', 'Toko Approved', 'Toko Rejected']);

        $this->actingAs($admin)
            ->get(route('admin.umkm-stores.index', ['status' => 'pending']))
            ->assertOk()
            ->assertSee('Toko Pending')
            ->assertDontSee(['Toko Approved', 'Toko Rejected']);

        $this->actingAs($admin)
            ->get(route('admin.umkm-stores.index', ['status' => 'approved']))
            ->assertOk()
            ->assertSee('Toko Approved')
            ->assertDontSee(['Toko Pending', 'Toko Rejected']);

        $this->actingAs($admin)
            ->get(route('admin.umkm-stores.index', ['status' => 'rejected']))
            ->assertOk()
            ->assertSee('Toko Rejected')
            ->assertDontSee(['Toko Pending', 'Toko Approved']);

        $this->actingAs($admin)
            ->patch(route('admin.umkm-stores.approve', $store))
            ->assertRedirect(route('admin.umkm-stores.index'))
            ->assertSessionHas('status', 'Toko berhasil disetujui.');

        $this->assertDatabaseHas('umkm_stores', [
            'id' => $store->id,
            'status' => 'approved',
            'rejection_reason' => null,
        ]);
        $this->assertNotNull($store->fresh()->verified_at);
    }

    public function test_admin_cannot_approve_a_store_that_is_already_approved(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $owner = User::factory()->create(['role' => 'penjual']);
        $store = $owner->umkmStore()->create([
            'name' => 'Toko Sudah Approved',
            'slug' => 'toko-sudah-approved',
        ]);
        $store->forceFill(['status' => 'approved', 'verified_at' => now()])->save();

        $this->actingAs($admin)
            ->patch(route('admin.umkm-stores.approve', $store))
            ->assertRedirect(route('admin.umkm-stores.index'))
            ->assertSessionHas('error', 'Toko ini sudah diproses dan tidak dapat disetujui kembali.');

        $this->assertSame('approved', $store->fresh()->status);
    }

    public function test_admin_can_reject_a_pending_store_with_a_reason(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $owner = User::factory()->create(['role' => 'penjual']);
        $store = $owner->umkmStore()->create([
            'name' => 'Toko Ditolak',
            'slug' => 'toko-ditolak-admin',
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.umkm-stores.reject', $store), [
                'rejection_reason' => 'Data alamat belum lengkap.',
            ])
            ->assertRedirect(route('admin.umkm-stores.index'))
            ->assertSessionHas('status', 'Toko ditolak.');

        $this->assertDatabaseHas('umkm_stores', [
            'id' => $store->id,
            'status' => 'rejected',
            'rejection_reason' => 'Data alamat belum lengkap.',
        ]);
        $this->actingAs($admin)->get(route('admin.umkm-stores.index', ['status' => 'rejected']))
            ->assertOk()->assertSee('Alasan:')->assertSee('Data alamat belum lengkap.');
    }

    public function test_non_admin_cannot_access_store_verification(): void
    {
        $owner = User::factory()->create(['role' => 'penjual']);
        $store = $owner->umkmStore()->create([
            'name' => 'Toko Pemilik',
            'slug' => 'toko-pemilik-admin',
        ]);

        $this->actingAs($owner)
            ->get(route('admin.umkm-stores.index'))
            ->assertForbidden();

        $this->actingAs($owner)
            ->patch(route('admin.umkm-stores.approve', $store))
            ->assertForbidden();

        $this->actingAs($owner)
            ->patch(route('admin.umkm-stores.reject', $store), [
                'rejection_reason' => 'Tidak berwenang.',
            ])
            ->assertForbidden();
    }
}
