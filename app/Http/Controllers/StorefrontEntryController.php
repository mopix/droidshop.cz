<?php

namespace App\Http\Controllers;

use App\Core\Modules\ModuleRegistry;
use App\Core\Storefront\Contracts\StorefrontHome;
use App\Core\Tenancy\TenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/**
 * The root path, which means two different things depending on the host.
 *
 * On the platform's own domain it is our marketing page. On a tenant domain it
 * is the shop's homepage, which belongs to the theme module — see
 * StorefrontHome for why the kernel keeps the route and delegates the body.
 */
class StorefrontEntryController
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        ModuleRegistry $registry,
    ) {
        $tenant = $context->current();

        if ($tenant === null) {
            return Inertia::render('Welcome', [
                'canLogin' => Route::has('login'),
                'canRegister' => Route::has('register'),
                'laravelVersion' => Application::VERSION,
                'phpVersion' => PHP_VERSION,
            ]);
        }

        if (! app()->bound(StorefrontHome::class)) {
            abort(404);
        }

        $home = app(StorefrontHome::class);

        // Same gate the module middleware applies elsewhere: a shop that does
        // not run the theme module, or a module withdrawn platform-wide, has
        // no homepage rather than a broken one.
        if (! $registry->isEnabled($tenant, $home->moduleKey())) {
            abort(404);
        }

        return $home->render($request);
    }
}
