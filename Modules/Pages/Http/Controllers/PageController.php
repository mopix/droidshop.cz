<?php

namespace Modules\Pages\Http\Controllers;

use Illuminate\View\View;
use Modules\Pages\Models\Page;

/**
 * Storefront rendering of a static page.
 *
 * Blade SSR, per the binding storefront rule: the full page has to be in the
 * server's first response, or it is worthless for SEO.
 */
class PageController
{
    public function show(string $slug): View
    {
        $page = Page::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        return view('pages::show', ['page' => $page]);
    }
}
