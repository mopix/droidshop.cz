<?php

use Illuminate\Support\Facades\Route;
use Modules\Feeds\Http\Controllers\FeedAdminController;

Route::get('/', [FeedAdminController::class, 'index'])->name('index');
Route::patch('/{type}', [FeedAdminController::class, 'update'])->name('update');
Route::patch('/{type}/kategorie', [FeedAdminController::class, 'categories'])->name('categories');
