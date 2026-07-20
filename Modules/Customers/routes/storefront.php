<?php

use Illuminate\Support\Facades\Route;
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
    ->middleware('auth:customer')
    ->name('logout');

// Reachable by both guests and signed-in customers: the link is what
// authenticates this request, not the session. See
// EmailVerificationController::verify() for why guest:customer /
// auth:customer would both be wrong here.
Route::get('/overeni-emailu/{token}', [EmailVerificationController::class, 'verify'])->name('verify');

// Unlike verify(), there is no token to authenticate the caller here, so
// this one does require a signed-in customer.
Route::post('/overeni-emailu/znovu', [EmailVerificationController::class, 'resend'])
    ->middleware('auth:customer')
    ->name('verify.resend');
