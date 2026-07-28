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
    /** Per cart token — the tight bound on one shopper's basket. */
    private const MAX_ATTEMPTS_PER_CART = 10;

    /**
     * Per IP — the outer bound that survives cart-token rotation. Higher than
     * the per-cart ceiling on purpose: a household or an office behind one NAT
     * address shares it legitimately, so this must not fire on ordinary use.
     */
    private const MAX_ATTEMPTS_PER_IP = 30;

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
     * TWO independent limiters, refused if EITHER trips. One key holding all
     * three components would not do it (re-review finding): a single
     * concatenation means changing any one component mints a fresh bucket, so
     * an attacker just calls the unthrottled `POST /kosik`, gets a brand new
     * valid cart token, and buys another ten guesses — roughly eleven requests
     * per ten guesses, unbounded. Cookie encryption stops a FORGED token, not a
     * minted one. The per-IP limiter is what actually bounds that loop; the
     * per-cart limiter is the tighter one that stops a single shopper's basket
     * from grinding through codes.
     *
     * Both are checked before either is hit, so a request already refused by
     * one limiter does not still spend an attempt against the other.
     *
     * Every attempt counts, successful ones included, and nothing clears the
     * counters (LoginRequest clears on a successful login; there is no
     * equivalent "you are in now" moment here). Ten codes a minute is far more
     * than a shopper typing one out of a newsletter ever needs, and treating a
     * hit as free would hand an attacker a free probe for every code they
     * happen to guess right.
     */
    protected function passedValidation(): void
    {
        $ipKey = $this->ipThrottleKey();
        $cartKey = $this->cartThrottleKey();

        $this->refuseIfExhausted($ipKey, self::MAX_ATTEMPTS_PER_IP);
        $this->refuseIfExhausted($cartKey, self::MAX_ATTEMPTS_PER_CART);

        RateLimiter::hit($ipKey, self::DECAY_SECONDS);
        RateLimiter::hit($cartKey, self::DECAY_SECONDS);
    }

    private function refuseIfExhausted(string $key, int $maxAttempts): void
    {
        if (! RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return;
        }

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            // Deliberately the same message for both limiters: which one the
            // caller tripped is nobody's business but ours, and a distinct
            // message would tell a bot whether rotating its cart token is
            // buying it anything.
            'code' => "Příliš mnoho pokusů o uplatnění slevového kódu. Zkuste to znovu za {$seconds} s.",
        ]);
    }

    /**
     * The outer bound: everything one address may try at this shop, whatever
     * cart cookie it presents. Higher ceiling than the per-cart one because a
     * household or an office behind one NAT address legitimately shares it.
     *
     * Tenant-scoped (same rule as LoginRequest): one shop's bot must not lock
     * a shopper out of an unrelated shop on the platform.
     */
    private function ipThrottleKey(): string
    {
        return 'discount-apply-ip|'.app(TenantContext::class)->id().'|'.$this->ip();
    }

    /**
     * The tighter bound, per basket. Catches a bot cycling addresses behind one
     * cart, which the IP key above would miss.
     */
    private function cartThrottleKey(): string
    {
        return 'discount-apply|'
            .app(TenantContext::class)->id().'|'
            .(CartCookie::read($this) ?? '-').'|'
            .$this->ip();
    }
}
