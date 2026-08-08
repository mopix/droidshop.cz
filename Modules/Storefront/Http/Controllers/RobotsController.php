<?php

namespace Modules\Storefront\Http\Controllers;

use App\Core\Shop\ShopSettingsService;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\Response;

/**
 * Per-tenant robots.txt.
 *
 * Served by the application rather than a static file, because what it says
 * depends on the tenant: a shop that is not trading must not be crawled, and
 * the sitemap line carries the tenant's own host.
 */
class RobotsController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ShopSettingsService $settings,
    ) {}

    public function __invoke(): Response
    {
        $tenant = $this->context->current();

        abort_if($tenant === null, 404);

        // A shop that asked not to be indexed says so here as well as in the
        // meta tag (wave 3.6). One without the other is half a refusal:
        // a crawler that never fetches the page never reads its meta tag, and
        // a crawler that ignores robots.txt still reads the tag.
        $noindex = $this->settings->forCurrentTenant()->noindex;

        $lines = $tenant->allowsStorefront() && ! $noindex
            ? [
                'User-agent: *',
                'Disallow: /admin/',
                'Disallow: /kosik',
                'Disallow: /pokladna/',
                'Disallow: /dekujeme/',
                'Disallow: /platba/',
                'Disallow: /hledani',
                'Disallow: /soubory/',
                '',
                'Sitemap: '.url('/sitemap.xml'),
            ]
            : ['User-agent: *', 'Disallow: /'];

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
