<?php

use App\Core\Storage\FileStorage;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\Onboarding\OnboardingController;
use App\Http\Controllers\Onboarding\ShopEntryController;
use App\Http\Controllers\Onboarding\SubdomainCheckController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Storage\PrivateFileController;
use App\Http\Controllers\StorefrontEntryController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Private tenant files. `signed` proves the URL is ours and unexpired; the
// controller then checks the file belongs to the current tenant. The tenant
// pipeline (prepended to the web group) has already set context by here.
Route::get('/soubory/{tenant}/{path}', PrivateFileController::class)
    ->where('path', '.*')
    ->middleware('signed')
    ->name(FileStorage::SIGNED_ROUTE);

// Platform marketing page or the shop homepage, depending on the host.
Route::get('/', StorefrontEntryController::class)->name('home');

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// Impersonation lands here on the tenant's own host, via a signed URL minted
// by a superadmin. `signed` proves the URL is ours and unexpired.
Route::get('/impersonace/zahajit/{user}/{admin}', [ImpersonationController::class, 'begin'])
    ->middleware('signed')
    ->name('impersonation.begin');
Route::post('/impersonace/ukoncit', [ImpersonationController::class, 'end'])
    ->name('impersonation.end');

// A freshly-provisioned owner lands here on the shop's own host, via a signed
// URL minted right after onboarding provisioning (see OnboardingController).
// `signed` proves the URL is ours and unexpired; not behind `auth`, since the
// user isn't authenticated on this host yet.
Route::get('/onboarding/vstup/{user}', [ShopEntryController::class, 'enter'])
    ->middleware('signed')
    ->name('onboarding.enter');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/onboarding/subdomena/check', SubdomainCheckController::class)
        ->name('onboarding.subdomain.check');

    Route::get('/onboarding', [OnboardingController::class, 'create'])->name('onboarding.create');
    Route::post('/onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');
});

require __DIR__.'/auth.php';
