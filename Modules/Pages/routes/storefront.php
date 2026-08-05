<?php

use Illuminate\Support\Facades\Route;
use Modules\Pages\Http\Controllers\PageController;

/*
 * Note: the storefront rule puts static pages at /{page-slug}. A catch-all at
 * the root would swallow every other storefront route, and route ordering
 * across modules is not solved until the theme module lands. Until then this
 * sits under /stranka/{slug}. Recorded as a deviation in the wave as-is.
 */
Route::get('/stranka/{slug}', [PageController::class, 'show'])
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
