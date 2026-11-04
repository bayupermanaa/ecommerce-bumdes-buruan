<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionAgreement extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'umkm_store_id',
        'idempotency_key',
        'commission_percentage',
        'effective_start_date',
        'effective_end_date',
        'status',
        'notes',
        'created_by',
        'activated_at',
        'ended_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'commission_percentage' => 'decimal:2',
            'effective_start_date' => 'date',
            'effective_end_date' => 'date',
            'activated_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * Get the UMKM store covered by this agreement.
     */
    public function umkmStore(): BelongsTo
    {
        return $this->belongsTo(UmkmStore::class);
    }

    /**
     * Get the admin who recorded the agreement.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Customer-facing Indonesian status without altering persisted values.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            'draft' => 'Draft',
            'active' => 'Aktif',
            'ended' => 'Berakhir',
            'cancelled' => 'Dibatalkan',
            default => str($this->status)->replace('_', ' ')->title()->toString(),
        };
    }
}
