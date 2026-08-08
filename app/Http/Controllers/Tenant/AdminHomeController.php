<?php

namespace App\Http\Controllers\Tenant;

use App\Core\Limits\LimitsService;
use App\Core\Modules\ModuleRegistry;
use App\Core\Orders\Contracts\OrderBook;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shop's dashboard — the first screen of the admin.
 *
 * Until wave 3.5 `/admin` had no screen of its own and redirected to
 * whichever module happened to come first in the menu, which meant the owner
 * landed on a product list with no sense of how the shop was doing. The
 * grouped menu the owner asked for starts with "Nástěnka", so the entry now
 * has somewhere to go.
 *
 * Everything here is read through kernel contracts. A shop that runs no
 * orders module still gets a dashboard — with zeroes and a nudge — rather
 * than an error, which is the same guest-safe null-binding rule the rest of
 * the platform follows.
 */
class AdminHomeController extends Controller
{
    /** How far back the turnover figure looks. */
    private const WINDOW_DAYS = 30;

    public function __construct(
        private readonly TenantContext $context,
        private readonly OrderBook $orders,
        private readonly LimitsService $limits,
        private readonly ModuleRegistry $registry,
    ) {}

    public function __invoke(): Response
    {
        $tenant = $this->context->current();

        $summary = $this->orders->dashboardSummary(now()->subDays(self::WINDOW_DAYS));

        return Inertia::render('Tenant/Dashboard', [
            'shop' => [
                'name' => $tenant->name,
                'url' => $this->storefrontUrl(),
            ],
            'summary' => [
                ...$summary,
                'windowDays' => self::WINDOW_DAYS,
                'currency' => $tenant->currency ?? 'CZK',
            ],
            'usage' => [
                // Plan limits the owner is closest to bumping into. Read
                // through LimitsService so the numbers match what actually
                // blocks a write.
                'products' => $this->limits->usage('products'),
                'storageMb' => $this->limits->usage('storage_mb'),
            ],
            // Resolved here, not in the page: a module this shop does not run
            // has no registered route, and route() in the template would
            // throw rather than quietly skip the link.
            'links' => $this->links(),
        ]);
    }

    /**
     * @return array<string, string|null>
     */
    private function links(): array
    {
        return [
            'orders' => $this->moduleUrl('orders', 'admin.orders.index'),
            'products' => $this->moduleUrl('products', 'admin.products.index'),
            // Kernel screens: no module to switch off, so only the route has
            // to exist.
            'appearance' => $this->urlIfRegistered('admin.appearance.edit'),
            'domain' => $this->urlIfRegistered('admin.domain.edit'),
            'billing' => $this->urlIfRegistered('admin.billing.edit'),
        ];
    }

    /**
     * A module link, but only for a shop that actually runs the module.
     *
     * Route::has() alone is not enough and the difference is easy to miss:
     * ModuleRouteRegistrar mounts every deployed module's routes at boot
     * regardless of who switched what on, so the route always exists. It is
     * the `module` middleware that 404s per tenant — which would make this a
     * dead link on the first screen of the admin.
     */
    private function moduleUrl(string $module, string $name): ?string
    {
        if (! $this->registry->isEnabled($this->context->current(), $module)) {
            return null;
        }

        return $this->urlIfRegistered($name);
    }

    private function urlIfRegistered(string $name): ?string
    {
        return Route::has($name) ? route($name) : null;
    }

    /**
     * The shop's own address, so the owner can look at what customers see.
     *
     * The canonical domain, not the request host: an owner who set up a
     * custom domain wants to check that one, and it is the one the storefront
     * redirects to anyway (wave 2.1).
     */
    private function storefrontUrl(): ?string
    {
        $domain = Domain::query()
            ->where('tenant_id', $this->context->id())
            ->where('is_primary', true)
            ->value('domain');

        return $domain === null ? null : 'https://'.$domain;
    }
}
