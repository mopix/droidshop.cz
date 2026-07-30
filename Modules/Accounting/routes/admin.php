<?php

use Illuminate\Support\Facades\Route;
use Modules\Accounting\Http\Controllers\AccountingExportController;

Route::get('/', [AccountingExportController::class, 'index'])->name('index');
Route::get('/export', [AccountingExportController::class, 'export'])->name('export');
Route::get('/isdoc/{number}', [AccountingExportController::class, 'isdoc'])->name('isdoc');
