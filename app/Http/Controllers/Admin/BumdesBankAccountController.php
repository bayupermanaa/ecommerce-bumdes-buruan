<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBumdesBankAccountRequest;
use App\Http\Requests\Admin\UpdateBumdesBankAccountRequest;
use App\Models\BumdesBankAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BumdesBankAccountController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', BumdesBankAccount::class);

        $accounts = BumdesBankAccount::query()
            ->with('creator')
            ->withCount('payments')
            ->orderByDesc('status')
            ->orderBy('bank_name')
            ->orderBy('account_holder_name')
            ->get();

        return view('admin.bumdes-bank-accounts.index', compact('accounts'));
    }

    public function create(): View
    {
        Gate::authorize('create', BumdesBankAccount::class);

        return view('admin.bumdes-bank-accounts.create');
    }

    public function store(StoreBumdesBankAccountRequest $request): RedirectResponse
    {
        Gate::authorize('create', BumdesBankAccount::class);
        $validated = $request->validated();

        $account = DB::transaction(function () use ($validated, $request): BumdesBankAccount {
            $this->ensureNoDuplicate($validated);

            return BumdesBankAccount::create([
                ...$validated,
                'created_by' => $request->user()->id,
            ]);
        });

        return redirect()
            ->route('admin.bumdes-bank-accounts.index')
            ->with('status', "Rekening {$account->bank_name} berhasil ditambahkan.");
    }

    public function edit(BumdesBankAccount $account): View
    {
        Gate::authorize('update', $account);

        return view('admin.bumdes-bank-accounts.edit', [
            'account' => $account,
            'hasPaymentHistory' => $account->payments()->exists(),
        ]);
    }

    public function update(UpdateBumdesBankAccountRequest $request, BumdesBankAccount $account): RedirectResponse
    {
        Gate::authorize('update', $account);
        $validated = $request->validated();

        DB::transaction(function () use ($account, $validated): void {
            $lockedAccount = BumdesBankAccount::query()
                ->whereKey($account->id)
                ->lockForUpdate()
                ->firstOrFail();
            $identityChanged = $this->identityChanged($lockedAccount, $validated);

            if ($identityChanged && $lockedAccount->payments()->exists()) {
                throw ValidationException::withMessages([
                    'account_number' => 'Rekening yang telah digunakan pembayaran tidak dapat diubah. Nonaktifkan rekening ini dan buat rekening baru.',
                ]);
            }

            if ($identityChanged) {
                $this->ensureNoDuplicate($validated, $lockedAccount->id);
            }

            $lockedAccount->fill($validated)->save();
        });

        return redirect()
            ->route('admin.bumdes-bank-accounts.index')
            ->with('status', 'Informasi rekening berhasil diperbarui.');
    }

    public function activate(BumdesBankAccount $account): RedirectResponse
    {
        Gate::authorize('activate', $account);

        return $this->setStatus($account, 'active', 'Rekening telah diaktifkan.', 'Rekening sudah aktif.');
    }

    public function deactivate(BumdesBankAccount $account): RedirectResponse
    {
        Gate::authorize('deactivate', $account);

        return $this->setStatus($account, 'inactive', 'Rekening telah dinonaktifkan.', 'Rekening sudah nonaktif.');
    }

    /**
     * Keep the existing payment references untouched while changing availability for new transfers.
     */
    private function setStatus(BumdesBankAccount $account, string $status, string $changedMessage, string $unchangedMessage): RedirectResponse
    {
        $changed = DB::transaction(function () use ($account, $status): bool {
            $lockedAccount = BumdesBankAccount::query()
                ->whereKey($account->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedAccount->status === $status) {
                return false;
            }

            $lockedAccount->forceFill(['status' => $status])->save();

            return true;
        });

        return redirect()
            ->route('admin.bumdes-bank-accounts.index')
            ->with('status', $changed ? $changedMessage : $unchangedMessage);
    }

    /**
     * @param array{bank_name: string, account_holder_name: string, account_number: string, notes?: string|null, status?: string} $attributes
     */
    private function ensureNoDuplicate(array $attributes, ?int $ignoreId = null): void
    {
        $query = BumdesBankAccount::query()
            ->where('bank_name', $attributes['bank_name'])
            ->where('account_holder_name', $attributes['account_holder_name'])
            ->where('account_number', $attributes['account_number']);

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        if ($query->lockForUpdate()->exists()) {
            throw ValidationException::withMessages([
                'account_number' => 'Rekening dengan bank, pemilik, dan nomor yang sama sudah terdaftar.',
            ]);
        }
    }

    /**
     * @param array{bank_name: string, account_holder_name: string, account_number: string, notes?: string|null} $attributes
     */
    private function identityChanged(BumdesBankAccount $account, array $attributes): bool
    {
        return $account->bank_name !== $attributes['bank_name']
            || $account->account_holder_name !== $attributes['account_holder_name']
            || $account->account_number !== $attributes['account_number'];
    }
}
