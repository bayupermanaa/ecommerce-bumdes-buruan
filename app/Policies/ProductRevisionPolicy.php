<?php

namespace App\Policies;

use App\Models\ProductRevision;
use App\Models\User;

class ProductRevisionPolicy
{
    /**
     * Determine whether the user can view product revisions.
     */
    public function viewAny(User $user): bool
    {
        return $user->role === 'admin_bumdes';
    }

    /**
     * Determine whether the user can review a product revision.
     */
    public function review(User $user, ProductRevision $revision): bool
    {
        return $user->role === 'admin_bumdes';
    }
}
