<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UmkmDisbursementEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['umkm_disbursement_id', 'actor_id', 'event_type', 'from_status', 'to_status', 'notes', 'metadata', 'idempotency_key', 'created_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    public function disbursement(): BelongsTo { return $this->belongsTo(UmkmDisbursement::class, 'umkm_disbursement_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
}
