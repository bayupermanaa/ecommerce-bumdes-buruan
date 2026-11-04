<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreOrder;
use App\Models\UmkmDisbursement;
use App\Models\UmkmDisbursementAllocation;
use App\Models\UmkmDisbursementEvent;
use App\Models\UmkmEntitlement;
use App\Models\UmkmEntitlementEvent;
use App\Models\UmkmStore;
use App\Models\User;
use App\Services\FinancialAudit\FinancialExceptionDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExportSecurityIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_csv_exports_protect_each_formula_prefix_and_remain_read_only(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $unsafeValues = ['=SUM(1,1)', '+SUM(1,1)', '-1+1', '@SUM(1,1)', "\tSUM(1,1)", "\rSUM(1,1)"];
        $stores = array_map(fn (string $value): UmkmStore => $this->makeStore($value), $unsafeValues);

        foreach ($stores as $store) {
            $this->makeCommissionStoreOrder($store);
            $this->makeEntitlement($store);
            $this->makeDisbursement($store, $store === $stores[0]);
        }
        foreach ($unsafeValues as $index => $unsafeValue) {
            $this->makeMissingEntitlementStoreOrder($stores[$index], $unsafeValue);
        }

        $before = $this->financialSnapshot();
        $commission = $this->actingAs($head)->get(route('kepala-bumdes.commission-report.export'));
        $entitlements = $this->actingAs($head)->get(route('kepala-bumdes.umkm-financial.entitlements.export'));
        $disbursements = $this->actingAs($head)->get(route('kepala-bumdes.umkm-financial.disbursements.export'));
        $audit = $this->actingAs($admin)->get(route('admin.financial-audit.export', [
            'category' => FinancialExceptionDetector::PAY_ENT_MISSING,
            'limit' => 25,
        ]));

        $commissionRows = $this->csvRows($commission->streamedContent());
        $entitlementRows = $this->csvRows($entitlements->streamedContent());
        $disbursementRows = $this->csvRows($disbursements->streamedContent());
        $auditRows = $this->csvRows($audit->streamedContent());
        $expected = array_map($this->protectedCsvText(...), $unsafeValues);

        foreach ([$commission, $entitlements, $disbursements, $audit] as $response) {
            $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
            $this->assertStringContainsString('attachment;', (string) $response->headers->get('content-disposition'));
        }
        $audit->assertHeader('X-Audit-Result-Scope', 'bounded-not-global-total');
        $this->assertEqualsCanonicalizing($expected, array_intersect(array_column(array_slice($commissionRows, 1), 2), $expected));
        $this->assertEqualsCanonicalizing($expected, array_intersect(array_column(array_slice($entitlementRows, 1), 1), $expected));
        $this->assertEqualsCanonicalizing($expected, array_intersect(array_column(array_slice($disbursementRows, 1), 1), $expected));
        $this->assertEqualsCanonicalizing($expected, array_intersect(array_column(array_slice($auditRows, 1), 6), $expected));

        $allContent = implode("\n", [
            $commission->streamedContent(), $entitlements->streamedContent(), $disbursements->streamedContent(), $audit->streamedContent(),
        ]);
        foreach (['SENSITIVE-ACCOUNT-123456', 'SENSITIVE-OWNER', 'SENSITIVE-REFERENCE', 'SENSITIVE-TRANSFER-PROOF', 'SENSITIVE-FAILURE-PROOF'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $allContent);
        }
        $this->assertSame($before, $this->financialSnapshot());
    }

    public function test_export_routes_are_get_only_and_enforce_their_role_boundaries(): void
    {
        $routes = [
            'kepala-bumdes.commission-report.export' => 'kepala_bumdes',
            'kepala-bumdes.umkm-financial.entitlements.export' => 'kepala_bumdes',
            'kepala-bumdes.umkm-financial.disbursements.export' => 'kepala_bumdes',
            'admin.financial-audit.export' => 'admin_bumdes',
        ];

        foreach ($routes as $routeName => $allowedRole) {
            $this->get(route($routeName))->assertRedirect(route('login'));
        }

        foreach ($routes as $routeName => $allowedRole) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route);
            $this->assertSame(['GET', 'HEAD'], $route->methods());

            foreach (['pelanggan', 'pemilik_umkm', 'admin_bumdes', 'kurir', 'kepala_bumdes'] as $role) {
                if ($role === $allowedRole) {
                    continue;
                }

                $this->actingAs(User::factory()->create(['role' => $role]))
                    ->get(route($routeName))
                    ->assertForbidden();
            }
        }
    }

    private function makeStore(string $name): UmkmStore
    {
        $owner = User::factory()->create(['role' => 'pemilik_umkm']);

        return $owner->umkmStore()->create([
            'name' => $name,
            'slug' => 'export-sec-'.Str::lower(Str::random(10)),
            'status' => 'approved',
        ]);
    }

    private function makeOrder(UmkmStore $store, bool $confirmedPayment = false): Order
    {
        $order = Order::create([
            'order_number' => 'ORD-EXP-'.Str::upper(Str::random(10)),
            'checkout_token' => (string) Str::uuid(),
            'user_id' => User::factory()->create(['role' => 'pelanggan'])->id,
            'fulfillment_method' => 'pickup',
            'recipient_name' => 'Pelanggan Privat',
            'recipient_phone' => '081234567890',
            'subtotal' => '1234567890.50',
            'shipping_total' => '0.00',
            'grand_total' => '1234567890.50',
            'payment_status' => 'paid',
            'payment_confirmed_at' => now(),
            'order_status' => 'completed',
        ]);

        if ($confirmedPayment) {
            Payment::create([
                'order_id' => $order->id,
                'confirmed_order_id' => $order->id,
                'payment_number' => 'PAY-EXP-'.Str::upper(Str::random(10)),
                'payment_method' => 'cash_pickup',
                'amount' => '1234567890.50',
                'status' => 'paid',
            ]);
        }

        return $order;
    }

    private function makeCommissionStoreOrder(UmkmStore $store): StoreOrder
    {
        $order = $this->makeOrder($store);

        return StoreOrder::create([
            'order_id' => $order->id,
            'umkm_store_id' => $store->id,
            'store_order_number' => 'SO-EXP-'.Str::upper(Str::random(10)),
            'store_name_snapshot' => $store->name,
            'subtotal' => '1234567890.50',
            'shipping_amount' => '0.00',
            'total' => '1234567890.50',
            'fulfillment_status' => 'completed',
            'commission_status' => 'calculated',
            'commission_percentage' => '12.50',
            'commission_amount' => '123456.70',
            'umkm_amount' => '1234444433.80',
        ]);
    }

    private function makeEntitlement(UmkmStore $store): UmkmEntitlement
    {
        $order = $this->makeOrder($store, true);
        $storeOrder = StoreOrder::create([
            'order_id' => $order->id,
            'umkm_store_id' => $store->id,
            'store_order_number' => 'SO-ENT-'.Str::upper(Str::random(10)),
            'store_name_snapshot' => $store->name,
            'subtotal' => '150000.50',
            'shipping_amount' => '0.00',
            'total' => '150000.50',
            'fulfillment_status' => 'completed',
            'commission_status' => 'unconfigured',
        ]);
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();

        return UmkmEntitlement::create([
            'umkm_store_id' => $store->id,
            'order_id' => $order->id,
            'store_order_id' => $storeOrder->id,
            'payment_id' => $payment->id,
            'source_umkm_amount' => '150000.50',
            'source_commission_amount' => '0.00',
            'source_commission_percentage' => '0.00',
            'commission_status_snapshot' => 'calculated',
            'status' => 'held',
            'accrued_at' => now(),
        ]);
    }

    private function makeDisbursement(UmkmStore $store, bool $sensitive): UmkmDisbursement
    {
        $account = $store->recipientBankAccounts()->create([
            'bank_name' => $sensitive ? 'SENSITIVE-BANK' : 'Bank Test',
            'account_holder_name' => $sensitive ? 'SENSITIVE-OWNER' : 'Pemilik Test',
            'account_number' => $sensitive ? 'SENSITIVE-ACCOUNT-123456' : '001'.Str::random(8),
            'status' => 'active',
            'active_store_lock' => 'store:'.$store->id,
            'created_by' => $store->user_id,
            'updated_by' => $store->user_id,
        ]);

        return UmkmDisbursement::create([
            'umkm_store_id' => $store->id,
            'recipient_bank_account_id' => $account->id,
            'recipient_bank_name' => $account->bank_name,
            'recipient_account_holder_name' => $account->account_holder_name,
            'recipient_account_number' => $account->account_number,
            'requested_amount' => '123456.70',
            'status' => 'cancelled',
            'requested_by' => $store->user_id,
            'requested_at' => now(),
            'transfer_reference' => $sensitive ? 'SENSITIVE-REFERENCE' : null,
            'transfer_proof_path' => $sensitive ? 'SENSITIVE-TRANSFER-PROOF' : null,
            'transfer_failure_proof_path' => $sensitive ? 'SENSITIVE-FAILURE-PROOF' : null,
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function makeMissingEntitlementStoreOrder(UmkmStore $store, string $fulfillmentStatus): StoreOrder
    {
        $order = $this->makeOrder($store, true);

        return StoreOrder::create([
            'order_id' => $order->id,
            'umkm_store_id' => $store->id,
            'store_order_number' => 'SO-MISS-'.Str::upper(Str::random(10)),
            'store_name_snapshot' => $store->name,
            'subtotal' => '0.00',
            'shipping_amount' => '0.00',
            'total' => '0.00',
            'fulfillment_status' => $fulfillmentStatus,
            'commission_status' => 'unconfigured',
        ]);
    }

    /** @return array<string, int> */
    private function financialSnapshot(): array
    {
        return [
            'orders' => Order::count(),
            'store_orders' => StoreOrder::count(),
            'payments' => Payment::count(),
            'entitlements' => UmkmEntitlement::count(),
            'entitlement_events' => UmkmEntitlementEvent::count(),
            'disbursements' => UmkmDisbursement::count(),
            'allocations' => UmkmDisbursementAllocation::count(),
            'disbursement_events' => UmkmDisbursementEvent::count(),
        ];
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

    private function protectedCsvText(string $value): string
    {
        $isFormula = preg_match('/^[=+\-@\t\r]/', $value) === 1;
        $value = str_replace(["\r", "\n"], ' ', $value);

        return $isFormula ? "'{$value}" : $value;
    }
}
