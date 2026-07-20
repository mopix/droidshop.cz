<?php

namespace Modules\Storefront\Providers;

use App\Core\Storefront\Contracts\StorefrontHome;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Modules\Categories\Models\Category;
use Modules\Storefront\Http\Controllers\HomeController;
use Modules\Storefront\Support\ShopModules;

class ModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StorefrontHome::class, HomeController::class);
    }

    public function boot(): void
    {
        $this->registerErrorViews();
        $this->composeLayout();
    }

    /**
     * Error pages in the shop's own template.
     *
     * Registered by appending this module's view root to view.paths rather
     * than by adding a hint: Laravel rebuilds the `errors` namespace from
     * view.paths at render time (Handler::registerErrorViewPaths), so a
     * prepended namespace is thrown away exactly when it would be used.
     * Appended, not prepended, so the application's own views still win.
     *
     * The view falls back to plain markup when there is no tenant, which keeps
     * shop chrome off the platform host.
     */
    private function registerErrorViews(): void
    {
        config()->set('view.paths', array_values(array_unique(array_merge(
            config('view.paths', []),
            [realpath(__DIR__.'/../Resources/views')],
        ))));
    }

    /**
     * Header and footer data for every storefront page.
     *
     * A composer rather than a repeated controller concern: forgetting it in
     * one controller would render a page with no navigation, and that is the
     * kind of omission nobody notices until a customer reports it.
     */
    private function composeLayout(): void
    {
        View::composer('storefront::layouts.shop', function ($view): void {
            $tenant = app(TenantContext::class)->current();
            $shopModules = app(ShopModules::class);
            $hasCustomers = $shopModules->has('customers');

            $view->with([
                'shopName' => $tenant?->name ?? config('app.name'),
                'navCategories' => ! $shopModules->has('categories') ? collect() : Category::query()
                    ->visible()
                    ->whereNull('parent_id')
                    ->orderBy('position')
                    ->get(),
                // Otherwise the customer account area (login, registration,
                // /ucet) is unreachable by navigation — nothing links to it.
                // A shop with the module switched off shows nothing at all
                // rather than a link into 404 territory.
                'customerAreaEnabled' => $hasCustomers,
                'signedInCustomer' => $hasCustomers ? Auth::guard('customer')->user() : null,
            ]);
        });
    }
}
