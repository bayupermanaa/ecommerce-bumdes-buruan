<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UmkmDisbursementAllocation extends Model
{
    use HasFactory;

    protected $fillable = ['umkm_disbursement_id', 'umkm_entitlement_id', 'amount', 'entitlement_reservation_lock'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function disbursement(): BelongsTo { return $this->belongsTo(UmkmDisbursement::class, 'umkm_disbursement_id'); }
    public function entitlement(): BelongsTo { return $this->belongsTo(UmkmEntitlement::class, 'umkm_entitlement_id'); }
}
