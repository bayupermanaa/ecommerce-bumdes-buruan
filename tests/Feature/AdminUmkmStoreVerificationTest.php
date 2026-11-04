<?php

namespace Tests\Feature;

use App\Models\UmkmStore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUmkmStoreVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_filter_stores_by_each_available_status_and_approve_one(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $owner = User::factory()->create(['role' => 'pemilik_umkm']);
        $store = $owner->umkmStore()->create([
            'name' => 'Toko Pending',
            'slug' => 'toko-pending-admin',
        ]);
        $approvedOwner = User::factory()->create(['role' => 'pemilik_umkm']);
        $approvedStore = $approvedOwner->umkmStore()->create([
            'name' => 'Toko Approved',
            'slug' => 'toko-approved-admin',
        ]);
        $approvedStore->forceFill(['status' => 'approved', 'verified_at' => now()])->save();
        $rejectedOwner = User::factory()->create(['role' => 'pemilik_umkm']);
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
        $owner = User::factory()->create(['role' => 'pemilik_umkm']);
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
        $owner = User::factory()->create(['role' => 'pemilik_umkm']);
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
    }

    public function test_non_admin_cannot_access_store_verification(): void
    {
        $owner = User::factory()->create(['role' => 'pemilik_umkm']);
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
