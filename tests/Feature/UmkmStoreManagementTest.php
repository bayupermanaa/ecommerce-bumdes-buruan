<?php

namespace Tests\Feature;

use App\Models\UmkmStore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UmkmStoreManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_umkm_owner_can_create_and_update_their_store(): void
    {
        $owner = User::factory()->create(['role' => 'pemilik_umkm']);

        $this->actingAs($owner)
            ->post(route('umkm.stores.store'), [
                'name' => 'Toko Buruan',
                'slug' => 'toko-buruan',
                'description' => 'Produk lokal Desa Buruan',
                'address' => 'Desa Buruan',
                'phone' => '08123456789',
            ])
            ->assertRedirect(route('umkm.stores.index'));

        $store = UmkmStore::firstOrFail();

        $this->assertSame($owner->id, $store->user_id);
        $this->assertSame('pending', $store->status);

        $this->actingAs($owner)
            ->put(route('umkm.stores.update', $store), [
                'name' => 'Toko Buruan Diperbarui',
                'slug' => 'toko-buruan',
                'description' => 'Produk lokal pilihan',
                'address' => 'Desa Buruan',
                'phone' => '08129876543',
            ])
            ->assertRedirect(route('umkm.stores.index'));

        $this->assertDatabaseHas('umkm_stores', [
            'id' => $store->id,
            'name' => 'Toko Buruan Diperbarui',
            'status' => 'pending',
        ]);
    }

    public function test_umkm_owner_cannot_manage_another_owners_store(): void
    {
        $owner = User::factory()->create(['role' => 'pemilik_umkm']);
        $otherOwner = User::factory()->create(['role' => 'pemilik_umkm']);
        $otherStore = $otherOwner->umkmStore()->create([
            'name' => 'Toko Lain',
            'slug' => 'toko-lain',
        ]);

        $this->actingAs($owner)
            ->get(route('umkm.stores.edit', $otherStore))
            ->assertForbidden();

        $this->actingAs($owner)
            ->put(route('umkm.stores.update', $otherStore), [
                'name' => 'Tidak Boleh Berubah',
                'slug' => 'toko-lain',
            ])
            ->assertForbidden();
    }

    public function test_umkm_owner_can_only_create_one_store(): void
    {
        $owner = User::factory()->create(['role' => 'pemilik_umkm']);
        $owner->umkmStore()->create([
            'name' => 'Toko Pertama',
            'slug' => 'toko-pertama',
        ]);

        $this->actingAs($owner)
            ->get(route('umkm.stores.create'))
            ->assertForbidden();
    }
}
