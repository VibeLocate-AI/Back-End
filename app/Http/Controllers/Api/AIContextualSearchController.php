<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VibeAiService;
use App\Services\ContextualSearchParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AIContextualSearchController extends Controller
{
    private const POI_RADIUS_KM = 1.0;
    private const RESULT_LIMIT = 50;
public function __construct(
    private VibeAiService $aiService,
    private ContextualSearchParser $localParser
) {}

    public function search(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'query' => [
                    'required',
                    'string',
                    'min:2',
                    'max:500',
                ],

                'language' => [
                    'nullable',
                    'string',
                    'in:en,ar',
                ],
            ]);

            $searchText =
                trim(
                    $validated[
                        'query'
                    ]
                );

            $language =
                $validated[
                    'language'
                ]
                ??
                $this
                    ->detectLanguage(
                        $searchText
                    );

          /*
|--------------------------------------------------------------------------
| Parse query locally in Laravel
|--------------------------------------------------------------------------
|
| Laravel is capable of understanding the explicit real-estate constraints
| itself. The external AI parser remains optional and can enrich the result,
| but a failure or empty AI response must never cause all properties to be
| returned.
|
*/

$localResponse =
    $this
        ->localParser
        ->parse(
            $searchText,
            $language
        );

/*
|--------------------------------------------------------------------------
| Optional AI enrichment
|--------------------------------------------------------------------------
*/

$remoteResponse = null;

try {
    $remoteResponse =
        $this
            ->aiService
            ->parseSearchQuery(
                $searchText,
                $language
            );
} catch (Throwable $e) {
    /*
     * The contextual search must continue using the Laravel parser.
     */
    $remoteResponse = null;
}

/*
|--------------------------------------------------------------------------
| Start with Laravel understanding
|--------------------------------------------------------------------------
*/

$aiResponse =
    $localResponse;

/*
|--------------------------------------------------------------------------
| Merge valid AI values
|--------------------------------------------------------------------------
|
| AI is allowed to add information Laravel did not recognize.
| It is NOT allowed to erase valid Laravel values by returning null.
|
*/

