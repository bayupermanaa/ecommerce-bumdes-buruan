<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreOrder;
use App\Models\UmkmDisbursement;
use App\Models\UmkmEntitlement;
use App\Models\UmkmStore;
use App\Models\User;
use App\Services\FinancialAudit\FinancialExceptionDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminFinancialAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_read_only_audit_and_filter_by_category_and_store(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $store = $this->makeStore('Toko Audit Utama');
        $otherStore = $this->makeStore('Toko Audit Lain');
        $this->makeEntitlement($store, 'held');
        $this->makeEntitlement($otherStore, 'held');

        $this->actingAs($admin)->get(route('admin.financial-audit.index', [
            'category' => 'ENT_HELD_REVIEW',
            'severity' => 'medium',
            'umkm_store_id' => $store->id,
        ]))
            ->assertOk()
            ->assertSee(['Audit Keuangan', 'ENT_HELD_REVIEW', 'umkm_entitlement'])
            ->assertViewHas('summary', fn (array $summary): bool => $summary['displayed_count'] === 1)
            ->assertViewHas('exceptions', fn ($exceptions): bool => $exceptions->every(fn (array $exception): bool => $exception['umkm_store_id'] === $store->id));
    }

    public function test_guest_and_other_roles_cannot_access_financial_audit(): void
    {
        $this->get(route('admin.financial-audit.index'))->assertRedirect(route('login'));
        $this->get(route('admin.financial-audit.export'))->assertRedirect(route('login'));

        foreach (['pelanggan', 'pemilik_umkm', 'kurir', 'kepala_bumdes'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('admin.financial-audit.index'))
                ->assertForbidden();
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('admin.financial-audit.export'))
                ->assertForbidden();
        }
    }

    public function test_admin_can_export_bounded_safe_detector_results_with_filters_and_stable_keys(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $store = $this->makeStore('Toko Audit CSV');
        $otherStore = $this->makeStore('Toko Audit CSV Lain');
        $held = $this->makeEntitlement($store, 'held');
        $this->makeEntitlement($otherStore, 'held');
        $formulaStoreOrder = $this->makeMissingEntitlementStoreOrder($store, '@FORMULA');
        $account = $store->recipientBankAccounts()->create([
            'bank_name' => 'Bank Rahasia',
            'account_holder_name' => 'Pemilik Rekening Rahasia',
            'account_number' => '00123456789',
            'status' => 'active',
            'active_store_lock' => 'store:'.$store->id,
            'created_by' => $store->user_id,
            'updated_by' => $store->user_id,
        ]);
        UmkmDisbursement::create([
            'umkm_store_id' => $store->id,
            'recipient_bank_account_id' => $account->id,
            'recipient_bank_name' => $account->bank_name,
            'recipient_account_holder_name' => $account->account_holder_name,
            'recipient_account_number' => $account->account_number,
            'requested_amount' => '150000.50',
            'status' => 'cancelled',
            'requested_by' => $store->user_id,
            'requested_at' => now(),
            'transfer_reference' => 'REF-PRIVATE-SECRET',
            'transfer_proof_path' => 'private-proof-secret',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $before = [Order::count(), StoreOrder::count(), UmkmEntitlement::count(), UmkmDisbursement::count()];
        $filters = [
            'category' => FinancialExceptionDetector::ENT_HELD_REVIEW,
            'severity' => 'medium',
            'umkm_store_id' => $store->id,
            'limit' => 25,
        ];
        $response = $this->actingAs($admin)->get(route('admin.financial-audit.export', $filters));
        $content = $response->streamedContent();
        $rows = $this->csvRows($content);
        $expected = app(FinancialExceptionDetector::class)->scan([
            'category' => FinancialExceptionDetector::ENT_HELD_REVIEW,
            'umkm_store_id' => $store->id,
        ])->first();

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('X-Audit-Result-Limit', '25')
            ->assertHeader('X-Audit-Results-Truncated', 'false')
            ->assertHeader('X-Audit-Result-Scope', 'bounded-not-global-total');
        $this->assertSame([
            'Kode Exception', 'Prioritas', 'Reason', 'Tipe Referensi', 'ID Referensi', 'ID UMKM',
            'Status Aktual', 'Keterangan', 'Stable Exception Key', 'Waktu Sumber',
        ], $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame(FinancialExceptionDetector::ENT_HELD_REVIEW, $rows[1][0]);
        $this->assertSame('medium', $rows[1][1]);
        $this->assertSame((string) $store->id, $rows[1][5]);
        $this->assertSame($expected['stable_exception_key'], $rows[1][8]);
        foreach (['00123456789', 'Pemilik Rekening Rahasia', 'REF-PRIVATE-SECRET', 'private-proof-secret'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $content);
        }

        $formulaRows = $this->csvRows($this->actingAs($admin)->get(route('admin.financial-audit.export', [
            'category' => FinancialExceptionDetector::PAY_ENT_MISSING,
        ]))->streamedContent());
        $formulaRow = collect(array_slice($formulaRows, 1))->first(fn (array $row): bool => (int) $row[4] === $formulaStoreOrder->id);
        $this->assertSame("'@FORMULA", $formulaRow[6]);
        $this->actingAs($admin)->get(route('admin.financial-audit.index', $filters))
            ->assertOk()
            ->assertSee(str_replace('&', '&amp;', route('admin.financial-audit.export', $filters)), false);
        $this->assertSame($before, [Order::count(), StoreOrder::count(), UmkmEntitlement::count(), UmkmDisbursement::count()]);
    }

    public function test_audit_export_honors_25_50_100_limits_and_all_supported_categories(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $store = $this->makeStore('Toko Batas Export');
        for ($i = 0; $i < 101; $i++) {
            $this->makeEntitlement($store, 'held');
        }

        foreach ([25, 50, 100] as $limit) {
            $response = $this->actingAs($admin)->get(route('admin.financial-audit.export', [
                'category' => FinancialExceptionDetector::ENT_HELD_REVIEW,
                'limit' => $limit,
            ]));
            $rows = $this->csvRows($response->streamedContent());

            $response->assertOk()
                ->assertHeader('X-Audit-Result-Limit', (string) $limit)
                ->assertHeader('X-Audit-Results-Truncated', 'true');
            $this->assertCount($limit + 1, $rows);
        }

        foreach ([
            FinancialExceptionDetector::PAY_ENT_MISSING,
            FinancialExceptionDetector::ENT_PAYMENT_INVALID,
            FinancialExceptionDetector::ENT_SNAPSHOT_MISMATCH,
            FinancialExceptionDetector::ENT_HELD_REVIEW,
            FinancialExceptionDetector::ENT_RESERVED_NO_ACTIVE_ALLOCATION,
            FinancialExceptionDetector::DISB_ACTIVE_ALLOCATION_MISMATCH,
            FinancialExceptionDetector::DISB_TERMINAL_LOCK_REMAINS,
            FinancialExceptionDetector::DISB_DUPLICATE_ACTIVE,
            FinancialExceptionDetector::DISB_PAID_VERIFICATION_MISSING,
            FinancialExceptionDetector::DISB_FAILURE_RELEASE_MISSING,
            FinancialExceptionDetector::EVENT_TRAIL_GAP,
        ] as $category) {
            $this->actingAs($admin)->get(route('admin.financial-audit.export', ['category' => $category]))
                ->assertOk()
                ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        }
    }

    public function test_audit_accepts_supported_limits_and_rejects_invalid_filters(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);

        $this->actingAs($admin)->get(route('admin.financial-audit.index', ['limit' => 50]))
            ->assertOk()
            ->assertViewHas('limit', 50);
        $this->actingAs($admin)->get(route('admin.financial-audit.index', ['limit' => 100]))
            ->assertOk()
            ->assertViewHas('limit', 100);

        $this->actingAs($admin)->get(route('admin.financial-audit.index', ['limit' => 26]))
            ->assertRedirect()
            ->assertSessionHasErrors('limit');
        $this->actingAs($admin)->get(route('admin.financial-audit.index', ['category' => 'INVALID']))
            ->assertRedirect()
            ->assertSessionHasErrors('category');
        $this->actingAs($admin)->get(route('admin.financial-audit.index', ['severity' => 'high']))
            ->assertRedirect()
            ->assertSessionHasErrors('severity');
        $this->actingAs($admin)->get(route('admin.financial-audit.index', ['umkm_store_id' => 999999]))
            ->assertRedirect()
            ->assertSessionHasErrors('umkm_store_id');
    }

    public function test_audit_has_empty_state_private_html_and_explicit_bounded_results(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $store = $this->makeStore('Toko Batas Audit');
        for ($i = 0; $i < 26; $i++) {
            $this->makeEntitlement($store, 'held');
        }

        $this->actingAs($admin)->get(route('admin.financial-audit.index', [
            'category' => 'ENT_HELD_REVIEW',
            'limit' => 25,
        ]))
            ->assertOk()
            ->assertSee('Hasil dibatasi 25 record')
            ->assertViewHas('hasMore', true)
            ->assertViewHas('exceptions', fn ($exceptions): bool => $exceptions->count() === 25)
            ->assertDontSee(['00123456789', 'Pemilik Rekening Rahasia', 'REF-PRIVATE-SECRET', 'private-proof-secret']);

        $this->actingAs($admin)->get(route('admin.financial-audit.index', [
            'category' => 'DISB_DUPLICATE_ACTIVE',
        ]))
            ->assertOk()
            ->assertSee('Tidak ada exception untuk filter ini.');
    }

    public function test_audit_exposes_only_get_and_head_routes(): void
    {
        foreach (['admin/audit-keuangan', 'admin/audit-keuangan/export'] as $uri) {
            $routes = collect(Route::getRoutes()->getRoutes())
                ->filter(fn ($route): bool => $route->uri() === $uri)
                ->values();

            $this->assertCount(1, $routes);
            $this->assertSame(['GET', 'HEAD'], $routes->first()->methods());
        }
    }

    private function makeStore(string $name): UmkmStore
    {
        $owner = User::factory()->create(['role' => 'pemilik_umkm']);

        return $owner->umkmStore()->create([
            'name' => $name,
            'slug' => 'admin-audit-'.Str::lower(Str::random(10)),
        ]);
    }

    private function makeEntitlement(UmkmStore $store, string $status): UmkmEntitlement
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $order = Order::create([
            'order_number' => 'ORD-AUD-'.Str::upper(Str::random(10)),
            'checkout_token' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'fulfillment_method' => 'pickup',
            'recipient_name' => 'Pelanggan Test',
            'recipient_phone' => '081234567890',
            'subtotal' => '150000.50',
            'shipping_total' => '0.00',
            'grand_total' => '150000.50',
            'payment_status' => 'paid',
            'order_status' => 'completed',
        ]);
        $storeOrder = StoreOrder::create([
            'order_id' => $order->id,
            'umkm_store_id' => $store->id,
            'store_order_number' => 'SO-AUD-'.Str::upper(Str::random(10)),
            'store_name_snapshot' => $store->name,
            'subtotal' => '150000.50',
            'shipping_amount' => '0.00',
            'total' => '150000.50',
            'fulfillment_status' => 'completed',
            'commission_percentage' => '0.00',
            'commission_amount' => '0.00',
            'umkm_amount' => '150000.50',
            'commission_status' => 'calculated',
        ]);
        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_number' => 'PAY-AUD-'.Str::upper(Str::random(10)),
            'payment_method' => 'cash_pickup',
            'amount' => '150000.50',
            'status' => 'paid',
        ]);

        return UmkmEntitlement::create([
            'umkm_store_id' => $store->id,
            'order_id' => $order->id,
            'store_order_id' => $storeOrder->id,
            'payment_id' => $payment->id,
            'source_umkm_amount' => '150000.50',
            'source_commission_amount' => '0.00',
            'source_commission_percentage' => '0.00',
            'commission_status_snapshot' => 'calculated',
            'status' => $status,
            'accrued_at' => now(),
        ]);
    }

    private function makeMissingEntitlementStoreOrder(UmkmStore $store, string $fulfillmentStatus): StoreOrder
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $order = Order::create([
            'order_number' => 'ORD-MISS-'.Str::upper(Str::random(10)),
            'checkout_token' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'fulfillment_method' => 'pickup',
            'recipient_name' => 'Pelanggan Test',
            'recipient_phone' => '081234567890',
            'subtotal' => '100000.00',
            'shipping_total' => '0.00',
            'grand_total' => '100000.00',
            'payment_status' => 'paid',
            'payment_confirmed_at' => now(),
            'order_status' => 'completed',
        ]);
        Payment::create([
            'order_id' => $order->id,
            'confirmed_order_id' => $order->id,
            'payment_number' => 'PAY-MISS-'.Str::upper(Str::random(10)),
            'payment_method' => 'cash_pickup',
            'amount' => '100000.00',
            'status' => 'paid',
        ]);

        return StoreOrder::create([
            'order_id' => $order->id,
            'umkm_store_id' => $store->id,
            'store_order_number' => 'SO-MISS-'.Str::upper(Str::random(10)),
            'store_name_snapshot' => $store->name,
            'subtotal' => '100000.00',
            'shipping_amount' => '0.00',
            'total' => '100000.00',
            'fulfillment_status' => $fulfillmentStatus,
            'commission_status' => 'calculated',
            'commission_percentage' => '10.00',
            'commission_amount' => '10000.00',
            'umkm_amount' => '90000.00',
        ]);
    }

    /** @return list<list<string|null>> */
    private function csvRows(string $content): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, substr($content, 3));
        rewind($stream);
        $rows = [];

        while (($row = fgetcsv($stream)) !== false) {
            $rows[] = $row;
        }

        fclose($stream);

        return $rows;
    }
}
