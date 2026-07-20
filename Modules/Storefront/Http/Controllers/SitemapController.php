<?php

namespace Modules\Storefront\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Categories\Models\Category;
use Modules\Pages\Models\Page;
use Modules\Products\Models\Product;
use Modules\Storefront\Support\ShopModules;

/**
 * Per-tenant sitemap (spec §3.1, storefront rule).
 *
 * Built on request and cached, not written to disk: a shop's catalogue is
 * small enough for this and a stale file is worse than a slightly expensive
 * first hit. Only what a customer may actually see gets in — an unlisted
 * product submitted to a crawler is a leak of the shop's own drafts.
 */
class SitemapController
{
    /** Protocol limit per file; above it a sitemap index is required. */
    private const MAX_URLS = 50000;

    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ShopModules $modules,
    ) {}

    public function __invoke(): Response
    {
        $tenant = $this->context->current();

        abort_if($tenant === null, 404);

        $xml = Cache::remember(
            'sitemap:'.$tenant->id,
            self::CACHE_TTL,
            fn () => $this->build(),
        );

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    private function build(): string
    {
        $entries = collect([['loc' => url('/'), 'lastmod' => null]]);

        if ($this->modules->has('categories')) {
            $entries = $entries->merge(
                Category::query()->visible()->get()->map(fn (Category $category) => [
                    'loc' => url($category->url()),
                    'lastmod' => $category->updated_at,
                ])
            );
        }

        if ($this->modules->has('products')) {
            $entries = $entries->merge(
                Product::query()->published()->get()->map(fn (Product $product) => [
                    'loc' => url($product->url()),
                    'lastmod' => $product->updated_at,
                ])
            );
        }

        $entries = $entries->merge($this->pageEntries());

        if ($entries->count() > self::MAX_URLS) {
            // Splitting into a sitemap index is a task for the first shop that
            // actually gets here; until then the truncation must be loud.
            Log::warning('Sitemap exceeds the 50 000 URL limit and is being truncated.', [
                'tenant' => $this->context->id(),
                'count' => $entries->count(),
            ]);

            $entries = $entries->take(self::MAX_URLS);
        }

        return view('storefront::sitemap', ['entries' => $entries])->render();
    }

    /**
     * Static pages, if the shop runs that module at all.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function pageEntries(): Collection
    {
        if (! $this->modules->has('pages')) {
            return collect();
        }

        return Page::query()
            ->where('is_published', true)
            ->get()
            ->map(fn ($page) => [
                'loc' => url('/stranka/'.$page->slug),
                'lastmod' => $page->updated_at,
            ]);
    }
}
