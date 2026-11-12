<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class ChatOffer extends Model {
    use HasFactory;
    protected $fillable = ['chat_conversation_id','seller_id','customer_id','product_id','product_variant_id','product_name_snapshot','product_image_snapshot','variant_label_snapshot','variant_sku_snapshot','custom_specifications','quantity','unit_price','total_price','needed_on','expires_at','status','replaced_by_id','responded_at'];
    protected function casts(): array { return ['custom_specifications'=>'array','unit_price'=>'decimal:2','total_price'=>'decimal:2','expires_at'=>'datetime','needed_on'=>'date','responded_at'=>'datetime']; }
    public function conversation(): BelongsTo { return $this->belongsTo(ChatConversation::class, 'chat_conversation_id'); }
    public function seller(): BelongsTo { return $this->belongsTo(User::class, 'seller_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(User::class, 'customer_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'product_variant_id'); }
    public function order(): \Illuminate\Database\Eloquent\Relations\HasOne { return $this->hasOne(Order::class, 'chat_offer_id'); }
}
