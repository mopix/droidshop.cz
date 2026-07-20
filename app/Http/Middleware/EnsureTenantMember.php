<?php

namespace App\Http\Middleware;

use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ties an authenticated session to the shop whose host it arrived on.
 *
 * Sessions are host-only cookies (SESSION_DOMAIN is null), so in practice a
 * user cannot carry one across shops. This does not rely on that: the cookie
 * setting is configuration, membership is the actual rule. Without the check,
 * anything that ever widened the cookie scope — a shared parent domain, a
 * custom domain, a misconfigured deploy — would silently become cross-tenant
 * admin access.
 */
class EnsureTenantMember
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->current();

        if ($tenant === null) {
            abort(404);
        }

        // Pinned to the 'web' guard explicitly: the tenant admin only ever
        // runs on 'web'. $request->user() with no guard resolves whatever
        // Auth::shouldUse() last set (falling back to the default guard),
        // which is normally 'web' too but is not guaranteed to stay that way
        // (impersonation, a console flow, a future auth path). If something
        // ever left the customer guard active here, resolving unpinned would
        // hand a Customer to belongsToTenant() below — a method only
        // App\Models\User has — and fatal instead of failing closed. Pinning
        // means a customer session simply resolves to null on this guard.
        $user = $request->user('web');

        // Throwing rather than redirecting keeps the login target in one place
        // (the guest redirect configured in bootstrap/app.php) and preserves
        // the intended URL, exactly as Laravel's own `auth` middleware does.
        if ($user === null) {
            throw new AuthenticationException;
        }

        // 403, not 404: the route's existence is already public knowledge by
        // the time the module gate let the request through.
        if (! $user->belongsToTenant($tenant)) {
            abort(403);
        }

        return $next($request);
    }
}
