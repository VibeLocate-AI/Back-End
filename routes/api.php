<?php

use App\Http\Controllers\Api\ChangePasswordController;
use App\Http\Controllers\Api\CompleteProfileController;
use App\Http\Controllers\Api\ForgotPasswordController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\LoginController;
use App\Http\Controllers\Api\LogoutController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\RefreshTokenController;
use App\Http\Controllers\Api\RegisterController;
use App\Http\Controllers\Api\RememberMeController;
use App\Http\Controllers\Api\ResendVerificationController;
use App\Http\Controllers\Api\ResetPasswordController;
use App\Http\Controllers\Api\SessionsController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\VerifyEmailController;
use App\Http\Controllers\Api\VerifyResetOtpController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\HomeController;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

Route::post(
    '/register',
    RegisterController::class
);

Route::post(
    '/login',
    LoginController::class
)->middleware(
    'vibe.rate:auth.login,5,15'
);

/*
|--------------------------------------------------------------------------
| Google Authentication
|--------------------------------------------------------------------------
*/

Route::post(
    '/auth/google',
    GoogleAuthController::class
)->middleware(
    'vibe.rate:auth.google,10,15'
);

Route::post(
    '/logout',
    LogoutController::class
);

Route::post(
    '/refresh-token',
    RefreshTokenController::class
);

Route::post(
    '/remember-me',
    RememberMeController::class
);


/*
|--------------------------------------------------------------------------
| Email Verification
|--------------------------------------------------------------------------
*/

Route::match(
    ['get', 'post'],
    '/verify-email',
    VerifyEmailController::class
);

Route::post(
    '/verify-otp',
    VerifyEmailController::class
);

Route::post(
    '/resend-verification',
    ResendVerificationController::class
);

Route::post(
    '/resend-otp',
    ResendVerificationController::class
);


/*
|--------------------------------------------------------------------------
| Password Recovery
|--------------------------------------------------------------------------
*/

Route::post(
    '/forgot-password',
    ForgotPasswordController::class
);

Route::post(
    '/verify-reset-otp',
    VerifyResetOtpController::class
);

Route::post(
    '/reset-password',
    ResetPasswordController::class
);


/*
|--------------------------------------------------------------------------
| Properties
|--------------------------------------------------------------------------
|
| Public routes for browsing property listings.
|
*/

Route::get('/properties', [PropertyController::class, 'index']);
Route::get('/properties/{id}', [PropertyController::class, 'show']);

Route::get('/home', [HomeController::class, 'index']);
/*
|--------------------------------------------------------------------------
| Protected Routes
|--------------------------------------------------------------------------
*/

Route::middleware('jwt')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Profile
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/profile',
        [ProfileController::class, 'show']
    );

    Route::match(
        ['put', 'patch'],
        '/profile',
        [ProfileController::class, 'update']
    );

    Route::match(
        ['post', 'put', 'patch'],
        '/complete-profile',
        CompleteProfileController::class
    );


    /*
    |--------------------------------------------------------------------------
    | Change Password
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/change-password',
        ChangePasswordController::class
    );


    /*
    |--------------------------------------------------------------------------
    | Sessions
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/sessions',
        [SessionsController::class, 'index']
    );

    Route::delete(
        '/sessions',
        [SessionsController::class, 'destroy']
    );


    /*
    |--------------------------------------------------------------------------
    | Two Factor Authentication
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/two-factor',
        [TwoFactorController::class, 'show']
    );

    Route::post(
        '/two-factor',
        [TwoFactorController::class, 'store']
    );

    Route::delete(
        '/two-factor',
        [TwoFactorController::class, 'destroy']
    );
    
});