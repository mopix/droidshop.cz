<?php

namespace App\Http\Middleware;

use App\Core\Auth\TenantPermissions;
use App\Core\Modules\NavigationBuilder;
use App\Core\Platform\Impersonation;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $impersonation = app(Impersonation::class);

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            // Shared rather than passed per screen: the platform layout shows it
            // on every superadmin page, and only ever name and e-mail — the
            // record also holds the 2FA secret.
            'admin' => fn () => $request->user('platform')?->only('name', 'email'),
            'flash' => [
                'recoveryCodes' => fn () => $request->session()->get('recoveryCodes'),
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            // The shop this request belongs to, and what its back office may
            // show. Lazy: platform hosts have no tenant and would pay for a
            // registry lookup that answers nothing.
            'tenant' => fn () => $this->tenantProps($request),

            // Drives the "complete your billing profile" banner in the tenant
            // admin layout. `current()` returns null on platform hosts, so
            // this safely reads false there too — the banner only renders
            // inside AdminLayout, which platform pages never use.
            'billingProfileComplete' => fn () => app(TenantContext::class)->current()?->billing_name !== null,

            // Drives the "you are impersonating" banner so a superadmin never
            // forgets they are acting as someone else.
            'impersonating' => $impersonation->isActive() ? [
                'user_id' => $impersonation->impersonatedUserId(),
                'admin_id' => $impersonation->impersonatorId(),
            ] : null,
        ];
    }

    /**
     * Shop identity, menu and the caller's rights inside it.
     *
     * The permission list is the user's own, not the shop's: the front end
     * uses it to hide what they cannot do. Hiding is courtesy — every one of
     * these is enforced again on the server.
     *
     * @return array<string, mixed>|null
     */
    private function tenantProps(Request $request): ?array
    {
        $tenant = app(TenantContext::class)->current();

        if ($tenant === null) {
            return null;
        }

        $user = $request->user();

        return [
            'name' => $tenant->name,
            'nav' => app(NavigationBuilder::class)->forTenant($tenant),
            'permissions' => $user === null
                ? []
                : array_values(array_filter(
                    app(TenantPermissions::class)->availableFor($tenant),
                    fn (string $permission) => app(TenantPermissions::class)
                        ->allows($user, $tenant, $permission),
                )),
        ];
    }
}
