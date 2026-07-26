<?php

use Illuminate\Support\Facades\Route;
use Modules\Products\Http\Controllers\ProductAdminController;
use Modules\Products\Http\Controllers\ProductImageAdminController;
use Modules\Products\Http\Controllers\ProductVariantAdminController;

Route::get('/', [ProductAdminController::class, 'index'])->name('index');
Route::post('/', [ProductAdminController::class, 'store'])->name('store');

Route::get('/{product}', [ProductAdminController::class, 'show'])->name('show');
Route::patch('/{product}', [ProductAdminController::class, 'update'])->name('update');
Route::delete('/{product}', [ProductAdminController::class, 'destroy'])->name('destroy');

Route::post('/{product}/obrazky', [ProductImageAdminController::class, 'store'])->name('images.store');
Route::post('/{product}/obrazky/poradi', [ProductImageAdminController::class, 'reorder'])->name('images.reorder');
Route::patch('/{product}/obrazky/{image}', [ProductImageAdminController::class, 'update'])->name('images.update');
Route::delete('/{product}/obrazky/{image}', [ProductImageAdminController::class, 'destroy'])->name('images.destroy');

Route::post('/{product}/varianty/osy', [ProductVariantAdminController::class, 'storeOption'])->name('variants.options.store');
Route::patch('/{product}/varianty/osy/{option}', [ProductVariantAdminController::class, 'updateOption'])->whereNumber('option')->name('variants.options.update');
Route::delete('/{product}/varianty/osy/{option}', [ProductVariantAdminController::class, 'destroyOption'])->whereNumber('option')->name('variants.options.destroy');
Route::post('/{product}/varianty/osy/{option}/poradi', [ProductVariantAdminController::class, 'moveOption'])->whereNumber('option')->name('variants.options.move');

Route::post('/{product}/varianty/osy/{option}/hodnoty', [ProductVariantAdminController::class, 'storeValue'])->whereNumber('option')->name('variants.values.store');
Route::delete('/{product}/varianty/osy/{option}/hodnoty/{value}', [ProductVariantAdminController::class, 'destroyValue'])->whereNumber('option')->whereNumber('value')->name('variants.values.destroy');
Route::post('/{product}/varianty/osy/{option}/hodnoty/{value}/poradi', [ProductVariantAdminController::class, 'moveValue'])->whereNumber('option')->whereNumber('value')->name('variants.values.move');

Route::post('/{product}/varianty/generovat', [ProductVariantAdminController::class, 'generate'])->name('variants.generate');
Route::patch('/{product}/varianty/{variant}', [ProductVariantAdminController::class, 'update'])->whereNumber('variant')->name('variants.update');
Route::delete('/{product}/varianty/{variant}', [ProductVariantAdminController::class, 'destroy'])->whereNumber('variant')->name('variants.destroy');
