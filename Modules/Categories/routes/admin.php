<?php

use Illuminate\Support\Facades\Route;
use Modules\Categories\Http\Controllers\CategoryAdminController;

Route::get('/', [CategoryAdminController::class, 'index'])->name('index');
Route::post('/', [CategoryAdminController::class, 'store'])->name('store');

// Ordering before the {category} routes: "poradi" would otherwise be read as
// a slug and 404 on every save.
Route::post('/poradi', [CategoryAdminController::class, 'reorder'])->name('reorder');

Route::patch('/{category}', [CategoryAdminController::class, 'update'])->name('update');
Route::post('/{category}/presun', [CategoryAdminController::class, 'move'])->name('move');
Route::delete('/{category}', [CategoryAdminController::class, 'destroy'])->name('destroy');
