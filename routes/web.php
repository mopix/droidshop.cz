<?php

use App\Core\Storage\FileStorage;
use App\Http\Controllers\ConsentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\Onboarding\OnboardingController;
use App\Http\Controllers\Onboarding\ShopEntryController;
use App\Http\Controllers\Onboarding\SubdomainCheckController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Storage\PrivateFileController;
use App\Http\Controllers\StorefrontEntryController;
use Illuminate\Support\Facades\Route;

// Private tenant files. `signed` proves the URL is ours and unexpired; the
// controller then checks the file belongs to the current tenant. The tenant
// pipeline (prepended to the web group) has already set context by here.
Route::get('/soubory/{tenant}/{path}', PrivateFileController::class)
    ->where('path', '.*')
    ->middleware('signed')
    ->name(FileStorage::SIGNED_ROUTE);

// Platform marketing page or the shop homepage, depending on the host.
Route::get('/', StorefrontEntryController::class)
    ->middleware('page-cache:catalog,content,theme')
    ->name('home');

// The platform's own legal documents. Blade SSR: they have to be readable
// before anyone registers, without JavaScript, and indexable.
//
// The /pravni prefix is load-bearing, not cosmetic. Since wave 3.1 a tenant's
// static pages answer at /{slug} through Route::fallback(); a single-segment
// route such as /cookies would match on a tenant host too, and
// RequirePlatformHost's 404 lands AFTER the match, so the fallback would never
// run and the tenant's own page would disappear. Modules\Pages\Lifecycle seeds
// `ochrana-osobnich-udaju` for every shop, so that would have been certain.
// A two-segment platform path cannot collide with a one-segment tenant slug.
Route::get('/pravni/{document}', [LegalController::class, 'show'])
    ->middleware('platform.host')
    ->name('legal.show');

// Cookie consent. In the kernel, not in the analytics module: a banner is a
// legal duty for every shop, including one that measures nothing.
//
// Deliberately no `page-cache` middleware. The settings screen reflects this
// visitor's own decision, so it is the one storefront page that must never be
// shared — and the POST sets a cookie, which PageCachePolicy refuses anyway.
Route::get('/souhlas-cookies', [ConsentController::class, 'show'])->name('consent.show');
Route::post('/souhlas-cookies', [ConsentController::class, 'store'])->name('consent.store');

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])->name('dashboard');

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
