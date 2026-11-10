<?php

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Category;
use App\Models\BumdesBankAccount;
use App\Models\CommissionAgreement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StoreOrder;
use App\Models\UmkmEntitlement;
use App\Models\UmkmEntitlementEvent;
use App\Models\UmkmRecipientBankAccount;
use App\Models\UmkmStore;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$password = Hash::make('password123');

$users = [];
foreach ([
    'admin_bumdes' => ['Admin E2E', 'admin.e2e@example.test'],
    'admin_verifier' => ['Admin Verifier E2E', 'admin.verify.e2e@example.test'],
    'penjual' => ['Penjual E2E', 'umkm.e2e@example.test'],
    'pelanggan' => ['Pelanggan E2E', 'pelanggan.e2e@example.test'],
    'kepala_bumdes' => ['Kepala BUMDes E2E', 'kepala.e2e@example.test'],
    'kurir' => ['Kurir E2E', 'kurir.e2e@example.test'],
] as $role => [$name, $email]) {
    $users[$role] = User::updateOrCreate(
        ['email' => $email],
        [
            'name' => $name,
            'role' => $role === 'admin_verifier' ? 'admin_bumdes' : $role,
            'password' => $password,
            'email_verified_at' => now(),
        ],
    );
}

$store = UmkmStore::query()->where('user_id', $users['penjual']->id)->first() ?? new UmkmStore();
$store->forceFill([
    'user_id' => $users['penjual']->id,
    'name' => 'Toko E2E Buruan',
    'slug' => 'toko-e2e-buruan',
    'description' => 'Toko sintetis untuk browser E2E.',
    'address' => 'Alamat sintetis E2E',
    'phone' => '0800000000',
    'status' => 'approved',
    'verified_at' => now(),
])->save();

$category = Category::firstOrCreate(
    ['slug' => 'produk-e2e'],
    ['name' => 'Produk E2E', 'description' => 'Kategori sintetis E2E', 'is_active' => true],
);

$product = $store->products()->updateOrCreate(
    ['sku' => 'SKU-E2E-001'],
    [
        'category_id' => $category->id,
        'name' => 'Produk E2E Buruan',
        'slug' => 'produk-e2e-buruan',
        'description' => 'Produk sintetis untuk pengujian katalog.',
        'price' => '12500.00',
        'stock' => 20,
        'status' => 'approved',
        'verified_at' => now(),
    ],
);
$product->forceFill(['status' => 'approved', 'verified_at' => now()])->save();

BumdesBankAccount::updateOrCreate(
    ['account_number' => '0000000000'],
    [
        'bank_name' => 'Bank E2E Sintetis',
        'account_holder_name' => 'BUMDes E2E',
        'status' => 'active',
        'notes' => 'Fixture browser E2E; bukan rekening nyata.',
        'created_by' => $users['admin_bumdes']->id,
    ],
);

$agreement = CommissionAgreement::updateOrCreate(
    ['umkm_store_id' => $store->id, 'effective_start_date' => '2026-01-01'],
    [
        'commission_percentage' => '10.00',
        'status' => 'active',
        'created_by' => $users['admin_bumdes']->id,
        'activated_at' => now(),
    ],
);

$recipient = UmkmRecipientBankAccount::updateOrCreate(
    ['umkm_store_id' => $store->id, 'account_number' => '00123456789'],
    [
        'bank_name' => 'Bank E2E Penerima',
        'account_holder_name' => $users['penjual']->name,
        'status' => 'active',
        'active_store_lock' => 'store:'.$store->id,
        'verified_at' => now(),
        'verified_by' => $users['admin_bumdes']->id,
        'created_by' => $users['penjual']->id,
        'updated_by' => $users['penjual']->id,
    ],
);

$order = Order::updateOrCreate(
    ['order_number' => 'ORD-E2E-ENT-001'],
    [
        'checkout_token' => (string) Str::uuid(),
        'user_id' => $users['pelanggan']->id,
        'fulfillment_method' => 'pickup',
        'recipient_name' => $users['pelanggan']->name,
        'recipient_phone' => '081234567890',
        'subtotal' => '100.00',
        'shipping_total' => '0.00',
        'grand_total' => '100.00',
        'payment_status' => 'paid',
        'payment_method' => 'cash_pickup',
        'payment_confirmed_at' => now(),
        'payment_confirmed_by' => $users['admin_bumdes']->id,
        'order_status' => 'completed',
    ],
);

$payment = Payment::updateOrCreate(
    ['payment_number' => 'PAY-E2E-ENT-001'],
    [
        'order_id' => $order->id,
        'confirmed_order_id' => $order->id,
        'payment_number' => 'PAY-E2E-ENT-001',
        'idempotency_key' => (string) Str::uuid(),
        'payment_method' => 'cash_pickup',
        'amount' => '100.00',
        'status' => 'paid',
        'payer_name' => $users['pelanggan']->name,
        'cash_received_by_name' => $users['admin_bumdes']->name,
        'cash_received_at' => now(),
        'confirmed_by' => $users['admin_bumdes']->id,
        'confirmed_at' => now(),
    ],
);

$storeOrder = StoreOrder::updateOrCreate(
    ['store_order_number' => 'STO-E2E-ENT-001'],
    [
        'order_id' => $order->id,
        'umkm_store_id' => $store->id,
        'commission_agreement_id' => $agreement->id,
        'store_name_snapshot' => $store->name,
        'store_address_snapshot' => $store->address,
        'store_phone_snapshot' => $store->phone,
        'subtotal' => '100.00',
        'shipping_amount' => '0.00',
        'total' => '100.00',
        'fulfillment_status' => 'completed',
        'commission_percentage' => '10.00',
        'commission_amount' => '10.00',
        'umkm_amount' => '90.00',
        'commission_status' => 'calculated',
    ],
);

