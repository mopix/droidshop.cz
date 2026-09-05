<?php

namespace Modules\Storefront\Http\Controllers;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Catalog\ProductQuery;
use App\Core\Shop\ShopSettingsService;
use App\Core\Storefront\Contracts\StorefrontHome;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Categories\Models\Category;
use Modules\Storefront\Enums\BlockType;
use Modules\Storefront\Models\HomepageBlock;
use Modules\Storefront\Support\Seo;
use Modules\Storefront\Support\ShopModules;

/**
 * The shop homepage. Reached through the kernel's root route, see
 * App\Core\Storefront\Contracts\StorefrontHome.
 */
class HomeController implements StorefrontHome
{
    public function __construct(
        private readonly ProductCatalog $catalog,
        private readonly TenantContext $context,
        private readonly ShopModules $modules,
        private readonly ShopSettingsService $settings,
    ) {}

    public function moduleKey(): string
    {
        return 'storefront';
    }

    public function render(Request $request): View
    {
        // Which tab of a product-tabs block is open. One number for the whole
        // page rather than one per block: it travels in the URL, it is part of
        // the page-cache key, and a key that grows a segment per block is a
        // key nobody can reason about. A homepage with two such blocks is not
        // a shape anyone has asked for.
        $activeTab = (int) $request->query('zalozka', 1);

        $tenant = $this->context->current();
        $shopName = $tenant?->name ?? config('app.name');
        $settings = $this->settings->forCurrentTenant();

        $blocks = HomepageBlock::query()->visible()->orderBy('position')->get()
            ->map(fn (HomepageBlock $block) => $this->prepare($block, $activeTab))
            ->filter() // Dropped (module off) blocks turn into null → removed.
            ->values();

        // Passed explicitly as well as composed onto the layout: section bodies
        // are evaluated before the layout, so the composer's data has not been
        // bound yet when this view runs.
        return view('storefront::home', [
            'shopName' => $shopName,
            'blocks' => $blocks,
            // The merchant's own title and description if they wrote one
            // (wave 3.6); otherwise the derived pair this page always used.
            // Empty degrades to the old behaviour rather than to an empty
            // <title>, which is worse than the automatic one it replaced.
            'seo' => new Seo(
                title: $settings->seoTitleOr($shopName),
                description: $settings->seo_description ?: 'Nakupujte v e-shopu '.$shopName.'.',
                canonical: Seo::canonicalFor('/'),
            ),
        ]);
    }

    /** @return array{type: string, data: array}|null */
    private function prepare(HomepageBlock $block, int $activeTab = 1): ?array
    {
        return match ($block->type) {
            BlockType::Hero => ['type' => 'hero', 'data' => $block->payload],
            BlockType::Slider => ['type' => 'slider', 'data' => [
                'id' => $block->id,
                'slides' => array_values($block->payload['slides'] ?? []),
            ]],
            BlockType::UspStrip => ['type' => 'usp-strip', 'data' => [
                'items' => array_values($block->payload['items'] ?? []),
            ]],
            BlockType::BannerGrid => ['type' => 'banner-grid', 'data' => [
                'banners' => array_values($block->payload['banners'] ?? []),
            ]],
            BlockType::Text => ['type' => 'text', 'data' => $block->payload],
            BlockType::Banner => ['type' => 'banner', 'data' => $block->payload],
            BlockType::ProductRow => $this->modules->has('products')
                ? ['type' => 'product-row', 'data' => [
                    'id' => $block->id,
                    'heading' => $block->payload['heading'] ?? null,
                    'products' => $this->rowProducts($block->payload),
                ]]
                : null,
            BlockType::ProductTabs => $this->modules->has('products')
                ? ['type' => 'product-tabs', 'data' => [
                    'id' => $block->id,
                    'heading' => $block->payload['heading'] ?? null,
                    'active' => $this->activeTabIndex($block->payload, $activeTab),
                    'tabs' => $this->tabs($block->payload, $this->activeTabIndex($block->payload, $activeTab)),
                ]]
                : null,
            BlockType::CategoryGrid => $this->modules->has('categories')
                ? ['type' => 'category-grid', 'data' => [
                    'heading' => $block->payload['heading'] ?? null,
                    'categories' => $this->gridCategories($block->payload),
                ]]
                : null,
            BlockType::CategoryMosaic => $this->modules->has('categories')
                ? ['type' => 'category-mosaic', 'data' => [
                    'heading' => $block->payload['heading'] ?? null,
                    'layout' => in_array($block->payload['layout'] ?? null, BlockType::MOSAIC_LAYOUTS, true)
                        ? $block->payload['layout']
                        : BlockType::MOSAIC_LAYOUTS[0],
                    'categories' => $this->gridCategories($block->payload),
                ]]
                : null,
        };
    }

    /**
     * A tab number from the query string, clamped to what exists.
     *
     * Out of range falls back to the first tab rather than to an empty
     * section: `?zalozka=99` is a stale link or a crawler guessing, and either
     * way the shop should show its goods.
     *
     * @param  array<string, mixed>  $payload
     */
    private function activeTabIndex(array $payload, int $requested): int
    {
        $count = count($payload['tabs'] ?? []);

        return $requested >= 1 && $requested <= $count ? $requested : 1;
    }

    /**
     * Only the open tab is filled with products.
     *
     * The closed ones are links, so their goods are one request away — loading
     * all of them would mean four catalogue queries and four sets of images on
     * a page where a visitor looks at one.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function tabs(array $payload, int $active): array
    {
        $tabs = [];

        foreach (array_values($payload['tabs'] ?? []) as $index => $tab) {
            $number = $index + 1;

            $tabs[] = [
                'number' => $number,
                'label' => (string) ($tab['label'] ?? ''),
                'active' => $number === $active,
                'products' => $number === $active ? $this->tabProducts($tab) : collect(),
            ];
        }

        return $tabs;
    }

    /**
     * @param  array<string, mixed>  $tab
     * @return Collection<int, mixed>
     */
    private function tabProducts(array $tab): Collection
    {
        $mode = $tab['mode'] ?? 'latest';
        $count = (int) ($tab['count'] ?? 6);

        if ($mode === 'manual') {
            return collect($tab['product_ids'] ?? [])
                ->map(fn ($id) => $this->catalog->findById((int) $id))
                ->filter()
                ->values();
        }

        if ($mode === 'category' && ! empty($tab['category_id'])) {
            // Products of that one category, not of its subtree: a tab named
            // after a category promises what is in it, and pulling the whole
            // branch would quietly show goods from a category the tenant did
            // not name.
            return collect($this->catalog->paginate(new ProductQuery(
                categoryIds: [(int) $tab['category_id']],
                perPage: $count,
            ))->items());
        }

        return $this->catalog->latest($count);
    }

    private function rowProducts(array $payload): Collection
    {
        if (($payload['mode'] ?? 'latest') === 'manual') {
            return collect($payload['product_ids'] ?? [])
                ->map(fn ($id) => $this->catalog->findById((int) $id))
                ->filter() // A vanished or hidden product is dropped.
                ->values();
        }

        return $this->catalog->latest((int) ($payload['count'] ?? 8));
    }

    private function gridCategories(array $payload): Collection
    {
        $ids = $payload['category_ids'] ?? [];
        $query = Category::query()->visible();

        return empty($ids)
            ? $query->whereNull('parent_id')->orderBy('position')->get()
            : $query->whereIn('id', $ids)->orderBy('position')->get();
    }
}
