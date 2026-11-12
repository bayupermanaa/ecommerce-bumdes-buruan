<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HelpCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_sees_customer_guide_without_private_links(): void
    {
        $this->get(route('help.index'))->assertOk()
            ->assertSee('Pusat Bantuan')->assertSee('Keranjang dan checkout')
            ->assertSee(route('login'))->assertDontSee(route('admin.payments.index'));
    }

    public function test_guides_match_role_and_link_to_existing_pages(): void
    {
        foreach ([
            'pelanggan' => ['Chat dan penawaran custom', 'orders.index'],
            'penjual' => ['Stok dan variasi', 'umkm.products.index'],
            'admin_bumdes' => ['Verifikasi toko dan produk', 'admin.umkm-stores.index'],
            'kepala_bumdes' => ['Monitoring', 'kepala-bumdes.dashboard'],
        ] as $role => [$heading, $routeName]) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('help.index'))->assertOk()->assertSee($heading)->assertSee(route($routeName));
        }
    }

    public function test_courier_does_not_see_undeveloped_delivery_instructions(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'kurir']))
            ->get(route('help.index'))->assertOk()
            ->assertSee('fitur operasional Kurir belum dikembangkan')
            ->assertDontSee(route('admin.payments.index'));
    }
}
