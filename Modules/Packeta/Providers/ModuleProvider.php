<?php

namespace Modules\Packeta\Providers;

use App\Core\Shipping\Contracts\PickupPointCatalog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Modules\Packeta\Console\SyncPickupPointsCommand;
use Modules\Packeta\Services\EloquentPickupPointCatalog;

class ModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        // Overrides the kernel's null binding. Per-tenant activation is
        // answered at call time inside the implementation by ShopModules,
        // not here — this binding is per deploy.
        //
        // CarrierRegistry and ShipmentBook are bound here in a later wave
        // 2.5 task, once their drivers exist — keeping this task standalone.
        $this->app->bind(PickupPointCatalog::class, EloquentPickupPointCatalog::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SyncPickupPointsCommand::class]);
        }

        // Scheduled from inside the provider, not routes/console.php: a
        // module the deploy does not run must not need a matching line in a
        // core file to avoid a scheduler error over a command that does not
        // exist. Same precedent as Modules\Customers\Providers\ModuleProvider
        // (PruneExpiredTokens). booted() defers registration until the
        // schedule itself is resolvable.
        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)
                ->command(SyncPickupPointsCommand::class)
                ->dailyAt('03:30');
        });
    }
}
