<?php

namespace Modules\Payments\Providers;

use App\Core\Payments\Contracts\PaymentGatewayRegistry;
use Illuminate\Support\ServiceProvider;
use Modules\Payments\Services\EloquentPaymentGatewayRegistry;

class ModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        // Overrides the kernel's NullPaymentGatewayRegistry. The per-tenant
        // "is the module active / is a gateway configured" question is answered
        // at call time inside the registry (ShopModules + the payment_methods
        // rows), not here — this binding is per deploy.
        $this->app->bind(PaymentGatewayRegistry::class, EloquentPaymentGatewayRegistry::class);
    }
}
