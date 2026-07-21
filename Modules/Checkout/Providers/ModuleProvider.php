<?php

namespace Modules\Checkout\Providers;

use App\Core\Checkout\Contracts\CartRepository;
use Illuminate\Support\ServiceProvider;
use Modules\Checkout\Services\EloquentCartRepository;

class ModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        // Overrides the kernel's null binding. The per-tenant "is the module
        // active" question is answered at call time by ShopModules inside the
        // implementation, not here — this binding is per deploy.
        $this->app->bind(CartRepository::class, EloquentCartRepository::class);
    }
}
