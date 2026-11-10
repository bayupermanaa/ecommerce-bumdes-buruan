<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\UmkmStore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SellerRoleCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_and_legacy_sellers_reach_their_seller_and_customer_pages(): void
    {
        foreach (['penjual', 'pemilik_umkm'] as $role) {
            $seller = User::factory()->create(['role' => $role]);
            $this->approvedStore($seller);
            $order = $this->orderFor($seller);

            $this->actingAs($seller)->get(route('dashboard'))->assertRedirect(route('umkm.dashboard'));
            $this->actingAs($seller)->get(route('umkm.dashboard'))->assertOk()->assertSee('Dashboard Penjual');
            $this->actingAs($seller)->get(route('umkm.products.create'))->assertOk();
            $this->actingAs($seller)->get(route('umkm.orders.index'))->assertOk();
            $this->actingAs($seller)->get(route('umkm.commission-agreements.index'))->assertOk();
            $this->actingAs($seller)->get(route('umkm.recipient-bank-accounts.index'))->assertOk();
            $this->actingAs($seller)->get(route('umkm.disbursements.index'))->assertOk();
            $this->actingAs($seller)->get(route('cart.index'))->assertOk();
            $this->actingAs($seller)->get(route('checkout.index'))->assertOk();
            $this->actingAs($seller)->get(route('orders.show', $order))->assertOk();
            $this->actingAs($seller)->get(route('orders.payments.create', $order))->assertOk();
        }
    }

    public function test_new_seller_cannot_access_another_sellers_store_or_order(): void
    {
        $seller = User::factory()->create(['role' => 'penjual']);
        $this->approvedStore($seller);
        $other = User::factory()->create(['role' => 'pemilik_umkm']);
        $otherStore = $this->approvedStore($other);
        $otherOrder = $this->orderFor($other);

        $this->actingAs($seller)->get(route('umkm.stores.edit', $otherStore))->assertForbidden();
        $this->actingAs($seller)->get(route('orders.show', $otherOrder))->assertForbidden();
        $this->actingAs($seller)->get(route('orders.payments.create', $otherOrder))->assertForbidden();
        $this->actingAs($seller)->get(route('umkm.orders.index'))->assertOk();
    }

    public function test_unapproved_seller_cannot_create_products(): void
    {
        $seller = User::factory()->create(['role' => 'penjual']);
        $seller->umkmStore()->create(['name' => 'Menunggu', 'slug' => 'menunggu-'.Str::random(8)]);

        $this->actingAs($seller)->get(route('umkm.products.create'))->assertForbidden();
    }

    private function approvedStore(User $seller): UmkmStore
    {
        $store = $seller->umkmStore()->create([
            'name' => 'Toko '.$seller->id,
            'slug' => 'toko-'.Str::random(10),
        ]);
        $store->forceFill(['status' => 'approved', 'verified_at' => now()])->save();

        return $store;
    }

    private function orderFor(User $buyer): Order
    {
        return Order::create([
            'order_number' => 'ORD-'.Str::upper(Str::random(10)),
            'checkout_token' => (string) Str::uuid(),
            'user_id' => $buyer->id,
            'fulfillment_method' => 'pickup',
            'recipient_name' => $buyer->name,
            'recipient_phone' => '081234567890',
            'subtotal' => '1000.00',
            'shipping_total' => '0.00',
            'grand_total' => '1000.00',
            'payment_status' => 'unpaid',
            'order_status' => 'pending_payment',
        ]);
    }
}
