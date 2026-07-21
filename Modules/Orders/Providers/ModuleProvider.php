<?php

namespace Modules\Orders\Providers;

use App\Core\Orders\Contracts\OrderBook;
use App\Core\Orders\Contracts\OrderPlacement;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Orders\Events\OrderPlaced;
use Modules\Orders\Listeners\SendOrderConfirmation;
use Modules\Orders\Services\EloquentOrderBook;
use Modules\Orders\Services\OrderPlacer;

class ModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The order confirmation e-mails (customer + operator) are a
        // post-commit side effect of a new order — see OrderPlaced. Wiring the
        // listener here, at deploy level, keeps checkout entirely unaware of
        // mail: it calls place() and redirects, this module sends.
        Event::listen(OrderPlaced::class, SendOrderConfirmation::class);
    }

    public function register(): void
    {
        // Overrides the kernel's null bindings. The per-tenant "is the module
        // active" question is answered at call time by ShopModules inside the
        // implementation, not here — this binding is per deploy.
        $this->app->bind(OrderBook::class, EloquentOrderBook::class);

        // OrderPlacer carries the placement transaction itself (idempotency,
        // stock, price integrity) — its own task, not this binding. Wiring it
        // here rather than leaving it to whoever adds the class is what lets
        // that work land without ever touching this provider again.
        $this->app->bind(OrderPlacement::class, OrderPlacer::class);
    }
}
