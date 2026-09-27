<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class MyPropertyController extends Controller
{
    /**
     * Get properties owned by current user.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('auth_user');

            if (!$user || empty($user['id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $perPage = (int) $request->query('per_page', 20);
            $perPage = max(1, min($perPage, 100));

            $query = DB::table('properties as p')
                ->leftJoin('property_types as pt', 'pt.id', '=', 'p.type_id')
                ->leftJoin('property_categories as pc', 'pc.id', '=', 'p.category_id')
                ->where('p.owner_id', (int) $user['id'])
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
                    'p.moderation_status',
                    'p.rejection_reason',
                    'p.reviewed_by',
                    'p.reviewed_at',
                    'p.property_condition',
                    'p.action_type',
                    'p.is_featured',
                    'p.title',
                    'p.slug',
                    'p.description',
                    'p.virtual_tour_url',
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

            if ($request->filled('moderation_status')) {
                $status = $request->query('moderation_status');

                if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
                    $query->where('p.moderation_status', $status);
                }
            }

            if ($request->filled('property_condition')) {
                $condition = $request->query('property_condition');

                if (in_array($condition, ['ready', 'off_plan'], true)) {
                    $query->where('p.property_condition', $condition);
                }
            }

            $properties = $query
                ->orderByDesc('p.id')
                ->paginate($perPage);

            $items = collect($properties->items());

            $propertyIds = $items
                ->pluck('id')
                ->values()
                ->all();

            $images = collect();
            $locations = collect();

            if (!empty($propertyIds)) {
                $images = DB::table('property_images')
                    ->whereIn('property_id', $propertyIds)
                    ->orderBy('display_order')
                    ->get()
                    ->groupBy('property_id');

                $locations = DB::table('property_locations as pl')
                    ->leftJoin(
                        'neighborhoods as n',
                        'n.id',
                        '=',
                        'pl.neighborhood_id'
                    )
                    ->whereIn('pl.property_id', $propertyIds)
                    ->select([
                        'pl.id',
                        'pl.property_id',
                        'pl.address_line_1',
                        'pl.address_line_2',
                        'pl.building_name',
                        'pl.latitude',
                        'pl.longitude',
                        'pl.neighborhood_id',
                        'n.name as neighborhood_name',
                    ])
                    ->get()
                    ->keyBy('property_id');
            }

            $data = $items->map(function ($property) use (
                $images,
                $locations
            ) {
                $property->images = $images
                    ->get($property->id, collect())
                    ->values();

                $property->location = $locations
                    ->get($property->id);

                $property->can_edit = true;
                $property->can_delete = true;

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
                'message' => 'Failed to load user properties',
            ], 500);
        }
    }

    /**
     * Update owned property.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->attributes->get('auth_user');

            if (!$user || empty($user['id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $property = DB::table('properties')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->first();

            if (!$property) {
                return response()->json([
                    'success' => false,
                    'message' => 'Property not found',
                ], 404);
            }

            if ((int) $property->owner_id !== (int) $user['id']) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not allowed to edit this property',
                ], 403);
            }

            $validator = validator($request->all(), [
                'title' => 'sometimes|required|string|max:255',
                'description' => 'sometimes|required|string',

                'type_id' =>
                    'sometimes|required|integer|exists:property_types,id',

                'price' =>
                    'sometimes|required|numeric|min:0',

                'action_type' =>
                    'sometimes|required|in:buy,rent,booking',

                'rent_frequency' =>
                    'nullable|in:monthly,yearly',

                'area_sqft' =>
                    'sometimes|required|numeric|min:0.01',

                'bedrooms' =>
                    'sometimes|required|integer|min:0',

                'bathrooms' =>
                    'sometimes|required|integer|min:0',

                'floor_number' =>
                    'nullable|integer|min:0',

                'total_floors' =>
                    'nullable|integer|min:0',

                'year_built' =>
                    'nullable|integer|min:1800|max:2100',

                'is_furnished' =>
                    'nullable|in:furnished,unfurnished,semi_furnished',

                'virtual_tour_url' =>
                    'nullable|url|max:500',

                'property_condition' =>
                    'sometimes|required|in:ready,off_plan',

                'neighborhood_id' =>
                    'sometimes|required|integer|exists:neighborhoods,id',

                'address_line_1' =>
                    'sometimes|required|string|max:255',

                'address_line_2' =>
                    'nullable|string|max:255',

                'building_name' =>
                    'nullable|string|max:150',

                'latitude' =>
                    'sometimes|required|numeric|between:-90,90',

                'longitude' =>
                    'sometimes|required|numeric|between:-180,180',

                'features' =>
                    'nullable|array',

                'features.*' =>
                    'integer|distinct|exists:property_features,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid property data',
                    'errors' => $validator->errors(),
                ], 422);
            }

            DB::transaction(function () use (
                $request,
                $id
            ) {
                $propertyData = [];

                $fields = [
                    'title',
                    'description',
                    'type_id',
                    'price',
                    'action_type',
                    'rent_frequency',
                    'area_sqft',
                    'bedrooms',
                    'bathrooms',
                    'floor_number',
                    'total_floors',
                    'year_built',
                    'is_furnished',
                    'virtual_tour_url',
                    'property_condition',
                ];

                foreach ($fields as $field) {
                    if ($request->exists($field)) {
                        $propertyData[$field] = $request->input($field);
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Any owner edit requires admin moderation again
                |--------------------------------------------------------------------------
                */

                $propertyData['moderation_status'] = 'pending';
                $propertyData['rejection_reason'] = null;
                $propertyData['reviewed_by'] = null;
                $propertyData['reviewed_at'] = null;
                $propertyData['updated_at'] = now();

                DB::table('properties')
                    ->where('id', $id)
                    ->update($propertyData);

                /*
                |--------------------------------------------------------------------------
                | Location
                |--------------------------------------------------------------------------
                */

                $locationFields = [
                    'neighborhood_id',
                    'address_line_1',
                    'address_line_2',
                    'building_name',
                    'latitude',
                    'longitude',
                ];

                $hasLocationUpdate = false;

                foreach ($locationFields as $field) {
                    if ($request->exists($field)) {
                        $hasLocationUpdate = true;
                        break;
                    }
                }

                if ($hasLocationUpdate) {
                    $location = DB::table('property_locations')
                        ->where('property_id', $id)
                        ->first();

                    $locationData = [];

                    foreach ($locationFields as $field) {
                        if ($request->exists($field)) {
                            $locationData[$field] = $request->input($field);
                        }
                    }

                    if (
                        $request->exists('latitude') ||
                        $request->exists('longitude')
                    ) {
                        $latitude = $request->exists('latitude')
                            ? (float) $request->input('latitude')
                            : (float) $location->latitude;

                        $longitude = $request->exists('longitude')
                            ? (float) $request->input('longitude')
                            : (float) $location->longitude;

                        $locationData['coordinates'] = DB::raw(
                            sprintf(
                                'POINT(%F, %F)',
                                $longitude,
                                $latitude
                            )
                        );
                    }

                    $locationData['updated_at'] = now();

                    DB::table('property_locations')
                        ->where('property_id', $id)
                        ->update($locationData);
                }

                /*
                |--------------------------------------------------------------------------
                | Features
                |--------------------------------------------------------------------------
                */

                if ($request->exists('features')) {
                    DB::table('property_feature_values')
                        ->where('property_id', $id)
                        ->delete();

                    foreach (
                        $request->input('features', [])
                        as $featureId
                    ) {
                        DB::table('property_feature_values')
                            ->insert([
                                'property_id' => $id,
                                'feature_id' => (int) $featureId,
                                'feature_value' => '1',
                            ]);
                    }
                }
            });

            return response()->json([
                'success' => true,
                'message' =>
                    'Property updated successfully and is pending admin approval',
                'property_id' => $id,
                'moderation_status' => 'pending',
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update property',
            ], 500);
        }
    }

    /**
     * Soft delete owned property.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->attributes->get('auth_user');

            if (!$user || empty($user['id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $property = DB::table('properties')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->first();

            if (!$property) {
                return response()->json([
                    'success' => false,
                    'message' => 'Property not found',
                ], 404);
            }

            if ((int) $property->owner_id !== (int) $user['id']) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not allowed to delete this property',
                ], 403);
            }

            DB::table('properties')
                ->where('id', $id)
                ->update([
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);

            return response()->json([
                'success' => true,
                'message' => 'Property deleted successfully',
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete property',
            ], 500);
        }
    }

    /**
     * Get nearby approved properties.
     */
    public function nearby(Request $request, int $id): JsonResponse
    {
        try {
            $radius = (float) $request->query('radius', 5);
            $radius = max(0.1, min($radius, 100));

            $limit = (int) $request->query('limit', 20);
            $limit = max(1, min($limit, 100));

            $property = DB::table('properties as p')
                ->join(
                    'property_locations as pl',
                    'pl.property_id',
                    '=',
                    'p.id'
                )
                ->where('p.id', $id)
                ->whereNull('p.deleted_at')
                ->select([
                    'p.id',
                    'pl.latitude',
                    'pl.longitude',
                ])
                ->first();

            if (!$property) {
                return response()->json([
                    'success' => false,
                    'message' => 'Property not found',
                ], 404);
            }

            if (
                $property->latitude === null ||
                $property->longitude === null
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Property coordinates are not available',
                ], 422);
            }

            $latitude = (float) $property->latitude;
            $longitude = (float) $property->longitude;

            $distanceSql = '
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
            ';

            $nearby = DB::table('properties as p')
                ->join(
                    'property_locations as pl',
                    'pl.property_id',
                    '=',
                    'p.id'
                )
                ->leftJoin(
                    'property_types as pt',
                    'pt.id',
                    '=',
                    'p.type_id'
                )
                ->leftJoin(
                    'neighborhoods as n',
                    'n.id',
                    '=',
                    'pl.neighborhood_id'
                )
                ->where('p.id', '!=', $id)
                ->whereNull('p.deleted_at')
                ->where('p.moderation_status', 'approved')
                ->whereNotNull('pl.latitude')
                ->whereNotNull('pl.longitude')
                ->select([
                    'p.id',
                    'p.title',
                    'p.type_id',
                    'pt.name as type_name',
                    'p.price',
                    'p.currency',
                    'p.action_type',
                    'p.property_condition',
                    'p.bedrooms',
                    'p.bathrooms',
                    'p.area_sqft',
                    'pl.latitude',
                    'pl.longitude',
                    'pl.neighborhood_id',
                    'n.name as neighborhood_name',
                ])
                ->selectRaw(
                    $distanceSql . ' AS distance_km',
                    [
                        $latitude,
                        $longitude,
                        $latitude
                    ]
                )
                ->having('distance_km', '<=', $radius)
                ->orderBy('distance_km')
                ->limit($limit)
                ->get();

            $propertyIds = $nearby
                ->pluck('id')
                ->all();

            $images = DB::table('property_images')
                ->whereIn('property_id', $propertyIds)
                ->where('is_primary', 1)
                ->select([
                    'property_id',
                    'image_url',
                ])
                ->get()
                ->keyBy('property_id');

            $nearby = $nearby->map(function ($item) use ($images) {
                $item->distance_km = round(
                    (float) $item->distance_km,
                    2
                );

                $item->primary_image =
                    $images->get($item->id)->image_url ?? null;

                return $item;
            });

            return response()->json([
                'success' => true,
                'property_id' => $id,
                'radius_km' => $radius,
                'total' => $nearby->count(),
                'data' => $nearby,
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load nearby properties',
            ], 500);
        }
    }
}