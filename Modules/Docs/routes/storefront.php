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
//
// customer.session (Modules\Customers\Http\Middleware\AuthenticateCustomerSession,
// aliased at deploy level by that module's ModuleProvider) pairs with
// auth:customer here exactly like it does on every other route under
// /ucet/*: a customer who changes their password to kill a stolen session
// must not be able to keep pulling billing-sensitive invoices through this
// route with the old, now-stale session.
Route::get('/faktura/{number}/pdf', [DocumentDownloadController::class, 'show'])
    ->middleware(['auth:customer', 'customer.session'])
    ->name('download');
