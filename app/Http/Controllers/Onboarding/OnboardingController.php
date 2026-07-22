<?php

namespace App\Http\Controllers\Onboarding;

use App\Core\Tenancy\Exceptions\SubdomainTaken;
use App\Core\Tenancy\TenantProvisioner;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\CreateShopRequest;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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

    public function store(CreateShopRequest $request, TenantProvisioner $provisioner): RedirectResponse|SymfonyResponse
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

        // The owner is authenticated on the platform host, but the shop admin
        // lives on the tenant's own subdomain, and SESSION_DOMAIN=null keeps
        // cookies host-only — the platform session does not carry over. A
        // short-lived signed URL, minted on and consumed on the tenant host,
        // establishes the session there instead (same pattern as
        // ImpersonationController::start / FileStorage::signedUrl). Laravel's
        // signature covers the full absolute URL including host, so the root
        // must be forced onto the tenant domain before signing, not rewritten
        // after.
        $host = $tenant->primaryDomain->domain;
        $previousRoot = URL::to('/');
        URL::forceRootUrl($request->getScheme().'://'.$host);

        try {
            $target = URL::temporarySignedRoute('onboarding.enter', now()->addMinutes(5), [
                'user' => $request->user()->id,
            ]);
        } finally {
            URL::forceRootUrl($previousRoot);
        }

        // Not redirect()->away(): the wizard is an Inertia page, and axios
        // would follow a plain redirect itself into a cross-origin request the
        // tenant domain never answers. Inertia::location hands the URL back to
        // the browser to visit, and still plain-redirects a non-Inertia POST.
        return Inertia::location($target);
    }
}
