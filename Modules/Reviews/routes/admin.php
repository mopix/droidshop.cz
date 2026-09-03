<?php

use Illuminate\Support\Facades\Route;
use Modules\Reviews\Http\Controllers\ReviewAdminController;

// Mounted by App\Core\Modules\ModuleRouteRegistrar::mountAdmin() under prefix
// admin/m/reviews, name admin.reviews.*, middleware
// ['web', 'module:reviews', 'tenant.member'] — no group() wrapper here, the
// registrar applies prefix/name/middleware for every module (same shape as
// Modules/Discounts/routes/admin.php).
//
// {review} route-model binding does the tenant isolation on its own: Review
// carries BelongsToTenant, so another shop's id never resolves and Laravel
// answers 404 before any controller method runs.
Route::get('/', [ReviewAdminController::class, 'index'])->name('index');
Route::post('/{review}/publikovat', [ReviewAdminController::class, 'publish'])->whereNumber('review')->name('publish');
Route::post('/{review}/zamitnout', [ReviewAdminController::class, 'reject'])->whereNumber('review')->name('reject');
Route::post('/{review}/skryt', [ReviewAdminController::class, 'hide'])->whereNumber('review')->name('hide');
Route::post('/{review}/odpoved', [ReviewAdminController::class, 'reply'])->whereNumber('review')->name('reply');
