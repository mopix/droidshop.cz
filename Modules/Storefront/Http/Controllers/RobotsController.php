<?php

namespace Modules\Storefront\Http\Controllers;

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
    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(): Response
    {
        $tenant = $this->context->current();

        abort_if($tenant === null, 404);

        $lines = $tenant->allowsStorefront()
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
