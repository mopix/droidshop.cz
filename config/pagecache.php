<?php

return [

    /*
     * Global off switch (spec: superadmin emergency brake). Turning this off
     * returns the storefront to its pre-wave-3.0 behaviour without touching
     * a line of code.
     */
    'enabled' => env('PAGE_CACHE_ENABLED', true),

    /*
     * Cache store to use. Null means the application default. No tags are
     * used anywhere, so file, database and Redis all work.
     *
     * `?: null` because .env.example ships the key present but empty, and an
     * empty string is not the same as an absent one: Cache::store('') throws
     * "Cache store [] is not defined" rather than falling back to the default.
     */
    'store' => env('PAGE_CACHE_STORE') ?: null,

    'ttl' => [
        'default' => 600,       // 10 min — spec §15.6
        'not_found' => 3600,    // in-route 404s (unknown slug)
        'search' => 300,
    ],

    /*
     * Query parameters allowed to fragment the cache. Everything else is
     * dropped from the key, so marketing parameters land on the same entry
     * as the bare URL and nobody can mint unbounded keys.
     */
    'query_whitelist' => ['razeni', 'skladem', 'page', 'q'],

    /*
     * Search terms longer than this are never cached — `?q=` has unbounded
     * cardinality and is the obvious way to fill the store on purpose.
     */
    'search_term_max' => 60,

];
