<?php

namespace Modules\Payments\Support;

/**
 * Verifies the authenticity of a Comgate background notification (spec §16.6).
 *
 * The e-commerce protocol authenticates the notification by including the
 * merchant's own `secret` in the POST body: a caller who does not know the
 * tenant's secret cannot forge one. We compare it in constant time against the
 * secret stored for that tenant's Comgate method.
 *
 * This is a first gate only. Even a signature match does not settle a payment —
 * the webhook still re-queries the gateway's status API (verify-before-trust),
 * because the notification body, secret and all, is not the source of truth.
 */
final class ComgateSignature
{
    public static function matches(?string $provided, string $expected): bool
    {
        if (! is_string($provided) || $provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }
}
