<?php

// Manual integration harness. Run only with explicit process environment targeting
// the disposable MySQL schema; never via phpunit.xml or the application .env.
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\Umkm\ProductController;
use App\Http\Controllers\Admin\ProductVerificationController;
use App\Http\Controllers\OrderController;
use App\Models\Cart;
use App\Models\Category;
use App\Models\CommissionAgreement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\CommissionCalculationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if (getenv('APP_ENV') !== 'testing'
    || getenv('DB_CONNECTION') !== 'mysql'
    || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_DATABASE') !== 'ecommerce_bumdes_variant_test') {
    throw new RuntimeException('Refusing unsafe database target.');
}

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ecommerce_bumdes_variant_test'
    || DB::selectOne('SELECT DATABASE() AS database_name')->database_name !== 'ecommerce_bumdes_variant_test') {
    throw new RuntimeException('Resolved database differs from allowlisted target.');
}

$mode = $argv[1] ?? '';
$runId = getenv('VARIANT_TEST_RUN_ID');
if (! preg_match('/^[a-z0-9]{8,24}$/', (string) $runId)) {
    throw new RuntimeException('Invalid synthetic run identifier.');
}
$prefix = 'parallel-'.$runId;

if ($mode === 'setup') {
    $fixture = DB::transaction(function () use ($prefix): array {
        $seller = User::create(['name' => 'Fixture Seller', 'email' => $prefix.'-seller@example.invalid', 'role' => 'penjual', 'password' => Str::random(40)]);
        $buyers = collect([1, 2, 3])->map(fn ($i) => User::create(['name' => 'Fixture Buyer '.$i, 'email' => $prefix.'-buyer'.$i.'@example.invalid', 'role' => 'pelanggan', 'password' => Str::random(40)]));
        $admin = User::create(['name' => 'Fixture Admin', 'email' => $prefix.'-admin@example.invalid', 'role' => 'admin_bumdes', 'password' => Str::random(40)]);
        $store = $seller->umkmStore()->create(['name' => 'Fixture Store', 'slug' => $prefix]);
        $store->forceFill(['status' => 'approved'])->save();
        $category = Category::create(['name' => 'Fixture Category', 'slug' => $prefix]);
        $variantProduct = $store->products()->create(['category_id' => $category->id, 'sku' => $prefix.'-variant-product', 'name' => 'Fixture Variant Product', 'slug' => $prefix.'-variant-product', 'price' => '10000.00', 'stock' => 0]);
        $variantProduct->forceFill(['status' => 'approved'])->save();
        $variant = $variantProduct->variants()->create(['label' => 'Fixture Size', 'sku' => $prefix.'-size', 'price' => '35000.25', 'stock' => 1]);
        $plain = $store->products()->create(['category_id' => $category->id, 'sku' => $prefix.'-plain', 'name' => 'Fixture Plain Product', 'slug' => $prefix.'-plain', 'price' => '12000.50', 'stock' => 1]);
        $plain->forceFill(['status' => 'approved'])->save();
        CommissionAgreement::create(['umkm_store_id' => $store->id, 'commission_percentage' => '10.00', 'effective_start_date' => today()->subDay(), 'status' => 'active', 'created_by' => $admin->id, 'activated_at' => now()]);
        foreach ($buyers as $i => $buyer) {
            $cart = Cart::create(['user_id' => $buyer->id]);
            $cart->items()->create($i < 2
                ? ['product_id' => $variantProduct->id, 'product_variant_id' => $variant->id, 'variant_identity' => $variant->id, 'quantity' => 1]
                : ['product_id' => $plain->id, 'quantity' => 1]);
        }

        return ['buyers' => $buyers->pluck('id')->all(), 'variant_id' => $variant->id, 'plain_id' => $plain->id];
    });
    echo json_encode($fixture, JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode === 'checkout') {
    $buyerId = (int) getenv('VARIANT_TEST_BUYER_ID');
    $buyer = User::query()->whereKey($buyerId)->where('email', 'like', $prefix.'-buyer%@example.invalid')->firstOrFail();
    $barrier = getenv('VARIANT_TEST_BARRIER');
    if (! is_string($barrier) || ! is_dir($barrier)) {
        throw new RuntimeException('Missing synchronization directory.');
    }
    file_put_contents($barrier.'/ready-'.$buyerId, 'ready');
    $deadline = microtime(true) + 20;
    while (! is_file($barrier.'/go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Barrier timed out.');
        }
        usleep(10_000);
    }

    $token = (string) Str::uuid();
    $request = Request::create('/checkout', 'POST', [
        'checkout_token' => $token,
        'fulfillment_method' => 'pickup',
        'recipient_name' => 'Fixture Recipient',
        'recipient_phone' => '0800000000',
        'price' => '1.00',
    ]);
    $session = new Store('variant-parallel-test', new ArraySessionHandler(120));
    $session->start();
    $session->put('checkout_token', $token);
    $request->setLaravelSession($session);
    $request->setUserResolver(fn () => $buyer);
    Auth::setUser($buyer);
    try {
        $response = $app->make(CheckoutController::class)->store($request, $app->make(CommissionCalculationService::class));
        echo json_encode(['buyer_id' => $buyerId, 'outcome' => 'success', 'redirect' => $response->getTargetUrl()], JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (ValidationException $exception) {
        echo json_encode(['buyer_id' => $buyerId, 'outcome' => 'rejected', 'errors' => array_keys($exception->errors())], JSON_THROW_ON_ERROR).PHP_EOL;
    }
    exit(0);
}

if ($mode === 'verify') {
    $buyers = User::query()->where('email', 'like', $prefix.'-buyer%@example.invalid')->pluck('id');
    $orders = Order::query()->whereIn('user_id', $buyers)->with('storeOrders.items')->get();
    $variant = ProductVariant::query()->where('sku', $prefix.'-size')->sole();
    $plain = Product::query()->where('sku', $prefix.'-plain')->sole();
    echo json_encode([
        'orders' => $orders->count(),
        'variant_orders' => $orders->whereIn('user_id', $buyers->take(2))->count(),
        'variant_stock' => $variant->stock,
        'plain_stock' => $plain->stock,
        'payments' => DB::table('payments')->whereIn('order_id', $orders->pluck('id'))->count(),
        'store_orders' => $orders->sum(fn ($order) => $order->storeOrders->count()),
        'order_items' => $orders->sum(fn ($order) => $order->storeOrders->sum(fn ($storeOrder) => $storeOrder->items->count())),
        'amounts' => $orders->map(fn ($order) => ['total' => $order->grand_total, 'commission' => $order->storeOrders->sole()->commission_amount, 'item_price' => $order->storeOrders->sole()->items->sole()->unit_price])->all(),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode === 'cancel') {
    $buyer = User::query()->where('email', $prefix.'-buyer1@example.invalid')->first();
    $order = $buyer ? Order::query()->where('user_id', $buyer->id)->first() : null;
    if (! $order) {
        $buyer = User::query()->where('email', $prefix.'-buyer2@example.invalid')->firstOrFail();
        $order = Order::query()->where('user_id', $buyer->id)->sole();
    }
    Auth::setUser($buyer);
    $request = Request::create('/pesanan/'.$order->id.'/cancel', 'PATCH');
    $request->setUserResolver(fn () => $buyer);
    $app->make(OrderController::class)->cancel($request, $order);
    $firstStock = ProductVariant::query()->where('sku', $prefix.'-size')->sole()->stock;
    $retryRejected = false;
    try {
        $app->make(OrderController::class)->cancel($request, $order->fresh());
    } catch (ValidationException $exception) {
        $retryRejected = array_key_exists('order', $exception->errors());
    }
    echo json_encode(['first_stock' => $firstStock, 'retry_rejected' => $retryRejected, 'final_stock' => ProductVariant::query()->where('sku', $prefix.'-size')->sole()->stock], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode === 'form') {
    $prefix = 'form-'.$runId;
    $seller = User::create(['name' => 'Form Fixture Seller', 'email' => $prefix.'@example.invalid', 'role' => 'penjual', 'password' => Str::random(40)]);
    $store = $seller->umkmStore()->create(['name' => 'Form Fixture Store', 'slug' => $prefix]);
    $store->forceFill(['status' => 'approved'])->save();
    $category = Category::create(['name' => 'Form Fixture Category', 'slug' => $prefix]);
    $requestFor = function (array $payload) use ($seller): Request {
        $request = Request::create('/umkm/products', 'POST', $payload);
        $request->setUserResolver(fn () => $seller);
        Auth::setUser($seller);
        return $request;
    };
    $controller = $app->make(ProductController::class);
    $single = $controller->store($requestFor(['category_id' => $category->id, 'sku' => $prefix.'-single', 'name' => 'Single', 'slug' => $prefix.'-single', 'description' => '', 'price' => '12000.25', 'stock' => 3, 'pricing_type' => 'single']));
    $variant = $controller->store($requestFor(['category_id' => $category->id, 'sku' => $prefix.'-variant', 'name' => 'Variant', 'slug' => $prefix.'-variant', 'description' => '', 'price' => '1.00', 'stock' => 0, 'pricing_type' => 'variants', 'variants' => [['label' => 'Small', 'sku' => $prefix.'-s', 'price' => '35000.25', 'stock' => 2], ['label' => 'Large', 'sku' => $prefix.'-l', 'price' => '85000.50', 'stock' => 1]]]));
    $product = Product::query()->where('sku', $prefix.'-variant')->with('variants')->sole();
    $duplicateRejected = false;
    try { $controller->store($requestFor(['category_id' => $category->id, 'sku' => $prefix.'-dup', 'name' => 'Dup', 'slug' => $prefix.'-dup', 'price' => '1', 'stock' => 0, 'pricing_type' => 'variants', 'variants' => [['label' => 'Small', 'sku' => $prefix.'-d1', 'price' => '1', 'stock' => 1], ['label' => 'small', 'sku' => $prefix.'-d2', 'price' => '2', 'stock' => 1]]])); } catch (ValidationException) { $duplicateRejected = true; }
    $admin = User::create(['name' => 'Form Fixture Admin', 'email' => $prefix.'-admin@example.invalid', 'role' => 'admin_bumdes', 'password' => Str::random(40)]);
    Auth::setUser($admin);
    $approvalRequest = Request::create('/admin/products/'.$product->id.'/approve', 'PATCH'); $approvalRequest->setUserResolver(fn () => $admin);
    $app->make(ProductVerificationController::class)->approve($approvalRequest, $product->fresh());
    $catalog = $app->make(CatalogController::class)->index(Request::create('/katalog', 'GET', ['sort' => 'price_asc']))->getData()['products'];
    $variantsBefore = $product->fresh()->variants->pluck('price')->sort()->values()->all();
    $cleanupIds = [$seller->id, $admin->id, $store->id, $category->id, Product::query()->where('sku', $prefix.'-single')->value('id'), $product->id];
    DB::transaction(function () use ($cleanupIds, $prefix): void {
        ProductVariant::whereIn('product_id', array_filter(array_slice($cleanupIds, 5)))->delete();
        Product::whereIn('id', array_filter(array_slice($cleanupIds, 4)))->delete();
        App\Models\UmkmStore::whereKey($cleanupIds[2])->delete(); Category::whereKey($cleanupIds[3])->delete(); User::whereIn('id', [$cleanupIds[0], $cleanupIds[1]])->delete();
    });
    echo json_encode(['single_created' => $single->getStatusCode() < 400, 'variant_created' => $variant->getStatusCode() < 400, 'prices' => $variantsBefore, 'duplicate_rejected' => $duplicateRejected, 'catalog_count' => $catalog->total()], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

throw new RuntimeException('Unsupported mode.');
