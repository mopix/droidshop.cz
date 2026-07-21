<?php

namespace Modules\Checkout\Providers;

use App\Core\Checkout\Contracts\CartRepository;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Checkout\Listeners\MergeCartOnCustomerLogin;
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

    public function boot(): void
    {
        // Wired here, not in Modules\Customers, so the customers module
        // stays entirely unaware that carts exist — mirrors
        // Modules\Orders\Providers\ModuleProvider listening for OrderPlaced
        // instead of checkout knowing about mail. Illuminate\Auth\Events\Login
        // fires for every guard; the listener itself filters to 'customer'.
        Event::listen(Login::class, MergeCartOnCustomerLogin::class);
    }
}
