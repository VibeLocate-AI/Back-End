<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AdminAgencyController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | List Agencies
    |--------------------------------------------------------------------------
    */

    public function index(Request $request): JsonResponse
    {
        try {
            $validator = validator(
                $request->query(),
                [
                    'status' =>
                        'nullable|in:pending,active,suspended,rejected',

                    'search' =>
                        'nullable|string|max:255',

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

            $perPage = (int) $request->query(
                'per_page',
                20
            );

            $perPage = max(
                1,
                min($perPage, 100)
            );

            $query = DB::table('agencies as a')
                ->leftJoin(
                    'users as owner',
                    'owner.id',
                    '=',
                    'a.owner_user_id'
                )
                ->select([
                    'a.id',
                    'a.owner_user_id',
                    'a.name',
                    'a.slug',
                    'a.license_number',
                    'a.license_authority',
                    'a.license_expiry_date',
                    'a.logo_url',
                    'a.address',
                    'a.phone',
                    'a.email',
                    'a.status',
                    'a.rejection_reason',
                    'a.reviewed_by',
                    'a.reviewed_at',
                    'a.created_at',
                    'a.updated_at',

                    'owner.first_name as owner_first_name',
                    'owner.last_name as owner_last_name',
                    'owner.email as owner_email',
                ]);

            /*
            |--------------------------------------------------------------------------
            | Filters
            |--------------------------------------------------------------------------
            */

            if ($request->filled('status')) {
                $query->where(
                    'a.status',
                    $request->query('status')
                );
            }

            if ($request->filled('search')) {
                $search = trim(
                    (string) $request->query('search')
                );

                $query->where(
                    function ($q) use ($search) {
                        $q->where(
                            'a.name',
                            'like',
                            '%' . $search . '%'
                        )
                            ->orWhere(
                                'a.license_number',
                                'like',
                                '%' . $search . '%'
                            )
                            ->orWhere(
                                'a.email',
                                'like',
                                '%' . $search . '%'
                            )
                            ->orWhere(
                                'owner.first_name',
                                'like',
                                '%' . $search . '%'
                            )
                            ->orWhere(
                                'owner.last_name',
                                'like',
                                '%' . $search . '%'
                            )
                            ->orWhere(
                                'owner.email',
                                'like',
                                '%' . $search . '%'
                            );
                    }
                );
            }

            $agencies = $query
                ->orderByDesc('a.id')
                ->paginate($perPage);

            $data = collect(
                $agencies->items()
            )->map(function ($agency) {

                $agentsCount = DB::table(
                    'agency_agents'
                )
                    ->where(
                        'agency_id',
                        $agency->id
                    )
                    ->count();

                return [
                    'id' =>
                        (int) $agency->id,

                    'owner_user_id' =>
                        $agency->owner_user_id !== null
                            ? (int) $agency->owner_user_id
                            : null,

                    'name' =>
                        $agency->name,

                    'slug' =>
                        $agency->slug,

                    'license_number' =>
                        $agency->license_number,

                    'license_authority' =>
                        $agency->license_authority,

                    'license_expiry_date' =>
                        $agency->license_expiry_date,

                    'logo_url' =>
                        $agency->logo_url,

                    'address' =>
                        $agency->address,

                    'phone' =>
                        $agency->phone,

                    'email' =>
                        $agency->email,

                    'status' =>
                        $agency->status,

                    'rejection_reason' =>
                        $agency->rejection_reason,

                    'reviewed_by' =>
                        $agency->reviewed_by,

                    'reviewed_at' =>
                        $agency->reviewed_at,

                    'agents_count' =>
                        $agentsCount,

                    'owner' => [
                        'first_name' =>
                            $agency->owner_first_name,

                        'last_name' =>
                            $agency->owner_last_name,

                        'email' =>
                            $agency->owner_email,
                    ],

                    'created_at' =>
                        $agency->created_at,

                    'updated_at' =>
                        $agency->updated_at,
                ];
            });

            return response()->json([
                'success' => true,

                'data' =>
                    $data,

                'filters' => [
                    'status' =>
                        $request->query('status'),

                    'search' =>
                        $request->query('search'),
                ],

                'pagination' => [
                    'current_page' =>
                        $agencies->currentPage(),

                    'per_page' =>
                        $agencies->perPage(),

                    'total' =>
                        $agencies->total(),

                    'last_page' =>
                        $agencies->lastPage(),
                ],
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,

                'message' =>
                    'Failed to load agencies',

                'details' =>
                    app()->environment('local')
                        ? $e->getMessage()
                        : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Agency Details
    |--------------------------------------------------------------------------
    */

    public function show(int $id): JsonResponse
    {
        try {
            $agency = DB::table('agencies as a')
                ->leftJoin(
                    'users as owner',
                    'owner.id',
                    '=',
                    'a.owner_user_id'
                )
                ->leftJoin(
                    'users as reviewer',
                    'reviewer.id',
                    '=',
                    'a.reviewed_by'
                )
                ->where(
                    'a.id',
                    $id
                )
                ->select([
                    'a.*',

                    'owner.first_name as owner_first_name',
                    'owner.last_name as owner_last_name',
                    'owner.email as owner_email',
                    'owner.phone as owner_phone',

                    'reviewer.first_name as reviewer_first_name',
                    'reviewer.last_name as reviewer_last_name',
                    'reviewer.email as reviewer_email',
                ])
                ->first();

            if (!$agency) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Agency not found',
                ], 404);
            }

            $agents = DB::table('agency_agents as aa')
                ->join(
                    'users as u',
                    'u.id',
                    '=',
                    'aa.user_id'
                )
                ->where(
                    'aa.agency_id',
                    $id
                )
                ->select([
                    'u.id',
                    'u.first_name',
                    'u.last_name',
                    'u.email',
                    'u.phone',
                    'u.status',
                    'aa.is_manager',
                    'aa.joined_at',
                ])
                ->orderByDesc(
                    'aa.is_manager'
                )
                ->get();

            $propertiesCount = DB::table('properties')
                ->where(
                    'agency_id',
                    $id
                )
                ->whereNull(
                    'deleted_at'
                )
                ->count();

            $pendingPropertiesCount =
                DB::table('properties')
                    ->where(
                        'agency_id',
                        $id
                    )
                    ->whereNull(
                        'deleted_at'
                    )
                    ->where(
                        'moderation_status',
                        'pending'
                    )
                    ->count();

            return response()->json([
                'success' => true,

                'data' => [
                    'agency' =>
                        $agency,

                    'agents' =>
                        $agents,

                    'statistics' => [
                        'agents_count' =>
                            $agents->count(),

                        'properties_count' =>
                            $propertiesCount,

                        'pending_properties_count' =>
                            $pendingPropertiesCount,
                    ],
                ],
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,

                'message' =>
                    'Failed to load agency',

                'details' =>
                    app()->environment('local')
                        ? $e->getMessage()
                        : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Update Agency Status
    |--------------------------------------------------------------------------
    */

    public function updateStatus(
        Request $request,
        int $id
    ): JsonResponse {
        try {
            $validator = validator(
                $request->all(),
                [
                    'status' =>
                        'required|in:pending,active,suspended,rejected',

                    'reason' =>
                        'nullable|string|max:5000',
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

            $authUser = $request
                ->attributes
                ->get('auth_user');

            if (
                !$authUser ||
                empty($authUser['id'])
            ) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Unauthenticated',
                ], 401);
            }

            $agency = DB::table('agencies')
                ->where(
                    'id',
                    $id
                )
                ->first();

            if (!$agency) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Agency not found',
                ], 404);
            }

            $status = trim(
                (string) $request->input('status')
            );

            $reason = $request->filled('reason')
                ? trim(
                    (string) $request->input('reason')
                )
                : null;

            /*
            |--------------------------------------------------------------------------
            | Rejection / Suspension Reason
            |--------------------------------------------------------------------------
            */

            if (
                in_array(
                    $status,
                    ['rejected', 'suspended'],
                    true
                )
                &&
                $reason === null
            ) {
                return response()->json([
                    'success' => false,

                    'message' =>
                        'Reason is required for rejected or suspended agencies',

                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Update
            |--------------------------------------------------------------------------
            */

            DB::table('agencies')
                ->where(
                    'id',
                    $id
                )
                ->update([
                    'status' =>
                        $status,

                    'rejection_reason' =>
                        in_array(
                            $status,
                            ['rejected', 'suspended'],
                            true
                        )
                            ? $reason
                            : null,

                    'reviewed_by' =>
                        (int) $authUser['id'],

                    'reviewed_at' =>
                        now(),

                    'updated_at' =>
                        now(),
                ]);

            /*
            |--------------------------------------------------------------------------
            | Notify Agency Owner
            |--------------------------------------------------------------------------
            */

            if ($agency->owner_user_id !== null) {
                $notification = match ($status) {
                    'active' => [
                        'title' =>
                            'تم اعتماد الوكالة | Agency Approved',

                        'message' =>
                            'تم اعتماد وكالتك وأصبحت خدمات الوكيل متاحة الآن. | Your agency has been approved and agent services are now available.',
                    ],

                    'rejected' => [
                        'title' =>
                            'تم رفض الوكالة | Agency Rejected',

                        'message' =>
                            'تم رفض طلب الوكالة. السبب: '
                            . $reason
                            . ' | Your agency application was rejected. Reason: '
                            . $reason,
                    ],

                    'suspended' => [
                        'title' =>
                            'تم تعليق الوكالة | Agency Suspended',

                        'message' =>
                            'تم تعليق الوكالة. السبب: '
                            . $reason
                            . ' | Your agency has been suspended. Reason: '
                            . $reason,
                    ],

                    default => [
                        'title' =>
                            'تحديث حالة الوكالة | Agency Status Updated',

                        'message' =>
                            'تم تحديث حالة الوكالة إلى '
                            . $status
                            . '. | Agency status has been updated to '
                            . $status
                            . '.',
                    ],
                };

                Notification::create([
                    'user_id' =>
                        (int) $agency->owner_user_id,

                    'type' =>
                        'system',

                    'title' =>
                        $notification['title'],

                    'message' =>
                        $notification['message'],

                    'image' =>
                        null,

                    'reference_id' =>
                        $id,

                    'reference_type' =>
                        'agency',

                    'action_url' =>
                        '/agent/dashboard',

                    'is_read' =>
                        false,

                    'read_at' =>
                        null,
                ]);
            }

            return response()->json([
                'success' => true,

                'message' =>
                    'Agency status updated successfully',

                'agency_id' =>
                    $id,

                'status' =>
                    $status,

                'reason' =>
                    in_array(
                        $status,
                        ['rejected', 'suspended'],
                        true
                    )
                        ? $reason
                        : null,
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,

                'message' =>
                    'Failed to update agency status',

                'details' =>
                    app()->environment('local')
                        ? $e->getMessage()
                        : null,
            ], 500);
        }
    }
}