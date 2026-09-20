<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class HomeController extends Controller
{
    public function featuredProperties(Request $request, string $lang): JsonResponse
    {
        try {
            $lang = $this->language($lang);

            $properties = DB::table('properties as p')
                ->whereNull('p.deleted_at')
                ->where('p.is_featured', 1)
                ->select($this->propertyColumns())
                ->orderByDesc('p.listing_date')
                ->limit(12)
                ->get();

            return $this->propertySectionResponse(
                'featured_properties',
                $properties,
                $lang
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load featured properties',
            ], 500);
        }
    }

    public function recommendedProperties(Request $request, string $lang): JsonResponse
    {
        try {
            $lang = $this->language($lang);

            $properties = DB::table('properties as p')
                ->whereNull('p.deleted_at')
                ->where('p.is_featured', 0)
                ->select($this->propertyColumns())
                ->orderByDesc('p.listing_date')
                ->limit(12)
                ->get();

            return $this->propertySectionResponse(
                'recommended_properties',
                $properties,
                $lang
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load recommended properties',
            ], 500);
        }
    }

    public function popularAreas(Request $request, string $lang): JsonResponse
    {
        try {
            $lang = $this->language($lang);

            $areas = DB::table('neighborhoods as n')
                ->join(
                    'property_locations as pl',
                    'pl.neighborhood_id',
                    '=',
                    'n.id'
                )
                ->join(
                    'properties as p',
                    'p.id',
                    '=',
                    'pl.property_id'
                )
                ->whereNull('p.deleted_at')
                ->select([
                    'n.id',
                    'n.name',
                    DB::raw('COUNT(DISTINCT p.id) as properties_count'),
                ])
                ->groupBy('n.id', 'n.name')
                ->orderByDesc('properties_count')
                ->limit(4)
                ->get();

            $areaIds = $areas->pluck('id')->all();
            $images = collect();
            $translations = collect();

            if (!empty($areaIds)) {
                $images = DB::table('property_locations as pl')
                    ->join('properties as p', 'p.id', '=', 'pl.property_id')
                    ->join('property_images as pi', 'pi.property_id', '=', 'p.id')
                    ->whereIn('pl.neighborhood_id', $areaIds)
                    ->whereNull('p.deleted_at')
                    ->where('pi.is_primary', 1)
                    ->select([
                        'pl.neighborhood_id',
                        'p.id as property_id',
                        'pi.image_url',
                    ])
                    ->orderBy('p.id')
                    ->get()
                    ->groupBy('neighborhood_id');

                if ($lang === 'ar') {
                    $translations = DB::table('neighborhood_translations')
                        ->whereIn('neighborhood_id', $areaIds)
                        ->where('language_code', 'ar')
                        ->select(['neighborhood_id', 'name'])
                        ->get()
                        ->keyBy('neighborhood_id');
                }
            }

            $areas = $areas->map(
                function ($area) use ($images, $translations, $lang) {
                    if ($lang === 'ar') {
                        $translation = $translations->get($area->id);

                        if ($translation) {
                            $area->name = $translation->name;
                        }
                    }

                    $image = $images
                        ->get($area->id, collect())
                        ->first();

                    $area->image_url = $image?->image_url;

                    return $area;
                }
            )->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'language' => $lang,
                    'popular_areas' => $areas,
                ],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load popular areas',
            ], 500);
        }
    }

    public function topAgents(Request $request, string $lang): JsonResponse
    {
        try {
            $lang = $this->language($lang);

            $agents = DB::table('agency_agents as aa')
                ->join('users as u', 'u.id', '=', 'aa.user_id')
                ->leftJoin('user_profiles as up', 'up.user_id', '=', 'u.id')
                ->leftJoin('user_translations as ut', function ($join) use ($lang) {
                    $join->on('ut.user_id', '=', 'u.id')
                        ->where('ut.language_code', '=', $lang);
                })
                ->join('agencies as a', 'a.id', '=', 'aa.agency_id')
                ->whereNull('u.deleted_at')
                ->select([
                    'u.id as user_id',
                    'u.first_name',
                    'u.last_name',
                    'u.phone',
                    'up.avatar_url',
                    'ut.first_name as translated_first_name',
                    'ut.last_name as translated_last_name',
                    'aa.is_manager',
                    'a.id as agency_id',
                    'a.name as agency_name',
                    'a.logo_url as agency_logo',
                ])
                ->orderByDesc('aa.is_manager')
                ->orderBy('aa.joined_at')
                ->limit(10)
                ->get()
                ->map(function ($agent) {
                    return [
                        'user_id' => $agent->user_id,
                        'name' => trim(
                            ($agent->translated_first_name ?? $agent->first_name)
                            . ' '
                            . ($agent->translated_last_name ?? $agent->last_name)
                        ),
                        'phone' => $agent->phone,
                        'avatar_url' => $agent->avatar_url,
                        'is_manager' => (bool) $agent->is_manager,
                        'agency' => [
                            'id' => $agent->agency_id,
                            'name' => $agent->agency_name,
                            'logo_url' => $agent->agency_logo,
                        ],
                    ];
                })
                ->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'language' => $lang,
                    'top_agents' => $agents,
                ],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load top agents',
            ], 500);
        }
    }

    private function language(string $lang): string
    {
        abort_unless(in_array($lang, ['en', 'ar'], true), 404);

        return $lang;
    }

    private function propertyColumns(): array
    {
        return [
            'p.id',
            'p.type_id',
            'p.category_id',
            'p.status_id',
            'p.is_featured',
            'p.title',
            'p.slug',
            'p.description',
            'p.price',
            'p.currency',
            'p.rent_frequency',
            'p.area_sqft',
            'p.bedrooms',
            'p.bathrooms',
            'p.is_furnished',
            'p.availability_date',
            'p.listing_date',
        ];
    }

    private function propertySectionResponse(
        string $key,
        $properties,
        string $lang
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data' => [
                'language' => $lang,
                $key => $this->prepareProperties($properties, $lang),
            ],
        ]);
    }

    private function prepareProperties($properties, string $lang)
    {
        $propertyIds = $properties->pluck('id')->values()->all();

        if (empty($propertyIds)) {
            return $properties->values();
        }

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

        $locations = DB::table('property_locations')
            ->whereIn('property_id', $propertyIds)
            ->select([
                'id',
                'property_id',
                'address_line_1',
                'address_line_2',
                'building_name',
                'latitude',
                'longitude',
                'neighborhood_id',
                'street_id',
            ])
            ->get()
            ->keyBy('property_id');

        $features = DB::table('property_feature_values as pfv')
            ->join('property_features as pf', 'pf.id', '=', 'pfv.feature_id')
            ->whereIn('pfv.property_id', $propertyIds)
            ->select([
                'pfv.property_id',
                'pf.id',
                'pf.name',
                'pf.category',
                'pfv.feature_value',
            ])
            ->orderBy('pf.id')
            ->get()
            ->groupBy('property_id');

        $translations = collect();
        $locationTranslations = collect();

        if ($lang === 'ar') {
            $translations = DB::table('property_translations')
                ->whereIn('property_id', $propertyIds)
                ->where('language_code', 'ar')
                ->select([
                    'property_id',
                    'title',
                    'description',
                ])
                ->get()
                ->keyBy('property_id');

            $locationIds = $locations
                ->pluck('id')
                ->filter()
                ->values()
                ->all();

            if (!empty($locationIds)) {
                $locationTranslations = DB::table(
                    'property_location_translations'
                )
                    ->whereIn('property_location_id', $locationIds)
                    ->where('language_code', 'ar')
                    ->select([
                        'property_location_id',
                        'address_line_1',
                        'address_line_2',
                        'building_name',
                    ])
                    ->get()
                    ->keyBy('property_location_id');
            }
        }

        return $properties->map(
            function ($property) use (
                $images,
                $locations,
                $features,
                $translations,
                $locationTranslations,
                $lang
            ) {
                if ($lang === 'ar') {
                    $translation = $translations->get($property->id);

                    if ($translation) {
                        $property->title = $translation->title;
                        $property->description = $translation->description;
                    }
                }

                $propertyImages = $images
                    ->get($property->id, collect())
                    ->values();

                $property->primary_image =
                    $propertyImages->firstWhere('is_primary', 1)
                    ?? $propertyImages->first();

                $property->images = $propertyImages;

                $property->location = $locations->get($property->id);

                if ($lang === 'ar' && $property->location) {
                    $translation = $locationTranslations
                        ->get($property->location->id);

                    if ($translation) {
                        $property->location->address_line_1 =
                            $translation->address_line_1;
                        $property->location->address_line_2 =
                            $translation->address_line_2;
                        $property->location->building_name =
                            $translation->building_name;
                    }
                }

                $property->features = $features
                    ->get($property->id, collect())
                    ->values();

                return $property;
            }
        )->values();
    }
}