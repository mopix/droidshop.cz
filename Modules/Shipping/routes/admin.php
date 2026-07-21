<?php

use Illuminate\Support\Facades\Route;
use Modules\Shipping\Http\Controllers\PaymentMethodAdminController;
use Modules\Shipping\Http\Controllers\ShippingMatrixAdminController;
use Modules\Shipping\Http\Controllers\ShippingMethodAdminController;

// Shipping and payment methods share one screen (admin.shipping.index): a shop
// configures how it delivers and how it takes money in one place.
Route::get('/', [ShippingMethodAdminController::class, 'index'])->name('index');

// Shipping methods. The reorder path is declared before the {shippingMethod}
// binding so "poradi" is never mistaken for a model key.
Route::post('/zpusoby-dopravy', [ShippingMethodAdminController::class, 'store'])->name('methods.store');
Route::put('/zpusoby-dopravy/poradi', [ShippingMethodAdminController::class, 'reorder'])->name('methods.reorder');
Route::put('/zpusoby-dopravy/{shippingMethod}', [ShippingMethodAdminController::class, 'update'])->name('methods.update');
Route::delete('/zpusoby-dopravy/{shippingMethod}', [ShippingMethodAdminController::class, 'destroy'])->name('methods.destroy');

// Payment methods.
Route::post('/zpusoby-platby', [PaymentMethodAdminController::class, 'store'])->name('payments.store');
Route::put('/zpusoby-platby/poradi', [PaymentMethodAdminController::class, 'reorder'])->name('payments.reorder');
Route::put('/zpusoby-platby/{paymentMethod}', [PaymentMethodAdminController::class, 'update'])->name('payments.update');
Route::delete('/zpusoby-platby/{paymentMethod}', [PaymentMethodAdminController::class, 'destroy'])->name('payments.destroy');

// The matrix that says which payment is allowed with which shipping.
Route::get('/matice', [ShippingMatrixAdminController::class, 'show'])->name('matrix');
Route::put('/matice', [ShippingMatrixAdminController::class, 'update'])->name('matrix.update');
