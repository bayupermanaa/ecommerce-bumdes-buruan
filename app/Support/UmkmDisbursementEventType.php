<?php

namespace App\Support;

final class UmkmDisbursementEventType
{
    public const REQUESTED = 'requested';
    public const REVIEW_STARTED = 'review_started';
    public const REVIEW_PASSED = 'review_passed';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';
    public const TRANSFER_RECORDED = 'transfer_recorded';
    public const TRANSFER_VERIFIED = 'transfer_verified';
    public const TRANSFER_FAILED = 'transfer_failed';
    public const PAID = 'paid';

    /** @return list<string> */
    public static function values(): array
    {
        return [self::REQUESTED, self::REVIEW_STARTED, self::REVIEW_PASSED, self::APPROVED, self::REJECTED, self::CANCELLED, self::TRANSFER_RECORDED, self::TRANSFER_VERIFIED, self::TRANSFER_FAILED, self::PAID];
    }
}
