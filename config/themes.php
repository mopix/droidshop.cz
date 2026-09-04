<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where themes live
    |--------------------------------------------------------------------------
    |
    | One directory per theme, each holding theme.json and an optional views/
    | tree. Configurable so tests can point the registry at a fixture instead
    | of the deployed themes.
    |
    */

    'path' => base_path('themes'),

    /*
    |--------------------------------------------------------------------------
    | The theme a shop gets when it never picked one
    |--------------------------------------------------------------------------
    |
    | Also the fallback for a key whose directory is gone. Must always exist
    | on disk: it is the only theme the platform is allowed to assume.
    |
    */

    'default' => 'base',

    'cache_ttl' => 3600,

];
