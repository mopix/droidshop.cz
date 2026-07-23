<?php

namespace App\Http\Controllers\Internal;

use App\Core\Enums\DomainType;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Caddy's on-demand TLS "ask" endpoint (wave 2.1).
 *
 * Caddy calls `GET /internal/tls-check?domain=<sni-hostname>` before it will
 * request a certificate for a host it has never seen before, and treats any
 * 2xx as "issue it" and anything else as "refuse". This is therefore the
 * only thing standing between an arbitrary DNS record pointed at our edge
 * and a certificate we requested from Let's Encrypt on the internet's
 * behalf — allow anything but a verified custom domain of a tenant whose
 * storefront still answers and it becomes a rate-limit abuse / mis-issuance
 * vector, not a convenience.
 *
 * Runs outside the web pipeline (see routes/internal.php): no session, no
 * CSRF, no ResolveHost/SetTenantContext, guarded instead by the
 * `internal.local` middleware. Domain has no tenant scope, so the direct
 * query below is correct here, not a bypass.
 *
 * `internal.local` (source IP) is defense-in-depth only, not the primary
 * gate: behind a Caddy reverse proxy on the same host, REMOTE_ADDR is
 * 127.0.0.1 for every request Caddy forwards — including public storefront
 * traffic — so the IP check alone cannot tell Caddy's own ask call from a
 * stranger's request routed through the proxy. The shared-secret token
 * below is topology-independent and is the actual authority; it is checked
 * before the domain lookup/cache so an unauthenticated caller learns
 * nothing about which domains exist.
 */
class TlsCheckController extends Controller
{
    /**
     * Exposed so callers that must invalidate a cached answer (domain
     * removal) use the exact same key without duplicating the format.
     */
    public static function cacheKey(string $host): string
    {
        return 'tls-check:'.$host;
    }

    public function __invoke(Request $request): Response
    {
        $this->assertValidToken($request);

        $host = mb_strtolower(trim((string) $request->query('domain')));

        if ($host === '') {
            abort(404);
        }

        $allowed = Cache::remember(
            self::cacheKey($host),
            config('platform.tls_check_ttl', 60),
            fn () => $this->isIssuable($host),
        );

        if (! $allowed) {
            abort(404);
        }

        return response('', 200);
    }

    /**
     * A null/empty configured token always denies: an operator who forgot
     * to set PLATFORM_TLS_CHECK_TOKEN gets on-demand TLS that never issues,
     * not one that is silently wide open.
     */
    private function assertValidToken(Request $request): void
    {
        $expected = (string) config('platform.tls_check_token');
        $supplied = (string) $request->query('token');

        if ($expected === '' || ! hash_equals($expected, $supplied)) {
            abort(404);
        }
    }

    private function isIssuable(string $host): bool
    {
        $domain = Domain::query()
            ->where('domain', $host)
            ->where('type', DomainType::Custom)
            ->first();

        if ($domain === null || $domain->verified_at === null) {
            return false;
        }

        $tenant = $domain->tenant;

        return $tenant !== null && $tenant->allowsStorefront();
    }
}
