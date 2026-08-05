<?php

namespace Modules\Analytics\Support;

use App\Core\Orders\Contracts\OrderView;

/**
 * What the conversion snippet needs about one completed order.
 *
 * Only what a measurement tool actually consumes: order number, value and
 * currency. No e-mail, no address, no line detail — a third-party script gets
 * the minimum that makes the conversion countable, not everything the page
 * happens to know.
 */
class PurchasePayload
{
    public function __construct(private readonly TrackingCodes $codes) {}

    /**
     * @return array<string, mixed>
     */
    public function for(OrderView $order): array
    {
        $codes = $this->codes->all();

        if ($codes === []) {
            return [];
        }

        return [
            'number' => $order->orderNumber(),
            // Major units with two decimals: every one of these tools expects
            // 1234.50, not the 123450 minor units Money carries internally.
            'value' => round($order->orderTotal()->amount / 100, 2),
            'currency' => $order->orderCurrency(),
            'ga4' => $codes['ga4'] ?? null,
            'sklikConversion' => $codes['sklikConversion'] ?? null,
            'metaPixel' => $codes['metaPixel'] ?? null,
        ];
    }
}
