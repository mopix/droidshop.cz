<?php

namespace Modules\Storefront\Http\Controllers;

use App\Core\Catalog\Contracts\ProductCatalog;
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
    ) {}

    public function moduleKey(): string
    {
        return 'storefront';
    }

    public function render(Request $request): View
    {
        $tenant = $this->context->current();

        $blocks = HomepageBlock::query()->visible()->orderBy('position')->get()
            ->map(fn (HomepageBlock $block) => $this->prepare($block))
            ->filter() // Dropped (module off) blocks turn into null → removed.
            ->values();

        // Passed explicitly as well as composed onto the layout: section bodies
        // are evaluated before the layout, so the composer's data has not been
        // bound yet when this view runs.
        return view('storefront::home', [
            'shopName' => $tenant?->name ?? config('app.name'),
            'blocks' => $blocks,
            'seo' => new Seo(
                title: $tenant?->name ?? config('app.name'),
                description: 'Nakupujte v e-shopu '.($tenant?->name ?? config('app.name')).'.',
                canonical: Seo::canonicalFor('/'),
            ),
        ]);
    }

    /** @return array{type: string, data: array}|null */
    private function prepare(HomepageBlock $block): ?array
    {
        return match ($block->type) {
            BlockType::Hero => ['type' => 'hero', 'data' => $block->payload],
            BlockType::Text => ['type' => 'text', 'data' => $block->payload],
            BlockType::Banner => ['type' => 'banner', 'data' => $block->payload],
            BlockType::ProductRow => $this->modules->has('products')
                ? ['type' => 'product-row', 'data' => [
                    'id' => $block->id,
                    'heading' => $block->payload['heading'] ?? null,
                    'products' => $this->rowProducts($block->payload),
                ]]
                : null,
            BlockType::CategoryGrid => $this->modules->has('categories')
                ? ['type' => 'category-grid', 'data' => [
                    'heading' => $block->payload['heading'] ?? null,
                    'categories' => $this->gridCategories($block->payload),
                ]]
                : null,
        };
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
