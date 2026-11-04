<?php
namespace App\Http\Controllers\Umkm;
use App\Http\Controllers\Controller;
use App\Models\UmkmRecipientBankAccount;
use App\Models\UmkmStore;
use App\Support\UmkmRecipientBankAccountStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RecipientBankAccountController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('create', UmkmRecipientBankAccount::class);
        $store = $request->user()->umkmStore;
        return view('umkm.recipient-bank-accounts.index', ['store' => $store, 'accounts' => $store->recipientBankAccounts()->latest()->get()]);
    }
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', UmkmRecipientBankAccount::class); $data = $this->data($request);
        DB::transaction(function () use ($request, $data): void {
            $store = UmkmStore::query()->whereKey($request->user()->umkmStore->id)->lockForUpdate()->firstOrFail();
            UmkmRecipientBankAccount::query()->where('umkm_store_id', $store->id)->where('status', 'active')->lockForUpdate()->update(['status' => 'inactive', 'active_store_lock' => null, 'updated_by' => $request->user()->id]);
            $store->recipientBankAccounts()->create([...$data, 'status' => 'active', 'active_store_lock' => 'store:'.$store->id, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        });
        return redirect()->route('umkm.recipient-bank-accounts.index')->with('status', 'Rekening penerima disimpan.');
    }
    public function update(Request $request, UmkmRecipientBankAccount $account): RedirectResponse
    {
        Gate::authorize('manage', $account); $data = $this->data($request);
        DB::transaction(function () use ($account, $data, $request): void {
            $locked = UmkmRecipientBankAccount::query()->lockForUpdate()->findOrFail($account->id);
            $changed = $locked->bank_name !== $data['bank_name'] || $locked->account_holder_name !== $data['account_holder_name'] || $locked->account_number !== $data['account_number'];
            $locked->update([...$data, 'verified_at' => $changed ? null : $locked->verified_at, 'verified_by' => $changed ? null : $locked->verified_by, 'updated_by' => $request->user()->id]);
        });
        return back()->with('status', 'Rekening diperbarui. Verifikasi dibatalkan bila data rekening berubah.');
    }
    public function activate(Request $request, UmkmRecipientBankAccount $account): RedirectResponse { Gate::authorize('manage', $account); $this->setActive($account, $request->user()->id, true); return back()->with('status', 'Rekening diaktifkan.'); }
    public function deactivate(Request $request, UmkmRecipientBankAccount $account): RedirectResponse { Gate::authorize('manage', $account); $this->setActive($account, $request->user()->id, false); return back()->with('status', 'Rekening dinonaktifkan.'); }
    private function setActive(UmkmRecipientBankAccount $account, int $userId, bool $active): void
    {
        DB::transaction(function () use ($account, $userId, $active): void {
            // Every active-account mutation locks its store before its accounts.
            // This serializes the one-active-account invariant per store.
            $store = UmkmStore::query()->whereKey($account->umkm_store_id)->lockForUpdate()->firstOrFail();
            $locked = UmkmRecipientBankAccount::query()->lockForUpdate()->findOrFail($account->id);
            if ($active) UmkmRecipientBankAccount::query()->where('umkm_store_id', $store->id)->where('status', 'active')->whereKeyNot($locked->id)->lockForUpdate()->update(['status' => 'inactive', 'active_store_lock' => null, 'updated_by' => $userId]);
            $locked->update(['status' => $active ? 'active' : 'inactive', 'active_store_lock' => $active ? 'store:'.$store->id : null, 'updated_by' => $userId]);
        });
    }
    private function data(Request $request): array
    {
        $request->merge(['account_number' => preg_replace('/[\s-]+/', '', (string) $request->input('account_number'))]);
        return $request->validate(['bank_name' => ['required','string','max:255'], 'account_holder_name' => ['required','string','max:255'], 'account_number' => ['required','regex:/^\d{5,34}$/']]);
    }
}
