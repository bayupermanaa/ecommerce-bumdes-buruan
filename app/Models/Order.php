<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'order_number',
        'checkout_token',
        'user_id',
        'fulfillment_method',
        'recipient_name',
        'recipient_phone',
        'subtotal',
        'shipping_total',
        'grand_total',
        'payment_status',
        'payment_method',
        'payment_confirmed_at',
        'payment_confirmed_by',
        'order_status',
        'cancelled_at',
        'cancellation_reason',
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
            'shipping_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'payment_confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * A customer may only cancel before any payment processing begins.
     */
    public function canBeCancelled(): bool
    {
        return $this->payment_status === 'unpaid'
            && $this->order_status === 'pending_payment';
    }

    /**
     * A new payment attempt may only start before any payment processing begins.
     */
    public function canSubmitPayment(): bool
    {
        return $this->payment_status === 'unpaid'
            && $this->order_status === 'pending_payment';
    }

    /**
     * Present the persisted order status in customer-facing Indonesian.
     */
    public function orderStatusLabel(): string
    {
        return match ($this->order_status) {
            'pending_payment' => 'Menunggu Pembayaran',
            'in_fulfillment' => 'Dalam Proses',
            'completed' => 'Selesai',
            'cancelled' => 'Dibatalkan',
            default => str($this->order_status)->replace('_', ' ')->title()->toString(),
        };
    }

    /**
     * Present payment status without changing its persisted value.
     */
    public function paymentStatusLabel(): string
    {
        return match ($this->payment_status) {
            'unpaid' => 'Belum Dibayar',
            'pending_confirmation' => 'Menunggu Verifikasi',
            'paid' => 'Sudah Dibayar',
            default => str($this->payment_status)->replace('_', ' ')->title()->toString(),
        };
    }

    /**
     * Get the customer who placed this order.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the store-specific orders created from this checkout.
     */
    public function storeOrders(): HasMany
    {
        return $this->hasMany(StoreOrder::class);
    }

    /**
     * Get all payment attempts, including rejected attempts, for this order.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Get the single successful payment protected by the unique confirmation key.
     */
    public function confirmedPayment(): HasOne
    {
        return $this->hasOne(Payment::class, 'confirmed_order_id');
    }

    /**
     * Get the BUMDes admin who confirmed this order's payment.
     */
    public function paymentConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_confirmed_by');
    }

    /** Get UMKM entitlement sources derived from this order's store splits. */
    public function umkmEntitlements(): HasMany
    {
        return $this->hasMany(UmkmEntitlement::class);
    }
}
