<?php

namespace Modules\Customers\Http\Requests;

use App\Core\Tenancy\TenantContext;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Validates both steps of a customer password reset.
 *
 * The two steps share one class rather than living apart in two, because the
 * tenant-scoped throttle key below (see throttleKey()) belongs to the
 * request step only, and duplicating it across two request classes is a
 * worse trade-off than branching once on which fields the request actually
 * carries. `token` is what tells the two apart: only the second step
 * (spending a token to set a new password) ever posts one.
 */
class PasswordResetRequest extends FormRequest
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
        if ($this->has('token')) {
            return [
                'email' => ['required', 'string', 'email'],
                'token' => ['required', 'string'],
                'password' => ['required', 'confirmed', Password::defaults()],
            ];
        }

        return [
            'email' => ['required', 'string', 'email'],
        ];
    }

    /**
     * Throttling for the "request a reset" step only, modelled on
     * LoginRequest: five attempts per shop, address and IP. A token, once
     * issued, is one-time and expires on its own (CustomerTokens), so the
     * update step needs no throttle of its own.
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
