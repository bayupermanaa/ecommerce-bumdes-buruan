<?php

namespace App\Http\Controllers\Umkm;

use App\Http\Controllers\Controller;
use App\Models\StoreOrder;
use App\Services\StoreOrderFulfillmentService;
use App\Support\StoreOrderFulfillmentStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StoreOrderController extends Controller
{
    /**
     * List only the store-specific orders belonging to the authenticated Penjual owner.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', StoreOrder::class);

        $validated = $request->validate([
            'status' => ['nullable', Rule::in([...StoreOrderFulfillmentStatus::values(), 'all'])],
        ]);
        $selectedStatus = $validated['status'] ?? 'all';
        $store = $request->user()->umkmStore()->firstOrFail();

        $storeOrders = StoreOrder::query()
            ->where('umkm_store_id', $store->id)
            ->with(['order.user'])
            ->withCount('items')
            ->when($selectedStatus !== 'all', fn ($query) => $query->where('fulfillment_status', $selectedStatus))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('umkm.orders.index', compact('store', 'storeOrders', 'selectedStatus'));
    }

    /**
     * Show one immutable store-order snapshot after ownership authorization.
     */
    public function show(StoreOrder $storeOrder): View
    {
        Gate::authorize('view', $storeOrder);

        $storeOrder->load([
            'order.user',
            'items',
            'statusLogs' => fn ($query) => $query->latest('created_at'),
            'statusLogs.actor',
        ]);

        return view('umkm.orders.show', compact('storeOrder'));
    }

    public function startProcessing(Request $request, StoreOrder $storeOrder, StoreOrderFulfillmentService $fulfillment): RedirectResponse
    {
        return $this->transition($request, $storeOrder, $fulfillment, StoreOrderFulfillmentStatus::PROCESSING, 'Pesanan mulai diproses.');
    }

    public function markReadyForPickup(Request $request, StoreOrder $storeOrder, StoreOrderFulfillmentService $fulfillment): RedirectResponse
    {
        return $this->transition($request, $storeOrder, $fulfillment, StoreOrderFulfillmentStatus::READY_FOR_PICKUP, 'Pesanan telah ditandai siap diambil.');
    }

    public function complete(Request $request, StoreOrder $storeOrder, StoreOrderFulfillmentService $fulfillment): RedirectResponse
    {
        return $this->transition($request, $storeOrder, $fulfillment, StoreOrderFulfillmentStatus::COMPLETED, 'Penyerahan barang telah dikonfirmasi.');
    }

    private function transition(
        Request $request,
        StoreOrder $storeOrder,
        StoreOrderFulfillmentService $fulfillment,
        string $targetStatus,
        string $message,
    ): RedirectResponse {
        Gate::authorize('update', $storeOrder);
        $fulfillment->transition($storeOrder, $request->user(), $targetStatus);

        return redirect()->route('umkm.orders.show', $storeOrder)->with('status', $message);
    }
}
