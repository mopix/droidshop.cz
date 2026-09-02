<?php

use App\Http\Controllers\Platform\Auth\LoginController;
use App\Http\Controllers\Platform\Auth\TwoFactorController;
use App\Http\Controllers\Platform\ImpersonationController;
use App\Http\Controllers\Platform\ModuleController;
use App\Http\Controllers\Platform\PlanController;
use App\Http\Controllers\Platform\PlatformInvoiceDownloadController;
use App\Http\Controllers\Platform\ProfileController as PlatformProfileController;
use App\Http\Controllers\Platform\TaxRateController;
use App\Http\Controllers\Platform\TenantController;
use App\Http\Controllers\Platform\TenantModuleController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Superadmin routes. Everything here is on a platform host only
 * (platform.host middleware) and is noindex. The web group is applied by
 * whoever requires this file, so session and CSRF are already in place.
 */
Route::middleware('platform.host')->group(function () {

    // Stripe S2S webhook — no auth/session, authenticity via signature.
    Route::post('/superadmin/stripe/webhook', StripeWebhookController::class)
        ->withoutMiddleware(VerifyCsrfToken::class)
        ->name('platform.stripe.webhook');

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

        // The administrator's own account. Behind the same 2FA gate as
        // everything else here: changing the e-mail on this account is
        // changing where its password resets land.
        Route::get('/superadmin/profil', [PlatformProfileController::class, 'edit'])
            ->name('platform.profile.edit');
        Route::patch('/superadmin/profil', [PlatformProfileController::class, 'update'])
            ->name('platform.profile.update');
        Route::put('/superadmin/profil/heslo', [PlatformProfileController::class, 'updatePassword'])
            ->name('platform.profile.password');
        Route::post('/superadmin/profil/zalozni-kody', [PlatformProfileController::class, 'regenerateRecoveryCodes'])
            ->name('platform.profile.recoveryCodes');

        Route::get('/superadmin/dph', [TaxRateController::class, 'index'])
            ->name('platform.tax-rates.index');
        Route::post('/superadmin/dph', [TaxRateController::class, 'store'])
            ->name('platform.tax-rates.store');
        Route::patch('/superadmin/dph/{taxRate}', [TaxRateController::class, 'update'])
            ->name('platform.tax-rates.update');
        Route::delete('/superadmin/dph/{taxRate}', [TaxRateController::class, 'destroy'])
            ->name('platform.tax-rates.destroy');

        Route::get('/superadmin/tenanti', [TenantController::class, 'index'])
            ->name('platform.tenants.index');

        Route::get('/superadmin/tenanti/{tenant}', [TenantController::class, 'show'])
            ->name('platform.tenants.show');

        Route::patch('/superadmin/tenanti/{tenant}/stav', [TenantController::class, 'updateStatus'])
            ->name('platform.tenants.status');

        Route::patch('/superadmin/tenanti/{tenant}/tarif', [TenantController::class, 'updatePlan'])
            ->name('platform.tenants.plan');

        Route::post('/superadmin/tenanti/{tenant}/export', [TenantController::class, 'exportData'])
            ->name('platform.tenants.export');

        Route::get('/superadmin/tenanti/{tenant}/dopad-tarifu', [TenantController::class, 'planImpact'])
            ->name('platform.tenants.plan-impact');

        Route::post('/superadmin/tenanti/{tenant}/moduly', [TenantModuleController::class, 'store'])
            ->name('platform.tenants.modules.store');

        Route::delete('/superadmin/tenanti/{tenant}/moduly/{module}/data', [TenantModuleController::class, 'purge'])
            ->name('platform.tenants.modules.purge');

        Route::delete('/superadmin/tenanti/{tenant}/moduly/{module}', [TenantModuleController::class, 'destroy'])
            ->name('platform.tenants.modules.destroy');

        Route::get('/superadmin/moduly', [ModuleController::class, 'index'])
            ->name('platform.modules.index');

        Route::patch('/superadmin/moduly/{module}/globalni-stav', [ModuleController::class, 'updateGlobalState'])
            ->name('platform.modules.global-state');

        Route::get('/superadmin/tarify', [PlanController::class, 'index'])
            ->name('platform.plans.index');

        Route::get('/superadmin/tarify/{plan}', [PlanController::class, 'show'])
            ->name('platform.plans.show');

        Route::get('/superadmin/tarify/{plan}/dopad', [PlanController::class, 'impact'])
            ->name('platform.plans.impact');

        Route::patch('/superadmin/tarify/{plan}/moduly', [PlanController::class, 'updateModules'])
            ->name('platform.plans.modules');

        Route::post('/superadmin/impersonace', [ImpersonationController::class, 'start'])
            ->name('platform.impersonate');

        Route::get('/superadmin/faktury/{invoice}/pdf', PlatformInvoiceDownloadController::class)
            ->name('platform.invoices.pdf');
    });
});
