<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UmkmEntitlementEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['umkm_entitlement_id', 'actor_id', 'event_type', 'amount', 'from_status', 'to_status', 'notes', 'metadata', 'idempotency_key', 'created_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'metadata' => 'array', 'created_at' => 'datetime'];
    }

    public function entitlement(): BelongsTo { return $this->belongsTo(UmkmEntitlement::class, 'umkm_entitlement_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
}
