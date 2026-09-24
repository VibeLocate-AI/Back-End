<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VibeAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AIContextualSearchController extends Controller
{
    public function __construct(
        private VibeAiService $aiService
    ) {}

    public function search(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'query' => ['required', 'string', 'min:2', 'max:500'],
                'language' => ['nullable', 'string', 'in:en,ar'],
            ]);

            $searchText = trim($validated['query']);
            $language = $validated['language'] ?? $this->detectLanguage($searchText);

            /*
            |--------------------------------------------------------------------------
            | 1. Let FastAPI understand the natural-language request
            |--------------------------------------------------------------------------
            */

            $aiResponse = $this->aiService->parseSearchQuery(
                $searchText,
                $language
            );

            if (!$aiResponse) {
                return response()->json([
                    'success' => false,
                    'message' => $language === 'ar'
                        ? 'تعذر تحليل طلب البحث حاليًا'
                        : 'Could not understand the search request',
                ], 503);
            }

            /*
            |--------------------------------------------------------------------------
            | 2. Extract AI filters
            |--------------------------------------------------------------------------
            */

            $propertyType = $aiResponse['property_type'] ?? null;
            $maxBudget = $aiResponse['max_budget'] ?? null;
            $minBudget = $aiResponse['min_budget'] ?? null;
            $currency = $aiResponse['budget_currency'] ?? null;

            $minBedrooms = $aiResponse['min_bedrooms'] ?? null;
            $maxBedrooms = $aiResponse['max_bedrooms'] ?? null;

            $locationHint = $aiResponse['location_hint'] ?? null;

            $actionType =
                $aiResponse['action_type']
                ?? $aiResponse['listing_type']
                ?? $aiResponse['purpose']
                ?? null;

            $confidence = $aiResponse['confidence'] ?? null;

            $needsClarification =
                (bool) ($aiResponse['needs_clarification'] ?? false);

            $vibeTags = $aiResponse['vibe_tags'] ?? [];
            $requiredAmenities =
                $aiResponse['required_amenities'] ?? [];

            /*
            |--------------------------------------------------------------------------
            | 3. Resolve property type from our own database
            |--------------------------------------------------------------------------
            */

            $typeId = null;
            $resolvedPropertyType = null;

            if (!empty($propertyType)) {
                $type = DB::table('property_types')
                    ->whereRaw(
                        'LOWER(name) = ?',
                        [mb_strtolower(trim($propertyType))]
                    )
                    ->first();

                if ($type) {
                    $typeId = (int) $type->id;
                    $resolvedPropertyType = $type->name;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | 4. Resolve location in English or Arabic
            |--------------------------------------------------------------------------
            */

            $neighborhoodId = null;
            $neighborhoodEn = null;
            $neighborhoodAr = null;

            if (!empty($locationHint)) {
                $normalizedLocation = mb_strtolower(trim($locationHint));

                $neighborhood = DB::table('neighborhoods as n')
                    ->leftJoin(
                        'neighborhood_translations as nt_en',
                        function ($join) {
                            $join->on(
                                'nt_en.neighborhood_id',
                                '=',
                                'n.id'
                            )->where(
                                'nt_en.language_code',
                                '=',
                                'en'
                            );
                        }
                    )
                    ->leftJoin(
                        'neighborhood_translations as nt_ar',
                        function ($join) {
                            $join->on(
                                'nt_ar.neighborhood_id',
                                '=',
                                'n.id'
                            )->where(
                                'nt_ar.language_code',
                                '=',
                                'ar'
                            );
                        }
                    )
                    ->where(function ($query) use ($normalizedLocation) {
                        $query
                            ->whereRaw(
                                'LOWER(n.name) = ?',
                                [$normalizedLocation]
                            )
                            ->orWhereRaw(
                                'LOWER(nt_en.name) = ?',
                                [$normalizedLocation]
                            )
                            ->orWhereRaw(
                                'LOWER(nt_ar.name) = ?',
                                [$normalizedLocation]
                            )
                            ->orWhereRaw(
                                'LOWER(n.name) LIKE ?',
                                ['%' . $normalizedLocation . '%']
                            )
                            ->orWhereRaw(
                                'LOWER(nt_en.name) LIKE ?',
                                ['%' . $normalizedLocation . '%']
                            )
                            ->orWhereRaw(
                                'LOWER(nt_ar.name) LIKE ?',
                                ['%' . $normalizedLocation . '%']
                            );
                    })
                    ->select([
                        'n.id',
                        'n.name',
                        DB::raw(
                            'COALESCE(nt_en.name, n.name) as name_en'
                        ),
                        'nt_ar.name as name_ar',
                    ])
                    ->first();

                if ($neighborhood) {
                    $neighborhoodId = (int) $neighborhood->id;
                    $neighborhoodEn =
                        $neighborhood->name_en
                        ?: $neighborhood->name;

                    $neighborhoodAr =
                        $neighborhood->name_ar;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | 5. Normalize action type
            |--------------------------------------------------------------------------
            */

            $normalizedActionType = null;

            if (!empty($actionType)) {
                $action = mb_strtolower(trim((string) $actionType));

                if (in_array(
                    $action,
                    ['buy', 'sale', 'purchase', 'for sale'],
                    true
                )) {
                    $normalizedActionType = 'buy';
                } elseif (in_array(
                    $action,
                    ['rent', 'rental', 'lease', 'for rent'],
                    true
                )) {
                    $normalizedActionType = 'rent';
                } elseif (in_array(
                    $action,
                    ['booking', 'book'],
                    true
                )) {
                    $normalizedActionType = 'booking';
                }
            }

            /*
            |--------------------------------------------------------------------------
            | 6. Build Laravel database query
            |--------------------------------------------------------------------------
            */

            $propertiesQuery = DB::table('properties as p')
                ->leftJoin(
                    'property_types as pt',
                    'pt.id',
                    '=',
                    'p.type_id'
                )
                ->leftJoin(
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
                    'neighborhood_translations as nt_en',
                    function ($join) {
                        $join->on(
                            'nt_en.neighborhood_id',
                            '=',
                            'n.id'
                        )->where(
                            'nt_en.language_code',
                            '=',
                            'en'
                        );
                    }
                )
                ->leftJoin(
                    'neighborhood_translations as nt_ar',
                    function ($join) {
                        $join->on(
                            'nt_ar.neighborhood_id',
                            '=',
                            'n.id'
                        )->where(
                            'nt_ar.language_code',
                            '=',
                            'ar'
                        );
                    }
                )
                ->whereNull('p.deleted_at');

            /*
            |--------------------------------------------------------------------------
            | 7. Apply AI filters
            |--------------------------------------------------------------------------
            */

            if ($typeId !== null) {
                $propertiesQuery->where(
                    'p.type_id',
                    $typeId
                );
            }

            if ($minBudget !== null) {
                $propertiesQuery->where(
                    'p.price',
                    '>=',
                    (float) $minBudget
                );
            }

            if ($maxBudget !== null) {
                $propertiesQuery->where(
                    'p.price',
                    '<=',
                    (float) $maxBudget
                );
            }

            if ($minBedrooms !== null) {
                $propertiesQuery->where(
                    'p.bedrooms',
                    '>=',
                    (int) $minBedrooms
                );
            }

            if ($maxBedrooms !== null) {
                $propertiesQuery->where(
                    'p.bedrooms',
                    '<=',
                    (int) $maxBedrooms
                );
            }

            if ($neighborhoodId !== null) {
                $propertiesQuery->where(
                    'pl.neighborhood_id',
                    $neighborhoodId
                );
            }

            if ($normalizedActionType !== null) {
                $propertiesQuery->where(
                    'p.action_type',
                    $normalizedActionType
                );
            }

            /*
            |--------------------------------------------------------------------------
            | 8. Get matching properties
            |--------------------------------------------------------------------------
            */

            $properties = $propertiesQuery
                ->select([
                    'p.id',
                    'p.owner_id',
                    'p.agency_id',
                    'p.type_id',
                    'p.category_id',
                    'p.status_id',
                    'p.property_condition',
                    'p.action_type',
                    'p.is_featured',
                    'p.title',
                    'p.slug',
                    'p.description',
                    'p.virtual_tour_url',
                    'p.price',
                    'p.currency',
                    'p.rent_frequency',
                    'p.area_sqft',
                    'p.bedrooms',
                    'p.bathrooms',
                    'p.floor_number',
                    'p.total_floors',
                    'p.year_built',
                    'p.is_furnished',
                    'p.availability_date',
                    'p.listing_date',

                    'pt.name as property_type',

                    'pl.id as location_id',
                    'pl.address_line_1',
                    'pl.address_line_2',
                    'pl.building_name',
                    'pl.latitude',
                    'pl.longitude',
                    'pl.neighborhood_id',
                    'pl.street_id',

                    'n.name as neighborhood_name',

                    DB::raw(
                        'COALESCE(nt_en.name, n.name) as neighborhood_en'
                    ),

                    'nt_ar.name as neighborhood_ar',
                ])
                ->orderByDesc('p.is_featured')
                ->orderByDesc('p.listing_date')
                ->limit(50)
                ->get();

            /*
            |--------------------------------------------------------------------------
            | 9. Arabic property translations
            |--------------------------------------------------------------------------
            */

            $propertyIds = $properties
                ->pluck('id')
                ->values()
                ->all();

            $propertyTranslations = collect();

            if (
                $language === 'ar'
                && !empty($propertyIds)
            ) {
                $propertyTranslations = DB::table(
                    'property_translations'
                )
                    ->whereIn(
                        'property_id',
                        $propertyIds
                    )
                    ->where(
                        'language_code',
                        'ar'
                    )
                    ->get()
                    ->keyBy('property_id');
            }

            /*
            |--------------------------------------------------------------------------
            | 10. Load property images
            |--------------------------------------------------------------------------
            */

            $images = collect();

            if (!empty($propertyIds)) {
                $images = DB::table('property_images')
                    ->whereIn(
                        'property_id',
                        $propertyIds
                    )
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

            /*
            |--------------------------------------------------------------------------
            | 11. Format results
            |--------------------------------------------------------------------------
            */

            $properties = $properties
                ->map(function ($property) use (
                    $language,
                    $propertyTranslations,
                    $images
                ) {
                    if ($language === 'ar') {
                        $translation =
                            $propertyTranslations->get(
                                $property->id
                            );

                        if ($translation) {
                            if (!empty($translation->title)) {
                                $property->title =
                                    $translation->title;
                            }

                            if (!empty($translation->description)) {
                                $property->description =
                                    $translation->description;
                            }
                        }
                    }

                    $propertyImages =
                        $images->get(
                            $property->id,
                            collect()
                        );

                    $primaryImage =
                        $propertyImages->firstWhere(
                            'is_primary',
                            1
                        )
                        ?? $propertyImages->first();

                    $property->primary_image =
                        $primaryImage
                            ? $primaryImage->image_url
                            : null;

                    $property->images =
                        $propertyImages
                            ->pluck('image_url')
                            ->values();

                    $property->location = [
                        'id' =>
                            $property->location_id,

                        'property_id' =>
                            $property->id,

                        'address_line_1' =>
                            $property->address_line_1,

                        'address_line_2' =>
                            $property->address_line_2,

                        'building_name' =>
                            $property->building_name,

                        'latitude' =>
                            $property->latitude,

                        'longitude' =>
                            $property->longitude,

                        'neighborhood_id' =>
                            $property->neighborhood_id,

                        'neighborhood_name' =>
                            $property->neighborhood_name,

                        'neighborhood_en' =>
                            $property->neighborhood_en,

                        'neighborhood_ar' =>
                            $property->neighborhood_ar,

                        'street_id' =>
                            $property->street_id,
                    ];

                    unset(
                        $property->location_id,
                        $property->address_line_1,
                        $property->address_line_2,
                        $property->building_name,
                        $property->latitude,
                        $property->longitude,
                        $property->neighborhood_id,
                        $property->neighborhood_name,
                        $property->neighborhood_en,
                        $property->neighborhood_ar,
                        $property->street_id
                    );

                    return $property;
                })
                ->values();

            /*
            |--------------------------------------------------------------------------
            | 12. Response
            |--------------------------------------------------------------------------
            */
$clarificationMessage = null;

if ($needsClarification) {
    $clarificationMessage = $language === 'ar'
        ? 'يرجى تحديد تفاصيل أكثر مثل الشراء أو الإيجار، المنطقة والميزانية.'
        : 'Please provide more details such as buy or rent, location, and budget.';
}
            return response()->json([
                'success' => true,

                'query' => $searchText,

                'language' => $language,

                'ai_understanding' => [
                    'property_type' =>
                        $resolvedPropertyType
                        ?? $propertyType,

                    'type_id' =>
                        $typeId,

                    'min_budget' =>
                        $minBudget,

                    'max_budget' =>
                        $maxBudget,

                    'budget_currency' =>
                        $currency,

                    'min_bedrooms' =>
                        $minBedrooms,

                    'max_bedrooms' =>
                        $maxBedrooms,

                    'location_hint' =>
                        $locationHint,

                    'neighborhood_id' =>
                        $neighborhoodId,

                    'neighborhood_en' =>
                        $neighborhoodEn,

                    'neighborhood_ar' =>
                        $neighborhoodAr,

                    'action_type' =>
                        $normalizedActionType,

                    'vibe_tags' =>
                        $vibeTags,

                    'required_amenities' =>
                        $requiredAmenities,

                    'confidence' =>
                        $confidence,

                    'needs_clarification' =>
                        $needsClarification,
                        'clarification_message' =>
    $clarificationMessage,
                ],

                'total_results' =>
                    $properties->count(),

                'properties' =>
                    $properties,
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' =>
                    'AI contextual search failed',
            ], 500);
        }
    }

    private function detectLanguage(
        string $text
    ): string {
        return preg_match(
            '/[\x{0600}-\x{06FF}]/u',
            $text
        )
            ? 'ar'
            : 'en';
    }
}