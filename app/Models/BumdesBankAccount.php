<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BumdesBankAccount extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'bank_name',
        'account_holder_name',
        'account_number',
        'status',
        'notes',
        'created_by',
    ];

    /**
     * Get the admin who recorded this official account.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get payment attempts submitted to this account.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
