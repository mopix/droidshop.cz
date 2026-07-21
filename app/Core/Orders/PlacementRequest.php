<?php

namespace App\Core\Orders;

use App\Core\Checkout\Contracts\CartShape;

/**
 * Everything OrderPlacement::place() needs to turn a cart into an order.
 *
 * A value object rather than a pile of arguments, because the same request is
 * built by the storefront checkout controller and, later, an admin "create
 * order manually" screen — and because `checkoutToken` (the idempotency key)
 * must be computed in exactly one place, not re-derived per caller.
 */
readonly class PlacementRequest
{
    /**
     * @param  array<string, mixed>  $billing  Billing name/address, shaped for orders.billing
     * @param  array<string, mixed>|null  $shipping  Delivery address, shaped for orders.shipping; null when same as billing
     */
    public function __construct(
        public CartShape $cart,
        public ?int $shippingMethodId,
        public ?int $paymentMethodId,
        public string $email,
        public ?string $phone,
        public array $billing,
        public ?array $shipping,
        public string $checkoutToken,
        public ?int $customerId = null,
        public string $source = 'storefront',
        public ?string $note = null,
    ) {}
}
