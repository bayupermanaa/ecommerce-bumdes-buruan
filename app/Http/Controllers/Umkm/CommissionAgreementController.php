<?php

namespace App\Http\Controllers\Umkm;

use App\Http\Controllers\Controller;
use App\Models\CommissionAgreement;
use App\Services\CommissionCalculationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CommissionAgreementController extends Controller
{
    /**
     * Show only the authenticated owner's own agreement history.
     */
    public function index(Request $request, CommissionCalculationService $commissionService): View
    {
        $store = $request->user()->umkmStore;

        if (! $store) {
            abort(404, 'Profil toko belum tersedia.');
        }

        $agreements = $store->commissionAgreements()
            ->with('creator')
            ->latest('effective_start_date')
            ->get();
        $currentAgreement = $commissionService->agreementForStore($store);

        if ($agreements->isNotEmpty()) {
            Gate::authorize('view', $agreements->first());
        }

        return view('umkm.commission-agreements.index', compact('store', 'agreements', 'currentAgreement'));
    }
}
