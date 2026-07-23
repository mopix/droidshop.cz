<?php

return [
    /*
     * IP address of the edge (Caddy on-demand TLS) server that tenant CNAME
     * records must resolve to. Used to render setup instructions and to
     * verify DNS ownership during custom domain onboarding.
     */
    'server_ip' => env('PLATFORM_SERVER_IP'),

    /*
     * Hostname tenants CNAME their custom domain to (or the value shown in
     * setup instructions alongside server_ip).
     */
    'edge_host' => env('PLATFORM_EDGE_HOST', 'edge.droidshop.cz'),

    /*
     * DNS TXT record name prefix used to verify domain ownership before we
     * ever attempt to issue a certificate for it. Not configurable via env:
     * changing it would silently break in-flight verifications.
     */
    'challenge_prefix' => '_droidshop-challenge',

    /*
     * Maximum number of times we probe the edge for a freshly issued
     * certificate before giving up and surfacing an error to the tenant.
     */
    'cert_probe_max_attempts' => 10,

    /*
     * How long a custom domain may sit unverified before we consider it
     * abandoned and eligible for cleanup.
     */
    'pending_ttl_hours' => 48,

    /*
     * Backoff between automatic re-checks of a domain stuck failing DNS
     * verification, in minutes.
     */
    'dns_backoff_minutes' => 15,

    /*
     * TTL, in seconds, for the edge's on-demand TLS "ask" endpoint response
     * cache — how long Caddy trusts a positive answer before asking again.
     */
    'tls_check_ttl' => 60,

    /*
     * Shared secret Caddy must present (as ?token=) when calling the
     * on-demand TLS "ask" endpoint. The `internal.local` middleware alone is
     * not sufficient: when Caddy reverse-proxies to the app on the same
     * host, REMOTE_ADDR is 127.0.0.1 for every request it forwards,
     * including public storefront traffic — the localhost check cannot tell
     * Caddy's own ask call from a request a stranger routed through the
     * proxy. A null/empty token always denies (fail closed), so this must
     * be set before on-demand TLS works in any environment.
     */
    'tls_check_token' => env('PLATFORM_TLS_CHECK_TOKEN'),
];
