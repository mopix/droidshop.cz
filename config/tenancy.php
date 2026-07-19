<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform domain
    |--------------------------------------------------------------------------
    |
    | Tenants live on subdomains of this host. A request whose Host header is
    | the platform domain itself (or one of the reserved subdomains below) is
    | never resolved to a tenant.
    |
    */

    'platform_domain' => env('PLATFORM_DOMAIN', 'droidshop.cz'),

    /*
    |--------------------------------------------------------------------------
    | Reserved subdomains
    |--------------------------------------------------------------------------
    |
    | Never handed out during onboarding and never resolved as a tenant.
    | Handing out "admin" or "api" would let a tenant shadow platform routes.
    |
    */

    'reserved_subdomains' => [
        'www', 'admin', 'api', 'mail', 'smtp', 'imap', 'pop', 'ftp', 'ns1', 'ns2',
        'app', 'status', 'blog', 'docs', 'help', 'support', 'cdn', 'static',
        'assets', 'files', 'img', 'media', 'test', 'staging', 'dev', 'demo',
    ],

    /*
    |--------------------------------------------------------------------------
    | Domain lookup cache
    |--------------------------------------------------------------------------
    |
    | Host to tenant resolution runs on every storefront request, so it is
    | cached. Kept short: a freshly pointed custom domain should start working
    | without an operator flushing anything by hand.
    |
    */

    'domain_cache_ttl' => 300,

];
