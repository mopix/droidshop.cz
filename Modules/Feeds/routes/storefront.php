<?php

use Illuminate\Support\Facades\Route;
use Modules\Feeds\Http\Controllers\FeedController;

// One route for both feeds; the controller refuses an unknown type. The
// module gate is applied by ModuleRouteRegistrar, so a shop that does not run
// the module gets a flat 404.
Route::get('/feed/{type}.xml', FeedController::class)
    ->where('type', '[a-z]+')
    ->name('show');
