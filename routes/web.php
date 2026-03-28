<?php

use Illuminate\Support\Facades\Route;
use PTeal79\MobileFileCache\Http\Controllers\CachedFileController;

Route::middleware(config('mobile-file-cache.route_middleware', ['web']))
    ->prefix(config('mobile-file-cache.route_prefix', 'mobile-cache'))
    ->group(function () {
        Route::get('/file', [CachedFileController::class, 'show'])
            ->name(config('mobile-file-cache.route_name', 'mobile_cached'));
    });
