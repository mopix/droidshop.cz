<?php

use Illuminate\Support\Facades\Route;
use Modules\Reviews\Http\Controllers\ReviewFormController;

// No prefix (ModuleRouteRegistrar::mountStorefront) and no page-cache
// middleware: every one of these URLs is tied to a single-use token, so the
// response is never something a second, unrelated visitor may be served.
Route::get('/recenze/{token}', [ReviewFormController::class, 'show'])
    ->name('form');

Route::post('/recenze/{token}', [ReviewFormController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('store');

Route::get('/recenze/{token}/odhlasit', [ReviewFormController::class, 'optout'])
    ->name('optout');
