<?php

use Illuminate\Support\Facades\Route;
use Modules\Products\Http\Controllers\ProductAdminController;
use Modules\Products\Http\Controllers\ProductImageAdminController;

Route::get('/', [ProductAdminController::class, 'index'])->name('index');
Route::post('/', [ProductAdminController::class, 'store'])->name('store');

Route::get('/{product}', [ProductAdminController::class, 'show'])->name('show');
Route::patch('/{product}', [ProductAdminController::class, 'update'])->name('update');
Route::delete('/{product}', [ProductAdminController::class, 'destroy'])->name('destroy');

Route::post('/{product}/obrazky', [ProductImageAdminController::class, 'store'])->name('images.store');
Route::post('/{product}/obrazky/poradi', [ProductImageAdminController::class, 'reorder'])->name('images.reorder');
Route::patch('/{product}/obrazky/{image}', [ProductImageAdminController::class, 'update'])->name('images.update');
Route::delete('/{product}/obrazky/{image}', [ProductImageAdminController::class, 'destroy'])->name('images.destroy');
