<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class InquiryController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | My Inquiries
    |--------------------------------------------------------------------------
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

            $inquiries = DB::table('property_leads as pl')
                ->join(
                    'properties as p',
                    'p.id',
                    '=',
                    'pl.property_id'
                )
                ->where('pl.user_id', $userId)
                ->whereNull('p.deleted_at')
                ->select([
                    'pl.id',
                    'pl.property_id',
                    'pl.full_name',
                    'pl.email',
                    'pl.phone',
                    'pl.message',
                    'pl.status',
                    'pl.created_at',

                    'p.title as property_title',
                    'p.slug as property_slug',
                    'p.price as property_price',
                    'p.currency as property_currency',
                ])
                ->orderByDesc('pl.created_at')
                ->get();

            $propertyIds = $inquiries
                ->pluck('property_id')
                ->unique()
                ->values()
                ->all();

            $images = collect();

            if (!empty($propertyIds)) {
                $images = DB::table('property_images')
                    ->whereIn(
                        'property_id',
                        $propertyIds
                    )
                    ->orderByDesc('is_primary')
                    ->orderBy('display_order')
                    ->get()
                    ->groupBy('property_id');
            }

            $inquiries = $inquiries->map(
                function ($inquiry) use ($images) {
                    $propertyImages = $images->get(
                        $inquiry->property_id,
                        collect()
                    );

                    $inquiry->property_image =
                        optional(
                            $propertyImages->first()
                        )->image_url;

                    $inquiry->property_price =
                        (float) $inquiry->property_price;

                    return $inquiry;
                }
            );

            return response()->json([
                'success' => true,
                'data' => $inquiries,
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load inquiries',
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Send Inquiry
    |--------------------------------------------------------------------------
    */

    public function store(
        Request $request,
        int $propertyId
    ): JsonResponse {
        $user = $request->attributes->get('auth_user');

        if (!$user || empty($user['id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $validator = validator(
            $request->all(),
            [
                'message' => [
                    'required',
                    'string',
                    'max:2000',
                ],
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid inquiry data',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $userId = (int) $user['id'];

            /*
            |--------------------------------------------------------------------------
            | Property
            |--------------------------------------------------------------------------
            */

            $property = DB::table('properties')
                ->where('id', $propertyId)
                ->whereNull('deleted_at')
                ->where(
                    'moderation_status',
                    'approved'
                )
                ->first();

            if (!$property) {
                return response()->json([
                    'success' => false,
                    'message' => 'Property not found',
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | User
            |--------------------------------------------------------------------------
            */

            $currentUser = DB::table('users')
                ->where('id', $userId)
                ->select([
                    'id',
                    'first_name',
                    'last_name',
                    'email',
                    'phone',
                ])
                ->first();

            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            $fullName = trim(
                ($currentUser->first_name ?? '')
                . ' '
                . ($currentUser->last_name ?? '')
            );

            /*
            |--------------------------------------------------------------------------
            | Create Inquiry
            |--------------------------------------------------------------------------
            */

            $inquiryId = DB::table('property_leads')
                ->insertGetId([
                    'property_id' => $propertyId,
                    'user_id' => $userId,

                    'full_name' => $fullName,

                    'email' => $currentUser->email,

                    'phone' =>
                        $currentUser->phone ?: null,

                    'message' => trim(
                        (string) $request->input(
                            'message'
                        )
                    ),

                    'status' => 'new',

                    'created_at' => now(),
                ]);

            $inquiry = DB::table('property_leads')
                ->where('id', $inquiryId)
                ->first();

            return response()->json([
                'success' => true,
                'message' =>
                    'Inquiry sent successfully',
                'data' => $inquiry,
            ], 201);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send inquiry',

                'details' =>
                    app()->environment('local')
                        ? $e->getMessage()
                        : null,
            ], 500);
        }
    }
}