$entitlement = UmkmEntitlement::updateOrCreate(
    ['store_order_id' => $storeOrder->id],
    [
        'umkm_store_id' => $store->id,
        'order_id' => $order->id,
        'payment_id' => $payment->id,
        'source_umkm_amount' => '90.00',
        'source_commission_amount' => '10.00',
        'source_commission_percentage' => '10.00',
        'commission_status_snapshot' => 'calculated',
        'status' => 'available',
        'accrued_at' => now(),
        'eligible_at' => now(),
        'reconciled_at' => now(),
        'held_reason' => null,
        'reconciliation_notes' => 'Fixture browser E2E sintetis.',
    ],
);

foreach ([
    ['accrued', null, 'accrued', 'eligible'],
    ['eligible', 'accrued', 'eligible', 'available'],
    ['reconciled', 'eligible', 'available', 'available'],
] as $index => [$type, $from, $to, $status]) {
    UmkmEntitlementEvent::updateOrCreate(
        ['idempotency_key' => '00000000-0000-5000-8000-'.str_pad((string) ($entitlement->id * 10 + $index + 1), 12, '0', STR_PAD_LEFT)],
        [
            'umkm_entitlement_id' => $entitlement->id,
            'actor_id' => $users['admin_bumdes']->id,
            'event_type' => $type,
            'amount' => '90.00',
            'from_status' => $from,
            'to_status' => $to,
            'notes' => 'Fixture browser E2E sintetis.',
            'metadata' => null,
            'created_at' => now(),
        ],
    );
}

if (getenv('E2E_NEGATIVE') === '1') {
    $negativeOrder = Order::updateOrCreate(
        ['order_number' => 'ORD-E2E-NEG-001'],
        [
            'checkout_token' => (string) Str::uuid(),
            'user_id' => $users['pelanggan']->id,
            'fulfillment_method' => 'pickup',
            'recipient_name' => $users['pelanggan']->name,
            'recipient_phone' => '081234567890',
            'subtotal' => '100.00',
            'shipping_total' => '0.00',
            'grand_total' => '100.00',
            'payment_status' => 'paid',
            'payment_method' => 'cash_pickup',
            'payment_confirmed_at' => now(),
            'payment_confirmed_by' => $users['admin_bumdes']->id,
            'order_status' => 'completed',
        ],
    );

    $negativePayment = Payment::updateOrCreate(
        ['payment_number' => 'PAY-E2E-NEG-001'],
        [
            'order_id' => $negativeOrder->id,
            'confirmed_order_id' => $negativeOrder->id,
            'payment_number' => 'PAY-E2E-NEG-001',
            'idempotency_key' => (string) Str::uuid(),
            'payment_method' => 'cash_pickup',
            'amount' => '100.00',
            'status' => 'paid',
            'payer_name' => $users['pelanggan']->name,
            'cash_received_by_name' => $users['admin_bumdes']->name,
            'cash_received_at' => now(),
            'confirmed_by' => $users['admin_bumdes']->id,
            'confirmed_at' => now(),
        ],
    );

    $negativeStoreOrder = StoreOrder::updateOrCreate(
        ['store_order_number' => 'STO-E2E-NEG-001'],
        [
            'order_id' => $negativeOrder->id,
            'umkm_store_id' => $store->id,
            'commission_agreement_id' => $agreement->id,
            'store_name_snapshot' => $store->name,
            'store_address_snapshot' => $store->address,
            'store_phone_snapshot' => $store->phone,
            'subtotal' => '100.00',
            'shipping_amount' => '0.00',
            'total' => '100.00',
            'fulfillment_status' => 'completed',
            'commission_percentage' => '10.00',
            'commission_amount' => '10.00',
            'umkm_amount' => '90.00',
            'commission_status' => 'calculated',
        ],
    );

    $negativeEntitlement = UmkmEntitlement::updateOrCreate(
        ['store_order_id' => $negativeStoreOrder->id],
        [
            'umkm_store_id' => $store->id,
            'order_id' => $negativeOrder->id,
            'payment_id' => $negativePayment->id,
            'source_umkm_amount' => '90.00',
            'source_commission_amount' => '10.00',
            'source_commission_percentage' => '10.00',
            'commission_status_snapshot' => 'calculated',
            'status' => 'available',
            'accrued_at' => now(),
            'eligible_at' => now(),
            'reconciled_at' => now(),
            'held_reason' => null,
            'reconciliation_notes' => 'Fixture browser E2E negatif sintetis.',
        ],
    );

    foreach ([
        ['accrued', null, 'accrued', 'eligible'],
        ['eligible', 'accrued', 'eligible', 'available'],
        ['reconciled', 'eligible', 'available', 'available'],
    ] as $index => [$type, $from, $to, $status]) {
        UmkmEntitlementEvent::updateOrCreate(
            ['idempotency_key' => '00000000-0000-5000-8000-'.str_pad((string) ($negativeEntitlement->id * 10 + $index + 1), 12, '0', STR_PAD_LEFT)],
            [
                'umkm_entitlement_id' => $negativeEntitlement->id,
                'actor_id' => $users['admin_bumdes']->id,
                'event_type' => $type,
                'amount' => '90.00',
                'from_status' => $from,
                'to_status' => $to,
                'notes' => 'Fixture browser E2E negatif sintetis.',
                'metadata' => null,
                'created_at' => now(),
            ],
        );
    }
}

fwrite(STDOUT, "E2E synthetic role, catalog, entitlement, and disbursement fixtures ready.\n");
