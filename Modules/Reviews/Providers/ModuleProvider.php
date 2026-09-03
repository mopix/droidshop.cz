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
        //
        // Turned on only now (Task 4): the invitation e-mail's links point
        // at storefront.reviews.store/optout, and until this task both were
        // 404 stubs — mailing a buyer a link to a dead page is worse than
        // not mailing them at all.
        //
        // withoutOverlapping() is not a nicety here: two overlapping runs
        // both pass SendReviewInvitations' own whereNotIn filter before
        // either has written a row, so the second collides on
        // unique(tenant_id, order_id) mid-send.
        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)
                ->command(SendReviewInvitations::class)
                ->dailyAt('09:00')
                ->withoutOverlapping();
        });
    }
}
