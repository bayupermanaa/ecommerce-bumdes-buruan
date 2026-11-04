<?php
namespace App\Policies;
use App\Models\UmkmRecipientBankAccount;
use App\Models\User;
class UmkmRecipientBankAccountPolicy
{
    public function viewAny(User $user): bool { return in_array($user->role, ['pemilik_umkm', 'admin_bumdes'], true); }
    public function view(User $user, UmkmRecipientBankAccount $account): bool { return $user->role === 'admin_bumdes' || ($user->role === 'pemilik_umkm' && $account->umkmStore->user_id === $user->id); }
    public function manage(User $user, UmkmRecipientBankAccount $account): bool { return $user->role === 'pemilik_umkm' && $account->umkmStore->user_id === $user->id; }
    public function create(User $user): bool { return $user->role === 'pemilik_umkm' && $user->umkmStore()->exists(); }
    public function verify(User $user, UmkmRecipientBankAccount $account): bool { return $user->role === 'admin_bumdes'; }
}
