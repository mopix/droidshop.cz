<?php

/*
 * Reasons App\Core\Discounts\DiscountRejection can carry, keyed by its
 * constants. Looked up with an explicit 'cs' locale (__($key, [], 'cs')),
 * never through the app's own locale — config('app.locale') is 'en' in this
 * project (translation files are not otherwise used; UI strings are plain
 * Czech literals in code and Blade), so relying on locale resolution here
 * would silently print the raw key instead of the message.
 */

return [
    'rejection' => [
        'not_found' => 'takový kód neexistuje.',
        'inactive' => 'kód je vypnutý.',
        'expired' => 'platnost kódu skončila.',
        'not_started' => 'kód ještě není platný.',
        'min_cart' => 'košík nedosahuje minimální hodnoty.',
        'no_eligible_items' => 'v košíku není zboží, na které kód platí.',
        'requires_login' => 'kód platí jen pro přihlášené zákazníky.',
        'first_order_only' => 'kód platí jen pro první objednávku.',
        'usage_limit' => 'kód je vyčerpaný.',
        'email_limit' => 'tento kód jste už použili.',
    ],
];
