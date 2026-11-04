<?php

namespace App\Support;

final class UmkmEntitlementEventType
{
    public const ACCRUED = 'accrued';
    public const ELIGIBLE = 'eligible';
    public const RECONCILED = 'reconciled';
    public const HELD = 'held';
    public const RELEASED = 'released';
    public const RESERVED = 'reserved';
    public const RESERVATION_RELEASED = 'reservation_released';
    public const SETTLED = 'settled';
    public const REVERSED = 'reversed';

    /** @return list<string> */
    public static function values(): array
    {
        return [self::ACCRUED, self::ELIGIBLE, self::RECONCILED, self::HELD, self::RELEASED, self::RESERVED, self::RESERVATION_RELEASED, self::SETTLED, self::REVERSED];
    }
}
