<?php

return [
    /*
     * Version of the platform's terms a tenant agrees to at registration,
     * stored alongside the timestamp on users.terms_version.
     *
     * A date, not a running number: the documents live in docs/legal/ and
     * their history is git's, so the only thing this has to answer is "which
     * wording was in force when they clicked". Bump it whenever a change to
     * the terms is substantive enough that consent to the old wording no
     * longer covers the new one.
     */
    'terms_version' => env('LEGAL_TERMS_VERSION', '2026-08-05'),

    /*
     * Effective date printed on the rendered documents. Kept separate from
     * terms_version so a typo fix can be published without invalidating
     * every tenant's recorded consent.
     */
    'effective_from' => env('LEGAL_EFFECTIVE_FROM', '2026-08-05'),
];
