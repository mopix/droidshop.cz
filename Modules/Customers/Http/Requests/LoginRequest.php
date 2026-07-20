<?php

namespace Modules\Customers\Http\Requests;

use App\Core\Tenancy\TenantContext;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validates and throttles a customer login, modelled on
 * App\Http\Requests\Platform\LoginRequest.
 *
 * Throttle is by shop + email + IP: a lockout at one shop must not lock the
 * same person out of another shop on the platform (they are unrelated
 * accounts, see Customer model).
 */
class LoginRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 5;

    /**
     * RateLimiter::hit() defaults to a 60-second decay when none is given,
     * so this must be passed explicitly — a login lockout stays short on
     * purpose, one minute, matching the "Zkuste to znovu za {seconds} s."
     * message below.
     */
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
            'password' => ['required', 'string'],
        ];
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::guard('customer')->attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                // One message for both wrong address and wrong password: a
                // different answer would tell an attacker which addresses
                // hold accounts at this shop.
                'email' => 'Zadané přihlašovací údaje neodpovídají žádnému účtu.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "Příliš mnoho pokusů o přihlášení. Zkuste to znovu za {$seconds} s.",
        ]);
    }

    /**
     * Keyed by tenant as well as address: a lockout at one shop must not lock
     * the same person out of another shop on the platform.
     */
    private function throttleKey(): string
    {
        return 'customer-login|'
            .app(TenantContext::class)->id().'|'
            .Str::lower((string) $this->string('email')).'|'
            .$this->ip();
    }
}
