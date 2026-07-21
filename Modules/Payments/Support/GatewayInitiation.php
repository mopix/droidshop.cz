<?php

namespace Modules\Payments\Support;

use App\Core\Payments\Contracts\PaymentInitiation;

/**
 * A driver's answer to initiate(): the redirect target and the gateway's own
 * transaction reference, which the caller persists on the order.
 */
final readonly class GatewayInitiation implements PaymentInitiation
{
    public function __construct(
        private string $redirectUrl,
        private string $reference,
    ) {}

    public function redirectUrl(): string
    {
        return $this->redirectUrl;
    }

    public function reference(): string
    {
        return $this->reference;
    }
}
