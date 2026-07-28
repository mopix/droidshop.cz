<?php

use Illuminate\Support\Facades\Route;
use Modules\Discounts\Http\Controllers\DiscountAdminController;

// Coupons and automatic rules admin (wave 2.6, task 12). Mounted by
// App\Core\Modules\ModuleRouteRegistrar::mountAdmin() under prefix
// admin/m/discounts, name admin.discounts.*, middleware
// ['web', 'module:discounts', 'tenant.member'] — no group() wrapper needed
// here, the registrar already applies prefix/name/middleware for every
// module (same shape as Modules/Products/routes/admin.php,
// Modules/Packeta/routes/admin.php).
Route::get('/', [DiscountAdminController::class, 'index'])->name('index');
Route::get('/nova', [DiscountAdminController::class, 'create'])->name('create');
Route::post('/', [DiscountAdminController::class, 'store'])->name('store');
Route::get('/{discount}/upravit', [DiscountAdminController::class, 'edit'])->whereNumber('discount')->name('edit');
Route::patch('/{discount}', [DiscountAdminController::class, 'update'])->whereNumber('discount')->name('update');
Route::delete('/{discount}', [DiscountAdminController::class, 'destroy'])->whereNumber('discount')->name('destroy');
