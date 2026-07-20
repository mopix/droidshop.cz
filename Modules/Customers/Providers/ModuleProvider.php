<?php

namespace Modules\Customers\Providers;

use App\Core\Customers\Contracts\CustomerIdentity;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use Modules\Customers\Auth\AnonymisedCustomerProvider;
use Modules\Customers\Services\EloquentCustomerIdentity;

class ModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CustomerIdentity::class, EloquentCustomerIdentity::class);
    }

    public function boot(): void
    {
        // Backs config/auth.php's `customers` provider (driver
        // `customer-eloquent`). Registered here, not left as the plain
        // `eloquent` driver, so every lookup the `customer` guard makes
        // excludes anonymised rows — see AnonymisedCustomerProvider's
        // docblock for why.
        Auth::provider('customer-eloquent', function ($app, array $config) {
            /** @var class-string<Authenticatable> $model */
            $model = $config['model'];

            return new AnonymisedCustomerProvider($app['hash'], $model);
        });
    }
}
