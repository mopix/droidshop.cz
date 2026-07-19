<?php

namespace App\Core\Platform;

use App\Models\PlatformAdmin;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor for platform admins (spec §15.4).
 *
 * Wraps google2fa so the rest of the app talks in terms of admins and codes,
 * not library internals.
 */
class TwoFactor
{
    public function __construct(private readonly Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /**
     * The otpauth:// URI a QR code encodes, so an authenticator app can enrol.
     */
    public function provisioningUri(PlatformAdmin $admin, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(
            config('app.name', 'DroidShop'),
            $admin->email,
            $secret,
        );
    }

    public function verify(string $secret, string $code): bool
    {
        // window: 1 tolerates the code from the adjacent 30s step, covering
        // small clock skew without materially widening the guess space.
        return $this->google2fa->verifyKey($secret, $code, window: 1);
    }
}
