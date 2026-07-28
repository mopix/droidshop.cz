<?php

namespace Modules\Feeds\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * The feeds module binds nothing into the kernel: it only reads the catalogue
 * through contracts that already exist. It is here because the module loader
 * expects every module to have a provider.
 */
class ModuleProvider extends ServiceProvider
{
    public function register(): void {}
}
