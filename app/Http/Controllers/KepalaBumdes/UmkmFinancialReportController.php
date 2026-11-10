<?php

namespace App\Http\Controllers\KepalaBumdes;

use App\Http\Controllers\Controller;
use App\Models\UmkmDisbursement;
use App\Models\UmkmEntitlement;
use App\Models\UmkmStore;
use App\Support\UmkmDisbursementStatus;
use App\Support\UmkmEntitlementStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UmkmFinancialReportController extends Controller
{
    /**
     * Prepare a read-only entitlement report for the future Kepala BUMDes view.
     *
     * The period is an accrued-at cohort: rows formed during the selected period
     * are grouped by their status at the time this report is opened.
     */
    public function entitlements(Request $request): View
    {
        $filters = $this->validatedEntitlementFilters($request);
        $base = $this->entitlementBaseQuery($filters);

        $summary = $this->normaliseAggregate((clone $base)->selectRaw(
            'COUNT(*) as entitlement_count, '
            .'SUM(source_umkm_amount) as known_amount_total, '
            .'SUM(CASE WHEN source_umkm_amount IS NULL THEN 1 ELSE 0 END) as unknown_amount_count'
        )->first());

        $statusRows = (clone $base)
            ->selectRaw(
                'status, COUNT(*) as entitlement_count, '
                .'SUM(source_umkm_amount) as known_amount_total, '
                .'SUM(CASE WHEN source_umkm_amount IS NULL THEN 1 ELSE 0 END) as unknown_amount_count'
            )
            ->groupBy('status')
            ->get()
            ->map(fn (object $row): object => $this->normaliseAggregate($row))
            ->keyBy('status');

        $statusSummary = collect(UmkmEntitlementStatus::values())
            ->mapWithKeys(function (string $status) use ($statusRows): array {
                return [$status => $statusRows->get($status, (object) [
                    'status' => $status,
                    'entitlement_count' => 0,
                    'known_amount_total' => '0',
                    'unknown_amount_count' => 0,
                ])];
            });

        $byStore = (clone $base)
            ->join('umkm_stores', 'umkm_stores.id', '=', 'umkm_entitlements.umkm_store_id')
            ->selectRaw(
                'umkm_stores.id as store_id, umkm_stores.name as store_name, umkm_entitlements.status, '
                .'COUNT(umkm_entitlements.id) as entitlement_count, '
                .'SUM(umkm_entitlements.source_umkm_amount) as known_amount_total, '
                .'SUM(CASE WHEN umkm_entitlements.source_umkm_amount IS NULL THEN 1 ELSE 0 END) as unknown_amount_count'
            )
            ->groupBy('umkm_stores.id', 'umkm_stores.name', 'umkm_entitlements.status')
            ->orderBy('umkm_stores.name')
            ->orderBy('umkm_entitlements.status')
            ->get()
            ->map(fn (object $row): object => $this->normaliseAggregate($row));

        $details = (clone $base)
            ->with([
                'umkmStore:id,name',
                'storeOrder:id,store_order_number',
                'order:id,order_number',
            ])
            ->latest('accrued_at')
            ->paginate(15)
            ->withQueryString();

        return view('kepala-bumdes.reports.umkm-financial.entitlements', [
            'summary' => $summary,
            'statusSummary' => $statusSummary,
            'byStore' => $byStore,
            'details' => $details,
            'filters' => $filters,
            'storeOptions' => UmkmStore::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Stream the same filtered entitlement records as the report, with no allocation joins. */
    public function exportEntitlements(Request $request): StreamedResponse
    {
        $filters = $this->validatedEntitlementFilters($request);
        $rows = $this->entitlementBaseQuery($filters)
            ->join('umkm_stores', 'umkm_stores.id', '=', 'umkm_entitlements.umkm_store_id')
            ->join('store_orders', 'store_orders.id', '=', 'umkm_entitlements.store_order_id')
            ->join('orders', 'orders.id', '=', 'umkm_entitlements.order_id')
            ->select([
                'umkm_entitlements.id',
                'umkm_entitlements.status',
                'umkm_entitlements.source_umkm_amount',
                'umkm_entitlements.accrued_at',
                'umkm_entitlements.eligible_at',
                'umkm_entitlements.reconciled_at',
                'umkm_entitlements.settled_at',
                'umkm_stores.name as umkm_store_name',
                'store_orders.store_order_number',
                'orders.order_number',
            ])
            ->orderBy('umkm_entitlements.accrued_at')
            ->orderBy('umkm_entitlements.id')
            ->cursor();

        return $this->streamCsv('laporan-hak-umkm', [
            'Tanggal Terbentuk', 'Nama Penjual', 'Referensi StoreOrder', 'Nomor Pesanan', 'Status Entitlement',
            'Nominal Hak Penjual', 'Tanggal Eligible', 'Tanggal Rekonsiliasi', 'Tanggal Settled',
        ], $rows, function (UmkmEntitlement $row): array {
            return [
                $this->safeCsvText($this->csvDate($row->accrued_at)),
                $this->safeCsvText($row->umkm_store_name),
                $this->safeCsvText($row->store_order_number),
                $this->safeCsvText($row->order_number),
                $this->safeCsvText($row->status),
                $row->source_umkm_amount === null ? 'Nominal belum diketahui' : (string) $row->source_umkm_amount,
                $this->safeCsvText($this->csvDate($row->eligible_at)),
                $this->safeCsvText($this->csvDate($row->reconciled_at)),
                $this->safeCsvText($this->csvDate($row->settled_at)),
            ];
        });
    }

    /**
     * Prepare a read-only disbursement report for the future Kepala BUMDes view.
     *
     * The period is a requested-at cohort: requests made during the selected
     * period are grouped by their status at the time this report is opened.
     */
    public function disbursements(Request $request): View
    {
        $filters = $this->validatedDisbursementFilters($request);
        $inProgressStatuses = [
            UmkmDisbursementStatus::REQUESTED,
            UmkmDisbursementStatus::UNDER_REVIEW,
            UmkmDisbursementStatus::APPROVED,
        ];

        $base = $this->disbursementBaseQuery($filters);

        $summary = $this->normaliseDisbursementAggregate((clone $base)->selectRaw(
            'COUNT(*) as disbursement_count, '
            .'SUM(requested_amount) as historical_requested_total, '
            .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as paid_count, '
            .'SUM(CASE WHEN status = ? THEN requested_amount ELSE 0 END) as paid_total, '
            .'SUM(CASE WHEN status IN (?, ?, ?) THEN 1 ELSE 0 END) as in_progress_count, '
            .'SUM(CASE WHEN status IN (?, ?, ?) THEN requested_amount ELSE 0 END) as in_progress_total',
            [
                UmkmDisbursementStatus::PAID,
                UmkmDisbursementStatus::PAID,
                ...$inProgressStatuses,
                ...$inProgressStatuses,
            ],
        )->first());

        $statusRows = (clone $base)
            ->selectRaw('status, COUNT(*) as disbursement_count, SUM(requested_amount) as requested_amount_total')
            ->groupBy('status')
            ->get()
            ->map(fn (object $row): object => $this->normaliseDisbursementAggregate($row))
            ->keyBy('status');

        $statusSummary = collect(UmkmDisbursementStatus::values())
            ->mapWithKeys(function (string $status) use ($statusRows): array {
                return [$status => $statusRows->get($status, (object) [
                    'status' => $status,
                    'disbursement_count' => 0,
                    'requested_amount_total' => '0',
                ])];
            });

        $byStore = (clone $base)
            ->join('umkm_stores', 'umkm_stores.id', '=', 'umkm_disbursements.umkm_store_id')
            ->selectRaw(
                'umkm_stores.id as store_id, umkm_stores.name as store_name, umkm_disbursements.status, '
                .'COUNT(umkm_disbursements.id) as disbursement_count, '
                .'SUM(umkm_disbursements.requested_amount) as requested_amount_total'
            )
            ->groupBy('umkm_stores.id', 'umkm_stores.name', 'umkm_disbursements.status')
            ->orderBy('umkm_stores.name')
            ->orderBy('umkm_disbursements.status')
            ->get()
            ->map(fn (object $row): object => $this->normaliseDisbursementAggregate($row));

        $details = (clone $base)
            ->select([
                'id',
                'umkm_store_id',
                'requested_amount',
                'status',
                'requested_at',
                'reviewed_at',
                'approved_at',
                'paid_at',
                'transfer_recorded_at',
                'transfer_verified_at',
                'transfer_failure_verified_at',
            ])
            ->with('umkmStore:id,name')
            ->latest('requested_at')
            ->paginate(15)
            ->withQueryString();

        return view('kepala-bumdes.reports.umkm-financial.disbursements', [
            'summary' => $summary,
            'statusSummary' => $statusSummary,
            'byStore' => $byStore,
            'details' => $details,
            'filters' => $filters,
            'storeOptions' => UmkmStore::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Stream the same filtered disbursement snapshots as the report, without bank or proof data. */
    public function exportDisbursements(Request $request): StreamedResponse
    {
        $filters = $this->validatedDisbursementFilters($request);
        $rows = $this->disbursementBaseQuery($filters)
            ->join('umkm_stores', 'umkm_stores.id', '=', 'umkm_disbursements.umkm_store_id')
            ->select([
                'umkm_disbursements.id',
                'umkm_disbursements.requested_amount',
                'umkm_disbursements.status',
                'umkm_disbursements.requested_at',
                'umkm_disbursements.reviewed_at',
                'umkm_disbursements.approved_at',
                'umkm_disbursements.transfer_recorded_at',
                'umkm_disbursements.transfer_verified_at',
                'umkm_disbursements.paid_at',
                'umkm_disbursements.transfer_failure_verified_at',
                'umkm_stores.name as umkm_store_name',
            ])
            ->orderByDesc('umkm_disbursements.requested_at')
            ->orderByDesc('umkm_disbursements.id')
            ->cursor();

        return $this->streamCsv('laporan-pencairan-umkm', [
            'ID Pengajuan', 'Nama Penjual', 'Tanggal Pengajuan', 'Nominal Pengajuan', 'Status Pencairan',
            'Tanggal Review', 'Tanggal Persetujuan', 'Tanggal Transfer Dicatat', 'Tanggal Transfer Diverifikasi',
            'Tanggal Pencairan Berhasil', 'Tanggal Kegagalan Dikonfirmasi',
        ], $rows, function (UmkmDisbursement $row): array {
            return [
                (string) $row->id,
                $this->safeCsvText($row->umkm_store_name),
                $this->safeCsvText($this->csvDate($row->requested_at)),
                (string) $row->requested_amount,
                $this->safeCsvText($row->status),
                $this->safeCsvText($this->csvDate($row->reviewed_at)),
                $this->safeCsvText($this->csvDate($row->approved_at)),
                $this->safeCsvText($this->csvDate($row->transfer_recorded_at)),
                $this->safeCsvText($this->csvDate($row->transfer_verified_at)),
                $this->safeCsvText($this->csvDate($row->paid_at)),
                $this->safeCsvText($this->csvDate($row->transfer_failure_verified_at)),
            ];
        });
    }

    /** @return array{date_from: string, date_to: string, store_id: int|string, entitlement_status: string} */
    private function validatedEntitlementFilters(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'store_id' => ['nullable', 'integer', Rule::exists('umkm_stores', 'id')],
            'entitlement_status' => ['nullable', Rule::in(UmkmEntitlementStatus::values())],
        ]);

        return [
            'date_from' => $validated['date_from'] ?? '',
            'date_to' => $validated['date_to'] ?? '',
            'store_id' => $validated['store_id'] ?? '',
            'entitlement_status' => $validated['entitlement_status'] ?? '',
        ];
    }

    /** @return array{date_from: string, date_to: string, store_id: int|string, disbursement_status: string} */
    private function validatedDisbursementFilters(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'store_id' => ['nullable', 'integer', Rule::exists('umkm_stores', 'id')],
            'disbursement_status' => ['nullable', Rule::in(UmkmDisbursementStatus::values())],
        ]);

        return [
            'date_from' => $validated['date_from'] ?? '',
            'date_to' => $validated['date_to'] ?? '',
            'store_id' => $validated['store_id'] ?? '',
            'disbursement_status' => $validated['disbursement_status'] ?? '',
        ];
    }

    /** @param array{date_from: string, date_to: string, store_id: int|string, entitlement_status: string} $filters */
    private function entitlementBaseQuery(array $filters): Builder
    {
        return UmkmEntitlement::query()
            ->when($filters['store_id'] !== '', fn ($query) => $query->where('umkm_entitlements.umkm_store_id', $filters['store_id']))
            ->when($filters['entitlement_status'] !== '', fn ($query) => $query->where('umkm_entitlements.status', $filters['entitlement_status']))
            ->when($filters['date_from'] !== '', fn ($query) => $query->whereDate('umkm_entitlements.accrued_at', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn ($query) => $query->whereDate('umkm_entitlements.accrued_at', '<=', $filters['date_to']));
    }

    /** @param array{date_from: string, date_to: string, store_id: int|string, disbursement_status: string} $filters */
    private function disbursementBaseQuery(array $filters): Builder
    {
        return UmkmDisbursement::query()
            ->when($filters['store_id'] !== '', fn ($query) => $query->where('umkm_disbursements.umkm_store_id', $filters['store_id']))
            ->when($filters['disbursement_status'] !== '', fn ($query) => $query->where('umkm_disbursements.status', $filters['disbursement_status']))
            ->when($filters['date_from'] !== '', fn ($query) => $query->whereDate('umkm_disbursements.requested_at', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn ($query) => $query->whereDate('umkm_disbursements.requested_at', '<=', $filters['date_to']));
    }

    /** @param iterable<object> $rows @param list<string> $headers */
    private function streamCsv(string $prefix, array $headers, iterable $rows, callable $rowMapper): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows, $rowMapper): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $headers);

            foreach ($rows as $row) {
                fputcsv($output, $rowMapper($row));
            }

            fclose($output);
        }, $prefix.'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function csvDate(mixed $date): string
    {
        return $date instanceof \DateTimeInterface ? $date->format('Y-m-d H:i:s') : (string) ($date ?? '');
    }

    /** Escape potentially executable spreadsheet text while leaving DECIMAL snapshots untouched. */
    private function safeCsvText(mixed $value): string
    {
        $text = (string) $value;
        $isFormula = preg_match('/^[=+\-@\t\r]/', $text) === 1;
        $text = str_replace(["\r", "\n"], ' ', $text);

        return $isFormula ? "'{$text}" : $text;
    }

    /**
     * Preserve nullable source amounts in SQL while giving a stable presentation
     * value only when the aggregate has no known amounts.
     */
    private function normaliseAggregate(object $aggregate): object
    {
        $aggregate->entitlement_count = (int) ($aggregate->entitlement_count ?? 0);
        $aggregate->known_amount_total = $aggregate->known_amount_total ?? '0';
        $aggregate->unknown_amount_count = (int) ($aggregate->unknown_amount_count ?? 0);

        return $aggregate;
    }

    /** Normalise nullable SQL sums without changing the persisted monetary values. */
    private function normaliseDisbursementAggregate(object $aggregate): object
    {
        $aggregate->disbursement_count = (int) ($aggregate->disbursement_count ?? 0);
        $attributes = method_exists($aggregate, 'getAttributes')
            ? $aggregate->getAttributes()
            : get_object_vars($aggregate);

        foreach (['historical_requested_total', 'paid_total', 'in_progress_total', 'requested_amount_total'] as $field) {
            if (array_key_exists($field, $attributes)) {
                if ($aggregate->{$field} === null || $aggregate->{$field} === '') {
                    $aggregate->{$field} = '0';
                }
            }
        }

        foreach (['paid_count', 'in_progress_count'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $aggregate->{$field} = (int) ($aggregate->{$field} ?? 0);
            }
        }

        return $aggregate;
    }
}
