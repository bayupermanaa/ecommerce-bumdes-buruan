<?php

namespace App\Policies;

use App\Models\StoreOrder;
use App\Models\User;

class StoreOrderPolicy
{
    /**
     * Only UMKM owners with a store can list their own store orders.
     */
    public function viewAny(User $user): bool
    {
        return $user->role === 'pemilik_umkm' && $user->umkmStore()->exists();
    }

    /**
     * A store order is visible only to the owner of its related store.
     */
    public function view(User $user, StoreOrder $storeOrder): bool
    {
        return $this->ownsStoreOrder($user, $storeOrder);
    }

    /**
     * Fulfillment actions use the same ownership rule as viewing.
     */
    public function update(User $user, StoreOrder $storeOrder): bool
    {
        return $this->ownsStoreOrder($user, $storeOrder);
    }

    private function ownsStoreOrder(User $user, StoreOrder $storeOrder): bool
    {
        return $user->role === 'pemilik_umkm'
            && $storeOrder->umkmStore !== null
            && $storeOrder->umkmStore->user_id === $user->id;
    }
}
