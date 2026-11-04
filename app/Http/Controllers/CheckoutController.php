<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreOrder;
use App\Models\UmkmStore;
use App\Services\CommissionCalculationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    /**
     * Display a read-only checkout review using the latest product data.
     */
    public function index(Request $request): View
    {
        $cart = Cart::query()
            ->where('user_id', $request->user()->id)
            ->with(['items.product.category', 'items.product.umkmStore'])
            ->first();
        $items = $cart?->items ?? collect();

        $storeGroups = $items
            ->groupBy(fn ($item) => $item->product->umkm_store_id)
            ->map(function (Collection $items): array {
                $store = $items->first()->product->umkmStore;
                $subtotalInMinorUnits = $items->sum(
                    fn ($item): int => $this->toMinorUnits((string) $item->product->price) * $item->quantity
                );

                return [
                    'store' => $store,
                    'items' => $items,
                    'subtotal' => $this->fromMinorUnits($subtotalInMinorUnits),
                ];
            })
            ->values();
        $totalInMinorUnits = $storeGroups->sum(
            fn (array $group): int => $this->toMinorUnits($group['subtotal'])
        );
        $total = $this->fromMinorUnits($totalInMinorUnits);
        $hasUnavailableItems = $items->contains(fn ($item): bool => ! $this->isAvailable($item));
        $checkoutToken = $request->session()->get('checkout_token');

        if (! is_string($checkoutToken)) {
            $checkoutToken = (string) Str::uuid();
            $request->session()->put('checkout_token', $checkoutToken);
        }

        return view('checkout.index', [
            'cart' => $cart,
            'storeGroups' => $storeGroups,
            'total' => $total,
            'hasUnavailableItems' => $hasUnavailableItems,
            'checkoutToken' => $checkoutToken,
            'districts' => [
                'Blahbatuh',
                'Gianyar',
                'Sukawati',
                'Ubud',
                'Tampaksiring',
                'Tegallalang',
                'Payangan',
            ],
        ]);
    }

    /**
     * Create a pickup order from the customer's current cart.
     */
    public function store(Request $request, CommissionCalculationService $commissionService): RedirectResponse
    {
        $validated = $request->validate([
            'checkout_token' => ['required', 'uuid'],
            'fulfillment_method' => ['required', 'in:pickup,delivery'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_phone' => ['required', 'string', 'max:50'],
        ]);

        if ($validated['fulfillment_method'] !== 'pickup') {
            throw ValidationException::withMessages([
                'fulfillment_method' => 'Pemesanan dengan kurir belum tersedia karena tarif pengiriman belum dikonfigurasi.',
            ]);
        }

        $sessionToken = $request->session()->get('checkout_token');

        if (! is_string($sessionToken) || ! hash_equals($sessionToken, $validated['checkout_token'])) {
            abort(419, 'Sesi checkout sudah tidak berlaku. Muat ulang halaman checkout.');
        }

        $transactionDate = now();
        $order = DB::transaction(function () use ($request, $validated, $commissionService, $transactionDate): Order {
            $cart = Cart::query()
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()
                ->first();
            $existingOrder = Order::query()
                ->where('user_id', $request->user()->id)
                ->where('checkout_token', $validated['checkout_token'])
                ->first();

            if ($existingOrder) {
                return $existingOrder;
            }

            if (! $cart) {
                throw ValidationException::withMessages(['cart' => 'Keranjang Anda kosong.']);
            }

            $cartItems = CartItem::query()
                ->where('cart_id', $cart->id)
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get();

            if ($cartItems->isEmpty()) {
                throw ValidationException::withMessages(['cart' => 'Keranjang Anda kosong.']);
            }

            $products = Product::query()
                ->whereIn('id', $cartItems->pluck('product_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $storeIds = $products->pluck('umkm_store_id')->unique()->sort()->values();
            $stores = UmkmStore::query()
                ->whereIn('id', $storeIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lines = $cartItems->map(function (CartItem $item) use ($products, $stores): array {
                $product = $products->get($item->product_id);
                $store = $product ? $stores->get($product->umkm_store_id) : null;

                if (! $product || ! $store || $product->status !== 'approved' || $store->status !== 'approved') {
                    throw ValidationException::withMessages([
                        'cart' => 'Salah satu produk atau toko tidak lagi tersedia.',
                    ]);
                }

                if ($item->quantity > $product->stock) {
                    throw ValidationException::withMessages([
                        'cart' => "Stok {$product->name} tidak mencukupi.",
                    ]);
                }

                return compact('item', 'product', 'store');
            });
            $subtotalInMinorUnits = $lines->sum(
                fn (array $line): int => $this->toMinorUnits((string) $line['product']->price) * $line['item']->quantity
            );
            $subtotal = $this->fromMinorUnits($subtotalInMinorUnits);
            $order = Order::create([
                'order_number' => $this->orderNumber(),
                'checkout_token' => $validated['checkout_token'],
                'user_id' => $request->user()->id,
                'fulfillment_method' => 'pickup',
                'recipient_name' => $validated['recipient_name'],
                'recipient_phone' => $validated['recipient_phone'],
                'subtotal' => $subtotal,
                'shipping_total' => 0,
                'grand_total' => $subtotal,
                'payment_status' => 'unpaid',
                'order_status' => 'pending_payment',
            ]);

            $lines->groupBy(fn (array $line) => $line['store']->id)->each(function (Collection $storeLines) use ($order, $commissionService, $transactionDate): void {
                /** @var UmkmStore $store */
                $store = $storeLines->first()['store'];
                $storeSubtotalInMinorUnits = $storeLines->sum(
                    fn (array $line): int => $this->toMinorUnits((string) $line['product']->price) * $line['item']->quantity
                );
                $storeSubtotal = $this->fromMinorUnits($storeSubtotalInMinorUnits);
                $commission = $commissionService->calculateForStore($store, $storeSubtotal, $transactionDate);
                $commissionSnapshot = $commission
                    ? [
                        'commission_agreement_id' => $commission['agreement']->id,
                        'commission_percentage' => $commission['agreement']->commission_percentage,
                        'commission_amount' => $commission['commission_amount'],
                        'umkm_amount' => $commission['umkm_amount'],
                        'commission_status' => 'calculated',
                    ]
                    : [
                        'commission_agreement_id' => null,
                        'commission_percentage' => null,
                        'commission_amount' => null,
                        'umkm_amount' => null,
                        'commission_status' => 'unconfigured',
                    ];
                $storeOrder = $order->storeOrders()->create([
                    'umkm_store_id' => $store->id,
                    'store_order_number' => $this->storeOrderNumber(),
                    'store_name_snapshot' => $store->name,
                    'store_address_snapshot' => $store->address,
                    'store_phone_snapshot' => $store->phone,
                    'subtotal' => $storeSubtotal,
                    'shipping_amount' => 0,
                    'total' => $storeSubtotal,
                    'fulfillment_status' => 'pending_payment',
                    ...$commissionSnapshot,
                ]);

                $storeOrder->items()->createMany($storeLines->map(function (array $line): array {
                    /** @var Product $product */
                    $product = $line['product'];
                    /** @var CartItem $item */
                    $item = $line['item'];
                    $lineTotal = $this->fromMinorUnits(
                        $this->toMinorUnits((string) $product->price) * $item->quantity
                    );

                    return [
                        'product_id' => $product->id,
                        'product_name_snapshot' => $product->name,
                        'sku_snapshot' => $product->sku,
                        'unit_price' => $product->price,
                        'quantity' => $item->quantity,
                        'line_total' => $lineTotal,
                    ];
                })->all());
            });

            $lines->each(function (array $line): void {
                /** @var Product $product */
                $product = $line['product'];
                $product->decrement('stock', $line['item']->quantity);
            });

            CartItem::query()->where('cart_id', $cart->id)->delete();

            return $order;
        });

        $request->session()->forget('checkout_token');

        return redirect()
            ->route('orders.show', $order)
            ->with('status', 'Pesanan berhasil dibuat. Silakan ajukan pembayaran untuk melanjutkan proses pesanan.');
    }

    /**
     * A checkout review may only continue with products available right now.
     */
    private function isAvailable(mixed $item): bool
    {
        $product = $item->product;

        return $product->status === 'approved'
            && $product->umkmStore?->status === 'approved'
            && $product->stock >= $item->quantity;
    }

    /**
     * Convert a positive two-decimal money string to integer minor units.
     */
    private function toMinorUnits(string $amount): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw new \InvalidArgumentException('Money amount must be a non-negative decimal with up to two decimal places.');
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    /**
     * Format integer minor units as a storage-compatible two-decimal amount.
     */
    private function fromMinorUnits(int $amount): string
    {
        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Generate public identifiers independently from sequential database IDs.
     */
    private function orderNumber(): string
    {
        return 'ORD-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8));
    }

    /**
     * Generate a unique-looking identifier for each store split of an order.
     */
    private function storeOrderNumber(): string
    {
        return 'STO-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8));
    }
}
