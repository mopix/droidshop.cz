<?php

namespace Modules\Pages\Providers;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Modules\Pages\Models\Page;
use Modules\Storefront\Support\ShopModules;

class ModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->sharePublishedPages();
    }

    /**
     * Published pages, for every view that needs to link them.
     *
     * The list lives here rather than in the storefront layout composer
     * because this module owns Page and therefore owns the decision about
     * where its pages are offered. It also has to reach two views, not one:
     * a Blade child renders BEFORE its layout, so a composer on
     * storefront::layouts.shop alone never reaches checkout/details — which
     * is the single place a customer is explicitly asked to agree to the
     * terms, and therefore the place the link matters most. That was a real
     * false pass during wave 3.2: the checkout assertion matched the
     * footer's copy of the same URL.
     *
     * Published only. An unfinished draft linked from the footer is worse
     * than no link, and the shop is seeded with three drafts by design.
     *
     * Per-tenant, not per-visitor, so this is safe inside cached HTML
     * (§15.6), and it is invalidated under Dimension::Content, which both
     * views already declare.
     */
    private function sharePublishedPages(): void
    {
        View::composer([
            'storefront::layouts.shop',
            'checkout::checkout.details',
        ], function ($view): void {
            // The composer fires for any deploy that has this module on disk,
            // including shops that do not run it — the gate is per tenant, so
            // it has to be asked at request time.
            if (! app(ShopModules::class)->has('pages')) {
                $view->with('footerPages', collect());

                return;
            }

            $view->with('footerPages', Page::query()
                ->where('is_published', true)
                ->orderBy('title')
                ->get(['slug', 'title']));
        });
    }
}
