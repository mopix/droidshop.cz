<?php

use App\Core\Storage\FileStorage;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Storage\PrivateFileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Private tenant files. `signed` proves the URL is ours and unexpired; the
// controller then checks the file belongs to the current tenant. The tenant
// pipeline (prepended to the web group) has already set context by here.
Route::get('/soubory/{tenant}/{path}', PrivateFileController::class)
    ->where('path', '.*')
    ->middleware('signed')
    ->name(FileStorage::SIGNED_ROUTE);

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
