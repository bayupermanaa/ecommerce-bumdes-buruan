<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    use HasFactory;

    protected $fillable = ['chat_conversation_id', 'sender_id', 'body', 'image_path', 'read_at'];

    protected function casts(): array { return ['read_at' => 'datetime']; }
    public function conversation(): BelongsTo { return $this->belongsTo(ChatConversation::class, 'chat_conversation_id'); }
    public function sender(): BelongsTo { return $this->belongsTo(User::class, 'sender_id'); }
}
