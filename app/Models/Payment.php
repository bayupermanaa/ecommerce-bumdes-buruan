<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'confirmed_order_id',
        'bumdes_bank_account_id',
        'payment_number',
        'idempotency_key',
        'payment_method',
        'amount',
        'status',
        'payer_name',
        'transfer_reference',
        'transfer_proof_path',
        'cash_received_by_name',
        'cash_received_at',
        'confirmed_by',
        'confirmed_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'cash_received_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /**
     * Get the order whose total this payment attempt represents.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the order reserved by this successful payment, if confirmed.
     */
    public function confirmedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'confirmed_order_id');
    }

    /**
     * Get the receiving BUMDes account for a bank transfer.
     */
    public function bumdesBankAccount(): BelongsTo
    {
        return $this->belongsTo(BumdesBankAccount::class);
    }

    /**
     * Get the BUMDes admin who confirmed the payment.
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * Get the BUMDes admin who rejected the payment attempt.
     */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * Get the chronological audit entries for this payment attempt.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(PaymentLog::class);
    }

    /** Get entitlement sources that explicitly reference this confirmed payment. */
    public function umkmEntitlements(): HasMany
    {
        return $this->hasMany(UmkmEntitlement::class);
    }
}
