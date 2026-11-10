<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreOrder;
use App\Models\UmkmEntitlement;
use App\Models\UmkmEntitlementEvent;
use App\Models\UmkmStore;
use App\Models\User;
use App\Support\PaymentStatus;
use App\Support\StoreOrderFulfillmentStatus;
use App\Support\UmkmEntitlementEventType;
use App\Support\UmkmEntitlementStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UmkmEntitlementService
{
    /**
     * Create exactly one immutable entitlement source for every store split of a confirmed payment.
     *
     * This method is deliberately called only from the payment-confirmation transaction.
     */
    public function accrueForConfirmedPayment(Order $order, Payment $payment, User $actor): void
    {
        $this->ensureAdmin($actor);

        DB::transaction(function () use ($order, $payment, $actor): void {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $this->ensureConfirmedPaymentMatchesOrder($lockedOrder, $lockedPayment);

            $storeOrders = StoreOrder::query()
                ->where('order_id', $lockedOrder->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($storeOrders as $storeOrder) {
                $this->accrueLockedStoreOrder($lockedOrder, $lockedPayment, $storeOrder, $actor);
            }
        });
    }

    /**
     * Move a valid accrued entitlement to eligible only after its own store order is completed.
     */
    public function markEligibleForCompletedStoreOrder(StoreOrder $storeOrder, User $actor): void
    {
        DB::transaction(function () use ($storeOrder, $actor): void {
            $source = StoreOrder::query()->whereKey($storeOrder->id)->firstOrFail(['id', 'order_id']);
            $order = Order::query()->whereKey($source->order_id)->lockForUpdate()->firstOrFail();
            $lockedStoreOrder = StoreOrder::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            $entitlement = UmkmEntitlement::query()
                ->where('store_order_id', $lockedStoreOrder->id)
                ->lockForUpdate()
                ->first();

            if (! $entitlement) {
                // Legacy paid orders may predate the entitlement foundation. Do not fabricate a right here.
                logger()->warning('Completed store order has no UMKM entitlement and requires reconciliation.', [
                    'store_order_id' => $lockedStoreOrder->id,
                    'order_id' => $order->id,
                ]);

                return;
            }

            if ($lockedStoreOrder->fulfillment_status !== StoreOrderFulfillmentStatus::COMPLETED) {
                throw ValidationException::withMessages([
                    'fulfillment_status' => 'Hak Penjual hanya dapat menjadi eligible setelah pesanan toko selesai.',
                ]);
            }

            $payment = Payment::query()->whereKey($entitlement->payment_id)->lockForUpdate()->firstOrFail();
            $this->ensureConfirmedPaymentMatchesOrder($order, $payment);
            $this->ensureEntitlementMatchesSource($entitlement, $order, $payment, $lockedStoreOrder);

            if ($entitlement->status === UmkmEntitlementStatus::HELD) {
                return;
            }

            if (in_array($entitlement->status, [
                UmkmEntitlementStatus::ELIGIBLE,
                UmkmEntitlementStatus::AVAILABLE,
                UmkmEntitlementStatus::RESERVED,
                UmkmEntitlementStatus::SETTLED,
            ], true)) {
                return;
            }

            if ($entitlement->status !== UmkmEntitlementStatus::ACCRUED) {
                throw ValidationException::withMessages(['entitlement' => 'Status hak Penjual tidak dapat menjadi eligible.']);
            }

            $fromStatus = $entitlement->status;
            $entitlement->forceFill([
                'status' => UmkmEntitlementStatus::ELIGIBLE,
                'eligible_at' => now(),
            ])->save();
            $this->writeEvent($entitlement, $actor, UmkmEntitlementEventType::ELIGIBLE, $fromStatus, UmkmEntitlementStatus::ELIGIBLE);
        });
    }

    /**
     * Make one eligible, unheld entitlement available after an explicit admin reconciliation.
     */
    public function reconcile(UmkmEntitlement $entitlement, User $admin, ?string $notes = null): UmkmEntitlement
    {
        $this->ensureAdmin($admin);

        return DB::transaction(function () use ($entitlement, $admin, $notes): UmkmEntitlement {
            $source = UmkmEntitlement::query()->whereKey($entitlement->id)->firstOrFail(['id', 'umkm_store_id', 'order_id', 'payment_id', 'store_order_id']);
            // Use the same first lock as a disbursement request so an entitlement
            // cannot become available halfway through a reservation transaction.
            UmkmStore::query()->whereKey($source->umkm_store_id)->lockForUpdate()->firstOrFail();
            $order = Order::query()->whereKey($source->order_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::query()->whereKey($source->payment_id)->lockForUpdate()->firstOrFail();
            $storeOrder = StoreOrder::query()->whereKey($source->store_order_id)->lockForUpdate()->firstOrFail();
            $lockedEntitlement = UmkmEntitlement::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();

            $this->ensureConfirmedPaymentMatchesOrder($order, $payment);
            $this->ensureEntitlementMatchesSource($lockedEntitlement, $order, $payment, $storeOrder);

            if ($lockedEntitlement->status === UmkmEntitlementStatus::AVAILABLE) {
                return $lockedEntitlement;
            }

            if ($lockedEntitlement->status !== UmkmEntitlementStatus::ELIGIBLE
                || $storeOrder->fulfillment_status !== StoreOrderFulfillmentStatus::COMPLETED) {
                throw ValidationException::withMessages(['entitlement' => 'Hak Penjual belum memenuhi syarat rekonsiliasi.']);
            }

            $lockedEntitlement->forceFill([
                'status' => UmkmEntitlementStatus::AVAILABLE,
                'reconciled_at' => now(),
                'reconciliation_notes' => filled($notes) ? trim($notes) : null,
            ])->save();
            $this->writeEvent($lockedEntitlement, $admin, UmkmEntitlementEventType::RECONCILED, UmkmEntitlementStatus::ELIGIBLE, UmkmEntitlementStatus::AVAILABLE, $notes);

            return $lockedEntitlement;
        });
    }

    private function accrueLockedStoreOrder(Order $order, Payment $payment, StoreOrder $storeOrder, User $actor): void
    {
        $existing = UmkmEntitlement::query()->where('store_order_id', $storeOrder->id)->lockForUpdate()->first();

        if ($existing) {
            $this->ensureEntitlementMatchesSource($existing, $order, $payment, $storeOrder);

            return;
        }

        $calculated = $storeOrder->commission_status === 'calculated'
            && $storeOrder->umkm_amount !== null
            && $storeOrder->commission_amount !== null;
        $status = $calculated ? UmkmEntitlementStatus::ACCRUED : UmkmEntitlementStatus::HELD;
        $heldReason = $calculated ? null : 'Snapshot komisi pesanan toko belum calculated; nominal hak Penjual tidak dapat ditentukan.';

        $entitlement = UmkmEntitlement::create([
            'umkm_store_id' => $storeOrder->umkm_store_id,
            'order_id' => $order->id,
            'store_order_id' => $storeOrder->id,
            'payment_id' => $payment->id,
            'source_umkm_amount' => $calculated ? $storeOrder->umkm_amount : null,
            'source_commission_amount' => $calculated ? $storeOrder->commission_amount : null,
            'source_commission_percentage' => $calculated ? $storeOrder->commission_percentage : null,
            'commission_status_snapshot' => (string) ($storeOrder->commission_status ?? 'unconfigured'),
            'status' => $status,
            'accrued_at' => now(),
            'held_reason' => $heldReason,
        ]);

        $this->writeEvent($entitlement, $actor, UmkmEntitlementEventType::ACCRUED, null, $status, $heldReason);
    }

    private function ensureConfirmedPaymentMatchesOrder(Order $order, Payment $payment): void
    {
        if ($order->payment_status !== PaymentStatus::PAID
            || $payment->status !== PaymentStatus::PAID
            || $payment->order_id !== $order->id
            || $payment->confirmed_order_id !== $order->id) {
            throw ValidationException::withMessages(['payment' => 'Pembayaran sukses tidak konsisten dengan pesanan.']);
        }
    }

    private function ensureEntitlementMatchesSource(UmkmEntitlement $entitlement, Order $order, Payment $payment, StoreOrder $storeOrder): void
    {
        if ($entitlement->order_id !== $order->id
            || $entitlement->payment_id !== $payment->id
            || $entitlement->store_order_id !== $storeOrder->id
            || $entitlement->umkm_store_id !== $storeOrder->umkm_store_id
            || $storeOrder->order_id !== $order->id
            || $entitlement->commission_status_snapshot !== (string) ($storeOrder->commission_status ?? 'unconfigured')) {
            throw ValidationException::withMessages(['entitlement' => 'Sumber entitlement tidak konsisten.']);
        }

        if ($entitlement->commission_status_snapshot === 'calculated'
            && ((string) $entitlement->source_umkm_amount !== (string) $storeOrder->umkm_amount
                || (string) $entitlement->source_commission_amount !== (string) $storeOrder->commission_amount
                || (string) $entitlement->source_commission_percentage !== (string) $storeOrder->commission_percentage)) {
            throw ValidationException::withMessages(['entitlement' => 'Snapshot komisi entitlement tidak konsisten.']);
        }
    }

    private function writeEvent(UmkmEntitlement $entitlement, User $actor, string $eventType, ?string $fromStatus, string $toStatus, ?string $notes = null): void
    {
        UmkmEntitlementEvent::query()->firstOrCreate(
            ['idempotency_key' => $this->eventKey($entitlement->id, $eventType)],
            [
                'umkm_entitlement_id' => $entitlement->id,
                'actor_id' => $actor->id,
                'event_type' => $eventType,
                'amount' => $entitlement->source_umkm_amount ?? '0.00',
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'notes' => filled($notes) ? trim($notes) : null,
            ],
        );
    }

    private function eventKey(int $entitlementId, string $eventType): string
    {
        $hash = md5('umkm-entitlement:'.$entitlementId.':'.$eventType);

        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-5'.substr($hash, 13, 3).'-8'.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
    }

    private function ensureAdmin(User $actor): void
    {
        if ($actor->role !== 'admin_bumdes') {
            throw new AuthorizationException('Hanya Admin BUMDes yang dapat merekonsiliasi hak Penjual.');
        }
    }
}
