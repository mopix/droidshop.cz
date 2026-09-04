<?php

namespace App\Http\Middleware;

use App\Core\PageCache\Dimension;
use App\Core\Tenancy\TenantContext;
use App\Core\Theme\ThemeRegistry;
use App\Core\Theme\ThemeViewPaths;
use App\Models\Tenant;
use App\Models\TenantTheme;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Points the view finder at the theme this shop runs.
 *
 * Runs on every request of the web group rather than only on storefront
 * routes, and always calls apply() — including for a request with no tenant
 * at all. Skipping the call would leave whatever the previous request set,
 * which in a queue worker or under Octane means one shop's storefront
 * rendered with another shop's theme.
 *
 * Only namespaces a theme actually declares are redirected (see
 * ThemeViewPaths), so an admin request rendering a module's own view is
 * unaffected by whichever theme the shop happens to have picked.
 */
class ApplyTenantTheme
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ThemeRegistry $themes,
        private readonly ThemeViewPaths $paths,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->current();

        $this->paths->apply($this->themes->find(
            $tenant === null ? null : $this->templateFor($tenant),
        ));

        return $next($request);
    }

    /**
     * Which theme this shop runs, without a query on the common path.
     *
     * This middleware runs in front of the page cache, so a plain lookup would
     * put a database query on every cache hit — the one request that is
     * supposed to cost almost nothing. The key carries the tenant's theme
     * generation, which PageCacheObserver already bumps whenever tenant_theme
     * is written, so a shop that switches theme reads through on its very next
     * request and nothing has to remember to forget this entry.
     */
    private function templateFor(Tenant $tenant): string
    {
        $generation = (int) ($tenant->{Dimension::Theme->column()} ?? 1);

        return Cache::remember(
            "tenant:{$tenant->id}:theme-template:{$generation}",
            now()->addDay(),
            // Never null: a cached null is indistinguishable from a cache
            // miss, so a shop that has no tenant_theme row at all — the
            // common case for a fresh shop — would query on every request.
            fn (): string => TenantTheme::query()->where('tenant_id', $tenant->id)->value('template')
                ?? (string) config('themes.default', 'base'),
        );
    }
}
