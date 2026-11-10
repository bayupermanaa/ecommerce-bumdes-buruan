<?php

namespace Tests\Feature;

use App\Models\UmkmDisbursement;
use App\Models\UmkmDisbursementAllocation;
use App\Models\UmkmEntitlement;
use App\Models\UmkmStore;
use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class KepalaBumdesUmkmFinancialReportTest extends TestCase
{
    use RefreshDatabase;

    private const ENTITLEMENT_ROUTE = 'kepala-bumdes.umkm-financial.entitlements';

    private const ENTITLEMENT_EXPORT_ROUTE = 'kepala-bumdes.umkm-financial.entitlements.export';

    private const DISBURSEMENT_ROUTE = 'kepala-bumdes.umkm-financial.disbursements';

    private const DISBURSEMENT_EXPORT_ROUTE = 'kepala-bumdes.umkm-financial.disbursements.export';

    public function test_head_can_render_both_financial_report_views_with_their_contract_data(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);

        $entitlements = $this->actingAs($head)->get(route(self::ENTITLEMENT_ROUTE));
        $entitlements->assertOk()
            ->assertViewIs('kepala-bumdes.reports.umkm-financial.entitlements')
            ->assertSee('Laporan Hak Pendapatan Penjual');
        foreach (['summary', 'statusSummary', 'byStore', 'details', 'filters', 'storeOptions'] as $variable) {
            $entitlements->assertViewHas($variable);
        }

        $disbursements = $this->actingAs($head)->get(route(self::DISBURSEMENT_ROUTE));
        $disbursements->assertOk()
            ->assertViewIs('kepala-bumdes.reports.umkm-financial.disbursements')
            ->assertSee('Laporan Pencairan Penjual');
        foreach (['summary', 'statusSummary', 'byStore', 'details', 'filters', 'storeOptions'] as $variable) {
            $disbursements->assertViewHas($variable);
        }
    }

    public function test_guests_are_redirected_to_login_for_both_financial_reports(): void
    {
        $this->get(route(self::ENTITLEMENT_ROUTE))->assertRedirect(route('login'));
        $this->get(route(self::DISBURSEMENT_ROUTE))->assertRedirect(route('login'));
        $this->get(route(self::ENTITLEMENT_EXPORT_ROUTE))->assertRedirect(route('login'));
        $this->get(route(self::DISBURSEMENT_EXPORT_ROUTE))->assertRedirect(route('login'));
    }

    public function test_non_head_roles_are_forbidden_from_both_financial_reports(): void
    {
        foreach (['pelanggan', 'penjual', 'admin_bumdes', 'kurir'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->get(route(self::ENTITLEMENT_ROUTE))->assertForbidden();
            $this->actingAs($user)->get(route(self::DISBURSEMENT_ROUTE))->assertForbidden();
            $this->actingAs($user)->get(route(self::ENTITLEMENT_EXPORT_ROUTE))->assertForbidden();
            $this->actingAs($user)->get(route(self::DISBURSEMENT_EXPORT_ROUTE))->assertForbidden();
        }
    }

    public function test_head_can_export_entitlements_with_null_decimal_filters_and_formula_protection(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $formulaStore = $this->makeStore('=SUM(1,1)');
        $otherStore = $this->makeStore('Toko Hak Lain');
        $entitlements = [];
        foreach ([
            ['accrued', '100000.50', $formulaStore], ['eligible', '200000.20', $formulaStore],
            ['available', '300000.70', $formulaStore], ['reserved', '400000.30', $formulaStore],
            ['held', null, $formulaStore], ['settled', '500000.40', $otherStore], ['reversed', '0.00', $otherStore],
        ] as [$status, $amount, $store]) {
            $entitlements[$status] = $this->makeEntitlement($store, $status, $amount);
        }
        $entitlements['accrued']->forceFill(['accrued_at' => '2026-10-01 08:00:00'])->save();
        foreach (['eligible', 'available', 'reserved', 'held', 'settled', 'reversed'] as $status) {
            $entitlements[$status]->forceFill(['accrued_at' => '2026-10-15 18:45:00'])->save();
        }
        $cancelled = $this->makeDisbursement($formulaStore, 'cancelled', '300000.70');
        UmkmDisbursementAllocation::create([
            'umkm_disbursement_id' => $cancelled->id,
            'umkm_entitlement_id' => $entitlements['available']->id,
            'amount' => '300000.70',
            'entitlement_reservation_lock' => null,
        ]);

        $before = [UmkmEntitlement::count(), UmkmDisbursement::count(), UmkmDisbursementAllocation::count()];
        $response = $this->actingAs($head)->get(route(self::ENTITLEMENT_EXPORT_ROUTE));
        $content = $response->streamedContent();
        $rows = $this->csvRows($content);

        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertSame([
            'Tanggal Terbentuk', 'Nama Penjual', 'Referensi StoreOrder', 'Nomor Pesanan', 'Status Entitlement',
            'Nominal Hak Penjual', 'Tanggal Eligible', 'Tanggal Rekonsiliasi', 'Tanggal Settled',
        ], $rows[0]);
        $this->assertCount(8, $rows);
        $byStatus = collect(array_slice($rows, 1))->keyBy(4);
        $this->assertSame("'=SUM(1,1)", $byStatus->get('available')[1]);
        $this->assertSame('300000.70', $byStatus->get('available')[5]);
        $this->assertSame('Nominal belum diketahui', $byStatus->get('held')[5]);
        $this->assertSame('0.00', $byStatus->get('reversed')[5]);

        $filteredRows = $this->csvRows($this->actingAs($head)->get(route(self::ENTITLEMENT_EXPORT_ROUTE, [
            'date_from' => '2026-10-10',
            'date_to' => '2026-10-15',
            'store_id' => $formulaStore->id,
            'entitlement_status' => 'available',
        ]))->streamedContent());
        $this->assertCount(2, $filteredRows);
        $this->assertSame('available', $filteredRows[1][4]);
        $this->assertSame('300000.70', $filteredRows[1][5]);
        $this->actingAs($head)->get(route(self::ENTITLEMENT_ROUTE, [
            'date_from' => '2026-10-10',
            'store_id' => $formulaStore->id,
            'entitlement_status' => 'available',
        ]))->assertOk()->assertSee(str_replace('&', '&amp;', route(self::ENTITLEMENT_EXPORT_ROUTE, [
            'date_from' => '2026-10-10',
            'store_id' => $formulaStore->id,
            'entitlement_status' => 'available',
        ])), false);
        $this->assertSame($before, [UmkmEntitlement::count(), UmkmDisbursement::count(), UmkmDisbursementAllocation::count()]);
    }

    public function test_head_can_export_disbursements_with_status_filters_and_without_private_snapshots(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $stores = [
            'requested' => $this->makeStore('=DISB+1'),
            'under_review' => $this->makeStore('Toko Review'),
            'approved' => $this->makeStore('Toko Approved'),
            'terminal' => $this->makeStore('Toko Terminal'),
        ];
        $disbursements = [];
        foreach ([
            ['requested', '100000.50', $stores['requested']], ['under_review', '200000.20', $stores['under_review']],
            ['approved', '300000.30', $stores['approved']], ['rejected', '400000.40', $stores['terminal']],
            ['cancelled', '500000.50', $stores['terminal']], ['transfer_failed', '600000.60', $stores['terminal']],
            ['paid', '700000.70', $stores['terminal']],
        ] as [$status, $amount, $store]) {
            $disbursements[$status] = $this->makeDisbursement($store, $status, $amount);
        }
        $disbursements['requested']->forceFill(['requested_at' => '2026-10-01 08:00:00'])->save();
        $disbursements['paid']->forceFill([
            'requested_at' => '2026-10-15 18:45:00',
            'recipient_account_number' => 'SENSITIVE-ACCOUNT-123456',
            'transfer_reference' => 'SENSITIVE-TRANSFER-REFERENCE',
            'transfer_proof_path' => 'private/SENSITIVE-TRANSFER-PROOF.pdf',
            'transfer_failure_proof_path' => 'private/SENSITIVE-FAILURE-PROOF.pdf',
        ])->save();

        $before = [UmkmDisbursement::count(), UmkmEntitlement::count(), UmkmDisbursementAllocation::count()];
        $response = $this->actingAs($head)->get(route(self::DISBURSEMENT_EXPORT_ROUTE));
        $content = $response->streamedContent();
        $rows = $this->csvRows($content);

        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertSame([
            'ID Pengajuan', 'Nama Penjual', 'Tanggal Pengajuan', 'Nominal Pengajuan', 'Status Pencairan',
            'Tanggal Review', 'Tanggal Persetujuan', 'Tanggal Transfer Dicatat', 'Tanggal Transfer Diverifikasi',
            'Tanggal Pencairan Berhasil', 'Tanggal Kegagalan Dikonfirmasi',
        ], $rows[0]);
        $this->assertCount(8, $rows);
        $byStatus = collect(array_slice($rows, 1))->keyBy(4);
        $this->assertSame("'=DISB+1", $byStatus->get('requested')[1]);
        $this->assertSame('700000.70', $byStatus->get('paid')[3]);
        $this->assertNotSame($byStatus->get('approved')[3], $byStatus->get('paid')[3]);
        foreach (['SENSITIVE-ACCOUNT-123456', 'SENSITIVE-TRANSFER-REFERENCE', 'SENSITIVE-TRANSFER-PROOF.pdf', 'SENSITIVE-FAILURE-PROOF.pdf'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $content);
        }

        $filteredRows = $this->csvRows($this->actingAs($head)->get(route(self::DISBURSEMENT_EXPORT_ROUTE, [
            'date_from' => '2026-10-10',
            'store_id' => $stores['terminal']->id,
            'disbursement_status' => 'paid',
        ]))->streamedContent());
        $this->assertCount(2, $filteredRows);
        $this->assertSame('paid', $filteredRows[1][4]);
        $this->assertSame('700000.70', $filteredRows[1][3]);
        $this->actingAs($head)->get(route(self::DISBURSEMENT_ROUTE, [
            'date_from' => '2026-10-10',
            'store_id' => $stores['terminal']->id,
            'disbursement_status' => 'paid',
        ]))->assertOk()->assertSee(str_replace('&', '&amp;', route(self::DISBURSEMENT_EXPORT_ROUTE, [
            'date_from' => '2026-10-10',
            'store_id' => $stores['terminal']->id,
            'disbursement_status' => 'paid',
        ])), false);
        $this->assertSame($before, [UmkmDisbursement::count(), UmkmEntitlement::count(), UmkmDisbursementAllocation::count()]);
    }

    public function test_empty_entitlement_report_keeps_all_statuses_and_renders_empty_state(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);

        $response = $this->actingAs($head)->get(route(self::ENTITLEMENT_ROUTE));
        $summary = $response->viewData('summary');
        $statusSummary = $response->viewData('statusSummary');

        $response->assertOk()
            ->assertSee('Rp0', false)
            ->assertSee('Belum ada entitlement pada filter yang dipilih.');
        $this->assertSame(0, $summary->entitlement_count);
        $this->assertSame('0', (string) $summary->known_amount_total);
        $this->assertSame(0, $summary->unknown_amount_count);
        $this->assertCount(7, $statusSummary);
        foreach (['accrued', 'eligible', 'available', 'reserved', 'held', 'settled', 'reversed'] as $status) {
            $this->assertSame(0, $statusSummary->get($status)->entitlement_count);
        }
    }

    public function test_empty_disbursement_report_keeps_all_statuses_and_renders_empty_state(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);

        $response = $this->actingAs($head)->get(route(self::DISBURSEMENT_ROUTE));
        $summary = $response->viewData('summary');
        $statusSummary = $response->viewData('statusSummary');

        $response->assertOk()
            ->assertSee('Rp0', false)
            ->assertSee('Belum ada pengajuan pencairan pada filter yang dipilih.');
        $this->assertSame(0, $summary->disbursement_count);
        $this->assertSame('0', (string) $summary->historical_requested_total);
        $this->assertSame('0', (string) $summary->paid_total);
        $this->assertSame('0', (string) $summary->in_progress_total);
        $this->assertCount(7, $statusSummary);
        foreach (['requested', 'under_review', 'approved', 'paid', 'rejected', 'cancelled', 'transfer_failed'] as $status) {
            $this->assertSame(0, $statusSummary->get($status)->disbursement_count);
        }
    }

    public function test_reports_do_not_render_sensitive_disbursement_snapshots_or_private_paths(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $store = $this->makeStore();
        $account = $store->recipientBankAccounts()->create([
            'bank_name' => 'Bank Test',
            'account_holder_name' => 'Pemilik Test',
            'account_number' => 'SENSITIVE-ACCOUNT-123456',
            'status' => 'active',
            'active_store_lock' => 'store:'.$store->id,
            'verified_at' => now(),
            'created_by' => $store->user_id,
            'updated_by' => $store->user_id,
        ]);

        UmkmDisbursement::create([
            'umkm_store_id' => $store->id,
            'recipient_bank_account_id' => $account->id,
            'recipient_bank_name' => 'Bank Test',
            'recipient_account_holder_name' => 'Pemilik Test',
            'recipient_account_number' => 'SENSITIVE-ACCOUNT-123456',
            'requested_amount' => '100000.00',
            'status' => 'requested',
            'active_request_lock' => 'store:'.$store->id,
            'requested_by' => $store->user_id,
            'requested_at' => now(),
            'transfer_proof_path' => 'private/SENSITIVE-TRANSFER-PROOF.pdf',
            'transfer_failure_proof_path' => 'private/SENSITIVE-FAILURE-PROOF.pdf',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->actingAs($head)->get(route(self::ENTITLEMENT_ROUTE))
            ->assertOk()
            ->assertDontSee('SENSITIVE-ACCOUNT-123456')
            ->assertDontSee('SENSITIVE-TRANSFER-PROOF.pdf')
            ->assertDontSee('SENSITIVE-FAILURE-PROOF.pdf');
        $this->actingAs($head)->get(route(self::DISBURSEMENT_ROUTE))
            ->assertOk()
            ->assertDontSee('SENSITIVE-ACCOUNT-123456')
            ->assertDontSee('SENSITIVE-TRANSFER-PROOF.pdf')
            ->assertDontSee('SENSITIVE-FAILURE-PROOF.pdf');
    }

    public function test_financial_report_routes_only_accept_get_and_head(): void
    {
        foreach ([self::ENTITLEMENT_ROUTE, self::DISBURSEMENT_ROUTE] as $name) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route);
            $this->assertSame(['GET', 'HEAD'], $route->methods());
        }
    }

    public function test_entitlement_report_aggregates_every_status_per_store_and_keeps_unknown_amounts_separate(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $storeA = $this->makeStore('Toko Hak A');
        $storeB = $this->makeStore('Toko Hak B');

        foreach ([
            [$storeA, 'accrued', '100000.00'],
            [$storeA, 'eligible', '200000.00'],
            [$storeA, 'available', '300000.00'],
            [$storeA, 'reserved', '400000.00'],
            [$storeB, 'settled', '500000.00'],
            [$storeB, 'held', null],
            [$storeB, 'reversed', '50000.00'],
        ] as [$store, $status, $amount]) {
            $this->makeEntitlement($store, $status, $amount);
        }

        $response = $this->actingAs($head)->get(route(self::ENTITLEMENT_ROUTE));
        $summary = $response->viewData('summary');
        $statusSummary = $response->viewData('statusSummary');
        $byStore = $response->viewData('byStore');

        $this->assertSame(7, $summary->entitlement_count);
        $this->assertSame('1550000', (string) $summary->known_amount_total);
        $this->assertSame(1, $summary->unknown_amount_count);
        $this->assertSame('300000', (string) $statusSummary->get('available')->known_amount_total);
        $this->assertSame('400000', (string) $statusSummary->get('reserved')->known_amount_total);
        $this->assertSame('500000', (string) $statusSummary->get('settled')->known_amount_total);
        $this->assertSame(1, $statusSummary->get('held')->unknown_amount_count);
        $this->assertSame('0', (string) $statusSummary->get('held')->known_amount_total);

        $availableForStoreA = $byStore->first(fn (object $row): bool => (int) $row->store_id === $storeA->id && $row->status === 'available');
        $heldForStoreB = $byStore->first(fn (object $row): bool => (int) $row->store_id === $storeB->id && $row->status === 'held');
        $this->assertSame('300000', (string) $availableForStoreA->known_amount_total);
        $this->assertSame(1, $heldForStoreB->unknown_amount_count);
        $response->assertSee('Nominal belum diketahui');
    }

    public function test_entitlement_report_counts_an_entitlement_once_despite_multiple_historical_allocations(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $store = $this->makeStore('Toko Riwayat Allocation');
        $entitlement = $this->makeEntitlement($store, 'available', '150000.50');
        $cancelled = $this->makeDisbursement($store, 'cancelled', '150000.50');
        $rejected = $this->makeDisbursement($store, 'rejected', '150000.50');

        UmkmDisbursementAllocation::create([
            'umkm_disbursement_id' => $cancelled->id,
            'umkm_entitlement_id' => $entitlement->id,
            'amount' => '150000.50',
            'entitlement_reservation_lock' => null,
        ]);
        UmkmDisbursementAllocation::create([
            'umkm_disbursement_id' => $rejected->id,
            'umkm_entitlement_id' => $entitlement->id,
            'amount' => '150000.50',
            'entitlement_reservation_lock' => null,
        ]);

        $response = $this->actingAs($head)->get(route(self::ENTITLEMENT_ROUTE));
        $summary = $response->viewData('summary');
        $available = $response->viewData('statusSummary')->get('available');

        $this->assertSame(2, UmkmDisbursementAllocation::query()->count());
        $this->assertSame(1, $summary->entitlement_count);
        $this->assertSame(15000050, $this->minorUnits((string) $summary->known_amount_total));
        $this->assertSame(1, $available->entitlement_count);
        $this->assertSame(15000050, $this->minorUnits((string) $available->known_amount_total));
    }

    public function test_disbursement_report_aggregates_statuses_and_store_breakdowns_without_treating_non_paid_as_paid(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $storeA = $this->makeStore('Toko Pencairan A');
        $storeB = $this->makeStore('Toko Pencairan B');
        $storeC = $this->makeStore('Toko Pencairan C');

        foreach ([
            [$storeA, 'requested', '100000.00'],
            [$storeB, 'under_review', '200000.00'],
            [$storeC, 'approved', '300000.00'],
            [$storeA, 'paid', '400000.00'],
            [$storeB, 'rejected', '500000.00'],
            [$storeB, 'cancelled', '600000.00'],
            [$storeC, 'transfer_failed', '700000.00'],
        ] as [$store, $status, $amount]) {
            $this->makeDisbursement($store, $status, $amount);
        }

        $response = $this->actingAs($head)->get(route(self::DISBURSEMENT_ROUTE));
        $summary = $response->viewData('summary');
        $statusSummary = $response->viewData('statusSummary');
        $byStore = $response->viewData('byStore');

        $this->assertSame(7, $summary->disbursement_count);
        $this->assertSame('2800000', (string) $summary->historical_requested_total);
        $this->assertSame(1, $summary->paid_count);
        $this->assertSame('400000', (string) $summary->paid_total);
        $this->assertSame(3, $summary->in_progress_count);
        $this->assertSame('600000', (string) $summary->in_progress_total);
        $this->assertSame('400000', (string) $statusSummary->get('paid')->requested_amount_total);
        $this->assertSame('700000', (string) $statusSummary->get('transfer_failed')->requested_amount_total);
        $this->assertSame('600000', (string) $statusSummary->get('cancelled')->requested_amount_total);

        $approvedForStoreC = $byStore->first(fn (object $row): bool => (int) $row->store_id === $storeC->id && $row->status === 'approved');
        $paidForStoreA = $byStore->first(fn (object $row): bool => (int) $row->store_id === $storeA->id && $row->status === 'paid');
        $this->assertSame('300000', (string) $approvedForStoreC->requested_amount_total);
        $this->assertSame('400000', (string) $paidForStoreA->requested_amount_total);
    }

    public function test_financial_reports_preserve_decimal_precision_without_float_rounding(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $store = $this->makeStore('Toko Decimal');
        $this->makeEntitlement($store, 'available', '150000.50');
        $this->makeDisbursement($store, 'paid', '150000.50');
        $this->makeDisbursement($store, 'cancelled', '123456.70');

        $entitlementResponse = $this->actingAs($head)->get(route(self::ENTITLEMENT_ROUTE));
        $disbursementResponse = $this->actingAs($head)->get(route(self::DISBURSEMENT_ROUTE));

        $this->assertSame(15000050, $this->minorUnits((string) $entitlementResponse->viewData('summary')->known_amount_total));
        $this->assertSame(27345720, $this->minorUnits((string) $disbursementResponse->viewData('summary')->historical_requested_total));
        $this->assertSame(15000050, $this->minorUnits((string) $disbursementResponse->viewData('summary')->paid_total));
        $disbursementResponse->assertSee('Rp150.000,50', false)
            ->assertSee('Rp123.456,70', false)
            ->assertSee('Rp273.457,20', false);
    }

    public function test_entitlement_report_filters_the_accrued_at_cohort_inclusively_by_date(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $store = $this->makeStore('Toko Filter Tanggal Hak');
        $first = $this->makeEntitlement($store, 'available', '100000.00');
        $second = $this->makeEntitlement($store, 'available', '200000.00');

        $first->forceFill(['accrued_at' => '2026-10-01 08:00:00'])->save();
        $second->forceFill(['accrued_at' => '2026-10-15 18:45:00'])->save();

        $fromResponse = $this->actingAs($head)->get(route(self::ENTITLEMENT_ROUTE, [
            'date_from' => '2026-10-10',
        ]));
        $toResponse = $this->actingAs($head)->get(route(self::ENTITLEMENT_ROUTE, [
            'date_to' => '2026-10-10',
        ]));
        $singleDayResponse = $this->actingAs($head)->get(route(self::ENTITLEMENT_ROUTE, [
            'date_from' => '2026-10-15',
            'date_to' => '2026-10-15',
        ]));

        $this->assertSame(1, $fromResponse->viewData('summary')->entitlement_count);
        $this->assertSame(20000000, $this->minorUnits((string) $fromResponse->viewData('summary')->known_amount_total));
        $this->assertSame([$second->id], $fromResponse->viewData('details')->pluck('id')->all());

        $this->assertSame(1, $toResponse->viewData('summary')->entitlement_count);
        $this->assertSame(10000000, $this->minorUnits((string) $toResponse->viewData('summary')->known_amount_total));
        $this->assertSame([$first->id], $toResponse->viewData('details')->pluck('id')->all());

        $this->assertSame(1, $singleDayResponse->viewData('summary')->entitlement_count);
        $this->assertSame([$second->id], $singleDayResponse->viewData('details')->pluck('id')->all());
    }

    public function test_disbursement_report_filters_the_requested_at_cohort_inclusively_by_date(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $store = $this->makeStore('Toko Filter Tanggal Pencairan');
        $first = $this->makeDisbursement($store, 'paid', '100000.00');
        $second = $this->makeDisbursement($store, 'cancelled', '200000.00');

        $first->forceFill(['requested_at' => '2026-10-01 08:00:00'])->save();
        $second->forceFill(['requested_at' => '2026-10-15 18:45:00'])->save();

        $fromResponse = $this->actingAs($head)->get(route(self::DISBURSEMENT_ROUTE, [
            'date_from' => '2026-10-10',
        ]));
        $toResponse = $this->actingAs($head)->get(route(self::DISBURSEMENT_ROUTE, [
            'date_to' => '2026-10-10',
        ]));
        $singleDayResponse = $this->actingAs($head)->get(route(self::DISBURSEMENT_ROUTE, [
            'date_from' => '2026-10-15',
            'date_to' => '2026-10-15',
        ]));

        $this->assertSame(1, $fromResponse->viewData('summary')->disbursement_count);
        $this->assertSame(20000000, $this->minorUnits((string) $fromResponse->viewData('summary')->historical_requested_total));
        $this->assertSame([$second->id], $fromResponse->viewData('details')->pluck('id')->all());

        $this->assertSame(1, $toResponse->viewData('summary')->disbursement_count);
        $this->assertSame(10000000, $this->minorUnits((string) $toResponse->viewData('summary')->historical_requested_total));
        $this->assertSame([$first->id], $toResponse->viewData('details')->pluck('id')->all());

        $this->assertSame(1, $singleDayResponse->viewData('summary')->disbursement_count);
        $this->assertSame([$second->id], $singleDayResponse->viewData('details')->pluck('id')->all());
    }

    public function test_store_and_status_filters_limit_all_financial_report_datasets(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $storeA = $this->makeStore('Toko Filter A');
        $storeB = $this->makeStore('Toko Filter B');

        $available = $this->makeEntitlement($storeA, 'available', '100000.00');
        $reserved = $this->makeEntitlement($storeA, 'reserved', '200000.00');
        $settled = $this->makeEntitlement($storeB, 'settled', '300000.00');
        $requested = $this->makeDisbursement($storeA, 'requested', '110000.00');
        $paid = $this->makeDisbursement($storeA, 'paid', '220000.00');
        $approved = $this->makeDisbursement($storeB, 'approved', '330000.00');
        $failed = $this->makeDisbursement($storeB, 'transfer_failed', '440000.00');

        foreach ([
            ['available', $available->id, 10000000],
            ['reserved', $reserved->id, 20000000],
            ['settled', $settled->id, 30000000],
        ] as [$status, $id, $minorUnits]) {
            $response = $this->actingAs($head)->get(route(self::ENTITLEMENT_ROUTE, [
                'store_id' => $status === 'settled' ? $storeB->id : $storeA->id,
                'entitlement_status' => $status,
            ]));

            $response->assertOk();
            $this->assertSame(1, $response->viewData('summary')->entitlement_count);
            $this->assertSame($minorUnits, $this->minorUnits((string) $response->viewData('summary')->known_amount_total));
            $this->assertSame(1, $response->viewData('statusSummary')->get($status)->entitlement_count);
            $this->assertCount(1, $response->viewData('byStore'));
            $this->assertSame([$id], $response->viewData('details')->pluck('id')->all());
        }

        foreach ([
            ['requested', $storeA->id, $requested->id, 11000000],
            ['paid', $storeA->id, $paid->id, 22000000],
            ['approved', $storeB->id, $approved->id, 33000000],
            ['transfer_failed', $storeB->id, $failed->id, 44000000],
        ] as [$status, $storeId, $id, $minorUnits]) {
            $response = $this->actingAs($head)->get(route(self::DISBURSEMENT_ROUTE, [
                'store_id' => $storeId,
                'disbursement_status' => $status,
            ]));

            $response->assertOk();
            $this->assertSame(1, $response->viewData('summary')->disbursement_count);
            $this->assertSame($minorUnits, $this->minorUnits((string) $response->viewData('summary')->historical_requested_total));
            $this->assertSame(1, $response->viewData('statusSummary')->get($status)->disbursement_count);
            $this->assertCount(1, $response->viewData('byStore'));
            $this->assertSame([$id], $response->viewData('details')->pluck('id')->all());
        }
    }

    public function test_financial_report_filters_are_validated_without_mutating_financial_data(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $beforeEntitlements = UmkmEntitlement::query()->count();
        $beforeDisbursements = UmkmDisbursement::query()->count();

        foreach ([
            [self::ENTITLEMENT_ROUTE, 'entitlement_status'],
            [self::DISBURSEMENT_ROUTE, 'disbursement_status'],
        ] as [$routeName, $statusKey]) {
            foreach ([
                [['date_from' => 'not-a-date'], 'date_from'],
                [['date_to' => 'not-a-date'], 'date_to'],
                [['date_from' => '2026-10-16', 'date_to' => '2026-10-15'], 'date_to'],
                [['store_id' => 999999], 'store_id'],
                [['store_id' => 'not-an-integer'], 'store_id'],
                [[$statusKey => 'unknown-status'], $statusKey],
            ] as [$query, $errorField]) {
                $response = $this->actingAs($head)->from(route($routeName))->get(route($routeName, $query));

                $response->assertRedirect(route($routeName));
                $response->assertSessionHasErrors($errorField);
            }
        }

        $this->assertSame($beforeEntitlements, UmkmEntitlement::query()->count());
        $this->assertSame($beforeDisbursements, UmkmDisbursement::query()->count());
    }

    public function test_financial_report_pagination_keeps_summary_statuses_and_active_filters_stable(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $store = $this->makeStore('Toko Pagination Keuangan');

        for ($index = 1; $index <= 16; $index++) {
            $entitlement = $this->makeEntitlement($store, 'available', '100000.00');
            $entitlement->forceFill(['accrued_at' => sprintf('2026-10-20 10:%02d:00', $index)])->save();

            $disbursement = $this->makeDisbursement($store, 'paid', '200000.00');
            $disbursement->forceFill(['requested_at' => sprintf('2026-10-20 11:%02d:00', $index)])->save();
        }

        $entitlementFilters = [
            'date_from' => '2026-10-20',
            'date_to' => '2026-10-20',
            'store_id' => $store->id,
            'entitlement_status' => 'available',
        ];
        $disbursementFilters = [
            'date_from' => '2026-10-20',
            'date_to' => '2026-10-20',
            'store_id' => $store->id,
            'disbursement_status' => 'paid',
        ];

        $entitlementPageOne = $this->actingAs($head)->get(route(self::ENTITLEMENT_ROUTE, $entitlementFilters));
        $entitlementPageTwo = $this->actingAs($head)->get(route(self::ENTITLEMENT_ROUTE, $entitlementFilters + ['page' => 2]));
        $disbursementPageOne = $this->actingAs($head)->get(route(self::DISBURSEMENT_ROUTE, $disbursementFilters));
        $disbursementPageTwo = $this->actingAs($head)->get(route(self::DISBURSEMENT_ROUTE, $disbursementFilters + ['page' => 2]));

        foreach ([[$entitlementPageOne, $entitlementPageTwo, 'entitlement_count', 'available'], [$disbursementPageOne, $disbursementPageTwo, 'disbursement_count', 'paid']] as [$pageOne, $pageTwo, $countField, $status]) {
            $pageOne->assertOk();
            $pageTwo->assertOk();
            $this->assertSame(16, $pageOne->viewData('summary')->{$countField});
            $this->assertSame(16, $pageTwo->viewData('summary')->{$countField});
            $this->assertSame(16, $pageOne->viewData('statusSummary')->get($status)->{$countField});
            $this->assertSame(16, $pageTwo->viewData('statusSummary')->get($status)->{$countField});
            $this->assertCount(15, $pageOne->viewData('details'));
            $this->assertCount(1, $pageTwo->viewData('details'));
            $this->assertEmpty(array_intersect(
                $pageOne->viewData('details')->pluck('id')->all(),
                $pageTwo->viewData('details')->pluck('id')->all(),
            ));
        }

        $this->assertStringContainsString('date_from=2026-10-20', $entitlementPageOne->viewData('details')->url(2));
        $this->assertStringContainsString('store_id='.$store->id, $entitlementPageOne->viewData('details')->url(2));
        $this->assertStringContainsString('entitlement_status=available', $entitlementPageOne->viewData('details')->url(2));
        $this->assertStringContainsString('date_from=2026-10-20', $disbursementPageOne->viewData('details')->url(2));
        $this->assertStringContainsString('store_id='.$store->id, $disbursementPageOne->viewData('details')->url(2));
        $this->assertStringContainsString('disbursement_status=paid', $disbursementPageOne->viewData('details')->url(2));
    }

    private function makeStore(?string $name = null): UmkmStore
    {
        $owner = User::factory()->create(['role' => 'penjual']);

        return $owner->umkmStore()->create([
            'name' => $name ?? 'Toko Laporan Test',
            'slug' => 'laporan-'.Str::lower(Str::random(10)),
            'status' => 'approved',
        ]);
    }

    private function makeEntitlement(UmkmStore $store, string $status, ?string $amount): UmkmEntitlement
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $order = Order::create([
            'order_number' => 'ORD-FIN-'.Str::upper(Str::random(10)),
            'checkout_token' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'fulfillment_method' => 'pickup',
            'recipient_name' => 'Pelanggan Test',
            'recipient_phone' => '081234567890',
            'subtotal' => '100000.00',
            'shipping_total' => '0.00',
            'grand_total' => '100000.00',
            'payment_status' => 'paid',
            'order_status' => 'completed',
        ]);
        $storeOrder = StoreOrder::create([
            'order_id' => $order->id,
            'umkm_store_id' => $store->id,
            'store_order_number' => 'SO-FIN-'.Str::upper(Str::random(10)),
            'store_name_snapshot' => $store->name,
            'subtotal' => '100000.00',
            'shipping_amount' => '0.00',
            'total' => '100000.00',
            'fulfillment_status' => 'completed',
        ]);
        $payment = Payment::create([
            'order_id' => $order->id,
            'confirmed_order_id' => $order->id,
            'payment_number' => 'PAY-FIN-'.Str::upper(Str::random(10)),
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

    private function makeDisbursement(UmkmStore $store, string $status, string $amount): UmkmDisbursement
    {
        $account = $store->recipientBankAccounts()->first();
        if (! $account) {
            $account = $store->recipientBankAccounts()->create([
                'bank_name' => 'Bank Test',
                'account_holder_name' => 'Pemilik Test',
                'account_number' => '001'.Str::random(8),
                'status' => 'active',
                'active_store_lock' => 'store:'.$store->id,
                'verified_at' => now(),
                'created_by' => $store->user_id,
                'updated_by' => $store->user_id,
            ]);
        }

        $inProgress = in_array($status, ['requested', 'under_review', 'approved'], true);

        return UmkmDisbursement::create([
            'umkm_store_id' => $store->id,
            'recipient_bank_account_id' => $account->id,
            'recipient_bank_name' => $account->bank_name,
            'recipient_account_holder_name' => $account->account_holder_name,
            'recipient_account_number' => $account->account_number,
            'requested_amount' => $amount,
            'status' => $status,
            'active_request_lock' => $inProgress ? 'store:'.$store->id : null,
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
        $this->assertMatchesRegularExpression('/^-?(\d+)(?:\.(\d{1,2}))?$/', $amount);
        preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches);

        return (((int) $matches[2] * 100) + (int) str_pad($matches[3] ?? '', 2, '0'))
            * ($matches[1] === '-' ? -1 : 1);
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
