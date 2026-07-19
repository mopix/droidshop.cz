<?php

namespace App\Http\Controllers\Platform\Auth;

use App\Core\Platform\TwoFactor;
use App\Http\Middleware\EnsurePlatformTwoFactor;
use App\Models\PlatformAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Enrolment and challenge for mandatory superadmin 2FA (spec §15.4).
 */
class TwoFactorController
{
    public function __construct(private readonly TwoFactor $twoFactor) {}

    /**
     * Enrolment: shows a fresh secret and its QR URI. The secret is kept in
     * the session until confirmed, so an abandoned setup leaves nothing on the
     * account.
     */
    public function setup(Request $request): Response
    {
        $admin = $this->admin($request);

        $secret = $request->session()->get('platform.2fa_setup_secret')
            ?? tap($this->twoFactor->generateSecret(), fn ($s) => $request->session()->put('platform.2fa_setup_secret', $s));

        return Inertia::render('Platform/Auth/TwoFactorSetup', [
            'secret' => $secret,
            'qr' => $this->twoFactor->provisioningUri($admin, $secret),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $admin = $this->admin($request);
        $secret = $request->session()->get('platform.2fa_setup_secret');

        $request->validate(['code' => ['required', 'string']]);

        if (! $secret || ! $this->twoFactor->verify($secret, $request->string('code'))) {
            throw ValidationException::withMessages(['code' => __('Neplatný ověřovací kód.')]);
        }

        $admin->forceFill([
            'two_fa_secret' => $secret,
            'two_fa_confirmed_at' => now(),
        ])->save();

        $codes = $admin->generateRecoveryCodes();

        $request->session()->forget('platform.2fa_setup_secret');
        $request->session()->put(EnsurePlatformTwoFactor::PASSED_SESSION_KEY, true);

        // Recovery codes are shown once, here, and never again.
        return redirect()->route('platform.dashboard')->with('recoveryCodes', $codes);
    }

    public function challenge(): Response
    {
        return Inertia::render('Platform/Auth/TwoFactorChallenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $admin = $this->admin($request);

        $request->validate(['code' => ['required', 'string']]);

        $code = $request->string('code');
        $passed = $this->twoFactor->verify((string) $admin->two_fa_secret, $code)
            || $admin->useRecoveryCode($code);

        if (! $passed) {
            throw ValidationException::withMessages(['code' => __('Neplatný ověřovací kód.')]);
        }

        $request->session()->put(EnsurePlatformTwoFactor::PASSED_SESSION_KEY, true);

        return redirect()->intended(route('platform.dashboard'));
    }

    private function admin(Request $request): PlatformAdmin
    {
        return $request->user('platform');
    }
}
