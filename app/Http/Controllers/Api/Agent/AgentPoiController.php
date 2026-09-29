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
    /**
     * List POIs near properties belonging to the agent's agency.
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

        $validator = validator($request->query(), [
            'category' => 'nullable|string|max:100',
            'subcategory' => 'nullable|string|max:100',
            'search' => 'nullable|string|max:255',
            'radius' => 'nullable|numeric|min:0.1|max:20',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid filters',
                'errors' => $validator->errors(),
            ], 422);
        }

        $agentId = (int) $user['id'];

        /*
        |--------------------------------------------------------------------------
        | Get Agent Agency
        |--------------------------------------------------------------------------
        */

        $agency = DB::table('agency_agents as aa')
            ->join(
                'agencies as a',
                'a.id',
                '=',
                'aa.agency_id'
            )
            ->where(
                'aa.user_id',
                $agentId
            )
            ->select([
                'a.id',
                'a.name',
            ])
            ->first();

        if (!$agency) {
            return response()->json([
                'success' => false,
                'message' => 'Agent is not assigned to an agency',
            ], 403);
        }

        $radiusKm = (float) $request->query(
            'radius',
            3
        );

        $perPage = (int) $request->query(
            'per_page',
            20
        );

        /*
        |--------------------------------------------------------------------------
        | Bounding Box
        |--------------------------------------------------------------------------
        |
        | 1 degree latitude ≈ 111 km.
        | In Dubai, 1 degree longitude ≈ 100 km.
        |
        */

        $latDelta = $radiusKm / 111;
        $lngDelta = $radiusKm / 100;

        /*
        |--------------------------------------------------------------------------
        | Haversine Formula
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

        /*
        |--------------------------------------------------------------------------
        | Query
        |--------------------------------------------------------------------------
        */

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

            /*
            |--------------------------------------------------------------------------
            | Agency Properties Only
            |--------------------------------------------------------------------------
            */

            ->where(
                'p.agency_id',
                (int) $agency->id
            )

            ->whereNull(
                'p.deleted_at'
            )

            ->whereNotNull(
                'pl.latitude'
            )

            ->whereNotNull(
                'pl.longitude'
            )

            /*
            |--------------------------------------------------------------------------
            | Approved Properties Only
            |--------------------------------------------------------------------------
            */

            ->where(
                'p.moderation_status',
                'approved'
            )

            /*
            |--------------------------------------------------------------------------
            | Valid POI Names
            |--------------------------------------------------------------------------
            */

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
        | Optional Filters
        |--------------------------------------------------------------------------
        */

        if ($request->filled('category')) {
            $query->where(
                'poi.category',
                $request->query('category')
            );
        }

        if ($request->filled('subcategory')) {
            $query->where(
                'poi.subcategory',
                $request->query('subcategory')
            );
        }

        if ($request->filled('search')) {
            $search = trim(
                $request->query('search')
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
            'poi.name',
            'poi.category',
            'poi.subcategory',
            'poi.latitude',
            'poi.longitude',
            'poi.icon',
            'poi.created_at',
            'poi.updated_at',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Nearest Distance
        |--------------------------------------------------------------------------
        */

        $query->selectRaw(
            'MIN(' . $distanceSql . ') AS distance_km'
        );

        /*
        |--------------------------------------------------------------------------
        | Number of Agency Properties Near This POI
        |--------------------------------------------------------------------------
        */

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

        /*
        |--------------------------------------------------------------------------
        | Group
        |--------------------------------------------------------------------------
        */

        $query->groupBy([
            'poi.id',
            'poi.osm_id',
            'poi.name',
            'poi.category',
            'poi.subcategory',
            'poi.latitude',
            'poi.longitude',
            'poi.icon',
            'poi.created_at',
            'poi.updated_at',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Exact Radius
        |--------------------------------------------------------------------------
        */

        $query->havingRaw(
            'MIN(' . $distanceSql . ') <= ?',
            [
                $radiusKm,
            ]
        );

        $query->orderByRaw(
            'MIN(' . $distanceSql . ') ASC'
        );

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $pois = $query->paginate(
            $perPage
        );

        /*
        |--------------------------------------------------------------------------
        | Format Result
        |--------------------------------------------------------------------------
        */

        $data = collect(
            $pois->items()
        )
            ->map(
                function ($poi) {
                    return [
                        'id' =>
                            (int) $poi->id,

                        'osm_id' =>
                            $poi->osm_id !== null
                                ? (int) $poi->osm_id
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

                        'created_at' =>
                            $poi->created_at,

                        'updated_at' =>
                            $poi->updated_at,
                    ];
                }
            )
            ->values();

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

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
            'message' => 'Failed to load agency POIs',
        ], 500);
    }
}
}