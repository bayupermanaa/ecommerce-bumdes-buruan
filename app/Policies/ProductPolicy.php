<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    /**
     * Determine whether the user can view products in their store.
     */
    public function viewAny(User $user): bool
    {
        return $user->role === 'admin_bumdes' || $user->umkmStore()->exists();
    }

    /**
     * Only approved stores may add products.
     */
    public function create(User $user): bool
    {
        return $user->umkmStore?->status === 'approved';
    }

    /**
     * Determine whether the user owns the product's store.
     */
    public function view(User $user, Product $product): bool
    {
        return $user->role === 'admin_bumdes' || $user->umkmStore?->id === $product->umkm_store_id;
    }

    /**
     * Determine whether the user can edit their own product.
     */
    public function update(User $user, Product $product): bool
    {
        return $user->umkmStore?->id === $product->umkm_store_id;
    }

    /**
     * Determine whether the user can delete their own product.
     */
    public function delete(User $user, Product $product): bool
    {
        return $user->umkmStore?->id === $product->umkm_store_id;
    }

    /**
     * Determine whether an admin can approve a product.
     */
    public function approve(User $user, Product $product): bool
    {
        return $user->role === 'admin_bumdes';
    }

    /**
     * Determine whether an admin can reject a product.
     */
    public function reject(User $user, Product $product): bool
    {
        return $user->role === 'admin_bumdes';
    }
}
