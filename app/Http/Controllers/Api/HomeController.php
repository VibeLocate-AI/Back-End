<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyType;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index(Request $request)
    {
        /*
        |--------------------------------------------------------------------------
        | Property Types
        |--------------------------------------------------------------------------
        */

        $propertyTypes = PropertyType::query()
            ->select('id', 'name', 'slug')
            ->get();


        /*
        |--------------------------------------------------------------------------
        | Recommended Properties
        |--------------------------------------------------------------------------
        */

        $recommendedProperties = Property::query()
            ->where('status_id', 2)
            ->with([
                'images' => function ($query) {
                    $query->where('is_primary', true)
                        ->orWhere('display_order', 0);
                },
                'type:id,name,slug',
                'category:id,name,slug',
                'location.neighborhood.community.district.city'
            ])
            ->latest('listing_date')
            ->limit(10)
            ->get();


        /*
        |--------------------------------------------------------------------------
        | Nearby Properties
        |--------------------------------------------------------------------------
        */

        $nearbyProperties = Property::query()
            ->where('status_id', 2)
            ->with([
                'images',
                'type:id,name,slug',
                'location.neighborhood.community'
            ])
            ->latest('listing_date')
            ->limit(10)
            ->get();


        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'success' => true,

            'data' => [

                'location' => [
                    'city' => 'Dubai City',
                    'community' => 'Dubai Marina',
                    'neighborhood' => 'Marina Gate District',
                ],

                'search' => [
                    'placeholder' => 'AI Contextual Search...',
                ],

                'property_types' => $propertyTypes,

                'recommended_properties' =>
                    $recommendedProperties->map(
                        fn ($property) =>
                        $this->formatProperty($property)
                    ),

                'nearby_properties' =>
                    $nearbyProperties->map(
                        fn ($property) =>
                        $this->formatProperty($property)
                    ),
            ]
        ]);
    }


    private function formatProperty($property)
    {
        $location = $property->location;

        $neighborhood = $location?->neighborhood;

        $community = $neighborhood?->community;

        $district = $community?->district;

        $city = $district?->city;


        return [

            'id' => $property->id,

            'title' => $property->title,

            'slug' => $property->slug,

            'description' => $property->description,

            'price' => $property->price,

            'currency' => $property->currency,

            'rent_frequency' =>
                $property->rent_frequency,

            'bedrooms' => $property->bedrooms,

            'bathrooms' => $property->bathrooms,

            'area_sqft' => $property->area_sqft,

            'type' => $property->type ? [
                'id' => $property->type->id,
                'name' => $property->type->name,
                'slug' => $property->type->slug,
            ] : null,

            'category' => $property->category ? [
                'id' => $property->category->id,
                'name' => $property->category->name,
                'slug' => $property->category->slug,
            ] : null,

            'image' =>
                $property->images->first()?->image_url,

            'images' =>
                $property->images
                    ->pluck('image_url')
                    ->values(),

            'location' => [

                'address' =>
                    $location?->address_line_1,

                'building' =>
                    $location?->building_name,

                'latitude' =>
                    $location?->latitude,

                'longitude' =>
                    $location?->longitude,

                'neighborhood' =>
                    $neighborhood?->name,

                'community' =>
                    $community?->name,

                'district' =>
                    $district?->name,

                'city' =>
                    $city?->name,
            ],
        ];
    }

    
}