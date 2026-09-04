<?php

namespace Modules\Products\Providers;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Catalog\Contracts\ProductFacets;
use App\Core\Limits\LimitsService;
use Illuminate\Support\ServiceProvider;
use Modules\Products\Console\ReindexSearchText;
use Modules\Products\Services\EloquentProductCatalog;
use Modules\Products\Services\EloquentProductFacets;
use Modules\Products\Services\ProductsLimitCounter;

class ModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProductCatalog::class, EloquentProductCatalog::class);
        $this->app->bind(ProductFacets::class, EloquentProductFacets::class);
    }

    public function boot(): void
    {
        $this->app->make(LimitsService::class)
            ->registerCounter($this->app->make(ProductsLimitCounter::class));

        if ($this->app->runningInConsole()) {
            $this->commands([ReindexSearchText::class]);
        }
    }
}
