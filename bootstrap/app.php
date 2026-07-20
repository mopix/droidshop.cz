<?php

use App\Core\Routing\RedirectResponder;
use App\Http\Middleware\CheckTenantStatus;
use App\Http\Middleware\EnsurePlatformTwoFactor;
use App\Http\Middleware\EnsureTenantMember;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequirePlatformHost;
use App\Http\Middleware\ResolveHost;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Superadmin routes share the web group (session, CSRF, the tenant
            // pipeline that lets platform hosts through) but are gated to
            // platform hosts by their own middleware.
            Route::middleware('web')
                ->group(base_path('routes/platform.php'));
        },
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

        $middleware->alias([
            'platform.host' => RequirePlatformHost::class,
            'platform.2fa' => EnsurePlatformTwoFactor::class,
            'tenant.member' => EnsureTenantMember::class,
        ]);

        // This one closure backs every guest redirect in the app — Laravel's
        // `auth` middleware and any AuthenticationException thrown directly
        // (EnsureTenantMember does exactly that) both funnel through it. It
        // has to decide per guard, not just per host: a request rejected by
        // `auth:customer` must land on that guard's own login, never on the
        // tenant staff or superadmin one, or a customer would be bounced to
        // a form they can never authenticate against. Read off the matched
        // route's own middleware rather than the path or route name — that
        // stays correct even for routes (like the admin gate) that throw the
        // exception without declaring an `auth:*` middleware at all.
        // Laravel's own redirect()->guest() call (Handler::unauthenticated())
        // already stores the intended URL before landing here, so no extra
        // machinery is needed to send the customer back after logging in.
        $middleware->redirectGuestsTo(function ($request) {
            $routeMiddleware = $request->route()?->gatherMiddleware() ?? [];

            if (in_array('auth:customer', $routeMiddleware, true)) {
                return route('storefront.customers.login');
            }

            return str_starts_with(ltrim($request->path(), '/'), 'superadmin')
                ? route('platform.login')
                : route('login');
        });

        // The mirror image of the guest redirect above: Laravel's `guest`
        // middleware (RedirectIfAuthenticated) defaults to route('dashboard')
        // for anyone already authenticated — a staff-only Inertia page. Left
        // at that default, a signed-in customer opening /prihlaseni or
        // /registrace (guest:customer) would be bounced there, fail the
        // dashboard's own `auth` (web guard) check, and land on the tenant
        // staff login instead — confusing at best, and on a shop with no
        // staff account handy, a dead end. Same per-guard read as
        // redirectGuestsTo: gathered route middleware, not path or name, so
        // it stays correct for any future guest:* group.
        $middleware->redirectUsersTo(function ($request) {
            $routeMiddleware = $request->route()?->gatherMiddleware() ?? [];

            if (in_array('guest:customer', $routeMiddleware, true)) {
                return route('storefront.customers.account');
            }

            return route('dashboard');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Renamed slugs keep answering (spec §15.3). Hung off the 404 rather
        // than added to the web pipeline: a middleware would cost a lookup on
        // every request, and a redirect only ever matters where no route
        // matched. Returning null falls through to the normal 404.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return app(RedirectResponder::class)->respond($request);
        });
    })->create();

// Local development uses .env.local so the shared .env stays untouched.
// Note: Laravel loads this file INSTEAD of .env, never merged on top of it,
// so .env.local must be a complete environment file. Absent in production.
if (file_exists($app->basePath('.env.local'))) {
    $app->loadEnvironmentFrom('.env.local');
}

return $app;
