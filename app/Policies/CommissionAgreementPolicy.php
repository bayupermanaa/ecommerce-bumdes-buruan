<?php

namespace App\Policies;

use App\Models\CommissionAgreement;
use App\Models\User;
use App\Support\UserRoles;

class CommissionAgreementPolicy
{
    /**
     * Only BUMDes admins administer commission agreements.
     */
    public function viewAny(User $user): bool
    {
        return $user->role === 'admin_bumdes';
    }

    /**
     * An admin may create a historical agreement record.
     */
    public function create(User $user): bool
    {
        return $user->role === 'admin_bumdes';
    }

    /**
     * Admins may view all agreements; UMKM owners may only view their store's records.
     */
    public function view(User $user, CommissionAgreement $agreement): bool
    {
        return $user->role === 'admin_bumdes'
            || (UserRoles::isSeller($user->role) && $agreement->umkmStore->user_id === $user->id);
    }

    /**
     * Only admins may make a draft agreement effective.
     */
    public function activate(User $user, CommissionAgreement $agreement): bool
    {
        return $user->role === 'admin_bumdes';
    }

    /**
     * Only admins may cancel a draft agreement.
     */
    public function cancel(User $user, CommissionAgreement $agreement): bool
    {
        return $user->role === 'admin_bumdes';
    }

    /**
     * Only admins may close an active agreement.
     */
    public function end(User $user, CommissionAgreement $agreement): bool
    {
        return $user->role === 'admin_bumdes';
    }
}
