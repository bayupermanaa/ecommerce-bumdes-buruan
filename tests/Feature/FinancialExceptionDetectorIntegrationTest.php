<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreOrder;
use App\Models\UmkmDisbursementAllocation;
use App\Models\UmkmDisbursementEvent;
use App\Models\UmkmEntitlement;
use App\Models\UmkmEntitlementEvent;
use App\Models\UmkmStore;
use App\Models\User;
use App\Services\FinancialAudit\FinancialExceptionDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialExceptionDetectorIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function categories(): array
    {
        return [
            FinancialExceptionDetector::PAY_ENT_MISSING,
            FinancialExceptionDetector::ENT_PAYMENT_INVALID,
            FinancialExceptionDetector::ENT_SNAPSHOT_MISMATCH,
            FinancialExceptionDetector::ENT_HELD_REVIEW,
            FinancialExceptionDetector::ENT_RESERVED_NO_ACTIVE_ALLOCATION,
            FinancialExceptionDetector::DISB_ACTIVE_ALLOCATION_MISMATCH,
            FinancialExceptionDetector::DISB_TERMINAL_LOCK_REMAINS,
            FinancialExceptionDetector::DISB_DUPLICATE_ACTIVE,
            FinancialExceptionDetector::DISB_PAID_VERIFICATION_MISSING,
            FinancialExceptionDetector::DISB_FAILURE_RELEASE_MISSING,
            FinancialExceptionDetector::EVENT_TRAIL_GAP,
        ];
    }

    public function test_all_eleven_categories_are_selectable_without_category_leakage(): void
    {
        $detector = app(FinancialExceptionDetector::class);
        $store = $this->makeStore('Toko Kategori Terpadu');
        $this->makeEntitlement($store, 'held');
        $this->makeEntitlement($store, 'settled');

        foreach ($this->categories() as $category) {
            $result = $detector->scan(['category' => $category]);

            $this->assertInstanceOf(LazyCollection::class, $result);
            $this->assertTrue(collect($result->all())->every(fn (array $exception): bool => $exception['code'] === $category));
        }

        $combined = $detector->scan([
            'categories' => [FinancialExceptionDetector::EVENT_TRAIL_GAP, FinancialExceptionDetector::ENT_HELD_REVIEW],
        ])->values()->all();

        $this->assertSame(
            [FinancialExceptionDetector::ENT_HELD_REVIEW, FinancialExceptionDetector::EVENT_TRAIL_GAP],
            array_values(array_unique(array_column($combined, 'code'))),
        );
    }

    public function test_integrated_contract_filters_streaming_stable_keys_read_only_and_privacy(): void
    {
        $detector = app(FinancialExceptionDetector::class);
        $store = $this->makeStore('Toko Audit Terpadu');
        $otherStore = $this->makeStore('Toko Lain Audit');
        $held = $this->makeEntitlement($store, 'held', '123456.70');
        $settled = $this->makeEntitlement($store, 'settled', '150000.50');
        $this->makeEntitlement($otherStore, 'held');
        $actor = User::factory()->create(['role' => 'admin_bumdes']);
        $optional = $this->makeEntitlement($store, 'available');
        UmkmEntitlementEvent::create([
            'umkm_entitlement_id' => $optional->id,
            'actor_id' => $actor->id,
            'event_type' => 'accrued',
            'amount' => '150000.50',
            'to_status' => 'available',
            'metadata' => ['private' => 'event-metadata-secret'],
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $before = [
            'entitlements' => UmkmEntitlement::query()->get(['id', 'status', 'source_umkm_amount'])->map->getAttributes()->all(),
            'allocations' => UmkmDisbursementAllocation::query()->get(['id', 'amount', 'entitlement_reservation_lock'])->map->getAttributes()->all(),
            'entitlement_events' => UmkmEntitlementEvent::query()->get(['id', 'event_type', 'metadata'])->map->getAttributes()->all(),
            'disbursement_events' => UmkmDisbursementEvent::query()->get(['id', 'event_type', 'metadata'])->map->getAttributes()->all(),
        ];
        $filters = [
            'categories' => [FinancialExceptionDetector::EVENT_TRAIL_GAP, FinancialExceptionDetector::ENT_HELD_REVIEW],
            'umkm_store_id' => $store->id,
        ];
        $scan = $detector->scan($filters);
        $first = $scan->values()->all();
        $second = $detector->scan($filters)->values()->all();
        $chunks = [];
        $detector->chunk($filters, 1, function (Collection $exceptions) use (&$chunks): void {
            $chunks[] = $exceptions->all();
        });

        $this->assertInstanceOf(LazyCollection::class, $scan);
        $this->assertCount(2, $first);
        $this->assertSame([FinancialExceptionDetector::ENT_HELD_REVIEW, FinancialExceptionDetector::EVENT_TRAIL_GAP], array_column($first, 'code'));
        $this->assertSame([$held->id, $settled->id], array_column($first, 'reference_id'));
        foreach ($first as $exception) {
            $this->assertSame([
                'code', 'severity', 'umkm_store_id', 'reference_type', 'reference_id', 'current_status', 'description',
                'detected_at', 'source_timestamp', 'stable_exception_key', 'context',
            ], array_keys($exception));
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $exception['stable_exception_key']);
        }
        $this->assertSame(array_column($first, 'stable_exception_key'), array_column($second, 'stable_exception_key'));
        $this->assertCount(count($first), array_unique(array_column($first, 'stable_exception_key')));
        $this->assertCount(2, $chunks);
        $this->assertTrue(collect($chunks)->every(fn (array $chunk): bool => count($chunk) <= 1));
        $this->assertSame(array_column($first, 'stable_exception_key'), array_merge(...array_map(fn (array $chunk): array => array_column($chunk, 'stable_exception_key'), $chunks)));

        $this->assertSame('123456.70', UmkmEntitlement::query()->findOrFail($held->id)->source_umkm_amount);
        $this->assertSame($before['entitlements'], UmkmEntitlement::query()->get(['id', 'status', 'source_umkm_amount'])->map->getAttributes()->all());
        $this->assertSame($before['allocations'], UmkmDisbursementAllocation::query()->get(['id', 'amount', 'entitlement_reservation_lock'])->map->getAttributes()->all());
        $this->assertSame($before['entitlement_events'], UmkmEntitlementEvent::query()->get(['id', 'event_type', 'metadata'])->map->getAttributes()->all());
        $this->assertSame($before['disbursement_events'], UmkmDisbursementEvent::query()->get(['id', 'event_type', 'metadata'])->map->getAttributes()->all());

        $context = json_encode($first, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('00123456789', $context);
        $this->assertStringNotContainsString('Pemilik Rekening Rahasia', $context);
        $this->assertStringNotContainsString('REF-PRIVATE-SECRET', $context);
        $this->assertStringNotContainsString('private-proof-secret', $context);
        $this->assertStringNotContainsString('event-metadata-secret', $context);
    }

    private function makeStore(string $name): UmkmStore
    {
        $owner = User::factory()->create(['role' => 'penjual']);

        return $owner->umkmStore()->create([
            'name' => $name,
            'slug' => 'integration-detector-'.Str::lower(Str::random(10)),
        ]);
    }

    private function makeEntitlement(UmkmStore $store, string $status, string $amount = '150000.50'): UmkmEntitlement
    {
        $customer = User::factory()->create(['role' => 'pelanggan']);
        $order = Order::create([
            'order_number' => 'ORD-I-'.Str::upper(Str::random(10)),
            'checkout_token' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'fulfillment_method' => 'pickup',
            'recipient_name' => 'Pelanggan Test',
            'recipient_phone' => '081234567890',
            'subtotal' => $amount,
            'shipping_total' => '0.00',
            'grand_total' => $amount,
            'payment_status' => 'paid',
            'order_status' => 'completed',
        ]);
        $storeOrder = StoreOrder::create([
            'order_id' => $order->id,
            'umkm_store_id' => $store->id,
            'store_order_number' => 'SO-I-'.Str::upper(Str::random(10)),
            'store_name_snapshot' => $store->name,
            'subtotal' => $amount,
            'shipping_amount' => '0.00',
            'total' => $amount,
            'fulfillment_status' => 'completed',
            'commission_percentage' => '0.00',
            'commission_amount' => '0.00',
            'umkm_amount' => $amount,
            'commission_status' => 'calculated',
        ]);
        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_number' => 'PAY-I-'.Str::upper(Str::random(10)),
            'payment_method' => 'cash_pickup',
            'amount' => $amount,
            'status' => 'paid',
        ]);

        return UmkmEntitlement::create([
            'umkm_store_id' => $store->id,
            'order_id' => $order->id,
            'store_order_id' => $storeOrder->id,
            'payment_id' => $payment->id,
            'source_umkm_amount' => $amount,
            'source_commission_amount' => '0.00',
            'source_commission_percentage' => '0.00',
            'commission_status_snapshot' => 'calculated',
            'status' => $status,
            'accrued_at' => now(),
            'settled_at' => $status === 'settled' ? now() : null,
        ]);
    }
}
