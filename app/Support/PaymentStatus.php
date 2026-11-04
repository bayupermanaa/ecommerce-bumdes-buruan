<?php

namespace App\Support;

final class PaymentStatus
{
    public const UNPAID = 'unpaid';

    public const PENDING_CONFIRMATION = 'pending_confirmation';

    public const PAID = 'paid';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /**
     * Payment statuses that may be persisted on an order.
     *
     * @return list<string>
     */
    public static function orderValues(): array
    {
        return [self::UNPAID, self::PENDING_CONFIRMATION, self::PAID];
    }

    /**
     * Payment-attempt statuses. Rejected attempts do not make the order rejected.
     *
     * @return list<string>
     */
    public static function attemptValues(): array
    {
        return [self::PENDING_CONFIRMATION, self::PAID, self::REJECTED, self::CANCELLED];
    }
}
