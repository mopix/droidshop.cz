<?php

use Illuminate\Support\Facades\Route;
use Modules\Packeta\Http\Controllers\DispatchQueueController;
use Modules\Packeta\Http\Controllers\ShipmentAdminController;

// Handing shipments over to the carrier, printing labels, and cancelling a
// shipment (wave 2.5, task 12). Mounted by
// App\Core\Modules\ModuleRouteRegistrar::mountAdmin() under prefix
// admin/m/packeta, name admin.packeta.*, middleware
// ['web', 'module:packeta', 'tenant.member'] — the same shape as
// Modules/Shipping/routes/admin.php: no group() wrapper in this file itself,
// the registrar already applies prefix/name/middleware for every module.
// `tenant.member` does its own authentication rather than stacking Laravel's
// `auth` alias (rozhodnutí 2026-07-20): `auth` sits in the framework's
// middleware priority list and would be hoisted in front of the module gate,
// turning a disabled module's 404 into a login redirect that reveals the
// module is installed.
//
// The dispatch queue listing (admin.packeta.dispatch, wave 2.5, task 14) is
// GET-only: it never mutates anything, so CheckTenantStatus's write-freeze
// (rozhodnutí 2026-07-22) leaves it reachable for a suspended tenant even
// though the mutating routes below are not.
Route::get('expedice', [DispatchQueueController::class, 'index'])->name('dispatch');
Route::post('zasilky/podat', [ShipmentAdminController::class, 'submit'])->name('shipments.submit');
Route::post('zasilky/stitky', [ShipmentAdminController::class, 'labels'])->name('shipments.labels');
Route::delete('zasilky/{shipment}', [ShipmentAdminController::class, 'cancel'])->name('shipments.cancel');
