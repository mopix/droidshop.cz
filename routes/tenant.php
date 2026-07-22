<?php

use App\Http\Controllers\Tenant\AdminHomeController;
use App\Http\Controllers\Tenant\BillingProfileController;
use App\Http\Controllers\Tenant\SubscriptionInvoiceController;
use Illuminate\Support\Facades\Route;

/*
 * Core tenant-admin routes — not owned by any module. Mounted under
 * ['web', 'tenant.member'] by bootstrap/app.php.
 */
Route::get('/admin', AdminHomeController::class)->name('admin.home');

Route::get('/admin/nastaveni/fakturace', [BillingProfileController::class, 'edit'])->name('admin.billing.edit');
Route::patch('/admin/nastaveni/fakturace', [BillingProfileController::class, 'update'])->name('admin.billing.update');

Route::get('/admin/predplatne/faktury', [SubscriptionInvoiceController::class, 'index'])->name('admin.subscription.invoices');
Route::get('/admin/predplatne/faktury/{invoice}/pdf', [SubscriptionInvoiceController::class, 'download'])->name('admin.subscription.invoices.pdf');
