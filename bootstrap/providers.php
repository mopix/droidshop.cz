<?php

use App\Providers\AppServiceProvider;
use App\Providers\BillingServiceProvider;
use App\Providers\ModuleServiceProvider;

return [
    AppServiceProvider::class,
    ModuleServiceProvider::class,
    BillingServiceProvider::class,
];
