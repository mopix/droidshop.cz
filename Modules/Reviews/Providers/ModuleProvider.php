<?php

namespace Modules\Reviews\Providers;

use App\Core\Reviews\Contracts\ReviewAggregates;
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

        // No Schedule::command() yet, deliberately: the invitation e-mail's
        // links point at storefront.reviews.store/optout, both still 404
        // stubs at this point in the wave. Task 4 gives them a real body and
        // is what adds the daily schedule entry (with withoutOverlapping()),
        // here in boot() rather than routes/console.php — a module the
        // deploy does not run must not need a matching line in a core file
        // to avoid a scheduler error over a command that does not exist.
        // Same precedent as Modules\Packeta\Providers\ModuleProvider
        // (SyncPickupPointsCommand) and Modules\Customers\Providers\ModuleProvider
        // (PruneExpiredTokens). Until then the command stays runnable by
        // hand and by tests.
    }
}
