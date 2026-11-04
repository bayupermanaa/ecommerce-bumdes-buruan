<?php

namespace App\Http\Controllers;

use App\Models\UmkmDisbursement;
use App\Services\UmkmDisbursementApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class KepalaBumdesDisbursementApprovalController extends Controller
{
    public function index(): View
    {
        Gate::authorize('decide', new UmkmDisbursement());
        $with = ['umkmStore', 'events.actor', 'allocations.entitlement', 'transferFailureRecordedBy', 'transferFailureVerifiedBy'];
        return view('kepala-bumdes.disbursements.index', [
            'disbursements' => UmkmDisbursement::with($with)->where('status', 'under_review')->latest('id')->paginate(15),
            'terminalDisbursements' => UmkmDisbursement::with($with)->whereIn('status', ['cancelled', 'transfer_failed'])->latest('id')->limit(20)->get(),
        ]);
    }
    public function approve(UmkmDisbursement $disbursement, Request $request, UmkmDisbursementApprovalService $service): RedirectResponse
    {
        Gate::authorize('decide', $disbursement); $service->approve($disbursement, $request->user());
        return back()->with('status', 'Pengajuan disetujui. Belum ada transfer dana pada tahap ini.');
    }
    public function reject(UmkmDisbursement $disbursement, Request $request, UmkmDisbursementApprovalService $service): RedirectResponse
    {
        Gate::authorize('decide', $disbursement); $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $service->reject($disbursement, $request->user(), $data['reason']);
        return back()->with('status', 'Pengajuan ditolak dan saldo tersedia kembali.');
    }
}
