<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CartController extends Controller
{
    /**
     * Display the authenticated customer's persisted cart.
     */
    public function index(Request $request): View
    {
        $variantReady = Schema::hasColumn('cart_items', 'variant_identity');
        $relations = ['items.product.category', 'items.product.umkmStore'];
        if ($variantReady) {
            $relations[] = 'items.variant';
            $relations['items.product'] = fn ($query) => $query->withExists('variants');
        }
        $cart = Cart::query()
            ->where('user_id', $request->user()->id)
            ->with($relations)
            ->first();

        return view('cart.index', compact('cart'));
    }

    /**
     * Add an approved product to the customer's cart.
     */
    public function store(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
            'product_variant_id' => ['nullable', 'integer'],
        ]);
        $quantity = $validated['quantity'];
        $variantId = $validated['product_variant_id'] ?? null;
        $variantReady = Schema::hasColumn('cart_items', 'variant_identity');
        if (! $variantReady && $variantId !== null) {
            throw ValidationException::withMessages(['product_variant_id' => 'Variasi produk belum tersedia.']);
        }

        DB::transaction(function () use ($request, $product, $quantity, $variantId, $variantReady): void {
            $cart = $this->cartForUser($request->user()->id);
            Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $product = Product::query()
                ->with('umkmStore')
                ->lockForUpdate()
                ->findOrFail($product->id);
            $variant = $variantReady ? $this->resolveVariant($product, $variantId) : null;
            $this->ensureProductCanBeAdded($product, $variant);

            $itemQuery = CartItem::query()
                ->where('cart_id', $cart->id)
                ->where('product_id', $product->id);
            if ($variantReady) {
                $itemQuery->where('variant_identity', $variant?->id ?? 0);
            }
            $item = $itemQuery->lockForUpdate()->first();
            $newQuantity = ($item?->quantity ?? 0) + $quantity;

            $this->ensureQuantityWithinStock($newQuantity, $variant ?? $product);

            if ($item) {
                $item->update(['quantity' => $newQuantity]);

                return;
            }

            $cart->items()->create([
                'product_id' => $product->id,
                ...($variantReady ? ['product_variant_id' => $variant?->id, 'variant_identity' => $variant?->id ?? 0] : []),
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
            Cart::query()->whereKey($item->cart_id)->lockForUpdate()->firstOrFail();
            $item = CartItem::query()->lockForUpdate()->findOrFail($item->id);
            $product = Product::query()
                ->with('umkmStore')
                ->lockForUpdate()
                ->findOrFail($item->product_id);
            $variant = Schema::hasColumn('cart_items', 'variant_identity')
                ? $this->resolveVariant($product, $item->product_variant_id)
                : null;
            $this->ensureProductCanBeAdded($product, $variant);
            $this->ensureQuantityWithinStock($quantity, $variant ?? $product);

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
    private function resolveVariant(Product $product, ?int $variantId): ?ProductVariant
    {
        if ($variantId === null) {
            if ($product->variants()->exists()) {
                throw ValidationException::withMessages(['product_variant_id' => 'Pilih variasi produk sebelum menambahkannya ke keranjang.']);
            }

            return null;
        }

        $variant = $product->variants()->lockForUpdate()->find($variantId);
        if (! $variant || ! $variant->is_active) {
            throw ValidationException::withMessages(['product_variant_id' => 'Variasi produk tidak tersedia.']);
        }

        return $variant;
    }

    private function ensureProductCanBeAdded(Product $product, ?ProductVariant $variant): void
    {
        if ($product->status !== 'approved' || $product->umkmStore?->status !== 'approved') {
            throw ValidationException::withMessages([
                'product' => 'Produk ini tidak tersedia untuk ditambahkan ke keranjang.',
            ]);
        }

        if (($variant ?? $product)->stock < 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Produk ini sedang kehabisan stok.',
            ]);
        }
    }

    /**
     * Keep each cart quantity within current stock without reserving stock.
     */
    private function ensureQuantityWithinStock(int $quantity, Product|ProductVariant $stockSource): void
    {
        if ($quantity > $stockSource->stock) {
            throw ValidationException::withMessages([
                'quantity' => "Jumlah produk tidak boleh melebihi stok tersedia ({$stockSource->stock}).",
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
