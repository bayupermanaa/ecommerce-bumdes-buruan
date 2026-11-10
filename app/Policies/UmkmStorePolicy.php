<?php

namespace App\Policies;

use App\Models\UmkmStore;
use App\Models\User;
use App\Support\UserRoles;

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
        return UserRoles::canBuy($user->role)
            && ! $user->umkmStore()->exists();
    }

    /**
     * A public seller application always starts from an ordinary customer account.
     */
    public function apply(User $user): bool
    {
        return $user->role === 'pelanggan' && ! $user->umkmStore()->exists();
    }

    /**
     * A rejected applicant may correct only their own existing application.
     */
    public function resubmit(User $user, UmkmStore $umkmStore): bool
    {
        return $user->role === 'pelanggan'
            && $umkmStore->user_id === $user->id
            && $umkmStore->status === 'rejected';
    }

    /**
     * Determine whether the user owns the requested store.
     */
    public function view(User $user, UmkmStore $umkmStore): bool
    {
        return $user->role === 'admin_bumdes'
            || (UserRoles::isSeller($user->role) && $user->id === $umkmStore->user_id);
    }

    /**
     * Determine whether the user can update their own store.
     */
    public function update(User $user, UmkmStore $umkmStore): bool
    {
        return UserRoles::isSeller($user->role) && $user->id === $umkmStore->user_id;
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
