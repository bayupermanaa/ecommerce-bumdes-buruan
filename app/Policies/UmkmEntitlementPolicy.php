<?php

namespace App\Policies;

use App\Models\UmkmEntitlement;
use App\Models\User;
use App\Support\UmkmEntitlementStatus;

class UmkmEntitlementPolicy
{
    public function viewAny(User $user): bool { return $user->role === 'admin_bumdes'; }
    public function view(User $user, UmkmEntitlement $entitlement): bool { return $user->role === 'admin_bumdes'; }
    public function reconcile(User $user, UmkmEntitlement $entitlement): bool
    {
        return $user->role === 'admin_bumdes' && in_array($entitlement->status, [UmkmEntitlementStatus::ELIGIBLE, UmkmEntitlementStatus::AVAILABLE], true);
    }
}
