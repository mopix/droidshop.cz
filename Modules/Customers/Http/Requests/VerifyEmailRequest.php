<?php

namespace Modules\Customers\Http\Requests;

use App\Core\Tenancy\TenantContext;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Throttles the public "confirm a verification link" endpoint.
 *
 * Keyed by tenant + IP only, unlike LoginRequest and ResendVerificationRequest:
 * there is no signed-in identity on this route at all (see
 * EmailVerificationController::verify() for why it is neither guest:customer
 * nor auth:customer). The 64-character token already makes brute force
 * pointless, so this is not protecting a secret — it exists only to cap a
 * cheap, unauthenticated, two-query-per-hit endpoint against flooding. The
 * limit is deliberately generous: a customer clicking their own link twice,
 * or opening it from two devices, must never trip it.
 */
class VerifyEmailRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 20;

    private const DECAY_SECONDS = 60;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "Příliš mnoho pokusů. Zkuste to znovu za {$seconds} s.",
        ]);
    }

    public function hit(): void
    {
        RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);
    }

    /**
     * No email or customer identity in the key: at this point in the request
     * we have not looked anything up yet, and the whole point is to avoid
     * spending a DB query before the throttle has had its say.
     */
    private function throttleKey(): string
    {
        return 'customer-verify|'
            .app(TenantContext::class)->id().'|'
            .$this->ip();
    }
}
