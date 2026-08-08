<?php

namespace App\Http\Middleware;

use App\Core\Shop\ShopSettingsService;
use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A shop the merchant has locked answers with a password form, not a
 * catalogue (wave 3.6).
 *
 * Appended to the `web` group, so it sits behind StartSession (the unlock is
 * remembered in the session) and in front of every route middleware —
 * including `page-cache`, which therefore never runs for a request this
 * rejects. PageCachePolicy refuses a locked shop as well; the two are
 * independent, because a cached page served past this middleware would make
 * the lock decorative.
 *
 * The unlock lives in the session rather than in a hand-rolled cookie: the
 * session cookie is already signed and encrypted by the framework, and a
 * cookie of our own would need its own HMAC to stop a visitor from writing
 * `unlocked=1` themselves.
 */
class EnsureShopUnlocked
{
    public const SESSION_KEY = 'shop_unlocked_for';

    /**
     * Paths that answer even while the shop is locked.
     *
     * Webhooks are the load-bearing entry: a locked shop that stops taking the
     * "this order is paid" notification loses the payment silently, and the
     * merchant finds out from the customer. The rest is machinery a locked
     * shop still needs — the admin they lock it from, the files that admin
     * serves, and robots.txt, which is how a crawler is told to go away.
     */
    private const OPEN_PREFIXES = [
        'admin',
        'superadmin',
        'onboarding',
        'impersonace',
        'soubory',
        'internal',
        'up',
        'robots.txt',
        'zamek',
        // Payment gateway and carrier server-to-server callbacks.
        'platba/notifikace',
        'zasilkovna/notifikace',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly ShopSettingsService $settings,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->current();

        if ($tenant === null) {
            return $next($request);
        }

        // Read off the tenant row, which is already loaded: the overwhelming
        // majority of requests are to shops that are not locked, and they must
        // not pay a query to find that out (the page-cache query budget from
        // wave 3.0 leaves no room for one).
        if (! $tenant->storefront_locked) {
            return $next($request);
        }

        if ($this->settings->forCurrentTenant()->lock_password === null) {
            return $next($request);
        }

        if ($this->isOpenPath($request) || $this->isUnlocked($request, $tenant->id)) {
            return $next($request);
        }

        // Staff looking at their own shop. They can already see everything
        // through the admin, and asking them for a second password to preview
        // what they just locked helps nobody.
        if (Auth::guard('web')->check()) {
            return $next($request);
        }

        return response()->view('shop-lock', [
            'shopName' => $tenant->name,
        ], 403);
    }

    private function isOpenPath(Request $request): bool
    {
        $path = trim($request->path(), '/');

        foreach (self::OPEN_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keyed by tenant id: one unlocked shop must not unlock another one the
     * same browser visits, and shops share a session cookie domain when they
     * run on subdomains of the platform.
     */
    private function isUnlocked(Request $request, int $tenantId): bool
    {
        return $request->hasSession()
            && (int) $request->session()->get(self::SESSION_KEY) === $tenantId;
    }
}
