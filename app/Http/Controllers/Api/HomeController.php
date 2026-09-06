<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HomeController extends Controller
{
    /**
     * Get all dynamic data required by the Home page.
     */
    public function index(): JsonResponse
    {
        try {

            /*
            |--------------------------------------------------------------------------
            | Home Properties
            |--------------------------------------------------------------------------
            | Get the 100 imported Dubai properties from database.
            */

            $properties = DB::table('properties as p')
                ->whereNull('p.deleted_at')
                ->where('p.slug', 'like', 'demo-dubai-%')
                ->select([
                    'p.id',
                    'p.type_id',
                    'p.category_id',
                    'p.status_id',
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
                | Property Images
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
                | Property Locations
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
                | Property Features
                |--------------------------------------------------------------------------
                */

                $features = DB::table('property_feature_values as pfv')
                    ->join(
                        'property_features as pf',
                        'pf.id',
                        '=',
                        'pfv.feature_id'
                    )
                    ->whereIn('pfv.property_id', $propertyIds)
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
            | Build Property Data
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
                        ->get($propertyId, collect())
                        ->values();

                    $property->primary_image = $propertyImages
                        ->firstWhere('is_primary', 1)
                        ?? $propertyImages->first();

                    $property->images = $propertyImages;

                    $property->location = $locations
                        ->get($propertyId);

                    $property->features = $features
                        ->get($propertyId, collect())
                        ->values();

                    return $property;
                }
            );

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
            | Property Categories
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
            | Returns only real published reviews.
            */

            $testimonials = DB::table('reviews as r')
                ->join('users as u', 'u.id', '=', 'r.user_id')
                ->where('r.status', 'published')
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
                        $review->first_name . ' ' . $review->last_name
                    );

                    unset(
                        $review->first_name,
                        $review->last_name
                    );

                    return $review;
                });

            /*
            |--------------------------------------------------------------------------
            | Statistics
            |--------------------------------------------------------------------------
            */

            $totalProperties = DB::table('properties')
                ->whereNull('deleted_at')
                ->count();

            $importedProperties = DB::table('properties')
                ->whereNull('deleted_at')
                ->where('slug', 'like', 'demo-dubai-%')
                ->count();

            /*
            |--------------------------------------------------------------------------
            | Response
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'success' => true,

                'data' => [
                    'total' => $homeProperties->count(),
                    'properties' => $homeProperties,
                    'property_types' => $propertyTypes,
                    'categories' => $categories,
                    'testimonials' => $testimonials,

                    'stats' => [
                        'total_properties' => $totalProperties,
                        'imported_properties' => $importedProperties,
                    ],
                ],
            ]);

        } catch (Throwable $e) {

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load home page data',
            ], 500);
        }
    }
}