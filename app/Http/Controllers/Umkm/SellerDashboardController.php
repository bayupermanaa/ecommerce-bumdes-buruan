<?php

namespace App\Http\Controllers\Umkm;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class SellerDashboardController extends Controller
{
    public function index(Request $request): View
    {
        $store = $request->user()->umkmStore;

        return view('dashboards.umkm', [
            'store' => $store,
            'productCounts' => $store ? $store->products()
                ->selectRaw('status, COUNT(*) AS total')
                ->groupBy('status')
                ->pluck('total', 'status') : collect(),
            'orderCount' => $store ? $store->storeOrders()->count() : 0,
        ]);
    }
}
