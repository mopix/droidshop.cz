<?php

use Illuminate\Support\Facades\Route;
use Modules\Pages\Http\Controllers\PageAdminController;

Route::get('/', [PageAdminController::class, 'index'])->name('index');
