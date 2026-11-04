<?php

namespace App\Http\Controllers;

use App\Models\UmkmStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UmkmStoreController extends Controller
{
    /**
     * Display the authenticated owner's store.
     */
    public function index(Request $request): View
    {
        return view('umkm-stores.index', [
            'store' => $request->user()->umkmStore,
        ]);
    }

    /**
     * Display the form for the owner's one store.
     */
    public function create(): View
    {
        Gate::authorize('create', UmkmStore::class);

        return view('umkm-stores.create');
    }

    /**
     * Store a new UMKM profile for the authenticated owner.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', UmkmStore::class);

        $request->user()->umkmStore()->create($this->validatedStoreData($request));

        return redirect()
            ->route('umkm.stores.index')
            ->with('status', 'Toko berhasil dibuat dan menunggu verifikasi.');
    }

    /**
     * Display the edit form for the owner's store.
     */
    public function edit(UmkmStore $store): View
    {
        Gate::authorize('update', $store);

        return view('umkm-stores.edit', compact('store'));
    }

    /**
     * Update the authenticated owner's store profile.
     */
    public function update(Request $request, UmkmStore $store): RedirectResponse
    {
        Gate::authorize('update', $store);

        $store->update($this->validatedStoreData($request, $store));

        return redirect()
            ->route('umkm.stores.index')
            ->with('status', 'Profil toko berhasil diperbarui.');
    }

    /**
     * Validate only owner-editable profile fields.
     *
     * @return array<string, mixed>
     */
    private function validatedStoreData(Request $request, ?UmkmStore $store = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('umkm_stores', 'slug')->ignore($store),
            ],
            'description' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);
    }
}
