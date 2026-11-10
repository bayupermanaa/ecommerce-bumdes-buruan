<?php

namespace App\Http\Controllers\KepalaBumdes;

use App\Http\Controllers\Controller;
use App\Models\StoreOrder;
use App\Models\UmkmStore;
use App\Support\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommissionReportController extends Controller
{
    /** Read-only report payload for kepala-bumdes.reports.commissions.index. */
    public function index(Request $request): View
    {
        $filters = $this->validatedFilters($request);
        $base = $this->baseQuery($filters);

        $summary = (clone $base)->selectRaw('COALESCE(SUM(store_orders.total), 0) as transaction_total, COALESCE(SUM(store_orders.commission_amount), 0) as commission_total, COALESCE(SUM(store_orders.umkm_amount), 0) as estimated_umkm_total, COUNT(*) as transaction_count, COUNT(DISTINCT store_orders.umkm_store_id) as store_count')->first();

        $byStore = (clone $base)->join('umkm_stores', 'umkm_stores.id', '=', 'store_orders.umkm_store_id')
            ->selectRaw('umkm_stores.id, umkm_stores.name, COUNT(store_orders.id) as transaction_count, COALESCE(SUM(store_orders.total),0) as transaction_total, COALESCE(SUM(store_orders.commission_amount),0) as commission_total, COALESCE(SUM(store_orders.umkm_amount),0) as estimated_umkm_total')
            ->groupBy('umkm_stores.id', 'umkm_stores.name')->orderByDesc('commission_total')->get();

        $details = (clone $base)->with(['order:id,order_number,payment_status,payment_confirmed_at,order_status', 'umkmStore:id,name'])
            ->latest('id')->paginate(15)->withQueryString();

        return view('kepala-bumdes.reports.commissions.index', compact('summary', 'byStore', 'details', 'filters') + [
            'storeOptions' => UmkmStore::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Stream the same filtered immutable commission snapshots as the report. */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->validatedFilters($request);
        $rows = $this->baseQuery($filters)
            ->join('orders', 'orders.id', '=', 'store_orders.order_id')
            ->join('umkm_stores', 'umkm_stores.id', '=', 'store_orders.umkm_store_id')
            ->select([
                'store_orders.id',
                'store_orders.total',
                'store_orders.commission_percentage',
                'store_orders.commission_amount',
                'store_orders.umkm_amount',
                'orders.payment_confirmed_at',
                'orders.order_number',
                'orders.payment_status as order_payment_status',
                'orders.order_status as order_status',
                'umkm_stores.name as umkm_store_name',
            ])
            ->orderBy('orders.payment_confirmed_at')
            ->orderBy('store_orders.id')
            ->cursor();

        $filename = 'laporan-komisi-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Tanggal Pembayaran',
                'Nomor Pesanan',
                'Nama Penjual',
                'Total Transaksi Toko',
                'Persentase Komisi Snapshot',
                'Komisi BUMDes',
                'Estimasi Hak Penjual',
                'Status Pembayaran',
                'Status Pesanan',
            ]);

            foreach ($rows as $row) {
                fputcsv($output, [
                    $this->safeCsvText($row->payment_confirmed_at ?? ''),
                    $this->safeCsvText($row->order_number),
                    $this->safeCsvText($row->umkm_store_name),
                    (string) $row->total,
                    (string) $row->commission_percentage,
                    (string) $row->commission_amount,
                    (string) $row->umkm_amount,
                    $this->safeCsvText($row->order_payment_status),
                    $this->safeCsvText($row->order_status),
                ]);
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{date_from?: string, date_to?: string, store_id?: int} */
    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'store_id' => ['nullable', 'integer', Rule::exists('umkm_stores', 'id')],
        ]);
    }

    /** @param array{date_from?: string, date_to?: string, store_id?: int} $filters */
    private function baseQuery(array $filters): Builder
    {
        return StoreOrder::query()
            ->where('commission_status', 'calculated')
            ->whereHas('order', fn ($q) => $q->where('payment_status', PaymentStatus::PAID))
            ->when($filters['store_id'] ?? null, fn ($q, $id) => $q->where('umkm_store_id', $id))
            // payment_confirmed_at is the report-period basis, not order creation time.
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereHas('order', fn ($o) => $o->whereDate('payment_confirmed_at', '>=', $date)))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereHas('order', fn ($o) => $o->whereDate('payment_confirmed_at', '<=', $date)));
    }

    /** Escape potentially executable spreadsheet text while leaving DECIMAL snapshots untouched. */
    private function safeCsvText(mixed $value): string
    {
        $text = (string) $value;
        $isFormula = preg_match('/^[=+\-@\t\r]/', $text) === 1;
        $text = str_replace(["\r", "\n"], ' ', $text);

        return $isFormula ? "'{$text}" : $text;
    }
}
