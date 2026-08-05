<?php

use Illuminate\Support\Facades\Route;
use Modules\Pages\Http\Controllers\PageController;

/*
 * Static pages live at /{page-slug}, per the binding storefront rule.
 *
 * The mechanism is Route::fallback(), not a catch-all /{slug}: Laravel
 * evaluates a fallback after every other route regardless of registration
 * order, so it cannot swallow a storefront path — including ones added after
 * this file was written. ModuleRouteRegistrar walks glob() (alphabetically),
 * which puts `pages` ahead of `products` and `storefront`, so a plain
 * catch-all here really would have eaten /kosik, /hledani and the rest. The
 * other candidate, a blacklist of reserved first segments, would have to be
 * extended by hand for every new route and would fail silently when it was
 * not.
 *
 * The price of a fallback is that it also matches multi-segment paths and
 * /admin/*; PageController::show() rejects anything with a slash and
 * abort(404)s, which is what hands those requests to RedirectResponder (the
 * NotFoundHttpException handler that answers renamed slugs with a 301).
 */
Route::get('/stranka/{slug}', [PageController::class, 'legacy'])
    ->where('slug', '[^/]+')
    ->name('legacy');

Route::fallback([PageController::class, 'show'])
    // Deliberately without `catalog`. The whole-branch review of wave 3.0
    // asked for it on the grounds that the shared layout renders
    // $navCategories from Category::query()->visible() — but this route does
    // not use the shared layout: pages::show is a standalone document that
    // never includes storefront::layouts.shop, so no category, price or stock
    // value reaches it. Adding `catalog` would re-render every static page on
    // every stock write-off (i.e. on every order) for nothing.
    //
    // If this view is ever moved onto storefront::layouts.shop — which is
    // where the header nav and the tenant's theme variables live, and which
    // is why `theme` is already declared here — add `catalog` at the same
    // time.
    ->middleware('page-cache:content,theme')
    ->name('show');
