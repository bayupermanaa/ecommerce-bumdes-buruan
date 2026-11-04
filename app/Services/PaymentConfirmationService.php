<?php

namespace App\Services;

use App\Models\BumdesBankAccount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentLog;
use App\Models\User;
use App\Support\PaymentMethod;
use App\Support\PaymentStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentConfirmationService
{
    public function __construct(
        private readonly StoreOrderFulfillmentService $fulfillmentService,
        private readonly UmkmEntitlementService $entitlementService,
    ) {
    }

    /**
     * Confirm a payment exactly once and atomically activate its store fulfillment flows.
     */
    public function confirm(Payment $payment, User $admin): Payment
    {
        $this->ensureAdmin($admin);

        return DB::transaction(function () use ($payment, $admin): Payment {
            [$order, $lockedPayment] = $this->lockOrderAndPayment($payment);

            if ($this->isConfirmedRetry($order, $lockedPayment)) {
                return $lockedPayment;
            }

            $this->ensurePendingConfirmation($lockedPayment);
            $this->ensureOrderCanBePaid($order);
            $this->ensureNoSuccessfulPayment($order, $lockedPayment);
            $this->ensureAmountMatchesOrder($order, $lockedPayment);
            $this->ensureMethodMatchesOrder($order, $lockedPayment);
            $this->ensureMethodDetails($lockedPayment);

            $confirmedAt = now();
            $lockedPayment->forceFill([
                'status' => PaymentStatus::PAID,
                'confirmed_order_id' => $order->id,
                'confirmed_by' => $admin->id,
                'confirmed_at' => $confirmedAt,
            ])->save();
            $order->forceFill([
                'payment_status' => PaymentStatus::PAID,
                'payment_method' => $lockedPayment->payment_method,
                'payment_confirmed_at' => $confirmedAt,
                'payment_confirmed_by' => $admin->id,
            ])->save();
            $this->log($lockedPayment, $admin, 'confirmed', PaymentStatus::PENDING_CONFIRMATION, PaymentStatus::PAID);
            $this->fulfillmentService->activateForConfirmedPayment($order, $admin);
            $this->entitlementService->accrueForConfirmedPayment($order, $lockedPayment, $admin);

            return $lockedPayment;
        });
    }

    /**
     * Reject a pending payment attempt without changing stock, commissions, or order totals.
     */
    public function reject(Payment $payment, User $admin, string $reason): Payment
    {
        $this->ensureAdmin($admin);

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'rejection_reason' => 'Alasan penolakan pembayaran wajib diisi.',
            ]);
        }

        return DB::transaction(function () use ($payment, $admin, $reason): Payment {
            [$order, $lockedPayment] = $this->lockOrderAndPayment($payment);

            $this->ensurePendingConfirmation($lockedPayment);

            $rejectedAt = now();
            $lockedPayment->forceFill([
                'status' => PaymentStatus::REJECTED,
                'rejected_by' => $admin->id,
                'rejected_at' => $rejectedAt,
                'rejection_reason' => $reason,
            ])->save();

            // A rejected attempt is retained, but must not permanently block a new submission.
            $this->releaseOrderForNewSubmission($order, $lockedPayment);
            $this->log($lockedPayment, $admin, 'rejected', PaymentStatus::PENDING_CONFIRMATION, PaymentStatus::REJECTED, $reason);

            return $lockedPayment;
        });
    }

    /**
     * Lock the common order first, then the target payment to serialize competing confirmations.
     *
     * @return array{Order, Payment}
     */
    private function lockOrderAndPayment(Payment $payment): array
    {
        $paymentOrderId = Payment::query()
            ->whereKey($payment->id)
            ->value('order_id');

        if (! $paymentOrderId) {
            throw ValidationException::withMessages(['payment' => 'Pembayaran tidak ditemukan.']);
        }

        $order = Order::query()
            ->whereKey($paymentOrderId)
            ->lockForUpdate()
            ->firstOrFail();
        $lockedPayment = Payment::query()
            ->whereKey($payment->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($lockedPayment->order_id !== $order->id) {
            throw ValidationException::withMessages(['payment' => 'Pembayaran tidak sesuai dengan pesanan.']);
        }

        return [$order, $lockedPayment];
    }

    /**
     * A repeated confirmation of this exact successful payment is a safe no-op.
     */
    private function isConfirmedRetry(Order $order, Payment $payment): bool
    {
        return $order->payment_status === PaymentStatus::PAID
            && $payment->status === PaymentStatus::PAID
            && $payment->confirmed_order_id === $order->id;
    }

    private function ensurePendingConfirmation(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::PENDING_CONFIRMATION || $payment->confirmed_order_id !== null) {
            throw ValidationException::withMessages([
                'payment' => 'Pembayaran ini tidak lagi menunggu konfirmasi.',
            ]);
        }
    }

    private function ensureOrderCanBePaid(Order $order): void
    {
        if ($order->order_status === 'cancelled') {
            throw ValidationException::withMessages([
                'order' => 'Pesanan yang dibatalkan tidak dapat dikonfirmasi pembayarannya.',
            ]);
        }

        if ($order->payment_status === PaymentStatus::PAID) {
            throw ValidationException::withMessages([
                'order' => 'Pesanan ini sudah memiliki pembayaran sukses.',
            ]);
        }
    }

    private function ensureNoSuccessfulPayment(Order $order, Payment $payment): void
    {
        $successfulPayment = Payment::query()
            ->where('confirmed_order_id', $order->id)
            ->where('id', '!=', $payment->id)
            ->lockForUpdate()
            ->first();

        if ($successfulPayment) {
            throw ValidationException::withMessages([
                'order' => 'Pesanan ini sudah memiliki pembayaran sukses lain.',
            ]);
        }
    }

    /**
     * Return an order to its pre-submission state only when no active attempt remains.
     */
    private function releaseOrderForNewSubmission(Order $order, Payment $rejectedPayment): void
    {
        $hasActivePayment = Payment::query()
            ->where('order_id', $order->id)
            ->where('id', '!=', $rejectedPayment->id)
            ->where(function ($query): void {
                $query->where('status', PaymentStatus::PENDING_CONFIRMATION)
                    ->orWhereNotNull('confirmed_order_id');
            })
            ->lockForUpdate()
            ->exists();

        if (! $hasActivePayment
            && $order->order_status !== 'cancelled'
            && $order->payment_status !== PaymentStatus::PAID) {
            $order->forceFill([
                'payment_status' => PaymentStatus::UNPAID,
                'payment_method' => null,
            ])->save();
        }
    }

    private function ensureAmountMatchesOrder(Order $order, Payment $payment): void
    {
        if ($this->toMinorUnits((string) $payment->amount) !== $this->toMinorUnits((string) $order->grand_total)) {
            throw ValidationException::withMessages([
                'amount' => 'Nominal pembayaran harus sama persis dengan total pesanan.',
            ]);
        }
    }

    private function ensureMethodMatchesOrder(Order $order, Payment $payment): void
    {
        if (! in_array($payment->payment_method, PaymentMethod::values(), true)) {
            throw ValidationException::withMessages([
                'payment_method' => 'Metode pembayaran tidak valid.',
            ]);
        }

        if ($order->payment_method !== null && $order->payment_method !== $payment->payment_method) {
            throw ValidationException::withMessages([
                'payment_method' => 'Metode pembayaran tidak sesuai dengan metode pada pesanan.',
            ]);
        }
    }

    private function ensureMethodDetails(Payment $payment): void
    {
        if ($payment->payment_method === PaymentMethod::BANK_TRANSFER) {
            $bankAccount = $payment->bumdes_bank_account_id
                ? BumdesBankAccount::query()->find($payment->bumdes_bank_account_id)
                : null;

            if (! $bankAccount
                || blank($payment->payer_name) || blank($payment->transfer_reference)) {
                throw ValidationException::withMessages([
                    'payment' => 'Transfer bank memerlukan rekening BUMDes yang tercatat, nama pengirim, dan referensi transfer.',
                ]);
            }

            return;
        }

        if (blank($payment->cash_received_by_name) || $payment->cash_received_at === null) {
            throw ValidationException::withMessages([
                'payment' => 'Pembayaran tunai memerlukan identitas penerima uang dan waktu penerimaan fisik.',
            ]);
        }
    }

    private function log(
        Payment $payment,
        User $admin,
        string $action,
        string $fromStatus,
        string $toStatus,
        ?string $notes = null,
    ): void {
        PaymentLog::create([
            'payment_id' => $payment->id,
            'actor_id' => $admin->id,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'notes' => $notes,
            'metadata' => [
                'order_id' => $payment->order_id,
                'payment_method' => $payment->payment_method,
            ],
        ]);
    }

    private function ensureAdmin(User $admin): void
    {
        if ($admin->role !== 'admin_bumdes') {
            throw new AuthorizationException('Hanya Admin BUMDes yang dapat memproses pembayaran.');
        }
    }

    /**
     * Compare two fixed-scale money values without floating-point arithmetic.
     */
    private function toMinorUnits(string $amount): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw ValidationException::withMessages([
                'amount' => 'Nominal pembayaran tidak valid.',
            ]);
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }
}
