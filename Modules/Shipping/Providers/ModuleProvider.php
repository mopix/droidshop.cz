<?php

namespace Modules\Shipping\Providers;

use App\Core\Shipping\Contracts\PaymentOptions;
use App\Core\Shipping\Contracts\ShippingOptions;
use Illuminate\Support\ServiceProvider;
use Modules\Shipping\Services\EloquentPaymentOptions;
use Modules\Shipping\Services\EloquentShippingOptions;

class ModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        // Overrides the kernel's null bindings. The per-tenant "is the module
        // active" question is answered at call time by ShopModules inside the
        // implementation, not here — this binding is per deploy.
        $this->app->bind(ShippingOptions::class, EloquentShippingOptions::class);
        $this->app->bind(PaymentOptions::class, EloquentPaymentOptions::class);
    }
}
