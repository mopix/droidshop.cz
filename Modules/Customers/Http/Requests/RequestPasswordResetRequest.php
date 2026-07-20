<?php

namespace Modules\Customers\Http\Requests;

use App\Core\Tenancy\TenantContext;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validates and throttles the "request a reset" step only.
 *
 * Kept apart from UpdatePasswordRequest deliberately: which rules apply must
 * be decided by which endpoint is running, not by which fields the caller
 * chose to send. A single class branching on $this->has('token') let a POST
 * to the update endpoint without a token key fall back to these rules,
 * silently skipping password validation on that call.
 */
class RequestPasswordResetRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 5;

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
        ];
    }

    /**
     * Throttling for this step only, modelled on LoginRequest: five attempts
     * per shop, address and IP. A token, once issued, is one-time and
     * expires on its own (CustomerTokens), so the update step needs no
     * throttle of its own.
     */
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
     * Counted on every request regardless of whether the address exists —
     * clearing it on a "hit" would let an attacker keep a real customer's
     * inbox topped up indefinitely just by repeatedly targeting an address
     * that happens to exist.
     */
    public function hit(): void
    {
        RateLimiter::hit($this->throttleKey());
    }

    /**
     * Keyed by tenant as well as address: a lockout at one shop must not
     * throttle the same person's request at another shop.
     */
    private function throttleKey(): string
    {
        return 'customer-password-reset|'
            .app(TenantContext::class)->id().'|'
            .Str::lower((string) $this->string('email')).'|'
            .$this->ip();
    }
}
