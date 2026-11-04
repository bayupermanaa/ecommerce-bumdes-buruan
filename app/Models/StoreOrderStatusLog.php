<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreOrderStatusLog extends Model
{
    use HasFactory;

    /**
     * Fulfillment logs are append-only records with no updated_at column.
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'store_order_id',
        'actor_id',
        'from_status',
        'to_status',
        'notes',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function storeOrder(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
