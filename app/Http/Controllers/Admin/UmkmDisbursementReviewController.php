<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UmkmDisbursement;
use App\Services\UmkmDisbursementApprovalService;
use App\Services\UmkmDisbursementTransferService;
use App\Services\UmkmDisbursementFailureService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class UmkmDisbursementReviewController extends Controller
{
    public function index(): View
    {
        Gate::authorize('review', new UmkmDisbursement());
        return view('admin.disbursements.index', ['disbursements' => UmkmDisbursement::with(['umkmStore', 'events.actor', 'allocations.entitlement', 'transferFailureRecordedBy'])->latest('id')->paginate(15)]);
    }
    public function start(UmkmDisbursement $disbursement, Request $request, UmkmDisbursementApprovalService $service): RedirectResponse
    {
        Gate::authorize('review', $disbursement); $service->startReview($disbursement, $request->user());
        return back()->with('status', 'Pemeriksaan pengajuan dimulai.');
    }
    public function complete(UmkmDisbursement $disbursement, Request $request, UmkmDisbursementApprovalService $service): RedirectResponse
    {
        Gate::authorize('review', $disbursement); $service->completeReview($disbursement, $request->user());
        return back()->with('status', 'Pemeriksaan Admin selesai dan siap menunggu persetujuan Kepala BUMDes.');
    }
    public function recordTransfer(UmkmDisbursement $disbursement, Request $request, UmkmDisbursementTransferService $service): RedirectResponse
    { Gate::authorize('transfer', $disbursement); $data=$request->validate(['transfer_reference'=>['required','string','max:255'],'transfer_recorded_at'=>['required','date','before_or_equal:now'],'transfer_amount'=>['required','decimal:0,2'],'proof'=>['required','file','mimes:jpg,jpeg,png,pdf','max:2048']]); $service->record($disbursement,$request->user(),$data['transfer_reference'],$data['transfer_recorded_at'],$data['transfer_amount'],$request->file('proof')); return back()->with('status','Transfer dicatat dan menunggu verifikasi Admin lain.'); }
    public function verifyTransfer(UmkmDisbursement $disbursement, Request $request, UmkmDisbursementTransferService $service): RedirectResponse
    { Gate::authorize('transfer',$disbursement);$service->verify($disbursement,$request->user());return back()->with('status','Transfer diverifikasi dan pencairan dinyatakan paid.'); }
    public function proof(UmkmDisbursement $disbursement)
    { Gate::authorize('transfer',$disbursement); abort_unless($disbursement->transfer_proof_path && Storage::disk('local')->exists($disbursement->transfer_proof_path),404); return Storage::disk('local')->response($disbursement->transfer_proof_path); }
    public function recordFailure(UmkmDisbursement $disbursement, Request $request, UmkmDisbursementFailureService $service): RedirectResponse
    { Gate::authorize('recordTransferFailure',$disbursement);$data=$request->validate(['reason'=>['required','string','max:2000'],'transfer_reference'=>['required','string','max:255'],'proof'=>['required','file','mimes:jpg,jpeg,png,pdf','max:2048']]);$path=$request->file('proof')->store('umkm-disbursement-failure-proofs','local');try{$service->recordTransferFailureEvidence($disbursement,$request->user(),$data['reason'],$data['transfer_reference'],$path);}catch(\Throwable $e){Storage::disk('local')->delete($path);throw $e;}return back()->with('status','Bukti kegagalan dicatat dan menunggu konfirmasi Admin lain.'); }
    public function confirmFailure(UmkmDisbursement $disbursement, Request $request, UmkmDisbursementFailureService $service): RedirectResponse
    { Gate::authorize('confirmTransferFailure',$disbursement);$data=$request->validate(['confirmation'=>['required','accepted']]);$service->confirmTransferFailure($disbursement,$request->user(),'Bank menyatakan transfer gagal secara final.');return back()->with('status','Kegagalan transfer dikonfirmasi dan reservation dilepas.'); }
    public function failureProof(UmkmDisbursement $disbursement)
    { Gate::authorize('viewTransferFailureProof',$disbursement);abort_unless($disbursement->transfer_failure_proof_path&&Storage::disk('local')->exists($disbursement->transfer_failure_proof_path),404);return Storage::disk('local')->response($disbursement->transfer_failure_proof_path); }
}
