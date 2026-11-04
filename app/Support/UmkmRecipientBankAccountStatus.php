<?php

namespace App\Support;

final class UmkmRecipientBankAccountStatus
{
    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    /** @return list<string> */
    public static function values(): array
    {
        return [self::ACTIVE, self::INACTIVE];
    }
}
