<?php

namespace App\Core\Orders\Contracts;

use App\Core\Money\Money;

/**
 * What OrderPlacement::place() hands back the instant an order exists.
 *
 * Deliberately narrower than OrderView: this is the confirmation-page shape —
 * enough to render "Objednávka č. 2026001 přijata" and, when the chosen
 * payment method needs it, to redirect to a gateway. It is not a read view of
 * an order's full history, which is what OrderView is for.
 */
interface PlacedOrder
{
    public function uuid(): string;

    public function number(): string;

    public function total(): Money;

    /**
     * The payment provider the shopper must be sent to next (e.g. a gateway
     * key), or null when the chosen method needs no further step (cash on
     * delivery, bank transfer).
     */
    public function paymentProvider(): ?string;
}
