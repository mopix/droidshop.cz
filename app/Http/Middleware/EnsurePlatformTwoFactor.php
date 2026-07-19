<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces mandatory two-factor for superadmins (spec §15.4).
 *
 * Two states are gated:
 *  - No confirmed 2FA yet: the admin can only reach 2FA setup and logout,
 *    nothing else, until they finish enrolling.
 *  - 2FA confirmed but not yet passed this session: the admin is held at the
 *    challenge until they enter a valid code.
 */
class EnsurePlatformTwoFactor
{
    /** Session flag set once a valid code has been entered this session. */
    public const PASSED_SESSION_KEY = 'platform.2fa_passed';

    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('platform')->user();

        if ($admin === null) {
            return $next($request);
        }

        if (! $admin->hasConfirmedTwoFactor()) {
            return $request->routeIs('platform.2fa.setup*')
                ? $next($request)
                : redirect()->route('platform.2fa.setup');
        }

        if (! $request->session()->get(self::PASSED_SESSION_KEY, false)) {
            return $request->routeIs('platform.2fa.challenge*')
                ? $next($request)
                : redirect()->route('platform.2fa.challenge');
        }

        return $next($request);
    }
}
