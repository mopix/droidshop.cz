<?php

namespace Modules\Customers\Support;

/**
 * Czech labels for Order::FULFILLMENT_* / PAYMENT_* string constants.
 *
 * The account order views only get an OrderView (App\Core\Orders\Contracts),
 * not the Orders module's Order model — the kernel contract exposes the raw
 * status strings, not their labels. Mirrors the same Czech wording the
 * orders admin UI already uses (resources/js/Pages/Modules/Orders/Index.vue
 * and Show.vue) so a customer and a nájemce never see the same status
 * called two different things. Duplicated rather than shared because the
 * admin's copy lives in Vue/TypeScript on the other side of the Inertia
 * boundary — there is no single PHP+TS source to point both at.
 */
final class OrderStatusLabels
{
    /** @var array<string, string> */
    private const FULFILLMENT = [
        'new' => 'Nová',
        'accepted' => 'Přijatá',
        'processing' => 'Zpracovává se',
        'shipped' => 'Odeslaná',
        'delivered' => 'Doručená',
        'cancelled' => 'Zrušená',
    ];

    /** @var array<string, string> */
    private const PAYMENT = [
        'unpaid' => 'Nezaplaceno',
        'paid' => 'Zaplaceno',
        'refunded' => 'Vráceno',
        'failed' => 'Selhala',
    ];

    public static function fulfillment(string $status): string
    {
        return self::FULFILLMENT[$status] ?? $status;
    }

    public static function payment(string $status): string
    {
        return self::PAYMENT[$status] ?? $status;
    }
}