if (is_array($remoteResponse)) {

    $mergeKeys = [
        'property_type',
        'min_budget',
        'max_budget',
        'budget_currency',
        'min_bedrooms',
        'max_bedrooms',
        'furnishing_status',
        'location_hint',
        'action_type',
        'listing_type',
        'purpose',
    ];

    foreach ($mergeKeys as $key) {

        if (
            (
                !array_key_exists(
                    $key,
                    $aiResponse
                )
                ||
                $aiResponse[$key] === null
                ||
                $aiResponse[$key] === ''
            )
            &&
            array_key_exists(
                $key,
                $remoteResponse
            )
            &&
            $remoteResponse[$key] !== null
            &&
            $remoteResponse[$key] !== ''
        ) {
            $aiResponse[$key] =
                $remoteResponse[$key];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Merge vibe tags
    |--------------------------------------------------------------------------
    */

    $localVibes =
        is_array(
            $aiResponse['vibe_tags']
            ?? null
        )
            ? $aiResponse['vibe_tags']
            : [];

    $remoteVibes =
        is_array(
            $remoteResponse['vibe_tags']
            ?? null
        )
            ? $remoteResponse['vibe_tags']
            : [];

    $aiResponse['vibe_tags'] =
        array_values(
            array_unique(
                array_merge(
                    $localVibes,
                    $remoteVibes
                )
            )
        );

    /*
    |--------------------------------------------------------------------------
    | Merge amenities
    |--------------------------------------------------------------------------
    */

    $localAmenities =
        is_array(
            $aiResponse['required_amenities']
            ?? null
        )
            ? $aiResponse['required_amenities']
            : [];

    $remoteAmenities =
        is_array(
            $remoteResponse['required_amenities']
            ?? null
        )
            ? $remoteResponse['required_amenities']
            : [];

    $aiResponse['required_amenities'] =
        array_values(
            array_merge(
                $localAmenities,
                $remoteAmenities
            )
        );

    /*
    |--------------------------------------------------------------------------
    | Confidence
    |--------------------------------------------------------------------------
    */

    $localConfidence =
        (float) (
            $aiResponse['confidence']
            ?? 0
        );

    $remoteConfidence =
        (float) (
            $remoteResponse['confidence']
            ?? 0
        );

    $aiResponse['confidence'] =
        max(
            $localConfidence,
            $remoteConfidence
        );
/*
|--------------------------------------------------------------------------
| Clarification
|--------------------------------------------------------------------------
|
| Laravel local parser is authoritative for explicit criteria.
| Do not allow the remote AI to mark a clearly understood query
| as ambiguous just because it returned needs_clarification = true.
*/

$localNeedsClarification =
    (bool) (
        $localResponse[
            'needs_clarification'
        ]
        ?? false
    );

$hasUsefulCriteria =
    !empty(
        $aiResponse[
            'property_type'
        ]
    )
    ||
    (
        $aiResponse[
            'min_budget'
        ]
        ?? null
    ) !== null
    ||
    (
        $aiResponse[
            'max_budget'
        ]
        ?? null
    ) !== null
    ||
    (
        $aiResponse[
            'min_bedrooms'
        ]
        ?? null
    ) !== null
    ||
    (
        $aiResponse[
            'max_bedrooms'
        ]
        ?? null
    ) !== null
    ||
    !empty(
        $aiResponse[
            'location_hint'
        ]
    )
    ||
    !empty(
        $aiResponse[
            'action_type'
        ]
    )
    ||
    !empty(
        $aiResponse[
            'listing_type'
        ]
    )
    ||
    !empty(
        $aiResponse[
            'purpose'
        ]
    )
    ||
    !empty(
        $aiResponse[
            'required_amenities'
        ]
    )
    ||
!empty(
    $aiResponse[
        'vibe_tags'
    ]
)
||
!empty(
    $aiResponse[
        'furnishing_status'
    ]
);

$aiResponse['needs_clarification'] =
    $localNeedsClarification
    ||
    !$hasUsefulCriteria;
}

            $propertyType =
                $aiResponse[
                    'property_type'
                ] ?? null;

            $minBudget =
                $aiResponse[
                    'min_budget'
                ] ?? null;

            $maxBudget =
                $aiResponse[
                    'max_budget'
                ] ?? null;

            $currency =
                $aiResponse[
                    'budget_currency'
                ] ?? null;

            $minBedrooms =
                $aiResponse[
                    'min_bedrooms'
                ] ?? null;

            $maxBedrooms =
                $aiResponse[
                    'max_bedrooms'
                ] ?? null;

            $locationHint =
                $aiResponse[
                    'location_hint'
                ] ?? null;
$furnishingStatus =
    $aiResponse[
        'furnishing_status'
    ] ?? null;

if ($furnishingStatus !== null) {
    $furnishingStatus =
        mb_strtolower(
            trim(
                (string) $furnishingStatus
            )
        );

    if (
        !in_array(
            $furnishingStatus,
            [
                'furnished',
                'unfurnished',
            ],
            true
        )
    ) {
        $furnishingStatus = null;
    }
}
            $actionType =
                $aiResponse[
                    'action_type'
                ]
                ??
                $aiResponse[
                    'listing_type'
                ]
                ??
                $aiResponse[
                    'purpose'
                ]
                ??
                null;

            $confidence =
                $aiResponse[
                    'confidence'
                ] ?? null;

            $needsClarification =
                (bool) (
                    $aiResponse[
                        'needs_clarification'
                    ] ?? false
                );

            $vibeTags =
                $aiResponse[
                    'vibe_tags'
                ] ?? [];

            $requiredAmenities =
                $aiResponse[
                    'required_amenities'
                ] ?? [];

            if (
                !is_array(
                    $requiredAmenities
                )
            ) {
                $requiredAmenities = [
                    $requiredAmenities,
                ];
            }

            $poiFilters =
                $this
                    ->resolvePoiFilters(
                        $requiredAmenities
                    );

            [
                $typeId,
                $resolvedPropertyType,
            ] =
                $this
                    ->resolvePropertyType(
                        $propertyType
                    );

            $location =
                $this
                    ->resolveLocation(
                        $locationHint
                    );

            $normalizedActionType =
                $this
                    ->normalizeActionType(
                        $actionType
                    );
/*
|--------------------------------------------------------------------------
| Prevent unfiltered contextual search
|--------------------------------------------------------------------------
|
| Previously, when the parser returned null for every field, the query below
| had no contextual WHERE conditions and therefore returned all approved
| properties.
|
*/

$hasApplicableCriteria =
    $typeId !== null
    ||
    $minBudget !== null
    ||
    $maxBudget !== null
    ||
    $minBedrooms !== null
    ||
    $maxBedrooms !== null
    ||
    $location['scope'] !== null
    ||
    $normalizedActionType !== null
    ||
    $furnishingStatus !== null
    ||
    !empty($poiFilters);
if (!$hasApplicableCriteria) {

    return response()->json([

        'success' =>
            true,

        'query' =>
            $searchText,

        'language' =>
            $language,

        'ai_understanding' => [

            'location_scope' =>
                null,

            'city_id' =>
                null,

            'city_name' =>
                null,

            'emirate_id' =>
                null,

            'emirate_name' =>
                null,

            'property_type' =>
                $propertyType,

            'type_id' =>
                null,

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

            'action_type' =>
                $normalizedActionType,
'furnishing_status' =>
    $furnishingStatus,
            'location_hint' =>
                $locationHint,

            'vibe_tags' =>
                $vibeTags,

            'required_amenities' =>
                $requiredAmenities,

            'confidence' =>
                $confidence,

            'needs_clarification' =>
                true,

            'clarification_message' =>
                $language === 'ar'
                    ? 'يرجى إضافة معلومات أوضح مثل نوع العقار أو السعر أو الموقع أو عدد الغرف أو الشراء/الإيجار.'
                    : 'Please add clearer criteria such as property type, budget, location, bedrooms, or rent/buy.',
        ],

        'total_results' =>
            0,

        'returned_results' =>
            0,

        'properties' =>
            [],

    ]);
}
            $propertiesQuery =
                DB::table(
                    'properties as p'
                )

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
                        'communities as c',
                        'c.id',
                        '=',
                        'n.community_id'
                    )

                    ->leftJoin(
                        'districts as d',
                        'd.id',
                        '=',
                        'c.district_id'
                    )

                    ->leftJoin(
                        'cities as ci',
                        'ci.id',
                        '=',
                        'd.city_id'
                    )

                    ->leftJoin(
                        'emirates as e',
                        'e.id',
                        '=',
                        'ci.emirate_id'
                    )

                    ->leftJoin(
                        'neighborhood_translations as nt_en',
                        function ($join) {
                            $join
                                ->on(
                                    'nt_en.neighborhood_id',
                                    '=',
                                    'n.id'
                                )
                                ->where(
                                    'nt_en.language_code',
                                    '=',
                                    'en'
                                );
                        }
                    )

                    ->leftJoin(
                        'neighborhood_translations as nt_ar',
                        function ($join) {
                            $join
                                ->on(
                                    'nt_ar.neighborhood_id',
                                    '=',
                                    'n.id'
                                )
                                ->where(
                                    'nt_ar.language_code',
                                    '=',
                                    'ar'
                                );
                        }
                    )

                    ->whereNull(
                        'p.deleted_at'
                    )

                    ->where(
                        'p.moderation_status',
                        'approved'
                    );

            if (
                $typeId !== null
            ) {
                $propertiesQuery
                    ->where(
                        'p.type_id',
                        $typeId
                    );
            }

            if (
                $minBudget !== null
            ) {
                $propertiesQuery
                    ->where(
                        'p.price',
                        '>=',
                        (float)
                        $minBudget
                    );
            }

            if (
                $maxBudget !== null
            ) {
                $propertiesQuery
                    ->where(
                        'p.price',
                        '<=',
                        (float)
                        $maxBudget
                    );
            }

            if (
                $minBedrooms !== null
            ) {
                $propertiesQuery
                    ->where(
                        'p.bedrooms',
                        '>=',
                        (int)
                        $minBedrooms
                    );
            }

            if (
                $maxBedrooms !== null
            ) {
                $propertiesQuery
                    ->where(
                        'p.bedrooms',
                        '<=',
                        (int)
                        $maxBedrooms
                    );
            }

            if (
                $location[
                    'scope'
                ] === 'neighborhood'

                &&

                $location[
                    'neighborhood_id'
                ] !== null
            ) {
                $propertiesQuery
                    ->where(
                        'pl.neighborhood_id',
                        $location[
                            'neighborhood_id'
                        ]
                    );
            }

            if (
                $location[
                    'scope'
                ] === 'city'

                &&

                $location[
                    'city_id'
                ] !== null
            ) {
                $propertiesQuery
                    ->where(
                        'ci.id',
                        $location[
                            'city_id'
                        ]
                    );
            }

            if (
                $location[
                    'scope'
                ] === 'emirate'

                &&

                $location[
                    'emirate_id'
                ] !== null
            ) {
                $propertiesQuery
                    ->where(
                        'e.id',
                        $location[
                            'emirate_id'
                        ]
                    );
            }

            if (
                $normalizedActionType
                !== null
            ) {
                $propertiesQuery
                    ->where(
                        'p.action_type',
                        $normalizedActionType
                    );
            }
/*
|--------------------------------------------------------------------------
| Furnishing status
|--------------------------------------------------------------------------
*/

if ($furnishingStatus !== null) {
    $propertiesQuery
        ->where(
            'p.is_furnished',
            $furnishingStatus
        );
}
            /*
            |--------------------------------------------------------------------------
            | POI constraints
            |--------------------------------------------------------------------------
            |
            | NEAR:
            | whereExists()
            |
            | FAR:
            | whereNotExists()
            |
            */

            if (
                !empty(
                    $poiFilters
                )
            ) {
                $propertiesQuery
                    ->whereNotNull(
                        'pl.latitude'
                    )
                    ->whereNotNull(
                        'pl.longitude'
                    );

                foreach (
                    $poiFilters
                    as $poiFilter
                ) {
                    $distanceSql =
                        $this
                            ->haversineSql(
                                'pl',
                                'poi'
                            );

                    $constraint =
                        function (
                            $subQuery
                        ) use (
                            $poiFilter,
                            $distanceSql
                        ) {
                            $subQuery
                                ->select(
                                    DB::raw(1)
                                )

                                ->from(
                                    'points_of_interest as poi'
                                )

                                ->where(
                                    'poi.category',
                                    $poiFilter[
                                        'category'
                                    ]
                                )

                                ->where(
                                    'poi.subcategory',
                                    $poiFilter[
                                        'subcategory'
                                    ]
                                )

                                ->whereNotNull(
                                    'poi.latitude'
                                )

                                ->whereNotNull(
                                    'poi.longitude'
                                )

                                ->whereNotNull(
                                    'poi.name'
                                )

                                ->where(
                                    'poi.name',
                                    '<>',
                                    ''
                                )

                                ->whereRaw(
                                    'LOWER(TRIM(poi.name)) <> ?',
                                    [
                                        'unnamed',
                                    ]
                                )

                                ->whereRaw(
                                    $distanceSql
                                    . ' <= ?',
                                    [
                                        $poiFilter[
                                            'radius_km'
                                        ],
                                    ]
                                );
                        };

                    if (
                        $poiFilter[
                            'relation'
                        ] === 'far'
                    ) {
                        /*
                        | FAR:
                        | لا يوجد هذا النوع ضمن 1 KM
                        */

                        $propertiesQuery
                            ->whereNotExists(
                                $constraint
                            );

                    } else {
                        /*
                        | NEAR:
                        | لازم يوجد هذا النوع ضمن 1 KM
                        */

                        $propertiesQuery
                            ->whereExists(
                                $constraint
                            );
                    }
                }
            }

            /*
            |--------------------------------------------------------------------------
            | True result count
            |--------------------------------------------------------------------------
            */

            $totalResults =
                (clone $propertiesQuery)
                    ->distinct()
                    ->count(
                        'p.id'
                    );

            /*
            |--------------------------------------------------------------------------
            | Return max 50
            |--------------------------------------------------------------------------
            */

            $properties =
                $propertiesQuery

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

                    ->distinct()

                    ->orderByDesc(
                        'p.is_featured'
                    )

                    ->orderByDesc(
                        'p.listing_date'
                    )

                    ->limit(
                        self::RESULT_LIMIT
                    )

                    ->get();

            $propertyIds =
                $properties
                    ->pluck(
                        'id'
                    )
                    ->values()
                    ->all();

            /*
            |--------------------------------------------------------------------------
            | Arabic translations
            |--------------------------------------------------------------------------
            */

            $propertyTranslations =
                collect();

            if (
                $language === 'ar'
                &&
                !empty(
                    $propertyIds
                )
            ) {
                $propertyTranslations =
                    DB::table(
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

                        ->keyBy(
                            'property_id'
                        );
            }

            /*
            |--------------------------------------------------------------------------
            | Images
            |--------------------------------------------------------------------------
            */

            $images =
                collect();

            if (
                !empty(
                    $propertyIds
                )
            ) {
                $images =
                    DB::table(
                        'property_images'
                    )

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

                        ->orderByDesc(
                            'is_primary'
                        )

                        ->orderBy(
                            'display_order'
                        )

                        ->get()

                        ->groupBy(
                            'property_id'
                        );
            }

            /*
            |--------------------------------------------------------------------------
            | Only NEAR constraints need nearby POI output
            |--------------------------------------------------------------------------
            */

            $nearFilters =
                array_values(
                    array_filter(
                        $poiFilters,

                        static fn (
                            array $filter
                        ) =>
                            $filter[
                                'relation'
                            ]
                            === 'near'
                    )
                );

            $nearbyAmenitiesByProperty =
                $this
                    ->loadNearestAmenities(
                        $propertyIds,
                        $nearFilters
                    );

            $properties =
                $properties
                    ->map(
                        function (
                            $property
                        ) use (
                            $language,
                            $propertyTranslations,
                            $images,
                            $nearbyAmenitiesByProperty,
                            $nearFilters
                        ) {

                            if (
                                $language
                                === 'ar'
                            ) {
                                $translation =
                                    $propertyTranslations
                                        ->get(
                                            $property
                                                ->id
                                        );

                                if ($translation) {

                                    if (
                                        !empty(
                                            $translation
                                                ->title
                                        )
                                    ) {
                                        $property
                                            ->title =
                                            $translation
                                                ->title;
                                    }

                                    if (
                                        !empty(
                                            $translation
                                                ->description
                                        )
                                    ) {
                                        $property
                                            ->description =
                                            $translation
                                                ->description;
                                    }
                                }
                            }

                            $propertyImages =
                                $images->get(
                                    $property
                                        ->id,
                                    collect()
                                );

                            $primaryImage =
                                $propertyImages
                                    ->firstWhere(
                                        'is_primary',
                                        1
                                    )
                                ??
                                $propertyImages
                                    ->first();

                            $property
                                ->primary_image =
                                $primaryImage
                                    ? $primaryImage
                                        ->image_url
                                    : null;

                            $property
                                ->images =
                                $propertyImages
                                    ->pluck(
                                        'image_url'
                                    )
                                    ->values();

                            $property
                                ->location = [
                                    'id' =>
                                        $property
                                            ->location_id,

                                    'property_id' =>
                                        $property
                                            ->id,

                                    'address_line_1' =>
                                        $property
                                            ->address_line_1,

                                    'address_line_2' =>
                                        $property
                                            ->address_line_2,

                                    'building_name' =>
                                        $property
                                            ->building_name,

                                    'latitude' =>
                                        $property
                                            ->latitude,

                                    'longitude' =>
                                        $property
                                            ->longitude,

                                    'neighborhood_id' =>
                                        $property
                                            ->neighborhood_id,

                                    'neighborhood_name' =>
                                        $property
                                            ->neighborhood_name,

                                    'neighborhood_en' =>
                                        $property
                                            ->neighborhood_en,

                                    'neighborhood_ar' =>
                                        $property
                                            ->neighborhood_ar,

                                    'street_id' =>
                                        $property
                                            ->street_id,
                                ];

                            $propertyNearby =
                                [];

                            foreach (
                                $nearFilters
                                as $nearFilter
                            ) {
                                $match =
                                    $nearbyAmenitiesByProperty[
                                        (int)
                                        $property
                                            ->id
                                    ][
                                        $nearFilter[
                                            'key'
                                        ]
                                    ]
                                    ?? null;

                                if ($match) {
                                    $propertyNearby[] =
                                        $match;
                                }
                            }

                            $property
                                ->nearby_amenities =
                                $propertyNearby;

                            $property
                                ->nearby_amenity =
                                $propertyNearby[
                                    0
                                ] ?? null;

                            unset(
                                $property
                                    ->location_id,

                                $property
                                    ->address_line_1,

                                $property
                                    ->address_line_2,

                                $property
                                    ->building_name,

                                $property
                                    ->latitude,

                                $property
                                    ->longitude,

                                $property
                                    ->neighborhood_id,

                                $property
                                    ->neighborhood_name,

                                $property
                                    ->neighborhood_en,

                                $property
                                    ->neighborhood_ar,

                                $property
                                    ->street_id
                            );

                            return $property;
                        }
                    )
                    ->values();

         $clarificationMessage = null;

if ($needsClarification) {
    $clarificationMessage =
        $language === 'ar'
            ? 'يحتوي طلب البحث على معلومات غير واضحة أو شروط متعارضة. يرجى توضيح طلبك.'
            : 'The search request contains ambiguous or conflicting criteria. Please clarify your request.';
}
            return response()->json([
                'success' =>
                    true,

                'query' =>
                    $searchText,

                'language' =>
                    $language,

                'ai_understanding' => [
                    'location_scope' =>
                        $location[
                            'scope'
                        ],

                    'city_id' =>
                        $location[
                            'city_id'
                        ],

                    'city_name' =>
                        $location[
                            'city_name'
                        ],

                    'emirate_id' =>
                        $location[
                            'emirate_id'
                        ],

                    'emirate_name' =>
                        $location[
                            'emirate_name'
                        ],

                    'property_type' =>
                        $resolvedPropertyType
                        ??
                        $propertyType,

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
                        $location[
                            'neighborhood_id'
                        ],

                    'neighborhood_en' =>
                        $location[
                            'neighborhood_en'
                        ],

                    'neighborhood_ar' =>
                        $location[
                            'neighborhood_ar'
                        ],

                    'action_type' =>
                        $normalizedActionType,
'furnishing_status' =>
    $furnishingStatus,
                    'vibe_tags' =>
                        $vibeTags,

                    'required_amenities' =>
                        $requiredAmenities,

                    'poi_radius_km' =>
                        !empty(
                            $poiFilters
                        )
                            ? self::POI_RADIUS_KM
                            : null,

                    /*
                    | Backward compatibility
                    */

                    'nearby_radius_km' =>
                        !empty(
                            $poiFilters
                        )
                            ? self::POI_RADIUS_KM
                            : null,

                    'applied_amenity_filters' =>
                        array_values(
                            array_map(
                                static fn (
                                    array $filter
                                ) => [
                                    'requested' =>
                                        $filter[
                                            'requested'
                                        ],

                                    'relation' =>
                                        $filter[
                                            'relation'
                                        ],

                                    'category' =>
                                        $filter[
                                            'category'
                                        ],

                                    'subcategory' =>
                                        $filter[
                                            'subcategory'
                                        ],

                                    'radius_km' =>
                                        $filter[
                                            'radius_km'
                                        ],
                                ],

                                $poiFilters
                            )
                        ),

                    'confidence' =>
                        $confidence,

                    'needs_clarification' =>
                        $needsClarification,

                    'clarification_message' =>
                        $clarificationMessage,
                ],

                'total_results' =>
                    $totalResults,

                'returned_results' =>
                    $properties
                        ->count(),

                'properties' =>
                    $properties,
            ]);

        } catch (Throwable $e) {

            report(
                $e
            );

            return response()->json([
                'success' =>
                    false,

                'message' =>
                    'AI contextual search failed',

                'details' =>
                    app()->environment(
                        'local'
                    )
                        ? $e->getMessage()
                        : null,

            ], 500);
        }
    }


    private function resolvePropertyType(
        ?string $propertyType
    ): array {

        if (
            empty(
                $propertyType
            )
        ) {
            return [
                null,
                null,
            ];
        }

        $type =
            DB::table(
                'property_types'
            )

                ->whereRaw(
                    'LOWER(name) = ?',
                    [
                        mb_strtolower(
                            trim(
                                $propertyType
                            )
                        ),
                    ]
                )

                ->first();

        if (!$type) {
            return [
                null,
                null,
            ];
        }

        return [
            (int)
            $type->id,

            $type->name,
        ];
    }


    private function resolveLocation(
        ?string $locationHint
    ): array {

        $result = [
            'scope' =>
                null,

            'neighborhood_id' =>
                null,

            'neighborhood_en' =>
                null,

            'neighborhood_ar' =>
                null,

            'city_id' =>
                null,

            'city_name' =>
                null,

            'emirate_id' =>
                null,

            'emirate_name' =>
                null,
        ];

        if (
            empty(
                $locationHint
            )
        ) {
            return $result;
        }

        $normalizedLocation =
            mb_strtolower(
                trim(
                    $locationHint
                )
            );

        $emirateAliases = [
            'دبي' =>
                'Dubai',

            'أبوظبي' =>
                'Abu Dhabi',

            'ابوظبي' =>
                'Abu Dhabi',

            'أبو ظبي' =>
                'Abu Dhabi',

            'ابو ظبي' =>
                'Abu Dhabi',

            'الشارقة' =>
                'Sharjah',

            'شارقة' =>
                'Sharjah',

            'عجمان' =>
                'Ajman',

            'الفجيرة' =>
                'Fujairah',

            'فجيرة' =>
                'Fujairah',

            'رأس الخيمة' =>
                'Ras Al Khaimah',

            'راس الخيمة' =>
                'Ras Al Khaimah',

            'أم القيوين' =>
                'Umm Al Quwain',

            'ام القيوين' =>
                'Umm Al Quwain',
        ];

        $emirateSearch =
            $emirateAliases[
                $normalizedLocation
            ]
            ??
            trim(
                $locationHint
            );

        $emirate =
            DB::table(
                'emirates'
            )

                ->whereRaw(
                    'LOWER(name) = ?',
                    [
                        mb_strtolower(
                            $emirateSearch
                        ),
                    ]
                )

                ->first();

        if ($emirate) {

            $result[
                'scope'
            ] =
                'emirate';

            $result[
                'emirate_id'
            ] =
                (int)
                $emirate
                    ->id;

            $result[
                'emirate_name'
            ] =
                $emirate
                    ->name;

            return $result;
        }

        $city =
            DB::table(
                'cities'
            )

                ->where(
                    function (
                        $query
                    ) use (
                        $normalizedLocation
                    ) {
                        $query
                            ->whereRaw(
                                'LOWER(name) = ?',
                                [
                                    $normalizedLocation,
                                ]
                            )

                            ->orWhereRaw(
                                'LOWER(name) = ?',
                                [
                                    $normalizedLocation
                                    . ' city',
                                ]
                            );
                    }
                )

                ->first();

        if ($city) {

            $result[
                'scope'
            ] =
                'city';

            $result[
                'city_id'
            ] =
                (int)
                $city
                    ->id;

            $result[
                'city_name'
            ] =
                $city
                    ->name;

            $result[
                'emirate_id'
            ] =
                (int)
                $city
                    ->emirate_id;

            return $result;
        }

        $neighborhood =
            DB::table(
                'neighborhoods as n'
            )

                ->leftJoin(
                    'neighborhood_translations as nt_en',
                    function (
                        $join
                    ) {
                        $join
                            ->on(
                                'nt_en.neighborhood_id',
                                '=',
                                'n.id'
                            )

                            ->where(
                                'nt_en.language_code',
                                '=',
                                'en'
                            );
                    }
                )

                ->leftJoin(
                    'neighborhood_translations as nt_ar',
                    function (
                        $join
                    ) {
                        $join
                            ->on(
                                'nt_ar.neighborhood_id',
                                '=',
                                'n.id'
                            )

                            ->where(
                                'nt_ar.language_code',
                                '=',
                                'ar'
                            );
                    }
                )

                ->where(
                    function (
                        $query
                    ) use (
                        $normalizedLocation
                    ) {
                        $query
                            ->whereRaw(
                                'LOWER(n.name) = ?',
                                [
                                    $normalizedLocation,
                                ]
                            )

                            ->orWhereRaw(
                                'LOWER(nt_en.name) = ?',
                                [
                                    $normalizedLocation,
                                ]
                            )

                            ->orWhereRaw(
                                'LOWER(nt_ar.name) = ?',
                                [
                                    $normalizedLocation,
                                ]
                            );
                    }
                )

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

            $result[
                'scope'
            ] =
                'neighborhood';

            $result[
                'neighborhood_id'
            ] =
                (int)
                $neighborhood
                    ->id;

            $result[
                'neighborhood_en'
            ] =
                $neighborhood
                    ->name_en
                ?:
                $neighborhood
                    ->name;

            $result[
                'neighborhood_ar'
            ] =
                $neighborhood
                    ->name_ar;
        }

        return $result;
    }


    private function normalizeActionType(
        ?string $actionType
    ): ?string {

        if (
            empty(
                $actionType
            )
        ) {
            return null;
        }

        $action =
            mb_strtolower(
                trim(
                    $actionType
                )
            );

        if (
            in_array(
                $action,
                [
                    'buy',
                    'sale',
                    'purchase',
                    'for sale',
                ],
                true
            )
        ) {
            return 'buy';
        }

        if (
            in_array(
                $action,
                [
                    'rent',
                    'rental',
                    'lease',
                    'for rent',
                ],
                true
            )
        ) {
            return 'rent';
        }

        if (
            in_array(
                $action,
                [
                    'booking',
                    'book',
                ],
                true
            )
        ) {
            return 'booking';
        }

        return null;
    }


    private function loadNearestAmenities(
        array $propertyIds,
        array $nearFilters
    ): array {

        $result =
            [];

        if (
            empty(
                $propertyIds
            )
            ||
            empty(
                $nearFilters
            )
        ) {
            return $result;
        }

        foreach (
            $nearFilters
            as $filter
        ) {
            $distanceSql =
                $this
                    ->haversineSql(
                        'apl',
                        'poi'
                    );

            $rows =
                DB::table(
                    'property_locations as apl'
                )

                    ->crossJoin(
                        'points_of_interest as poi'
                    )

                    ->whereIn(
                        'apl.property_id',
                        $propertyIds
                    )

                    ->whereNotNull(
                        'apl.latitude'
                    )

                    ->whereNotNull(
                        'apl.longitude'
                    )

                    ->where(
                        'poi.category',
                        $filter[
                            'category'
                        ]
                    )

                    ->where(
                        'poi.subcategory',
                        $filter[
                            'subcategory'
                        ]
                    )

                    ->whereNotNull(
                        'poi.latitude'
                    )

                    ->whereNotNull(
                        'poi.longitude'
                    )

                    ->whereNotNull(
                        'poi.name'
                    )

                    ->where(
                        'poi.name',
                        '<>',
                        ''
                    )

                    ->whereRaw(
                        'LOWER(TRIM(poi.name)) <> ?',
                        [
                            'unnamed',
                        ]
                    )

                    ->select([
                        'apl.property_id',

                        'poi.id as poi_id',

                        'poi.name',

                        'poi.category',

                        'poi.subcategory',

                        'poi.latitude',

                        'poi.longitude',

                        'poi.icon',
                    ])

                    ->selectRaw(
                        $distanceSql
                        . ' AS distance_km'
                    )

                    ->havingRaw(
                        'distance_km <= ?',
                        [
                            $filter[
                                'radius_km'
                            ],
                        ]
                    )

                    ->orderBy(
                        'apl.property_id'
                    )

                    ->orderBy(
                        'distance_km'
                    )

                    ->get();

            foreach (
                $rows
                as $row
            ) {
                $propertyId =
                    (int)
                    $row
                        ->property_id;

                $filterKey =
                    $filter[
                        'key'
                    ];

                if (
                    isset(
                        $result[
                            $propertyId
                        ][
                            $filterKey
                        ]
                    )
                ) {
                    continue;
                }

                $result[
                    $propertyId
                ][
                    $filterKey
                ] = [
                    'id' =>
                        (int)
                        $row
                            ->poi_id,

                    'name' =>
                        $row
                            ->name,

                    'relation' =>
                        'near',

                    'category' =>
                        $row
                            ->category,

                    'subcategory' =>
                        $row
                            ->subcategory,

                    'latitude' =>
                        (float)
                        $row
                            ->latitude,

                    'longitude' =>
                        (float)
                        $row
                            ->longitude,

                    'icon' =>
                        $row
                            ->icon,

                    'distance_km' =>
                        round(
                            (float)
                            $row
                                ->distance_km,
                            3
                        ),

                    'radius_km' =>
                        $filter[
                            'radius_km'
                        ],
                ];
            }
        }

        return $result;
    }

