<?php

namespace App\Http\Controllers\Umkm;

use App\Http\Controllers\Controller;
use App\Services\UmkmDisbursementRequestService;
use App\Services\UmkmDisbursementFailureService;
use App\Models\UmkmDisbursement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DisbursementRequestController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', UmkmDisbursement::class);
        $store = $request->user()->umkmStore;
        $entitlements = $store->entitlements()->orderBy('id')->get(['id', 'status', 'source_umkm_amount']);

        return view('umkm.disbursements.index', [
            'store' => $store,
            'balances' => $this->balances($entitlements),
            'account' => $store->recipientBankAccounts()
                ->where('status', 'active')->orderBy('id')->first(),
            'activeDisbursement' => $store->disbursements()
                ->whereNotNull('active_request_lock')->latest('id')->first(),
            'disbursements' => $store->disbursements()
                ->withCount('allocations')->latest('id')->paginate(10),
        ]);
    }

    public function store(Request $request, UmkmDisbursementRequestService $service): RedirectResponse
    {
        Gate::authorize('request', UmkmDisbursement::class);
        $data = $request->validate(['idempotency_key' => ['required', 'uuid']]);
        $disbursement = $service->request($request->user()->umkmStore, $request->user(), $data['idempotency_key']);

        return redirect()->route('umkm.disbursements.index')
            ->with('status', 'Pengajuan pencairan telah dibuat dan menunggu pemeriksaan.')
            ->with('disbursement_id', $disbursement->id);
    }
    public function cancel(UmkmDisbursement $disbursement, Request $request, UmkmDisbursementFailureService $service): RedirectResponse
    { Gate::authorize('cancelRequested',$disbursement);$data=$request->validate(['reason'=>['required','string','max:2000']]);$service->cancelRequested($disbursement,$request->user(),$data['reason']);return back()->with('status','Pengajuan dibatalkan; saldo tersedia kembali.'); }

    /** @param \Illuminate\Support\Collection<int, \App\Models\UmkmEntitlement> $entitlements */
    private function balances($entitlements): array
    {
        $result = ['accrued' => 0, 'eligible' => 0, 'available' => 0, 'reserved' => 0, 'settled' => 0, 'held_count' => 0];
        foreach ($entitlements as $entitlement) {
            if ($entitlement->status === 'held') {
                $result['held_count']++;
                continue;
            }
            if (array_key_exists($entitlement->status, $result)) {
                $result[$entitlement->status] += $this->minorUnits($entitlement->source_umkm_amount);
            }
        }

        foreach (['accrued', 'eligible', 'available', 'reserved', 'settled'] as $key) {
            $result[$key] = sprintf('%d.%02d', intdiv($result[$key], 100), $result[$key] % 100);
        }

        return $result;
    }

    private function minorUnits(?string $amount): int
    {
        preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', (string) $amount, $parts);

        return isset($parts[1]) ? ((int) $parts[1] * 100) + (int) str_pad($parts[2] ?? '', 2, '0') : 0;
    }
}
