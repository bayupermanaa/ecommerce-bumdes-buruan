<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentSubmissionController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\Admin\ProductVerificationController;
use App\Http\Controllers\Admin\ProductRevisionVerificationController;
use App\Http\Controllers\Admin\CommissionAgreementController as AdminCommissionAgreementController;
use App\Http\Controllers\Umkm\CommissionAgreementController as UmkmCommissionAgreementController;
use App\Http\Controllers\Admin\UmkmStoreVerificationController;
use App\Http\Controllers\Admin\BumdesBankAccountController;
use App\Http\Controllers\Admin\PaymentVerificationController;
use App\Http\Controllers\Admin\UmkmEntitlementController;
use App\Http\Controllers\Admin\UmkmDisbursementReviewController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\SellerDirectoryController;
use App\Http\Controllers\Admin\UmkmRecipientBankAccountVerificationController;
use App\Http\Controllers\Admin\FinancialAuditController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Umkm\ProductController;
use App\Http\Controllers\Umkm\StoreOrderController;
use App\Http\Controllers\Umkm\RecipientBankAccountController;
use App\Http\Controllers\Umkm\DisbursementRequestController;
use App\Http\Controllers\UmkmStoreController;
use App\Http\Controllers\SellerApplicationController;
use App\Http\Controllers\KepalaBumdesMonitoringController;
use App\Http\Controllers\KepalaBumdesDisbursementApprovalController;
use App\Http\Controllers\KepalaBumdes\CommissionReportController;
use App\Http\Controllers\KepalaBumdes\UmkmFinancialReportController;
use Illuminate\Support\Facades\Route;
use App\Support\UserRoles;

Route::get('/', function () {
    return redirect()->route('catalog.index');
});

Route::get('/katalog', [CatalogController::class, 'index'])->name('catalog.index');
Route::get('/katalog/{slug}', [CatalogController::class, 'show'])->name('catalog.show');

Route::get('/dashboard', function () {
    return match (request()->user()?->role) {
        UserRoles::SELLER, UserRoles::LEGACY_SELLER => redirect()->route('umkm.dashboard'),
        'admin_bumdes' => redirect()->route('admin.dashboard'),
        'kurir' => redirect()->route('kurir.dashboard'),
        'kepala_bumdes' => redirect()->route('kepala-bumdes.dashboard'),
        default => redirect()->route('pelanggan.dashboard'),
    };
})->middleware('auth')->name('dashboard');

Route::middleware(['auth', 'role:pelanggan'])->group(function () {
    Route::view('/pelanggan/dashboard', 'dashboards.pelanggan')->name('pelanggan.dashboard');

    Route::post('/mulai-berjualan', [SellerApplicationController::class, 'store'])
        ->name('seller-application.store');
    Route::patch('/mulai-berjualan/{store}/ajukan-ulang', [SellerApplicationController::class, 'resubmit'])
        ->name('seller-application.resubmit');
});

