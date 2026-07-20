<?php

namespace Modules\Customers\Http\Controllers;

use App\Core\Mail\Contracts\MailService;
use App\Core\Mail\MailKind;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Customers\Http\Requests\RequestPasswordResetRequest;
use Modules\Customers\Http\Requests\UpdatePasswordRequest;
use Modules\Customers\Mail\ResetPassword;
use Modules\Customers\Models\Customer;
use Modules\Customers\Services\CustomerTokens;
use Modules\Storefront\Support\Seo;

class PasswordResetController
{
    private const GENERIC_FAILURE = 'Odkaz pro obnovení hesla je neplatný nebo už vypršel.';

    public function request(): View
    {
        return view('customers::storefront.password-request', [
            'seo' => new Seo(title: 'Zapomenuté heslo', noindex: true),
        ]);
    }

    /**
     * Always answers identically, whether or not the address holds an
     * account here — the only way account enumeration through this endpoint
     * is not possible is for a wrong guess to be indistinguishable from a
     * right one.
     */
    public function email(RequestPasswordResetRequest $request, CustomerTokens $tokens, MailService $mail): RedirectResponse
    {
        $request->ensureIsNotRateLimited();
        $request->hit();

        $email = Str::lower((string) $request->string('email'));

        $customer = Customer::where('email', $email)->first();

        if ($customer !== null) {
            $token = $tokens->issue($email, CustomerTokens::PASSWORD_RESET);

            $resetUrl = route('storefront.customers.password.edit', [
                'token' => $token,
                'email' => $email,
            ]);

            $shopName = app(TenantContext::class)->current()?->name ?? '';

            $mail->send(new ResetPassword($resetUrl, $shopName), $email, MailKind::Transactional);
        } else {
            // Do comparable work on the not-found branch so the synchronous
            // response time does not itself reveal whether the address
            // exists — a hash costs real, roughly constant time regardless
            // of outcome. This only equalises the synchronous path: the real
            // uniformity in production comes from mail being queued
            // (MailService), so the request returns before delivery either
            // way. A dummy token row is deliberately not written here —
            // that would leak an unknown address into customer_tokens
            // instead of into a stopwatch.
            Hash::make(Str::random(32));
        }

        return redirect()->route('storefront.customers.password.request')
            ->with('status', 'Pokud u nás pod touto adresou existuje účet, poslali jsme na ni odkaz pro obnovení hesla.');
    }

    public function edit(Request $request, string $token): View
    {
        return view('customers::storefront.password-reset', [
            'seo' => new Seo(title: 'Obnovení hesla', noindex: true),
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    public function update(UpdatePasswordRequest $request, CustomerTokens $tokens): RedirectResponse
    {
        $email = Str::lower((string) $request->string('email'));

        $consumed = $tokens->consume($email, CustomerTokens::PASSWORD_RESET, (string) $request->string('token'));

        if (! $consumed) {
            // One message for every failure mode (wrong, expired, reused or
            // foreign-tenant token): a different answer would tell an
            // attacker which of those it was.
            throw ValidationException::withMessages(['email' => self::GENERIC_FAILURE]);
        }

        $customer = Customer::where('email', $email)->first();

        if ($customer === null) {
            // The token was genuinely valid and scoped to this tenant, but
            // the account it belonged to is gone (e.g. GDPR erasure between
            // issuing and consuming the token). Same message either way.
            throw ValidationException::withMessages(['email' => self::GENERIC_FAILURE]);
        }

        // The model casts password to hashed, so the plain value never lands
        // in the column as-is.
        $customer->forceFill(['password' => (string) $request->string('password')])->save();

        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();

        return redirect()->route('storefront.customers.account')->with('status', 'Heslo bylo změněno.');
    }
}
