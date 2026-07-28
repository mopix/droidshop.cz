<?php

namespace Modules\Checkout\Http\Requests;

use App\Core\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Modules\Checkout\Support\CartCookie;

/**
 * The only field a shopper ever submits about a discount. Anything else on
 * the request body is ignored on purpose: the amount, the eligible lines and
 * the discount's identity are all decided server-side (AK 5).
 *
 * `return_to` is a two-value enum, not a URL — CartDiscountController maps it
 * to a route name itself, so nothing from this request body can ever become
 * an open redirect.
 *
 * Throttled here, in the request, exactly the way every other endpoint in this
 * codebase that takes a guessable secret does it (Modules\Customers\Http\
 * Requests\LoginRequest, App\Http\Requests\Platform\LoginRequest): a discount
 * code IS a guessable secret, and the rejection reasons this endpoint answers
 * with distinguish "takový kód neexistuje" from "kód je vyčerpaný" /
 * "platnost kódu skončila" / "košík nedosahuje minimální hodnoty". Without a
 * limit that difference is a dictionary oracle — a bot walks a tenant's
 * storefront at HTTP speed, learns which codes exist, and then goes and
 * satisfies their conditions. The `web` group carries no throttle of its own
 * (bootstrap/app.php), so this is the only thing standing there.
 */
class ApplyDiscountRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 10;

    /**
     * RateLimiter::hit() defaults to 60 seconds when no decay is given, but it
     * is passed explicitly for the same reason LoginRequest passes it: the
     * window has to match the "Zkuste to znovu za {seconds} s." message below.
     */
    private const DECAY_SECONDS = 60;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
            'return_to' => ['nullable', 'in:cart,checkout'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Zadejte slevový kód.',
            'code.max' => 'Slevový kód je příliš dlouhý.',
        ];
    }

    /**
     * Runs after the rules pass and before the controller — so a throttled
     * request never reaches the discount lookup at all, which is the whole
     * point: an answer withheld is an answer that leaks nothing.
     *
     * Every attempt counts, successful ones included, and nothing clears the
     * counter (LoginRequest clears on a successful login; there is no
     * equivalent "you are in now" moment here). Ten codes a minute is far more
     * than a shopper typing one out of a newsletter ever needs, and treating a
     * hit as free would hand an attacker a free probe for every code they
     * happen to guess right.
     */
    protected function passedValidation(): void
    {
        $key = $this->throttleKey();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'code' => "Příliš mnoho pokusů o uplatnění slevového kódu. Zkuste to znovu za {$seconds} s.",
            ]);
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);
    }

    /**
     * Cart token first, then IP. The token is what identifies this shopper's
     * basket across the whole checkout, so a bot cycling IPs behind one cart
     * is still counted; the IP is what stops a bot cycling cart cookies
     * instead. Tenant-scoped as well (same rule as LoginRequest): one shop's
     * bot must not lock a shopper out of an unrelated shop on the platform.
     */
    private function throttleKey(): string
    {
        return 'discount-apply|'
            .app(TenantContext::class)->id().'|'
            .(CartCookie::read($this) ?? '-').'|'
            .$this->ip();
    }
}
