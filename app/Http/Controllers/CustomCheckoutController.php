<?php
namespace App\Http\Controllers;

use App\Models\ChatOffer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StoreOrder;
use App\Services\CommissionCalculationService;
use App\Notifications\MarketplaceNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CustomCheckoutController extends Controller
{
    public function show(Request $request, ChatOffer $offer): View
    {
        $this->authorizeBuyer($request, $offer);
        $offer->load(['product.umkmStore', 'variant', 'order']);
        if ($offer->status !== 'accepted' || $offer->order) abort(422, 'Penawaran ini tidak tersedia untuk checkout.');
        if ($offer->expires_at->isPast()) abort(422, 'Penawaran sudah kedaluwarsa.');
        return view('checkout.custom', compact('offer'));
    }

    public function store(Request $request, ChatOffer $offer, CommissionCalculationService $commissionService): RedirectResponse
    {
        $this->authorizeBuyer($request, $offer);
        $data = $request->validate(['recipient_name'=>['required','string','max:255'],'recipient_phone'=>['required','string','max:50'],'fulfillment_method'=>['required','in:pickup']]);
        $order = DB::transaction(function () use ($request, $offer, $data, $commissionService) {
            $locked = ChatOffer::query()->lockForUpdate()->findOrFail($offer->id);
            if ($locked->customer_id !== $request->user()->id || $locked->status !== 'accepted' || $locked->order()->exists() || $locked->expires_at->isPast()) throw ValidationException::withMessages(['offer'=>'Penawaran sudah tidak dapat digunakan.']);
            $product = Product::query()->whereKey($locked->product_id)->lockForUpdate()->firstOrFail();
            $store = $product->umkmStore()->lockForUpdate()->firstOrFail();
            $variant = $locked->product_variant_id ? ProductVariant::query()->whereKey($locked->product_variant_id)->lockForUpdate()->firstOrFail() : null;
            if ($product->status !== 'approved' || $store->status !== 'approved' || ($variant && ($variant->product_id !== $product->id || ! $variant->is_active))) throw ValidationException::withMessages(['offer'=>'Produk atau variasi tidak lagi tersedia.']);
            $stockSource = $variant ?: $product;
            if ($stockSource->stock < $locked->quantity) throw ValidationException::withMessages(['offer'=>'Stok produk tidak mencukupi.']);
            $total = (string) $locked->total_price;
            $commission = $commissionService->calculateForStore($store, $total, now());
            $snapshot = $commission ? ['commission_agreement_id'=>$commission['agreement']->id,'commission_percentage'=>$commission['agreement']->commission_percentage,'commission_amount'=>$commission['commission_amount'],'umkm_amount'=>$commission['umkm_amount'],'commission_status'=>'calculated'] : ['commission_agreement_id'=>null,'commission_percentage'=>null,'commission_amount'=>null,'umkm_amount'=>null,'commission_status'=>'unconfigured'];
            $order = Order::create(['order_number'=>'ORD-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8)),'checkout_token'=>(string) Str::uuid(),'chat_offer_id'=>$locked->id,'user_id'=>$request->user()->id,'fulfillment_method'=>'pickup','recipient_name'=>$data['recipient_name'],'recipient_phone'=>$data['recipient_phone'],'subtotal'=>$total,'shipping_total'=>'0.00','grand_total'=>$total,'payment_status'=>'unpaid','order_status'=>'pending_payment']);
            $storeOrder = $order->storeOrders()->create(['umkm_store_id'=>$store->id,'store_order_number'=>'STO-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8)),'store_name_snapshot'=>$store->name,'store_address_snapshot'=>$store->address,'store_phone_snapshot'=>$store->phone,'subtotal'=>$total,'shipping_amount'=>'0.00','total'=>$total,'fulfillment_status'=>'pending_payment',...$snapshot]);
            $storeOrder->items()->create(['product_id'=>$product->id,'product_variant_id'=>$variant?->id,'product_name_snapshot'=>$locked->product_name_snapshot,'sku_snapshot'=>$variant?->sku ?? $product->sku,'variant_label_snapshot'=>$locked->variant_label_snapshot,'variant_sku_snapshot'=>$locked->variant_sku_snapshot,'custom_specifications'=>$locked->custom_specifications,'unit_price'=>$locked->unit_price,'quantity'=>$locked->quantity,'line_total'=>$locked->total_price]);
            $stockSource->decrement('stock', $locked->quantity);
            $locked->update(['status'=>'checked_out']);
            return $order;
        });
        $order->load('storeOrders.umkmStore');
        foreach ($order->storeOrders as $storeOrder) {
            MarketplaceNotification::sendOnce($storeOrder->umkmStore->user, 'Pesanan custom baru', 'Anda menerima pesanan custom yang menunggu pembayaran.', route('umkm.orders.show', $storeOrder), 'order.created.'.$order->id.'.'.$storeOrder->id);
        }
        return redirect()->route('orders.show', $order)->with('status','Pesanan custom berhasil dibuat. Silakan ajukan pembayaran.');
    }

    private function authorizeBuyer(Request $request, ChatOffer $offer): void { abort_unless($offer->customer_id === $request->user()->id, 403); }
}
