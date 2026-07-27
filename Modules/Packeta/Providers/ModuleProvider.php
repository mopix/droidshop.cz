<?php

namespace Modules\Packeta\Providers;

use App\Core\Shipping\Contracts\PickupPointCatalog;
use Illuminate\Support\ServiceProvider;
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
}
