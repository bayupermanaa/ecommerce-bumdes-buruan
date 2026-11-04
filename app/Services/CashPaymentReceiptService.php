<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentLog;
use App\Models\User;
use App\Support\PaymentMethod;
use App\Support\PaymentStatus;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashPaymentReceiptService
{
    /**
     * Record physical cash receipt without confirming the payment itself.
     */
    public function record(Payment $payment, User $admin, string $receivedByName, string $receivedAt): Payment
    {
        $this->ensureAdmin($admin);
        $receivedByName = trim($receivedByName);

        if ($receivedByName === '') {
            throw ValidationException::withMessages([
                'cash_received_by_name' => 'Nama petugas penerima uang wajib diisi.',
            ]);
        }

        $receivedAtDate = Carbon::parse($receivedAt);

        if ($receivedAtDate->isFuture()) {
            throw ValidationException::withMessages([
                'cash_received_at' => 'Waktu penerimaan tunai tidak boleh berada di masa depan.',
            ]);
        }

        return DB::transaction(function () use ($payment, $admin, $receivedByName, $receivedAtDate): Payment {
            [$order, $lockedPayment] = $this->lockOrderAndPayment($payment);

            $this->ensureEligibleCashPayment($order, $lockedPayment);
            $this->ensureAmountMatchesOrder($order, $lockedPayment);

            $lockedPayment->forceFill([
                'cash_received_by_name' => $receivedByName,
                'cash_received_at' => $receivedAtDate,
            ])->save();

            PaymentLog::create([
                'payment_id' => $lockedPayment->id,
                'actor_id' => $admin->id,
                'action' => 'cash_received',
                'from_status' => PaymentStatus::PENDING_CONFIRMATION,
                'to_status' => PaymentStatus::PENDING_CONFIRMATION,
                'metadata' => [
                    'order_id' => $order->id,
                    'payment_method' => PaymentMethod::CASH_PICKUP,
                    'amount' => (string) $lockedPayment->amount,
                ],
            ]);

            return $lockedPayment;
        });
    }

    /**
     * Lock the shared order before its payment to match the confirmation service ordering.
     *
     * @return array{Order, Payment}
     */
    private function lockOrderAndPayment(Payment $payment): array
    {
        $paymentOrderId = Payment::query()->whereKey($payment->id)->value('order_id');

        if (! $paymentOrderId) {
            throw ValidationException::withMessages(['payment' => 'Pembayaran tidak ditemukan.']);
        }

        $order = Order::query()->whereKey($paymentOrderId)->lockForUpdate()->firstOrFail();
        $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

        if ($lockedPayment->order_id !== $order->id) {
            throw ValidationException::withMessages(['payment' => 'Pembayaran tidak sesuai dengan pesanan.']);
        }

        return [$order, $lockedPayment];
    }

    private function ensureEligibleCashPayment(Order $order, Payment $payment): void
    {
        if ($payment->payment_method !== PaymentMethod::CASH_PICKUP) {
            throw ValidationException::withMessages(['payment' => 'Penerimaan fisik hanya berlaku untuk pembayaran tunai saat pickup.']);
        }

        if ($payment->status !== PaymentStatus::PENDING_CONFIRMATION || $payment->confirmed_order_id !== null) {
            throw ValidationException::withMessages(['payment' => 'Pembayaran ini tidak lagi menunggu penerimaan tunai.']);
        }

        if ($order->order_status === 'cancelled' || $order->payment_status === PaymentStatus::PAID) {
            throw ValidationException::withMessages(['order' => 'Pesanan ini tidak dapat menerima pencatatan tunai.']);
        }

        if ($payment->cash_received_by_name !== null || $payment->cash_received_at !== null) {
            throw ValidationException::withMessages(['payment' => 'Penerimaan tunai untuk pembayaran ini sudah dicatat.']);
        }
    }

    private function ensureAmountMatchesOrder(Order $order, Payment $payment): void
    {
        if ($this->toMinorUnits((string) $payment->amount) !== $this->toMinorUnits((string) $order->grand_total)) {
            throw ValidationException::withMessages(['amount' => 'Nominal pembayaran harus sama persis dengan total pesanan.']);
        }
    }

    private function ensureAdmin(User $admin): void
    {
        if ($admin->role !== 'admin_bumdes') {
            throw new AuthorizationException('Hanya Admin BUMDes yang dapat mencatat penerimaan tunai.');
        }
    }

    private function toMinorUnits(string $amount): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw ValidationException::withMessages(['amount' => 'Nominal pembayaran tidak valid.']);
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }
}
