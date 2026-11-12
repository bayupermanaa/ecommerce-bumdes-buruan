<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatOffer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Notifications\MarketplaceNotification;
use App\Models\UmkmStore;
use App\Support\UserRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ChatController extends Controller
{
    public function index(Request $request): View
    {
        $query = ChatConversation::query()->with(['customer', 'store.user'])->withMax('messages', 'created_at');
        if (UserRoles::isSeller($request->user()->role)) {
            $query->whereHas('store', fn ($q) => $q->where('user_id', $request->user()->id));
        } else {
            $query->where('customer_id', $request->user()->id);
        }
        $conversations = $query->latest('updated_at')->paginate(15);
        $unread = $this->unreadCount($request->user());
        return view('chat.index', compact('conversations', 'unread'));
    }

    public function start(Request $request, Product $product): RedirectResponse
    {
        abort_unless(UserRoles::canBuy($request->user()->role), 403);
        $product->load('umkmStore');
        abort_unless($product->status === 'approved' && $product->umkmStore?->status === 'approved', 404);
        abort_if($product->umkmStore->user_id === $request->user()->id, 403);
        $conversation = ChatConversation::firstOrCreate([
            'customer_id' => $request->user()->id,
            'umkm_store_id' => $product->umkm_store_id,
        ]);
        return redirect()->route('chat.show', $conversation);
    }

    public function show(Request $request, ChatConversation $conversation): View
    {
        $this->authorizeParticipant($request, $conversation);
        $conversation->load(['customer', 'store.user', 'messages.sender', 'offers.product', 'offers.variant']);
        $conversation->messages()->whereNull('read_at')->where('sender_id', '!=', $request->user()->id)->update(['read_at' => now()]);
        $products = UserRoles::isSeller($request->user()->role)
            ? Product::query()->where('umkm_store_id', $conversation->umkm_store_id)->where('status', 'approved')->with(['variants' => fn ($q) => $q->where('is_active', true)->orderBy('label')])->orderBy('name')->get()
            : collect();
        return view('chat.show', ['conversation' => $conversation, 'products' => $products, 'unread' => $this->unreadCount($request->user())]);
    }

    public function send(Request $request, ChatConversation $conversation): RedirectResponse
    {
        $this->authorizeParticipant($request, $conversation);
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000', 'required_without:image'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'required_without:body'],
        ]);
        $path = null;
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('chat-images', 'local');
        }
        try {
            $message = $conversation->messages()->create(['sender_id' => $request->user()->id, 'body' => trim((string) ($data['body'] ?? '')) ?: null, 'image_path' => $path]);
            $conversation->touch();
            $recipient = $request->user()->id === $conversation->customer_id ? $conversation->store->user : $conversation->customer;
            MarketplaceNotification::sendOnce($recipient, 'Pesan chat baru', 'Anda menerima pesan baru di percakapan.', route('chat.show', $conversation), 'chat.message.'.$message->id);
        } catch (\Throwable $e) {
            if ($path) Storage::disk('local')->delete($path);
            throw $e;
        }
        return back()->with('status', 'Pesan berhasil dikirim.');
    }

    public function createOffer(Request $request, ChatConversation $conversation): RedirectResponse
    {
        $this->authorizeSeller($request, $conversation);
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'], 'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'specifications' => ['nullable', 'string', 'max:5000'], 'quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'unit_price' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'], 'needed_on' => ['nullable', 'date', 'after_or_equal:today'], 'expires_at' => ['required', 'date', 'after:now'],
        ]);
        $product = Product::query()->whereKey($data['product_id'])->where('umkm_store_id', $conversation->umkm_store_id)->with('umkmStore')->firstOrFail();
        abort_unless($product->status === 'approved' && $product->umkmStore->status === 'approved', 422);
        $variant = !empty($data['product_variant_id']) ? ProductVariant::query()->whereKey($data['product_variant_id'])->where('product_id', $product->id)->where('is_active', true)->firstOrFail() : null;
        $unit = (float) $data['unit_price']; $quantity = (int) $data['quantity'];
        $createdOfferId = DB::transaction(function () use ($conversation, $request, $data, $product, $variant, $unit, $quantity) {
            $conversation->offers()->where('status', 'pending')->lockForUpdate()->get()->each(fn (ChatOffer $old) => $old->update(['status' => 'replaced']));
            $offer = $conversation->offers()->create(['seller_id'=>$request->user()->id,'customer_id'=>$conversation->customer_id,'product_id'=>$product->id,'product_variant_id'=>$variant?->id,'product_name_snapshot'=>$product->name,'product_image_snapshot'=>$product->image_path,'variant_label_snapshot'=>$variant?->label,'variant_sku_snapshot'=>$variant?->sku,'custom_specifications'=>!empty($data['specifications']) ? ['notes'=>$data['specifications']] : null,'quantity'=>$quantity,'unit_price'=>number_format($unit,2,'.',''),'total_price'=>number_format($unit*$quantity,2,'.',''),'needed_on'=>$data['needed_on'] ?? null,'expires_at'=>$data['expires_at'],'status'=>'pending']);
            $conversation->touch();
            return $offer->id;
        });
        $conversation->load('customer');
        MarketplaceNotification::sendOnce($conversation->customer, 'Penawaran custom baru', 'Penjual mengirim penawaran harga custom.', route('chat.show', $conversation), 'offer.created.'.$conversation->id.'.'.$createdOfferId);
        return back()->with('status', 'Penawaran berhasil dikirim.');
    }

    public function acceptOffer(Request $request, ChatOffer $offer): RedirectResponse { $this->respondToOffer($request, $offer, 'accepted'); $offer->load(['seller','conversation']); MarketplaceNotification::sendOnce($offer->seller, 'Penawaran diterima', 'Pembeli menerima penawaran custom Anda.', route('chat.show', $offer->conversation), 'offer.accepted.'.$offer->id); return back()->with('status', 'Penawaran diterima. Checkout custom belum dibuat pada tahap ini.'); }
    public function rejectOffer(Request $request, ChatOffer $offer): RedirectResponse { $this->respondToOffer($request, $offer, 'rejected'); $offer->load(['seller','conversation']); MarketplaceNotification::sendOnce($offer->seller, 'Penawaran ditolak', 'Pembeli menolak penawaran custom Anda.', route('chat.show', $offer->conversation), 'offer.rejected.'.$offer->id); return back()->with('status', 'Penawaran ditolak.'); }

    public function poll(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorizeParticipant($request, $conversation);
        $conversation->messages()->whereNull('read_at')->where('sender_id', '!=', $request->user()->id)->update(['read_at' => now()]);
        return response()->json(['messages' => $conversation->messages()->with('sender')->oldest()->get()->map(fn (ChatMessage $message) => $this->messagePayload($message)), 'offers' => $conversation->offers()->latest()->get()->map(fn (ChatOffer $offer) => $this->offerPayload($offer))]);
    }

    public function image(Request $request, ChatMessage $message)
    {
        $this->authorizeParticipant($request, $message->conversation()->firstOrFail());
        abort_unless($message->image_path && Storage::disk('local')->exists($message->image_path), 404);
        return Storage::disk('local')->response($message->image_path);
    }

    private function authorizeParticipant(Request $request, ChatConversation $conversation): void
    {
        $user = $request->user();
        $allowed = $conversation->customer_id === $user->id
            || (UserRoles::isSeller($user->role) && $conversation->store()->where('user_id', $user->id)->exists());
        abort_unless($allowed, 403);
    }

    private function authorizeSeller(Request $request, ChatConversation $conversation): void { abort_unless(UserRoles::isSeller($request->user()->role) && $conversation->store()->where('user_id', $request->user()->id)->exists(), 403); }

    private function respondToOffer(Request $request, ChatOffer $offer, string $status): void
    {
        abort_unless($offer->customer_id === $request->user()->id, 403);
        DB::transaction(function () use ($offer, $status) {
            $locked = ChatOffer::query()->lockForUpdate()->findOrFail($offer->id);
            if ($locked->status !== 'pending') abort(422, 'Penawaran sudah tidak menunggu persetujuan.');
            if ($locked->expires_at->isPast()) { $locked->update(['status'=>'expired']); abort(422, 'Penawaran sudah kedaluwarsa.'); }
            $locked->update(['status'=>$status,'responded_at'=>now()]);
        });
    }

    private function unreadCount($user): int
    {
        return ChatMessage::query()->whereNull('read_at')->where('sender_id', '!=', $user->id)->whereHas('conversation', function ($q) use ($user) {
            $q->where('customer_id', $user->id)->orWhereHas('store', fn ($s) => $s->where('user_id', $user->id));
        })->count();
    }

    private function messagePayload(ChatMessage $message): array
    {
        return ['id' => $message->id, 'body' => $message->body, 'sender_id' => $message->sender_id, 'sender_name' => $message->sender->name, 'read_at' => $message->read_at?->toISOString(), 'created_at' => $message->created_at->toISOString(), 'image_url' => $message->image_path ? route('chat.messages.image', $message) : null];
    }

    private function offerPayload(ChatOffer $offer): array { return ['id'=>$offer->id,'product_name'=>$offer->product_name_snapshot,'image_url'=>$offer->product_image_snapshot ? \Illuminate\Support\Facades\Storage::url($offer->product_image_snapshot) : null,'variant'=>$offer->variant_label_snapshot,'specifications'=>$offer->custom_specifications,'quantity'=>$offer->quantity,'unit_price'=>$offer->unit_price,'total_price'=>$offer->total_price,'status'=>$offer->status,'expires_at'=>$offer->expires_at->toISOString(),'needed_on'=>$offer->needed_on?->toDateString()]; }
}
