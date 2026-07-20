<?php

namespace Modules\Customers\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Customers\Http\Requests\RegisterRequest;
use Modules\Customers\Services\CustomerRegistrar;
use Modules\Storefront\Support\Seo;

class RegistrationController
{
    public function create(): View
    {
        return view('customers::storefront.register', [
            'seo' => new Seo(title: 'Registrace', noindex: true),
        ]);
    }

    public function store(RegisterRequest $request, CustomerRegistrar $registrar): RedirectResponse
    {
        $customer = $registrar->register($request->validated());

        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();

        // Hardcoded path, not route('storefront.customers.account'): the
        // account page is built in Task 5 and the named route does not exist
        // yet — resolving it here would throw before the redirect ever left
        // the controller. Task 5 adds storefront.customers.account at this
        // same path; swap this literal for the named route deliberately then,
        // rather than leaving it hardcoded forever.
        return redirect('/ucet')
            ->with('status', 'Účet byl založen. Poslali jsme vám ověřovací e-mail.');
    }
}
