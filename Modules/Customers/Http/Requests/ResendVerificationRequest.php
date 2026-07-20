<?php

namespace Modules\Customers\Http\Requests;

use App\Core\Tenancy\TenantContext;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Throttles "resend the verification e-mail" requests.
 *
 * No email field, unlike RequestPasswordResetRequest: the route this backs
 * requires auth:customer, so the address to mail is read from the signed-in
 * session, never from user input. Accepting an address here instead would
 * turn the endpoint into an inbox-flooding oracle open to anyone, since
 * there would be no token or session tying the request to the account it
 * claims to act on.
 */
class ResendVerificationRequest extends FormRequest
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
            'verification' => "Příliš mnoho pokusů. Zkuste to znovu za {$seconds} s.",
        ]);
    }

    public function hit(): void
    {
        RateLimiter::hit($this->throttleKey());
    }

    /**
     * Keyed by tenant and the signed-in customer, not by IP alone: a shared
     * office connection must not let one customer's resend attempts lock
     * another customer at the same shop out of theirs.
     */
    private function throttleKey(): string
    {
        return 'customer-verify-resend|'
            .app(TenantContext::class)->id().'|'
            .Auth::guard('customer')->id().'|'
            .$this->ip();
    }
}
