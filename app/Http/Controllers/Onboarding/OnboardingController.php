<?php

namespace App\Http\Controllers\Onboarding;

use App\Core\Tenancy\Exceptions\SubdomainTaken;
use App\Core\Tenancy\TenantProvisioner;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\CreateShopRequest;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Onboarding/Wizard', [
            'plans' => Plan::where('is_public', true)
                ->orderBy('price_month')
                ->get(['id', 'key', 'name', 'price_month', 'price_year', 'limits']),
        ]);
    }

    public function store(CreateShopRequest $request, TenantProvisioner $provisioner): RedirectResponse
    {
        $plan = Plan::findOrFail($request->integer('plan_id'));

        try {
            $tenant = $provisioner->provision(
                $request->user(),
                $request->string('shop_name')->toString(),
                $request->string('subdomain')->toString(),
                $plan,
            );
        } catch (SubdomainTaken) {
            return back()->withErrors(['subdomain' => 'Tato subdoména je již obsazená.'])->withInput();
        }

        // B4 replaces this with a signed cross-host auto-login redirect.
        return redirect()->route('dashboard')->with('status', "E-shop {$tenant->name} byl vytvořen.");
    }
}
