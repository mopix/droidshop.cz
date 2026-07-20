<?php

namespace Modules\Customers\Providers;

use App\Core\Customers\Contracts\CustomerIdentity;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use Modules\Customers\Auth\AnonymisedCustomerProvider;
use Modules\Customers\Console\PruneExpiredTokens;
use Modules\Customers\Http\Middleware\AuthenticateCustomerSession;
use Modules\Customers\Services\EloquentCustomerIdentity;

class ModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        // Overrides the kernel's NullCustomerIdentity (App\Providers\AppServiceProvider).
        // register() on a module provider runs after the kernel's own
        // register() phase (ModuleServiceProvider::boot() is what pulls this
        // provider in), so this bind() — last one wins in the container —
        // is what makes the contract resolve to a real implementation once
        // the module is present. See EloquentCustomerIdentity for the other
        // half: a tenant that has switched the module off must still look
        // like a guest-only shop, which is a runtime check, not something a
        // deploy-level binding can express.
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

        $this->app['router']->aliasMiddleware('customer.session', AuthenticateCustomerSession::class);

        if ($this->app->runningInConsole()) {
            $this->commands([PruneExpiredTokens::class]);
        }

        // Scheduled from inside the provider, not routes/console.php: a
        // module the deploy does not run must not need a matching line in a
        // core file to avoid a scheduler error over a command that does not
        // exist. booted() defers registration until the schedule itself is
        // resolvable, which is what Laravel's own docs recommend for
        // packages that want to contribute schedule entries.
        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)
                ->command(PruneExpiredTokens::class)
                ->daily();
        });
    }
}
