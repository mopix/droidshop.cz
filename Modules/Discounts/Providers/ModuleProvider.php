<?php

namespace Modules\Discounts\Providers;

use App\Core\Discounts\Contracts\DiscountBook;
use App\Core\Discounts\Contracts\DiscountEngine;
use App\Core\Discounts\Contracts\DiscountRedemption;
use Illuminate\Support\ServiceProvider;
use Modules\Discounts\Services\DiscountEvaluator;
use Modules\Discounts\Services\EloquentDiscountBook;
use Modules\Discounts\Services\EloquentDiscountRedemption;

class ModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        // Overrides the kernel's null bindings. Per-tenant activation is
        // answered by the evaluator at call time (ShopModules), not here —
        // this binding is per deploy, the same stance the packeta module takes.
        $this->app->bind(DiscountEngine::class, DiscountEvaluator::class);
        $this->app->bind(DiscountBook::class, EloquentDiscountBook::class);
        $this->app->bind(DiscountRedemption::class, EloquentDiscountRedemption::class);
    }
}
