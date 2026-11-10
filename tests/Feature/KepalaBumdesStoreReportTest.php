<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\StoreOrder;
use App\Models\UmkmStore;
use App\Models\User;
use App\Support\OrderFulfillmentStatus;
use App\Support\PaymentStatus;
use App\Support\StoreOrderFulfillmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class KepalaBumdesStoreReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_kepala_bumdes_sees_correct_store_snapshot_totals_without_multi_store_duplication(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $storeA = $this->store('Penjual A');
        $storeB = $this->store('Penjual B');
        $paid = $this->order(PaymentStatus::PAID, OrderFulfillmentStatus::IN_FULFILLMENT, '150000.00');
        $this->storeOrder($paid, $storeA, '100000.00', '10000.00', '90000.00', StoreOrderFulfillmentStatus::COMPLETED);
        $this->storeOrder($paid, $storeB, '50000.00', '5000.00', '45000.00', StoreOrderFulfillmentStatus::PROCESSING);
        $unpaid = $this->order(PaymentStatus::UNPAID, OrderFulfillmentStatus::PENDING_PAYMENT, '70000.00');
        $this->storeOrder($unpaid, $storeA, '70000.00', '7000.00', '63000.00', StoreOrderFulfillmentStatus::PENDING_PAYMENT);

        $this->actingAs($head)->get(route('kepala-bumdes.stores.index'))
            ->assertOk()
            ->assertSee([
                'Penjual A', 'Penjual B', '1 / 1', '1 / 0',
                'Rp 100.000,00', 'Rp 10.000,00', 'Rp 90.000,00',
                'Rp 50.000,00', 'Rp 5.000,00', 'Rp 45.000,00',
            ])
            ->assertDontSee('Rp 170.000,00');

        $this->actingAs($head)->get(route('kepala-bumdes.stores.show', $storeA))
            ->assertOk()
            ->assertSee([$paid->order_number, $unpaid->order_number, 'Rp 100.000,00', 'Rp 10.000,00', 'Rp 90.000,00'])
            ->assertSee('—');
    }

    public function test_non_head_roles_are_forbidden_from_store_reports(): void
    {
        $store = $this->store('Penjual Privat');

        foreach (['pelanggan', 'penjual', 'admin_bumdes', 'kurir'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->get(route('kepala-bumdes.stores.index'))->assertForbidden();
            $this->actingAs($user)->get(route('kepala-bumdes.stores.show', $store))->assertForbidden();
        }
    }

    public function test_date_store_and_fulfillment_filters_and_pagination_are_read_only(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $selectedStore = $this->store('Penjual Terpilih');
        $otherStore = $this->store('Penjual Lain');
        $matching = $this->order(PaymentStatus::PAID, OrderFulfillmentStatus::IN_FULFILLMENT, '20000.00', '2026-10-22 10:00:00');
        $this->storeOrder($matching, $selectedStore, '20000.00', '2000.00', '18000.00', StoreOrderFulfillmentStatus::PROCESSING);
        $old = $this->order(PaymentStatus::PAID, OrderFulfillmentStatus::COMPLETED, '30000.00', '2026-10-10 10:00:00');
        $this->storeOrder($old, $otherStore, '30000.00', '3000.00', '27000.00', StoreOrderFulfillmentStatus::COMPLETED);

        for ($number = 1; $number <= 16; $number++) {
            $order = $this->order(PaymentStatus::UNPAID, OrderFulfillmentStatus::PENDING_PAYMENT, '10000.00');
            $this->storeOrder($order, $selectedStore, '10000.00', '0.00', '0.00', StoreOrderFulfillmentStatus::PENDING_PAYMENT, 'unconfigured');
        }

        $before = [Order::count(), StoreOrder::count()];
        $this->actingAs($head)->get(route('kepala-bumdes.stores.index', [
            'store_id' => $selectedStore->id,
            'date_from' => '2026-10-22',
            'date_to' => '2026-10-22',
            'fulfillment_status' => StoreOrderFulfillmentStatus::PROCESSING,
        ]))
            ->assertOk()
            ->assertSee($selectedStore->name)
            ->assertDontSee('/kepala-bumdes/laporan-umkm/'.$otherStore->id, false)
            ->assertSee('Rp 20.000,00');

        $this->actingAs($head)->get(route('kepala-bumdes.stores.show', [
            'store' => $selectedStore,
            'fulfillment_status' => StoreOrderFulfillmentStatus::PENDING_PAYMENT,
        ]))
            ->assertOk()
            ->assertSee('page=2')
            ->assertDontSee($matching->order_number);

        $this->assertSame($before, [Order::count(), StoreOrder::count()]);
    }

    private function store(string $name): UmkmStore
    {
        $owner = User::factory()->create(['role' => 'penjual']);

        return $owner->umkmStore()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'status' => 'approved',
        ]);
    }

    private function order(string $paymentStatus, string $orderStatus, string $total, ?string $createdAt = null): Order
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $order = Order::create([
            'order_number' => 'ORD-'.Str::upper(Str::random(12)),
            'checkout_token' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'fulfillment_method' => 'pickup',
            'recipient_name' => 'Pelanggan Test',
            'recipient_phone' => '08123456789',
            'subtotal' => $total,
            'shipping_total' => '0.00',
            'grand_total' => $total,
            'payment_status' => $paymentStatus,
            'order_status' => $orderStatus,
        ]);

        if ($createdAt !== null) {
            $order->forceFill(['created_at' => Carbon::parse($createdAt), 'updated_at' => Carbon::parse($createdAt)])->save();
        }

        return $order;
    }

    private function storeOrder(
        Order $order,
        UmkmStore $store,
        string $total,
        string $commissionAmount,
        string $umkmAmount,
        string $status,
        string $commissionStatus = 'calculated',
    ): StoreOrder {
        return $order->storeOrders()->create([
            'umkm_store_id' => $store->id,
            'store_order_number' => 'STO-'.Str::upper(Str::random(12)),
            'store_name_snapshot' => $store->name,
            'subtotal' => $total,
            'shipping_amount' => '0.00',
            'total' => $total,
            'fulfillment_status' => $status,
            'commission_percentage' => $commissionStatus === 'calculated' ? '10.00' : null,
            'commission_amount' => $commissionStatus === 'calculated' ? $commissionAmount : null,
            'umkm_amount' => $commissionStatus === 'calculated' ? $umkmAmount : null,
            'commission_status' => $commissionStatus,
        ]);
    }
}
