<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreOrder;
use App\Models\UmkmDisbursement;
use App\Models\UmkmDisbursementAllocation;
use App\Models\UmkmEntitlement;
use App\Models\User;
use App\Services\UmkmDisbursementApprovalService;
use App\Services\UmkmDisbursementTransferService;
use App\Support\UmkmEntitlementStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UmkmDisbursementApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_review_then_head_approval_is_audited_and_idempotent(): void
    {
        [$disbursement, $entitlement, $admin, $head] = $this->requestedDisbursement();
        $this->actingAs($admin)->get(route('admin.disbursements.index'))->assertOk()->assertSee('Pemeriksaan Pencairan');
        $this->actingAs($head)->get(route('kepala-bumdes.disbursements.index'))->assertOk();

        $this->service()->startReview($disbursement, $admin);
        $this->service()->startReview($disbursement->fresh(), $admin);
        $this->assertSame('under_review', $disbursement->fresh()->status);
        $this->assertDatabaseCount('umkm_disbursement_events', 1);

        try { $this->service()->approve($disbursement->fresh(), $head); $this->fail('Approval before review completion must fail.'); }
        catch (ValidationException) { $this->assertSame('under_review', $disbursement->fresh()->status); }

        $this->service()->completeReview($disbursement->fresh(), $admin);
        $this->service()->completeReview($disbursement->fresh(), $admin);
        $this->service()->approve($disbursement->fresh(), $head);
        $this->service()->approve($disbursement->fresh(), $head);

        $this->assertSame('approved', $disbursement->fresh()->status);
        $this->assertSame(UmkmEntitlementStatus::RESERVED, $entitlement->fresh()->status);
        $this->assertDatabaseCount('umkm_disbursement_events', 3);
        $this->assertDatabaseHas('orders', ['id' => $entitlement->order_id, 'payment_status' => 'paid']);
        $this->assertDatabaseHas('payments', ['id' => $entitlement->payment_id, 'status' => 'paid']);
    }

    public function test_head_rejection_releases_reservation_but_keeps_allocation_history(): void
    {
        [$disbursement, $entitlement, $admin, $head] = $this->requestedDisbursement();
        $this->service()->startReview($disbursement, $admin);
        $this->service()->reject($disbursement->fresh(), $head, 'Data rekening perlu diperbarui.');
        $this->service()->reject($disbursement->fresh(), $head, 'Retry tidak mengubah riwayat.');

        $this->assertSame('rejected', $disbursement->fresh()->status);
        $this->assertNull($disbursement->fresh()->active_request_lock);
        $this->assertSame(UmkmEntitlementStatus::AVAILABLE, $entitlement->fresh()->status);
        $this->assertDatabaseCount('umkm_disbursement_allocations', 1);
        $this->assertDatabaseHas('umkm_disbursement_allocations', ['umkm_disbursement_id' => $disbursement->id, 'entitlement_reservation_lock' => null]);
        $this->assertDatabaseCount('umkm_disbursement_events', 2);
    }

    public function test_roles_cannot_perform_each_others_actions(): void
    {
        [$disbursement, , $admin, $head] = $this->requestedDisbursement();
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $this->actingAs($customer)->get(route('admin.disbursements.index'))->assertForbidden();
        $this->actingAs($admin)->patch(route('kepala-bumdes.disbursements.approve', $disbursement))->assertForbidden();
        $this->actingAs($head)->patch(route('admin.disbursements.start-review', $disbursement))->assertForbidden();
    }

    public function test_two_different_admins_record_and_verify_private_transfer(): void
    {
        Storage::fake('local');
        [$disbursement, $entitlement, $admin, $head] = $this->requestedDisbursement();
        $this->service()->startReview($disbursement, $admin);
        $this->service()->completeReview($disbursement->fresh(), $admin);
        $this->service()->approve($disbursement->fresh(), $head);
        $recorder = User::factory()->create(['role' => 'admin_bumdes']);
        $verifier = User::factory()->create(['role' => 'admin_bumdes']);
        $transfer = app(UmkmDisbursementTransferService::class);
        $transfer->record($disbursement->fresh(), $recorder, 'BANK-REF-001', now()->format('Y-m-d H:i:s'), '90.00', UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf'));
        $this->assertSame('approved', $disbursement->fresh()->status);
        Storage::disk('local')->assertExists($disbursement->fresh()->transfer_proof_path);
        try { $transfer->verify($disbursement->fresh(), $recorder); $this->fail('Recorder must not verify.'); } catch (ValidationException) {}
        $transfer->verify($disbursement->fresh(), $verifier);
        $this->assertSame('paid', $disbursement->fresh()->status);
        $this->assertSame('settled', $entitlement->fresh()->status);
        $this->assertDatabaseCount('umkm_disbursement_allocations', 1);
    }

    /** @return array{UmkmDisbursement, UmkmEntitlement, User, User} */
    private function requestedDisbursement(): array
    {
        $owner = User::factory()->create(['role' => 'penjual']); $admin = User::factory()->create(['role' => 'admin_bumdes']); $head = User::factory()->create(['role' => 'kepala_bumdes']);
        $store = $owner->umkmStore()->create(['name' => 'Toko Uji', 'slug' => 'toko-'.Str::lower(Str::random(10)), 'status' => 'approved']);
        $account = $store->recipientBankAccounts()->create(['bank_name' => 'Bank Uji', 'account_holder_name' => $owner->name, 'account_number' => '00123456789', 'status' => 'active', 'active_store_lock' => 'store:'.$store->id, 'verified_at' => now(), 'created_by' => $owner->id, 'updated_by' => $owner->id]);
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $order = Order::create(['order_number' => 'ORD-'.Str::upper(Str::random(10)), 'checkout_token' => (string) Str::uuid(), 'user_id' => $customer->id, 'fulfillment_method' => 'pickup', 'recipient_name' => 'Pelanggan', 'recipient_phone' => '08123456789', 'subtotal' => '100.00', 'shipping_total' => '0.00', 'grand_total' => '100.00', 'payment_status' => 'paid', 'order_status' => 'completed']);
        $payment = Payment::create(['order_id' => $order->id, 'confirmed_order_id' => $order->id, 'payment_number' => 'PAY-'.Str::upper(Str::random(10)), 'payment_method' => 'cash_pickup', 'amount' => '100.00', 'status' => 'paid']);
        $storeOrder = StoreOrder::create(['order_id' => $order->id, 'umkm_store_id' => $store->id, 'store_order_number' => 'STO-'.Str::upper(Str::random(10)), 'store_name_snapshot' => $store->name, 'subtotal' => '100.00', 'shipping_amount' => '0.00', 'total' => '100.00', 'fulfillment_status' => 'completed', 'commission_status' => 'calculated', 'commission_percentage' => '10.00', 'commission_amount' => '10.00', 'umkm_amount' => '90.00']);
        $entitlement = UmkmEntitlement::create(['umkm_store_id' => $store->id, 'order_id' => $order->id, 'store_order_id' => $storeOrder->id, 'payment_id' => $payment->id, 'source_umkm_amount' => '90.00', 'source_commission_amount' => '10.00', 'source_commission_percentage' => '10.00', 'commission_status_snapshot' => 'calculated', 'status' => 'reserved', 'accrued_at' => now()]);
        $disbursement = UmkmDisbursement::create(['umkm_store_id' => $store->id, 'recipient_bank_account_id' => $account->id, 'recipient_bank_name' => $account->bank_name, 'recipient_account_holder_name' => $account->account_holder_name, 'recipient_account_number' => $account->account_number, 'requested_amount' => '90.00', 'status' => 'requested', 'active_request_lock' => 'store:'.$store->id, 'requested_by' => $owner->id, 'requested_at' => now(), 'idempotency_key' => (string) Str::uuid()]);
        UmkmDisbursementAllocation::create(['umkm_disbursement_id' => $disbursement->id, 'umkm_entitlement_id' => $entitlement->id, 'amount' => '90.00', 'entitlement_reservation_lock' => 'entitlement:'.$entitlement->id]);
        return [$disbursement, $entitlement, $admin, $head];
    }
    private function service(): UmkmDisbursementApprovalService { return app(UmkmDisbursementApprovalService::class); }
}
