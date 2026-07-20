<?php

namespace Modules\Customers\Http\Controllers;

use App\Core\Mail\Contracts\MailService;
use App\Core\Mail\MailKind;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Customers\Http\Requests\ResendVerificationRequest;
use Modules\Customers\Http\Requests\VerifyEmailRequest;
use Modules\Customers\Mail\VerifyEmail;
use Modules\Customers\Models\Customer;
use Modules\Customers\Services\CustomerTokens;

class EmailVerificationController
{
    private const GENERIC_FAILURE = 'Odkaz pro ověření e-mailu je neplatný nebo už vypršel.';

    /**
     * Confirms the token from a verification link.
     *
     * Deliberately not behind auth:customer, and not even guest:customer:
     * a customer very often opens this link on a different device (phone)
     * than the one they registered on (desktop), where they are signed in
     * nowhere at all. What authenticates this request is possession of the
     * token itself, not a session — so the guard here checks nothing about
     * who the caller is signed in as.
     *
     * Being public and unauthenticated, it also gets its own light,
     * IP-keyed throttle (VerifyEmailRequest) — checked before any DB lookup,
     * since this is the only endpoint in the module that costs a query
     * before a caller has proven anything about themselves.
     */
    public function verify(VerifyEmailRequest $request, string $token, CustomerTokens $tokens): RedirectResponse
    {
        $request->ensureIsNotRateLimited();
        $request->hit();

        $email = Str::lower((string) $request->query('email', ''));

        $customer = Customer::where('email', $email)->first();

        if ($customer === null) {
            return $this->failure();
        }

        $consumed = $tokens->consume($email, CustomerTokens::EMAIL_VERIFICATION, $token);

        if (! $consumed) {
            // A token that no longer works is not automatically a failure:
            // it may simply be an old copy of a link that already did its
            // job (or was superseded by a resend) for an address that is
            // verified regardless. Telling the customer "invalid link" for
            // something that already succeeded would read as an error for
            // no reason — being already done is not an error.
            if ($customer->hasVerifiedEmail()) {
                return $this->success($customer, 'E-mail je již ověřený.');
            }

            return $this->failure();
        }

        $customer->forceFill(['email_verified_at' => now()])->save();

        return $this->success($customer, 'E-mail byl úspěšně ověřen.');
    }

    /**
     * Requires auth:customer, unlike verify(): there is no token in this
     * request to authenticate the caller instead, so the signed-in session
     * is the only safe way to know which address to mail.
     */
    public function resend(ResendVerificationRequest $request, CustomerTokens $tokens, MailService $mail): RedirectResponse
    {
        /** @var Customer $customer */
        $customer = Auth::guard('customer')->user();

        if ($customer->hasVerifiedEmail()) {
            return redirect()->route('storefront.customers.account')->with('status', 'E-mail je již ověřený.');
        }

        $request->ensureIsNotRateLimited();
        $request->hit();

        $token = $tokens->issue($customer->email, CustomerTokens::EMAIL_VERIFICATION);

        $verifyUrl = route('storefront.customers.verify', [
            'token' => $token,
            'email' => $customer->email,
        ]);

        $shopName = app(TenantContext::class)->current()?->name ?? '';

        $mail->send(new VerifyEmail($verifyUrl, $shopName), $customer->email, MailKind::Transactional);

        return redirect()->route('storefront.customers.account')->with('status', 'Ověřovací e-mail byl znovu odeslán.');
    }

    private function success(Customer $customer, string $message): RedirectResponse
    {
        $destination = Auth::guard('customer')->check() && Auth::guard('customer')->id() === $customer->id
            ? route('storefront.customers.account')
            : route('storefront.customers.login');

        return redirect($destination)->with('status', $message);
    }

    private function failure(): RedirectResponse
    {
        // One message regardless of whether the token was wrong, expired,
        // already spent or scoped to another tenant — the caller must not
        // be able to tell those apart.
        return redirect()->route('storefront.customers.login')->withErrors(['email' => self::GENERIC_FAILURE]);
    }
}
