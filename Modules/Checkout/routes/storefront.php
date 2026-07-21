<?php

use Illuminate\Support\Facades\Route;
use Modules\Checkout\Http\Controllers\CartController;
use Modules\Checkout\Http\Controllers\CartSummaryController;
use Modules\Checkout\Http\Controllers\CheckoutController;

Route::get('/kosik', [CartController::class, 'show'])->name('show');
Route::post('/kosik', [CartController::class, 'add'])->name('add');
Route::patch('/kosik/{item}', [CartController::class, 'update'])->whereNumber('item')->name('update');
Route::delete('/kosik/{item}', [CartController::class, 'remove'])->whereNumber('item')->name('remove');

Route::get('/pokladna/doprava', [CheckoutController::class, 'shipping'])->name('shipping');
Route::post('/pokladna/doprava', [CheckoutController::class, 'chooseShipping'])->name('chooseShipping');

// The mini-cart island. A literal path rather than under this module's own
// api/m/checkout/* prefix (ModuleRouteRegistrar::mountApi) — the storefront
// bundle calls a stable, human-readable /api/kosik/souhrn, and this file is
// mounted with no URL prefix already (mountStorefront), which is what makes
// that literal path possible under the `web` group (session, CSRF) rather
// than the `api` group.
Route::get('/api/kosik/souhrn', CartSummaryController::class)->name('summary');
