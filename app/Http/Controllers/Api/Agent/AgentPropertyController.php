<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\NotificationController;
use App\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AgentPropertyController extends Controller
{
    /**
     * Get properties assigned to the agent's agency.
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

            $agentId = (int) $user['id'];

            /*
            |--------------------------------------------------------------------------
            | Get Agent Agency
            |--------------------------------------------------------------------------
            */

            $agency = DB::table('agency_agents')
                ->where('user_id', $agentId)
                ->first();

            if (!$agency) {
                return response()->json([
                    'success' => false,
                    'message' => 'Agent is not assigned to an agency',
                ], 403);
            }

            /*
            |--------------------------------------------------------------------------
            | Status Filter
            |--------------------------------------------------------------------------
            */

            $status = (string) $request->query(
                'status',
                'pending'
            );

            $allowedStatuses = [
                'pending',
                'approved',
                'rejected',
            ];

            if (!in_array($status, $allowedStatuses, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid moderation status',
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Pagination
            |--------------------------------------------------------------------------
            */

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
            | Properties Query
            |--------------------------------------------------------------------------
            */

            $query = DB::table('properties as p')
                ->leftJoin(
                    'users as u',
                    'u.id',
                    '=',
                    'p.owner_id'
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
                ->where(
                    'p.agency_id',
                    (int) $agency->agency_id
                )
                ->where(
                    'p.moderation_status',
                    $status
                )
                ->select([
                    'p.id',
                    'p.owner_id',
                    'p.agency_id',
                    'p.title',
                    'p.description',
                    'p.price',
                    'p.currency',
                    'p.bedrooms',
                    'p.bathrooms',
                    'p.area_sqft',
                    'p.moderation_status',
                    'p.rejection_reason',
                    'p.created_at',

                    'pt.name as property_type',
                    'pc.name as category',

                    'u.first_name as owner_first_name',
                    'u.last_name as owner_last_name',
                    'u.email as owner_email',
                ])
                ->orderByDesc('p.id');

            $properties = $query->paginate(
                $perPage
            );

            /*
            |--------------------------------------------------------------------------
            | Format Properties
            |--------------------------------------------------------------------------
            */

            $data = collect(
                $properties->items()
            )->map(function ($property) {
                $image = DB::table(
                    'property_images'
                )
                    ->where(
                        'property_id',
                        $property->id
                    )
                    ->orderByDesc(
                        'is_primary'
                    )
                    ->orderBy(
                        'display_order'
                    )
                    ->value(
                        'image_url'
                    );

                $ownerName = trim(
                    (
                        $property
                            ->owner_first_name
                        ?? ''
                    )
                    . ' '
                    . (
                        $property
                            ->owner_last_name
                        ?? ''
                    )
                );

                return [
                    'id' =>
                        (int) $property->id,

                    'owner_id' =>
                        (int) $property->owner_id,

                    'agency_id' =>
                        $property->agency_id
                            !== null
                            ? (int) $property->agency_id
                            : null,

                    'title' =>
                        $property->title,

                    'description' =>
                        $property->description,

                    'price' =>
                        $property->price,

                    'currency' =>
                        $property->currency,

                    'bedrooms' =>
                        $property->bedrooms
                            !== null
                            ? (int) $property->bedrooms
                            : null,

                    'bathrooms' =>
                        $property->bathrooms
                            !== null
                            ? (int) $property->bathrooms
                            : null,

                    'area_sqft' =>
                        $property->area_sqft,

                    'moderation_status' =>
                        $property
                            ->moderation_status,

                    'rejection_reason' =>
                        $property
                            ->rejection_reason,

                    'created_at' =>
                        $property
                            ->created_at,

                    'property_type' =>
                        $property
                            ->property_type,

                    'category' =>
                        $property
                            ->category,

                    'image' =>
                        $image,

                    'owner' => [
                        'id' =>
                            (int) $property
                                ->owner_id,

                        'name' =>
                            $ownerName,

                        'email' =>
                            $property
                                ->owner_email,
                    ],
                ];
            });

            return response()->json([
                'success' => true,

                'status' => $status,

                'data' => $data,

                'pagination' => [
                    'current_page' =>
                        $properties
                            ->currentPage(),

                    'per_page' =>
                        $properties
                            ->perPage(),

                    'total' =>
                        $properties
                            ->total(),

                    'last_page' =>
                        $properties
                            ->lastPage(),
                ],
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' =>
                    'Failed to load properties',
            ], 500);
        }
    }


    /**
     * Approve a pending property.
     */
    public function approve(
        Request $request,
        int $id
    ): JsonResponse {
        try {
            $user = $request->attributes->get(
                'auth_user'
            );

            if (!$user || empty($user['id'])) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Unauthenticated',
                ], 401);
            }

            $agentId =
                (int) $user['id'];

            /*
            |--------------------------------------------------------------------------
            | Get Agent Agency
            |--------------------------------------------------------------------------
            */

            $agency = DB::table(
                'agency_agents'
            )
                ->where(
                    'user_id',
                    $agentId
                )
                ->first();

            if (!$agency) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Agent is not assigned to an agency',
                ], 403);
            }

            /*
            |--------------------------------------------------------------------------
            | Get Property
            |--------------------------------------------------------------------------
            */

            $property = DB::table(
                'properties'
            )
                ->where(
                    'id',
                    $id
                )
                ->whereNull(
                    'deleted_at'
                )
                ->first();

            if (!$property) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Property not found',
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | Check Agency
            |--------------------------------------------------------------------------
            */

            if (
                (int) $property->agency_id
                !==
                (int) $agency->agency_id
            ) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'You are not allowed to review this property',
                ], 403);
            }

            /*
            |--------------------------------------------------------------------------
            | IMPORTANT:
            | Only Pending Properties Can Be Reviewed
            |--------------------------------------------------------------------------
            */

            if (
                $property->moderation_status
                !== 'pending'
            ) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Only pending properties can be reviewed',
                ], 409);
            }

            /*
            |--------------------------------------------------------------------------
            | Approve Property
            |--------------------------------------------------------------------------
            */

            DB::transaction(
                function () use (
                    $id,
                    $agentId,
                    $property
                ) {
                    DB::table(
                        'properties'
                    )
                        ->where(
                            'id',
                            $id
                        )
                        ->update([
                            'moderation_status' =>
                                'approved',

                            'rejection_reason' =>
                                null,

                            'reviewed_by' =>
                                $agentId,

                            'reviewed_at' =>
                                now(),

                            'updated_at' =>
                                now(),
                        ]);

                    /*
                    |--------------------------------------------------------------------------
                    | Notify Property Owner
                    |--------------------------------------------------------------------------
                    */

                    Notification::create([
                        'user_id' =>
                            (int) $property
                                ->owner_id,

                        'type' =>
                            'system',

                        'title' =>
                            'تمت الموافقة على عقارك | Property Approved',

                        'message' =>
                            'تمت الموافقة على عقارك وأصبح متاحًا للمستخدمين. | Your property has been approved and is now visible to users.',

                        'image' =>
                            null,

                        'reference_id' =>
                            $id,

                        'reference_type' =>
                            'property',

                        'action_url' =>
                            '/properties/' . $id,

                        'is_read' =>
                            false,

                        'read_at' =>
                            null,
                    ]);
                }
            );

            /*
            |--------------------------------------------------------------------------
            | Notify All Users About New Approved Property
            |--------------------------------------------------------------------------
            */

            NotificationController::sendToAllUsers(
                'property',
                'عقار جديد | New Property',
                'تمت إضافة عقار جديد على VibeLocate. يمكنك الاطلاع على التفاصيل الآن. | A new property has been added to VibeLocate. Check it out now.',
                'property',
                $id,
                '/properties/' . $id
            );

            return response()->json([
                'success' => true,

                'message' =>
                    'Property approved successfully by agent',

                'property_id' =>
                    $id,

                'moderation_status' =>
                    'approved',
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' =>
                    'Failed to approve property',
            ], 500);
        }
    }


    /**
     * Reject a pending property.
     */
    public function reject(
        Request $request,
        int $id
    ): JsonResponse {
        try {
            $user = $request->attributes->get(
                'auth_user'
            );

            if (!$user || empty($user['id'])) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Unauthenticated',
                ], 401);
            }

            $agentId =
                (int) $user['id'];

            /*
            |--------------------------------------------------------------------------
            | Validate Rejection Reason
            |--------------------------------------------------------------------------
            */

            $validator = validator(
                $request->all(),
                [
                    'reason' =>
                        'required|string|min:3|max:2000',
                ]
            );

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,

                    'message' =>
                        'Invalid rejection data',

                    'errors' =>
                        $validator->errors(),
                ], 422);
            }

            $reason = trim(
                (string) $request->input(
                    'reason'
                )
            );

            /*
            |--------------------------------------------------------------------------
            | Get Agent Agency
            |--------------------------------------------------------------------------
            */

            $agency = DB::table(
                'agency_agents'
            )
                ->where(
                    'user_id',
                    $agentId
                )
                ->first();

            if (!$agency) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Agent is not assigned to an agency',
                ], 403);
            }

            /*
            |--------------------------------------------------------------------------
            | Get Property
            |--------------------------------------------------------------------------
            */

            $property = DB::table(
                'properties'
            )
                ->where(
                    'id',
                    $id
                )
                ->whereNull(
                    'deleted_at'
                )
                ->first();

            if (!$property) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Property not found',
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | Check Agency
            |--------------------------------------------------------------------------
            */

            if (
                (int) $property->agency_id
                !==
                (int) $agency->agency_id
            ) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'You are not allowed to review this property',
                ], 403);
            }

            /*
            |--------------------------------------------------------------------------
            | IMPORTANT:
            | Only Pending Properties Can Be Reviewed
            |--------------------------------------------------------------------------
            */

            if (
                $property->moderation_status
                !== 'pending'
            ) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Only pending properties can be reviewed',
                ], 409);
            }

            /*
            |--------------------------------------------------------------------------
            | Reject Property
            |--------------------------------------------------------------------------
            */

            DB::transaction(
                function () use (
                    $id,
                    $agentId,
                    $reason,
                    $property
                ) {
                    DB::table(
                        'properties'
                    )
                        ->where(
                            'id',
                            $id
                        )
                        ->update([
                            'moderation_status' =>
                                'rejected',

                            'rejection_reason' =>
                                $reason,

                            'reviewed_by' =>
                                $agentId,

                            'reviewed_at' =>
                                now(),

                            'updated_at' =>
                                now(),
                        ]);

                    /*
                    |--------------------------------------------------------------------------
                    | Notify Property Owner
                    |--------------------------------------------------------------------------
                    */

                    Notification::create([
                        'user_id' =>
                            (int) $property
                                ->owner_id,

                        'type' =>
                            'system',

                        'title' =>
                            'تم رفض العقار | Property Rejected',

                        'message' =>
                            'تم رفض العقار. السبب: '
                            . $reason
                            . ' | Your property was rejected. Reason: '
                            . $reason,

                        'image' =>
                            null,

                        'reference_id' =>
                            $id,

                        'reference_type' =>
                            'property',

                        'action_url' =>
                            '/properties/' . $id,

                        'is_read' =>
                            false,

                        'read_at' =>
                            null,
                    ]);
                }
            );

            return response()->json([
                'success' => true,

                'message' =>
                    'Property rejected successfully by agent',

                'property_id' =>
                    $id,

                'moderation_status' =>
                    'rejected',

                'rejection_reason' =>
                    $reason,
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' =>
                    'Failed to reject property',
            ], 500);
        }
    }
}