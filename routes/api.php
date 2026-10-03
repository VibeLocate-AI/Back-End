<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Middleware
|--------------------------------------------------------------------------
*/

use App\Http\Middleware\RequireRole;
use App\Http\Middleware\EnsureActiveAgency;

/*
|--------------------------------------------------------------------------
| Authentication Controllers
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\Api\RegisterController;
use App\Http\Controllers\Api\LoginController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\LogoutController;
use App\Http\Controllers\Api\RefreshTokenController;
use App\Http\Controllers\Api\VerifyEmailController;
use App\Http\Controllers\Api\ResendVerificationController;
use App\Http\Controllers\Api\ForgotPasswordController;
use App\Http\Controllers\Api\VerifyResetOtpController;
use App\Http\Controllers\Api\ResetPasswordController;
use App\Http\Controllers\Api\ChangePasswordController;
use App\Http\Controllers\Api\SessionsController;
use App\Http\Controllers\Api\TwoFactorController;

/*
|--------------------------------------------------------------------------
| General API Controllers
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\CompleteProfileController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\MyPropertyController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\InquiryController;
use App\Http\Controllers\Api\SearchAlertController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\MapController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\AIContextualSearchController;

/*
|--------------------------------------------------------------------------
| Agent Controllers
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\Api\Agent\AgentRegisterController;
use App\Http\Controllers\Api\Agent\AgentOnboardingController;
use App\Http\Controllers\Api\Agent\AgentPropertyController;
use App\Http\Controllers\Api\Agent\AgentPoiController;


/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

/*
 * Normal user registration.
 */
Route::post(
    '/register',
    RegisterController::class
)->middleware(
    'vibe.rate:auth.register,5,15'
);

/*
 * Unified login:
 *
 * User
 * Owner
 * Agent
 * Admin
 * Super Admin
 */
Route::post(
    '/login',
    LoginController::class
)->middleware(
    'vibe.rate:auth.login,5,15'
);

/*
|--------------------------------------------------------------------------
| Agent Registration
|--------------------------------------------------------------------------
|
| Agent account creation is public.
| Email verification will be handled through /verify-otp.
|
*/

Route::post(
    '/agent/register',
    [AgentRegisterController::class, 'store']
)->middleware(
    'vibe.rate:auth.agent.register,5,15'
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


/*
|--------------------------------------------------------------------------
| Email Verification
|--------------------------------------------------------------------------
|
| Final endpoints:
|
| POST /api/verify-otp
| POST /api/resend-otp
|
| Removed aliases:
|
| /verify-email
| /resend-verification
|
*/

Route::post(
    '/verify-otp',
    VerifyEmailController::class
)->middleware(
    'vibe.rate:auth.verify-email,10,15'
);

Route::post(
    '/resend-otp',
    ResendVerificationController::class
)->middleware(
    'vibe.rate:auth.resend-otp,3,15'
);


/*
|--------------------------------------------------------------------------
| Token Management
|--------------------------------------------------------------------------
|
| These routes do not require a valid access JWT.
|
| A user may need to refresh/logout even when the access token
| has already expired.
|
*/

Route::post(
    '/refresh-token',
    RefreshTokenController::class
)->middleware(
    'vibe.rate:auth.refresh-token,30,15'
);

Route::post(
    '/logout',
    LogoutController::class
)->middleware(
    'vibe.rate:auth.logout,30,15'
);


/*
|--------------------------------------------------------------------------
| Password Recovery
|--------------------------------------------------------------------------
|
| Flow:
|
| forgot-password
|       ↓
| Email OTP
|       ↓
| verify-reset-otp
|       ↓
| Secure reset token
|       ↓
| reset-password
|
*/

Route::post(
    '/forgot-password',
    ForgotPasswordController::class
)->middleware(
    'vibe.rate:auth.forgot-password,3,15'
);

Route::post(
    '/verify-reset-otp',
    VerifyResetOtpController::class
)->middleware(
    'vibe.rate:auth.verify-reset,10,15'
);

Route::post(
    '/reset-password',
    ResetPasswordController::class
)->middleware(
    'vibe.rate:auth.reset-password,5,15'
);


/*
|--------------------------------------------------------------------------
| Public Properties
|--------------------------------------------------------------------------
*/

Route::get(
    '/properties',
    [PropertyController::class, 'index']
);


/*
|--------------------------------------------------------------------------
| Public Map
|--------------------------------------------------------------------------
*/

Route::get(
    '/map',
    [MapController::class, 'index']
);


/*
|--------------------------------------------------------------------------
| Home
|--------------------------------------------------------------------------
|
| Supported languages:
|
| en
| ar
|
*/

Route::prefix('home/{lang}')
    ->where([
        'lang' => 'en|ar',
    ])
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Featured Properties
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/featured-properties',
            [HomeController::class, 'featuredProperties']
        );

        Route::get(
            '/featured-properties/{id}',
            [HomeController::class, 'featuredPropertyDetails']
        )->whereNumber('id');


        /*
        |--------------------------------------------------------------------------
        | Recommended Properties
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/recommended-properties',
            [HomeController::class, 'recommendedProperties']
        );

        Route::get(
            '/recommended-properties/{id}',
            [HomeController::class, 'recommendedPropertyDetails']
        )->whereNumber('id');


        /*
        |--------------------------------------------------------------------------
        | Popular Areas
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/popular-areas',
            [HomeController::class, 'popularAreas']
        );

        Route::get(
            '/popular-areas/{id}',
            [HomeController::class, 'popularAreaDetails']
        )->whereNumber('id');


        /*
        |--------------------------------------------------------------------------
        | Top Agents
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/top-agents',
            [HomeController::class, 'topAgents']
        );

        Route::get(
            '/top-agents/{id}',
            [HomeController::class, 'topAgentDetails']
        )->whereNumber('id');
    });


/*
|--------------------------------------------------------------------------
| AI Contextual Search
|--------------------------------------------------------------------------
*/

