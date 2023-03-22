<?php

namespace App\Providers;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * Observers
 */

use App\Observers\CompanyObserver;
use App\Company;
use App\Observers\SubscriptionProductObserver;
use App\SubscriptionProduct;
use App\Observers\SubscriptionPlanObserver;
use App\SubscriptionPlan;
use App\Observers\SubscriptionDiscountObserver;
use App\SubscriptionDiscount;
use App\Observers\SubscriptionObserver;
use App\Subscription;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Schema::defaultStringLength(191);

        if (!Collection::hasMacro('paginate')) {
            Collection::macro(
                'paginate',
                function ($perPage = 15, $page = null, $options = []) {
                    $page = $page ?: (Paginator::resolveCurrentPage() ?: 1);
                    return (new LengthAwarePaginator(
                        $this->forPage($page, $perPage),
                        $this->count(),
                        $perPage,
                        $page,
                        $options
                    ))
                        ->withPath('');
                }
            );
        }

        /**
         * Register models to observe
         */

        Company::observe(CompanyObserver::class);
        SubscriptionProduct::observe(SubscriptionProductObserver::class);
        SubscriptionPlan::observe(SubscriptionPlanObserver::class);
        SubscriptionDiscount::observe(SubscriptionDiscountObserver::class);
        Subscription::observe(SubscriptionObserver::class);
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        if ($this->app->isLocal()) {
            $this->app->register(TelescopeServiceProvider::class);
        }

        // Register stripe api key
        \Stripe\Stripe::setApiKey(config('app.stripe_secret_token'));
    }
}
