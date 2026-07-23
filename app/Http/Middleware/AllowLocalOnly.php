<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to requests originating on the machine itself.
 *
 * Defense in depth for the internal-only routes (currently just Caddy's
 * on-demand TLS ask endpoint, wave 2.1): the primary control is that the
 * edge process and the app share localhost and nothing else routes to this
 * port from outside, but a firewall misconfiguration must not turn into a
 * public TLS-issuance oracle. 404, not 403 — a stranger probing this path
 * should not learn that it exists.
 */
class AllowLocalOnly
{
    private const ALLOWED_IPS = ['127.0.0.1', '::1'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->ip(), self::ALLOWED_IPS, true)) {
            abort(404);
        }

        return $next($request);
    }
}