Route::post(
    '/ai/contextual-search',
    [AIContextualSearchController::class, 'search']
);


/*
|--------------------------------------------------------------------------
| Protected User Routes
|--------------------------------------------------------------------------
|
| Everything inside this group requires:
|
| Authorization: Bearer <access_token>
|
*/

Route::middleware('jwt')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Profile Collections
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/profile/saved-properties',
        [ProfileController::class, 'savedProperties']
    );

    Route::get(
        '/profile/recently-viewed',
        [ProfileController::class, 'recentlyViewed']
    );

    Route::get(
        '/profile/search-alerts',
        [ProfileController::class, 'profileSearchAlerts']
    );

    Route::get(
        '/profile/inquiries',
        [ProfileController::class, 'profileInquiries']
    );


    /*
    |--------------------------------------------------------------------------
    | Property Inquiries
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/inquiries',
        [InquiryController::class, 'index']
    );

    Route::post(
        '/properties/{propertyId}/inquiries',
        [InquiryController::class, 'store']
    )->whereNumber('propertyId');


    /*
    |--------------------------------------------------------------------------
    | Search Alerts
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/search-alerts',
        [SearchAlertController::class, 'index']
    );

    Route::post(
        '/search-alerts',
        [SearchAlertController::class, 'store']
    );

    Route::put(
        '/search-alerts/{id}',
        [SearchAlertController::class, 'update']
    )->whereNumber('id');

    Route::delete(
        '/search-alerts/{id}',
        [SearchAlertController::class, 'destroy']
    )->whereNumber('id');


    /*
    |--------------------------------------------------------------------------
    | Property Reviews
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/properties/{propertyId}/review',
        [ReviewController::class, 'show']
    )->whereNumber('propertyId');

    Route::post(
        '/properties/{propertyId}/review',
        [ReviewController::class, 'store']
    )->whereNumber('propertyId');

    Route::put(
        '/properties/{propertyId}/review',
        [ReviewController::class, 'update']
    )->whereNumber('propertyId');

    Route::delete(
        '/properties/{propertyId}/review',
        [ReviewController::class, 'destroy']
    )->whereNumber('propertyId');


    /*
    |--------------------------------------------------------------------------
    | My Properties
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/my-properties',
        [MyPropertyController::class, 'index']
    );

    Route::put(
        '/my-properties/{id}',
        [MyPropertyController::class, 'update']
    )->whereNumber('id');

    Route::delete(
        '/my-properties/{id}',
        [MyPropertyController::class, 'destroy']
    )->whereNumber('id');


    /*
    |--------------------------------------------------------------------------
    | Nearby Places
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/properties/{id}/nearby',
        [PropertyController::class, 'nearby']
    )->whereNumber('id');


    /*
    |--------------------------------------------------------------------------
    | Property Details / Create
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/properties/{id}',
        [PropertyController::class, 'show']
    )->whereNumber('id');

    Route::post(
        '/properties',
        [PropertyController::class, 'store']
    );


    /*
    |--------------------------------------------------------------------------
    | Favorites
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/favorites',
        [FavoriteController::class, 'index']
    );

    Route::post(
        '/favorites/{propertyId}',
        [FavoriteController::class, 'store']
    )->whereNumber('propertyId');

    Route::delete(
        '/favorites/{propertyId}',
        [FavoriteController::class, 'destroy']
    )->whereNumber('propertyId');


    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/notifications',
        [NotificationController::class, 'index']
    );

    Route::get(
        '/notifications/unread-count',
        [NotificationController::class, 'unreadCount']
    );

    Route::put(
        '/notifications/read-all',
        [NotificationController::class, 'markAllAsRead']
    );

    Route::put(
        '/notifications/{id}/read',
        [NotificationController::class, 'markAsRead']
    )->whereNumber('id');

    Route::delete(
        '/notifications/{id}',
        [NotificationController::class, 'destroy']
    )->whereNumber('id');


    /*
    |--------------------------------------------------------------------------
    | Reports / Complaints
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/reports',
        [ReportController::class, 'store']
    );

    Route::get(
        '/reports',
        [ReportController::class, 'index']
    );


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

    Route::put(
        '/profile/preferences',
        [ProfileController::class, 'updatePreferences']
    );


    /*
    |--------------------------------------------------------------------------
    | Profile Location
    |--------------------------------------------------------------------------
    */

    Route::put(
        '/profile/location',
        [ProfileController::class, 'updateLocation']
    );


    /*
    |--------------------------------------------------------------------------
    | Profile Avatar
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/profile/avatar',
        [ProfileController::class, 'updateAvatar']
    );


    /*
    |--------------------------------------------------------------------------
    | Complete Profile
    |--------------------------------------------------------------------------
    */

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
    )->middleware(
        'vibe.rate:auth.change-password,5,15'
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
    | Two-Factor Authentication
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


/*
|--------------------------------------------------------------------------
| Agent Routes
|--------------------------------------------------------------------------
|
| Agent must:
|
| 1. Have authenticated JWT
| 2. Have role = agent
|
*/

Route::middleware([
    'jwt',
    RequireRole::class . ':agent',
])
    ->prefix('agent')
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Agent Onboarding
        |--------------------------------------------------------------------------
        |
        | Pending agencies ARE allowed here.
        |
        | This allows the Agent to complete:
        |
        | - License
        | - Expertise
        | - Profile
        |
        */

        Route::get(
            '/onboarding/status',
            [AgentOnboardingController::class, 'status']
        );

        Route::post(
            '/onboarding/license',
            [AgentOnboardingController::class, 'license']
        );

        Route::post(
            '/onboarding/expertise',
            [AgentOnboardingController::class, 'expertise']
        );

        Route::post(
            '/onboarding/profile',
            [AgentOnboardingController::class, 'profile']
        );


        /*
        |--------------------------------------------------------------------------
        | Active Agency Only
        |--------------------------------------------------------------------------
        |
        | Property moderation and POI management require
        | an ACTIVE agency.
        |
        */

        Route::middleware(
            EnsureActiveAgency::class
        )->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Agent Property Moderation
            |--------------------------------------------------------------------------
            */

            Route::get(
                '/properties',
                [AgentPropertyController::class, 'index']
            );

            Route::put(
                '/properties/{id}/approve',
                [AgentPropertyController::class, 'approve']
            )->whereNumber('id');

            Route::put(
                '/properties/{id}/reject',
                [AgentPropertyController::class, 'reject']
            )->whereNumber('id');


            /*
            |--------------------------------------------------------------------------
            | Agent POIs
            |--------------------------------------------------------------------------
            */

            Route::get(
                '/pois',
                [AgentPoiController::class, 'index']
            );

            Route::post(
                '/pois',
                [AgentPoiController::class, 'store']
            );

            Route::put(
                '/pois/{id}',
                [AgentPoiController::class, 'update']
            )->whereNumber('id');

            Route::delete(
                '/pois/{id}',
                [AgentPoiController::class, 'destroy']
            )->whereNumber('id');
        });
    });


/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
|
| Admin-specific routes live in:
|
| routes/admin.php
|
*/

require __DIR__ . '/admin.php';