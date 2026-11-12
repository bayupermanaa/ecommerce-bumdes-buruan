<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatConversation extends Model
{
    use HasFactory;

    protected $fillable = ['customer_id', 'umkm_store_id'];

    public function customer(): BelongsTo { return $this->belongsTo(User::class, 'customer_id'); }
    public function store(): BelongsTo { return $this->belongsTo(UmkmStore::class, 'umkm_store_id'); }
    public function messages(): HasMany { return $this->hasMany(ChatMessage::class); }
    public function offers(): HasMany { return $this->hasMany(ChatOffer::class); }
}
