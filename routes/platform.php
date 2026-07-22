<?php

use App\Http\Controllers\Platform\Auth\LoginController;
use App\Http\Controllers\Platform\Auth\TwoFactorController;
use App\Http\Controllers\Platform\ImpersonationController;
use App\Http\Controllers\Platform\ModuleController;
use App\Http\Controllers\Platform\PlatformInvoiceDownloadController;
use App\Http\Controllers\Platform\TenantController;
use App\Http\Controllers\Platform\TenantModuleController;
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

        Route::get('/superadmin/tenanti', [TenantController::class, 'index'])
            ->name('platform.tenants.index');

        Route::get('/superadmin/tenanti/{tenant}', [TenantController::class, 'show'])
            ->name('platform.tenants.show');

        Route::patch('/superadmin/tenanti/{tenant}/stav', [TenantController::class, 'updateStatus'])
            ->name('platform.tenants.status');

        Route::patch('/superadmin/tenanti/{tenant}/tarif', [TenantController::class, 'updatePlan'])
            ->name('platform.tenants.plan');

        Route::post('/superadmin/tenanti/{tenant}/predplatne/aktivovat', [TenantController::class, 'activateSubscription'])
            ->name('platform.tenants.subscription.activate');

        Route::get('/superadmin/tenanti/{tenant}/dopad-tarifu', [TenantController::class, 'planImpact'])
            ->name('platform.tenants.plan-impact');

        Route::post('/superadmin/tenanti/{tenant}/moduly', [TenantModuleController::class, 'store'])
            ->name('platform.tenants.modules.store');

        Route::delete('/superadmin/tenanti/{tenant}/moduly/{module}', [TenantModuleController::class, 'destroy'])
            ->name('platform.tenants.modules.destroy');

        Route::get('/superadmin/moduly', [ModuleController::class, 'index'])
            ->name('platform.modules.index');

        Route::patch('/superadmin/moduly/{module}/globalni-stav', [ModuleController::class, 'updateGlobalState'])
            ->name('platform.modules.global-state');

        Route::post('/superadmin/impersonace', [ImpersonationController::class, 'start'])
            ->name('platform.impersonate');

        Route::get('/superadmin/faktury/{invoice}/pdf', PlatformInvoiceDownloadController::class)
            ->name('platform.invoices.pdf');
    });
});
