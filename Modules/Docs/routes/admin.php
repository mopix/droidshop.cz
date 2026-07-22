<?php

use Illuminate\Support\Facades\Route;
use Modules\Docs\Http\Controllers\DocumentAdminController;

Route::get('/', [DocumentAdminController::class, 'index'])->name('index');
Route::post('/', [DocumentAdminController::class, 'store'])->name('store');
Route::get('/{number}/pdf', [DocumentAdminController::class, 'download'])->name('download');
Route::post('/{number}/odeslat', [DocumentAdminController::class, 'resend'])->name('resend');
