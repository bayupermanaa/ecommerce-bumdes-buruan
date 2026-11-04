<?php

namespace App\Http\Controllers;

use App\Models\BumdesBankAccount;
use App\Models\Order;
use App\Services\PaymentSubmissionService;
use App\Support\PaymentMethod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\View\View;

class PaymentSubmissionController extends Controller
{
    /**
     * Show the customer-only payment submission form for one owned order.
     */
    public function create(Request $request, Order $order): View
    {
        Gate::authorize('view', $order);

        $order->load(['payments' => fn ($query) => $query->latest()]);
        $bankAccounts = BumdesBankAccount::query()
            ->where('status', 'active')
            ->orderBy('bank_name')
            ->orderBy('account_holder_name')
            ->get();

        return view('orders.payment', [
            'order' => $order,
            'bankAccounts' => $bankAccounts,
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    /**
     * Store a pending customer payment attempt; an admin must still confirm it.
     */
    public function store(Request $request, Order $order, PaymentSubmissionService $paymentSubmission): RedirectResponse
    {
        Gate::authorize('view', $order);

        $baseRules = [
            'payment_method' => ['required', Rule::in(PaymentMethod::values())],
            'idempotency_key' => ['required', 'uuid'],
        ];

        if ($request->input('payment_method') === PaymentMethod::BANK_TRANSFER) {
            $validated = $request->validate(array_merge($baseRules, [
                'bumdes_bank_account_id' => ['required', 'integer', 'exists:bumdes_bank_accounts,id'],
                'payer_name' => ['required', 'string', 'max:255'],
                'transfer_reference' => ['required', 'string', 'max:255'],
                // File performs MIME-based validation rather than trusting the filename extension.
                'transfer_proof' => ['required', File::types(['jpg', 'jpeg', 'png', 'pdf'])->max(2048)],
            ]));

            $paymentSubmission->submitBankTransfer(
                $order,
                $request->user(),
                $validated,
                $request->file('transfer_proof'),
            );

            return redirect()
                ->route('orders.show', $order)
                ->with('status', 'Bukti transfer telah diajukan dan menunggu verifikasi Admin BUMDes.');
        }

        $validated = $request->validate($baseRules);
        $paymentSubmission->submitCashPickup($order, $request->user(), $validated['idempotency_key']);

        return redirect()
            ->route('orders.show', $order)
            ->with('status', 'Pilihan pembayaran tunai dicatat. Bayarkan kepada petugas BUMDes saat mengambil pesanan.');
    }
}
