<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreOrder;
use App\Models\UmkmEntitlement;
use App\Models\User;
use App\Support\UmkmEntitlementStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminUmkmEntitlementReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_filter_show_and_reconcile_eligible_entitlement(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $eligible = $this->entitlement(UmkmEntitlementStatus::ELIGIBLE, 'Toko Rekonsiliasi');
        $held = $this->entitlement(UmkmEntitlementStatus::HELD, 'Toko Ditahan');

        $this->actingAs($admin)->get(route('admin.umkm-entitlements.index', ['store' => 'Rekonsiliasi', 'status' => 'eligible']))
            ->assertOk()->assertSee([$eligible->order->order_number, 'Toko Rekonsiliasi'])->assertDontSee('Toko Ditahan');
        $this->actingAs($admin)->get(route('admin.umkm-entitlements.show', $eligible))->assertOk()->assertSee(['Riwayat Event', 'Rekonsiliasi Hak UMKM']);
        $this->actingAs($admin)->patch(route('admin.umkm-entitlements.reconcile', $eligible), ['notes' => 'Sesuai catatan.'])
            ->assertRedirect(route('admin.umkm-entitlements.show', $eligible));

        $this->assertSame(UmkmEntitlementStatus::AVAILABLE, $eligible->fresh()->status);
        $this->assertDatabaseHas('umkm_entitlement_events', ['umkm_entitlement_id' => $eligible->id, 'actor_id' => $admin->id, 'event_type' => 'reconciled']);
        $this->actingAs($admin)->patch(route('admin.umkm-entitlements.reconcile', $eligible->fresh()))->assertRedirect();
        $this->assertSame(2, $eligible->events()->count());
        $this->assertSame(UmkmEntitlementStatus::HELD, $held->fresh()->status);
    }

    public function test_non_admin_and_non_eligible_statuses_are_forbidden(): void
    {
        $eligible = $this->entitlement(UmkmEntitlementStatus::ELIGIBLE);
        $accrued = $this->entitlement(UmkmEntitlementStatus::ACCRUED);
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $admin = User::factory()->create(['role' => 'admin_bumdes']);

        $this->actingAs($customer)->get(route('admin.umkm-entitlements.index'))->assertForbidden();
        $this->actingAs($customer)->get(route('admin.umkm-entitlements.show', $eligible))->assertForbidden();
        $this->actingAs($customer)->patch(route('admin.umkm-entitlements.reconcile', $eligible))->assertForbidden();
        $this->actingAs($admin)->patch(route('admin.umkm-entitlements.reconcile', $accrued))->assertForbidden();
    }

    private function entitlement(string $status, string $name = 'Toko Test'): UmkmEntitlement
    {
        $owner = User::factory()->create(['role' => 'pemilik_umkm']); $customer = User::factory()->create(['role' => 'pelanggan']);
        $store = $owner->umkmStore()->create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)), 'status' => 'approved']);
        $order = Order::create(['order_number' => 'ORD-'.Str::upper(Str::random(10)), 'checkout_token' => (string) Str::uuid(), 'user_id' => $customer->id, 'fulfillment_method' => 'pickup', 'recipient_name' => 'Pelanggan', 'recipient_phone' => '081', 'subtotal' => '100.00', 'shipping_total' => '0.00', 'grand_total' => '100.00', 'payment_status' => 'paid', 'order_status' => 'completed']);
        $payment = Payment::create(['order_id' => $order->id, 'confirmed_order_id' => $order->id, 'payment_number' => 'PAY-'.Str::upper(Str::random(10)), 'payment_method' => 'cash_pickup', 'amount' => '100.00', 'status' => 'paid', 'cash_received_by_name' => 'Petugas', 'cash_received_at' => now(), 'confirmed_at' => now()]);
        $storeOrder = $order->storeOrders()->create(['umkm_store_id' => $store->id, 'store_order_number' => 'STO-'.Str::upper(Str::random(10)), 'store_name_snapshot' => $name, 'subtotal' => '100.00', 'shipping_amount' => '0.00', 'total' => '100.00', 'fulfillment_status' => 'completed', 'commission_percentage' => '10.00', 'commission_amount' => '10.00', 'umkm_amount' => '90.00', 'commission_status' => 'calculated']);
        $entitlement = UmkmEntitlement::create(['umkm_store_id' => $store->id, 'order_id' => $order->id, 'store_order_id' => $storeOrder->id, 'payment_id' => $payment->id, 'source_umkm_amount' => '90.00', 'source_commission_amount' => '10.00', 'source_commission_percentage' => '10.00', 'commission_status_snapshot' => 'calculated', 'status' => $status, 'accrued_at' => now(), 'eligible_at' => $status === 'eligible' ? now() : null, 'held_reason' => $status === 'held' ? 'Belum calculated' : null]);
        if ($status === 'eligible') { $entitlement->events()->create(['event_type' => 'eligible', 'amount' => '90.00', 'to_status' => 'eligible', 'idempotency_key' => (string) Str::uuid()]); }
        return $entitlement;
    }
}
