<?php

use App\Http\Controllers\Api\Admin\AdminAiHealthController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AdminVibeReportController;
use App\Http\Controllers\Api\Admin\AdminReportController;

use App\Http\Middleware\RequireRole;
use Illuminate\Support\Facades\Route;

Route::middleware([

    'jwt',
    RequireRole::class . ':admin',

])->prefix('admin')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Vibe Report Coverage
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/vibe-report/coverage',
        [AdminVibeReportController::class, 'coverage']
    );

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
    | Reports / Complaints
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/reports',
        [AdminReportController::class, 'index']
    );

    Route::get(
        '/reports/{id}',
        [AdminReportController::class, 'show']
    )->whereNumber('id');

    Route::put(
        '/reports/{id}/status',
        [AdminReportController::class, 'updateStatus']
    )->whereNumber('id');

});