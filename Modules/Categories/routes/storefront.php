<?php

use Illuminate\Support\Facades\Route;
use Modules\Categories\Http\Controllers\CategoryStorefrontController;

Route::get('/kategorie/{slug}', CategoryStorefrontController::class)
    ->middleware('page-cache:catalog,theme')
    ->name('show');
