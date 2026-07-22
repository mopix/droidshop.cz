<?php

return [
    /*
     * Trial length and dunning grace, in days. The lifecycle sweeper reads
     * these so they can be tuned without a migration (spec §9).
     */
    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 14),
    'grace_days' => (int) env('BILLING_GRACE_DAYS', 7),

    /*
     * Subscription gateway driver. 'null' = no real charge (dev auto-success).
     * 'stripe' arrives in wave 1.8.
     */
    'subscription' => [
        'driver' => env('BILLING_SUBSCRIPTION_DRIVER', 'null'),
    ],

    /*
     * Stripe API credentials and portal configuration.
     */
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // Billing Portal configuration id (bprc_...) created in Stripe dashboard.
        'portal_config' => env('STRIPE_PORTAL_CONFIG'),
    ],

    /*
     * The platform's own billing identity — supplier on the subscription
     * invoice we issue to the tenant. Placeholder values; fill before launch.
     */
    'company' => [
        'name' => env('BILLING_COMPANY_NAME', 'Miroslav Opletal'),
        'ico' => env('BILLING_COMPANY_ICO', ''),
        'dic' => env('BILLING_COMPANY_DIC', ''),
        'address' => env('BILLING_COMPANY_ADDRESS', ''),
        'vat_payer' => (bool) env('BILLING_COMPANY_VAT_PAYER', false),
    ],

    /*
     * VAT rate applied to the subscription fee, in percent. 21 = CZ standard.
     */
    'vat_rate' => (int) env('BILLING_VAT_RATE', 21),

    /*
     * Number series prefix for platform invoices: PF{YYYY}{NNNN}.
     */
    'invoice_prefix' => env('BILLING_INVOICE_PREFIX', 'PF'),
];
