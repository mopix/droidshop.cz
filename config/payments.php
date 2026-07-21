<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reservation TTL
    |--------------------------------------------------------------------------
    |
    | How long an online-payment order may sit unpaid before it is expired and
    | its stock returned (plan decision 5). The order's stock is decremented at
    | placement; a gateway payment that never completes would otherwise hold it
    | forever. Enforced by a delayed queue job, so it applies only when a real
    | queue runs — on the sync driver the job would fire immediately, so it is
    | not scheduled there and abandoned orders are cleared by manual cancel.
    |
    */

    'reservation_ttl_minutes' => (int) env('PAYMENTS_RESERVATION_TTL', 30),

    /*
    |--------------------------------------------------------------------------
    | Gateways
    |--------------------------------------------------------------------------
    |
    | Per-driver, non-secret configuration. Credentials (merchant id, secret)
    | are never here — they are per-tenant in payment_methods.settings, encrypted
    | (spec §16.5). Only endpoints and transport limits live in config.
    |
    */

    'comgate' => [
        // The e-commerce HTTP-POST protocol (v1.0), form-encoded. Test vs. live
        // is a request flag (the `test` field), not a separate host.
        'base_url' => env('PAYMENTS_COMGATE_BASE_URL', 'https://payments.comgate.cz/v1.0'),
        'timeout' => (int) env('PAYMENTS_COMGATE_TIMEOUT', 15),
    ],

];
