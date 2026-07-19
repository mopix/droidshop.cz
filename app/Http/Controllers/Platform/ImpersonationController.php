<?php

namespace App\Http\Controllers\Platform;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Superadmin side of impersonation (spec §6.12).
 *
 * Platform and tenant hosts have separate, host-only session cookies, so the
 * superadmin cannot simply flip a flag and land inside the tenant. Instead a
 * short-lived signed URL is minted on the tenant's own domain; the tenant side
 * validates it and establishes the impersonated session there. This is the
 * "signed token, 30 min" the spec calls for.
 */
class ImpersonationController
{
    public function start(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'user_id' => ['required', 'integer'],
        ]);

        $tenant = Tenant::findOrFail($data['tenant_id']);
        $user = User::findOrFail($data['user_id']);

        // The target must actually own or staff this tenant; you cannot
        // impersonate someone into a shop they have no part in.
        abort_unless($tenant->users()->whereKey($user->id)->exists(), 403);

        $domain = $tenant->primaryDomain?->domain;
        abort_if($domain === null, 422, 'Tenant has no primary domain.');

        $adminId = $request->user('platform')->id;

        // Signed on the tenant's domain so the signature validates there.
        $previousRoot = URL::to('/');
        URL::forceRootUrl('https://'.$domain);

        try {
            $url = URL::temporarySignedRoute('impersonation.begin', now()->addMinutes(5), [
                'user' => $user->id,
                'admin' => $adminId,
            ]);
        } finally {
            URL::forceRootUrl($previousRoot);
        }

        return redirect()->away($url);
    }
}
