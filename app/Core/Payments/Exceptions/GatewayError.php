<?php

namespace App\Core\Payments\Exceptions;

use RuntimeException;

/**
 * A gateway call could not be completed — transport failure, a non-zero
 * protocol code, or a response missing fields we need. Distinct from a payment
 * simply not being paid yet (that is a PaymentResult, not an error): this is
 * the gateway or the network misbehaving.
 *
 * Lives in the kernel so callers outside the payments module (the checkout,
 * settling a payment) can catch "the gateway failed" without importing a
 * module-internal class — the checkout surfaces it as "payment could not be
 * started, try again" and keeps the already-placed order intact.
 */
final class GatewayError extends RuntimeException
{
    public static function orderMissing(string $uuid): self
    {
        return new self("Order {$uuid} not found for gateway initiation.");
    }

    public static function transport(string $endpoint, int $status): self
    {
        return new self("Comgate {$endpoint} returned HTTP {$status}.");
    }

    /**
     * @param  array<string, string>  $response
     */
    public static function malformedResponse(string $endpoint, array $response): self
    {
        return new self("Comgate {$endpoint} response missing redirect or transId.");
    }

    public static function rejected(string $endpoint, string $code, string $message): self
    {
        return new self("Comgate {$endpoint} rejected the request (code {$code}: {$message}).");
    }
}
