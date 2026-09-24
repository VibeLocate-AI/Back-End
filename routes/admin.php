<?php

use App\Http\Controllers\Api\Admin\AdminAiHealthController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminPropertyController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Middleware\RequireRole;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Admin\AdminVibeReportController;

Route::middleware([
    
    'jwt',
    RequireRole::class . ':admin',
])->prefix('admin')->group(function () {

    Route::get('/vibe-report/coverage', [AdminVibeReportController::class, 'coverage']);
    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/dashboard',
        AdminDashboardController::class
    );

    /*
    |--------------------------------------------------------------------------
    | AI Health
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/ai-health',
        AdminAiHealthController::class
    );

    /*
    |--------------------------------------------------------------------------
    | Users
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/users',
        [AdminUserController::class, 'index']
    );

    Route::get(
        '/users/{id}',
        [AdminUserController::class, 'show']
    )->whereNumber('id');

    Route::put(
        '/users/{id}/status',
        [AdminUserController::class, 'updateStatus']
    )->whereNumber('id');

    /*
    |--------------------------------------------------------------------------
    | Property Moderation
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/properties',
        [AdminPropertyController::class, 'index']
    );

    Route::put(
        '/properties/{id}/approve',
        [AdminPropertyController::class, 'approve']
    )->whereNumber('id');

    Route::put(
        '/properties/{id}/reject',
        [AdminPropertyController::class, 'reject']
    )->whereNumber('id');

});
