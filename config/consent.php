<?php

return [
    /*
     * Version of the consent wording. Stored in the visitor's cookie and
     * compared on every read: a decision recorded against an older version is
     * treated as no decision at all, so bumping this re-asks everybody.
     *
     * Bump it when the set of tools changes — consent to "analytics means
     * GA4" does not cover a second analytics tool added later.
     */
    'version' => env('CONSENT_VERSION', '1'),

    /*
     * How long a decision stands before the banner asks again. Six months is
     * the interval Czech and EU supervisory practice treats as reasonable;
     * a consent that never expires is not one the visitor is aware of.
     */
    'lifetime_days' => (int) env('CONSENT_LIFETIME_DAYS', 180),
];
