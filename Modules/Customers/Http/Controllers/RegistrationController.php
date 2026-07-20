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

        return redirect()->route('storefront.customers.account')
            ->with('status', 'Účet byl založen. Poslali jsme vám ověřovací e-mail.');
    }
}
