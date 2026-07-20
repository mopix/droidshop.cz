<?php

use Illuminate\Support\Facades\Route;
use Modules\Storefront\Http\Controllers\RobotsController;
use Modules\Storefront\Http\Controllers\SearchController;
use Modules\Storefront\Http\Controllers\SitemapController;

// The homepage is not here: core owns `/` and delegates it through the
// StorefrontHome contract, because core web routes are matched first.
Route::get('/hledani', SearchController::class)->name('search');
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('/robots.txt', RobotsController::class)->name('robots');
