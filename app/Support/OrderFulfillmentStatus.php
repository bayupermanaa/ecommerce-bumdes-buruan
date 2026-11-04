<?php

namespace App\Support;

final class OrderFulfillmentStatus
{
    public const PENDING_PAYMENT = 'pending_payment';

    public const IN_FULFILLMENT = 'in_fulfillment';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::PENDING_PAYMENT,
            self::IN_FULFILLMENT,
            self::COMPLETED,
            self::CANCELLED,
        ];
    }
}
