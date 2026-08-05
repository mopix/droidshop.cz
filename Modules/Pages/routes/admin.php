<?php

use Illuminate\Support\Facades\Route;
use Modules\Pages\Http\Controllers\PageAdminController;

Route::get('/', [PageAdminController::class, 'index'])->name('index');

// Above /{page}, and constrained to digits below, so `nova` can never be
// looked up as a page. Same shape as the product import/export routes above
// /{product} (wave 2.8).
Route::get('/nova', [PageAdminController::class, 'create'])->name('create');
Route::post('/', [PageAdminController::class, 'store'])->name('store');

Route::get('/{page}/upravit', [PageAdminController::class, 'edit'])->whereNumber('page')->name('edit');
Route::put('/{page}', [PageAdminController::class, 'update'])->whereNumber('page')->name('update');
Route::delete('/{page}', [PageAdminController::class, 'destroy'])->whereNumber('page')->name('destroy');
