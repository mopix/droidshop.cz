<?php

use Illuminate\Support\Facades\Route;
use Modules\Orders\Http\Controllers\OrderAdminController;
use Modules\Orders\Http\Controllers\OrderEditController;
use Modules\Orders\Http\Controllers\OrderStateController;

Route::get('/', [OrderAdminController::class, 'index'])->name('index');

// Registered ahead of the GET /{uuid} wildcard below: routes match in
// registration order within the same HTTP method, and "vytvorit" would
// otherwise be swallowed by {uuid} and land on OrderAdminController::show().
Route::get('/vytvorit', [OrderEditController::class, 'create'])->name('create');
Route::post('/', [OrderEditController::class, 'store'])->name('store');

Route::get('/{uuid}', [OrderAdminController::class, 'show'])->name('show');
Route::patch('/{uuid}/stav', [OrderStateController::class, 'update'])->name('state.update');
Route::patch('/{uuid}', [OrderEditController::class, 'update'])->name('update');
Route::post('/{uuid}/storno', [OrderEditController::class, 'cancel'])->name('cancel');
