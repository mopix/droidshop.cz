<?php

namespace Modules\Customers\Providers;

use App\Core\Customers\Contracts\CustomerIdentity;
use Illuminate\Support\ServiceProvider;
use Modules\Customers\Services\EloquentCustomerIdentity;

class ModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CustomerIdentity::class, EloquentCustomerIdentity::class);
    }
}
