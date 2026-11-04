<?php

namespace App\Support;

final class StoreOrderFulfillmentStatus
{
    public const PENDING_PAYMENT = 'pending_payment';

    public const AWAITING_PROCESSING = 'awaiting_processing';

    public const PROCESSING = 'processing';

    public const READY_FOR_PICKUP = 'ready_for_pickup';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::PENDING_PAYMENT,
            self::AWAITING_PROCESSING,
            self::PROCESSING,
            self::READY_FOR_PICKUP,
            self::COMPLETED,
            self::CANCELLED,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function transitions(): array
    {
        return [
            self::AWAITING_PROCESSING => [self::PROCESSING],
            self::PROCESSING => [self::READY_FOR_PICKUP],
            self::READY_FOR_PICKUP => [self::COMPLETED],
        ];
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::transitions()[$from] ?? [], true);
    }

    public static function isPostPayment(string $status): bool
    {
        return in_array($status, [
            self::AWAITING_PROCESSING,
            self::PROCESSING,
            self::READY_FOR_PICKUP,
            self::COMPLETED,
        ], true);
    }
}