Route::middleware(['auth', 'role:pelanggan,penjual'])->group(function () {
    Route::get('/mulai-berjualan', [SellerApplicationController::class, 'show'])
        ->name('seller-application.show');

    Route::prefix('keranjang')->name('cart.')->group(function () {
        Route::get('/', [CartController::class, 'index'])->name('index');
        Route::post('/items/{product}', [CartController::class, 'store'])->name('items.store');
        Route::patch('/items/{item}', [CartController::class, 'update'])->name('items.update');
        Route::delete('/items/{item}', [CartController::class, 'destroy'])->name('items.destroy');
        Route::delete('/', [CartController::class, 'clear'])->name('clear');
    });

    Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout.index');
    Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');
    Route::get('/pesanan', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/pesanan/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('/pesanan/{order}/pembayaran', [PaymentSubmissionController::class, 'create'])->name('orders.payments.create');
    Route::post('/pesanan/{order}/pembayaran', [PaymentSubmissionController::class, 'store'])->name('orders.payments.store');
    Route::patch('/pesanan/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
});

Route::middleware(['auth', 'role:penjual'])->group(function () {
    Route::get('/umkm/dashboard', [\App\Http\Controllers\Umkm\SellerDashboardController::class, 'index'])->name('umkm.dashboard');

    Route::prefix('umkm')->name('umkm.')->group(function () {
        Route::resource('stores', UmkmStoreController::class)
            ->only(['index', 'create', 'store', 'edit', 'update']);

        Route::resource('products', ProductController::class)
            ->except(['show']);

        Route::get('orders', [StoreOrderController::class, 'index'])
            ->name('orders.index');
        Route::get('orders/{storeOrder}', [StoreOrderController::class, 'show'])
            ->name('orders.show');
        Route::patch('orders/{storeOrder}/start-processing', [StoreOrderController::class, 'startProcessing'])
            ->name('orders.start-processing');
        Route::patch('orders/{storeOrder}/ready-for-pickup', [StoreOrderController::class, 'markReadyForPickup'])
            ->name('orders.ready-for-pickup');
        Route::patch('orders/{storeOrder}/complete', [StoreOrderController::class, 'complete'])
            ->name('orders.complete');

        Route::get('commission-agreements', [UmkmCommissionAgreementController::class, 'index'])
            ->name('commission-agreements.index');
        Route::get('rekening-pencairan', [RecipientBankAccountController::class, 'index'])->name('recipient-bank-accounts.index');
        Route::post('rekening-pencairan', [RecipientBankAccountController::class, 'store'])->name('recipient-bank-accounts.store');
        Route::patch('rekening-pencairan/{account}', [RecipientBankAccountController::class, 'update'])->name('recipient-bank-accounts.update');
        Route::patch('rekening-pencairan/{account}/activate', [RecipientBankAccountController::class, 'activate'])->name('recipient-bank-accounts.activate');
        Route::patch('rekening-pencairan/{account}/deactivate', [RecipientBankAccountController::class, 'deactivate'])->name('recipient-bank-accounts.deactivate');
        Route::get('pencairan', [DisbursementRequestController::class, 'index'])->name('disbursements.index');
        Route::post('pencairan', [DisbursementRequestController::class, 'store'])->name('disbursements.store');
        Route::patch('pencairan/{disbursement}/batalkan', [DisbursementRequestController::class, 'cancel'])->name('disbursements.cancel');
    });
});

Route::middleware(['auth', 'role:admin_bumdes'])->group(function () {
    Route::get('/admin/dashboard', [AdminDashboardController::class, 'index'])->name('admin.dashboard');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('penjual', [SellerDirectoryController::class, 'index'])->name('sellers.index');
        Route::get('penjual/{store}', [SellerDirectoryController::class, 'show'])->name('sellers.show');
        Route::resource('categories', CategoryController::class)->except('show');

        Route::get('audit-keuangan', [FinancialAuditController::class, 'index'])
            ->name('financial-audit.index');
        Route::get('audit-keuangan/export', [FinancialAuditController::class, 'export'])
            ->name('financial-audit.export');
        Route::get('rekening-penerima-umkm', [UmkmRecipientBankAccountVerificationController::class, 'index'])->name('recipient-bank-accounts.index');
        Route::patch('rekening-penerima-umkm/{account}/verify', [UmkmRecipientBankAccountVerificationController::class, 'verify'])->name('recipient-bank-accounts.verify');
        Route::get('umkm-entitlements', [UmkmEntitlementController::class, 'index'])->name('umkm-entitlements.index');
        Route::get('umkm-entitlements/{entitlement}', [UmkmEntitlementController::class, 'show'])->name('umkm-entitlements.show');
        Route::patch('umkm-entitlements/{entitlement}/reconcile', [UmkmEntitlementController::class, 'reconcile'])->name('umkm-entitlements.reconcile');
        Route::get('pencairan-umkm', [UmkmDisbursementReviewController::class, 'index'])->name('disbursements.index');
        Route::patch('pencairan-umkm/{disbursement}/mulai-review', [UmkmDisbursementReviewController::class, 'start'])->name('disbursements.start-review');
        Route::patch('pencairan-umkm/{disbursement}/selesai-review', [UmkmDisbursementReviewController::class, 'complete'])->name('disbursements.complete-review');
        Route::patch('pencairan-umkm/{disbursement}/catat-transfer', [UmkmDisbursementReviewController::class, 'recordTransfer'])->name('disbursements.record-transfer');
        Route::patch('pencairan-umkm/{disbursement}/verifikasi-transfer', [UmkmDisbursementReviewController::class, 'verifyTransfer'])->name('disbursements.verify-transfer');
        Route::get('pencairan-umkm/{disbursement}/bukti-transfer', [UmkmDisbursementReviewController::class, 'proof'])->name('disbursements.proof');
        Route::patch('pencairan-umkm/{disbursement}/catat-kegagalan', [UmkmDisbursementReviewController::class, 'recordFailure'])->name('disbursements.record-failure');
        Route::patch('pencairan-umkm/{disbursement}/konfirmasi-kegagalan', [UmkmDisbursementReviewController::class, 'confirmFailure'])->name('disbursements.confirm-failure');
        Route::get('pencairan-umkm/{disbursement}/bukti-kegagalan', [UmkmDisbursementReviewController::class, 'failureProof'])->name('disbursements.failure-proof');
        Route::get('pembayaran', [PaymentVerificationController::class, 'index'])
            ->name('payments.index');
        Route::get('pembayaran/{payment}/bukti', [PaymentVerificationController::class, 'proof'])
            ->name('payments.proof');
        Route::patch('pembayaran/{payment}/confirm', [PaymentVerificationController::class, 'confirm'])
            ->name('payments.confirm');
        Route::patch('pembayaran/{payment}/reject', [PaymentVerificationController::class, 'reject'])
            ->name('payments.reject');
        Route::patch('pembayaran/{payment}/penerimaan-tunai', [PaymentVerificationController::class, 'recordCashReceipt'])
            ->name('payments.cash-receipt');
        Route::get('pembayaran/{payment}', [PaymentVerificationController::class, 'show'])
            ->name('payments.show');

        Route::get('rekening-bumdes', [BumdesBankAccountController::class, 'index'])
            ->name('bumdes-bank-accounts.index');
        Route::get('rekening-bumdes/tambah', [BumdesBankAccountController::class, 'create'])
            ->name('bumdes-bank-accounts.create');
        Route::post('rekening-bumdes', [BumdesBankAccountController::class, 'store'])
            ->name('bumdes-bank-accounts.store');
        Route::get('rekening-bumdes/{account}/edit', [BumdesBankAccountController::class, 'edit'])
            ->name('bumdes-bank-accounts.edit');
        Route::patch('rekening-bumdes/{account}', [BumdesBankAccountController::class, 'update'])
            ->name('bumdes-bank-accounts.update');
        Route::patch('rekening-bumdes/{account}/activate', [BumdesBankAccountController::class, 'activate'])
            ->name('bumdes-bank-accounts.activate');
        Route::patch('rekening-bumdes/{account}/deactivate', [BumdesBankAccountController::class, 'deactivate'])
            ->name('bumdes-bank-accounts.deactivate');

        Route::get('umkm-stores', [UmkmStoreVerificationController::class, 'index'])
            ->name('umkm-stores.index');
        Route::patch('umkm-stores/{store}/approve', [UmkmStoreVerificationController::class, 'approve'])
            ->name('umkm-stores.approve');
        Route::patch('umkm-stores/{store}/reject', [UmkmStoreVerificationController::class, 'reject'])
            ->name('umkm-stores.reject');

        Route::get('products', [ProductVerificationController::class, 'index'])
            ->name('products.index');
        Route::patch('products/{product}/approve', [ProductVerificationController::class, 'approve'])
            ->name('products.approve');
        Route::patch('products/{product}/reject', [ProductVerificationController::class, 'reject'])
            ->name('products.reject');

        Route::get('product-revisions', [ProductRevisionVerificationController::class, 'index'])
            ->name('product-revisions.index');
        Route::patch('product-revisions/{revision}/approve', [ProductRevisionVerificationController::class, 'approve'])
            ->name('product-revisions.approve');
        Route::patch('product-revisions/{revision}/reject', [ProductRevisionVerificationController::class, 'reject'])
            ->name('product-revisions.reject');

        Route::get('commission-agreements', [AdminCommissionAgreementController::class, 'index'])
            ->name('commission-agreements.index');
        Route::get('commission-agreements/create/{store}', [AdminCommissionAgreementController::class, 'create'])
            ->name('commission-agreements.create');
        Route::post('commission-agreements', [AdminCommissionAgreementController::class, 'store'])
            ->name('commission-agreements.store');
        Route::get('commission-agreements/{agreement}', [AdminCommissionAgreementController::class, 'show'])
            ->name('commission-agreements.show');
        Route::patch('commission-agreements/{agreement}/activate', [AdminCommissionAgreementController::class, 'activate'])
            ->name('commission-agreements.activate');
        Route::patch('commission-agreements/{agreement}/cancel', [AdminCommissionAgreementController::class, 'cancel'])
            ->name('commission-agreements.cancel');
        Route::patch('commission-agreements/{agreement}/end', [AdminCommissionAgreementController::class, 'end'])
            ->name('commission-agreements.end');
    });
});

