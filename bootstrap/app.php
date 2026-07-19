<?php

use App\Http\Middleware\CheckTenantStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveHost;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Tenant pipeline (spec §15.2) runs before anything else on the web
        // group: session, auth and Inertia all need to know which tenant they
        // belong to before they touch the database.
        $middleware->web(prepend: [
            ResolveHost::class,
            CheckTenantStatus::class,
            SetTenantContext::class,
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

// Local development uses .env.local so the shared .env stays untouched.
// Note: Laravel loads this file INSTEAD of .env, never merged on top of it,
// so .env.local must be a complete environment file. Absent in production.
if (file_exists($app->basePath('.env.local'))) {
    $app->loadEnvironmentFrom('.env.local');
}

return $app;
