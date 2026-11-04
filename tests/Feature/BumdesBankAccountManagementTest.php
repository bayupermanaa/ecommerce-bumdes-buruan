<?php

namespace Tests\Feature;

use App\Models\BumdesBankAccount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentSubmissionService;
use App\Support\PaymentMethod;
use App\Support\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class BumdesBankAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_and_create_a_valid_bank_account_with_leading_zeroes(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);

        $this->actingAs($admin)
            ->get(route('admin.bumdes-bank-accounts.index'))
            ->assertOk()
            ->assertSee('Rekening Resmi BUMDes');

        $this->actingAs($admin)
            ->post(route('admin.bumdes-bank-accounts.store'), $this->accountData(['account_number' => '001234567890']))
            ->assertRedirect(route('admin.bumdes-bank-accounts.index'));

        $this->assertDatabaseHas('bumdes_bank_accounts', [
            'bank_name' => 'Bank BUMDes',
            'account_number' => '001234567890',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);
    }

    public function test_admin_can_edit_unused_account_but_duplicate_or_invalid_data_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $account = $this->account();
        $duplicate = $this->account(['account_number' => '009876543210']);

        $this->actingAs($admin)
            ->patch(route('admin.bumdes-bank-accounts.update', $account), $this->accountData([
                'account_number' => '001111111111',
                'notes' => 'Diperbarui.',
            ]))
            ->assertRedirect(route('admin.bumdes-bank-accounts.index'));

        $this->assertDatabaseHas('bumdes_bank_accounts', ['id' => $account->id, 'account_number' => '001111111111']);

        $this->actingAs($admin)
            ->post(route('admin.bumdes-bank-accounts.store'), $this->accountData([
                'account_number' => $duplicate->account_number,
            ]))
            ->assertSessionHasErrors('account_number');

        $this->actingAs($admin)
            ->post(route('admin.bumdes-bank-accounts.store'), $this->accountData(['account_number' => 'rekening-tidak-valid']))
            ->assertSessionHasErrors('account_number');
    }

    public function test_identity_of_account_with_payment_history_cannot_be_changed(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $account = $this->account();
        $payment = $this->paymentUsing($account);

        $this->actingAs($admin)
            ->patch(route('admin.bumdes-bank-accounts.update', $account), $this->accountData([
                'account_number' => '001111111111',
            ]))
            ->assertSessionHasErrors('account_number');

        $this->assertSame('001234567890', $account->fresh()->account_number);
        $this->assertSame($account->id, $payment->fresh()->bumdes_bank_account_id);
    }

    public function test_admin_can_activate_deactivate_idempotently_and_keep_payment_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin_bumdes']);
        $account = $this->account();
        $payment = $this->paymentUsing($account);

        $this->actingAs($admin)
            ->patch(route('admin.bumdes-bank-accounts.deactivate', $account))
            ->assertRedirect(route('admin.bumdes-bank-accounts.index'));
        $this->assertSame('inactive', $account->fresh()->status);
        $this->assertSame($account->id, $payment->fresh()->bumdes_bank_account_id);

        $this->actingAs($admin)
            ->patch(route('admin.bumdes-bank-accounts.deactivate', $account))
            ->assertSessionHas('status', 'Rekening sudah nonaktif.');

        $this->actingAs($admin)
            ->patch(route('admin.bumdes-bank-accounts.activate', $account))
            ->assertRedirect(route('admin.bumdes-bank-accounts.index'));
        $this->assertSame('active', $account->fresh()->status);
    }

    public function test_inactive_account_is_rejected_for_new_transfer_submission(): void
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $order = $this->order($customer);
        $account = $this->account(['status' => 'inactive']);

        try {
            app(PaymentSubmissionService::class)->submitBankTransfer($order, $customer, [
                'bumdes_bank_account_id' => $account->id,
                'payer_name' => 'Pelanggan Test',
                'transfer_reference' => 'REF-TEST',
                'idempotency_key' => (string) Str::uuid(),
            ], UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf'));
            $this->fail('A non-active account must not accept transfer submissions.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertDatabaseCount('payments', 0);
        }

        // The cash assertion above cannot prove transfer availability, so use the form's active list.
        $this->actingAs($customer)
            ->get(route('orders.payments.create', $order))
            ->assertDontSee($account->account_number);
    }

    public function test_non_admin_roles_are_forbidden_and_guest_is_redirected(): void
    {
        $account = $this->account();

        $this->get(route('admin.bumdes-bank-accounts.index'))
            ->assertRedirect(route('login'));

        foreach (['pelanggan', 'pemilik_umkm', 'kurir', 'kepala_bumdes'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('admin.bumdes-bank-accounts.index'))
                ->assertForbidden();
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->patch(route('admin.bumdes-bank-accounts.deactivate', $account))
                ->assertForbidden();
        }
    }

    /** @param array<string, mixed> $overrides */
    private function account(array $overrides = []): BumdesBankAccount
    {
        return BumdesBankAccount::create(array_merge([
            'bank_name' => 'Bank BUMDes',
            'account_holder_name' => 'BUMDes Desa Buruan',
            'account_number' => '001234567890',
            'status' => 'active',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function accountData(array $overrides = []): array
    {
        return array_merge([
            'bank_name' => 'Bank BUMDes',
            'account_holder_name' => 'BUMDes Desa Buruan',
            'account_number' => '001234567890',
            'status' => 'active',
            'notes' => 'Rekening resmi.',
        ], $overrides);
    }

    private function paymentUsing(BumdesBankAccount $account): Payment
    {
        $order = $this->order(User::factory()->create(['role' => 'pelanggan']));

        return Payment::create([
            'order_id' => $order->id,
            'bumdes_bank_account_id' => $account->id,
            'payment_number' => 'PAY-'.Str::upper(Str::random(12)),
            'payment_method' => PaymentMethod::BANK_TRANSFER,
            'amount' => $order->grand_total,
            'status' => PaymentStatus::PENDING_CONFIRMATION,
            'payer_name' => 'Pelanggan Test',
            'transfer_reference' => 'REF-TEST',
        ]);
    }

    private function order(User $customer): Order
    {
        return Order::create([
            'order_number' => 'ORD-'.Str::upper(Str::random(12)),
            'checkout_token' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'fulfillment_method' => 'pickup',
            'recipient_name' => 'Pelanggan Test',
            'recipient_phone' => '08123456789',
            'subtotal' => '25000.00',
            'shipping_total' => '0.00',
            'grand_total' => '25000.00',
            'payment_status' => PaymentStatus::UNPAID,
            'order_status' => 'pending_payment',
        ]);
    }
}
