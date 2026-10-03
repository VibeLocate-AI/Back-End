<?php

use App\Http\Controllers\Api\Admin\AdminAiHealthController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AdminVibeReportController;
use App\Http\Controllers\Api\Admin\AdminReportController;
use App\Http\Controllers\Api\Admin\AdminAgencyController;
use App\Http\Controllers\Api\NotificationController;

use App\Http\Middleware\RequireRole;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'jwt',
    RequireRole::class . ':admin',
])->prefix('admin')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Agencies
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/agencies',
        [AdminAgencyController::class, 'index']
    );

    Route::get(
        '/agencies/{id}',
        [AdminAgencyController::class, 'show']
    )->whereNumber('id');

    Route::put(
        '/agencies/{id}/status',
        [AdminAgencyController::class, 'updateStatus']
    )->whereNumber('id');

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
    | Update User Role
    |--------------------------------------------------------------------------
    */

    Route::put(
        '/users/{id}/role',
        [AdminUserController::class, 'updateRole']
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

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/notifications',
        [NotificationController::class, 'adminStore']
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
    | Vibe Report Coverage
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/vibe-report/coverage',
        [AdminVibeReportController::class, 'coverage']
    );
});