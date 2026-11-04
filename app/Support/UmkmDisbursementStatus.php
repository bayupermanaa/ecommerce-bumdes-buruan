<?php

namespace App\Support;

final class UmkmDisbursementStatus
{
    public const REQUESTED = 'requested';
    public const UNDER_REVIEW = 'under_review';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';
    public const TRANSFER_FAILED = 'transfer_failed';
    public const PAID = 'paid';

    /** @return list<string> */
    public static function values(): array
    {
        return [self::REQUESTED, self::UNDER_REVIEW, self::APPROVED, self::REJECTED, self::CANCELLED, self::TRANSFER_FAILED, self::PAID];
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return [self::REQUESTED, self::UNDER_REVIEW, self::APPROVED, self::TRANSFER_FAILED];
    }
}
