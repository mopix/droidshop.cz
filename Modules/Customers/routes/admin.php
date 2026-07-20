<?php

use Illuminate\Support\Facades\Route;
use Modules\Customers\Http\Controllers\CustomerAdminController;

Route::get('/', [CustomerAdminController::class, 'index'])->name('index');
Route::get('/{customer}', [CustomerAdminController::class, 'show'])->name('show');
Route::post('/{customer}/vymazat', [CustomerAdminController::class, 'erase'])->name('erase');
Route::get('/{customer}/export', [CustomerAdminController::class, 'export'])->name('export');
