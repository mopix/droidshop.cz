<?php

namespace App\Providers;

use App\Core\Modules\ModuleRouteRegistrar;
use App\Http\Middleware\EnsureModuleEnabled;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class ModuleServiceProvider extends ServiceProvider
{
    public function boot(Router $router, ModuleRouteRegistrar $registrar): void
    {
        $router->aliasMiddleware('module', EnsureModuleEnabled::class);

        // Migrations and views come from disk, not the registry: module tables
        // are shared and migrate platform-wide (spec §15.5 bod 4), and a
        // migration that only ran for "enabled" modules would leave the schema
        // depending on runtime state.
        $this->loadModuleAssetsFromDisk();

        // Routes also come from disk. The kill switch still works: the
        // `module` middleware consults the registry on every request, so a
        // withdrawn module answers 404 without anything being redeployed.
        $registrar->register();
    }

    private function loadModuleAssetsFromDisk(): void
    {
        foreach ($this->moduleDirectories() as $directory) {
            $key = Str::snake(basename($directory), '-');

            $migrations = $directory.'/Database/Migrations';

            if (is_dir($migrations)) {
                $this->loadMigrationsFrom($migrations);
            }

            $views = $directory.'/Resources/views';

            if (is_dir($views)) {
                $this->loadViewsFrom($views, $key);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function moduleDirectories(): array
    {
        return glob(base_path('Modules/*'), GLOB_ONLYDIR) ?: [];
    }
}