private function resolvePoiFilters(
    array $requiredAmenities
): array {

    $filters =
        [];

    $poiMap = [
        'restaurant' => [
            'category' =>
                'amenities',

            'subcategory' =>
                'restaurant',

            'aliases' => [
                'restaurant',
                'restaurants',
                'مطعم',
                'مطاعم',
            ],
        ],

        'supermarket' => [
            'category' =>
                'amenities',

            'subcategory' =>
                'supermarket',

            'aliases' => [
                'supermarket',
                'super market',
                'grocery',
                'grocery store',
                'سوبرماركت',
                'سوبر ماركت',
                'بقالة',
            ],
        ],

        'cafe' => [
            'category' =>
                'amenities',

            'subcategory' =>
                'cafe',

            'aliases' => [
                'cafe',
                'café',
                'coffee shop',
                'coffee',
                'مقهى',
                'كافيه',
                'كوفي',
            ],
        ],

        'pharmacy' => [
            'category' =>
                'amenities',

            'subcategory' =>
                'pharmacy',

            'aliases' => [
                'pharmacy',
                'drugstore',
                'صيدلية',
                'صيدليه',
            ],
        ],

        'transit_station' => [
            'category' =>
                'amenities',

            'subcategory' =>
                'transit_station',

            'aliases' => [
                'transit station',
                'metro station',
                'bus station',
                'train station',
                'metro',
                'محطة مترو',
                'محطة باص',
                'محطة حافلات',
                'مترو',
                'محطة',
            ],
        ],

        'school' => [
            'category' =>
                'amenities',

            'subcategory' =>
                'school',

            'aliases' => [
                'school',
                'schools',
                'مدرسة',
                'مدرسه',
                'مدارس',
            ],
        ],

        'clinic' => [
            'category' =>
                'safety',

            'subcategory' =>
                'clinic',

            'aliases' => [
                'clinic',
                'medical clinic',
                'عيادة',
                'عياده',
                'مستوصف',
            ],
        ],

        'hospital' => [
            'category' =>
                'safety',

            'subcategory' =>
                'hospital',

            'aliases' => [
                'hospital',
                'hospitals',
                'مستشفى',
                'مستشفيات',
            ],
        ],

        'park' => [
            'category' =>
                'quietness_positive',

            'subcategory' =>
                'park',

            'aliases' => [
                'park',
                'parks',
                'garden',
                'public park',
                'حديقة',
                'حديقه',
                'منتزه',
            ],
        ],

        'police' => [
            'category' =>
                'safety',

            'subcategory' =>
                'police',

            'aliases' => [
                'police station',
                'police',
                'مركز شرطة',
                'شرطة',
                'مخفر',
            ],
        ],

        'bar' => [
            'category' =>
                'quietness_negative',

            'subcategory' =>
                'bar',

            'aliases' => [
                'bar',
                'bars',
                'بار',
            ],
        ],

        'nightclub' => [
            'category' =>
                'quietness_negative',

            'subcategory' =>
                'nightclub',

            'aliases' => [
                'nightclub',
                'night club',
                'nightclubs',
                'night clubs',
                'نادي ليلي',
                'ملهى ليلي',
                'ملاهي ليلية',
            ],
        ],
    ];

    foreach (
        $requiredAmenities
        as $requested
    ) {

        /*
        |--------------------------------------------------------------------------
        | Normalize amenity input
        |--------------------------------------------------------------------------
        |
        | Supports string values:
        |
        | nearby school
        | near school
        | far school
        | far from school
        |
        | And array values such as:
        |
        | [
        |     'amenity' => 'school',
        |     'relation' => 'near',
        | ]
        |
        */

        if (is_array($requested)) {

            $amenityName =
                $requested['amenity']
                ??
                $requested['type']
                ??
                $requested['name']
                ??
                $requested['subcategory']
                ??
                null;

            $requestedRelation =
                $requested['relation']
                ?? null;

            /*
            |--------------------------------------------------------------------------
            | Ignore malformed array values
            |--------------------------------------------------------------------------
            */

            if (
                !is_string($amenityName)
                ||
                trim($amenityName) === ''
            ) {
                continue;
            }

            $amenityName =
                trim($amenityName);

            if (is_string($requestedRelation)) {

                $requestedRelation =
                    mb_strtolower(
                        trim(
                            $requestedRelation
                        )
                    );

            } else {

                $requestedRelation =
                    null;
            }

            /*
            |--------------------------------------------------------------------------
            | Convert array format to canonical string format
            |--------------------------------------------------------------------------
            */

            if (
                in_array(
                    $requestedRelation,
                    [
                        'far',
                        'far_from',
                        'far from',
                        'away',
                    ],
                    true
                )
            ) {

                $requested =
                    'far '
                    . $amenityName;

            } else {

                $requested =
                    'nearby '
                    . $amenityName;
            }

        } elseif (
            is_string($requested)
            ||
            is_numeric($requested)
        ) {

            $requested =
                trim(
                    (string)
                    $requested
                );

            if ($requested === '') {
                continue;
            }

        } else {

            /*
            |--------------------------------------------------------------------------
            | Ignore unsupported values safely
            |--------------------------------------------------------------------------
            */

            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize text
        |--------------------------------------------------------------------------
        */

        $normalized =
            mb_strtolower(
                trim(
                    $requested
                )
            );

        $normalized =
            preg_replace(
                '/\s+/u',
                ' ',
                $normalized
            )
            ??
            $normalized;

        /*
        |--------------------------------------------------------------------------
        | Detect relation
        |--------------------------------------------------------------------------
        */

        $relation =
            null;

        $body =
            $normalized;

        if (
            str_starts_with(
                $normalized,
                'nearby '
            )
        ) {
            $relation =
                'near';

            $body =
                trim(
                    mb_substr(
                        $normalized,
                        mb_strlen(
                            'nearby '
                        )
                    )
                );

        } elseif (
            str_starts_with(
                $normalized,
                'near '
            )
        ) {
            $relation =
                'near';

            $body =
                trim(
                    mb_substr(
                        $normalized,
                        mb_strlen(
                            'near '
                        )
                    )
                );

        } elseif (
            str_starts_with(
                $normalized,
                'far from '
            )
        ) {
            $relation =
                'far';

            $body =
                trim(
                    mb_substr(
                        $normalized,
                        mb_strlen(
                            'far from '
                        )
                    )
                );

        } elseif (
            str_starts_with(
                $normalized,
                'far '
            )
        ) {
            $relation =
                'far';

            $body =
                trim(
                    mb_substr(
                        $normalized,
                        mb_strlen(
                            'far '
                        )
                    )
                );
        }

        /*
        |--------------------------------------------------------------------------
        | Backward compatibility
        |--------------------------------------------------------------------------
        |
        | Old values without near/far are treated as NEAR.
        |
        */

        $relation ??=
            'near';

        /*
        |--------------------------------------------------------------------------
        | Resolve requested amenity
        |--------------------------------------------------------------------------
        */

        foreach (
            $poiMap
            as $key =>
            $definition
        ) {

            $aliases =
                array_map(
                    static fn (
                        $value
                    ) =>
                        mb_strtolower(
                            trim(
                                (string)
                                $value
                            )
                        ),

                    $definition[
                        'aliases'
                    ]
                );

            if (
                $body
                ===
                mb_strtolower(
                    $key
                )

                ||

                $body
                ===
                mb_strtolower(
                    str_replace(
                        '_',
                        ' ',
                        $key
                    )
                )

                ||

                in_array(
                    $body,
                    $aliases,
                    true
                )
            ) {

                $uniqueKey =
                    $relation
                    . ':'
                    . $key;

                $filters[
                    $uniqueKey
                ] = [
                    'key' =>
                        $key,

                    'requested' =>
                        (string)
                        $requested,

                    'relation' =>
                        $relation,

                    'category' =>
                        $definition[
                            'category'
                        ],

                    'subcategory' =>
                        $definition[
                            'subcategory'
                        ],

                    'radius_km' =>
                        self::POI_RADIUS_KM,
                ];

                break;
            }
        }
    }

    return array_values(
        $filters
    );
}

    private function haversineSql(
        string $propertyAlias,
        string $poiAlias
    ): string {

        return '
            6371 * ACOS(
                LEAST(
                    1,
                    GREATEST(
                        -1,

                        COS(
                            RADIANS('
                            . $propertyAlias
                            . '.latitude)
                        )

                        *

                        COS(
                            RADIANS('
                            . $poiAlias
                            . '.latitude)
                        )

                        *

                        COS(
                            RADIANS('
                            . $poiAlias
                            . '.longitude)

                            -

                            RADIANS('
                            . $propertyAlias
                            . '.longitude)
                        )

                        +

                        SIN(
                            RADIANS('
                            . $propertyAlias
                            . '.latitude)
                        )

                        *

                        SIN(
                            RADIANS('
                            . $poiAlias
                            . '.latitude)
                        )
                    )
                )
            )
        ';
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