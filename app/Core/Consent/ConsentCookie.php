<?php

namespace App\Core\Consent;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * The visitor's consent, as it travels in a cookie.
 *
 * Deliberately outside Laravel's cookie encryption (see EncryptCookies in
 * bootstrap/app.php) and deliberately NOT httpOnly: the storefront's own
 * JavaScript has to read it to hide the banner before the page paints. The
 * cookie holds three booleans and a version — nothing personal — so an XSS
 * that could read it is already running on the page and has bigger targets.
 */
class ConsentCookie
{
    public const NAME = 'cookie_consent';

    public static function read(Request $request): ?Consent
    {
        return Consent::fromCookie($request->cookie(self::NAME));
    }

    /**
     * Queues the cookie onto the next response.
     *
     * Note for page cache (wave 3.0): PageCachePolicy::mayStore() refuses any
     * response carrying a Set-Cookie, so the response that records a decision
     * is never stored. That is correct and needs no special case — it is a
     * POST, which the policy rejects anyway.
     */
    public static function queue(Consent $consent): void
    {
        Cookie::queue(Cookie::make(
            name: self::NAME,
            value: $consent->toJson(),
            minutes: (int) config('consent.lifetime_days', 180) * 24 * 60,
            path: '/',
            domain: null,
            secure: null,
            httpOnly: false,
            raw: false,
            sameSite: 'lax',
        ));
    }

    public static function forget(): void
    {
        Cookie::queue(Cookie::forget(self::NAME));
    }
}
