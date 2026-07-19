<?php

use Illuminate\Support\Facades\Route;
use Modules\Pages\Http\Controllers\PageController;

/*
 * Note: the storefront rule puts static pages at /{page-slug}. A catch-all at
 * the root would swallow every other storefront route, and route ordering
 * across modules is not solved until the theme module lands. Until then this
 * sits under /stranka/{slug}. Recorded as a deviation in the wave as-is.
 */
Route::get('/stranka/{slug}', [PageController::class, 'show'])->name('show');
