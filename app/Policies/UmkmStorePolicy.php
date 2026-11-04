<?php

namespace App\Policies;

use App\Models\UmkmStore;
use App\Models\User;

class UmkmStorePolicy
{
    /**
     * Determine whether an admin can view the UMKM store list.
     */
    public function viewAny(User $user): bool
    {
        return $user->role === 'admin_bumdes';
    }

    /**
     * Determine whether the user can create their one permitted store.
     */
    public function create(User $user): bool
    {
        return ! $user->umkmStore()->exists();
    }

    /**
     * Determine whether the user owns the requested store.
     */
    public function view(User $user, UmkmStore $umkmStore): bool
    {
        return $user->role === 'admin_bumdes' || $user->id === $umkmStore->user_id;
    }

    /**
     * Determine whether the user can update their own store.
     */
    public function update(User $user, UmkmStore $umkmStore): bool
    {
        return $user->id === $umkmStore->user_id;
    }

    /**
     * Determine whether an admin can approve a store.
     */
    public function approve(User $user, UmkmStore $umkmStore): bool
    {
        return $user->role === 'admin_bumdes';
    }

    /**
     * Determine whether an admin can reject a store.
     */
    public function reject(User $user, UmkmStore $umkmStore): bool
    {
        return $user->role === 'admin_bumdes';
    }
}
