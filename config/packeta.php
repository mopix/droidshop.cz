<?php

return [
    // Platform-wide key used only to download the shared pickup point feed.
    // Falls back to the first configured tenant's key (see PickupPointSync),
    // so the catalogue works before we have our own Packeta account.
    'feed_api_key' => env('PACKETA_FEED_API_KEY'),

    'feed_url' => env('PACKETA_FEED_URL', 'https://www.zasilkovna.cz/api/v4/{key}/branch.json'),

    'api_url' => env('PACKETA_API_URL', 'https://www.zasilkovna.cz/api/rest'),

    'timeout' => (int) env('PACKETA_TIMEOUT', 30),

    // A feed answer with fewer points than this is treated as broken and is
    // not applied — deactivating thousands of pickup points because of one bad
    // response would break checkout for every tenant at once.
    'feed_min_points' => (int) env('PACKETA_FEED_MIN_POINTS', 100),

    'tracking_url' => env('PACKETA_TRACKING_URL', 'https://tracking.packeta.com/cs/?id={barcode}'),
];
