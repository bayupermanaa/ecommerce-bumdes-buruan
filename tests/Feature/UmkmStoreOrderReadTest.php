<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreOrder;
use App\Models\StoreOrderStatusLog;
use App\Models\User;
use App\Support\OrderFulfillmentStatus;
use App\Support\PaymentStatus;
use App\Support\StoreOrderFulfillmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UmkmStoreOrderReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_only_sees_orders_for_their_own_store_and_can_filter_status(): void
    {
        $owner = $this->owner('Toko Pemilik');
        $otherOwner = $this->owner('Toko Lain');
        $visible = $this->storeOrderFor($owner, 'PESANAN-MILIK', StoreOrderFulfillmentStatus::AWAITING_PROCESSING);
        $pending = $this->storeOrderFor($owner, 'PESANAN-BELUM-BAYAR', StoreOrderFulfillmentStatus::PENDING_PAYMENT, PaymentStatus::UNPAID);
        $hidden = $this->storeOrderFor($otherOwner, 'PESANAN-RAHASIA', StoreOrderFulfillmentStatus::AWAITING_PROCESSING);

        $this->actingAs($owner)
            ->get(route('umkm.orders.index'))
            ->assertOk()
            ->assertSee([$visible->store_order_number, $pending->store_order_number])
            ->assertDontSee($hidden->store_order_number)
            ->assertSee(['Sudah Dibayar', 'Menunggu Diproses', 'Belum Dibayar', 'Menunggu Pembayaran']);

        $this->actingAs($owner)
            ->get(route('umkm.orders.index', ['status' => StoreOrderFulfillmentStatus::AWAITING_PROCESSING]))
            ->assertOk()
            ->assertSee($visible->store_order_number)
            ->assertDontSee($pending->store_order_number)
            ->assertDontSee($hidden->store_order_number)
            ->assertSee('selected', false);
    }

    public function test_owner_can_view_only_their_store_order_detail_with_snapshots_and_history(): void
    {
        $owner = $this->owner('Toko Snapshot');
        $storeOrder = $this->storeOrderFor($owner, 'PESANAN-SNAPSHOT', StoreOrderFulfillmentStatus::AWAITING_PROCESSING);
        $admin = User::factory()->create(['role' => 'admin_bumdes', 'name' => 'Admin Verifikator']);
        StoreOrderStatusLog::create([
            'store_order_id' => $storeOrder->id,
            'actor_id' => $admin->id,
            'from_status' => StoreOrderFulfillmentStatus::PENDING_PAYMENT,
            'to_status' => StoreOrderFulfillmentStatus::AWAITING_PROCESSING,
            'notes' => 'Pembayaran telah dikonfirmasi.',
        ]);

        $this->actingAs($owner)
            ->get(route('umkm.orders.show', $storeOrder))
            ->assertOk()
            ->assertSee([
                'Perjalanan pesanan',
                $storeOrder->store_order_number,
                'Produk PESANAN-SNAPSHOT',
                'SKU-PESANAN-SNAPSHOT',
                '25.000',
                'Menunggu Diproses',
                'Pembayaran telah dikonfirmasi.',
                'Admin Verifikator',
            ]);
    }

    public function test_owner_cannot_open_another_stores_order_by_changing_the_url(): void
    {
        $owner = $this->owner('Toko Aman');
        $otherOwner = $this->owner('Toko Privat');
        $otherStoreOrder = $this->storeOrderFor($otherOwner, 'PESANAN-PRIVAT', StoreOrderFulfillmentStatus::AWAITING_PROCESSING);

        $this->actingAs($owner)
            ->get(route('umkm.orders.show', $otherStoreOrder))
            ->assertForbidden();
    }

    public function test_non_umkm_roles_and_guests_cannot_access_umkm_order_pages(): void
    {
        $owner = $this->owner('Toko Akses');
        $storeOrder = $this->storeOrderFor($owner, 'PESANAN-AKSES', StoreOrderFulfillmentStatus::AWAITING_PROCESSING);

        $this->get(route('umkm.orders.index'))->assertRedirect(route('login'));

        foreach (['pelanggan', 'admin_bumdes', 'kurir', 'kepala_bumdes'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get(route('umkm.orders.index'))->assertForbidden();
            $this->actingAs($user)->get(route('umkm.orders.show', $storeOrder))->assertForbidden();
        }
    }

    public function test_pagination_and_multi_store_order_show_only_the_owned_store_part(): void
    {
        $owner = $this->owner('Toko Pagination');
        $otherOwner = $this->owner('Toko Pasangan');
        $first = $this->storeOrderFor($owner, 'PAGINASI-01', StoreOrderFulfillmentStatus::AWAITING_PROCESSING);
        $this->storeOrderFor($otherOwner, 'PAGINASI-TOKO-LAIN', StoreOrderFulfillmentStatus::AWAITING_PROCESSING, PaymentStatus::PAID, $first->order);

        for ($number = 2; $number <= 16; $number++) {
            $this->storeOrderFor($owner, sprintf('PAGINASI-%02d', $number), StoreOrderFulfillmentStatus::AWAITING_PROCESSING);
        }

        $this->actingAs($owner)
            ->get(route('umkm.orders.index'))
            ->assertOk()
            ->assertSee('PAGINASI-16')
            ->assertDontSee('PAGINASI-01')
            ->assertDontSee('PAGINASI-TOKO-LAIN');

        $this->actingAs($owner)
            ->get(route('umkm.orders.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('PAGINASI-01')
            ->assertDontSee('PAGINASI-16')
            ->assertDontSee('PAGINASI-TOKO-LAIN');
    }

    public function test_read_only_order_pages_do_not_change_order_data_and_explain_legacy_status(): void
    {
        $owner = $this->owner('Toko Lama');
        $legacy = $this->storeOrderFor($owner, 'PESANAN-LAMA', StoreOrderFulfillmentStatus::PENDING_PAYMENT);
        $legacy->order->forceFill(['order_status' => OrderFulfillmentStatus::PENDING_PAYMENT])->save();
        $before = [
            'orders' => Order::count(),
            'store_orders' => StoreOrder::count(),
            'order_items' => $legacy->items()->count(),
            'status_logs' => StoreOrderStatusLog::count(),
        ];

        $this->actingAs($owner)
            ->get(route('umkm.orders.index'))
            ->assertOk()
            ->assertSee('status fulfillment lama belum direkonsiliasi');
        $this->actingAs($owner)
            ->get(route('umkm.orders.show', $legacy))
            ->assertOk()
            ->assertSee('status fulfillment data lama belum direkonsiliasi');

        $this->assertSame($before['orders'], Order::count());
        $this->assertSame($before['store_orders'], StoreOrder::count());
        $this->assertSame($before['order_items'], $legacy->items()->count());
        $this->assertSame($before['status_logs'], StoreOrderStatusLog::count());
    }

    private function owner(string $storeName): User
    {
        $owner = User::factory()->create(['role' => 'penjual']);
        $owner->umkmStore()->create([
            'name' => $storeName,
            'slug' => 'toko-'.Str::lower(Str::random(12)),
        ]);

        return $owner;
    }

    private function storeOrderFor(
        User $owner,
        string $identifier,
        string $fulfillmentStatus,
        string $paymentStatus = PaymentStatus::PAID,
        ?Order $order = null,
    ): StoreOrder {
        $store = $owner->umkmStore;
        $store->forceFill(['status' => 'approved'])->save();
        $order ??= $this->order($paymentStatus);
        $category = Category::create([
            'name' => 'Kategori '.Str::lower(Str::random(8)),
            'slug' => 'kategori-'.Str::lower(Str::random(12)),
        ]);
        $product = $store->products()->create([
            'category_id' => $category->id,
            'sku' => 'SKU-'.$identifier,
            'name' => 'Produk '.$identifier,
            'slug' => 'produk-'.Str::lower(Str::random(12)),
            'price' => '25000.00',
            'stock' => 10,
        ]);
        $product->forceFill(['status' => 'approved'])->save();
        $storeOrder = $order->storeOrders()->create([
            'umkm_store_id' => $store->id,
            'store_order_number' => 'STO-'.$identifier,
            'store_name_snapshot' => $store->name,
            'subtotal' => '25000.00',
            'shipping_amount' => '0.00',
            'total' => '25000.00',
            'fulfillment_status' => $fulfillmentStatus,
            'commission_percentage' => '12.50',
            'commission_amount' => '3125.00',
            'umkm_amount' => '21875.00',
            'commission_status' => 'calculated',
        ]);
        $storeOrder->items()->create([
            'product_id' => $product->id,
            'product_name_snapshot' => $product->name,
            'sku_snapshot' => $product->sku,
            'unit_price' => '25000.00',
            'quantity' => 1,
            'line_total' => '25000.00',
        ]);

        return $storeOrder;
    }

    private function order(string $paymentStatus): Order
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);

        return Order::create([
            'order_number' => 'ORD-'.Str::upper(Str::random(12)),
            'checkout_token' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'fulfillment_method' => 'pickup',
            'recipient_name' => 'Pelanggan Pemesan',
            'recipient_phone' => '08123456789',
            'subtotal' => '25000.00',
            'shipping_total' => '0.00',
            'grand_total' => '25000.00',
            'payment_status' => $paymentStatus,
            'order_status' => $paymentStatus === PaymentStatus::PAID
                ? OrderFulfillmentStatus::IN_FULFILLMENT
                : OrderFulfillmentStatus::PENDING_PAYMENT,
        ]);
    }
}
