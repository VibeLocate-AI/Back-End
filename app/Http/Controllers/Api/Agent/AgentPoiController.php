<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AgentPoiController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | List POIs Near Agency Properties
    |--------------------------------------------------------------------------
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

            $agencyId = (int) $request->attributes->get(
                'agent_agency_id'
            );

            if (!$agencyId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Agency information is missing',
                ], 403);
            }

            $validator = validator(
                $request->query(),
                [
                    'category' =>
                        'nullable|string|max:100',

                    'subcategory' =>
                        'nullable|string|max:100',

                    'search' =>
                        'nullable|string|max:255',

                    'radius' =>
                        'nullable|numeric|min:0.1|max:20',

                    'per_page' =>
                        'nullable|integer|min:1|max:100',
                ]
            );

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid filters',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $agency = DB::table('agencies')
                ->where('id', $agencyId)
                ->select([
                    'id',
                    'name',
                    'status',
                ])
                ->first();

            if (!$agency) {
                return response()->json([
                    'success' => false,
                    'message' => 'Agency not found',
                ], 404);
            }

            $radiusKm = (float) $request->query(
                'radius',
                3
            );

            $perPage = (int) $request->query(
                'per_page',
                20
            );

            $perPage = max(
                1,
                min($perPage, 100)
            );

            /*
            |--------------------------------------------------------------------------
            | Bounding Box
            |--------------------------------------------------------------------------
            */

            $latDelta = $radiusKm / 111;
            $lngDelta = $radiusKm / 100;

            /*
            |--------------------------------------------------------------------------
            | Haversine Distance
            |--------------------------------------------------------------------------
            */

            $distanceSql = '
                6371 * ACOS(
                    LEAST(
                        1,
                        GREATEST(
                            -1,
                            COS(RADIANS(pl.latitude))
                            * COS(RADIANS(poi.latitude))
                            * COS(
                                RADIANS(poi.longitude)
                                - RADIANS(pl.longitude)
                            )
                            + SIN(RADIANS(pl.latitude))
                            * SIN(RADIANS(poi.latitude))
                        )
                    )
                )
            ';

            $query = DB::table('property_locations as pl')
                ->join(
                    'properties as p',
                    'p.id',
                    '=',
                    'pl.property_id'
                )
                ->join(
                    'points_of_interest as poi',
                    function ($join) use (
                        $latDelta,
                        $lngDelta
                    ) {
                        $join->whereRaw(
                            'poi.latitude BETWEEN
                             (pl.latitude - ?)
                             AND
                             (pl.latitude + ?)',
                            [
                                $latDelta,
                                $latDelta,
                            ]
                        );

                        $join->whereRaw(
                            'poi.longitude BETWEEN
                             (pl.longitude - ?)
                             AND
                             (pl.longitude + ?)',
                            [
                                $lngDelta,
                                $lngDelta,
                            ]
                        );
                    }
                )
                ->where(
                    'p.agency_id',
                    $agencyId
                )
                ->whereNull(
                    'p.deleted_at'
                )
                ->where(
                    'p.moderation_status',
                    'approved'
                )
                ->whereNotNull(
                    'pl.latitude'
                )
                ->whereNotNull(
                    'pl.longitude'
                )
                ->whereNotNull(
                    'poi.name'
                )
                ->where(
                    'poi.name',
                    '<>',
                    ''
                )
                ->whereRaw(
                    'LOWER(TRIM(poi.name)) <> ?',
                    ['unnamed']
                );

            /*
            |--------------------------------------------------------------------------
            | Filters
            |--------------------------------------------------------------------------
            */

            if ($request->filled('category')) {
                $query->where(
                    'poi.category',
                    trim(
                        (string) $request->query('category')
                    )
                );
            }

            if ($request->filled('subcategory')) {
                $query->where(
                    'poi.subcategory',
                    trim(
                        (string) $request->query('subcategory')
                    )
                );
            }

            if ($request->filled('search')) {
                $search = trim(
                    (string) $request->query('search')
                );

                $query->where(
                    function ($q) use ($search) {
                        $q->where(
                            'poi.name',
                            'like',
                            '%' . $search . '%'
                        )
                            ->orWhere(
                                'poi.category',
                                'like',
                                '%' . $search . '%'
                            )
                            ->orWhere(
                                'poi.subcategory',
                                'like',
                                '%' . $search . '%'
                            );
                    }
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Select
            |--------------------------------------------------------------------------
            */

            $query->select([
                'poi.id',
                'poi.osm_id',
                'poi.agency_id',
                'poi.created_by',
                'poi.name',
                'poi.category',
                'poi.subcategory',
                'poi.latitude',
                'poi.longitude',
                'poi.icon',
                'poi.created_at',
                'poi.updated_at',
            ]);

            $query->selectRaw(
                'MIN(' . $distanceSql . ') AS distance_km'
            );

            $query->selectRaw(
                '
                COUNT(
                    DISTINCT CASE
                        WHEN (' . $distanceSql . ') <= ?
                        THEN p.id
                    END
                ) AS nearby_properties_count
                ',
                [
                    $radiusKm,
                ]
            );

            $query->groupBy([
                'poi.id',
                'poi.osm_id',
                'poi.agency_id',
                'poi.created_by',
                'poi.name',
                'poi.category',
                'poi.subcategory',
                'poi.latitude',
                'poi.longitude',
                'poi.icon',
                'poi.created_at',
                'poi.updated_at',
            ]);

            $query->havingRaw(
                'MIN(' . $distanceSql . ') <= ?',
                [
                    $radiusKm,
                ]
            );

            $query->orderByRaw(
                'MIN(' . $distanceSql . ') ASC'
            );

            $pois = $query->paginate(
                $perPage
            );

            $data = collect(
                $pois->items()
            )
                ->map(
                    function ($poi) use ($agencyId) {
                        return [
                            'id' =>
                                (int) $poi->id,

                            'osm_id' =>
                                $poi->osm_id !== null
                                    ? (int) $poi->osm_id
                                    : null,

                            'agency_id' =>
                                $poi->agency_id !== null
                                    ? (int) $poi->agency_id
                                    : null,

                            'created_by' =>
                                $poi->created_by !== null
                                    ? (int) $poi->created_by
                                    : null,

                            'name' =>
                                $poi->name,

                            'category' =>
                                $poi->category,

                            'subcategory' =>
                                $poi->subcategory,

                            'latitude' =>
                                (float) $poi->latitude,

                            'longitude' =>
                                (float) $poi->longitude,

                            'icon' =>
                                $poi->icon,

                            'distance_km' =>
                                round(
                                    (float) $poi->distance_km,
                                    3
                                ),

                            'distance_meters' =>
                                (int) round(
                                    (float) $poi->distance_km
                                    * 1000
                                ),

                            'nearby_properties_count' =>
                                (int) $poi
                                    ->nearby_properties_count,

                            /*
                             * Global imported POIs are read-only.
                             * Agency POIs can be managed by that agency.
                             */
                            'can_manage' =>
                                $poi->agency_id !== null
                                &&
                                (int) $poi->agency_id
                                    === $agencyId,

                            'created_at' =>
                                $poi->created_at,

                            'updated_at' =>
                                $poi->updated_at,
                        ];
                    }
                )
                ->values();

            return response()->json([
                'success' => true,

                'agency' => [
                    'id' =>
                        (int) $agency->id,

                    'name' =>
                        $agency->name,
                ],

                'radius_km' =>
                    $radiusKm,

                'data' =>
                    $data,

                'pagination' => [
                    'current_page' =>
                        $pois->currentPage(),

                    'last_page' =>
                        $pois->lastPage(),

                    'per_page' =>
                        $pois->perPage(),

                    'total' =>
                        $pois->total(),
                ],
            ]);

        } catch (Throwable $e) {
            Log::error(
                'Agent POI list failed',
                [
                    'agent_id' =>
                        $user['id'] ?? null,

                    'error' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),
                ]
            );

            report($e);

            return response()->json([
                'success' => false,
                'message' =>
                    'Failed to load agency POIs',
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Create POI
    |--------------------------------------------------------------------------
    */

    public function store(Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('auth_user');

            if (!$user || empty($user['id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $agentId = (int) $user['id'];

            $agencyId = (int) $request->attributes->get(
                'agent_agency_id'
            );

            if (!$agencyId) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Agency information is missing',
                ], 403);
            }

            $validator = validator(
                $request->all(),
                [
                    'name' =>
                        'required|string|max:255',

                    'category' =>
                        'required|string|max:100',

                    'subcategory' =>
                        'required|string|max:100',

                    'latitude' =>
                        'required|numeric|between:-90,90',

                    'longitude' =>
                        'required|numeric|between:-180,180',

                    'icon' =>
                        'nullable|string|max:20',
                ]
            );

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Validation failed',

                    'errors' =>
                        $validator->errors(),

                ], 422);
            }

            $poiId = DB::table(
                'points_of_interest'
            )
                ->insertGetId([
                    'osm_id' =>
                        null,

                    'agency_id' =>
                        $agencyId,

                    'created_by' =>
                        $agentId,

                    'name' =>
                        trim(
                            (string) $request->name
                        ),

                    'category' =>
                        trim(
                            (string) $request->category
                        ),

                    'subcategory' =>
                        trim(
                            (string) $request->subcategory
                        ),

                    'latitude' =>
                        (float) $request->latitude,

                    'longitude' =>
                        (float) $request->longitude,

                    'icon' =>
                        $request->filled('icon')
                            ? trim(
                                (string) $request->icon
                            )
                            : null,

                    'created_at' =>
                        now(),

                    'updated_at' =>
                        now(),
                ]);

            $poi = DB::table(
                'points_of_interest'
            )
                ->where(
                    'id',
                    $poiId
                )
                ->first();

            return response()->json([
                'success' => true,

                'message' =>
                    'POI created successfully',

                'data' =>
                    $poi,

            ], 201);

        } catch (Throwable $e) {
            Log::error(
                'Agent POI create failed',
                [
                    'agent_id' =>
                        $user['id'] ?? null,

                    'error' =>
                        $e->getMessage(),
                ]
            );

            report($e);

            return response()->json([
                'success' => false,
                'message' =>
                    'Failed to create POI',
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Update Agency POI
    |--------------------------------------------------------------------------
    */

    public function update(
        Request $request,
        int $id
    ): JsonResponse {
        try {
            $user = $request->attributes->get('auth_user');

            if (!$user || empty($user['id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $agencyId = (int) $request->attributes->get(
                'agent_agency_id'
            );

            if (!$agencyId) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Agency information is missing',
                ], 403);
            }

            /*
             * Only POIs owned by this agency
             * can be modified.
             */
            $poi = DB::table(
                'points_of_interest'
            )
                ->where(
                    'id',
                    $id
                )
                ->where(
                    'agency_id',
                    $agencyId
                )
                ->first();

            if (!$poi) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'POI not found or cannot be managed by your agency',
                ], 404);
            }

            $validator = validator(
                $request->all(),
                [
                    'name' =>
                        'sometimes|required|string|max:255',

                    'category' =>
                        'sometimes|required|string|max:100',

                    'subcategory' =>
                        'sometimes|required|string|max:100',

                    'latitude' =>
                        'sometimes|required|numeric|between:-90,90',

                    'longitude' =>
                        'sometimes|required|numeric|between:-180,180',

                    'icon' =>
                        'nullable|string|max:20',
                ]
            );

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,

                    'message' =>
                        'Validation failed',

                    'errors' =>
                        $validator->errors(),

                ], 422);
            }

            $data = [];

            if ($request->has('name')) {
                $data['name'] = trim(
                    (string) $request->name
                );
            }

            if ($request->has('category')) {
                $data['category'] = trim(
                    (string) $request->category
                );
            }

            if ($request->has('subcategory')) {
                $data['subcategory'] = trim(
                    (string) $request->subcategory
                );
            }

            if ($request->has('latitude')) {
                $data['latitude'] =
                    (float) $request->latitude;
            }

            if ($request->has('longitude')) {
                $data['longitude'] =
                    (float) $request->longitude;
            }

            if ($request->has('icon')) {
                $data['icon'] =
                    $request->filled('icon')
                        ? trim(
                            (string) $request->icon
                        )
                        : null;
            }

            if (empty($data)) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'No fields provided for update',
                ], 422);
            }

            $data['updated_at'] = now();

            DB::table(
                'points_of_interest'
            )
                ->where(
                    'id',
                    $id
                )
                ->where(
                    'agency_id',
                    $agencyId
                )
                ->update(
                    $data
                );

            $updatedPoi = DB::table(
                'points_of_interest'
            )
                ->where(
                    'id',
                    $id
                )
                ->where(
                    'agency_id',
                    $agencyId
                )
                ->first();

            return response()->json([
                'success' => true,

                'message' =>
                    'POI updated successfully',

                'data' =>
                    $updatedPoi,
            ]);

        } catch (Throwable $e) {
            Log::error(
                'Agent POI update failed',
                [
                    'agent_id' =>
                        $user['id'] ?? null,

                    'poi_id' =>
                        $id,

                    'error' =>
                        $e->getMessage(),
                ]
            );

            report($e);

            return response()->json([
                'success' => false,
                'message' =>
                    'Failed to update POI',
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Delete Agency POI
    |--------------------------------------------------------------------------
    */

    public function destroy(
        Request $request,
        int $id
    ): JsonResponse {
        try {
            $user = $request->attributes->get('auth_user');

            if (!$user || empty($user['id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $agencyId = (int) $request->attributes->get(
                'agent_agency_id'
            );

            if (!$agencyId) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Agency information is missing',
                ], 403);
            }

            $poi = DB::table(
                'points_of_interest'
            )
                ->where(
                    'id',
                    $id
                )
                ->where(
                    'agency_id',
                    $agencyId
                )
                ->first();

            if (!$poi) {
                return response()->json([
                    'success' => false,

                    'message' =>
                        'POI not found or cannot be managed by your agency',

                ], 404);
            }

            DB::table(
                'points_of_interest'
            )
                ->where(
                    'id',
                    $id
                )
                ->where(
                    'agency_id',
                    $agencyId
                )
                ->delete();

            return response()->json([
                'success' => true,

                'message' =>
                    'POI deleted successfully',
            ]);

        } catch (Throwable $e) {
            Log::error(
                'Agent POI delete failed',
                [
                    'agent_id' =>
                        $user['id'] ?? null,

                    'poi_id' =>
                        $id,

                    'error' =>
                        $e->getMessage(),
                ]
            );

            report($e);

            return response()->json([
                'success' => false,
                'message' =>
                    'Failed to delete POI',
            ], 500);
        }
    }
}