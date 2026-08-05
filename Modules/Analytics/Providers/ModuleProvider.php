<?php

namespace Modules\Analytics\Providers;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Modules\Analytics\Support\TrackingCodes;

class ModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->shareTrackingCodes();
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
