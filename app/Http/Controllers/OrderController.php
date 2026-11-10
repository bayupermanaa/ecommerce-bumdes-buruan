<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StoreOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OrderController extends Controller
{
    /**
     * Display orders owned by the authenticated customer.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Order::class);

        $orders = $request->user()
            ->orders()
            ->with('storeOrders:id,order_id,fulfillment_status')
            ->withCount('storeOrders')
            ->latest()
            ->paginate(12);

        return view('orders.index', compact('orders'));
    }

    /**
     * Display immutable snapshots belonging to the authenticated customer.
     */
    public function show(Order $order): View
    {
        Gate::authorize('view', $order);

        $order->load([
            'storeOrders.items',
            'storeOrders.statusLogs' => fn ($query) => $query->latest('created_at'),
            'payments' => fn ($query) => $query->latest(),
        ]);

        return view('orders.show', compact('order'));
    }

    /**
     * Cancel an unpaid order and return its reserved stock exactly once.
     */
    public function cancel(Request $request, Order $order): RedirectResponse
    {
        Gate::authorize('cancel', $order);

        $validated = $request->validate([
            'cancellation_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $order, $validated): void {
            $lockedOrder = Order::query()
                ->whereKey($order)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->user_id !== $request->user()->id) {
                abort(403);
            }

            if (! $lockedOrder->canBeCancelled()) {
                throw ValidationException::withMessages([
                    'order' => 'Pesanan ini tidak dapat dibatalkan.',
                ]);
            }

            $storeOrders = StoreOrder::query()
                ->where('order_id', $lockedOrder->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $items = OrderItem::query()
                ->whereIn('store_order_id', $storeOrders->pluck('id'))
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get();
            $plainQuantities = $items->whereNull('product_variant_id')->groupBy('product_id')->map(
                fn ($productItems): int => $productItems->sum('quantity')
            );
            $variantQuantities = $items->whereNotNull('product_variant_id')->groupBy('product_variant_id')->map(
                fn ($variantItems): int => $variantItems->sum('quantity')
            );
            $products = Product::query()
                ->whereIn('id', $items->pluck('product_id')->unique())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $variants = $variantQuantities->isEmpty()
                ? collect()
                : ProductVariant::query()
                    ->whereIn('id', $variantQuantities->keys())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

            if ($products->count() !== $items->pluck('product_id')->unique()->count()
                || $variants->count() !== $variantQuantities->count()) {
                throw new \LogicException('Produk pada pesanan tidak lengkap.');
            }

            foreach ($items->whereNotNull('product_variant_id') as $item) {
                if ($variants->get($item->product_variant_id)->product_id !== $item->product_id) {
                    throw new \LogicException('Variasi pada pesanan tidak cocok dengan produk.');
                }
            }

            foreach ($plainQuantities as $productId => $quantity) {
                $products->get($productId)->increment('stock', $quantity);
            }

            foreach ($variantQuantities as $variantId => $quantity) {
                $variants->get($variantId)->increment('stock', $quantity);
            }

            foreach ($storeOrders as $storeOrder) {
                $storeOrder->forceFill(['fulfillment_status' => 'cancelled'])->save();
            }

            $lockedOrder->forceFill([
                'order_status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $validated['cancellation_reason'] ?? null,
            ])->save();
        });

        return redirect()
            ->route('orders.show', $order)
            ->with('status', 'Pesanan berhasil dibatalkan dan stok telah dikembalikan.');
    }
}
