<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'sku',
        'name',
        'slug',
        'description',
        'image_path',
        'price',
        'stock',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'stock' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * Get the category for this product.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function activeVariants(): HasMany
    {
        return $this->variants()->where('is_active', true);
    }

    /**
     * Get the UMKM store that owns this product.
     */
    public function umkmStore(): BelongsTo
    {
        return $this->belongsTo(UmkmStore::class);
    }

    /**
     * Get the proposed changes submitted for this product.
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(ProductRevision::class);
    }

    /**
     * Get the single active revision, if the owner has one awaiting review.
     */
    public function pendingRevision(): HasOne
    {
        return $this->hasOne(ProductRevision::class)->where('status', 'pending');
    }

    /**
     * Get the most recently submitted revision for owner-facing status information.
     */
    public function latestRevision(): HasOne
    {
        return $this->hasOne(ProductRevision::class)->latestOfMany();
    }

    /**
     * Get cart entries that currently reference this product.
     */
    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * Get historical order item snapshots that reference this product.
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
