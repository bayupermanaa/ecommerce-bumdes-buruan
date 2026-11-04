<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StoreOrder extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'umkm_store_id',
        'commission_agreement_id',
        'store_order_number',
        'store_name_snapshot',
        'store_address_snapshot',
        'store_phone_snapshot',
        'subtotal',
        'shipping_amount',
        'total',
        'fulfillment_status',
        'commission_percentage',
        'commission_amount',
        'umkm_amount',
        'commission_status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'commission_percentage' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'umkm_amount' => 'decimal:2',
        ];
    }

    /**
     * Get the parent customer order.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the source store without relying on it for historical display.
     */
    public function umkmStore(): BelongsTo
    {
        return $this->belongsTo(UmkmStore::class);
    }

    /**
     * Get the agreement referenced by this immutable commission snapshot.
     */
    public function commissionAgreement(): BelongsTo
    {
        return $this->belongsTo(CommissionAgreement::class);
    }

    /**
     * Get the item snapshots belonging to this store order.
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Get the append-only fulfillment status history for this store order.
     */
    public function statusLogs(): HasMany
    {
        return $this->hasMany(StoreOrderStatusLog::class);
    }

    /** Get the single financial entitlement source, when created by a future service. */
    public function umkmEntitlement(): HasOne
    {
        return $this->hasOne(UmkmEntitlement::class);
    }

    /**
     * Present the persisted fulfillment status in Indonesian for UMKM views.
     */
    public function fulfillmentStatusLabel(): string
    {
        return self::fulfillmentStatusLabelFor($this->fulfillment_status);
    }

    public static function fulfillmentStatusLabelFor(string $status): string
    {
        return match ($status) {
            'pending_payment' => 'Menunggu Pembayaran',
            'awaiting_processing' => 'Menunggu Diproses',
            'processing' => 'Sedang Diproses',
            'ready_for_pickup' => 'Siap Diambil',
            'completed' => 'Selesai',
            'cancelled' => 'Dibatalkan',
            default => str($status)->replace('_', ' ')->title()->toString(),
        };
    }
}
