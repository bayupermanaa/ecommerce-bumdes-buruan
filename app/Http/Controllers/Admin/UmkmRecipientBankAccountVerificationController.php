<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\UmkmRecipientBankAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
class UmkmRecipientBankAccountVerificationController extends Controller
{
    public function index(): View { Gate::authorize('viewAny', UmkmRecipientBankAccount::class); return view('admin.recipient-bank-accounts.index', ['accounts' => UmkmRecipientBankAccount::query()->with('umkmStore.user','verifiedBy')->latest()->paginate(15)]); }
    public function verify(Request $request, UmkmRecipientBankAccount $account): RedirectResponse { Gate::authorize('verify', $account); DB::transaction(function () use ($account, $request): void { UmkmRecipientBankAccount::query()->lockForUpdate()->findOrFail($account->id)->update(['verified_at' => now(), 'verified_by' => $request->user()->id]); }); return back()->with('status', 'Rekening telah ditandai terverifikasi.'); }
}
