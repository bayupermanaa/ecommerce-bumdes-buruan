<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UmkmRecipientBankAccount extends Model
{
    use HasFactory;

    protected $fillable = ['umkm_store_id', 'bank_name', 'account_holder_name', 'account_number', 'status', 'active_store_lock', 'verified_at', 'verified_by', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    public function umkmStore(): BelongsTo { return $this->belongsTo(UmkmStore::class); }
    public function verifiedBy(): BelongsTo { return $this->belongsTo(User::class, 'verified_by'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function updater(): BelongsTo { return $this->belongsTo(User::class, 'updated_by'); }
    public function disbursements(): HasMany { return $this->hasMany(UmkmDisbursement::class, 'recipient_bank_account_id'); }
}
