<?php

namespace App\Support;

final class PaymentMethod
{
    public const BANK_TRANSFER = 'bank_transfer';

    public const CASH_PICKUP = 'cash_pickup';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [self::BANK_TRANSFER, self::CASH_PICKUP];
    }
}
