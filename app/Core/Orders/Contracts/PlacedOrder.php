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
     * The chosen method's provider key (e.g. 'comgate'), or null when no
     * payment method was selected. Whether it needs a gateway redirect is the
     * caller's question for PaymentGatewayRegistry — an offline provider
     * (cash on delivery, bank transfer) resolves to no driver and the shopper
     * goes straight to the thank-you page; an online one is redirected first.
     */
    public function paymentProvider(): ?string;
}
