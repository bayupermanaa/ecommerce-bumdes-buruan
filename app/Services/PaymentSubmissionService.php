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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentSubmissionService
{
    private const PROOF_DISK = 'local';

    private const PROOF_DIRECTORY = 'payment-proofs';

    /**
     * Submit a bank-transfer proof without treating it as a successful payment.
     *
     * @param array{bumdes_bank_account_id: int, payer_name: string, transfer_reference: string, idempotency_key: string} $data
     */
    public function submitBankTransfer(Order $order, User $customer, array $data, UploadedFile $proof): Payment
    {
        $this->ensureCustomerOwnsOrder($order, $customer);
        $this->ensureProofIsValid($proof);

        $storedPath = null;

        try {
            return DB::transaction(function () use ($order, $customer, $data, $proof, &$storedPath): Payment {
                $lockedOrder = $this->lockOrder($order);
                $this->ensureCustomerOwnsOrder($lockedOrder, $customer);

                if ($existing = $this->idempotentPayment($lockedOrder, $data['idempotency_key'])) {
                    return $existing;
                }

                $this->ensureCanSubmit($lockedOrder);
                $this->ensureNoActivePayment($lockedOrder);

                $account = BumdesBankAccount::query()
                    ->whereKey($data['bumdes_bank_account_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $account || $account->status !== 'active') {
                    throw ValidationException::withMessages([
                        'bumdes_bank_account_id' => 'Rekening BUMDes yang dipilih tidak aktif.',
                    ]);
                }

                $storedPath = $this->storeProof($proof);

                return $this->createPendingPayment($lockedOrder, $customer, [
                    'idempotency_key' => $data['idempotency_key'],
                    'payment_method' => PaymentMethod::BANK_TRANSFER,
                    'bumdes_bank_account_id' => $account->id,
                    'payer_name' => trim($data['payer_name']),
                    'transfer_reference' => trim($data['transfer_reference']),
                    'transfer_proof_path' => $storedPath,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            $this->deleteProof($storedPath);

            return $this->findIdempotentPaymentOrFail($order, $data['idempotency_key']);
        } catch (Throwable $exception) {
            $this->deleteProof($storedPath);

            throw $exception;
        }
    }

    /**
     * Record a cash-at-pickup payment attempt. Receipt details remain empty until staff receives cash.
     */
    public function submitCashPickup(Order $order, User $customer, string $idempotencyKey): Payment
    {
        $this->ensureCustomerOwnsOrder($order, $customer);

        try {
            return DB::transaction(function () use ($order, $customer, $idempotencyKey): Payment {
                $lockedOrder = $this->lockOrder($order);
                $this->ensureCustomerOwnsOrder($lockedOrder, $customer);

                if ($existing = $this->idempotentPayment($lockedOrder, $idempotencyKey)) {
                    return $existing;
                }

                $this->ensureCanSubmit($lockedOrder);
                $this->ensureNoActivePayment($lockedOrder);

                return $this->createPendingPayment($lockedOrder, $customer, [
                    'idempotency_key' => $idempotencyKey,
                    'payment_method' => PaymentMethod::CASH_PICKUP,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->findIdempotentPaymentOrFail($order, $idempotencyKey);
        }
    }

    private function lockOrder(Order $order): Order
    {
        return Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
    }

    private function ensureCustomerOwnsOrder(Order $order, User $customer): void
    {
        if (! \App\Support\UserRoles::canBuy($customer->role)
            || $order->user_id !== $customer->id) {
            throw new AuthorizationException('Anda tidak berwenang mengajukan pembayaran untuk pesanan ini.');
        }
    }

    private function ensureCanSubmit(Order $order): void
    {
        if ($order->order_status === 'cancelled') {
            throw ValidationException::withMessages(['order' => 'Pesanan yang dibatalkan tidak dapat dibayar.']);
        }

        if ($order->payment_status === PaymentStatus::PAID) {
            throw ValidationException::withMessages(['order' => 'Pesanan ini sudah dibayar.']);
        }

        if ($order->payment_status !== PaymentStatus::UNPAID || $order->order_status !== 'pending_payment') {
            throw ValidationException::withMessages(['order' => 'Pesanan ini tidak dapat menerima pengajuan pembayaran baru.']);
        }
    }

    private function ensureNoActivePayment(Order $order): void
    {
        $activePayment = Payment::query()
            ->where('order_id', $order->id)
            ->where(function ($query): void {
                $query->where('status', PaymentStatus::PENDING_CONFIRMATION)
                    ->orWhereNotNull('confirmed_order_id');
            })
            ->lockForUpdate()
            ->exists();

        if ($activePayment) {
            throw ValidationException::withMessages(['order' => 'Pesanan ini sudah memiliki pengajuan pembayaran aktif.']);
        }
    }

    private function idempotentPayment(Order $order, string $idempotencyKey): ?Payment
    {
        $payment = Payment::query()
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if (! $payment) {
            return null;
        }

        if ($payment->order_id !== $order->id) {
            throw ValidationException::withMessages(['idempotency_key' => 'Token pengajuan tidak valid.']);
        }

        return $payment;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createPendingPayment(Order $order, User $customer, array $attributes): Payment
    {
        $payment = Payment::create(array_merge($attributes, [
            'order_id' => $order->id,
            'payment_number' => 'PAY-'.Str::upper((string) Str::uuid()),
            'amount' => $order->grand_total,
            'status' => PaymentStatus::PENDING_CONFIRMATION,
        ]));

        $order->forceFill([
            'payment_status' => PaymentStatus::PENDING_CONFIRMATION,
            'payment_method' => $payment->payment_method,
        ])->save();

        PaymentLog::create([
            'payment_id' => $payment->id,
            'actor_id' => $customer->id,
            'action' => 'submitted',
            'from_status' => null,
            'to_status' => PaymentStatus::PENDING_CONFIRMATION,
            'metadata' => [
                'order_id' => $order->id,
                'payment_method' => $payment->payment_method,
            ],
        ]);

        return $payment;
    }

    private function ensureProofIsValid(UploadedFile $proof): void
    {
        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
        ];

        $mimeType = $proof->getMimeType();

        if (! $proof->isValid() || ! isset($allowedMimes[$mimeType]) || $proof->getSize() > 2 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'transfer_proof' => 'Bukti transfer harus berupa JPG, PNG, atau PDF berukuran maksimal 2 MB.',
            ]);
        }
    }

    private function storeProof(UploadedFile $proof): string
    {
        $extension = match ($proof->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
        };
        $path = self::PROOF_DIRECTORY.'/'.Str::uuid().'.'.$extension;

        if (! Storage::disk(self::PROOF_DISK)->put($path, $proof->getContent())) {
            throw ValidationException::withMessages([
                'transfer_proof' => 'Bukti transfer tidak dapat disimpan. Silakan coba lagi.',
            ]);
        }

        return $path;
    }

    private function deleteProof(?string $path): void
    {
        if ($path) {
            Storage::disk(self::PROOF_DISK)->delete($path);
        }
    }

    private function findIdempotentPaymentOrFail(Order $order, string $idempotencyKey): Payment
    {
        $payment = Payment::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if (! $payment || $payment->order_id !== $order->id) {
            throw ValidationException::withMessages(['payment' => 'Pengajuan pembayaran tidak dapat diproses.']);
        }

        return $payment;
    }
}
