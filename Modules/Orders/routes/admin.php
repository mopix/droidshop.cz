<?php

use Illuminate\Support\Facades\Route;
use Modules\Orders\Http\Controllers\OrderAdminController;
use Modules\Orders\Http\Controllers\OrderStateController;

Route::get('/', [OrderAdminController::class, 'index'])->name('index');
Route::get('/{uuid}', [OrderAdminController::class, 'show'])->name('show');
Route::patch('/{uuid}/stav', [OrderStateController::class, 'update'])->name('state.update');
