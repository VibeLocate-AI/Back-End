<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Cloudinary\Cloudinary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProfileController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Get Full Profile Page
    |--------------------------------------------------------------------------
    */

    public function show(Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('auth_user');

            if (!$user || empty($user['id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $userId = (int) $user['id'];

            /*
            |--------------------------------------------------------------------------
            | Profile
            |--------------------------------------------------------------------------
            */

            $profile = $this->getProfile($userId);

            if (!$profile) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | Roles
            |--------------------------------------------------------------------------
            */

            $roles = DB::table('user_roles as ur')
                ->join(
                    'roles as r',
                    'r.id',
                    '=',
                    'ur.role_id'
                )
                ->where('ur.user_id', $userId)
                ->select([
                    'r.id',
                    'r.name',
                    'r.slug',
                ])
                ->get();

            $roleSlugs = $roles
                ->pluck('slug')
                ->values()
                ->all();

            $primaryRole = $this->getPrimaryRole(
                $roleSlugs
            );

            /*
            |--------------------------------------------------------------------------
            | Statistics
            |--------------------------------------------------------------------------
            */

            $favoritesCount = DB::table('favorites')
                ->where('user_id', $userId)
                ->count();

            $viewedPropertiesCount = DB::table('property_views')
                ->where('user_id', $userId)
                ->distinct()
                ->count('property_id');

            $searchAlertsCount = DB::table('saved_searches')
                ->where('user_id', $userId)
                ->where('is_active', 1)
                ->count();

            $inquiriesCount = DB::table('property_leads')
                ->where('user_id', $userId)
                ->count();

            /*
            |--------------------------------------------------------------------------
            | Preferences
            |--------------------------------------------------------------------------
            */

            $preferences = DB::table('user_preferences')
                ->where('user_id', $userId)
                ->first();

            if ($preferences) {
                $preferencesData = [
                    'preferred_area' =>
                        $preferences->preferred_area,

                    'property_types' =>
                        $this->decodeJson(
                            $preferences->property_types
                        ),

                    'min_price' =>
                        $preferences->min_price !== null
                            ? (float) $preferences->min_price
                            : null,

                    'max_price' =>
                        $preferences->max_price !== null
                            ? (float) $preferences->max_price
                            : null,

                    'min_bedrooms' =>
                        $preferences->min_bedrooms !== null
                            ? (int) $preferences->min_bedrooms
                            : null,

                    'max_bedrooms' =>
                        $preferences->max_bedrooms !== null
                            ? (int) $preferences->max_bedrooms
                            : null,

                    'lifestyle_preferences' =>
                        $this->decodeJson(
                            $preferences->lifestyle_preferences
                        ),

                    'email_notifications' =>
                        (bool) $preferences->email_notifications,

                    'browser_notifications' =>
                        (bool) $preferences->browser_notifications,
                ];
            } else {
                $preferencesData = [
                    'preferred_area' => null,
                    'property_types' => [],
                    'min_price' => null,
                    'max_price' => null,
                    'min_bedrooms' => null,
                    'max_bedrooms' => null,
                    'lifestyle_preferences' => [],
                    'email_notifications' => true,
                    'browser_notifications' => true,
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | Recently Viewed
            |--------------------------------------------------------------------------
            */

            $latestViews = DB::table('property_views')
                ->where('user_id', $userId)
                ->select([
                    'property_id',
                    DB::raw(
                        'MAX(viewed_at) as last_viewed_at'
                    ),
                ])
                ->groupBy('property_id');

            $recentlyViewed = DB::table('properties as p')
                ->joinSub(
                    $latestViews,
                    'pv',
                    function ($join) {
                        $join->on(
                            'pv.property_id',
                            '=',
                            'p.id'
                        );
                    }
                )
                ->leftJoin(
                    'property_types as pt',
                    'pt.id',
                    '=',
                    'p.type_id'
                )
                ->leftJoin(
                    'property_locations as pl',
                    'pl.property_id',
                    '=',
                    'p.id'
                )
                ->whereNull('p.deleted_at')
                ->where(
                    'p.moderation_status',
                    'approved'
                )
                ->select([
                    'p.id',
                    'p.title',
                    'p.slug',
                    'p.price',
                    'p.currency',
                    'p.bedrooms',
                    'p.bathrooms',
                    'p.area_sqft',
                    'p.property_condition',
                    'p.action_type',

                    'pt.name as type_name',

                    'pl.address_line_1',
                    'pl.building_name',
                    'pl.latitude',
                    'pl.longitude',

                    'pv.last_viewed_at',
                ])
                ->orderByDesc(
                    'pv.last_viewed_at'
                )
                ->limit(6)
                ->get();

            $recentIds = $recentlyViewed
                ->pluck('id')
                ->values()
                ->all();

            $recentImages = collect();

            if (!empty($recentIds)) {
                $recentImages = DB::table(
                    'property_images'
                )
                    ->whereIn(
                        'property_id',
                        $recentIds
                    )
                    ->orderByDesc('is_primary')
                    ->orderBy('display_order')
                    ->get()
                    ->groupBy('property_id');
            }

            $recentlyViewed = $recentlyViewed
                ->map(
                    function ($property) use (
                        $recentImages
                    ) {
                        $propertyImages =
                            $recentImages->get(
                                $property->id,
                                collect()
                            );

                        $property->primary_image =
                            optional(
                                $propertyImages->first()
                            )->image_url;

                        return $property;
                    }
                );

            /*
            |--------------------------------------------------------------------------
            | Saved Properties
            |--------------------------------------------------------------------------
            */

            $savedProperties = DB::table(
                'favorites as f'
            )
                ->join(
                    'properties as p',
                    'p.id',
                    '=',
                    'f.property_id'
                )
                ->leftJoin(
                    'property_types as pt',
                    'pt.id',
                    '=',
                    'p.type_id'
                )
                ->leftJoin(
                    'property_locations as pl',
                    'pl.property_id',
                    '=',
                    'p.id'
                )
                ->where(
                    'f.user_id',
                    $userId
                )
                ->whereNull(
                    'p.deleted_at'
                )
                ->where(
                    'p.moderation_status',
                    'approved'
                )
                ->select([
                    'p.id',
                    'p.title',
                    'p.slug',
                    'p.price',
                    'p.currency',
                    'p.bedrooms',
                    'p.bathrooms',
                    'p.area_sqft',
                    'p.property_condition',
                    'p.action_type',

                    'pt.name as type_name',

                    'pl.address_line_1',
                    'pl.building_name',
                    'pl.latitude',
                    'pl.longitude',

                    'f.created_at as saved_at',
                ])
                ->orderByDesc(
                    'f.created_at'
                )
                ->limit(6)
                ->get();

            $savedPropertyIds = $savedProperties
                ->pluck('id')
                ->values()
                ->all();

            $savedImages = collect();

            if (!empty($savedPropertyIds)) {
                $savedImages = DB::table(
                    'property_images'
                )
                    ->whereIn(
                        'property_id',
                        $savedPropertyIds
                    )
                    ->orderByDesc('is_primary')
                    ->orderBy('display_order')
                    ->get()
                    ->groupBy('property_id');
            }

            $savedProperties = $savedProperties
                ->map(
                    function ($property) use (
                        $savedImages
                    ) {
                        $propertyImages =
                            $savedImages->get(
                                $property->id,
                                collect()
                            );

                        $property->primary_image =
                            optional(
                                $propertyImages->first()
                            )->image_url;

                        return $property;
                    }
                );

            /*
            |--------------------------------------------------------------------------
            | Search Alerts
            |--------------------------------------------------------------------------
            */

            $searchAlertsList = DB::table(
                'saved_searches'
            )
                ->where(
                    'user_id',
                    $userId
                )
                ->orderByDesc(
                    'created_at'
                )
                ->limit(5)
                ->get()
                ->map(
                    function ($alert) {
                        $alert->query_parameters =
                            $this->decodeJson(
                                $alert->query_parameters
                            );

                        $alert->is_active =
                            (bool) $alert->is_active;

                        return $alert;
                    }
                );

            /*
            |--------------------------------------------------------------------------
            | Inquiries
            |--------------------------------------------------------------------------
            */

            $inquiriesList = DB::table(
                'property_leads as pl'
            )
                ->join(
                    'properties as p',
                    'p.id',
                    '=',
                    'pl.property_id'
                )
                ->where(
                    'pl.user_id',
                    $userId
                )
                ->whereNull(
                    'p.deleted_at'
                )
                ->select([
                    'pl.id',
                    'pl.property_id',
                    'pl.message',
                    'pl.status',
                    'pl.created_at',

                    'p.title as property_title',
                    'p.slug as property_slug',
                    'p.price as property_price',
                    'p.currency as property_currency',
                ])
                ->orderByDesc(
                    'pl.created_at'
                )
                ->limit(5)
                ->get();

            $inquiryPropertyIds = $inquiriesList
                ->pluck('property_id')
                ->unique()
                ->values()
                ->all();

            $inquiryImages = collect();

            if (!empty($inquiryPropertyIds)) {
                $inquiryImages = DB::table(
                    'property_images'
                )
                    ->whereIn(
                        'property_id',
                        $inquiryPropertyIds
                    )
                    ->orderByDesc('is_primary')
                    ->orderBy('display_order')
                    ->get()
                    ->groupBy('property_id');
            }

            $inquiriesList = $inquiriesList
                ->map(
                    function ($inquiry) use (
                        $inquiryImages
                    ) {
                        $propertyImages =
                            $inquiryImages->get(
                                $inquiry->property_id,
                                collect()
                            );

                        $inquiry->property_image =
                            optional(
                                $propertyImages->first()
                            )->image_url;

                        $inquiry->property_price =
                            (float)
                            $inquiry->property_price;

                        return $inquiry;
                    }
                );

            /*
            |--------------------------------------------------------------------------
            | Profile Extra Data
            |--------------------------------------------------------------------------
            */

            $profile->full_name = trim(
                ($profile->first_name ?? '')
                . ' '
                . ($profile->last_name ?? '')
            );

            $profile->location =
                $this->formatLocation(
                    $profile->city,
                    $profile->country
                );

            $profile->account_type =
                $primaryRole;

            $profile->roles =
                $roles;

            $profile->member_since =
                $profile->created_at;

            /*
            |--------------------------------------------------------------------------
            | Final Response
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'success' => true,

                'data' => [

                    /*
                    |--------------------------------------------------------------------------
                    | Profile
                    |--------------------------------------------------------------------------
                    */

                    'profile' => [
                        'id' =>
                            (int) $profile->id,

                        'first_name' =>
                            $profile->first_name,

                        'last_name' =>
                            $profile->last_name,

                        'full_name' =>
                            $profile->full_name,

                        'email' =>
                            $profile->email,

                        'phone' =>
                            $profile->phone,

                        'status' =>
                            $profile->status,

                        'email_verified' =>
                            $profile
                                ->email_verified_at
                                !== null,

                        'email_verified_at' =>
                            $profile
                                ->email_verified_at,

                        'avatar_url' =>
                            $profile->avatar_url,

                        'bio' =>
                            $profile->bio,

                        'city' =>
                            $profile->city,

                        'country' =>
                            $profile->country,

                        'location' =>
                            $profile->location,

                        'latitude' =>
                            $profile->latitude,

                        'longitude' =>
                            $profile->longitude,

                        'preferred_language' =>
                            $profile
                                ->preferred_language,

                        'currency' =>
                            $profile->currency,

                        'nationality' =>
                            $profile->nationality,

                        'date_of_birth' =>
                            $profile->date_of_birth,

                        'gender' =>
                            $profile->gender,

                        'account_type' =>
                            $profile->account_type,

                        'roles' =>
                            $profile->roles,

                        'member_since' =>
                            $profile->member_since,
                    ],

                    /*
                    |--------------------------------------------------------------------------
                    | Statistics
                    |--------------------------------------------------------------------------
                    */

                    'stats' => [
                        'saved_properties' =>
                            $favoritesCount,

                        'viewed_properties' =>
                            $viewedPropertiesCount,

                        'search_alerts' =>
                            $searchAlertsCount,

                        'inquiries' =>
                            $inquiriesCount,
                    ],

                    /*
                    |--------------------------------------------------------------------------
                    | Preferences
                    |--------------------------------------------------------------------------
                    */

                    'preferences' => [
                        'preferred_area' =>
                            $preferencesData[
                                'preferred_area'
                            ],

                        'property_types' =>
                            $preferencesData[
                                'property_types'
                            ],

                        'price_range' => [
                            'min' =>
                                $preferencesData[
                                    'min_price'
                                ],

                            'max' =>
                                $preferencesData[
                                    'max_price'
                                ],
                        ],

                        'bedrooms' => [
                            'min' =>
                                $preferencesData[
                                    'min_bedrooms'
                                ],

                            'max' =>
                                $preferencesData[
                                    'max_bedrooms'
                                ],
                        ],

                        'lifestyle_preferences' =>
                            $preferencesData[
                                'lifestyle_preferences'
                            ],

                        'notifications' => [
                            'email' =>
                                $preferencesData[
                                    'email_notifications'
                                ],

                            'browser' =>
                                $preferencesData[
                                    'browser_notifications'
                                ],
                        ],
                    ],

                    /*
                    |--------------------------------------------------------------------------
                    | Sections
                    |--------------------------------------------------------------------------
                    */

                    'sections' => [

                        /*
                        |--------------------------------------------------------------------------
                        | Recently Viewed
                        |--------------------------------------------------------------------------
                        */

                        'recently_viewed' => [
                            'count' =>
                                $recentlyViewed
                                    ->count(),

                            'items' =>
                                $recentlyViewed
                                    ->map(
                                        function (
                                            $property
                                        ) {
                                            return [
                                                'id' =>
                                                    (int)
                                                    $property
                                                        ->id,

                                                'title' =>
                                                    $property
                                                        ->title,

                                                'slug' =>
                                                    $property
                                                        ->slug,

                                                'price' =>
                                                    (float)
                                                    $property
                                                        ->price,

                                                'currency' =>
                                                    $property
                                                        ->currency,

                                                'type' =>
                                                    $property
                                                        ->type_name,

                                                'bedrooms' =>
                                                    (int)
                                                    $property
                                                        ->bedrooms,

                                                'bathrooms' =>
                                                    (int)
                                                    $property
                                                        ->bathrooms,

                                                'area_sqft' =>
                                                    (float)
                                                    $property
                                                        ->area_sqft,

                                                'property_condition' =>
                                                    $property
                                                        ->property_condition,

                                                'action_type' =>
                                                    $property
                                                        ->action_type,

                                                'location' => [
                                                    'address' =>
                                                        $property
                                                            ->address_line_1,

                                                    'building_name' =>
                                                        $property
                                                            ->building_name,

                                                    'latitude' =>
                                                        $property
                                                                ->latitude
                                                            !== null
                                                            ? (float)
                                                                $property
                                                                    ->latitude
                                                            : null,

                                                    'longitude' =>
                                                        $property
                                                                ->longitude
                                                            !== null
                                                            ? (float)
                                                                $property
                                                                    ->longitude
                                                            : null,
                                                ],

                                                'primary_image' =>
                                                    $property
                                                        ->primary_image,

                                                'last_viewed_at' =>
                                                    $property
                                                        ->last_viewed_at,
                                            ];
                                        }
                                    )
                                    ->values(),
                        ],

                        /*
                        |--------------------------------------------------------------------------
                        | Saved Properties
                        |--------------------------------------------------------------------------
                        */

                        'saved_properties' => [
                            'count' =>
                                $favoritesCount,

                            'items' =>
                                $savedProperties
                                    ->map(
                                        function (
                                            $property
                                        ) {
                                            return [
                                                'id' =>
                                                    (int)
                                                    $property
                                                        ->id,

                                                'title' =>
                                                    $property
                                                        ->title,

                                                'slug' =>
                                                    $property
                                                        ->slug,

                                                'price' =>
                                                    (float)
                                                    $property
                                                        ->price,

                                                'currency' =>
                                                    $property
                                                        ->currency,

                                                'type' =>
                                                    $property
                                                        ->type_name,

                                                'bedrooms' =>
                                                    (int)
                                                    $property
                                                        ->bedrooms,

                                                'bathrooms' =>
                                                    (int)
                                                    $property
                                                        ->bathrooms,

                                                'area_sqft' =>
                                                    (float)
                                                    $property
                                                        ->area_sqft,

                                                'property_condition' =>
                                                    $property
                                                        ->property_condition,

                                                'action_type' =>
                                                    $property
                                                        ->action_type,

                                                'location' => [
                                                    'address' =>
                                                        $property
                                                            ->address_line_1,

                                                    'building_name' =>
                                                        $property
                                                            ->building_name,

                                                    'latitude' =>
                                                        $property
                                                                ->latitude
                                                            !== null
                                                            ? (float)
                                                                $property
                                                                    ->latitude
                                                            : null,

                                                    'longitude' =>
                                                        $property
                                                                ->longitude
                                                            !== null
                                                            ? (float)
                                                                $property
                                                                    ->longitude
                                                            : null,
                                                ],

                                                'primary_image' =>
                                                    $property
                                                        ->primary_image,

                                                'saved_at' =>
                                                    $property
                                                        ->saved_at,
                                            ];
                                        }
                                    )
                                    ->values(),
                        ],

                        /*
                        |--------------------------------------------------------------------------
                        | Search Alerts
                        |--------------------------------------------------------------------------
                        */

                        'search_alerts' => [
                            'count' =>
                                $searchAlertsCount,

                            'items' =>
                                $searchAlertsList
                                    ->map(
                                        function (
                                            $alert
                                        ) {
                                            return [
                                                'id' =>
                                                    (int)
                                                    $alert->id,

                                                'name' =>
                                                    $alert
                                                        ->search_name,

                                                'filters' =>
                                                    $alert
                                                        ->query_parameters,

                                                'is_active' =>
                                                    (bool)
                                                    $alert
                                                        ->is_active,

                                                'created_at' =>
                                                    $alert
                                                        ->created_at,

                                                'updated_at' =>
                                                    $alert
                                                        ->updated_at,
                                            ];
                                        }
                                    )
                                    ->values(),
                        ],

                        /*
                        |--------------------------------------------------------------------------
                        | Inquiries
                        |--------------------------------------------------------------------------
                        */

                        'inquiries' => [
                            'count' =>
                                $inquiriesCount,

                            'items' =>
                                $inquiriesList
                                    ->map(
                                        function (
                                            $inquiry
                                        ) {
                                            return [
                                                'id' =>
                                                    (int)
                                                    $inquiry
                                                        ->id,

                                                'property' => [
                                                    'id' =>
                                                        (int)
                                                        $inquiry
                                                            ->property_id,

                                                    'title' =>
                                                        $inquiry
                                                            ->property_title,

                                                    'slug' =>
                                                        $inquiry
                                                            ->property_slug,

                                                    'price' =>
                                                        (float)
                                                        $inquiry
                                                            ->property_price,

                                                    'currency' =>
                                                        $inquiry
                                                            ->property_currency,

                                                    'image' =>
                                                        $inquiry
                                                            ->property_image,
                                                ],

                                                'message' =>
                                                    $inquiry
                                                        ->message,

                                                'status' =>
                                                    $inquiry
                                                        ->status,

                                                'created_at' =>
                                                    $inquiry
                                                        ->created_at,
                                            ];
                                        }
                                    )
                                    ->values(),
                        ],
                    ],
                ],
            ]);

        } catch (Throwable $e) {
            Log::error(
                'Failed to load full profile',
                [
                    'error' =>
                        $e->getMessage(),
                ]
            );

            report($e);

            return response()->json([
                'success' => false,
                'message' =>
                    'Failed to load profile',

                'details' =>
                    app()->environment('local')
                        ? $e->getMessage()
                        : null,
            ], 500);
        }
    }
/*
|--------------------------------------------------------------------------
| View All Saved Properties
|--------------------------------------------------------------------------
*/

public function savedProperties(Request $request): JsonResponse
{
    try {
        $user = $request->attributes->get('auth_user');

        if (!$user || empty($user['id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $userId = (int) $user['id'];

        $perPage = min(
            max((int) $request->query('per_page', 12), 1),
            50
        );

        $properties = DB::table('favorites as f')
            ->join(
                'properties as p',
                'p.id',
                '=',
                'f.property_id'
            )
            ->leftJoin(
                'property_types as pt',
                'pt.id',
                '=',
                'p.type_id'
            )
            ->leftJoin(
                'property_locations as pl',
                'pl.property_id',
                '=',
                'p.id'
            )
            ->where('f.user_id', $userId)
            ->whereNull('p.deleted_at')
            ->where('p.moderation_status', 'approved')
            ->select([
                'p.id',
                'p.title',
                'p.slug',
                'p.price',
                'p.currency',
                'p.bedrooms',
                'p.bathrooms',
                'p.area_sqft',
                'p.property_condition',
                'p.action_type',

                'pt.name as type_name',

                'pl.address_line_1',
                'pl.building_name',
                'pl.latitude',
                'pl.longitude',

                'f.created_at as saved_at',
            ])
            ->orderByDesc('f.created_at')
            ->paginate($perPage);

        $propertyIds = $properties
            ->getCollection()
            ->pluck('id')
            ->values()
            ->all();

        $images = collect();

        if (!empty($propertyIds)) {
            $images = DB::table('property_images')
                ->whereIn('property_id', $propertyIds)
                ->orderByDesc('is_primary')
                ->orderBy('display_order')
                ->get()
                ->groupBy('property_id');
        }

        $properties->setCollection(
            $properties
                ->getCollection()
                ->map(function ($property) use ($images) {
                    $propertyImages = $images->get(
                        $property->id,
                        collect()
                    );

                    return [
                        'id' => (int) $property->id,
                        'title' => $property->title,
                        'slug' => $property->slug,

                        'price' => (float) $property->price,
                        'currency' => $property->currency,

                        'type' => $property->type_name,

                        'bedrooms' => (int) $property->bedrooms,
                        'bathrooms' => (int) $property->bathrooms,
                        'area_sqft' => (float) $property->area_sqft,

                        'property_condition' =>
                            $property->property_condition,

                        'action_type' =>
                            $property->action_type,

                        'location' => [
                            'address' =>
                                $property->address_line_1,

                            'building_name' =>
                                $property->building_name,

                            'latitude' =>
                                $property->latitude !== null
                                    ? (float) $property->latitude
                                    : null,

                            'longitude' =>
                                $property->longitude !== null
                                    ? (float) $property->longitude
                                    : null,
                        ],

                        'primary_image' =>
                            optional(
                                $propertyImages->first()
                            )->image_url,

                        'saved_at' =>
                            $property->saved_at,
                    ];
                })
                ->values()
        );

        return response()->json([
            'success' => true,

            'data' => $properties->items(),

            'pagination' => [
                'current_page' =>
                    $properties->currentPage(),

                'last_page' =>
                    $properties->lastPage(),

                'per_page' =>
                    $properties->perPage(),

                'total' =>
                    $properties->total(),

                'from' =>
                    $properties->firstItem(),

                'to' =>
                    $properties->lastItem(),

                'has_more' =>
                    $properties->hasMorePages(),
            ],
        ]);

    } catch (Throwable $e) {
        report($e);

        return response()->json([
            'success' => false,
            'message' =>
                'Failed to load saved properties',

            'details' =>
                app()->environment('local')
                    ? $e->getMessage()
                    : null,
        ], 500);
    }
}


/*
|--------------------------------------------------------------------------
| View All Recently Viewed
|--------------------------------------------------------------------------
*/

public function recentlyViewed(Request $request): JsonResponse
{
    try {
        $user = $request->attributes->get('auth_user');

        if (!$user || empty($user['id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $userId = (int) $user['id'];

        $perPage = min(
            max((int) $request->query('per_page', 12), 1),
            50
        );

        $latestViews = DB::table('property_views')
            ->where('user_id', $userId)
            ->select([
                'property_id',
                DB::raw(
                    'MAX(viewed_at) as last_viewed_at'
                ),
            ])
            ->groupBy('property_id');

        $properties = DB::table('properties as p')
            ->joinSub(
                $latestViews,
                'pv',
                function ($join) {
                    $join->on(
                        'pv.property_id',
                        '=',
                        'p.id'
                    );
                }
            )
            ->leftJoin(
                'property_types as pt',
                'pt.id',
                '=',
                'p.type_id'
            )
            ->leftJoin(
                'property_locations as pl',
                'pl.property_id',
                '=',
                'p.id'
            )
            ->whereNull('p.deleted_at')
            ->where('p.moderation_status', 'approved')
            ->select([
                'p.id',
                'p.title',
                'p.slug',
                'p.price',
                'p.currency',
                'p.bedrooms',
                'p.bathrooms',
                'p.area_sqft',
                'p.property_condition',
                'p.action_type',

                'pt.name as type_name',

                'pl.address_line_1',
                'pl.building_name',
                'pl.latitude',
                'pl.longitude',

                'pv.last_viewed_at',
            ])
            ->orderByDesc('pv.last_viewed_at')
            ->paginate($perPage);

        $propertyIds = $properties
            ->getCollection()
            ->pluck('id')
            ->values()
            ->all();

        $images = collect();

        if (!empty($propertyIds)) {
            $images = DB::table('property_images')
                ->whereIn('property_id', $propertyIds)
                ->orderByDesc('is_primary')
                ->orderBy('display_order')
                ->get()
                ->groupBy('property_id');
        }

        $properties->setCollection(
            $properties
                ->getCollection()
                ->map(function ($property) use ($images) {
                    $propertyImages = $images->get(
                        $property->id,
                        collect()
                    );

                    return [
                        'id' => (int) $property->id,
                        'title' => $property->title,
                        'slug' => $property->slug,

                        'price' => (float) $property->price,
                        'currency' => $property->currency,

                        'type' => $property->type_name,

                        'bedrooms' => (int) $property->bedrooms,
                        'bathrooms' => (int) $property->bathrooms,
                        'area_sqft' => (float) $property->area_sqft,

                        'property_condition' =>
                            $property->property_condition,

                        'action_type' =>
                            $property->action_type,

                        'location' => [
                            'address' =>
                                $property->address_line_1,

                            'building_name' =>
                                $property->building_name,

                            'latitude' =>
                                $property->latitude !== null
                                    ? (float) $property->latitude
                                    : null,

                            'longitude' =>
                                $property->longitude !== null
                                    ? (float) $property->longitude
                                    : null,
                        ],

                        'primary_image' =>
                            optional(
                                $propertyImages->first()
                            )->image_url,

                        'last_viewed_at' =>
                            $property->last_viewed_at,
                    ];
                })
                ->values()
        );

        return response()->json([
            'success' => true,

            'data' => $properties->items(),

            'pagination' => [
                'current_page' =>
                    $properties->currentPage(),

                'last_page' =>
                    $properties->lastPage(),

                'per_page' =>
                    $properties->perPage(),

                'total' =>
                    $properties->total(),

                'from' =>
                    $properties->firstItem(),

                'to' =>
                    $properties->lastItem(),

                'has_more' =>
                    $properties->hasMorePages(),
            ],
        ]);

    } catch (Throwable $e) {
        report($e);

        return response()->json([
            'success' => false,
            'message' =>
                'Failed to load recently viewed properties',

            'details' =>
                app()->environment('local')
                    ? $e->getMessage()
                    : null,
        ], 500);
    }
}


/*
|--------------------------------------------------------------------------
| View All Search Alerts
|--------------------------------------------------------------------------
*/

public function profileSearchAlerts(
    Request $request
): JsonResponse {
    try {
        $user = $request->attributes->get('auth_user');

        if (!$user || empty($user['id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $userId = (int) $user['id'];

        $perPage = min(
            max((int) $request->query('per_page', 12), 1),
            50
        );

        $alerts = DB::table('saved_searches')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $alerts->setCollection(
            $alerts
                ->getCollection()
                ->map(function ($alert) {
                    return [
                        'id' =>
                            (int) $alert->id,

                        'name' =>
                            $alert->search_name,

                        'filters' =>
                            $this->decodeJson(
                                $alert->query_parameters
                            ),

                        'is_active' =>
                            (bool) $alert->is_active,

                        'created_at' =>
                            $alert->created_at,

                        'updated_at' =>
                            $alert->updated_at,
                    ];
                })
                ->values()
        );

        return response()->json([
            'success' => true,

            'data' => $alerts->items(),

            'pagination' => [
                'current_page' =>
                    $alerts->currentPage(),

                'last_page' =>
                    $alerts->lastPage(),

                'per_page' =>
                    $alerts->perPage(),

                'total' =>
                    $alerts->total(),

                'from' =>
                    $alerts->firstItem(),

                'to' =>
                    $alerts->lastItem(),

                'has_more' =>
                    $alerts->hasMorePages(),
            ],
        ]);

    } catch (Throwable $e) {
        report($e);

        return response()->json([
            'success' => false,
            'message' =>
                'Failed to load search alerts',

            'details' =>
                app()->environment('local')
                    ? $e->getMessage()
                    : null,
        ], 500);
    }
}


/*
|--------------------------------------------------------------------------
| View All Inquiries
|--------------------------------------------------------------------------
*/

public function profileInquiries(
    Request $request
): JsonResponse {
    try {
        $user = $request->attributes->get('auth_user');

        if (!$user || empty($user['id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $userId = (int) $user['id'];

        $perPage = min(
            max((int) $request->query('per_page', 12), 1),
            50
        );

        $inquiries = DB::table('property_leads as pl')
            ->join(
                'properties as p',
                'p.id',
                '=',
                'pl.property_id'
            )
            ->where('pl.user_id', $userId)
            ->whereNull('p.deleted_at')
            ->select([
                'pl.id',
                'pl.property_id',
                'pl.message',
                'pl.status',
                'pl.created_at',

                'p.title as property_title',
                'p.slug as property_slug',
                'p.price as property_price',
                'p.currency as property_currency',
            ])
            ->orderByDesc('pl.created_at')
            ->paginate($perPage);

        $propertyIds = $inquiries
            ->getCollection()
            ->pluck('property_id')
            ->unique()
            ->values()
            ->all();

        $images = collect();

        if (!empty($propertyIds)) {
            $images = DB::table('property_images')
                ->whereIn('property_id', $propertyIds)
                ->orderByDesc('is_primary')
                ->orderBy('display_order')
                ->get()
                ->groupBy('property_id');
        }

        $inquiries->setCollection(
            $inquiries
                ->getCollection()
                ->map(function ($inquiry) use ($images) {
                    $propertyImages = $images->get(
                        $inquiry->property_id,
                        collect()
                    );

                    return [
                        'id' =>
                            (int) $inquiry->id,

                        'property' => [
                            'id' =>
                                (int) $inquiry->property_id,

                            'title' =>
                                $inquiry->property_title,

                            'slug' =>
                                $inquiry->property_slug,

                            'price' =>
                                (float) $inquiry->property_price,

                            'currency' =>
                                $inquiry->property_currency,

                            'image' =>
                                optional(
                                    $propertyImages->first()
                                )->image_url,
                        ],

                        'message' =>
                            $inquiry->message,

                        'status' =>
                            $inquiry->status,

                        'created_at' =>
                            $inquiry->created_at,
                    ];
                })
                ->values()
        );

        return response()->json([
            'success' => true,

            'data' => $inquiries->items(),

            'pagination' => [
                'current_page' =>
                    $inquiries->currentPage(),

                'last_page' =>
                    $inquiries->lastPage(),

                'per_page' =>
                    $inquiries->perPage(),

                'total' =>
                    $inquiries->total(),

                'from' =>
                    $inquiries->firstItem(),

                'to' =>
                    $inquiries->lastItem(),

                'has_more' =>
                    $inquiries->hasMorePages(),
            ],
        ]);

    } catch (Throwable $e) {
        report($e);

        return response()->json([
            'success' => false,
            'message' =>
                'Failed to load inquiries',

            'details' =>
                app()->environment('local')
                    ? $e->getMessage()
                    : null,
        ], 500);
    }
}
    /*
    |--------------------------------------------------------------------------
    | Update Profile
    |--------------------------------------------------------------------------
    */

    public function update(
        Request $request
    ): JsonResponse {
        $user = $request
            ->attributes
            ->get('auth_user');

        if (
            !$user ||
            empty($user['id'])
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $currentUser = DB::table('users')
            ->where(
                'id',
                $user['id']
            )
            ->select(
                'first_name',
                'last_name',
                'email',
                'phone'
            )
            ->first();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        $currentProfile = DB::table(
            'user_profiles'
        )
            ->where(
                'user_id',
                $user['id']
            )
            ->first();

        /*
        |--------------------------------------------------------------------------
        | User Data
        |--------------------------------------------------------------------------
        */

        $firstName =
            $request->exists('first_name')
                ? trim(
                    (string)
                    $request->input('first_name')
                )
                : $currentUser->first_name;

        $lastName =
            $request->exists('last_name')
                ? trim(
                    (string)
                    $request->input('last_name')
                )
                : $currentUser->last_name;

        $email =
            $request->exists('email')
                ? strtolower(
                    trim(
                        (string)
                        $request->input('email')
                    )
                )
                : $currentUser->email;

        $phone =
            $request->exists('phone')
                ? trim(
                    (string)
                    $request->input('phone')
                )
                : $currentUser->phone;

        /*
        |--------------------------------------------------------------------------
        | Profile Data
        |--------------------------------------------------------------------------
        */

        $bio =
            $request->exists('bio')
                ? $request->input('bio')
                : (
                    $currentProfile->bio
                    ?? null
                );

        $city =
            $request->exists('city')
                ? trim(
                    (string)
                    $request->input('city')
                )
                : (
                    $currentProfile->city
                    ?? null
                );

        $country =
            $request->exists('country')
                ? trim(
                    (string)
                    $request->input('country')
                )
                : (
                    $currentProfile->country
                    ?? null
                );

        $preferredLanguage =
            $request->exists(
                'preferred_language'
            )
                ? trim(
                    (string)
                    $request->input(
                        'preferred_language'
                    )
                )
                : (
                    $currentProfile
                        ->preferred_language
                    ?? 'en'
                );

        $currency =
            $request->exists('currency')
                ? strtoupper(
                    trim(
                        (string)
                        $request->input(
                            'currency'
                        )
                    )
                )
                : (
                    $currentProfile->currency
                    ?? 'AED'
                );

        $nationality =
            $request->exists('nationality')
                ? trim(
                    (string)
                    $request->input(
                        'nationality'
                    )
                )
                : (
                    $currentProfile
                        ->nationality
                    ?? null
                );

        $dateOfBirth =
            $request->exists(
                'date_of_birth'
            )
                ? $request->input(
                    'date_of_birth'
                )
                : (
                    $currentProfile
                        ->date_of_birth
                    ?? null
                );

        $gender =
            $request->exists('gender')
                ? $request->input(
                    'gender'
                )
                : (
                    $currentProfile->gender
                    ?? null
                );

        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        if ($firstName === '') {
            return response()->json([
                'success' => false,
                'message' =>
                    'First name is required',
            ], 422);
        }

        if ($lastName === '') {
            return response()->json([
                'success' => false,
                'message' =>
                    'Last name is required',
            ], 422);
        }

        if (
            $email === '' ||
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'A valid email address is required',
            ], 422);
        }

        $emailExists =
            DB::table('users')
                ->where(
                    'email',
                    $email
                )
                ->where(
                    'id',
                    '<>',
                    $user['id']
                )
                ->exists();

        if ($emailExists) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Email already registered',
            ], 409);
        }

        if (
            $phone !== null &&
            $phone !== ''
        ) {
            $phoneExists =
                DB::table('users')
                    ->where(
                        'phone',
                        $phone
                    )
                    ->where(
                        'id',
                        '<>',
                        $user['id']
                    )
                    ->exists();

            if ($phoneExists) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Phone already registered',
                ], 409);
            }
        }

        if (
            $bio !== null &&
            mb_strlen(
                (string) $bio
            ) > 300
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Bio may not be greater than 300 characters',
            ], 422);
        }

        $allowedGenders = [
            'male',
            'female',
            'other',
            'prefer_not_to_say',
        ];

        if (
            $gender !== null &&
            $gender !== '' &&
            !in_array(
                $gender,
                $allowedGenders,
                true
            )
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Invalid gender value',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Update Database
        |--------------------------------------------------------------------------
        */

        DB::transaction(
            function () use (
                $user,
                $firstName,
                $lastName,
                $email,
                $phone,
                $bio,
                $city,
                $country,
                $preferredLanguage,
                $currency,
                $nationality,
                $dateOfBirth,
                $gender
            ) {
                DB::table('users')
                    ->where(
                        'id',
                        $user['id']
                    )
                    ->update([
                        'first_name' =>
                            $firstName,

                        'last_name' =>
                            $lastName,

                        'email' =>
                            $email,

                        'phone' =>
                            (
                                $phone !== null &&
                                $phone !== ''
                            )
                                ? $phone
                                : null,

                        'updated_at' =>
                            now(),
                    ]);

                $profileData = [
                    'bio' =>
                        $bio,

                    'city' =>
                        (
                            $city !== null &&
                            $city !== ''
                        )
                            ? $city
                            : null,

                    'country' =>
                        (
                            $country !== null &&
                            $country !== ''
                        )
                            ? $country
                            : null,

                    'preferred_language' =>
                        $preferredLanguage,

                    'currency' =>
                        $currency,

                    'nationality' =>
                        (
                            $nationality !== null &&
                            $nationality !== ''
                        )
                            ? $nationality
                            : null,

                    'date_of_birth' =>
                        $dateOfBirth,

                    'gender' =>
                        $gender !== ''
                            ? $gender
                            : null,

                    'updated_at' =>
                        now(),
                ];

                $profileExists =
                    DB::table(
                        'user_profiles'
                    )
                        ->where(
                            'user_id',
                            $user['id']
                        )
                        ->exists();

                if ($profileExists) {
                    DB::table(
                        'user_profiles'
                    )
                        ->where(
                            'user_id',
                            $user['id']
                        )
                        ->update(
                            $profileData
                        );
                } else {
                    $profileData['user_id'] =
                        $user['id'];

                    $profileData['created_at'] =
                        now();

                    DB::table(
                        'user_profiles'
                    )->insert(
                        $profileData
                    );
                }
            }
        );

        return response()->json([
            'success' => true,
            'message' =>
                'Profile updated successfully',

            'profile' =>
                $this->getProfile(
                    (int) $user['id']
                ),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Update User Location
    |--------------------------------------------------------------------------
    */

    public function updateLocation(
        Request $request
    ): JsonResponse {
        $user = $request
            ->attributes
            ->get('auth_user');

        if (
            !$user ||
            empty($user['id'])
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $validator = validator(
            $request->all(),
            [
                'latitude' => [
                    'required',
                    'numeric',
                    'between:-90,90',
                ],

                'longitude' => [
                    'required',
                    'numeric',
                    'between:-180,180',
                ],
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Invalid location data',

                'errors' =>
                    $validator->errors(),
            ], 422);
        }

        try {
            $latitude =
                (float)
                $request->input(
                    'latitude'
                );

            $longitude =
                (float)
                $request->input(
                    'longitude'
                );

            $profileExists =
                DB::table(
                    'user_profiles'
                )
                    ->where(
                        'user_id',
                        $user['id']
                    )
                    ->exists();

            if ($profileExists) {
                DB::table(
                    'user_profiles'
                )
                    ->where(
                        'user_id',
                        $user['id']
                    )
                    ->update([
                        'latitude' =>
                            $latitude,

                        'longitude' =>
                            $longitude,

                        'updated_at' =>
                            now(),
                    ]);
            } else {
                DB::table(
                    'user_profiles'
                )
                    ->insert([
                        'user_id' =>
                            $user['id'],

                        'latitude' =>
                            $latitude,

                        'longitude' =>
                            $longitude,

                        'preferred_language' =>
                            'en',

                        'currency' =>
                            'AED',

                        'created_at' =>
                            now(),

                        'updated_at' =>
                            now(),
                    ]);
            }

            return response()->json([
                'success' => true,

                'message' =>
                    'Location updated successfully',

                'location' => [
                    'latitude' =>
                        $latitude,

                    'longitude' =>
                        $longitude,
                ],
            ]);

        } catch (Throwable $e) {
            Log::error(
                'Failed to update user location',
                [
                    'user_id' =>
                        $user['id'],

                    'error' =>
                        $e->getMessage(),
                ]
            );

            report($e);

            return response()->json([
                'success' => false,
                'message' =>
                    'Failed to update location',
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Upload Profile Avatar
    |--------------------------------------------------------------------------
    */

    public function updateAvatar(
        Request $request
    ): JsonResponse {
        $user = $request
            ->attributes
            ->get('auth_user');

        if (
            !$user ||
            empty($user['id'])
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        if (
            !$request->hasFile('avatar')
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Profile photo is required',
            ], 422);
        }

        $validator = validator(
            $request->all(),
            [
                'avatar' => [
                    'required',
                    'file',
                    'image',
                    'mimes:jpg,jpeg,png',
                    'max:5120',
                ],
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,

                'message' =>
                    'Invalid profile photo',

                'errors' =>
                    $validator->errors(),
            ], 422);
        }

        try {
            $cloudinaryUrl =
                env('CLOUDINARY_URL');

            if (!$cloudinaryUrl) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Cloudinary is not configured',
                ], 500);
            }

            $cloudinary =
                new Cloudinary(
                    $cloudinaryUrl
                );

            $uploadResult =
                $cloudinary
                    ->uploadApi()
                    ->upload(
                        $request
                            ->file('avatar')
                            ->getRealPath(),
                        [
                            'folder' =>
                                'vibelocate/profile-images',

                            'resource_type' =>
                                'image',
                        ]
                    );

            $avatarUrl =
                $uploadResult[
                    'secure_url'
                ] ?? null;

            if (!$avatarUrl) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Profile photo upload failed',
                ], 500);
            }

            $profileExists =
                DB::table(
                    'user_profiles'
                )
                    ->where(
                        'user_id',
                        $user['id']
                    )
                    ->exists();

            if ($profileExists) {
                DB::table(
                    'user_profiles'
                )
                    ->where(
                        'user_id',
                        $user['id']
                    )
                    ->update([
                        'avatar_url' =>
                            $avatarUrl,

                        'updated_at' =>
                            now(),
                    ]);
            } else {
                DB::table(
                    'user_profiles'
                )
                    ->insert([
                        'user_id' =>
                            $user['id'],

                        'avatar_url' =>
                            $avatarUrl,

                        'preferred_language' =>
                            'en',

                        'currency' =>
                            'AED',

                        'created_at' =>
                            now(),

                        'updated_at' =>
                            now(),
                    ]);
            }

            return response()->json([
                'success' => true,

                'message' =>
                    'Profile photo updated successfully',

                'avatar_url' =>
                    $avatarUrl,

                'profile' =>
                    $this->getProfile(
                        (int) $user['id']
                    ),
            ]);

        } catch (Throwable $e) {
            Log::error(
                'Cloudinary profile photo upload failed',
                [
                    'user_id' =>
                        $user['id'],

                    'error' =>
                        $e->getMessage(),
                ]
            );

            report($e);

            return response()->json([
                'success' => false,
                'message' =>
                    'Profile photo upload failed',
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Update Preferences
    |--------------------------------------------------------------------------
    */

    public function updatePreferences(
        Request $request
    ): JsonResponse {
        $user = $request
            ->attributes
            ->get('auth_user');

        if (
            !$user ||
            empty($user['id'])
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $userId =
            (int) $user['id'];

        $validator = validator(
            $request->all(),
            [
                'preferred_area' =>
                    'nullable|string|max:150',

                'property_types' =>
                    'nullable|array',

                'property_types.*' =>
                    'integer|distinct|exists:property_types,id',

                'min_price' =>
                    'nullable|numeric|min:0',

                'max_price' =>
                    'nullable|numeric|min:0',

                'min_bedrooms' =>
                    'nullable|integer|min:0|max:100',

                'max_bedrooms' =>
                    'nullable|integer|min:0|max:100',

                'lifestyle_preferences' =>
                    'nullable|array',

                'lifestyle_preferences.*' =>
                    'string|max:100',

                'email_notifications' =>
                    'nullable|boolean',

                'browser_notifications' =>
                    'nullable|boolean',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,

                'message' =>
                    'Invalid preference data',

                'errors' =>
                    $validator->errors(),
            ], 422);
        }

        if (
            $request->filled(
                'min_price'
            ) &&
            $request->filled(
                'max_price'
            ) &&
            (float)
            $request->input(
                'min_price'
            )
            >
            (float)
            $request->input(
                'max_price'
            )
        ) {
            return response()->json([
                'success' => false,

                'message' =>
                    'Minimum price cannot be greater than maximum price',
            ], 422);
        }

        if (
            $request->filled(
                'min_bedrooms'
            ) &&
            $request->filled(
                'max_bedrooms'
            ) &&
            (int)
            $request->input(
                'min_bedrooms'
            )
            >
            (int)
            $request->input(
                'max_bedrooms'
            )
        ) {
            return response()->json([
                'success' => false,

                'message' =>
                    'Minimum bedrooms cannot be greater than maximum bedrooms',
            ], 422);
        }

        try {
            $current =
                DB::table(
                    'user_preferences'
                )
                    ->where(
                        'user_id',
                        $userId
                    )
                    ->first();

            $data = [
                'preferred_area' =>
                    $request->exists(
                        'preferred_area'
                    )
                        ? $request->input(
                            'preferred_area'
                        )
                        : (
                            $current
                                ->preferred_area
                            ?? null
                        ),

                'property_types' =>
                    $request->exists(
                        'property_types'
                    )
                        ? json_encode(
                            $request->input(
                                'property_types',
                                []
                            ),
                            JSON_UNESCAPED_UNICODE
                        )
                        : (
                            $current
                                ->property_types
                            ?? null
                        ),

                'min_price' =>
                    $request->exists(
                        'min_price'
                    )
                        ? $request->input(
                            'min_price'
                        )
                        : (
                            $current
                                ->min_price
                            ?? null
                        ),

                'max_price' =>
                    $request->exists(
                        'max_price'
                    )
                        ? $request->input(
                            'max_price'
                        )
                        : (
                            $current
                                ->max_price
                            ?? null
                        ),

                'min_bedrooms' =>
                    $request->exists(
                        'min_bedrooms'
                    )
                        ? $request->input(
                            'min_bedrooms'
                        )
                        : (
                            $current
                                ->min_bedrooms
                            ?? null
                        ),

                'max_bedrooms' =>
                    $request->exists(
                        'max_bedrooms'
                    )
                        ? $request->input(
                            'max_bedrooms'
                        )
                        : (
                            $current
                                ->max_bedrooms
                            ?? null
                        ),

                'lifestyle_preferences' =>
                    $request->exists(
                        'lifestyle_preferences'
                    )
                        ? json_encode(
                            $request->input(
                                'lifestyle_preferences',
                                []
                            ),
                            JSON_UNESCAPED_UNICODE
                        )
                        : (
                            $current
                                ->lifestyle_preferences
                            ?? null
                        ),

                'email_notifications' =>
                    $request->exists(
                        'email_notifications'
                    )
                        ? (
                            $request->boolean(
                                'email_notifications'
                            )
                                ? 1
                                : 0
                        )
                        : (
                            $current
                                ->email_notifications
                            ?? 1
                        ),

                'browser_notifications' =>
                    $request->exists(
                        'browser_notifications'
                    )
                        ? (
                            $request->boolean(
                                'browser_notifications'
                            )
                                ? 1
                                : 0
                        )
                        : (
                            $current
                                ->browser_notifications
                            ?? 1
                        ),

                'updated_at' =>
                    now(),
            ];

            if ($current) {
                DB::table(
                    'user_preferences'
                )
                    ->where(
                        'user_id',
                        $userId
                    )
                    ->update(
                        $data
                    );
            } else {
                $data['user_id'] =
                    $userId;

                $data['created_at'] =
                    now();

                DB::table(
                    'user_preferences'
                )->insert(
                    $data
                );
            }

            return response()->json([
                'success' => true,

                'message' =>
                    'Preferences updated successfully',
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,

                'message' =>
                    'Failed to update preferences',

                'details' =>
                    app()->environment(
                        'local'
                    )
                        ? $e->getMessage()
                        : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Profile Helper
    |--------------------------------------------------------------------------
    */

    private function getProfile(
        int $userId
    ) {
        return DB::table(
            'users as u'
        )
            ->leftJoin(
                'user_profiles as up',
                'up.user_id',
                '=',
                'u.id'
            )
            ->where(
                'u.id',
                $userId
            )
            ->whereNull(
                'u.deleted_at'
            )
            ->select(
                'u.id',
                'u.first_name',
                'u.last_name',
                'u.email',
                'u.phone',
                'u.status',
                'u.email_verified_at',
                'u.created_at',

                'up.avatar_url',
                'up.bio',
                'up.city',
                'up.country',

                'up.latitude',
                'up.longitude',

                'up.preferred_language',
                'up.currency',
                'up.nationality',
                'up.date_of_birth',
                'up.gender'
            )
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Primary Role
    |--------------------------------------------------------------------------
    */

    private function getPrimaryRole(
        array $roles
    ): string {
        $priority = [
            'super-admin',
            'admin',
            'agent',
            'owner',
            'tenant',
            'inspector',
        ];

        foreach ($priority as $role) {
            if (
                in_array(
                    $role,
                    $roles,
                    true
                )
            ) {
                return $role;
            }
        }

        return !empty($roles)
            ? (string) $roles[0]
            : 'user';
    }

    /*
    |--------------------------------------------------------------------------
    | Decode JSON
    |--------------------------------------------------------------------------
    */

    private function decodeJson(
        mixed $value
    ): array {
        if (
            $value === null ||
            $value === ''
        ) {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        $decoded =
            json_decode(
                (string) $value,
                true
            );

        return is_array($decoded)
            ? $decoded
            : [];
    }

    /*
    |--------------------------------------------------------------------------
    | Format Location
    |--------------------------------------------------------------------------
    */

    private function formatLocation(
        ?string $city,
        ?string $country
    ): ?string {
        $parts = array_filter([
            $city,
            $country,
        ]);

        return !empty($parts)
            ? implode(
                ', ',
                $parts
            )
            : null;
    }
}