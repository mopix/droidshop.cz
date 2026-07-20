<?php

namespace Modules\Customers\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Evicts this session the instant the signed-in customer's password stops
 * matching the hash this session was issued under.
 *
 * Laravel ships this exact idea as Illuminate\Session\Middleware\AuthenticateSession,
 * but it is not usable here: internally it calls $request->user() with no
 * guard argument and keys its stored hash off config('auth.defaults.guard')
 * — both of which resolve the 'web' guard, never 'customer', no matter which
 * guard the route it is attached to actually authenticates. Wiring it onto
 * auth:customer routes is a silent no-op: $request->user() (default guard)
 * is null there, so its very first check lets every request through
 * unchecked. AccountController::updateProfile() used to document exactly
 * this and, correctly, called nothing rather than ship a call that does
 * nothing. This class is the guard-aware equivalent: the same "compare a
 * per-session stored password hash" idea, addressed explicitly to the
 * 'customer' guard instead of assuming there is only one guard in the app.
 *
 * Self-initialising: a session's stored hash is written the first time this
 * runs for it (login redirects straight into an auth:customer route), so
 * neither SessionController nor PasswordResetController has to remember to
 * seed it. It is also what makes the device that performs a password change
 * survive its own next request — the hash is rewritten to the new one after
 * the response, not just compared before it.
 */
class AuthenticateCustomerSession
{
    private const SESSION_KEY = 'password_hash_customer';

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('customer');

        if (! $request->hasSession() || ! $guard->check()) {
            return $next($request);
        }

        $storedHash = $request->session()->get(self::SESSION_KEY);
        $currentHash = $guard->user()->getAuthPassword();

        if ($storedHash !== null && ! hash_equals($currentHash, $storedHash)) {
            $guard->logoutCurrentDevice();
            $request->session()->flush();

            throw new AuthenticationException('Unauthenticated.', ['customer']);
        }

        return tap($next($request), function () use ($request, $guard): void {
            // Re-read rather than reuse $currentHash: the request just
            // handled may itself have been the password change (e.g.
            // AccountController::updateProfile()), and the whole point is to
            // let that device carry on with its own new hash instead of
            // being logged out by its own next request.
            if ($guard->check()) {
                $request->session()->put(self::SESSION_KEY, $guard->user()->getAuthPassword());
            }
        });
    }
}
