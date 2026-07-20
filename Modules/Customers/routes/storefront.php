<?php

use Illuminate\Support\Facades\Route;
use Modules\Customers\Http\Controllers\AccountController;
use Modules\Customers\Http\Controllers\EmailVerificationController;
use Modules\Customers\Http\Controllers\PasswordResetController;
use Modules\Customers\Http\Controllers\RegistrationController;
use Modules\Customers\Http\Controllers\SessionController;

// Guest-only: an already signed-in customer has no business on these pages.
Route::middleware('guest:customer')->group(function () {
    Route::get('/registrace', [RegistrationController::class, 'create'])->name('register');
    Route::post('/registrace', [RegistrationController::class, 'store'])->name('register.store');

    Route::get('/prihlaseni', [SessionController::class, 'create'])->name('login');
    Route::post('/prihlaseni', [SessionController::class, 'store'])->name('login.store');

    Route::get('/zapomenute-heslo', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/zapomenute-heslo', [PasswordResetController::class, 'email'])->name('password.email');
    Route::get('/obnova-hesla/{token}', [PasswordResetController::class, 'edit'])->name('password.edit');
    Route::post('/obnova-hesla', [PasswordResetController::class, 'update'])->name('password.update');
});

Route::post('/odhlaseni', [SessionController::class, 'destroy'])
    ->middleware(['auth:customer', 'customer.session'])
    ->name('logout');

// Reachable by both guests and signed-in customers: the link is what
// authenticates this request, not the session. See
// EmailVerificationController::verify() for why guest:customer /
// auth:customer would both be wrong here.
Route::get('/overeni-emailu/{token}', [EmailVerificationController::class, 'verify'])->name('verify');

// Unlike verify(), there is no token to authenticate the caller here, so
// this one does require a signed-in customer.
Route::post('/overeni-emailu/znovu', [EmailVerificationController::class, 'resend'])
    ->middleware(['auth:customer', 'customer.session'])
    ->name('verify.resend');

// The account area. Every route here requires a signed-in customer — see
// AccountController for how each write is additionally scoped to the
// authenticated customer's own rows, never trusting an id from the request.
// customer.session (Modules\Customers\Http\Middleware\AuthenticateCustomerSession)
// is what makes a password change here evict every other signed-in session
// for this customer — see that class's docblock for why Laravel's own
// AuthenticateSession middleware cannot do this for a non-default guard.
Route::middleware(['auth:customer', 'customer.session'])->group(function () {
    Route::get('/ucet', [AccountController::class, 'index'])->name('account');

    Route::get('/ucet/udaje', [AccountController::class, 'editProfile'])->name('account.profile');
    Route::put('/ucet/udaje', [AccountController::class, 'updateProfile'])->name('account.profile.update');

    Route::get('/ucet/adresy', [AccountController::class, 'addresses'])->name('account.addresses');
    Route::post('/ucet/adresy', [AccountController::class, 'storeAddress'])->name('account.addresses.store');
    // whereNumber: the controller type-hints int $address and there is no
    // implicit route model binding here (ownership is resolved through the
    // authenticated customer's own relation, not CustomerAddress::findOrFail).
    // Without the constraint a non-numeric segment reaches the controller
    // and throws a TypeError (500) instead of a clean 404.
    Route::get('/ucet/adresy/{address}/upravit', [AccountController::class, 'editAddress'])->name('account.addresses.edit')->whereNumber('address');
    Route::put('/ucet/adresy/{address}', [AccountController::class, 'updateAddress'])->name('account.addresses.update')->whereNumber('address');
    // A GET confirmation step, not a JS confirm() dialog: the delete itself
    // stays a real DELETE request from a real form on this page, so the
    // whole flow works with JavaScript switched off.
    Route::get('/ucet/adresy/{address}/smazat', [AccountController::class, 'confirmDeleteAddress'])->name('account.addresses.delete')->whereNumber('address');
    Route::delete('/ucet/adresy/{address}', [AccountController::class, 'destroyAddress'])->name('account.addresses.destroy')->whereNumber('address');
});
