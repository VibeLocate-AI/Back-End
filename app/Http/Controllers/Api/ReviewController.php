<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReviewController extends Controller
{
    /**
     * Add a new review.
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
                ->where('moderation_status', 'approved')
                ->exists();

            if (!$propertyExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Property not found',
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | Prevent duplicate review
            |--------------------------------------------------------------------------
            */

            $existingReview = DB::table('reviews')
                ->where('user_id', $userId)
                ->where('property_id', $propertyId)
                ->first();

            if ($existingReview) {
                return response()->json([
                    'success' => false,
                    'message' => 'You have already reviewed this property',
                ], 409);
            }

            $reviewId = DB::table('reviews')->insertGetId([
                'user_id' => $userId,
                'property_id' => $propertyId,
                'rating' => $request->input('rating'),
                'review' => $request->input('review'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $summary = $this->getReviewSummary($propertyId);

            return response()->json([
                'success' => true,
                'message' => 'Review added successfully',
                'review_id' => $reviewId,
                'property_id' => $propertyId,
                'user_rating' => (float) $request->input('rating'),
                'user_review' => $request->input('review'),
                'rating' => $summary['rating'],
                'total_reviews' => $summary['total_reviews'],
            ], 201);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to add review',
            ], 500);
        }
    }

    /**
     * Update current user's review.
     */
    public function update(Request $request, int $propertyId): JsonResponse
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
                ->where('moderation_status', 'approved')
                ->exists();

            if (!$propertyExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Property not found',
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | Get user's own review only
            |--------------------------------------------------------------------------
            */

            $existingReview = DB::table('reviews')
                ->where('user_id', $userId)
                ->where('property_id', $propertyId)
                ->first();

            if (!$existingReview) {
                return response()->json([
                    'success' => false,
                    'message' => 'Review not found',
                ], 404);
            }

            DB::table('reviews')
                ->where('id', $existingReview->id)
                ->where('user_id', $userId)
                ->update([
                    'rating' => $request->input('rating'),
                    'review' => $request->input('review'),
                    'updated_at' => now(),
                ]);

            $summary = $this->getReviewSummary($propertyId);

            return response()->json([
                'success' => true,
                'message' => 'Review updated successfully',
                'review_id' => $existingReview->id,
                'property_id' => $propertyId,
                'user_rating' => (float) $request->input('rating'),
                'user_review' => $request->input('review'),
                'rating' => $summary['rating'],
                'total_reviews' => $summary['total_reviews'],
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update review',
            ], 500);
        }
    }

    /**
     * Get current user's review for a property.
     */
    public function show(Request $request, int $propertyId): JsonResponse
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

            $review = DB::table('reviews')
                ->where('user_id', $userId)
                ->where('property_id', $propertyId)
                ->select([
                    'id',
                    'user_id',
                    'property_id',
                    'rating',
                    'review',
                    'created_at',
                    'updated_at',
                ])
                ->first();

            if (!$review) {
                return response()->json([
                    'success' => false,
                    'message' => 'Review not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $review,
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load review',
            ], 500);
        }
    }

    /**
     * Delete current user's review.
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

            $review = DB::table('reviews')
                ->where('user_id', $userId)
                ->where('property_id', $propertyId)
                ->first();

            if (!$review) {
                return response()->json([
                    'success' => false,
                    'message' => 'Review not found',
                ], 404);
            }

            DB::table('reviews')
                ->where('id', $review->id)
                ->where('user_id', $userId)
                ->delete();

            $summary = $this->getReviewSummary($propertyId);

            return response()->json([
                'success' => true,
                'message' => 'Review deleted successfully',
                'property_id' => $propertyId,
                'rating' => $summary['rating'],
                'total_reviews' => $summary['total_reviews'],
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete review',
            ], 500);
        }
    }

    /**
     * Calculate average rating and number of reviews.
     */
    private function getReviewSummary(int $propertyId): array
    {
        $averageRating = DB::table('reviews')
            ->where('property_id', $propertyId)
            ->avg('rating');

        $totalReviews = DB::table('reviews')
            ->where('property_id', $propertyId)
            ->count();

        return [
            'rating' => $averageRating !== null
                ? round((float) $averageRating, 1)
                : 0.0,

            'total_reviews' => $totalReviews,
        ];
    }
}