<?php

namespace App\Providers;

use App\Models\ProductRevision;
use App\Models\Order;
use App\Models\StoreOrder;
use App\Models\CommissionAgreement;
use App\Models\BumdesBankAccount;
use App\Models\Payment;
use App\Models\UmkmEntitlement;
use App\Models\UmkmRecipientBankAccount;
use App\Models\UmkmDisbursement;
use App\Policies\CommissionAgreementPolicy;
use App\Policies\BumdesBankAccountPolicy;
use App\Policies\OrderPolicy;
use App\Policies\StoreOrderPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\ProductRevisionPolicy;
use App\Policies\UmkmEntitlementPolicy;
use App\Policies\UmkmRecipientBankAccountPolicy;
use App\Policies\UmkmDisbursementPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(ProductRevision::class, ProductRevisionPolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(StoreOrder::class, StoreOrderPolicy::class);
        Gate::policy(CommissionAgreement::class, CommissionAgreementPolicy::class);
        Gate::policy(BumdesBankAccount::class, BumdesBankAccountPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(UmkmEntitlement::class, UmkmEntitlementPolicy::class);
        Gate::policy(UmkmRecipientBankAccount::class, UmkmRecipientBankAccountPolicy::class);
        Gate::policy(UmkmDisbursement::class, UmkmDisbursementPolicy::class);
    }
}
