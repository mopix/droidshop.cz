<?php

namespace App\Providers;

use App\Core\Auth\TenantPermissions;
use App\Core\Modules\ModuleRouteRegistrar;
use App\Core\Tenancy\TenantContext;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Models\User;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
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

        $this->registerModulePermissionGate();
    }

    /**
     * Makes manifest permissions answerable through `Gate` / `$user->can()`.
     *
     * A `before` hook rather than a `Gate::define()` per permission, because
     * the set of permissions depends on which modules the *current tenant*
     * runs, and that is not known at boot. Returning null for anything the
     * shop does not declare leaves ordinary policies untouched — and means an
     * unknown ability is denied by the gate's own default rather than by us.
     */
    private function registerModulePermissionGate(): void
    {
        Gate::before(function ($user, string $ability) {
            if (! $user instanceof User) {
                return null;
            }

            $tenant = $this->app->make(TenantContext::class)->current();

            if ($tenant === null) {
                return null;
            }

            $permissions = $this->app->make(TenantPermissions::class);

            if (! in_array($ability, $permissions->availableFor($tenant), true)) {
                return null;
            }

            return $permissions->allows($user, $tenant, $ability);
        });
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
