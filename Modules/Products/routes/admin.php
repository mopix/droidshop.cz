<?php

use Illuminate\Support\Facades\Route;
use Modules\Products\Http\Controllers\AddonAdminController;
use Modules\Products\Http\Controllers\AttributeAdminController;
use Modules\Products\Http\Controllers\ProductAdminController;
use Modules\Products\Http\Controllers\ProductExportController;
use Modules\Products\Http\Controllers\ProductImageAdminController;
use Modules\Products\Http\Controllers\ProductImportController;
use Modules\Products\Http\Controllers\ProductVariantAdminController;

Route::get('/', [ProductAdminController::class, 'index'])->name('index');
Route::post('/', [ProductAdminController::class, 'store'])->name('store');

// Above the /{product} routes on purpose: a product is bound by slug, so
// "export" and "import" would otherwise be looked up as products of that name.
Route::get('/export', [ProductExportController::class, 'download'])->name('export');

// Above /{product} for the same reason as export: a product is bound by slug,
// so "vlastnosti" would otherwise be looked up as a product of that name.
Route::get('/vlastnosti', [AttributeAdminController::class, 'index'])->name('attributes.index');
Route::post('/vlastnosti', [AttributeAdminController::class, 'store'])->name('attributes.store');
Route::patch('/vlastnosti/{attribute}', [AttributeAdminController::class, 'update'])
    ->whereNumber('attribute')->name('attributes.update');
Route::delete('/vlastnosti/{attribute}', [AttributeAdminController::class, 'destroy'])
    ->whereNumber('attribute')->name('attributes.destroy');
Route::post('/vlastnosti/{attribute}/hodnoty', [AttributeAdminController::class, 'storeValue'])
    ->whereNumber('attribute')->name('attributes.values.store');
Route::patch('/vlastnosti/hodnoty/{value}', [AttributeAdminController::class, 'updateValue'])
    ->whereNumber('value')->name('attributes.values.update');
Route::delete('/vlastnosti/hodnoty/{value}', [AttributeAdminController::class, 'destroyValue'])
    ->whereNumber('value')->name('attributes.values.destroy');

// Accessories live on the product they belong to.
Route::post('/{product}/doplnky', [AddonAdminController::class, 'storeGroup'])->name('addons.groups.store');
Route::delete('/doplnky/skupina/{group}', [AddonAdminController::class, 'destroyGroup'])
    ->whereNumber('group')->name('addons.groups.destroy');
Route::post('/doplnky/skupina/{group}', [AddonAdminController::class, 'storeAddon'])
    ->whereNumber('group')->name('addons.store');
Route::delete('/doplnky/{addon}', [AddonAdminController::class, 'destroyAddon'])
    ->whereNumber('addon')->name('addons.destroy');

Route::get('/import', [ProductImportController::class, 'index'])->name('import.index');
Route::post('/import', [ProductImportController::class, 'store'])->name('import.store');
Route::get('/import/{import}/protokol', [ProductImportController::class, 'report'])
    ->whereNumber('import')->name('import.report');

Route::get('/{product}', [ProductAdminController::class, 'show'])->name('show');
Route::patch('/{product}', [ProductAdminController::class, 'update'])->name('update');
Route::patch('/{product}/stav', [ProductAdminController::class, 'updateStatus'])->name('status.update');
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
