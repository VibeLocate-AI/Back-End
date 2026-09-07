<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class PropertyController extends Controller
{
    /**
     * Get property listings.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->query('per_page', 20);
            $perPage = max(1, min($perPage, 100));

            $sort = (string) $request->query('sort', '');
            $hasNearbySort = $sort === 'nearby';

            $latitude = $request->filled('latitude')
                ? (float) $request->query('latitude')
                : null;

            $longitude = $request->filled('longitude')
                ? (float) $request->query('longitude')
                : null;

            if ($hasNearbySort && ($latitude === null || $longitude === null)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Latitude and longitude are required for nearby sorting',
                ], 422);
            }

            if (
                $latitude !== null &&
                ($latitude < -90 || $latitude > 90)
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Latitude must be between -90 and 90',
                ], 422);
            }

            if (
                $longitude !== null &&
                ($longitude < -180 || $longitude > 180)
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Longitude must be between -180 and 180',
                ], 422);
            }

            $query = DB::table('properties as p')
                ->whereNull('p.deleted_at');

            if ($hasNearbySort) {
                $query
                    ->join(
                        'property_locations as nearby_pl',
                        'nearby_pl.property_id',
                        '=',
                        'p.id'
                    )
                    ->whereNotNull('nearby_pl.latitude')
                    ->whereNotNull('nearby_pl.longitude');
            }

            $query->select([
                    'p.id',
                    'p.owner_id',
                    'p.agency_id',
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
                    'p.floor_number',
                    'p.total_floors',
                    'p.year_built',
                    'p.is_furnished',
                    'p.availability_date',
                    'p.listing_date',
                    'p.created_at',
                    'p.updated_at',
                ]);

            if ($hasNearbySort) {
                $distanceSql = '6371 * ACOS(LEAST(1, GREATEST(-1, '
                    . 'COS(RADIANS(?)) * COS(RADIANS(nearby_pl.latitude)) '
                    . '* COS(RADIANS(nearby_pl.longitude) - RADIANS(?)) '
                    . '+ SIN(RADIANS(?)) * SIN(RADIANS(nearby_pl.latitude))'
                    . ')))';

                $query
                    ->selectRaw(
                        $distanceSql . ' AS distance_km',
                        [$latitude, $longitude, $latitude]
                    )
                    ->orderBy('distance_km')
                    ->orderByDesc('p.id');
            } else {
                $query->orderByDesc('p.id');
            }

            /*
            |--------------------------------------------------------------------------
            | Optional Filters
            |--------------------------------------------------------------------------
            */
if ($request->filled('neighborhood_id')) {

    $neighborhoodId = (int) $request->query(
        'neighborhood_id'
    );

    $query->whereExists(function ($subQuery) use (
        $neighborhoodId
    ) {
        $subQuery
            ->select(DB::raw(1))
            ->from('property_locations as pl')
            ->whereColumn(
                'pl.property_id',
                'p.id'
            )
            ->where(
                'pl.neighborhood_id',
                $neighborhoodId
            );
    });
}

            if ($request->filled('is_featured')) {
                $query->where(
                    'p.is_featured',
                    (int) $request->query('is_featured')
                );
            }

            if ($request->filled('type_id')) {
                $query->where(
                    'p.type_id',
                    (int) $request->query('type_id')
                );
            }

            if ($request->filled('category_id')) {
                $query->where(
                    'p.category_id',
                    (int) $request->query('category_id')
                );
            }

            if ($request->filled('status_id')) {
                $query->where(
                    'p.status_id',
                    (int) $request->query('status_id')
                );
            }

            if ($request->filled('bedrooms')) {
                $query->where(
                    'p.bedrooms',
                    (int) $request->query('bedrooms')
                );
            }

            if ($request->filled('bathrooms')) {
                $query->where(
                    'p.bathrooms',
                    (int) $request->query('bathrooms')
                );
            }

            if ($request->filled('min_price')) {
                $query->where(
                    'p.price',
                    '>=',
                    (float) $request->query('min_price')
                );
            }

            if ($request->filled('max_price')) {
                $query->where(
                    'p.price',
                    '<=',
                    (float) $request->query('max_price')
                );
            }

            if ($request->filled('search')) {
                $search = trim(
                    (string) $request->query('search')
                );

                $query->where(function ($q) use ($search) {
                    $q->where(
                        'p.title',
                        'like',
                        '%' . $search . '%'
                    )->orWhere(
                        'p.description',
                        'like',
                        '%' . $search . '%'
                    );
                });
            }

            $properties = $query->paginate($perPage);

            /*
            |--------------------------------------------------------------------------
            | Load related data
            |--------------------------------------------------------------------------
            */

            $items = collect($properties->items());

            $propertyIds = $items
                ->pluck('id')
                ->values()
                ->all();

            $images = collect();
            $locations = collect();
            $features = collect();

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
                    ->orderBy('display_order')
                    ->get()
                    ->groupBy('property_id');

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

            $data = $items->map(function ($property) use (
                $images,
                $locations,
                $features
            ) {
                $propertyId = $property->id;

                $property->images = $images
                    ->get($propertyId, collect())
                    ->values();

                $property->location = $locations
                    ->get($propertyId);

                $property->features = $features
                    ->get($propertyId, collect())
                    ->values();

                if (isset($property->distance_km)) {
                    $property->distance_km = round(
                        (float) $property->distance_km,
                        2
                    );
                }

                return $property;
            });

            return response()->json([
                'success' => true,

                'data' => $data,

                'pagination' => [
                    'current_page' => $properties->currentPage(),
                    'per_page' => $properties->perPage(),
                    'total' => $properties->total(),
                    'last_page' => $properties->lastPage(),
                ],
            ]);

        } catch (Throwable $e) {

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load properties',
            ], 500);
        }
    }


    /**
     * Get one property with its related data.
     */
    public function show(int $id): JsonResponse
    {
        try {

            $property = DB::table('properties as p')
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
                ->leftJoin(
                    'property_status as ps',
                    'ps.id',
                    '=',
                    'p.status_id'
                )
                ->leftJoin(
                    'users as u',
                    'u.id',
                    '=',
                    'p.owner_id'
                )
                ->leftJoin(
                    'agencies as a',
                    'a.id',
                    '=',
                    'p.agency_id'
                )
                ->where('p.id', $id)
                ->whereNull('p.deleted_at')
                ->select([
                    'p.id',
                    'p.owner_id',
                    'p.agency_id',
                    'p.type_id',
                    'pt.name as type_name',
                    'p.category_id',
                    'pc.name as category_name',
                    'p.status_id',
                    'ps.name as status_name',
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
                    'p.floor_number',
                    'p.total_floors',
                    'p.year_built',
                    'p.is_furnished',
                    'p.availability_date',
                    'p.listing_date',
                    'p.created_at',
                    'p.updated_at',
                    'u.first_name as owner_first_name',
                    'u.last_name as owner_last_name',
                    'u.email as owner_email',
                    'u.phone as owner_phone',
                    'a.name as agency_name',
                ])
                ->first();

            if (!$property) {
                return response()->json([
                    'success' => false,
                    'message' => 'Property not found',
                ], 404);
            }

            $property->images = DB::table(
                'property_images'
            )
                ->where('property_id', $property->id)
                ->select([
                    'id',
                    'image_url',
                    'is_primary',
                    'display_order',
                ])
                ->orderBy('display_order')
                ->get();

            $property->location = DB::table(
                'property_locations as pl'
            )
                ->leftJoin(
                    'neighborhoods as n',
                    'n.id',
                    '=',
                    'pl.neighborhood_id'
                )
                ->where('pl.property_id', $property->id)
                ->select([
                    'pl.id',
                    'pl.address_line_1',
                    'pl.address_line_2',
                    'pl.building_name',
                    'pl.latitude',
                    'pl.longitude',
                    'pl.neighborhood_id',
                    'n.name as neighborhood_name',
                    'pl.street_id',
                ])
                ->first();

            $property->features = DB::table(
                'property_feature_values as pfv'
            )
                ->join(
                    'property_features as pf',
                    'pf.id',
                    '=',
                    'pfv.feature_id'
                )
                ->where(
                    'pfv.property_id',
                    $property->id
                )
                ->select([
                    'pf.id',
                    'pf.name',
                    'pf.category',
                    'pfv.feature_value',
                ])
                ->orderBy('pf.id')
                ->get();

            $property->owner = $property->owner_id
                ? [
                    'id' => $property->owner_id,
                    'first_name' => $property->owner_first_name,
                    'last_name' => $property->owner_last_name,
                    'email' => $property->owner_email,
                    'phone' => $property->owner_phone,
                ]
                : null;

            $property->agency = $property->agency_id
                ? [
                    'id' => $property->agency_id,
                    'name' => $property->agency_name,
                ]
                : null;

            unset(
                $property->owner_first_name,
                $property->owner_last_name,
                $property->owner_email,
                $property->owner_phone,
                $property->agency_name
            );

            $property->reviews = DB::table('reviews as r')
                ->join('users as ru', 'ru.id', '=', 'r.user_id')
                ->where('r.property_id', $property->id)
                ->where('r.status', 'published')
                ->whereNull('r.deleted_at')
                ->select([
                    'r.id',
                    'r.rating',
                    'r.comment',
                    'r.created_at',
                    'ru.id as user_id',
                    'ru.first_name',
                    'ru.last_name',
                ])
                ->orderByDesc('r.created_at')
                ->get();

            $property->reviews_summary = [
                'average_rating' => $property->reviews->isNotEmpty()
                    ? round((float) $property->reviews->avg('rating'), 1)
                    : null,
                'total_reviews' => $property->reviews->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $property,
            ]);

        } catch (Throwable $e) {

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load property',
            ], 500);
        }
    }
}