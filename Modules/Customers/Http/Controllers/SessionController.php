<?php

namespace Modules\Customers\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Customers\Http\Requests\LoginRequest;
use Modules\Storefront\Support\Seo;

class SessionController
{
    public function create(): View
    {
        return view('customers::storefront.login', [
            'seo' => new Seo(title: 'Přihlášení', noindex: true),
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        Auth::guard('customer')->user()->forceFill(['last_login_at' => now()])->save();

        // Hardcoded path, not route('storefront.customers.account'): the
        // account page is built in Task 5 and the named route does not exist
        // yet — resolving it here would throw before the redirect ever left
        // the controller. Task 5 adds storefront.customers.account at this
        // same path; swap this literal for the named route deliberately then,
        // rather than leaving it hardcoded forever.
        return redirect()->intended('/ucet');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('customer')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
