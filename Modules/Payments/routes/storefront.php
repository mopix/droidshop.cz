<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Modules\Payments\Http\Controllers\PaymentReturnController;
use Modules\Payments\Http\Controllers\PaymentWebhookController;

// Where the shopper returns from the gateway. Under the web group (session,
// tenant from host) and the module gate; the order is re-verified server-side,
// so no query it carries is trusted. noindex like the rest of checkout.
Route::get('/platba/navrat', PaymentReturnController::class)->name('return');

// The server-to-server notification. CSRF is dropped for this one route — an
// S2S caller has no token — and authenticity is enforced by the gateway secret
// inside the controller instead. Still under module:payments, so a shop not
// running the module 404s.
Route::post('/platba/notifikace', PaymentWebhookController::class)
    ->withoutMiddleware(VerifyCsrfToken::class)
    ->name('notify');
