<?php

namespace App\Http\Controllers;

use App\Core\Consent\Consent;
use App\Core\Consent\ConsentCategory;
use App\Core\Consent\ConsentCookie;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
// A module import in a kernel controller, which app/Core never does. It is
// deliberate here: the settings view already extends
// storefront::layouts.shop, so the dependency on that module exists and is
// harder than this import — the view path would fail first. `storefront` is
// a core module and is always deployed.
use Modules\Storefront\Support\Seo;

/**
 * Records what the visitor decided about cookies.
 *
 * Lives in the kernel, not in the analytics module: a cookie banner is a
 * legal duty for every shop, including one that measures nothing, and a
 * module that can be switched off cannot carry it.
 *
 * Works without JavaScript end to end — a plain form POST followed by a
 * redirect back. The JS on the storefront only removes the round trip; it is
 * never what makes consent possible.
 */
class ConsentController
{
    /**
     * The settings page: the no-JS route to a per-category choice, and the
     * target of the permanent "Nastavení cookies" link in the footer.
     *
     * Withdrawing consent has to be as easy as giving it, so this screen is
     * reachable at any time and pre-fills whatever the visitor chose before.
     */
    public function show(Request $request): View
    {
        return view('consent.settings', [
            'consent' => ConsentCookie::read($request),
            'categories' => ConsentCategory::cases(),
            'seo' => new Seo(
                title: 'Nastavení cookies',
                description: null,
                canonical: Seo::canonicalFor('/souhlas-cookies'),
                // Reflects one visitor's own decision — nothing for a crawler
                // to index, and nothing that would be the same twice.
                noindex: true,
            ),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        ConsentCookie::queue($this->decisionFrom($request));

        // Back where they were, so the banner disappears on the page the
        // visitor was actually reading. `back()` falls back to / when there
        // is no referer, which is what a direct POST deserves.
        return back()->with('status', 'Nastavení cookies bylo uloženo.');
    }

    private function decisionFrom(Request $request): Consent
    {
        return match ($request->input('volba')) {
            'vse' => Consent::acceptAll(),
            'nic' => Consent::rejectAll(),
            // The per-category form. Anything the visitor did not tick is
            // absent from the request, which is exactly a refusal of it.
            default => Consent::of(array_values(array_filter(
                (array) $request->input('kategorie', []),
                fn ($value) => is_string($value),
            ))),
        };
    }
}
