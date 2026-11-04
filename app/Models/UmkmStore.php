<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UmkmStore extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'address',
        'phone',
        'logo',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    /**
     * Get the owner of the store.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the products owned by this store.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Get store-specific order records that reference this store.
     */
    public function storeOrders(): HasMany
    {
        return $this->hasMany(StoreOrder::class);
    }

    /**
     * Get the immutable commission agreement history for this store.
     */
    public function commissionAgreements(): HasMany
    {
        return $this->hasMany(CommissionAgreement::class);
    }

    /** Get recipient bank accounts controlled by this UMKM. */
    public function recipientBankAccounts(): HasMany
    {
        return $this->hasMany(UmkmRecipientBankAccount::class);
    }

    /** Get immutable entitlement sources for this UMKM. */
    public function entitlements(): HasMany
    {
        return $this->hasMany(UmkmEntitlement::class);
    }

    /** Get historical disbursement requests for this UMKM. */
    public function disbursements(): HasMany
    {
        return $this->hasMany(UmkmDisbursement::class);
    }
}
