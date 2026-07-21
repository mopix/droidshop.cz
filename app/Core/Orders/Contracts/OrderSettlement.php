<?php

namespace App\Core\Orders\Contracts;

/**
 * How the payments module settles an order's payment without reaching into the
 * orders module's own model or its OrderWorkflow (spec §16.4, §16.6).
 *
 * The verify-before-trust decision — is this payment really paid? — belongs to
 * the payments module, which owns the gateway. The consequences of that
 * decision on the order — moving payment_status, returning stock, writing the
 * event — belong here, in the orders module, next to the placement logic that
 * took the stock in the first place. This contract is the seam between them,
 * the same shape as OrderPlacement and OrderBook.
 *
 * Every method is idempotent: a webhook and a browser return may both call
 * settlePaid for the same order, and only the first actually moves it.
 */
interface OrderSettlement
{
    /**
     * Binds a gateway transaction to an order, right after the gateway created
     * it. Stored server-side so the return and webhook re-verify this exact
     * reference rather than one handed to them in a request.
     */
    public function attachReference(string $uuid, string $reference): void;

    /**
     * Marks a verified-paid order paid. Returns false when it was already
     * paid (a duplicate settlement), true when this call moved it.
     */
    public function settlePaid(string $uuid, ?string $note = null): bool;

    /**
     * Marks an order's payment failed. When $returnStock is true the order's
     * lines give their stock back in the same transaction — the counterpart of
     * the decrement placement took — so an abandoned or declined online
     * payment does not hold stock forever. Returns false when the order was
     * already settled and nothing moved.
     */
    public function settleFailed(string $uuid, bool $returnStock, ?string $note = null): bool;
}
