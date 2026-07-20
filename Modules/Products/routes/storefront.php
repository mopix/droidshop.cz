<?php

use Illuminate\Support\Facades\Route;
use Modules\Products\Http\Controllers\ProductStorefrontController;

// Flat product URLs (decision 2026-07-19): the address survives every
// reorganisation of the catalogue.
Route::get('/produkt/{slug}', ProductStorefrontController::class)->name('show');
