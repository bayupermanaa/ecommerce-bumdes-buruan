<?php

namespace App\Policies;

use App\Models\UmkmDisbursement;
use App\Models\User;
use App\Support\UserRoles;

class UmkmDisbursementPolicy
{
    public function viewAny(User $user): bool
    {
        return UserRoles::isSeller($user->role) && $user->umkmStore()->exists();
    }

    public function view(User $user, UmkmDisbursement $disbursement): bool
    {
        return UserRoles::isSeller($user->role)
            && $disbursement->umkmStore->user_id === $user->id;
    }

    public function request(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function review(User $user, UmkmDisbursement $disbursement): bool
    {
        return $user->role === 'admin_bumdes';
    }

    public function decide(User $user, UmkmDisbursement $disbursement): bool
    {
        return $user->role === 'kepala_bumdes';
    }
    public function transfer(User $user, UmkmDisbursement $disbursement): bool { return $user->role === 'admin_bumdes'; }
    public function cancelRequested(User $user, UmkmDisbursement $disbursement): bool { return $this->view($user, $disbursement); }
    public function recordTransferFailure(User $user, UmkmDisbursement $disbursement): bool { return $user->role === 'admin_bumdes'; }
    public function confirmTransferFailure(User $user, UmkmDisbursement $disbursement): bool { return $user->role === 'admin_bumdes' && $disbursement->transfer_failure_recorded_by !== $user->id; }
    public function viewTransferFailureProof(User $user, UmkmDisbursement $disbursement): bool { return $user->role === 'admin_bumdes'; }
}
