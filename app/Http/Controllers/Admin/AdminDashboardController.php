<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UmkmEntitlement;
use App\Support\UmkmEntitlementStatus;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function index(): View
    {
        $sum = fn (string $status) => UmkmEntitlement::query()->where('status', $status)->sum('source_umkm_amount');

        return view('dashboards.admin', ['entitlementSummary' => [
            'accrued' => $sum(UmkmEntitlementStatus::ACCRUED),
            'eligible' => $sum(UmkmEntitlementStatus::ELIGIBLE),
            'available' => $sum(UmkmEntitlementStatus::AVAILABLE),
            'held_count' => UmkmEntitlement::query()->where('status', UmkmEntitlementStatus::HELD)->count(),
            'held_known_amount' => $sum(UmkmEntitlementStatus::HELD),
            'held_known_amount' => $sum(UmkmEntitlementStatus::HELD),
            'eligible_count' => UmkmEntitlement::query()->where('status', UmkmEntitlementStatus::ELIGIBLE)->count(),
        ]]);
    }
}
