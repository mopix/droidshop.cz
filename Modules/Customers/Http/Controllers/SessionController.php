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

        return redirect()->intended(route('storefront.customers.account'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('customer')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
