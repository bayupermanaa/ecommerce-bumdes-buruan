<?php
namespace App\Policies;
use App\Models\UmkmRecipientBankAccount;
use App\Models\User;
use App\Support\UserRoles;
class UmkmRecipientBankAccountPolicy
{
    public function viewAny(User $user): bool { return UserRoles::isSeller($user->role) || $user->role === 'admin_bumdes'; }
    public function view(User $user, UmkmRecipientBankAccount $account): bool { return $user->role === 'admin_bumdes' || (UserRoles::isSeller($user->role) && $account->umkmStore->user_id === $user->id); }
    public function manage(User $user, UmkmRecipientBankAccount $account): bool { return UserRoles::isSeller($user->role) && $account->umkmStore->user_id === $user->id; }
    public function create(User $user): bool { return UserRoles::isSeller($user->role) && $user->umkmStore()->exists(); }
    public function verify(User $user, UmkmRecipientBankAccount $account): bool { return $user->role === 'admin_bumdes'; }
}
