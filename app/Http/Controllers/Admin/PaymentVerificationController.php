<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectPaymentRequest;
use App\Http\Requests\Admin\RecordCashPaymentReceiptRequest;
use App\Models\Payment;
use App\Services\CashPaymentReceiptService;
use App\Services\PaymentConfirmationService;
use App\Notifications\MarketplaceNotification;
use App\Support\PaymentMethod;
use App\Support\PaymentStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentVerificationController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Payment::class);

        $validated = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', PaymentStatus::attemptValues())],
            'method' => ['nullable', 'in:'.implode(',', PaymentMethod::values())],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $payments = Payment::query()
            ->with(['order.user', 'bumdesBankAccount'])
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['method'] ?? null, fn ($query, $method) => $query->where('payment_method', $method))
            ->when($validated['search'] ?? null, function ($query, $search): void {
                $query->whereHas('order', fn ($orderQuery) => $orderQuery->where('order_number', 'like', '%'.$search.'%'));
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.payments.index', compact('payments', 'validated'));
    }

    public function show(Payment $payment): View
    {
        Gate::authorize('view', $payment);

        $payment->load([
            'order.user',
            'order.storeOrders.items',
            'bumdesBankAccount',
            'confirmedBy',
            'rejectedBy',
            'logs.actor',
        ]);
        $paymentHistory = $payment->order->payments()
            ->with(['bumdesBankAccount', 'rejectedBy', 'confirmedBy'])
            ->latest()
            ->get();

        return view('admin.payments.show', compact('payment', 'paymentHistory'));
    }

    /**
     * Stream only this payment's private proof after a strict server-side path check.
     */
    public function proof(Payment $payment): StreamedResponse
    {
        Gate::authorize('viewProof', $payment);

        $path = $payment->transfer_proof_path;

        if (! is_string($path) || ! preg_match('/^payment-proofs\/[0-9a-f-]+\.(jpg|png|pdf)$/', $path, $matches)) {
            abort(404);
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            abort(404);
        }

        $mimeType = match ($matches[1]) {
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'pdf' => 'application/pdf',
        };
        $downloadName = 'bukti-transfer-'.$payment->payment_number.'.'.$matches[1];

        return $disk->response($path, $downloadName, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="'.$downloadName.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function confirm(Payment $payment, PaymentConfirmationService $paymentConfirmation): RedirectResponse
    {
        Gate::authorize('confirm', $payment);

        $paymentConfirmation->confirm($payment, request()->user());
        $payment->load('order.user');
        MarketplaceNotification::sendOnce($payment->order->user, 'Pembayaran diverifikasi', 'Pembayaran pesanan Anda telah diverifikasi Admin BUMDes.', route('orders.show', $payment->order), 'payment.confirmed.'.$payment->id);

        return redirect()
            ->route('admin.payments.show', $payment)
            ->with('status', $payment->payment_method === PaymentMethod::CASH_PICKUP
                ? 'Pembayaran tunai berhasil dikonfirmasi setelah penerimaan fisik tercatat.'
                : 'Pembayaran transfer berhasil dikonfirmasi. Pastikan dana telah diterima di rekening resmi BUMDes sebelum tindakan ini dilakukan.');
    }

    public function reject(RejectPaymentRequest $request, Payment $payment, PaymentConfirmationService $paymentConfirmation): RedirectResponse
    {
        Gate::authorize('reject', $payment);

        $paymentConfirmation->reject($payment, $request->user(), $request->validated('rejection_reason'));
        $payment->load('order.user');
        MarketplaceNotification::sendOnce($payment->order->user, 'Pembayaran ditolak', 'Pengajuan pembayaran pesanan Anda ditolak dan dapat diajukan kembali.', route('orders.show', $payment->order), 'payment.rejected.'.$payment->id);

        return redirect()
            ->route('admin.payments.show', $payment)
            ->with('status', 'Pengajuan pembayaran ditolak. Pelanggan dapat mengajukan pembayaran baru.');
    }

    public function recordCashReceipt(RecordCashPaymentReceiptRequest $request, Payment $payment, CashPaymentReceiptService $cashReceipt): RedirectResponse
    {
        Gate::authorize('recordCashReceipt', $payment);

        $cashReceipt->record(
            $payment,
            $request->user(),
            $request->validated('cash_received_by_name'),
            $request->receivedAtUtc(),
        );

        return redirect()
            ->route('admin.payments.show', $payment)
            ->with('status', 'Penerimaan tunai telah dicatat. Konfirmasikan pembayaran setelah data penerimaan diperiksa kembali.');
    }
}
