<?php

namespace App\Core\Modules;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Mounts module route files (spec §15.5).
 *
 * Reads the module list from disk, not from the registry. Routes describe what
 * is deployed; the database describes who may use it. Two reasons this matters:
 *
 * 1. Registration happens at boot, before a tenant is known and, on a fresh
 *    database, before the registry table exists at all.
 * 2. The route table is global and cacheable, so it cannot vary per tenant.
 *
 * Access is decided per request by the `module` middleware, which is also what
 * makes the global kill switch take effect without a redeploy.
 */
class ModuleRouteRegistrar
{
    public function register(): void
    {
        foreach ($this->moduleKeys() as $key) {
            $this->mount($key);
        }
    }

    /**
     * @return list<string>
     */
    public function moduleKeys(): array
    {
        $directories = glob(base_path('Modules/*'), GLOB_ONLYDIR) ?: [];

        return array_map(
            fn (string $directory) => Str::snake(basename($directory), '-'),
            $directories
        );
    }

    private function mount(string $key): void
    {
        $base = base_path('Modules/'.Str::studly($key));

        $this->mountAdmin($key, $base.'/routes/admin.php');
        $this->mountStorefront($key, $base.'/routes/storefront.php');
        $this->mountApi($key, $base.'/routes/api.php');
    }

    private function mountAdmin(string $key, string $file): void
    {
        if (! is_file($file)) {
            return;
        }

        // Order matters. The module gate runs first so a platform host or a
        // shop that does not run the module gets a flat 404 without ever
        // revealing there is a login behind it. Only then do we ask who the
        // caller is. `tenant.member` does the authentication itself rather
        // than stacking Laravel's `auth` alias, because `auth` sits in the
        // framework's middleware priority list and would be hoisted in front
        // of the module gate, turning those 404s into login redirects.
        Route::middleware(['web', 'module:'.$key, 'tenant.member'])
            ->prefix('admin/m/'.$key)
            ->name('admin.'.$key.'.')
            ->group($file);
    }

    private function mountStorefront(string $key, string $file): void
    {
        if (! is_file($file)) {
            return;
        }

        // No prefix: storefront URLs belong to the shop's own structure, and a
        // module path segment in them would be an SEO liability.
        Route::middleware(['web', 'module:'.$key])
            ->name('storefront.'.$key.'.')
            ->group($file);
    }

    private function mountApi(string $key, string $file): void
    {
        if (! is_file($file)) {
            return;
        }

        Route::middleware(['api', 'module:'.$key])
            ->prefix('api/m/'.$key)
            ->name('api.'.$key.'.')
            ->group($file);
    }
}
