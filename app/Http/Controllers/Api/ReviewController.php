<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReviewController extends Controller
{
    public function store(Request $request, int $propertyId): JsonResponse
    {
        $user = $request->attributes->get('auth_user');

        if (!$user || empty($user['id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $validator = validator($request->all(), [
            'rating' => 'required|numeric|min:1|max:5',
            'review' => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
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

            $existingReview = DB::table('reviews')
                ->where('user_id', $userId)
                ->where('property_id', $propertyId)
                ->first();

            if ($existingReview) {
                DB::table('reviews')
                    ->where('id', $existingReview->id)
                    ->update([
                        'rating' => $request->input('rating'),
                        'review' => $request->input('review'),
                        'updated_at' => now(),
                    ]);

                $message = 'Review updated successfully';
            } else {
                DB::table('reviews')->insert([
                    'user_id' => $userId,
                    'property_id' => $propertyId,
                    'rating' => $request->input('rating'),
                    'review' => $request->input('review'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $message = 'Review added successfully';
            }

            $averageRating = DB::table('reviews')
                ->where('property_id', $propertyId)
                ->avg('rating');

            $totalReviews = DB::table('reviews')
                ->where('property_id', $propertyId)
                ->count();

            return response()->json([
                'success' => true,
                'message' => $message,
                'property_id' => $propertyId,
                'rating' => $averageRating !== null
                    ? round((float) $averageRating, 1)
                    : 0.0,
                'total_reviews' => $totalReviews,
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save review',
            ], 500);
        }
    }

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

            $deleted = DB::table('reviews')
                ->where('user_id', $userId)
                ->where('property_id', $propertyId)
                ->delete();

            if (!$deleted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Review not found',
                ], 404);
            }

            $averageRating = DB::table('reviews')
                ->where('property_id', $propertyId)
                ->avg('rating');

            $totalReviews = DB::table('reviews')
                ->where('property_id', $propertyId)
                ->count();

            return response()->json([
                'success' => true,
                'message' => 'Review deleted successfully',
                'property_id' => $propertyId,
                'rating' => $averageRating !== null
                    ? round((float) $averageRating, 1)
                    : 0.0,
                'total_reviews' => $totalReviews,
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete review',
            ], 500);
        }
    }
}