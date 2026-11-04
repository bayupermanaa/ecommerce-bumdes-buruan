<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UmkmStore;
use App\Services\FinancialAudit\FinancialExceptionDetector;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinancialAuditController extends Controller
{
    /** @var list<string> */
    private const CATEGORIES = [
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
    ];

    private const LIMITS = [25, 50, 100];

    public function index(Request $request, FinancialExceptionDetector $detector): View
    {
        $validated = $this->validatedFilters($request);
        ['displayed' => $displayed, 'hasMore' => $hasMore, 'limit' => $limit] = $this->displayedExceptions($validated, $detector);
        $summary = [
            'displayed_count' => $displayed->count(),
            'critical_count' => $displayed->where('severity', 'critical')->count(),
            'medium_count' => $displayed->where('severity', 'medium')->count(),
        ];

        return view('admin.financial-audit.index', [
            'exceptions' => $displayed,
            'hasMore' => $hasMore,
            'limit' => $limit,
            'summary' => $summary,
            'categories' => self::CATEGORIES,
            'storeOptions' => UmkmStore::query()->orderBy('name')->get(['id', 'name']),
            'filters' => [
                'category' => $validated['category'] ?? '',
                'severity' => $validated['severity'] ?? '',
                'umkm_store_id' => $validated['umkm_store_id'] ?? '',
                'limit' => $limit,
            ],
        ]);
    }

    /** Export the same bounded, filtered detector output as the audit page. */
    public function export(Request $request, FinancialExceptionDetector $detector): StreamedResponse
    {
        $validated = $this->validatedFilters($request);
        ['displayed' => $displayed, 'hasMore' => $hasMore, 'limit' => $limit] = $this->displayedExceptions($validated, $detector);

        return response()->streamDownload(function () use ($displayed): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Kode Exception', 'Prioritas', 'Reason', 'Tipe Referensi', 'ID Referensi', 'ID UMKM',
                'Status Aktual', 'Keterangan', 'Stable Exception Key', 'Waktu Sumber',
            ]);

            foreach ($displayed as $exception) {
                fputcsv($output, [
                    $this->safeCsvText($exception['code']),
                    $this->safeCsvText($exception['severity']),
                    $this->safeCsvText($exception['context']['reason'] ?? ''),
                    $this->safeCsvText($exception['reference_type']),
                    (string) $exception['reference_id'],
                    (string) $exception['umkm_store_id'],
                    $this->safeCsvText($exception['current_status']),
                    $this->safeCsvText($exception['description']),
                    $this->safeCsvText($exception['stable_exception_key']),
                    $this->safeCsvText($exception['source_timestamp'] ?? ''),
                ]);
            }

            fclose($output);
        }, 'audit-keuangan-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Audit-Result-Limit' => (string) $limit,
            'X-Audit-Results-Truncated' => $hasMore ? 'true' : 'false',
            'X-Audit-Result-Scope' => 'bounded-not-global-total',
        ]);
    }

    /** @return array{category?: string, severity?: string, umkm_store_id?: int, limit?: int} */
    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'category' => ['nullable', Rule::in(self::CATEGORIES)],
            'severity' => ['nullable', Rule::in(['critical', 'medium'])],
            'umkm_store_id' => ['nullable', 'integer', 'exists:umkm_stores,id'],
            'limit' => ['nullable', 'integer', Rule::in(self::LIMITS)],
        ]);
    }

    /**
     * @param array{category?: string, severity?: string, umkm_store_id?: int, limit?: int} $validated
     * @return array{displayed: \Illuminate\Support\Collection<int, array<string, mixed>>, hasMore: bool, limit: int}
     */
    private function displayedExceptions(array $validated, FinancialExceptionDetector $detector): array
    {
        $limit = (int) ($validated['limit'] ?? 50);
        $scanFilters = array_filter([
            'category' => $validated['category'] ?? null,
            'umkm_store_id' => $validated['umkm_store_id'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== '');

        $exceptions = $detector->scan($scanFilters)
            ->when($validated['severity'] ?? null, fn ($scan, $severity) => $scan->filter(fn (array $exception): bool => $exception['severity'] === $severity))
            ->take($limit + 1)
            ->collect()
            ->values();

        return [
            'displayed' => $exceptions->take($limit)->values(),
            'hasMore' => $exceptions->count() > $limit,
            'limit' => $limit,
        ];
    }

    /** Escape potentially executable spreadsheet text; raw detector context is never exported. */
    private function safeCsvText(mixed $value): string
    {
        $text = (string) $value;
        $isFormula = preg_match('/^[=+\-@\t\r]/', $text) === 1;
        $text = str_replace(["\r", "\n"], ' ', $text);

        return $isFormula ? "'{$text}" : $text;
    }
}
