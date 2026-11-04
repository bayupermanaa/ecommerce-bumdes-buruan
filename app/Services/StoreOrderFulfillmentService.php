<?php

namespace App\Services;

use App\Models\Order;
use App\Models\StoreOrder;
use App\Models\StoreOrderStatusLog;
use App\Models\User;
use App\Support\OrderFulfillmentStatus;
use App\Support\PaymentStatus;
use App\Support\StoreOrderFulfillmentStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class StoreOrderFulfillmentService
{
    public function __construct(
        private readonly UmkmEntitlementService $entitlementService,
    ) {
    }

    /**
     * Initialize every store-specific fulfillment flow after one payment is confirmed.
     *
     * This is intended for the payment-confirmation transaction, not for UMKM actions.
     */
    public function activateForConfirmedPayment(Order $order, User $actor): void
    {
        $this->ensureAdmin($actor);

        DB::transaction(function () use ($order, $actor): void {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->ensureOrderIsPaid($lockedOrder);

            $storeOrders = StoreOrder::query()
                ->where('order_id', $lockedOrder->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // Legacy or malformed orders are not silently promoted without a store split.
            if ($storeOrders->isEmpty()) {
                return;
            }

            if ($storeOrders->every(
                fn (StoreOrder $storeOrder): bool => $storeOrder->fulfillment_status === StoreOrderFulfillmentStatus::AWAITING_PROCESSING
            )) {
                $this->synchronizeOrderStatus($lockedOrder, $storeOrders);

                return;
            }

            if ($storeOrders->contains(
                fn (StoreOrder $storeOrder): bool => $storeOrder->fulfillment_status !== StoreOrderFulfillmentStatus::PENDING_PAYMENT
            )) {
                throw ValidationException::withMessages([
                    'fulfillment_status' => 'Status fulfillment pesanan tidak konsisten untuk aktivasi pembayaran.',
                ]);
            }

            foreach ($storeOrders as $storeOrder) {
                $storeOrder->forceFill([
                    'fulfillment_status' => StoreOrderFulfillmentStatus::AWAITING_PROCESSING,
                ])->save();

                StoreOrderStatusLog::create([
                    'store_order_id' => $storeOrder->id,
                    'actor_id' => $actor->id,
                    'from_status' => StoreOrderFulfillmentStatus::PENDING_PAYMENT,
                    'to_status' => StoreOrderFulfillmentStatus::AWAITING_PROCESSING,
                ]);

                $storeOrder->fulfillment_status = StoreOrderFulfillmentStatus::AWAITING_PROCESSING;
            }

            $this->synchronizeOrderStatus($lockedOrder, $storeOrders);
        });
    }

    /**
     * Advance one owned store order by exactly one permitted fulfillment state.
     *
     * The parent order is locked before all of its store orders so concurrent
     * changes to different stores of one customer order remain serialized.
     */
    public function transition(StoreOrder $storeOrder, User $actor, string $toStatus, ?string $notes = null): StoreOrder
    {
        if (! in_array($toStatus, StoreOrderFulfillmentStatus::values(), true)) {
            throw ValidationException::withMessages([
                'fulfillment_status' => 'Status fulfillment tidak valid.',
            ]);
        }

        return DB::transaction(function () use ($storeOrder, $actor, $toStatus, $notes): StoreOrder {
            $orderId = StoreOrder::query()->whereKey($storeOrder->id)->value('order_id');

            if (! $orderId) {
                throw ValidationException::withMessages(['store_order' => 'Pesanan toko tidak ditemukan.']);
            }

            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $storeOrders = StoreOrder::query()
                ->where('order_id', $order->id)
                ->with('umkmStore')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $lockedStoreOrder = $storeOrders->firstWhere('id', $storeOrder->id);

            if (! $lockedStoreOrder) {
                throw ValidationException::withMessages(['store_order' => 'Pesanan toko tidak sesuai dengan pesanan induk.']);
            }

            Gate::forUser($actor)->authorize('update', $lockedStoreOrder);
            $this->ensureOrderIsPaid($order);

            if ($lockedStoreOrder->fulfillment_status === $toStatus) {
                return $lockedStoreOrder;
            }

            $this->ensureTransitionIsAllowed($lockedStoreOrder, $toStatus);

            $fromStatus = $lockedStoreOrder->fulfillment_status;
            $lockedStoreOrder->forceFill(['fulfillment_status' => $toStatus])->save();

            StoreOrderStatusLog::create([
                'store_order_id' => $lockedStoreOrder->id,
                'actor_id' => $actor->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'notes' => filled($notes) ? trim($notes) : null,
            ]);

            $lockedStoreOrder->fulfillment_status = $toStatus;
            if ($toStatus === StoreOrderFulfillmentStatus::COMPLETED) {
                $this->entitlementService->markEligibleForCompletedStoreOrder($lockedStoreOrder, $actor);
            }
            $this->synchronizeOrderStatus($order, $storeOrders);

            return $lockedStoreOrder;
        });
    }

    /**
     * Determine the aggregate status without treating one completed store as a completed order.
     *
     * @param Collection<int, StoreOrder> $storeOrders
     */
    public function aggregateOrderStatus(Order $order, Collection $storeOrders): string
    {
        if ($order->order_status === OrderFulfillmentStatus::CANCELLED) {
            return OrderFulfillmentStatus::CANCELLED;
        }

        if ($order->payment_status !== PaymentStatus::PAID) {
            return OrderFulfillmentStatus::PENDING_PAYMENT;
        }

        if ($storeOrders->isEmpty() || $storeOrders->contains(
            fn (StoreOrder $storeOrder): bool => ! StoreOrderFulfillmentStatus::isPostPayment($storeOrder->fulfillment_status)
        )) {
            return OrderFulfillmentStatus::PENDING_PAYMENT;
        }

        if ($storeOrders->every(
            fn (StoreOrder $storeOrder): bool => $storeOrder->fulfillment_status === StoreOrderFulfillmentStatus::COMPLETED
        )) {
            return OrderFulfillmentStatus::COMPLETED;
        }

        return OrderFulfillmentStatus::IN_FULFILLMENT;
    }

    /**
     * Keep the parent status derived from every store order in the same transaction.
     *
     * @param Collection<int, StoreOrder> $storeOrders
     */
    private function synchronizeOrderStatus(Order $order, Collection $storeOrders): void
    {
        $aggregateStatus = $this->aggregateOrderStatus($order, $storeOrders);

        if ($order->order_status !== $aggregateStatus) {
            $order->forceFill(['order_status' => $aggregateStatus])->save();
        }
    }

    private function ensureOrderIsPaid(Order $order): void
    {
        if ($order->order_status === OrderFulfillmentStatus::CANCELLED) {
            throw ValidationException::withMessages(['order' => 'Pesanan yang dibatalkan tidak dapat diproses.']);
        }

        if ($order->payment_status !== PaymentStatus::PAID) {
            throw ValidationException::withMessages(['order' => 'Pesanan belum memiliki pembayaran terkonfirmasi.']);
        }
    }

    private function ensureAdmin(User $actor): void
    {
        if ($actor->role !== 'admin_bumdes') {
            throw new AuthorizationException('Hanya Admin BUMDes yang dapat mengaktifkan fulfillment setelah pembayaran.');
        }
    }

    private function ensureTransitionIsAllowed(StoreOrder $storeOrder, string $toStatus): void
    {
        $fromStatus = $storeOrder->fulfillment_status;

        if (in_array($fromStatus, [
            StoreOrderFulfillmentStatus::COMPLETED,
            StoreOrderFulfillmentStatus::CANCELLED,
        ], true)) {
            throw ValidationException::withMessages([
                'fulfillment_status' => 'Status fulfillment akhir tidak dapat diubah.',
            ]);
        }

        if (! StoreOrderFulfillmentStatus::canTransition($fromStatus, $toStatus)) {
            throw ValidationException::withMessages([
                'fulfillment_status' => 'Perubahan status fulfillment tidak berurutan.',
            ]);
        }
    }
}
