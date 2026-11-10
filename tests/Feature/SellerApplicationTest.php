<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreOrder;
use App\Models\UmkmStore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SellerApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_submit_one_complete_store_application_as_pending(): void
    {
        Storage::fake('public');
        $customer = User::factory()->create(['role' => 'pelanggan']);

        $this->actingAs($customer)
            ->get(route('seller-application.show'))
            ->assertOk()
            ->assertSee('Mulai Berjualan');

        $this->actingAs($customer)
            ->post(route('seller-application.store'), $this->applicationData([
                'logo' => UploadedFile::fake()->image('logo-toko.png'),
            ]))
            ->assertRedirect(route('seller-application.show'));

        $store = UmkmStore::sole();

        $this->assertSame($customer->id, $store->user_id);
        $this->assertSame('pending', $store->status);
        $this->assertSame('pelanggan', $customer->fresh()->role);
        $this->assertNotNull($store->logo);
        Storage::disk('public')->assertExists($store->logo);

        $this->actingAs($customer)
            ->get(route('seller-application.show'))
            ->assertOk()
            ->assertSee('Menunggu verifikasi')
            ->assertSee('Toko Nusantara');
    }

    public function test_store_application_requires_complete_information(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);

        $this->actingAs($customer)
            ->post(route('seller-application.store'), [
                'name' => '',
                'description' => '',
                'address' => '',
                'phone' => '',
            ])
            ->assertSessionHasErrors(['name', 'description', 'address', 'phone']);

        $this->assertDatabaseCount('umkm_stores', 0);
    }

    public function test_pending_applicant_cannot_access_seller_management(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $this->storeFor($customer, 'pending');

        $this->actingAs($customer)->get(route('umkm.dashboard'))->assertForbidden();
        $this->actingAs($customer)->get(route('umkm.products.index'))->assertForbidden();
        $this->actingAs($customer)->get(route('umkm.orders.index'))->assertForbidden();
    }

    public function test_admin_approval_atomically_approves_store_and_promotes_customer(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $store = $this->storeFor($customer, 'pending');
        $admin = User::factory()->create(['role' => 'admin_bumdes']);

        $this->actingAs($admin)
            ->patch(route('admin.umkm-stores.approve', $store))
            ->assertRedirect(route('admin.umkm-stores.index'));

        $this->assertSame('approved', $store->fresh()->status);
        $this->assertNotNull($store->fresh()->verified_at);
        $this->assertSame('penjual', $customer->fresh()->role);

        $this->actingAs($customer->fresh())
            ->get(route('umkm.dashboard'))
            ->assertOk()
            ->assertSee('Penjual');

        $this->actingAs($customer->fresh())
            ->get(route('seller-application.show'))
            ->assertOk()
            ->assertSee('Toko disetujui')
            ->assertSee('Buka Dashboard Penjual');
    }

    public function test_rejection_keeps_customer_role_and_same_store_can_be_resubmitted(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $store = $this->storeFor($customer, 'pending');
        $admin = User::factory()->create(['role' => 'admin_bumdes']);

        $this->actingAs($admin)
            ->patch(route('admin.umkm-stores.reject', $store), [
                'rejection_reason' => 'Alamat toko belum lengkap.',
            ])
            ->assertRedirect(route('admin.umkm-stores.index'));

        $this->assertSame('rejected', $store->fresh()->status);
        $this->assertSame('pelanggan', $customer->fresh()->role);

        $this->actingAs($customer)
            ->get(route('seller-application.show'))
            ->assertOk()
            ->assertSee('Alamat toko belum lengkap.')
            ->assertSee('Perbaiki dan ajukan ulang');

        $this->actingAs($customer)
            ->patch(route('seller-application.resubmit', $store), $this->applicationData([
                'name' => 'Toko Nusantara Diperbarui',
                'address' => 'Jalan Baru Nomor 20, Kabupaten Gianyar, Bali',
            ]))
            ->assertRedirect(route('seller-application.show'));

        $this->assertDatabaseCount('umkm_stores', 1);
        $this->assertDatabaseHas('umkm_stores', [
            'id' => $store->id,
            'user_id' => $customer->id,
            'name' => 'Toko Nusantara Diperbarui',
            'status' => 'pending',
            'rejection_reason' => null,
            'verified_at' => null,
        ]);
        $this->assertSame('pelanggan', $customer->fresh()->role);
    }

    public function test_each_account_can_only_have_one_store_application(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $this->storeFor($customer, 'pending');

        $this->actingAs($customer)
            ->post(route('seller-application.store'), $this->applicationData())
            ->assertForbidden();

        $this->assertDatabaseCount('umkm_stores', 1);
    }

    public function test_approved_seller_keeps_access_to_owned_customer_orders_and_shopping(): void
    {
        $seller = User::factory()->create(['role' => 'penjual']);
        $this->storeFor($seller, 'approved');
        $oldOrder = $this->orderFor($seller);
        $otherSeller = User::factory()->create(['role' => 'penjual']);
        $otherStore = $this->storeFor($otherSeller, 'approved');
        $product = $this->approvedProduct($otherStore);

        $this->actingAs($seller)->get(route('cart.index'))->assertOk();
        $this->actingAs($seller)->get(route('orders.index'))->assertOk()->assertSee($oldOrder->order_number);
        $this->actingAs($seller)->get(route('orders.show', $oldOrder))->assertOk();
        $this->actingAs($seller)
            ->post(route('cart.items.store', $product), ['quantity' => 1])
            ->assertRedirect(route('cart.index'));

        $this->assertDatabaseHas('carts', ['user_id' => $seller->id]);
        $this->assertDatabaseHas('cart_items', ['product_id' => $product->id, 'quantity' => 1]);
    }

    public function test_sellers_cannot_access_another_sellers_products_or_store_orders(): void
    {
        $sellerA = User::factory()->create(['role' => 'penjual']);
        $storeA = $this->storeFor($sellerA, 'approved');
        $sellerB = User::factory()->create(['role' => 'penjual']);
        $storeB = $this->storeFor($sellerB, 'approved');
        $productB = $this->approvedProduct($storeB);
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $order = $this->orderFor($customer);
        $storeOrderB = $this->storeOrderFor($order, $storeB);
        $storeOrderA = $this->storeOrderFor($order, $storeA);

        $this->actingAs($sellerA)
            ->get(route('umkm.products.edit', $productB))
            ->assertForbidden();
        $this->actingAs($sellerA)
            ->get(route('umkm.orders.show', $storeOrderB))
            ->assertForbidden();
        $this->actingAs($sellerA)
            ->get(route('umkm.orders.index'))
            ->assertOk()
            ->assertSee($storeOrderA->store_order_number)
            ->assertDontSee($storeOrderB->store_order_number);
    }

    /** @param array<string, mixed> $overrides */
    private function applicationData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Toko Nusantara',
            'description' => 'Menjual produk kebutuhan rumah tangga.',
            'address' => 'Jalan Utama Nomor 10, Kabupaten Gianyar, Bali',
            'phone' => '+62 812-3456-7890',
        ], $overrides);
    }

    private function storeFor(User $user, string $status): UmkmStore
    {
        $store = $user->umkmStore()->create([
            'name' => 'Toko '.$user->id,
            'slug' => 'toko-'.$user->id.'-'.Str::lower(Str::random(6)),
            'description' => 'Deskripsi toko test.',
            'address' => 'Alamat toko test.',
            'phone' => '081234567890',
        ]);
        $store->forceFill([
            'status' => $status,
            'verified_at' => $status === 'approved' ? now() : null,
        ])->save();

        return $store;
    }

    private function orderFor(User $customer): Order
    {
        return Order::create([
            'order_number' => 'ORD-'.Str::upper(Str::random(12)),
            'checkout_token' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'fulfillment_method' => 'pickup',
            'recipient_name' => $customer->name,
            'recipient_phone' => '081234567890',
            'subtotal' => '25000.00',
            'shipping_total' => '0.00',
            'grand_total' => '25000.00',
            'payment_status' => 'unpaid',
            'order_status' => 'pending_payment',
        ]);
    }

    private function approvedProduct(UmkmStore $store): Product
    {
        $category = Category::firstOrCreate(
            ['slug' => 'kategori-test'],
            ['name' => 'Kategori Test', 'description' => 'Fixture test', 'is_active' => true],
        );
        $product = $store->products()->create([
            'category_id' => $category->id,
            'sku' => 'SKU-'.Str::upper(Str::random(10)),
            'name' => 'Produk Test',
            'slug' => 'produk-'.Str::lower(Str::random(10)),
            'description' => 'Produk untuk pengujian.',
            'price' => '25000.00',
            'stock' => 10,
        ]);
        $product->forceFill(['status' => 'approved'])->save();

        return $product;
    }

    private function storeOrderFor(Order $order, UmkmStore $store): StoreOrder
    {
        return StoreOrder::create([
            'order_id' => $order->id,
            'umkm_store_id' => $store->id,
            'store_order_number' => 'STO-'.Str::upper(Str::random(12)),
            'store_name_snapshot' => $store->name,
            'subtotal' => '25000.00',
            'shipping_amount' => '0.00',
            'total' => '25000.00',
            'fulfillment_status' => 'pending_payment',
            'commission_status' => 'unconfigured',
        ]);
    }
}
