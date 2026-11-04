<?php

namespace App\Support;

final class UmkmEntitlementStatus
{
    public const ACCRUED = 'accrued';
    public const ELIGIBLE = 'eligible';
    public const AVAILABLE = 'available';
    public const RESERVED = 'reserved';
    public const HELD = 'held';
    public const SETTLED = 'settled';
    public const REVERSED = 'reversed';

    /** @return list<string> */
    public static function values(): array
    {
        return [self::ACCRUED, self::ELIGIBLE, self::AVAILABLE, self::RESERVED, self::HELD, self::SETTLED, self::REVERSED];
    }
}
