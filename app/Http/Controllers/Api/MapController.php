<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class MapController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {

            $validated = $request->validate([
                'latitude' => [
                    'nullable',
                    'numeric',
                    'between:-90,90',
                    'required_with:longitude',
                ],
                'longitude' => [
                    'nullable',
                    'numeric',
                    'between:-180,180',
                    'required_with:latitude',
                ],
            ]);

            $userLatitude = $validated['latitude'] ?? null;
            $userLongitude = $validated['longitude'] ?? null;

            $hasUserLocation =
                $userLatitude !== null &&
                $userLongitude !== null;

            $query = DB::table('properties as p')
                ->join(
                    'property_locations as pl',
                    'pl.property_id',
                    '=',
                    'p.id'
                )
                ->leftJoin(
                    'neighborhoods as n',
                    'n.id',
                    '=',
                    'pl.neighborhood_id'
                )
                ->leftJoin(
                    'property_types as pt',
                    'pt.id',
                    '=',
                    'p.type_id'
                )
                ->leftJoin(
                    'property_categories as pc',
                    'pc.id',
                    '=',
                    'p.category_id'
                )
                ->whereNull('p.deleted_at')
                ->whereNotNull('pl.latitude')
                ->whereNotNull('pl.longitude')
                ->select([
                    'p.id',
                    'p.title',
                    'p.slug',
                    'p.price',
                    'p.currency',

                    'p.bedrooms',
                    'p.bathrooms',
                    'p.area_sqft',

                    'p.type_id',
                    'pt.name as property_type',

                    'p.category_id',
                    'pc.name as purpose',

                    'pl.address_line_1',
                    'pl.latitude',
                    'pl.longitude',
                    'pl.neighborhood_id',

                    'n.name as neighborhood',
                ]);

            /*
            |--------------------------------------------------------------------------
            | Calculate distance from user's current location
            |--------------------------------------------------------------------------
            */

            if ($hasUserLocation) {

                $distanceSql = '
                    (
                        6371 * ACOS(
                            LEAST(
                                1,
                                GREATEST(
                                    -1,
                                    COS(RADIANS(?))
                                    * COS(RADIANS(pl.latitude))
                                    * COS(
                                        RADIANS(pl.longitude)
                                        - RADIANS(?)
                                    )
                                    + SIN(RADIANS(?))
                                    * SIN(RADIANS(pl.latitude))
                                )
                            )
                        )
                    )
                ';

                $query->selectRaw(
                    $distanceSql . ' AS distance_km',
                    [
                        $userLatitude,
                        $userLongitude,
                        $userLatitude,
                    ]
                );

                $query->orderBy('distance_km');

            } else {

                $query->orderByDesc('p.id');
            }

            $properties = $query->get();

            /*
            |--------------------------------------------------------------------------
            | Property Images
            |--------------------------------------------------------------------------
            */

            $propertyIds = $properties
                ->pluck('id')
                ->all();

            $images = collect();

            if (!empty($propertyIds)) {

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
            }

            /*
            |--------------------------------------------------------------------------
            | Prepare Response
            |--------------------------------------------------------------------------
            */

            $data = $properties->map(
                function ($property) use ($images, $hasUserLocation) {

                    $propertyImages = $images->get(
                        $property->id,
                        collect()
                    );

                    $primaryImage =
                        $propertyImages->firstWhere('is_primary', 1)
                        ?? $propertyImages->first();

                    $item = [
                        'id' => $property->id,

                        'title' => $property->title,
                        'slug' => $property->slug,

                        'price' => $property->price,
                        'currency' => $property->currency,

                        'category_id' => $property->category_id,
                        'purpose' => $property->purpose,

                        'bedrooms' => $property->bedrooms,
                        'bathrooms' => $property->bathrooms,
                        'area_sqft' => $property->area_sqft,

                        'type_id' => $property->type_id,
                        'property_type' => $property->property_type,

                        'latitude' => $property->latitude,
                        'longitude' => $property->longitude,

                        'address' => $property->address_line_1,

                        'neighborhood_id' =>
                            $property->neighborhood_id,

                        'neighborhood' =>
                            $property->neighborhood,

                        'primary_image' => $primaryImage
                            ? [
                                'id' => $primaryImage->id,
                                'image_url' =>
                                    $primaryImage->image_url,
                            ]
                            : null,
                    ];

                    if ($hasUserLocation) {

                        $item['distance_km'] =
                            $property->distance_km !== null
                                ? round(
                                    (float) $property->distance_km,
                                    2
                                )
                                : null;
                    }

                    return $item;
                }
            );

            /*
            |--------------------------------------------------------------------------
            | Response
            |--------------------------------------------------------------------------
            */

            $response = [
                'success' => true,
                'total' => $data->count(),
            ];

            if ($hasUserLocation) {

                $response['user_location'] = [
                    'latitude' => (float) $userLatitude,
                    'longitude' => (float) $userLongitude,
                ];
            }

            $response['data'] = $data;

            return response()->json($response);

        } catch (Throwable $e) {

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load map properties',
            ], 500);
        }
    }
}