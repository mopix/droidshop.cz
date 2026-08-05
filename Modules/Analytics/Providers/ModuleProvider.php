<?php

namespace Modules\Analytics\Providers;

use App\Core\Orders\Contracts\OrderView;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Modules\Analytics\Listeners\ReportOrderToHeureka;
use Modules\Analytics\Support\PurchasePayload;
use Modules\Analytics\Support\TrackingCodes;

class ModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->shareTrackingCodes();
        $this->sharePurchase();

        // Resolved by name, not by importing the orders module's event class:
        // analytics declares no `requires` on orders, so a deploy without it
        // must still boot. An event that never fires costs nothing.
        Event::listen('Modules\\Orders\\Events\\OrderPlaced', ReportOrderToHeureka::class);
    }

    /**
     * The purchase conversion, on the one page that knows an order value.
     *
     * A composer on the checkout module's view rather than a change to its
     * controller: the checkout must not have to know that measurement exists,
     * and a tenant without this module gets an empty array and no snippet.
     *
     * Safe to carry a single customer's order value because the thank-you
     * page is served `no-store` and never becomes a page-cache entry. The
     * same markup on a cached page would be a leak between customers.
     */
    private function sharePurchase(): void
    {
        View::composer('checkout::thank-you', function ($view): void {
            $order = $view->getData()['order'] ?? null;

            $view->with('purchase', $order instanceof OrderView
                ? app(PurchasePayload::class)->for($order)
                : []);
        });
    }

    /**
     * Pushes the measurement snippets into the storefront layout.
     *
     * A view composer on the layout rather than a per-controller concern:
     * forgetting it in one controller would silently stop measuring that
     * page, and nobody notices missing data for weeks (same reasoning as the
     * storefront's own layout composer).
     *
     * Only ids reach the HTML — never the visitor's decision. Cached pages
     * must be identical for everyone (§15.6), so the server may render what
     * the TENANT configured but never what this VISITOR allowed; the snippet
     * itself reads the consent cookie.
     *
     * The gate is per tenant and asked at request time: this provider boots
     * for every deploy that has the module on disk, including shops that do
     * not run it.
     */
    private function shareTrackingCodes(): void
    {
        View::composer('storefront::layouts.shop', function ($view): void {
            $view->with('trackingCodes', app(TrackingCodes::class)->all());
        });
    }
}
