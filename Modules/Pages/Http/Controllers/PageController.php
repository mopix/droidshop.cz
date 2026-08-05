<?php

namespace Modules\Pages\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function show(Request $request): View
    {
        $slug = trim($request->path(), '/');

        // This is the route's fallback, so it matches every path nothing else
        // claimed — /admin/neco and multi-segment paths included. A page slug
        // is a single segment; everything else has to fall through to the 404
        // handler, which is where RedirectResponder answers renamed slugs
        // with a 301 (spec §15.3).
        if ($slug === '' || str_contains($slug, '/')) {
            abort(404);
        }

        $page = Page::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        return view('pages::show', ['page' => $page]);
    }

    /**
     * Pages used to live under /stranka/{slug} (a deviation from the
     * storefront rule that wave 3.1 closed). The old path keeps answering
     * with a permanent redirect: it is in sent e-mails and possibly in
     * tenants' own page bodies, and deleting it would turn those into 404s.
     *
     * No database lookup on purpose. The redirect holds even for a slug that
     * never existed — the new path answers 404 for it on its own — and a
     * lookup here would only turn this into an oracle for which slugs exist.
     */
    public function legacy(string $slug): RedirectResponse
    {
        return redirect()->to('/'.$slug, 301);
    }
}
