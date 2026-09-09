<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class MapController extends Controller
{
    public function index(): JsonResponse
    {
        try {

            $properties = DB::table('properties as p')
                ->join(
                    'property_locations as pl',
                    'pl.property_id',
                    '=',
                    'p.id'
                )
                ->leftJoin(
                    'neighborhoods as n',
                    'n.id',
                    '=',
                    'pl.neighborhood_id'
                )
                ->leftJoin(
                    'property_types as pt',
                    'pt.id',
                    '=',
                    'p.type_id'
                )
                ->whereNull('p.deleted_at')
                ->whereNotNull('pl.latitude')
                ->whereNotNull('pl.longitude')
                ->select([
                    'p.id',
                    'p.title',
                    'p.slug',
                    'p.price',
                    'p.currency',
                    'p.bedrooms',
                    'p.bathrooms',
                    'p.area_sqft',
                    'p.type_id',
                    'pt.name as property_type',

                    'pl.address_line_1',
                    'pl.latitude',
                    'pl.longitude',
                    'pl.neighborhood_id',

                    'n.name as neighborhood',
                ])
                ->orderByDesc('p.id')
                ->get();

            $propertyIds = $properties
                ->pluck('id')
                ->all();

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

            $data = $properties->map(function ($property) use ($images) {

                $propertyImages = $images->get(
                    $property->id,
                    collect()
                );

                $primaryImage = $propertyImages
                    ->firstWhere('is_primary', 1)
                    ?? $propertyImages->first();

                return [
                    'id' => $property->id,

                    'title' => $property->title,
                    'slug' => $property->slug,

                    'price' => $property->price,
                    'currency' => $property->currency,

                    'bedrooms' => $property->bedrooms,
                    'bathrooms' => $property->bathrooms,
                    'area_sqft' => $property->area_sqft,

                    'type_id' => $property->type_id,
                    'property_type' => $property->property_type,

                    'latitude' => $property->latitude,
                    'longitude' => $property->longitude,

                    'address' => $property->address_line_1,

                    'neighborhood_id' => $property->neighborhood_id,
                    'neighborhood' => $property->neighborhood,

                    'primary_image' => $primaryImage
                        ? [
                            'id' => $primaryImage->id,
                            'image_url' => $primaryImage->image_url,
                        ]
                        : null,
                ];
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
                'message' => 'Failed to load map properties',
            ], 500);
        }
    }
}