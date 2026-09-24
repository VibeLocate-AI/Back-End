<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AdminPropertyController extends Controller
{
    /**
     * List properties for admin review.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $status = (string) $request->query('status', 'pending');

            if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid moderation status',
                ], 422);
            }

            $perPage = (int) $request->query('per_page', 20);
            $perPage = max(1, min($perPage, 100));

            $query = DB::table('properties as p')
                ->leftJoin('users as u', 'u.id', '=', 'p.owner_id')
                ->leftJoin('property_types as pt', 'pt.id', '=', 'p.type_id')
                ->leftJoin('property_categories as pc', 'pc.id', '=', 'p.category_id')
                ->leftJoin('property_status as ps', 'ps.id', '=', 'p.status_id')
                ->whereNull('p.deleted_at')
                ->where('p.moderation_status', $status)
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
                    'p.moderation_status',
                    'p.rejection_reason',
                    'p.reviewed_by',
                    'p.reviewed_at',
                    'p.property_condition',
                    'p.action_type',
                    'p.title',
                    'p.slug',
                    'p.description',
                    'p.price',
                    'p.currency',
                    'p.rent_frequency',
                    'p.area_sqft',
                    'p.bedrooms',
                    'p.bathrooms',
                    'p.created_at',
                    'p.updated_at',
                    'u.first_name as owner_first_name',
                    'u.last_name as owner_last_name',
                    'u.email as owner_email',
                    'u.phone as owner_phone',
                ]);

            if ($request->filled('search')) {
                $search = trim((string) $request->query('search'));

                $query->where(function ($q) use ($search) {
                    $q->where('p.title', 'like', '%' . $search . '%')
                        ->orWhere('p.description', 'like', '%' . $search . '%')
                        ->orWhere('u.first_name', 'like', '%' . $search . '%')
                        ->orWhere('u.last_name', 'like', '%' . $search . '%')
                        ->orWhere('u.email', 'like', '%' . $search . '%');
                });
            }

            $properties = $query
                ->orderByDesc('p.id')
                ->paginate($perPage);

            $items = collect($properties->items());
            $ids = $items->pluck('id')->all();

            $images = collect();
            $locations = collect();

            if (!empty($ids)) {
                $images = DB::table('property_images')
                    ->whereIn('property_id', $ids)
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

                $locations = DB::table('property_locations as pl')
                    ->leftJoin('neighborhoods as n', 'n.id', '=', 'pl.neighborhood_id')
                    ->whereIn('pl.property_id', $ids)
                    ->select([
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

            $data = $items->map(function ($property) use ($images, $locations) {
                $property->owner = [
                    'id' => $property->owner_id,
                    'first_name' => $property->owner_first_name,
                    'last_name' => $property->owner_last_name,
                    'email' => $property->owner_email,
                    'phone' => $property->owner_phone,
                ];

                $property->images = $images
                    ->get($property->id, collect())
                    ->values();

                $property->location = $locations->get($property->id);

                unset(
                    $property->owner_first_name,
                    $property->owner_last_name,
                    $property->owner_email,
                    $property->owner_phone
                );

                return $property;
            });

            return response()->json([
                'success' => true,
                'status' => $status,
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
                'message' => 'Failed to load properties for review',
                'details' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Approve a property.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        try {
            $admin = $request->attributes->get('auth_user');

            if (!$admin || empty($admin['id'])) {
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

            if ($property->moderation_status === 'approved') {
                return response()->json([
                    'success' => true,
                    'message' => 'Property is already approved',
                    'property_id' => $id,
                    'moderation_status' => 'approved',
                ]);
            }

            DB::table('properties')
                ->where('id', $id)
                ->update([
                    'moderation_status' => 'approved',
                    'rejection_reason' => null,
                    'reviewed_by' => (int) $admin['id'],
                    'reviewed_at' => now(),
                    'updated_at' => now(),
                ]);

            return response()->json([
                'success' => true,
                'message' => 'Property approved successfully',
                'property_id' => $id,
                'moderation_status' => 'approved',
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve property',
                'details' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Reject a property.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        try {
            $admin = $request->attributes->get('auth_user');

            if (!$admin || empty($admin['id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $validator = validator($request->all(), [
                'reason' => 'required|string|min:3|max:2000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rejection reason is required',
                    'errors' => $validator->errors(),
                ], 422);
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

            DB::table('properties')
                ->where('id', $id)
                ->update([
                    'moderation_status' => 'rejected',
                    'rejection_reason' => trim((string) $request->input('reason')),
                    'reviewed_by' => (int) $admin['id'],
                    'reviewed_at' => now(),
                    'updated_at' => now(),
                ]);

            return response()->json([
                'success' => true,
                'message' => 'Property rejected successfully',
                'property_id' => $id,
                'moderation_status' => 'rejected',
                'rejection_reason' => trim((string) $request->input('reason')),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reject property',
                'details' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
