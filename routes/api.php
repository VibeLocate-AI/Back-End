<?php
use App\Http\Controllers\Api\InquiryController;
use App\Services\VibeAiService;
use App\Http\Controllers\Api\SearchAlertController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\MapController;
use App\Http\Controllers\Api\AIContextualSearchController;
use App\Http\Controllers\Api\ChangePasswordController;
use App\Http\Controllers\Api\CompleteProfileController;
use App\Http\Controllers\Api\ForgotPasswordController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\HomeController;
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
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\MyPropertyController;
use App\Http\Controllers\Api\Agent\AgentPropertyController;
use App\Http\Middleware\RequireRole;

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

Route::post('/register', RegisterController::class);

Route::post('/login', LoginController::class)
    ->middleware('vibe.rate:auth.login,5,15');

/*
|--------------------------------------------------------------------------
| Google Authentication
|--------------------------------------------------------------------------
*/

Route::post('/auth/google', GoogleAuthController::class)
    ->middleware('vibe.rate:auth.google,10,15');

Route::post('/logout', LogoutController::class);

Route::post('/refresh-token', RefreshTokenController::class);

Route::post('/remember-me', RememberMeController::class);

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
*/

Route::get(
    '/properties',
    [PropertyController::class, 'index']
);

/*
|--------------------------------------------------------------------------
| Map
|--------------------------------------------------------------------------
*/

Route::get(
    '/map',
    [MapController::class, 'index']
);

/*
|--------------------------------------------------------------------------
| Home - Separate English / Arabic Endpoints
|--------------------------------------------------------------------------
*/

Route::prefix('home/{lang}')
    ->where([
        'lang' => 'en|ar'
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
| Protected Routes
|--------------------------------------------------------------------------
*/

Route::middleware('jwt')->group(function () {
    Route::post(
    '/admin/notifications',
    [NotificationController::class, 'adminStore']
);
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
    | Nearby Properties
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/properties/{id}/nearby',
        [MyPropertyController::class, 'nearby']
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
    | Password
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

    /*
    |--------------------------------------------------------------------------
    | Temporary AI Connection Test
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/test-ai-connection',
        function (VibeAiService $aiService) {

            $result = $aiService->parseSearchQuery(
                'I want an office under 200000 AED in Dubai Marina',
                'en'
            );

            if ($result === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to connect to AI service',
                ], 503);
            }

            return response()->json([
                'success' => true,
                'ai_response' => $result,
            ]);
        }
    );
});

/*
|--------------------------------------------------------------------------
| Agent Property Moderation
|--------------------------------------------------------------------------
*/

Route::middleware([
    'jwt',
    RequireRole::class . ':agent',
])->prefix('agent')->group(function () {

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

});


/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
*/

require __DIR__ . '/admin.php';