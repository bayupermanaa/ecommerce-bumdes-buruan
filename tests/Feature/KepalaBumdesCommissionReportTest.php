<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\StoreOrder;
use App\Models\UmkmDisbursement;
use App\Models\UmkmStore;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class KepalaBumdesCommissionReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_head_can_render_empty_commission_report(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);

        $this->actingAs($head)->get(route('kepala-bumdes.commission-report.index'))
            ->assertOk()
            ->assertSee('Laporan Komisi BUMDes')
            ->assertSee('Rp0', false)
            ->assertSee('Belum ada transaksi terverifikasi.');
    }

    public function test_guest_is_redirected_and_other_roles_are_forbidden(): void
    {
        $this->get(route('kepala-bumdes.commission-report.index'))->assertRedirect(route('login'));
        $this->get(route('kepala-bumdes.commission-report.export'))->assertRedirect(route('login'));

        foreach (['pelanggan', 'penjual', 'admin_bumdes', 'kurir'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('kepala-bumdes.commission-report.index'))
                ->assertForbidden();
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('kepala-bumdes.commission-report.export'))
                ->assertForbidden();
        }
    }

    public function test_head_can_stream_filtered_snapshot_csv_without_private_data_or_mutation(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $storeA = $this->makeStore('Toko CSV A');
        $storeFormula = $this->makeStore('=SUM(1,1)');
        $order = $this->makeOrder('paid', '300000.00', Carbon::parse('2026-10-15 18:45:00'));
        $rowA = $this->makeStoreOrder($order, $storeA, [
            'total' => '100000.50',
            'commission_percentage' => '12.50',
            'commission_amount' => '12345.67',
            'umkm_amount' => '87654.83',
        ]);
        $this->makeStoreOrder($order, $storeFormula, [
            'total' => '200000.20',
            'commission_percentage' => '7.25',
            'commission_amount' => '10000.10',
            'umkm_amount' => '190000.10',
        ]);
        $unpaid = $this->makeOrder('unpaid', '999999.99', Carbon::parse('2026-10-15 19:00:00'));
        $this->makeStoreOrder($unpaid, $storeA, ['total' => '999999.99']);
        $unconfigured = $this->makeOrder('paid', '888888.88', Carbon::parse('2026-10-15 20:00:00'));
        $this->makeStoreOrder($unconfigured, $storeA, ['total' => '888888.88', 'commission_status' => 'unconfigured']);

        $account = $storeA->recipientBankAccounts()->create([
            'bank_name' => 'Bank Rahasia',
            'account_holder_name' => 'Pemilik Rahasia',
            'account_number' => 'SENSITIVE-ACCOUNT-123456',
            'status' => 'active',
            'active_store_lock' => 'store:'.$storeA->id,
            'created_by' => $storeA->user_id,
            'updated_by' => $storeA->user_id,
        ]);
        UmkmDisbursement::create([
            'umkm_store_id' => $storeA->id,
            'recipient_bank_account_id' => $account->id,
            'recipient_bank_name' => $account->bank_name,
            'recipient_account_holder_name' => $account->account_holder_name,
            'recipient_account_number' => $account->account_number,
            'requested_amount' => '100000.50',
            'status' => 'cancelled',
            'requested_by' => $storeA->user_id,
            'requested_at' => now(),
            'transfer_proof_path' => 'private/SENSITIVE-TRANSFER-PROOF.pdf',
            'transfer_failure_proof_path' => 'private/SENSITIVE-FAILURE-PROOF.pdf',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $before = [Order::count(), StoreOrder::count(), UmkmDisbursement::count()];
        $response = $this->actingAs($head)->get(route('kepala-bumdes.commission-report.export'));
        $content = $response->streamedContent();
        $rows = $this->csvRows($content);

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('laporan-komisi-', (string) $response->headers->get('content-disposition'));
        $this->assertSame([
            'Tanggal Pembayaran', 'Nomor Pesanan', 'Nama Penjual', 'Total Transaksi Toko',
            'Persentase Komisi Snapshot', 'Komisi BUMDes', 'Estimasi Hak Penjual',
            'Status Pembayaran', 'Status Pesanan',
        ], $rows[0]);
        $this->assertCount(3, $rows);
        $exportedByStore = collect(array_slice($rows, 1))->keyBy(2);
        $this->assertSame('100000.50', $exportedByStore->get($storeA->name)[3]);
        $this->assertSame('12.50', $exportedByStore->get($storeA->name)[4]);
        $this->assertSame('12345.67', $exportedByStore->get($storeA->name)[5]);
        $this->assertSame('87654.83', $exportedByStore->get($storeA->name)[6]);
        $this->assertSame('paid', $exportedByStore->get($storeA->name)[7]);
        $this->assertSame('completed', $exportedByStore->get($storeA->name)[8]);
        $this->assertSame("'=SUM(1,1)", $exportedByStore->get("'=SUM(1,1)")[2]);
        $this->assertStringNotContainsString($unpaid->order_number, $content);
        $this->assertStringNotContainsString($unconfigured->order_number, $content);
        $this->assertStringNotContainsString('SENSITIVE-ACCOUNT-123456', $content);
        $this->assertStringNotContainsString('SENSITIVE-TRANSFER-PROOF.pdf', $content);
        $this->assertStringNotContainsString('SENSITIVE-FAILURE-PROOF.pdf', $content);
        $this->assertSame($before, [Order::count(), StoreOrder::count(), UmkmDisbursement::count()]);
        $this->assertSame($rowA->id, StoreOrder::findOrFail($rowA->id)->id);
    }

    public function test_csv_export_filters_by_confirmation_date_and_store_and_link_preserves_active_filters(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $storeA = $this->makeStore('Toko Filter CSV A');
        $storeB = $this->makeStore('Toko Filter CSV B');
        $early = $this->makeOrder('paid', '100000.00', Carbon::parse('2026-10-01 08:00:00'));
        $late = $this->makeOrder('paid', '200000.00', Carbon::parse('2026-10-15 18:00:00'));
        $otherStore = $this->makeOrder('paid', '300000.00', Carbon::parse('2026-10-15 19:00:00'));
        $this->makeStoreOrder($early, $storeA);
        $this->makeStoreOrder($late, $storeA);
        $this->makeStoreOrder($otherStore, $storeB);

        $fromRows = $this->csvRows($this->actingAs($head)->get(route('kepala-bumdes.commission-report.export', [
            'date_from' => '2026-10-10',
        ]))->streamedContent());
        $toRows = $this->csvRows($this->actingAs($head)->get(route('kepala-bumdes.commission-report.export', [
            'date_to' => '2026-10-10',
        ]))->streamedContent());
        $storeRows = $this->csvRows($this->actingAs($head)->get(route('kepala-bumdes.commission-report.export', [
            'store_id' => $storeA->id,
        ]))->streamedContent());
        $report = $this->actingAs($head)->get(route('kepala-bumdes.commission-report.index', [
            'date_from' => '2026-10-10',
            'store_id' => $storeA->id,
        ]));

        $this->assertSame([$late->order_number, $otherStore->order_number], array_column(array_slice($fromRows, 1), 1));
        $this->assertSame([$early->order_number], array_column(array_slice($toRows, 1), 1));
        $this->assertSame([$early->order_number, $late->order_number], array_column(array_slice($storeRows, 1), 1));
        $report->assertOk()
            ->assertSee(str_replace('&', '&amp;', route('kepala-bumdes.commission-report.export', [
                'date_from' => '2026-10-10',
                'store_id' => $storeA->id,
            ])), false);
    }

    public function test_report_aggregates_paid_calculated_snapshots_per_store_without_double_counting_order_total(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $order = $this->makeOrder('paid', '300000.00');
        $storeA = $this->makeStore('Toko Komisi A');
        $storeB = $this->makeStore('Toko Komisi B');

        // The percentage deliberately differs from the stored amount: the report
        // must aggregate immutable snapshot amounts rather than recalculate them.
        $this->makeStoreOrder($order, $storeA, [
            'total' => '100000.00',
            'commission_percentage' => '35.00',
            'commission_amount' => '10000.00',
            'umkm_amount' => '90000.00',
        ]);
        $this->makeStoreOrder($order, $storeB, [
            'total' => '200000.00',
            'commission_percentage' => '1.00',
            'commission_amount' => '20000.00',
            'umkm_amount' => '180000.00',
        ]);

        $summary = $this->actingAs($head)
            ->get(route('kepala-bumdes.commission-report.index'))
            ->viewData('summary');

        $this->assertSame('300000', (string) $summary->transaction_total);
        $this->assertSame('30000', (string) $summary->commission_total);
        $this->assertSame('270000', (string) $summary->estimated_umkm_total);
        $this->assertSame(2, $summary->transaction_count);
        $this->assertSame(2, $summary->store_count);
    }

    public function test_report_excludes_unpaid_orders_and_snapshots_not_yet_calculated(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $store = $this->makeStore('Toko Filter Komisi');

        $unpaidOrder = $this->makeOrder('unpaid', '500000.00');
        $this->makeStoreOrder($unpaidOrder, $store, [
            'total' => '500000.00',
            'commission_amount' => '50000.00',
            'umkm_amount' => '450000.00',
        ]);

        $paidOrder = $this->makeOrder('paid', '600000.00');
        $this->makeStoreOrder($paidOrder, $store, [
            'total' => '600000.00',
            'commission_amount' => '60000.00',
            'umkm_amount' => '540000.00',
            'commission_status' => 'unconfigured',
        ]);

        $summary = $this->actingAs($head)
            ->get(route('kepala-bumdes.commission-report.index'))
            ->viewData('summary');

        $this->assertSame('0', (string) $summary->transaction_total);
        $this->assertSame('0', (string) $summary->commission_total);
        $this->assertSame('0', (string) $summary->estimated_umkm_total);
        $this->assertSame(0, $summary->transaction_count);
        $this->assertSame(0, $summary->store_count);
    }

    public function test_date_from_date_to_and_single_day_filters_use_payment_confirmation_date(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $store = $this->makeStore('Toko Periode');
        $earlyOrder = $this->makeOrder('paid', '100000.00', Carbon::parse('2026-10-01 09:30:00'));
        $lateOrder = $this->makeOrder('paid', '200000.00', Carbon::parse('2026-10-15 18:45:00'));

        $this->makeStoreOrder($earlyOrder, $store, [
            'total' => '100000.00',
            'commission_amount' => '10000.00',
            'umkm_amount' => '90000.00',
        ]);
        $this->makeStoreOrder($lateOrder, $store, [
            'total' => '200000.00',
            'commission_amount' => '20000.00',
            'umkm_amount' => '180000.00',
        ]);

        $fromSummary = $this->actingAs($head)
            ->get(route('kepala-bumdes.commission-report.index', ['date_from' => '2026-10-10']))
            ->viewData('summary');
        $toSummary = $this->actingAs($head)
            ->get(route('kepala-bumdes.commission-report.index', ['date_to' => '2026-10-10']))
            ->viewData('summary');
        $sameDaySummary = $this->actingAs($head)
            ->get(route('kepala-bumdes.commission-report.index', [
                'date_from' => '2026-10-15',
                'date_to' => '2026-10-15',
            ]))
            ->viewData('summary');

        $this->assertSame('200000', (string) $fromSummary->transaction_total);
        $this->assertSame('20000', (string) $fromSummary->commission_total);
        $this->assertSame('100000', (string) $toSummary->transaction_total);
        $this->assertSame('10000', (string) $toSummary->commission_total);
        $this->assertSame('200000', (string) $sameDaySummary->transaction_total);
        $this->assertSame('20000', (string) $sameDaySummary->commission_total);
    }

    public function test_store_filter_limits_summary_breakdown_and_details_to_the_selected_store(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $storeA = $this->makeStore('Toko Filter A');
        $storeB = $this->makeStore('Toko Filter B');
        $order = $this->makeOrder('paid', '300000.00');

        $storeOrderA = $this->makeStoreOrder($order, $storeA, [
            'total' => '100000.00',
            'commission_amount' => '10000.00',
            'umkm_amount' => '90000.00',
        ]);
        $this->makeStoreOrder($order, $storeB, [
            'total' => '200000.00',
            'commission_amount' => '20000.00',
            'umkm_amount' => '180000.00',
        ]);

        $response = $this->actingAs($head)->get(route('kepala-bumdes.commission-report.index', [
            'store_id' => $storeA->id,
        ]));
        $summary = $response->viewData('summary');
        $byStore = $response->viewData('byStore');
        $details = $response->viewData('details');

        $this->assertSame('100000', (string) $summary->transaction_total);
        $this->assertSame('10000', (string) $summary->commission_total);
        $this->assertSame('90000', (string) $summary->estimated_umkm_total);
        $this->assertCount(1, $byStore);
        $this->assertSame($storeA->id, $byStore->first()->id);
        $this->assertSame([$storeOrderA->id], $details->pluck('id')->all());
    }

    public function test_invalid_report_filters_return_validation_errors_instead_of_server_errors(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);

        $this->actingAs($head)
            ->get(route('kepala-bumdes.commission-report.index', ['date_from' => 'not-a-date']))
            ->assertRedirect()
            ->assertSessionHasErrors(['date_from']);
        $this->actingAs($head)
            ->get(route('kepala-bumdes.commission-report.index', ['date_to' => 'not-a-date']))
            ->assertRedirect()
            ->assertSessionHasErrors(['date_to']);
        $this->actingAs($head)
            ->get(route('kepala-bumdes.commission-report.index', [
                'date_from' => '2026-10-15',
                'date_to' => '2026-10-01',
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors(['date_to']);
        $this->actingAs($head)
            ->get(route('kepala-bumdes.commission-report.index', ['store_id' => 999999]))
            ->assertRedirect()
            ->assertSessionHasErrors(['store_id']);
        $this->actingAs($head)
            ->get(route('kepala-bumdes.commission-report.index', ['store_id' => 'not-an-id']))
            ->assertRedirect()
            ->assertSessionHasErrors(['store_id']);
    }

    public function test_pagination_keeps_the_same_summary_and_preserves_active_filters(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $store = $this->makeStore('Toko Pagination');
        $confirmedAt = Carbon::parse('2026-10-20 10:00:00');

        for ($index = 0; $index < 16; $index++) {
            $order = $this->makeOrder('paid', '100000.00', $confirmedAt);
            $this->makeStoreOrder($order, $store, [
                'total' => '100000.00',
                'commission_amount' => '10000.00',
                'umkm_amount' => '90000.00',
            ]);
        }

        $filters = ['date_from' => '2026-10-20', 'store_id' => $store->id];
        $pageOne = $this->actingAs($head)
            ->get(route('kepala-bumdes.commission-report.index', $filters));
        $pageTwo = $this->actingAs($head)
            ->get(route('kepala-bumdes.commission-report.index', $filters + ['page' => 2]));

        $pageOneSummary = $pageOne->viewData('summary');
        $pageTwoSummary = $pageTwo->viewData('summary');
        $pageOneDetails = $pageOne->viewData('details');
        $pageTwoDetails = $pageTwo->viewData('details');

        $this->assertSame('1600000', (string) $pageOneSummary->transaction_total);
        $this->assertSame('160000', (string) $pageOneSummary->commission_total);
        $this->assertSame((string) $pageOneSummary->transaction_total, (string) $pageTwoSummary->transaction_total);
        $this->assertSame((string) $pageOneSummary->commission_total, (string) $pageTwoSummary->commission_total);
        $this->assertCount(15, $pageOneDetails);
        $this->assertCount(1, $pageTwoDetails);
        $this->assertEmpty(array_intersect($pageOneDetails->pluck('id')->all(), $pageTwoDetails->pluck('id')->all()));
        $this->assertStringContainsString('date_from=2026-10-20', $pageOneDetails->url(2));
        $this->assertStringContainsString('store_id='.$store->id, $pageOneDetails->url(2));
    }

    public function test_paid_order_without_payment_confirmation_timestamp_is_only_excluded_when_a_date_filter_is_active(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $store = $this->makeStore('Toko Konfirmasi Null');
        $order = $this->makeOrder('paid', '100000.00', null, true);

        $this->makeStoreOrder($order, $store, [
            'total' => '100000.00',
            'commission_amount' => '10000.00',
            'umkm_amount' => '90000.00',
        ]);

        $unfiltered = $this->actingAs($head)
            ->get(route('kepala-bumdes.commission-report.index'))
            ->viewData('summary');
        $dated = $this->actingAs($head)
            ->get(route('kepala-bumdes.commission-report.index', ['date_from' => '2026-10-01']))
            ->viewData('summary');

        $this->assertSame('100000', (string) $unfiltered->transaction_total);
        $this->assertSame('10000', (string) $unfiltered->commission_total);
        $this->assertSame('0', (string) $dated->transaction_total);
        $this->assertSame('0', (string) $dated->commission_total);
    }

    public function test_report_formats_large_decimal_snapshot_amounts_and_percentage_for_presentation(): void
    {
        $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $store = $this->makeStore('Toko Nominal Pecahan');
        $order = $this->makeOrder('paid', '1234567890.50');

        $this->makeStoreOrder($order, $store, [
            'total' => '1234567890.50',
            'commission_percentage' => '12.50',
            'commission_amount' => '123456.70',
            'umkm_amount' => '1234444433.80',
        ]);

        $this->actingAs($head)
            ->get(route('kepala-bumdes.commission-report.index'))
            ->assertOk()
            ->assertSee('Rp1.234.567.890,50', false)
            ->assertSee('Rp123.456,70', false)
            ->assertSee('Rp1.234.444.433,80', false)
            ->assertSee('12,5%', false);
    }

    private function makeOrder(
        string $paymentStatus,
        string $grandTotal,
        ?Carbon $paymentConfirmedAt = null,
        bool $paymentConfirmedAtIsNull = false,
    ): Order
    {
        return Order::create([
            'order_number' => 'ORD-COMM-'.Str::upper(Str::random(10)),
            'checkout_token' => (string) Str::uuid(),
            'user_id' => User::factory()->create(['role' => 'pelanggan'])->id,
            'fulfillment_method' => 'pickup',
            'recipient_name' => 'Pelanggan Test',
            'recipient_phone' => '081234567890',
            'subtotal' => $grandTotal,
            'shipping_total' => '0.00',
            'grand_total' => $grandTotal,
            'payment_status' => $paymentStatus,
            'payment_confirmed_at' => $paymentStatus === 'paid' && ! $paymentConfirmedAtIsNull
                ? ($paymentConfirmedAt ?? now())
                : null,
            'order_status' => $paymentStatus === 'paid' ? 'completed' : 'pending_payment',
        ]);
    }

    private function makeStore(string $name): UmkmStore
    {
        $owner = User::factory()->create(['role' => 'penjual']);

        return $owner->umkmStore()->create([
            'name' => $name,
            'slug' => 'comm-'.Str::lower(Str::random(10)),
            'status' => 'approved',
        ]);
    }

    /** @param array<string, string> $overrides */
    private function makeStoreOrder(Order $order, UmkmStore $store, array $overrides = []): StoreOrder
    {
        return $order->storeOrders()->create(array_merge([
            'umkm_store_id' => $store->id,
            'store_order_number' => 'SO-COMM-'.Str::upper(Str::random(10)),
            'store_name_snapshot' => $store->name,
            'subtotal' => '100000.00',
            'shipping_amount' => '0.00',
            'total' => '100000.00',
            'fulfillment_status' => 'completed',
            'commission_status' => 'calculated',
            'commission_percentage' => '10.00',
            'commission_amount' => '10000.00',
            'umkm_amount' => '90000.00',
        ], $overrides));
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
