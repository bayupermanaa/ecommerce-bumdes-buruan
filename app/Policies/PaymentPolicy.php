<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use App\Support\PaymentMethod;
use App\Support\PaymentStatus;

class PaymentPolicy
{
    /**
     * Payment records and their evidence are only visible to BUMDes admins.
     */
    public function viewAny(User $user): bool
    {
        return $user->role === 'admin_bumdes';
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->role === 'admin_bumdes';
    }

    public function viewProof(User $user, Payment $payment): bool
    {
        return $this->view($user, $payment);
    }

    /**
     * Only BUMDes admins may confirm a pending payment or retry its completed confirmation safely.
     */
    public function confirm(User $user, Payment $payment): bool
    {
        if ($user->role !== 'admin_bumdes' || ! $payment->order || $payment->order->order_status === 'cancelled') {
            return false;
        }

        if ($payment->payment_method === PaymentMethod::CASH_PICKUP
            && (blank($payment->cash_received_by_name) || $payment->cash_received_at === null)) {
            return false;
        }

        return ($payment->status === PaymentStatus::PENDING_CONFIRMATION
                && $payment->confirmed_order_id === null
                && $payment->order->payment_status !== PaymentStatus::PAID)
            || ($payment->status === PaymentStatus::PAID
                && $payment->confirmed_order_id === $payment->order_id
                && $payment->order->payment_status === PaymentStatus::PAID);
    }

    /**
     * Only BUMDes admins may reject an unprocessed payment attempt.
     */
    public function reject(User $user, Payment $payment): bool
    {
        return $user->role === 'admin_bumdes'
            && $payment->order !== null
            && $payment->status === PaymentStatus::PENDING_CONFIRMATION
            && $payment->confirmed_order_id === null
            && $payment->order->payment_status !== PaymentStatus::PAID
            && ! ($payment->payment_method === PaymentMethod::CASH_PICKUP
                && ($payment->cash_received_by_name !== null || $payment->cash_received_at !== null));
    }

    /**
     * Only admins can record an unrecorded physical receipt for pending cash pickup.
     */
    public function recordCashReceipt(User $user, Payment $payment): bool
    {
        return $user->role === 'admin_bumdes'
            && $payment->order !== null
            && $payment->payment_method === PaymentMethod::CASH_PICKUP
            && $payment->status === PaymentStatus::PENDING_CONFIRMATION
            && $payment->confirmed_order_id === null
            && $payment->order->order_status !== 'cancelled'
            && $payment->order->payment_status !== PaymentStatus::PAID
            && $payment->cash_received_by_name === null
            && $payment->cash_received_at === null;
    }
}
