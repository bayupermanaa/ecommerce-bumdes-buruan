<?php

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{Payment, UmkmDisbursement, UmkmDisbursementAllocation, UmkmDisbursementEvent, UmkmEntitlement, UmkmEntitlementEvent};

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$disbursementStatuses = UmkmDisbursement::query()->orderBy('id')->pluck('status')->all();
$entitlementStatuses = UmkmEntitlement::query()->orderBy('id')->pluck('status')->all();
$eventCounts = UmkmDisbursementEvent::query()->selectRaw('event_type, count(*) as total')->groupBy('event_type')->pluck('total', 'event_type')->all();
$releaseCount = UmkmEntitlementEvent::query()->where('event_type', 'reservation_released')->count();

fwrite(STDOUT, json_encode([
    'payments' => Payment::count(),
    'entitlements' => UmkmEntitlement::count(),
    'disbursements' => UmkmDisbursement::count(),
    'allocations' => UmkmDisbursementAllocation::count(),
    'disbursement_statuses' => $disbursementStatuses,
    'entitlement_statuses' => $entitlementStatuses,
    'event_counts' => $eventCounts,
    'release_count' => $releaseCount,
], JSON_PRETTY_PRINT)."\n");

$assert(Payment::count() === 2, 'expected two isolated synthetic payments');
$assert(UmkmEntitlement::count() === 2, 'expected two isolated synthetic entitlements');
$assert(UmkmDisbursement::count() === 2, 'expected two disbursement requests');
$assert(UmkmDisbursementAllocation::count() === 4, 'historical allocations must remain without duplication');
$assert($disbursementStatuses === ['cancelled', 'transfer_failed'], 'final disbursement statuses are incorrect');
$assert($entitlementStatuses === ['available', 'available'], 'reservation release must happen exactly once per request');
$assert(($eventCounts['cancelled'] ?? 0) === 1, 'cancelled event count mismatch');
$assert(($eventCounts['transfer_failed'] ?? 0) === 1, 'transfer_failed event count mismatch');
$assert(($eventCounts['failure_evidence_recorded'] ?? 0) === 1, 'failure evidence event count mismatch');
$assert($releaseCount === 4, 'reservation_released event count mismatch');
$assert(UmkmDisbursement::query()->where('status', 'paid')->count() === 0, 'negative flow must not produce paid');
$assert(UmkmEntitlement::query()->where('source_umkm_amount', '90.00')->count() === 2, 'snapshot nominal changed');
$assert(UmkmDisbursementAllocation::query()->where('amount', '90.00')->count() === 4, 'allocation nominal changed');

fwrite(STDOUT, "Negative financial SQLite state assertions passed.\n");
