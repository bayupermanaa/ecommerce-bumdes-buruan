<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CartController extends Controller
{
    /**
     * Display the authenticated customer's persisted cart.
     */
    public function index(Request $request): View
    {
        $cart = Cart::query()
            ->where('user_id', $request->user()->id)
            ->with(['items.product.category', 'items.product.umkmStore'])
            ->first();

        return view('cart.index', compact('cart'));
    }

    /**
     * Add an approved product to the customer's cart.
     */
    public function store(Request $request, Product $product): RedirectResponse
    {
        $quantity = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ])['quantity'];

        DB::transaction(function () use ($request, $product, $quantity): void {
            $product = Product::query()
                ->with('umkmStore')
                ->lockForUpdate()
                ->findOrFail($product->id);
            $this->ensureProductCanBeAdded($product);

            $cart = $this->cartForUser($request->user()->id);
            $item = CartItem::query()
                ->where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();
            $newQuantity = ($item?->quantity ?? 0) + $quantity;

            $this->ensureQuantityWithinStock($newQuantity, $product);

            if ($item) {
                $item->update(['quantity' => $newQuantity]);

                return;
            }

            $cart->items()->create([
                'product_id' => $product->id,
                'quantity' => $quantity,
            ]);
        });

        return redirect()
            ->route('cart.index')
            ->with('status', 'Produk berhasil ditambahkan ke keranjang.');
    }

    /**
     * Change an item's quantity without accepting price data from the browser.
     */
    public function update(Request $request, CartItem $item): RedirectResponse
    {
        $quantity = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ])['quantity'];
        $this->ensureItemBelongsToUser($request, $item);

        DB::transaction(function () use ($item, $quantity): void {
            $item = CartItem::query()->lockForUpdate()->findOrFail($item->id);
            $product = Product::query()
                ->with('umkmStore')
                ->lockForUpdate()
                ->findOrFail($item->product_id);
            $this->ensureProductCanBeAdded($product);
            $this->ensureQuantityWithinStock($quantity, $product);

            $item->update(['quantity' => $quantity]);
        });

        return redirect()
            ->route('cart.index')
            ->with('status', 'Jumlah produk berhasil diperbarui.');
    }

    /**
     * Remove one owned item from the cart.
     */
    public function destroy(Request $request, CartItem $item): RedirectResponse
    {
        $this->ensureItemBelongsToUser($request, $item);
        $item->delete();

        return redirect()
            ->route('cart.index')
            ->with('status', 'Produk dihapus dari keranjang.');
    }

    /**
     * Empty only the authenticated customer's cart.
     */
    public function clear(Request $request): RedirectResponse
    {
        Cart::query()
            ->where('user_id', $request->user()->id)
            ->first()?->items()
            ->delete();

        return redirect()
            ->route('cart.index')
            ->with('status', 'Keranjang berhasil dikosongkan.');
    }

    /**
     * Get or create the unique persisted cart for a customer.
     */
    private function cartForUser(int $userId): Cart
    {
        try {
            return Cart::query()->firstOrCreate(['user_id' => $userId]);
        } catch (UniqueConstraintViolationException) {
            return Cart::query()->where('user_id', $userId)->firstOrFail();
        }
    }

    /**
     * Reject products that are no longer publicly purchasable.
     */
    private function ensureProductCanBeAdded(Product $product): void
    {
        if ($product->status !== 'approved' || $product->umkmStore?->status !== 'approved') {
            throw ValidationException::withMessages([
                'product' => 'Produk ini tidak tersedia untuk ditambahkan ke keranjang.',
            ]);
        }

        if ($product->stock < 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Produk ini sedang kehabisan stok.',
            ]);
        }
    }

    /**
     * Keep each cart quantity within current stock without reserving stock.
     */
    private function ensureQuantityWithinStock(int $quantity, Product $product): void
    {
        if ($quantity > $product->stock) {
            throw ValidationException::withMessages([
                'quantity' => "Jumlah produk tidak boleh melebihi stok tersedia ({$product->stock}).",
            ]);
        }
    }

    /**
     * Prevent a customer from manipulating another customer's cart item.
     */
    private function ensureItemBelongsToUser(Request $request, CartItem $item): void
    {
        $belongsToUser = Cart::query()
            ->whereKey($item->cart_id)
            ->where('user_id', $request->user()->id)
            ->exists();

        abort_unless($belongsToUser, 403);
    }
}
