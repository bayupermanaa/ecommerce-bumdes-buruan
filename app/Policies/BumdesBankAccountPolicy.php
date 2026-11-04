<?php

namespace App\Policies;

use App\Models\BumdesBankAccount;
use App\Models\User;

class BumdesBankAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === 'admin_bumdes';
    }

    public function create(User $user): bool
    {
        return $user->role === 'admin_bumdes';
    }

    public function update(User $user, BumdesBankAccount $account): bool
    {
        return $user->role === 'admin_bumdes';
    }

    public function activate(User $user, BumdesBankAccount $account): bool
    {
        return $user->role === 'admin_bumdes';
    }

    public function deactivate(User $user, BumdesBankAccount $account): bool
    {
        return $user->role === 'admin_bumdes';
    }
}
