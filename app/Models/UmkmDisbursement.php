<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UmkmDisbursement extends Model
{
    use HasFactory;

    protected $fillable = ['umkm_store_id', 'recipient_bank_account_id', 'recipient_bank_name', 'recipient_account_holder_name', 'recipient_account_number', 'requested_amount', 'status', 'active_request_lock', 'requested_by', 'reviewed_by', 'approved_by', 'paid_by', 'transfer_recorded_by', 'transfer_verified_by', 'requested_at', 'reviewed_at', 'approved_at', 'paid_at', 'rejection_reason', 'review_notes', 'transfer_reference', 'transfer_amount', 'transfer_proof_path', 'transfer_recorded_at', 'transfer_verified_at', 'transfer_failure_reason', 'transfer_failure_proof_path', 'transfer_failure_recorded_by', 'transfer_failure_verified_by', 'transfer_failure_recorded_at', 'transfer_failure_verified_at', 'idempotency_key'];

    protected function casts(): array
    {
        return ['requested_amount' => 'decimal:2', 'transfer_amount' => 'decimal:2', 'requested_at' => 'datetime', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'paid_at' => 'datetime', 'transfer_recorded_at' => 'datetime', 'transfer_verified_at' => 'datetime', 'transfer_failure_recorded_at' => 'datetime', 'transfer_failure_verified_at' => 'datetime'];
    }

    public function umkmStore(): BelongsTo { return $this->belongsTo(UmkmStore::class); }
    public function recipientBankAccount(): BelongsTo { return $this->belongsTo(UmkmRecipientBankAccount::class, 'recipient_bank_account_id'); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function paidBy(): BelongsTo { return $this->belongsTo(User::class, 'paid_by'); }
    public function transferRecordedBy(): BelongsTo { return $this->belongsTo(User::class, 'transfer_recorded_by'); }
    public function transferVerifiedBy(): BelongsTo { return $this->belongsTo(User::class, 'transfer_verified_by'); }
    public function transferFailureRecordedBy(): BelongsTo { return $this->belongsTo(User::class, 'transfer_failure_recorded_by'); }
    public function transferFailureVerifiedBy(): BelongsTo { return $this->belongsTo(User::class, 'transfer_failure_verified_by'); }
    public function allocations(): HasMany { return $this->hasMany(UmkmDisbursementAllocation::class); }
    public function events(): HasMany { return $this->hasMany(UmkmDisbursementEvent::class); }
}
