<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
    'name',
    'email',
    'role',
    'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the single UMKM store owned by the user.
     */
    public function umkmStore(): HasOne
    {
        return $this->hasOne(UmkmStore::class);
    }

    /**
     * Get the single persisted cart owned by the customer.
     */
    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }

    /**
     * Get customer orders while preserving each order's historical snapshots.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get commission agreements recorded by this administrator.
     */
    public function createdCommissionAgreements(): HasMany
    {
        return $this->hasMany(CommissionAgreement::class, 'created_by');
    }

    /**
     * Get official bank accounts recorded by this user.
     */
    public function createdBumdesBankAccounts(): HasMany
    {
        return $this->hasMany(BumdesBankAccount::class, 'created_by');
    }

    /**
     * Get payment attempts confirmed by this user.
     */
    public function confirmedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'confirmed_by');
    }

    /**
     * Get payment attempts rejected by this user.
     */
    public function rejectedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'rejected_by');
    }

    /**
     * Get payment audit events attributed to this user.
     */
    public function paymentLogs(): HasMany
    {
        return $this->hasMany(PaymentLog::class, 'actor_id');
    }

    /**
     * Get fulfillment status changes performed by this UMKM owner.
     */
    public function storeOrderStatusLogs(): HasMany
    {
        return $this->hasMany(StoreOrderStatusLog::class, 'actor_id');
    }

    public function verifiedUmkmRecipientBankAccounts(): HasMany
    {
        return $this->hasMany(UmkmRecipientBankAccount::class, 'verified_by');
    }

    public function requestedUmkmDisbursements(): HasMany
    {
        return $this->hasMany(UmkmDisbursement::class, 'requested_by');
    }

    public function reviewedUmkmDisbursements(): HasMany
    {
        return $this->hasMany(UmkmDisbursement::class, 'reviewed_by');
    }

    public function approvedUmkmDisbursements(): HasMany
    {
        return $this->hasMany(UmkmDisbursement::class, 'approved_by');
    }
}
