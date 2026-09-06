<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class AIContextualSearchController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'query' => ['required', 'string', 'min:2', 'max:500'],
            ]);

            $searchText = trim($request->input('query'));
            $normalizedText = mb_strtolower($searchText);

            /*
            |--------------------------------------------------------------------------
            | Detect Property Type
            |--------------------------------------------------------------------------
            */

            $typeKeywords = [
                'Apartment' => [
                    'apartment',
                    'apartments',
                    'flat',
                    'flats',
                ],

                'Villa' => [
                    'villa',
                    'villas',
                ],

                'Penthouse' => [
                    'penthouse',
                    'penthouses',
                ],

                'Townhouse' => [
                    'townhouse',
                    'townhouses',
                    'town house',
                    'town houses',
                ],
            ];

            $detectedType = null;

            foreach ($typeKeywords as $type => $keywords) {
                foreach ($keywords as $keyword) {
                    if (str_contains($normalizedText, $keyword)) {
                        $detectedType = $type;
                        break 2;
                    }
                }
            }


            /*
            |--------------------------------------------------------------------------
            | Detect Bedrooms
            |--------------------------------------------------------------------------
            */

            $bedrooms = null;

            if (
                preg_match(
                    '/(\d+)\s*(bedroom|bedrooms|bed|beds|br)\b/i',
                    $normalizedText,
                    $matches
                )
            ) {
                $bedrooms = (int) $matches[1];
            }


            /*
            |--------------------------------------------------------------------------
            | Detect Bathrooms
            |--------------------------------------------------------------------------
            */

            $bathrooms = null;

            if (
                preg_match(
                    '/(\d+)\s*(bathroom|bathrooms|bath|baths)\b/i',
                    $normalizedText,
                    $matches
                )
            ) {
                $bathrooms = (int) $matches[1];
            }


            /*
            |--------------------------------------------------------------------------
            | Detect Price
            |--------------------------------------------------------------------------
            */

            $minPrice = null;
            $maxPrice = null;

            if (
                preg_match(
                    '/(?:under|below|less than|max(?:imum)?|up to)\s*(?:aed\s*)?([\d,.]+)\s*(million|m|k)?/i',
                    $normalizedText,
                    $matches
                )
            ) {
                $maxPrice = $this->normalizePrice(
                    $matches[1],
                    $matches[2] ?? null
                );
            }

            if (
                preg_match(
                    '/(?:over|above|more than|min(?:imum)?|starting from)\s*(?:aed\s*)?([\d,.]+)\s*(million|m|k)?/i',
                    $normalizedText,
                    $matches
                )
            ) {
                $minPrice = $this->normalizePrice(
                    $matches[1],
                    $matches[2] ?? null
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Detect Neighborhood
            |--------------------------------------------------------------------------
            */

            $neighborhoods = DB::table('neighborhoods')
                ->select([
                    'id',
                    'name',
                ])
                ->get();

            $detectedNeighborhood = null;

            foreach ($neighborhoods as $neighborhood) {
                if (
                    str_contains(
                        $normalizedText,
                        mb_strtolower($neighborhood->name)
                    )
                ) {
                    $detectedNeighborhood = $neighborhood;
                    break;
                }
            }


            /*
            |--------------------------------------------------------------------------
            | Detect Features
            |--------------------------------------------------------------------------
            */

            $availableFeatures = DB::table('property_features')
                ->select([
                    'id',
                    'name',
                    'category',
                ])
                ->get();

            $featureAliases = [
                'Swimming Pool' => [
                    'swimming pool',
                    'pool',
                ],

                'Gym' => [
                    'gym',
                    'fitness',
                    'fitness center',
                ],

                'Parking' => [
                    'parking',
                    'car park',
                    'garage',
                ],

                '24/7 Security' => [
                    'security',
                    '24/7 security',
                    '24 hour security',
                ],

                'Balcony' => [
                    'balcony',
                ],

                'Central Air Conditioning' => [
                    'air conditioning',
                    'air conditioner',
                    'central ac',
                    'central air',
                    'a/c',
                    'ac',
                ],

                'Elevator' => [
                    'elevator',
                    'lift',
                ],

                'Concierge' => [
                    'concierge',
                ],

                'Kids Play Area' => [
                    'kids play area',
                    'children play area',
                    'play area',
                ],

                'Garden' => [
                    'garden',
                ],

                'Sea View' => [
                    'sea view',
                    'ocean view',
                    'water view',
                ],

                'Smart Home' => [
                    'smart home',
                    'smart house',
                ],

                'BBQ Area' => [
                    'bbq',
                    'barbecue',
                    'barbeque',
                ],

                'Jacuzzi' => [
                    'jacuzzi',
                    'hot tub',
                ],
            ];

            $detectedFeatures = collect();

            foreach ($availableFeatures as $feature) {
                $aliases = $featureAliases[$feature->name]
                    ?? [mb_strtolower($feature->name)];

                foreach ($aliases as $alias) {
                    if (
                        str_contains(
                            $normalizedText,
                            mb_strtolower($alias)
                        )
                    ) {
                        $detectedFeatures->push($feature);
                        break;
                    }
                }
            }

            $detectedFeatures = $detectedFeatures
                ->unique('id')
                ->values();


            /*
            |--------------------------------------------------------------------------
            | Load Properties
            |--------------------------------------------------------------------------
            */

            $properties = DB::table('properties as p')
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
                ->whereNull('p.deleted_at')
                ->where(
                    'p.slug',
                    'like',
                    'demo-dubai-%'
                )
                ->select([
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

                    'pt.name as property_type',

                    'pl.address_line_1',
                    'pl.latitude',
                    'pl.longitude',

                    'n.id as neighborhood_id',
                    'n.name as neighborhood',
                ])
                ->distinct()
                ->get();


            /*
            |--------------------------------------------------------------------------
            | Load Images + Features
            |--------------------------------------------------------------------------
            */

            $propertyIds = $properties
                ->pluck('id')
                ->values()
                ->all();

            $images = collect();
            $propertyFeatures = collect();

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


                $propertyFeatures = DB::table(
                    'property_feature_values as pfv'
                )
                    ->join(
                        'property_features as pf',
                        'pf.id',
                        '=',
                        'pfv.feature_id'
                    )
                    ->whereIn(
                        'pfv.property_id',
                        $propertyIds
                    )
                    ->select([
                        'pfv.property_id',
                        'pf.id',
                        'pf.name',
                        'pf.category',
                        'pfv.feature_value',
                    ])
                    ->get()
                    ->groupBy('property_id');
            }


            /*
            |--------------------------------------------------------------------------
            | Calculate Match Score
            |--------------------------------------------------------------------------
            */

            $scoredProperties = $properties
                ->map(function ($property) use (
                    $images,
                    $propertyFeatures,
                    $detectedType,
                    $bedrooms,
                    $bathrooms,
                    $detectedNeighborhood,
                    $minPrice,
                    $maxPrice,
                    $detectedFeatures
                ) {
                    $propertyImages = $images->get(
                        $property->id,
                        collect()
                    );

                    $features = $propertyFeatures->get(
                        $property->id,
                        collect()
                    );

                    $featureNames = $features
                        ->pluck('name')
                        ->map(fn ($name) => mb_strtolower($name))
                        ->values();


                    $score = 0;
                    $possibleScore = 0;

                    $matched = [];
                    $missing = [];


                    /*
                    |--------------------------------------------------------------------------
                    | Property Type Score
                    |--------------------------------------------------------------------------
                    */

                    if ($detectedType !== null) {
                        $possibleScore += 25;

                        if (
                            mb_strtolower($property->property_type)
                            === mb_strtolower($detectedType)
                        ) {
                            $score += 25;

                            $matched[] = $detectedType;
                        } else {
                            $missing[] = $detectedType;
                        }
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | Neighborhood Score
                    |--------------------------------------------------------------------------
                    */

                    if ($detectedNeighborhood !== null) {
                        $possibleScore += 25;

                        if (
                            (int) $property->neighborhood_id
                            === (int) $detectedNeighborhood->id
                        ) {
                            $score += 25;

                            $matched[] =
                                $detectedNeighborhood->name;
                        } else {
                            $missing[] =
                                $detectedNeighborhood->name;
                        }
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | Bedrooms Score
                    |--------------------------------------------------------------------------
                    */

                    if ($bedrooms !== null) {
                        $possibleScore += 20;

                        if (
                            (int) $property->bedrooms
                            === (int) $bedrooms
                        ) {
                            $score += 20;

                            $matched[] =
                                $bedrooms . ' bedrooms';
                        } else {
                            $missing[] =
                                $bedrooms . ' bedrooms';
                        }
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | Bathrooms Score
                    |--------------------------------------------------------------------------
                    */

                    if ($bathrooms !== null) {
                        $possibleScore += 10;

                        if (
                            (int) $property->bathrooms
                            === (int) $bathrooms
                        ) {
                            $score += 10;

                            $matched[] =
                                $bathrooms . ' bathrooms';
                        } else {
                            $missing[] =
                                $bathrooms . ' bathrooms';
                        }
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | Minimum Price Score
                    |--------------------------------------------------------------------------
                    */

                    if ($minPrice !== null) {
                        $possibleScore += 10;

                        if (
                            (float) $property->price
                            >= $minPrice
                        ) {
                            $score += 10;

                            $matched[] =
                                'minimum price';
                        } else {
                            $missing[] =
                                'minimum price';
                        }
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | Maximum Price Score
                    |--------------------------------------------------------------------------
                    */

                    if ($maxPrice !== null) {
                        $possibleScore += 10;

                        if (
                            (float) $property->price
                            <= $maxPrice
                        ) {
                            $score += 10;

                            $matched[] =
                                'maximum price';
                        } else {
                            $missing[] =
                                'maximum price';
                        }
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | Features Score
                    |--------------------------------------------------------------------------
                    */

                    if ($detectedFeatures->isNotEmpty()) {
                        foreach ($detectedFeatures as $feature) {
                            $possibleScore += 15;

                            $featureName =
                                mb_strtolower($feature->name);

                            if (
                                $featureNames->contains(
                                    $featureName
                                )
                            ) {
                                $score += 15;

                                $matched[] =
                                    $feature->name;
                            } else {
                                $missing[] =
                                    $feature->name;
                            }
                        }
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | Final Percentage
                    |--------------------------------------------------------------------------
                    */

                    $matchScore = $possibleScore > 0
                        ? (int) round(
                            ($score / $possibleScore) * 100
                        )
                        : 0;


                    /*
                    |--------------------------------------------------------------------------
                    | Add Property Data
                    |--------------------------------------------------------------------------
                    */

                    $property->primary_image =
                        $propertyImages->firstWhere(
                            'is_primary',
                            1
                        )
                        ?? $propertyImages->first();

                    $property->features =
                        $features->values();

                    $property->match_score =
                        $matchScore;

                    $property->matched =
                        array_values(
                            array_unique($matched)
                        );

                    $property->missing =
                        array_values(
                            array_unique($missing)
                        );

                    $property->is_exact_match =
                        $possibleScore > 0
                        && $score === $possibleScore;

                    return $property;
                });


            /*
            |--------------------------------------------------------------------------
            | Exact Matches
            |--------------------------------------------------------------------------
            */

            $exactMatches = $scoredProperties
                ->filter(
                    fn ($property) =>
                        $property->is_exact_match === true
                )
                ->sortByDesc('is_featured')
                ->values();


            /*
            |--------------------------------------------------------------------------
            | Fallback Recommendations
            |--------------------------------------------------------------------------
            */

            if ($exactMatches->isNotEmpty()) {
                $resultType = 'exact';

                $results = $exactMatches
                    ->take(20)
                    ->values();
            } else {
                $resultType = 'recommended';

                $results = $scoredProperties
                    ->filter(
                        fn ($property) =>
                            $property->match_score > 0
                    )
                    ->sort(function ($a, $b) {
                        if (
                            $a->match_score
                            === $b->match_score
                        ) {
                            return $b->is_featured
                                <=> $a->is_featured;
                        }

                        return $b->match_score
                            <=> $a->match_score;
                    })
                    ->take(20)
                    ->values();
            }


            /*
            |--------------------------------------------------------------------------
            | Response
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'success' => true,

                'query' => $searchText,

                'search_mode' => $resultType,

                'understood' => [
                    'property_type' =>
                        $detectedType,

                    'bedrooms' =>
                        $bedrooms,

                    'bathrooms' =>
                        $bathrooms,

                    'neighborhood' =>
                        $detectedNeighborhood
                            ? $detectedNeighborhood->name
                            : null,

                    'min_price' =>
                        $minPrice,

                    'max_price' =>
                        $maxPrice,

                    'features' =>
                        $detectedFeatures
                            ->pluck('name')
                            ->values(),
                ],

                'exact_matches' =>
                    $exactMatches->count(),

                'total_results' =>
                    $results->count(),

                'properties' =>
                    $results,
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


    /*
    |--------------------------------------------------------------------------
    | Normalize Price
    |--------------------------------------------------------------------------
    */

    private function normalizePrice(
        string $value,
        ?string $suffix
    ): float {
        $price = (float) str_replace(
            [',', ' '],
            '',
            $value
        );

        $suffix = mb_strtolower(
            trim($suffix ?? '')
        );

        if ($suffix === 'k') {
            $price *= 1000;
        }

        if (
            $suffix === 'm'
            || $suffix === 'million'
        ) {
            $price *= 1000000;
        }

        return $price;
    }
}