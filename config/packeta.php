<?php

return [
    // Platform-wide key used only to download the shared pickup point feed.
    // Falls back to the first configured tenant's key (see PickupPointSync),
    // so the catalogue works before we have our own Packeta account.
    'feed_api_key' => env('PACKETA_FEED_API_KEY'),

    'feed_url' => env('PACKETA_FEED_URL', 'https://www.zasilkovna.cz/api/v4/{key}/branch.json'),

    'api_url' => env('PACKETA_API_URL', 'https://www.zasilkovna.cz/api/rest'),

    // Fallback default for Packeta's own catalog id for the partner carrier
    // (PPL/DPD/GLS/Česká pošta) home delivery brokers through — used ONLY
    // when a tenant's packeta_hd shipping method has not set its own
    // settings['carrier_id'] yet (review finding, task 4: which partner
    // carrier depends on the tenant's own contract with them, so the
    // method's own setting is the actual authority, read by
    // Modules\Packeta\Services\EloquentCarrierRegistry::packetaHomeDelivery()
    // — this key never wins over it, only fills the gap before the tenant
    // configures one).
    'home_delivery_carrier_id' => env('PACKETA_HOME_DELIVERY_CARRIER_ID', ''),

    'timeout' => (int) env('PACKETA_TIMEOUT', 30),

    // A feed answer with fewer points than this is treated as broken and is
    // not applied — deactivating thousands of pickup points because of one bad
    // response would break checkout for every tenant at once.
    'feed_min_points' => (int) env('PACKETA_FEED_MIN_POINTS', 100),

    'tracking_url' => env('PACKETA_TRACKING_URL', 'https://tracking.packeta.com/cs/?id={barcode}'),

    // How long a shipment may sit claimed (status `submitting`) before a later
    // attempt is allowed to reclaim it and call the carrier again (wave 2.5,
    // fix round 2/5). This is the only way out of a row a process crashed on
    // between winning the atomic claim and writing the carrier's answer —
    // without it that row is silently unrecoverable and the order never
    // ships (see Modules\Packeta\Services\ShipmentSubmitter::claimForSubmission()).
    // Minutes, not seconds: the HTTP timeout ('timeout' above) is 30s, so any
    // crash shows up as stale within a minute of that, but the threshold must
    // stay comfortably above it so a merely slow — not crashed — request in
    // flight is never reclaimed out from under itself.
    'submit_stale_after_minutes' => (int) env('PACKETA_SUBMIT_STALE_AFTER_MINUTES', 15),
];
