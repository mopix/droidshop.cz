<?php

namespace Modules\Reviews\Providers;

use App\Core\Reviews\Contracts\ReviewAggregates;
use Illuminate\Support\ServiceProvider;
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
    }
}
