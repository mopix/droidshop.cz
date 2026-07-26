<?php

use Illuminate\Support\Facades\Route;
use Modules\Storefront\Http\Controllers\HomepageAdminController;

Route::prefix('homepage')->name('homepage.')->group(function () {
    Route::get('/', [HomepageAdminController::class, 'index'])->name('index');
    Route::post('/blok', [HomepageAdminController::class, 'store'])->name('store');

    // Ordering matters here too (same reasoning as Categories/routes/admin.php):
    // these all sit under /blok/{block}/..., so the sub-actions must be
    // declared so Laravel does not need any special-casing against a bare
    // "{block}" segment eating them.
    Route::patch('/blok/{block}/presun', [HomepageAdminController::class, 'move'])->name('move');
    Route::patch('/blok/{block}/viditelnost', [HomepageAdminController::class, 'toggle'])->name('toggle');
    Route::patch('/blok/{block}', [HomepageAdminController::class, 'update'])->name('update');
    Route::delete('/blok/{block}', [HomepageAdminController::class, 'destroy'])->name('destroy');
});
