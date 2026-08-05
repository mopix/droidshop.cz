<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * The platform's own legal documents (spec kap. 11).
 *
 * Blade SSR, not Inertia: these have to be readable before anyone registers,
 * without JavaScript, and indexable — a tenant deciding whether to sign up
 * reads the terms first.
 *
 * The routes sit under /pravni/ rather than at the root, and that prefix is
 * load-bearing. Since wave 3.1 a tenant's static pages answer at /{slug}
 * through Route::fallback(); a single-segment platform route such as
 * /cookies would match on a tenant host as well, and RequirePlatformHost's
 * 404 comes AFTER the match, so the fallback would never run and the
 * tenant's own page would vanish. Modules\Pages\Lifecycle seeds
 * `ochrana-osobnich-udaju` for every shop, so that was a certainty, not a
 * risk. A two-segment platform path cannot collide with a one-segment
 * tenant slug by construction.
 *
 * Route::domain(config('tenancy.platform_domain')) was the other candidate
 * and was rejected: the domain is baked into the route at boot, while the
 * test suite sets tenancy.platform_domain in setUp(), so the two would
 * disagree. DomainTenantFinder::isPlatformHost() reads the config at
 * request time, which is why the middleware approach works at all.
 */
class LegalController
{
    /**
     * Slug → view name. Server-authoritative on purpose: the slug from the
     * URL never becomes a file path, so no request can reach a template that
     * is not on this list.
     *
     * @var array<string, string>
     */
    private const DOCUMENTS = [
        'obchodni-podminky' => 'terms',
        'ochrana-osobnich-udaju' => 'privacy',
        'zpracovani-udaju' => 'dpa',
        'cookies' => 'cookies',
    ];

    public function show(string $document): View
    {
        $view = self::DOCUMENTS[$document] ?? abort(404);

        return view('legal.'.$view, [
            'company' => config('billing.company'),
            'effectiveFrom' => config('legal.effective_from'),
            'termsVersion' => config('legal.terms_version'),
            'canonical' => url('/pravni/'.$document),
        ]);
    }
}
