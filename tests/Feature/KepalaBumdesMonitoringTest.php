<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StoreOrder;
use App\Models\UmkmDisbursement;
use App\Models\UmkmDisbursementAllocation;
use App\Models\UmkmEntitlement;
use App\Models\UmkmStore;
use App\Models\User;
use App\Support\OrderFulfillmentStatus;
use App\Support\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class KepalaBumdesMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_kepala_bumdes_can_view_actual_read_only_dashboard_metrics(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $approvedStore = $this->store('Toko Disetujui', 'approved');
        $this->store('Toko Menunggu', 'pending');
        $this->product($approvedStore, 'Produk Aktif', 'approved');
        $this->product($approvedStore, 'Produk Menunggu', 'pending');

        $paidCompleted = $this->order(PaymentStatus::PAID, OrderFulfillmentStatus::COMPLETED, '100000.00');
        $this->storeOrder($paidCompleted, $approvedStore, '6000.00');
        $this->storeOrder($paidCompleted, $this->store('Toko Kedua', 'approved'), '4000.00');
        $this->order(PaymentStatus::PAID, OrderFulfillmentStatus::IN_FULFILLMENT, '50000.00');
        $this->order(PaymentStatus::UNPAID, OrderFulfillmentStatus::CANCELLED, '99000.00');

        $response = $this->actingAs($head)->get(route('kepala-bumdes.dashboard'));
        $response
            ->assertOk()
            ->assertSee([
                'UMKM Terdaftar', '3', 'UMKM Disetujui', '2', 'Produk Disetujui', '1',
                'Seluruh Pesanan', '3', 'Pesanan Dalam Proses', '1', 'Pesanan Selesai', '1',
                'Pesanan Dibatalkan', '1', 'Rp150.000', 'Rp10.000',
                'Monitoring Hak UMKM', 'Monitoring Pencairan UMKM', 'Nominal Belum Diketahui', 'Rp0',
            ]);

        $this->assertArrayHasKey('confirmed_payment_total', $response->viewData('metrics'));
        $this->assertArrayHasKey('commission_snapshot_total', $response->viewData('metrics'));
        foreach (['accrued', 'eligible', 'available', 'reserved', 'held', 'settled', 'reversed'] as $status) {
            $this->assertSame(0, $response->viewData('entitlementMonitoring')['statuses'][$status]['entitlement_count']);
        }
        foreach (['requested', 'under_review', 'approved', 'rejected', 'cancelled', 'transfer_failed', 'paid'] as $status) {
            $this->assertSame(0, $response->viewData('disbursementMonitoring')['statuses'][$status]['disbursement_count']);
        }
    }

    public function test_only_kepala_bumdes_can_access_monitoring_pages(): void
    {
        foreach (['pelanggan', 'pemilik_umkm', 'admin_bumdes', 'kurir'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->get(route('kepala-bumdes.dashboard'))->assertForbidden();
            $this->actingAs($user)->get(route('kepala-bumdes.transactions.index'))->assertForbidden();
        }
    }

    public function test_transaction_report_filters_and_uses_parent_orders_without_double_counting_commission(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $store = $this->store('Toko Laporan', 'approved');
        $paid = $this->order(PaymentStatus::PAID, OrderFulfillmentStatus::IN_FULFILLMENT, '120000.00', '2026-10-20 10:00:00');
        $this->storeOrder($paid, $store, '7000.00');
        $this->storeOrder($paid, $this->store('Toko Laporan Kedua', 'approved'), '5000.00');
        $unpaid = $this->order(PaymentStatus::UNPAID, OrderFulfillmentStatus::PENDING_PAYMENT, '80000.00', '2026-10-21 10:00:00');
        $this->storeOrder($unpaid, $store, '9999.00');

        $response = $this->actingAs($head)->get(route('kepala-bumdes.transactions.index', [
            'date_from' => '2026-10-20',
            'date_to' => '2026-10-20',
            'payment_status' => PaymentStatus::PAID,
            'order_status' => OrderFulfillmentStatus::IN_FULFILLMENT,
        ]));

        $response->assertOk()
            ->assertSee([$paid->order_number, '2 toko', 'Rp 120.000,00', 'Rp 12.000,00'])
            ->assertDontSee($unpaid->order_number)
            ->assertSee('value="2026-10-20"', false)
            ->assertSee('selected', false);
    }

    public function test_report_paginates_and_does_not_change_persisted_data(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $before = [Order::count(), StoreOrder::count()];

        for ($number = 1; $number <= 16; $number++) {
            $this->order(PaymentStatus::UNPAID, OrderFulfillmentStatus::PENDING_PAYMENT, '10000.00');
        }

        $afterCreation = [Order::count(), StoreOrder::count()];

        $this->actingAs($head)->get(route('kepala-bumdes.transactions.index'))
            ->assertOk()
            ->assertSee('page=2');

        $this->assertSame($before[0] + 16, $afterCreation[0]);
        $this->assertSame($afterCreation, [Order::count(), StoreOrder::count()]);
    }

    public function test_dashboard_provides_read_only_financial_monitoring_with_complete_status_summaries(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $store = $this->store('Toko Monitoring Keuangan', 'approved');

        $entitlements = [];
        foreach ([
            ['accrued', '100000.50'], ['eligible', '200000.20'], ['available', '300000.70'],
            ['reserved', '400000.30'], ['settled', '500000.40'], ['held', null], ['reversed', '50000.60'],
        ] as [$status, $amount]) {
            $entitlements[$status] = $this->financialEntitlement($store, $status, $amount);
        }

        $disbursements = [];
        foreach ([
            ['requested', '100000.50'], ['under_review', '200000.20'], ['approved', '300000.30'],
            ['paid', '400000.40'], ['rejected', '500000.50'], ['cancelled', '600000.60'],
            ['transfer_failed', '700000.70'],
        ] as [$status, $amount]) {
            // Active requests are uniquely locked per store; use distinct stores
            // to keep this aggregate fixture valid under the production constraint.
            $disbursementStore = in_array($status, ['requested', 'under_review', 'approved'], true)
                ? $this->store('Toko Pencairan '.$status, 'approved')
                : $store;
            $disbursements[$status] = $this->financialDisbursement($disbursementStore, $status, $amount);
        }

        // Historical allocations must not multiply current-state entitlement amounts.
        UmkmDisbursementAllocation::create([
            'umkm_disbursement_id' => $disbursements['cancelled']->id,
            'umkm_entitlement_id' => $entitlements['available']->id,
            'amount' => '300000.70',
            'entitlement_reservation_lock' => null,
        ]);
        $disbursements['paid']->forceFill([
            'recipient_account_number' => 'SENSITIVE-ACCOUNT-123456',
            'transfer_proof_path' => 'private/SENSITIVE-TRANSFER-PROOF.pdf',
            'transfer_failure_proof_path' => 'private/SENSITIVE-FAILURE-PROOF.pdf',
        ])->save();

        $before = [
            UmkmEntitlement::count(),
            UmkmDisbursement::count(),
            UmkmDisbursementAllocation::count(),
        ];
        $response = $this->actingAs($head)->get(route('kepala-bumdes.dashboard'));
        $entitlementMonitoring = $response->viewData('entitlementMonitoring');
        $disbursementMonitoring = $response->viewData('disbursementMonitoring');

        $response->assertOk()
            ->assertSee([
                'Total Entitlement', 'Nominal Diketahui', 'Nominal Belum Diketahui',
                'Tercatat', 'Layak Direkonsiliasi', 'Tersedia', 'Direservasi', 'Ditahan', 'Telah Diselesaikan', 'Dibalik',
                'Seluruh Pengajuan', 'Nilai Historis Pengajuan', 'Pencairan Final (Paid)', 'Pengajuan Berjalan', 'Riwayat Non-Final',
                'Diajukan', 'Dalam Pemeriksaan', 'Disetujui', 'Ditolak', 'Dibatalkan', 'Transfer Gagal', 'Dibayar',
                'Rp1.550.002,70', 'Rp2.800.003,20', 'Rp400.000,40', 'Rp600.001,00', 'Rp1.800.001,80',
            ])
            ->assertSee(route('kepala-bumdes.umkm-financial.entitlements'), false)
            ->assertSee(route('kepala-bumdes.umkm-financial.disbursements'), false)
            ->assertDontSee('SENSITIVE-ACCOUNT-123456')
            ->assertDontSee('SENSITIVE-TRANSFER-PROOF.pdf')
            ->assertDontSee('SENSITIVE-FAILURE-PROOF.pdf');
        $this->assertSame(7, $entitlementMonitoring['summary']['entitlement_count']);
        $this->assertSame(155000270, $this->minorUnits($entitlementMonitoring['summary']['known_amount_total']));
        $this->assertSame(1, $entitlementMonitoring['summary']['unknown_amount_count']);
        foreach (['accrued', 'eligible', 'available', 'reserved', 'held', 'settled', 'reversed'] as $status) {
            $this->assertSame(1, $entitlementMonitoring['statuses'][$status]['entitlement_count']);
        }
        $this->assertSame(1, $entitlementMonitoring['statuses']['held']['unknown_amount_count']);
        $this->assertSame(30000070, $this->minorUnits($entitlementMonitoring['statuses']['available']['known_amount_total']));

        $this->assertSame(7, $disbursementMonitoring['summary']['disbursement_count']);
        $this->assertSame(280000320, $this->minorUnits($disbursementMonitoring['summary']['historical_requested_total']));
        $this->assertSame(1, $disbursementMonitoring['summary']['paid_count']);
        $this->assertSame(40000040, $this->minorUnits($disbursementMonitoring['summary']['paid_total']));
        $this->assertSame(3, $disbursementMonitoring['summary']['in_progress_count']);
        $this->assertSame(60000100, $this->minorUnits($disbursementMonitoring['summary']['in_progress_total']));
        $this->assertSame(3, $disbursementMonitoring['summary']['non_final_count']);
        $this->assertSame(180000180, $this->minorUnits($disbursementMonitoring['summary']['non_final_total']));
        foreach (['requested', 'under_review', 'approved', 'paid', 'rejected', 'cancelled', 'transfer_failed'] as $status) {
            $this->assertSame(1, $disbursementMonitoring['statuses'][$status]['disbursement_count']);
        }
        $this->assertSame(70000070, $this->minorUnits($disbursementMonitoring['statuses']['transfer_failed']['requested_amount_total']));
        $this->assertSame($before, [
            UmkmEntitlement::count(),
            UmkmDisbursement::count(),
            UmkmDisbursementAllocation::count(),
        ]);
    }

    private function store(string $name, string $status): UmkmStore
    {
        $owner = User::factory()->create(['role' => 'pemilik_umkm']);

        return $owner->umkmStore()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'status' => $status,
        ]);
    }

    private function product(UmkmStore $store, string $name, string $status): Product
    {
        $category = Category::create([
            'name' => 'Kategori '.Str::random(8),
            'slug' => 'kategori-'.Str::lower(Str::random(8)),
        ]);

        return $store->products()->create([
            'category_id' => $category->id,
            'sku' => 'SKU-'.Str::upper(Str::random(8)),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'price' => '10000.00',
            'stock' => 10,
            'status' => $status,
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

    private function storeOrder(Order $order, UmkmStore $store, string $commissionAmount): StoreOrder
    {
        return $order->storeOrders()->create([
            'umkm_store_id' => $store->id,
            'store_order_number' => 'STO-'.Str::upper(Str::random(12)),
            'store_name_snapshot' => $store->name,
            'subtotal' => '50000.00',
            'shipping_amount' => '0.00',
            'total' => '50000.00',
            'fulfillment_status' => 'pending_payment',
            'commission_percentage' => '10.00',
            'commission_amount' => $commissionAmount,
            'umkm_amount' => '40000.00',
            'commission_status' => 'calculated',
        ]);
    }

    private function financialEntitlement(UmkmStore $store, string $status, ?string $amount): UmkmEntitlement
    {
        $order = $this->order(PaymentStatus::PAID, OrderFulfillmentStatus::COMPLETED, '100000.00');
        $storeOrder = $this->storeOrder($order, $store, '0.00');
        $payment = Payment::create([
            'order_id' => $order->id,
            'confirmed_order_id' => $order->id,
            'payment_number' => 'PAY-MON-'.Str::upper(Str::random(10)),
            'payment_method' => 'cash_pickup',
            'amount' => '100000.00',
            'status' => 'paid',
        ]);

        return UmkmEntitlement::create([
            'umkm_store_id' => $store->id,
            'order_id' => $order->id,
            'store_order_id' => $storeOrder->id,
            'payment_id' => $payment->id,
            'source_umkm_amount' => $amount,
            'source_commission_amount' => $amount === null ? null : '0.00',
            'source_commission_percentage' => $amount === null ? null : '0.00',
            'commission_status_snapshot' => $amount === null ? 'unconfigured' : 'calculated',
            'status' => $status,
            'accrued_at' => now(),
            'eligible_at' => in_array($status, ['eligible', 'available', 'reserved', 'settled'], true) ? now() : null,
            'reconciled_at' => in_array($status, ['available', 'reserved', 'settled'], true) ? now() : null,
            'settled_at' => $status === 'settled' ? now() : null,
            'held_reason' => $amount === null ? 'Snapshot belum dihitung.' : null,
        ]);
    }

    private function financialDisbursement(UmkmStore $store, string $status, string $amount): UmkmDisbursement
    {
        $account = $store->recipientBankAccounts()->first() ?? $store->recipientBankAccounts()->create([
            'bank_name' => 'Bank Test',
            'account_holder_name' => 'Pemilik Test',
            'account_number' => '001'.Str::random(8),
            'status' => 'active',
            'active_store_lock' => 'store:'.$store->id,
            'verified_at' => now(),
            'created_by' => $store->user_id,
            'updated_by' => $store->user_id,
        ]);
        $active = in_array($status, ['requested', 'under_review', 'approved'], true);

        return UmkmDisbursement::create([
            'umkm_store_id' => $store->id,
            'recipient_bank_account_id' => $account->id,
            'recipient_bank_name' => $account->bank_name,
            'recipient_account_holder_name' => $account->account_holder_name,
            'recipient_account_number' => $account->account_number,
            'requested_amount' => $amount,
            'status' => $status,
            'active_request_lock' => $active ? 'store:'.$store->id : null,
            'requested_by' => $store->user_id,
            'requested_at' => now(),
            'reviewed_at' => in_array($status, ['under_review', 'approved', 'paid', 'rejected'], true) ? now() : null,
            'approved_at' => in_array($status, ['approved', 'paid'], true) ? now() : null,
            'paid_at' => $status === 'paid' ? now() : null,
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function minorUnits(string $amount): int
    {
        $this->assertMatchesRegularExpression('/^-?(\\d+)(?:\\.(\\d{1,2}))?$/', $amount);
        preg_match('/^(-?)(\\d+)(?:\\.(\\d{1,2}))?$/', $amount, $matches);

        return (((int) $matches[2] * 100) + (int) str_pad($matches[3] ?? '', 2, '0'))
            * ($matches[1] === '-' ? -1 : 1);
    }
}
