<?php

namespace App\Http\Controllers\Onboarding;

use App\Core\Tenancy\DomainTenantFinder;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Lands a freshly-provisioned (or dashboard-hopping) owner into their shop
 * admin. The URL is signed and short-lived, and it runs on the tenant's own
 * host, so it establishes the web-guard session where SESSION_DOMAIN=null keeps
 * cookies host-only. Same shape as ImpersonationController::begin.
 */
class ShopEntryController extends Controller
{
    public function enter(Request $request, User $user, DomainTenantFinder $finder): RedirectResponse
    {
        $tenant = $finder->find($request->getHost());

        // The signature only proves the URL is ours and unexpired; it says
        // nothing about who the target user actually is. Without this check,
        // any signed URL minted for user X would log X in on any tenant host.
        if ($tenant === null || ! $tenant->users()->where('users.id', $user->id)->exists()) {
            throw new AccessDeniedHttpException('Not a member of this shop.');
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect('/admin');
    }
}