Route::middleware(['auth', 'role:kurir'])->group(function () {
    Route::view('/kurir/dashboard', 'dashboards.kurir')->name('kurir.dashboard');
});

Route::middleware(['auth', 'role:kepala_bumdes'])->group(function () {
    Route::get('/kepala-bumdes/dashboard', [KepalaBumdesMonitoringController::class, 'dashboard'])
        ->name('kepala-bumdes.dashboard');
    Route::get('/kepala-bumdes/laporan-transaksi', [KepalaBumdesMonitoringController::class, 'transactions'])
        ->name('kepala-bumdes.transactions.index');
    Route::get('/kepala-bumdes/laporan-komisi', [CommissionReportController::class, 'index'])
        ->name('kepala-bumdes.commission-report.index');
    Route::get('/kepala-bumdes/laporan-komisi/export', [CommissionReportController::class, 'export'])
        ->name('kepala-bumdes.commission-report.export');
    Route::get('/kepala-bumdes/laporan-hak-umkm', [UmkmFinancialReportController::class, 'entitlements'])
        ->name('kepala-bumdes.umkm-financial.entitlements');
    Route::get('/kepala-bumdes/laporan-hak-umkm/export', [UmkmFinancialReportController::class, 'exportEntitlements'])
        ->name('kepala-bumdes.umkm-financial.entitlements.export');
    Route::get('/kepala-bumdes/laporan-pencairan-umkm', [UmkmFinancialReportController::class, 'disbursements'])
        ->name('kepala-bumdes.umkm-financial.disbursements');
    Route::get('/kepala-bumdes/laporan-pencairan-umkm/export', [UmkmFinancialReportController::class, 'exportDisbursements'])
        ->name('kepala-bumdes.umkm-financial.disbursements.export');
    Route::get('/kepala-bumdes/laporan-umkm', [KepalaBumdesMonitoringController::class, 'stores'])
        ->name('kepala-bumdes.stores.index');
    Route::get('/kepala-bumdes/laporan-umkm/{store}', [KepalaBumdesMonitoringController::class, 'storeDetail'])
        ->name('kepala-bumdes.stores.show');
    Route::get('/kepala-bumdes/persetujuan-pencairan', [KepalaBumdesDisbursementApprovalController::class, 'index'])->name('kepala-bumdes.disbursements.index');
    Route::patch('/kepala-bumdes/persetujuan-pencairan/{disbursement}/setujui', [KepalaBumdesDisbursementApprovalController::class, 'approve'])->name('kepala-bumdes.disbursements.approve');
    Route::patch('/kepala-bumdes/persetujuan-pencairan/{disbursement}/tolak', [KepalaBumdesDisbursementApprovalController::class, 'reject'])->name('kepala-bumdes.disbursements.reject');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
