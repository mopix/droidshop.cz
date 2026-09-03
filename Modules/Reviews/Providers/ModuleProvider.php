<?php

namespace Modules\Reviews\Providers;

use App\Core\Reviews\Contracts\ReviewAggregates;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Modules\Reviews\Console\SendReviewInvitations;
use Modules\Reviews\Services\EloquentReviewAggregates;

class ModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        // Overrides the kernel's null binding. Per-tenant activation is answered
        // at call time by ShopModules, not here — this binding is per deploy,
        // the same stance the discounts and packeta modules take.
        $this->app->bind(ReviewAggregates::class, EloquentReviewAggregates::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'reviews');

        if ($this->app->runningInConsole()) {
            $this->commands([SendReviewInvitations::class]);
        }

        // Scheduled from inside the provider, not routes/console.php: a
        // module the deploy does not run must not need a matching line in a
        // core file to avoid a scheduler error over a command that does not
        // exist. Same precedent as Modules\Packeta\Providers\ModuleProvider
        // (SyncPickupPointsCommand) and Modules\Customers\Providers\ModuleProvider
        // (PruneExpiredTokens). booted() defers registration until the
        // schedule itself is resolvable.
        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)
                ->command(SendReviewInvitations::class)
                ->dailyAt('09:00');
        });
    }
}
