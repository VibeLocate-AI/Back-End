<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class HomeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {

            /*
            |--------------------------------------------------------------------------
            | User Location
            |--------------------------------------------------------------------------
            */

            $request->validate([
                'latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            ]);

            $userLatitude = $request->filled('latitude')
                ? (float) $request->input('latitude')
                : null;

            $userLongitude = $request->filled('longitude')
                ? (float) $request->input('longitude')
                : null;

            $hasUserLocation =
                $userLatitude !== null
                && $userLongitude !== null;


            /*
            |--------------------------------------------------------------------------
            | Properties
            |--------------------------------------------------------------------------
            */

            $properties = DB::table('properties as p')
                ->whereNull('p.deleted_at')
                ->where('p.slug', 'like', 'demo-dubai-%')
                ->select([
                    'p.id',
                    'p.type_id',
                    'p.category_id',
                    'p.status_id',
                    'p.is_featured',
                    'p.title',
                    'p.slug',
                    'p.description',
                    'p.price',
                    'p.currency',
                    'p.rent_frequency',
                    'p.area_sqft',
                    'p.bedrooms',
                    'p.bathrooms',
                    'p.is_furnished',
                    'p.availability_date',
                    'p.listing_date',
                ])
                ->orderBy('p.id')
                ->limit(100)
                ->get();

            $propertyIds = $properties
                ->pluck('id')
                ->values()
                ->all();

            $images = collect();
            $locations = collect();
            $features = collect();

            if (!empty($propertyIds)) {

                /*
                |--------------------------------------------------------------------------
                | Images
                |--------------------------------------------------------------------------
                */

                $images = DB::table('property_images')
                    ->whereIn('property_id', $propertyIds)
                    ->select([
                        'id',
                        'property_id',
                        'image_url',
                        'is_primary',
                        'display_order',
                    ])
                    ->orderByDesc('is_primary')
                    ->orderBy('display_order')
                    ->get()
                    ->groupBy('property_id');


                /*
                |--------------------------------------------------------------------------
                | Locations
                |--------------------------------------------------------------------------
                */

                $locations = DB::table('property_locations')
                    ->whereIn('property_id', $propertyIds)
                    ->select([
                        'id',
                        'property_id',
                        'address_line_1',
                        'address_line_2',
                        'building_name',
                        'latitude',
                        'longitude',
                        'neighborhood_id',
                        'street_id',
                    ])
                    ->get()
                    ->keyBy('property_id');


                /*
                |--------------------------------------------------------------------------
                | Features
                |--------------------------------------------------------------------------
                */

                $features = DB::table(
                    'property_feature_values as pfv'
                )
                    ->join(
                        'property_features as pf',
                        'pf.id',
                        '=',
                        'pfv.feature_id'
                    )
                    ->whereIn(
                        'pfv.property_id',
                        $propertyIds
                    )
                    ->select([
                        'pfv.property_id',
                        'pf.id',
                        'pf.name',
                        'pf.category',
                        'pfv.feature_value',
                    ])
                    ->orderBy('pf.id')
                    ->get()
                    ->groupBy('property_id');
            }


            /*
            |--------------------------------------------------------------------------
            | Prepare Home Properties
            |--------------------------------------------------------------------------
            */

            $homeProperties = $properties->map(
                function ($property) use (
                    $images,
                    $locations,
                    $features
                ) {

                    $propertyId = $property->id;

                    $propertyImages = $images
                        ->get(
                            $propertyId,
                            collect()
                        )
                        ->values();

                    $property->primary_image =
                        $propertyImages->firstWhere(
                            'is_primary',
                            1
                        )
                        ?? $propertyImages->first();

                    $property->images =
                        $propertyImages;

                    $property->location =
                        $locations->get(
                            $propertyId
                        );

                    $property->features =
                        $features
                            ->get(
                                $propertyId,
                                collect()
                            )
                            ->values();

                    return $property;
                }
            );


            /*
            |--------------------------------------------------------------------------
            | Featured Properties
            |--------------------------------------------------------------------------
            */

            $featuredProperties = $homeProperties
                ->filter(function ($property) {
                    return (int) $property->is_featured === 1;
                })
                ->values();


            /*
            |--------------------------------------------------------------------------
            | Nearby & Recommended
            |--------------------------------------------------------------------------
            */

            if ($hasUserLocation) {

                $recommendedProperties = $homeProperties
                    ->filter(function ($property) {
                        return
                            (int) $property->is_featured === 0
                            && $property->location !== null
                            && $property->location->latitude !== null
                            && $property->location->longitude !== null;
                    })
                    ->map(function ($property) use (
                        $userLatitude,
                        $userLongitude
                    ) {

                        $propertyLatitude =
                            (float) $property->location->latitude;

                        $propertyLongitude =
                            (float) $property->location->longitude;

                        $property->distance_km =
                            $this->calculateDistance(
                                $userLatitude,
                                $userLongitude,
                                $propertyLatitude,
                                $propertyLongitude
                            );

                        return $property;
                    })
                    ->sortBy('distance_km')
                    ->take(12)
                    ->values();

                $recommendationMode = 'nearby';

            } else {

                $recommendedProperties = $homeProperties
                    ->filter(function ($property) {
                        return (int) $property->is_featured === 0;
                    })
                    ->sortByDesc(function ($property) {
                        return $property->listing_date;
                    })
                    ->take(12)
                    ->values();

                $recommendationMode = 'recommended';
            }


           /*
|--------------------------------------------------------------------------
| Popular Areas
|--------------------------------------------------------------------------
*/

$popularAreas = DB::table('neighborhoods as n')
    ->join(
        'property_locations as pl',
        'pl.neighborhood_id',
        '=',
        'n.id'
    )
    ->join(
        'properties as p',
        'p.id',
        '=',
        'pl.property_id'
    )
    ->whereNull('p.deleted_at')
    ->select([
        'n.id',
        'n.name',
        DB::raw(
            'COUNT(DISTINCT p.id) as properties_count'
        ),
    ])
    ->groupBy(
        'n.id',
        'n.name'
    )
    ->orderByDesc('properties_count')
    ->limit(4)
    ->get();

$popularAreaIds = $popularAreas
    ->pluck('id')
    ->values()
    ->all();

$popularAreaImages = collect();

if (!empty($popularAreaIds)) {

    $popularAreaImages = DB::table('property_locations as pl')
        ->join(
            'properties as p',
            'p.id',
            '=',
            'pl.property_id'
        )
        ->join(
            'property_images as pi',
            'pi.property_id',
            '=',
            'p.id'
        )
        ->whereIn(
            'pl.neighborhood_id',
            $popularAreaIds
        )
        ->whereNull('p.deleted_at')
        ->where('pi.is_primary', 1)
        ->select([
            'pl.neighborhood_id',
            'p.id as property_id',
            'pi.image_url',
        ])
        ->orderBy('p.id')
        ->get()
        ->groupBy('neighborhood_id');
}

$popularAreas = $popularAreas
    ->map(function ($area) use ($popularAreaImages) {

        $areaImage = $popularAreaImages
            ->get(
                $area->id,
                collect()
            )
            ->first();

        $area->image_url =
            $areaImage?->image_url;

        return $area;
    });


            /*
            |--------------------------------------------------------------------------
            | Top Real Estate Agent
            |--------------------------------------------------------------------------
            */

            $topAgentRaw = DB::table('agency_agents as aa')
                ->join(
                    'users as u',
                    'u.id',
                    '=',
                    'aa.user_id'
                )
                ->leftJoin(
                    'user_profiles as up',
                    'up.user_id',
                    '=',
                    'u.id'
                )
                ->join(
                    'agencies as a',
                    'a.id',
                    '=',
                    'aa.agency_id'
                )
                ->whereNull('u.deleted_at')
                ->select([
                    'u.id as user_id',
                    'u.first_name',
                    'u.last_name',
                    'u.phone',
                    'up.avatar_url',
                    'aa.is_manager',
                    'a.id as agency_id',
                    'a.name as agency_name',
                    'a.logo_url as agency_logo',
                ])
                ->orderByDesc('aa.is_manager')
                ->orderBy('aa.joined_at')
                ->first();

            $topAgent = null;

            if ($topAgentRaw) {

                $topAgent = [
                    'user_id' =>
                        $topAgentRaw->user_id,

                    'name' => trim(
                        $topAgentRaw->first_name
                        . ' '
                        . $topAgentRaw->last_name
                    ),

                    'phone' =>
                        $topAgentRaw->phone,

                    'avatar_url' =>
                        $topAgentRaw->avatar_url,

                    'is_manager' =>
                        (bool) $topAgentRaw->is_manager,

                    'agency' => [
                        'id' =>
                            $topAgentRaw->agency_id,

                        'name' =>
                            $topAgentRaw->agency_name,

                        'logo_url' =>
                            $topAgentRaw->agency_logo,
                    ],
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Property Types
            |--------------------------------------------------------------------------
            */

            $propertyTypes = DB::table('property_types')
                ->select([
                    'id',
                    'name',
                ])
                ->orderBy('id')
                ->get();


            /*
            |--------------------------------------------------------------------------
            | Categories
            |--------------------------------------------------------------------------
            */

            $categories = DB::table('property_categories')
                ->select([
                    'id',
                    'name',
                ])
                ->orderBy('id')
                ->get();


            /*
            |--------------------------------------------------------------------------
            | Testimonials
            |--------------------------------------------------------------------------
            */

            $testimonials = DB::table('reviews as r')
                ->join(
                    'users as u',
                    'u.id',
                    '=',
                    'r.user_id'
                )
                ->where(
                    'r.status',
                    'published'
                )
                ->whereNull('r.deleted_at')
                ->whereNull('u.deleted_at')
                ->select([
                    'r.id',
                    'r.property_id',
                    'r.rating',
                    'r.comment',
                    'r.created_at',
                    'u.id as user_id',
                    'u.first_name',
                    'u.last_name',
                ])
                ->orderByDesc('r.created_at')
                ->limit(3)
                ->get()
                ->map(function ($review) {

                    $review->user_name = trim(
                        $review->first_name
                        . ' '
                        . $review->last_name
                    );

                    unset(
                        $review->first_name,
                        $review->last_name
                    );

                    return $review;
                });


            /*
            |--------------------------------------------------------------------------
            | Stats
            |--------------------------------------------------------------------------
            */

            $totalProperties = DB::table('properties')
                ->whereNull('deleted_at')
                ->count();


            /*
            |--------------------------------------------------------------------------
            | Response
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'success' => true,

                'data' => [

                    'total' =>
                        $homeProperties->count(),

                    'popular_areas' =>
                        $popularAreas,

                    'featured_properties' =>
                        $featuredProperties,

                    'recommendation_mode' =>
                        $recommendationMode,

                    'user_location' =>
                        $hasUserLocation
                            ? [
                                'latitude' =>
                                    $userLatitude,

                                'longitude' =>
                                    $userLongitude,
                            ]
                            : null,

                    'recommended_properties' =>
                        $recommendedProperties,

                    'top_agent' =>
                        $topAgent,

                    'properties' =>
                        $homeProperties,

                    'property_types' =>
                        $propertyTypes,

                    'categories' =>
                        $categories,

                    'testimonials' =>
                        $testimonials,

                    'stats' => [
                        'total_properties' =>
                            $totalProperties,
                    ],
                ],
            ]);

        } catch (Throwable $e) {

            report($e);

            return response()->json([
                'success' => false,
                'message' =>
                    'Failed to load home page data',
            ], 500);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Calculate Distance - Haversine Formula
    |--------------------------------------------------------------------------
    */

    private function calculateDistance(
        float $latitude1,
        float $longitude1,
        float $latitude2,
        float $longitude2
    ): float {

        $earthRadius = 6371;

        $latitudeDifference =
            deg2rad($latitude2 - $latitude1);

        $longitudeDifference =
            deg2rad($longitude2 - $longitude1);

        $a =
            sin($latitudeDifference / 2)
            * sin($latitudeDifference / 2)
            + cos(deg2rad($latitude1))
            * cos(deg2rad($latitude2))
            * sin($longitudeDifference / 2)
            * sin($longitudeDifference / 2);

        $c =
            2 * atan2(
                sqrt($a),
                sqrt(1 - $a)
            );

        return round(
            $earthRadius * $c,
            2
        );
    }
}