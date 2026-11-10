<?php

namespace App\Http\Controllers;

use App\Models\UmkmStore;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class SellerApplicationController extends Controller
{
    /**
     * Show the authenticated customer's current seller application state.
     */
    public function show(Request $request): View
    {
        return view('seller-applications.show', [
            'store' => $request->user()->umkmStore()->first(),
        ]);
    }

    /**
     * Create one pending store application without promoting the customer yet.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('apply', UmkmStore::class);

        $data = $this->validatedApplication($request);
        $logo = $data['logo'] ?? null;
        unset($data['logo']);
        $logoPath = $logo?->store('store-logos', 'public');

        try {
            DB::transaction(function () use ($request, $data, $logoPath): void {
                $user = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();

                if ($user->role !== 'pelanggan') {
                    throw new AuthorizationException('Hanya akun pelanggan yang dapat mengajukan pembukaan toko.');
                }

                if (UmkmStore::query()->where('user_id', $user->id)->lockForUpdate()->exists()) {
                    throw new AuthorizationException('Setiap akun hanya dapat memiliki satu toko.');
                }

                $user->umkmStore()->create([
                    ...$data,
                    'slug' => $this->uniqueSlug($data['name']),
                    'logo' => $logoPath,
                ]);
            });
        } catch (UniqueConstraintViolationException $exception) {
            $this->deleteLogo($logoPath);

            throw new AuthorizationException('Setiap akun hanya dapat memiliki satu toko.', previous: $exception);
        } catch (Throwable $exception) {
            $this->deleteLogo($logoPath);

            throw $exception;
        }

        return redirect()
            ->route('seller-application.show')
            ->with('status', 'Pengajuan toko berhasil dikirim dan menunggu verifikasi Admin BUMDes.');
    }

    /**
     * Let a rejected applicant correct the same store and submit it again.
     */
    public function resubmit(Request $request, UmkmStore $store): RedirectResponse
    {
        Gate::authorize('resubmit', $store);

        $data = $this->validatedApplication($request);
        $logo = $data['logo'] ?? null;
        unset($data['logo']);
        $newLogoPath = $logo?->store('store-logos', 'public');
        $oldLogoPath = $store->logo;

        try {
            DB::transaction(function () use ($request, $store, $data, $newLogoPath): void {
                $user = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $lockedStore = UmkmStore::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();

                if ($user->role !== 'pelanggan'
                    || $lockedStore->user_id !== $user->id
                    || $lockedStore->status !== 'rejected') {
                    throw new AuthorizationException('Pengajuan toko ini tidak dapat diajukan ulang.');
                }

                $lockedStore->forceFill([
                    ...$data,
                    'slug' => $this->uniqueSlug($data['name'], $lockedStore->id),
                    ...($newLogoPath !== null ? ['logo' => $newLogoPath] : []),
                    'status' => 'pending',
                    'rejection_reason' => null,
                    'verified_at' => null,
                ])->save();
            });
        } catch (Throwable $exception) {
            $this->deleteLogo($newLogoPath);

            throw $exception;
        }

        if ($newLogoPath !== null && is_string($oldLogoPath) && $oldLogoPath !== $newLogoPath) {
            Storage::disk('public')->delete($oldLogoPath);
        }

        return redirect()
            ->route('seller-application.show')
            ->with('status', 'Perbaikan pengajuan toko berhasil dikirim dan menunggu verifikasi ulang.');
    }

    /** @return array{name: string, description: string, address: string, phone: string, logo?: mixed} */
    private function validatedApplication(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'address' => ['required', 'string', 'max:2000'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+()\.\-\s]{8,30}$/'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);
    }

    private function uniqueSlug(string $name, ?int $ignoreStoreId = null): string
    {
        $base = Str::slug($name) ?: 'toko';
        $candidate = $base;
        $suffix = 1;

        while (UmkmStore::query()
            ->where('slug', $candidate)
            ->when($ignoreStoreId !== null, fn ($query) => $query->whereKeyNot($ignoreStoreId))
            ->exists()) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }

    private function deleteLogo(?string $path): void
    {
        if ($path !== null) {
            Storage::disk('public')->delete($path);
        }
    }
}
