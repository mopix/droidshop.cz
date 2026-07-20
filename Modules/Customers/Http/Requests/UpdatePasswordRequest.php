<?php

namespace Modules\Customers\Http\Requests;

use App\Core\Tenancy\TenantContext;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Validates the "spend a token, set a new password" step only.
 *
 * Kept apart from RequestPasswordResetRequest deliberately — see that
 * class's docblock for why a single combined request was wrong.
 */
class UpdatePasswordRequest extends FormRequest
{
    /**
     * This step spends a 64-character random token, not a human-guessable
     * secret — the limit exists only to keep a script from brute-forcing it
     * across many requests, so it can afford to be tighter than the
     * request-a-reset step's hourly cap. Modelled on LoginRequest's own
     * five-attempts-per-minute shape rather than RequestPasswordResetRequest's:
     * this endpoint sends no mail, so there is no inbox to protect from being
     * kept topped up by an attacker resetting the counter with successes.
     */
    private const MAX_ATTEMPTS = 5;

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
        return [
            'email' => ['required', 'string', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
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

    /**
     * Recorded on a failed spend attempt only, and cleared on a successful
     * one — a customer who mistypes a password once and then gets it right
     * must not stay throttled by their own earlier mistake.
     */
    public function hit(): void
    {
        RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);
    }

    public function clearRateLimit(): void
    {
        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Keyed by tenant and IP, not by the submitted address: unlike a login
     * or a reset request, a wrong guess here says nothing about which
     * addresses hold accounts (the token itself is the secret), so there is
     * no address-scoped lockout to keep separate per shop the way
     * LoginRequest's does.
     */
    private function throttleKey(): string
    {
        return 'customer-password-update|'
            .app(TenantContext::class)->id().'|'
            .$this->ip();
    }
}
