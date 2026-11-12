<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ChatConversation;
use App\Models\ChatOffer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_start_chat_send_text_and_read_messages(): void
    {
        [$buyer, $seller, $store] = $this->fixture();
        $product = $store->products()->create(['category_id' => Category::first()->id, 'sku' => 'CHAT-1', 'name' => 'Produk Chat', 'slug' => 'produk-chat', 'price' => 1000, 'stock' => 2]);
        $product->forceFill(['status' => 'approved'])->save();
        $this->actingAs($buyer)->post(route('chat.start', $product))->assertRedirect();
        $conversation = ChatConversation::sole();
        $this->actingAs($buyer)->post(route('chat.messages.store', $conversation), ['body' => 'Halo, bisa custom?'])->assertRedirect();
        $this->assertDatabaseHas('chat_messages', ['chat_conversation_id' => $conversation->id, 'sender_id' => $buyer->id, 'body' => 'Halo, bisa custom?']);
        $this->actingAs($seller)->get(route('chat.show', $conversation))->assertOk()->assertSee('Halo, bisa custom?');
    }

    public function test_chat_isolated_and_image_is_private(): void
    {
        Storage::fake('local');
        [$buyer, $seller, $store] = $this->fixture();
        $other = User::factory()->create(['role' => 'pelanggan']);
        $conversation = ChatConversation::create(['customer_id' => $buyer->id, 'umkm_store_id' => $store->id]);
        $this->actingAs($buyer)->post(route('chat.messages.store', $conversation), ['image' => UploadedFile::fake()->image('referensi.png')])->assertRedirect();
        $message = $conversation->messages()->sole();
        Storage::disk('local')->assertExists($message->image_path);
        $this->actingAs($other)->get(route('chat.show', $conversation))->assertForbidden();
        $this->actingAs($other)->get(route('chat.messages.image', $message))->assertForbidden();
    }

    public function test_owner_cannot_start_chat_with_own_store(): void
    {
        [, $seller, $store] = $this->fixture();
        $product = $store->products()->create(['category_id' => Category::first()->id, 'sku' => 'CHAT-2', 'name' => 'Produk', 'slug' => 'produk-chat-2', 'price' => 1000, 'stock' => 1]);
        $product->forceFill(['status' => 'approved'])->save();
        $this->actingAs($seller)->post(route('chat.start', $product))->assertForbidden();
        $this->assertDatabaseCount('chat_conversations', 0);
    }

    public function test_seller_can_create_offer_and_customer_can_accept_once(): void
    {
        [$buyer, $seller, $store] = $this->fixture();
        $product = $store->products()->create(['category_id' => Category::first()->id, 'sku' => 'OFFER-1', 'name' => 'Kue Custom', 'slug' => 'kue-custom', 'price' => 1000, 'stock' => 2]); $product->forceFill(['status' => 'approved'])->save();
        $conversation = ChatConversation::create(['customer_id' => $buyer->id, 'umkm_store_id' => $store->id]);
        $this->actingAs($seller)->post(route('chat.offers.store', $conversation), ['product_id' => $product->id, 'specifications' => 'Tulisan ulang tahun', 'quantity' => 2, 'unit_price' => '125000.00', 'expires_at' => now()->addDay()->format('Y-m-d H:i:s')])->assertRedirect();
        $offer = ChatOffer::sole();
        $this->assertSame('250000.00', $offer->total_price);
        $this->actingAs($buyer)->post(route('chat.offers.accept', $offer))->assertRedirect();
        $this->assertDatabaseHas('chat_offers', ['id' => $offer->id, 'status' => 'accepted']);
        $this->actingAs($buyer)->post(route('chat.offers.accept', $offer))->assertStatus(422);
    }

    public function test_offer_isolated_price_server_validated_and_new_offer_replaces_pending(): void
    {
        [$buyer, $seller, $store] = $this->fixture();
        $otherSeller = User::factory()->create(['role' => 'penjual']);
        $otherStore = $otherSeller->umkmStore()->create(['name' => 'Other Store', 'slug' => 'other-store']);
        $otherStore->forceFill(['status' => 'approved'])->save();
        $product = $store->products()->create(['category_id' => Category::first()->id, 'sku' => 'OFFER-2', 'name' => 'Kue', 'slug' => 'kue-offer', 'price' => 1000, 'stock' => 2]); $product->forceFill(['status' => 'approved'])->save();
        $conversation = ChatConversation::create(['customer_id' => $buyer->id, 'umkm_store_id' => $store->id]);
        $this->actingAs($otherSeller)->post(route('chat.offers.store', $conversation), ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100, 'expires_at' => now()->addDay()->format('Y-m-d H:i:s')])->assertForbidden();
        $this->actingAs($seller)->post(route('chat.offers.store', $conversation), ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100, 'expires_at' => now()->addDay()->format('Y-m-d H:i:s')])->assertRedirect();
        $old = ChatOffer::sole();
        $this->actingAs($seller)->post(route('chat.offers.store', $conversation), ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 200, 'expires_at' => now()->addDay()->format('Y-m-d H:i:s')])->assertRedirect();
        $this->assertDatabaseHas('chat_offers', ['id' => $old->id, 'status' => 'replaced']);
        $this->assertDatabaseHas('chat_offers', ['total_price' => '200.00', 'status' => 'pending']);
    }

    public function test_accepted_offer_creates_one_custom_order_with_snapshot_and_stock(): void
    {
        [$buyer, $seller, $store] = $this->fixture();
        $product = $store->products()->create(['category_id'=>Category::first()->id,'sku'=>'CUSTOM-1','name'=>'Custom Order','slug'=>'custom-order','price'=>1000,'stock'=>3]); $product->forceFill(['status'=>'approved'])->save();
        $conversation = ChatConversation::create(['customer_id'=>$buyer->id,'umkm_store_id'=>$store->id]);
        $offer = ChatOffer::create(['chat_conversation_id'=>$conversation->id,'seller_id'=>$seller->id,'customer_id'=>$buyer->id,'product_id'=>$product->id,'product_name_snapshot'=>$product->name,'custom_specifications'=>['notes'=>'Desain khusus'],'quantity'=>2,'unit_price'=>'75000.00','total_price'=>'150000.00','expires_at'=>now()->addDay(),'status'=>'accepted']);
        $payload = ['fulfillment_method'=>'pickup','recipient_name'=>'Pembeli','recipient_phone'=>'08123456789'];
        $this->actingAs($buyer)->post(route('checkout.custom.store', $offer), $payload)->assertRedirect();
        $order = Order::sole();
        $this->assertSame('150000.00', $order->grand_total);
        $this->actingAs($buyer)->get(route('orders.show', $order))->assertOk()->assertSee('Perjalanan pesanan');
        $this->assertDatabaseHas('chat_offers', ['id'=>$offer->id,'status'=>'checked_out']);
        $this->assertDatabaseHas('order_items', ['store_order_id'=>$order->storeOrders()->sole()->id,'custom_specifications'=>'{"notes":"Desain khusus"}','unit_price'=>'75000.00']);
        $this->assertSame(1, $product->refresh()->stock);
        $this->actingAs($buyer)->post(route('checkout.custom.store', $offer), $payload)->assertSessionHasErrors('offer');
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_custom_checkout_is_rejected_for_other_buyer_and_pending_offer(): void
    {
        [$buyer, $seller, $store] = $this->fixture(); $other = User::factory()->create(['role'=>'pelanggan']);
        $product = $store->products()->create(['category_id'=>Category::first()->id,'sku'=>'CUSTOM-2','name'=>'Custom','slug'=>'custom-2','price'=>1000,'stock'=>1]); $product->forceFill(['status'=>'approved'])->save();
        $conversation = ChatConversation::create(['customer_id'=>$buyer->id,'umkm_store_id'=>$store->id]);
        $offer = ChatOffer::create(['chat_conversation_id'=>$conversation->id,'seller_id'=>$seller->id,'customer_id'=>$buyer->id,'product_id'=>$product->id,'product_name_snapshot'=>'Custom','quantity'=>1,'unit_price'=>'10.00','total_price'=>'10.00','expires_at'=>now()->addDay(),'status'=>'pending']);
        $this->actingAs($other)->get(route('checkout.custom.show', $offer))->assertForbidden();
        $this->actingAs($buyer)->get(route('checkout.custom.show', $offer))->assertStatus(422);
    }

    public function test_chat_notification_is_private_and_can_be_marked_read(): void
    {
        [$buyer, $seller, $store] = $this->fixture();
        $conversation = ChatConversation::create(['customer_id'=>$buyer->id,'umkm_store_id'=>$store->id]);
        $this->actingAs($buyer)->post(route('chat.messages.store', $conversation), ['body'=>'Pesan notifikasi'])->assertRedirect();
        $this->assertSame(1, $seller->fresh()->unreadNotifications()->count());
        $notification = $seller->fresh()->notifications()->first();
        $this->actingAs($seller)->post(route('notifications.read', $notification->id))->assertRedirect(route('chat.show', $conversation));
        $this->assertNotNull($notification->fresh()->read_at);
        $this->actingAs($buyer)->get(route('notifications.index'))->assertOk();
    }

    public function test_legacy_seller_can_read_only_their_own_store_conversation(): void
    {
        [$buyer, $seller, $store] = $this->fixture();
        $seller->forceFill(['role' => 'pemilik_umkm'])->save();
        $own = ChatConversation::create(['customer_id' => $buyer->id, 'umkm_store_id' => $store->id]);
        $otherSeller = User::factory()->create(['role' => 'penjual']);
        $otherStore = $otherSeller->umkmStore()->create(['name' => 'Toko Lain', 'slug' => 'toko-lain-chat']);
        $other = ChatConversation::create(['customer_id' => $buyer->id, 'umkm_store_id' => $otherStore->id]);

        $this->actingAs($seller)->get(route('chat.index'))->assertOk()->assertSee(route('chat.show', $own))->assertDontSee(route('chat.show', $other));
        $this->actingAs($seller)->get(route('chat.show', $own))->assertOk();
        $this->actingAs($seller)->get(route('chat.show', $other))->assertForbidden();
    }

    private function fixture(): array
    {
        $buyer = User::factory()->create(['role' => 'pelanggan']);
        $seller = User::factory()->create(['role' => 'penjual']);
        $store = $seller->umkmStore()->create(['name' => 'Chat Store', 'slug' => 'chat-store']);
        $store->forceFill(['status' => 'approved'])->save();
        Category::create(['name' => 'Chat Category', 'slug' => 'chat-category']);
        return [$buyer, $seller, $store];
    }
}
