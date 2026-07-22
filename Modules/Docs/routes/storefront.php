<?php

use Illuminate\Support\Facades\Route;
use Modules\Docs\Http\Controllers\DocumentDownloadController;

// Customer-only: an invoice PDF is billing-sensitive, so this route sits
// behind auth:customer on top of the module gate ModuleRouteRegistrar already
// applies to every storefront route file (mounted as storefront.docs.*, no
// path prefix — see ModuleRouteRegistrar::mountStorefront()). Ownership
// itself is checked inside the controller (via the kernel OrderBook
// contract), not here — the same split AccountOrdersController uses for
// order details.
Route::get('/faktura/{number}/pdf', [DocumentDownloadController::class, 'show'])
    ->middleware('auth:customer')
    ->name('download');
