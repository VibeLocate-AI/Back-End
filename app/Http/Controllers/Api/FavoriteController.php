<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class FavoriteController extends Controller
{
    /**
     * Get authenticated user's favorite properties.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('auth_user');

        if (!$user || empty($user['id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        try {
            $userId = (int) $user['id'];

            $favorites = DB::table('favorites as f')
                ->join('properties as p', 'p.id', '=', 'f.property_id')
                ->leftJoin('property_types as pt', 'pt.id', '=', 'p.type_id')
                ->leftJoin('property_categories as pc', 'pc.id', '=', 'p.category_id')
                ->leftJoin('property_locations as pl', 'pl.property_id', '=', 'p.id')
                ->leftJoin('neighborhoods as n', 'n.id', '=', 'pl.neighborhood_id')
                ->where('f.user_id', $userId)
                ->whereNull('p.deleted_at')
                ->select([
                    'p.id',
                    'p.title',
                    'p.slug',
                    'p.price',
                    'p.currency',
                    'p.bedrooms',
                    'p.bathrooms',
                    'p.area_sqft',
                    'p.action_type',
                    'p.type_id',
                    'pt.name as property_type',
                    'p.category_id',
                    'pc.name as category_name',
                    'pl.latitude',
                    'pl.longitude',
                    'pl.address_line_1 as address',
                    'pl.neighborhood_id',
                    'n.name as neighborhood',
                    'f.created_at as favorited_at',
                ])
                ->orderByDesc('f.created_at')
                ->get();

            $propertyIds = $favorites->pluck('id')->all();

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

            $data = $favorites->map(function ($property) use ($images) {
                $propertyImages = $images->get($property->id, collect());

                $primaryImage =
                    $propertyImages->firstWhere('is_primary', 1)
                    ?? $propertyImages->first();

                $property->primary_image = $primaryImage
                    ? [
                        'id' => $primaryImage->id,
                        'image_url' => $primaryImage->image_url,
                    ]
                    : null;

                $property->is_favorite = true;

                return $property;
            });

            return response()->json([
                'success' => true,
                'total' => $data->count(),
                'data' => $data,
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load favorites',
            ], 500);
        }
    }

    /**
     * Add property to favorites.
     */
    public function store(Request $request, int $propertyId): JsonResponse
    {
        $user = $request->attributes->get('auth_user');

        if (!$user || empty($user['id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        try {
            $userId = (int) $user['id'];

            $propertyExists = DB::table('properties')
                ->where('id', $propertyId)
                ->whereNull('deleted_at')
                ->exists();

            if (!$propertyExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Property not found',
                ], 404);
            }

            DB::table('favorites')->insertOrIgnore([
                'user_id' => $userId,
                'property_id' => $propertyId,
                'created_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Property added to favorites',
                'property_id' => $propertyId,
                'is_favorite' => true,
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to add favorite',
            ], 500);
        }
    }

    /**
     * Remove property from favorites.
     */
    public function destroy(Request $request, int $propertyId): JsonResponse
    {
        $user = $request->attributes->get('auth_user');

        if (!$user || empty($user['id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        try {
            $userId = (int) $user['id'];

            DB::table('favorites')
                ->where('user_id', $userId)
                ->where('property_id', $propertyId)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Property removed from favorites',
                'property_id' => $propertyId,
                'is_favorite' => false,
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove favorite',
            ], 500);
        }
    }
}