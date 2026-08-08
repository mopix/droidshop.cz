<?php

namespace App\Http\Controllers;

use App\Core\Shop\ShopSettingsService;
use App\Core\Tenancy\TenantContext;
use App\Http\Middleware\EnsureShopUnlocked;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Unlocking a shop the merchant put behind a password (wave 3.6).
 */
class ShopLockController extends Controller
{
    /**
     * A lock password is short and typed by hand, so it is guessable by
     * definition. Without a limit an unattended script walks a four-digit one
     * in minutes. Same RateLimiter shape as the coupon endpoint (wave 2.6).
     */
    private const MAX_ATTEMPTS = 10;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ShopSettingsService $settings,
    ) {}

    public function unlock(Request $request): RedirectResponse
    {
        $tenant = $this->context->current();

        abort_if($tenant === null, 404);

        $request->validate(['password' => ['required', 'string']]);

        $key = 'shop-lock:'.$tenant->id.':'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'password' => 'Příliš mnoho pokusů. Zkuste to za chvíli znovu.',
            ]);
        }

        // Counted before the check, successes included: "a hit is free" would
        // hand an attacker unlimited probes at whatever they already guessed
        // right.
        RateLimiter::hit($key, 60);

        $settings = $this->settings->forCurrentTenant();

        if ($settings->lock_password === null
            || ! Hash::check($request->string('password')->toString(), $settings->lock_password)) {
            throw ValidationException::withMessages(['password' => 'Heslo není správné.']);
        }

        $request->session()->put(EnsureShopUnlocked::SESSION_KEY, $tenant->id);

        return redirect('/');
    }
}
