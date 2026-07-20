<?php

namespace Modules\Storefront\Http\Controllers;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Storefront\Contracts\StorefrontHome;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Modules\Categories\Models\Category;
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

        // Passed explicitly as well as composed onto the layout: section bodies
        // are evaluated before the layout, so the composer's data has not been
        // bound yet when this view runs.
        return view('storefront::home', [
            'shopName' => $tenant?->name ?? config('app.name'),
            'products' => $this->modules->has('products') ? $this->catalog->latest(8) : collect(),
            'categories' => $this->modules->has('categories')
                ? Category::query()->visible()->whereNull('parent_id')->orderBy('position')->get()
                : collect(),
            'seo' => new Seo(
                title: $tenant?->name ?? config('app.name'),
                description: 'Nakupujte v e-shopu '.($tenant?->name ?? config('app.name')).'.',
                canonical: Seo::canonicalFor('/'),
            ),
        ]);
    }
}
