<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UmkmEntitlement extends Model
{
    use HasFactory;

    protected $fillable = ['umkm_store_id', 'order_id', 'store_order_id', 'payment_id', 'source_umkm_amount', 'source_commission_amount', 'source_commission_percentage', 'commission_status_snapshot', 'status', 'accrued_at', 'eligible_at', 'reconciled_at', 'settled_at', 'held_reason', 'reconciliation_notes'];

    protected function casts(): array
    {
        return ['source_umkm_amount' => 'decimal:2', 'source_commission_amount' => 'decimal:2', 'source_commission_percentage' => 'decimal:2', 'accrued_at' => 'datetime', 'eligible_at' => 'datetime', 'reconciled_at' => 'datetime', 'settled_at' => 'datetime'];
    }

    public function umkmStore(): BelongsTo { return $this->belongsTo(UmkmStore::class); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function storeOrder(): BelongsTo { return $this->belongsTo(StoreOrder::class); }
    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
    public function events(): HasMany { return $this->hasMany(UmkmEntitlementEvent::class); }
    public function allocations(): HasMany { return $this->hasMany(UmkmDisbursementAllocation::class); }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'accrued' => 'Terbentuk', 'eligible' => 'Eligible', 'available' => 'Tersedia', 'reserved' => 'Direservasi',
            'held' => 'Ditahan', 'settled' => 'Dibayarkan', 'reversed' => 'Dibalik', default => str($this->status)->replace('_', ' ')->title()->toString(),
        };
    }
}
