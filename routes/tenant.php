<?php

use App\Http\Controllers\Tenant\AdminHomeController;
use App\Http\Controllers\Tenant\BillingProfileController;
use App\Http\Controllers\Tenant\DomainController;
use App\Http\Controllers\Tenant\SubscriptionController;
use App\Http\Controllers\Tenant\SubscriptionInvoiceController;
use Illuminate\Support\Facades\Route;

/*
 * Core tenant-admin routes — not owned by any module. Mounted under
 * ['web', 'tenant.member'] by bootstrap/app.php.
 */
Route::get('/admin', AdminHomeController::class)->name('admin.home');

Route::get('/admin/nastaveni/fakturace', [BillingProfileController::class, 'edit'])->name('admin.billing.edit');
Route::patch('/admin/nastaveni/fakturace', [BillingProfileController::class, 'update'])->name('admin.billing.update');

Route::get('/admin/nastaveni/domena', [DomainController::class, 'edit'])->name('admin.domain.edit');
Route::post('/admin/nastaveni/domena', [DomainController::class, 'store'])->name('admin.domain.store');
Route::post('/admin/nastaveni/domena/overit', [DomainController::class, 'verify'])->name('admin.domain.verify');
Route::delete('/admin/nastaveni/domena', [DomainController::class, 'destroy'])->name('admin.domain.destroy');

Route::get('/admin/predplatne', [SubscriptionController::class, 'show'])->name('admin.subscription');
Route::post('/admin/predplatne/checkout', [SubscriptionController::class, 'checkout'])->name('admin.subscription.checkout');
Route::post('/admin/predplatne/portal', [SubscriptionController::class, 'portal'])->name('admin.subscription.portal');
Route::get('/admin/predplatne/dev-dokonceni', [SubscriptionController::class, 'devComplete'])->name('admin.subscription.dev-complete');

Route::get('/admin/predplatne/faktury', [SubscriptionInvoiceController::class, 'index'])->name('admin.subscription.invoices');
Route::get('/admin/predplatne/faktury/{invoice}/pdf', [SubscriptionInvoiceController::class, 'download'])->name('admin.subscription.invoices.pdf');
