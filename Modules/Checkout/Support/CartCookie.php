<?php

namespace Modules\Checkout\Support;

use App\Core\Checkout\Contracts\CartShape;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie as CookieFacade;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * The cart identity cookie: a host-only, cryptographically random token
 * (rozhodnutí AK 6), never an autoincrement id.
 *
 * Built directly against Symfony's Cookie rather than the `Cookie` facade
 * (`Cookie::make()`/`::queue()`): that facade falls back to
 * `config('session.domain')` whenever the domain argument is null *or*
 * empty — including an explicit `''` — so it cannot actually produce a
 * host-only cookie on a deploy where SESSION_DOMAIN is set for SSO across
 * subdomains. Constructing the cookie here bypasses that fallback entirely,
 * which is the only way to guarantee "no Domain attribute" regardless of
 * session config. EncryptCookies still sees and encrypts it like any other
 * cookie on the response, because that middleware inspects the response's
 * final Set-Cookie headers, not how they were added.
 */
final class CartCookie
{
    public const NAME = 'cart_token';

    /** Mirrors CartRepository's own 14-day cart expiry. */
    private const MINUTES = 60 * 24 * 14;

    public static function read(Request $request): ?string
    {
        $value = $request->cookie(self::NAME);

        return is_string($value) ? $value : null;
    }

    /**
     * @template TResponse of Response
     *
     * @param  TResponse  $response
     * @return TResponse
     */
    public static function attach(Response $response, CartShape $cart, Request $request): Response
    {
        // A transient cart (module deployed but not active for this tenant,
        // or no checkout module at all) has nothing persisted behind its
        // token — setting a cookie for it would only leave a cookie no
        // future request can ever resolve to anything.
        if ($cart->cartId() === null) {
            return $response;
        }

        $cookie = Cookie::create(self::NAME)
            ->withValue($cart->cartToken())
            ->withExpires(now()->addMinutes(self::MINUTES))
            ->withPath('/')
            ->withDomain(null)
            ->withSecure($request->secure())
            ->withHttpOnly(true)
            ->withSameSite('lax');

        $response->headers->setCookie($cookie);

        return $response;
    }

    /**
     * Queues the cart cookie for whatever response the current request
     * eventually produces, for a caller that runs before a Response exists
     * — namely Modules\Checkout\Listeners\MergeCartOnCustomerLogin, an auth
     * `Login` event listener.
     *
     * `Cookie::queue()` (the facade, not this class's own `Cookie` import)
     * stores a pre-built Symfony Cookie verbatim when handed one directly
     * (`Illuminate\Cookie\CookieJar::queue()` checks `instanceof Cookie`
     * before ever calling its own `make()`), so this skips the same
     * `config('session.domain')` fallback `attach()` above avoids.
     * `AddQueuedCookiesToResponse` attaches the queued cookie to the
     * response later in the request lifecycle, and `EncryptCookies` still
     * encrypts it there exactly as if `attach()` had set it directly.
     */
    public static function queueRefresh(CartShape $cart, Request $request): void
    {
        if ($cart->cartId() === null) {
            return;
        }

        $cookie = Cookie::create(self::NAME)
            ->withValue($cart->cartToken())
            ->withExpires(now()->addMinutes(self::MINUTES))
            ->withPath('/')
            ->withDomain(null)
            ->withSecure($request->secure())
            ->withHttpOnly(true)
            ->withSameSite('lax');

        CookieFacade::queue($cookie);
    }

    /**
     * Expires the cart cookie — used once an order is placed, so the next
     * visit to `/kosik` starts a fresh, empty cart rather than resolving to
     * the just-converted one (which still holds its now-ordered lines).
     *
     * @template TResponse of Response
     *
     * @param  TResponse  $response
     * @return TResponse
     */
    public static function forget(Response $response, Request $request): Response
    {
        $cookie = Cookie::create(self::NAME)
            ->withValue('')
            ->withExpires(1)
            ->withPath('/')
            ->withDomain(null)
            ->withSecure($request->secure())
            ->withHttpOnly(true)
            ->withSameSite('lax');

        $response->headers->setCookie($cookie);

        return $response;
    }
}
