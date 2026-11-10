<?php

namespace App\Support;

final class UserRoles
{
    public const SELLER = 'penjual';
    public const LEGACY_SELLER = 'pemilik_umkm';

    public static function isSeller(?string $role): bool
    {
        return in_array($role, [self::SELLER, self::LEGACY_SELLER], true);
    }

    public static function canBuy(?string $role): bool
    {
        return $role === 'pelanggan' || self::isSeller($role);
    }
}
