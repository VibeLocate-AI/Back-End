<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PropertyController extends Controller
{
    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'title' => 'required|string|max:255',

            'property_type' => [
                'required',
                'string',
                'in:apartment,villa,townhouse,penthouse,office',
            ],

            'listing_type' => [
                'required',
                'string',
                'in:for_sale,for_rent',
            ],

            'price' => 'required|numeric|min:0',

            'payment_period' => [
                'nullable',
                'string',
                'in:monthly,yearly',
                'required_if:listing_type,for_rent',
            ],

            'description' => 'nullable|string',

            'community' => 'required|string|max:255',
            'street_address' => 'nullable|string|max:255',
            'building_name' => 'nullable|string|max:255',

            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',

            'size' => 'required|numeric|min:0',
            'bedrooms' => 'required|integer|min:0',
            'bathrooms' => 'required|integer|min:0',

            'property_status' => [
                'required',
                'string',
                'in:ready,off_plan',
            ],

            'parking' => 'nullable|boolean',
            'swimming_pool' => 'nullable|boolean',
            'gym' => 'nullable|boolean',
            'security' => 'nullable|boolean',
            'balcony' => 'nullable|boolean',
            'pet_friendly' => 'nullable|boolean',
            'central_ac' => 'nullable|boolean',

            'cover_image' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',

            'gallery' => 'nullable|array|max:20',
            'gallery.*' => 'image|mimes:jpg,jpeg,png,webp|max:5120',

            'video_url' => 'nullable|url|max:2048',

            'agent_name' => 'required|string|max:255',
            'agent_phone' => 'required|string|max:30',
            'agent_email' => 'required|email|max:255',
        ]);

        DB::beginTransaction();

        try {

            // Upload cover image
            $coverPath = $request->file('cover_image')
                ->store('properties/covers', 'public');

            $property = Property::create([
                'user_id' => $user->id,

                'title' => $validated['title'],
                'property_type' => $validated['property_type'],
                'listing_type' => $validated['listing_type'],

                'price' => $validated['price'],
                'payment_period' => $validated['payment_period'] ?? null,

                'description' => $validated['description'] ?? null,

                'community' => $validated['community'],
                'street_address' => $validated['street_address'] ?? null,
                'building_name' => $validated['building_name'] ?? null,

                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,

                'size' => $validated['size'],
                'bedrooms' => $validated['bedrooms'],
                'bathrooms' => $validated['bathrooms'],

                'property_status' => $validated['property_status'],

                'parking' => $validated['parking'] ?? false,
                'swimming_pool' => $validated['swimming_pool'] ?? false,
                'gym' => $validated['gym'] ?? false,
                'security' => $validated['security'] ?? false,
                'balcony' => $validated['balcony'] ?? false,
                'pet_friendly' => $validated['pet_friendly'] ?? false,
                'central_ac' => $validated['central_ac'] ?? false,

                'cover_image' => $coverPath,

                'video_url' => $validated['video_url'] ?? null,

                'agent_name' => $validated['agent_name'],
                'agent_phone' => $validated['agent_phone'],
                'agent_email' => $validated['agent_email'],
            ]);

            // Upload gallery
            if ($request->hasFile('gallery')) {
                foreach ($request->file('gallery') as $image) {

                    $path = $image->store(
                        'properties/gallery',
                        'public'
                    );

                    $property->images()->create([
                        'path' => $path,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Property created successfully.',
                'data' => [
                    'property' => $property->load('images'),
                ],
            ], 201);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create property.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}