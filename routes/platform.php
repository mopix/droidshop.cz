<?php

use App\Http\Controllers\Platform\Auth\LoginController;
use App\Http\Controllers\Platform\Auth\TwoFactorController;
use App\Http\Controllers\Platform\ImpersonationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Superadmin routes. Everything here is on a platform host only
 * (platform.host middleware) and is noindex. The web group is applied by
 * whoever requires this file, so session and CSRF are already in place.
 */
Route::middleware('platform.host')->group(function () {

    Route::middleware('guest:platform')->group(function () {
        Route::get('/superadmin/login', [LoginController::class, 'show'])->name('platform.login');
        Route::post('/superadmin/login', [LoginController::class, 'store']);
    });

    // Logged in, but 2FA not yet completed: only setup, challenge and logout
    // are reachable. platform.2fa itself sends the admin to the right one.
    Route::middleware('auth:platform')->group(function () {
        Route::post('/superadmin/logout', [LoginController::class, 'destroy'])->name('platform.logout');

        Route::get('/superadmin/2fa/setup', [TwoFactorController::class, 'setup'])->name('platform.2fa.setup');
        Route::post('/superadmin/2fa/setup', [TwoFactorController::class, 'confirm']);
        Route::get('/superadmin/2fa/challenge', [TwoFactorController::class, 'challenge'])->name('platform.2fa.challenge');
        Route::post('/superadmin/2fa/challenge', [TwoFactorController::class, 'verify']);
    });

    Route::middleware(['auth:platform', 'platform.2fa'])->group(function () {
        Route::get('/superadmin', fn () => Inertia::render('Platform/Dashboard', [
            'admin' => auth('platform')->user()->only('name', 'email'),
        ]))->name('platform.dashboard');

        Route::post('/superadmin/impersonace', [ImpersonationController::class, 'start'])
            ->name('platform.impersonate');
    });
});
