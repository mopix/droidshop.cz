<?php

use App\Http\Controllers\Internal\TlsCheckController;
use Illuminate\Support\Facades\Route;

/*
 * Machine-to-machine routes for the edge (Caddy) process, not for browsers.
 * Registered outside the `web` group in bootstrap/app.php — no session, no
 * CSRF, no tenant pipeline — and gated to localhost by `internal.local`
 * (see App\Http\Middleware\AllowLocalOnly).
 */

Route::get('/internal/tls-check', TlsCheckController::class);
