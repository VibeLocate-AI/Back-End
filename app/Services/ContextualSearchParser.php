<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ContextualSearchParser
{
    /**
     * Parse a natural-language real-estate search query.
     *
     * This parser is independent from the external AI service.
     * It supports Arabic and English and returns the same structure
     * expected by AIContextualSearchController.
     */
    public function parse(string $text, string $language = 'en'): array
    {
        $original = trim($text);

        $normalized = $this->normalizeText($original);

        $result = [
            'property_type' => null,

            'min_budget' => null,
            'max_budget' => null,
            'furnishing_status' => null,
            'budget_currency' => null,

            'min_bedrooms' => null,
            'max_bedrooms' => null,

            'location_hint' => null,

            'action_type' => null,

            'vibe_tags' => [],

            'required_amenities' => [],

            'confidence' => 0.0,

            'needs_clarification' => false,
        ];

        /*
        |--------------------------------------------------------------------------
        | Action type
        |--------------------------------------------------------------------------
        */

        $result['action_type'] =
            $this->extractActionType($normalized);
/*
|--------------------------------------------------------------------------
| Furnishing status
|--------------------------------------------------------------------------
*/

$normalizedForFurnishing = mb_strtolower(
    preg_replace('/\s+/u', ' ', trim($normalized))
);

/*
| IMPORTANT:
| Detect negative expressions first because "unfurnished"
| contains the word "furnished".
*/

$unfurnishedPatterns = [
    '/\bunfurnished\b/ui',
    '/\bnot\s+furnished\b/ui',
    '/\bwithout\s+furniture\b/ui',
    '/غير\s+مفروش/u',
    '/غير\s+مؤثث/u',
    '/بدون\s+أثاث/u',
    '/بدون\s+اثاث/u',
];

$furnishedPatterns = [
    '/\bfully\s+furnished\b/ui',
    '/\bfurnished\b/ui',
    '/مفروش/u',
    '/مؤثث/u',
];

foreach ($unfurnishedPatterns as $pattern) {
    if (preg_match($pattern, $normalizedForFurnishing)) {
        $result['furnishing_status'] = 'unfurnished';
        break;
    }
}

if ($result['furnishing_status'] === null) {
    foreach ($furnishedPatterns as $pattern) {
        if (preg_match($pattern, $normalizedForFurnishing)) {
            $result['furnishing_status'] = 'furnished';
            break;
        }
    }
}
        /*
        |--------------------------------------------------------------------------
        | Property type
        |--------------------------------------------------------------------------
        */

        $result['property_type'] =
            $this->extractPropertyType($normalized);

        /*
        |--------------------------------------------------------------------------
        | Budget
        |--------------------------------------------------------------------------
        */

        $budget =
            $this->extractBudget($normalized);

        $result['min_budget'] =
            $budget['min'];

        $result['max_budget'] =
            $budget['max'];

        $result['budget_currency'] =
            $budget['currency'];

        /*
        |--------------------------------------------------------------------------
        | Bedrooms
        |--------------------------------------------------------------------------
        */

        $bedrooms =
            $this->extractBedrooms($normalized);

        $result['min_bedrooms'] =
            $bedrooms['min'];

        $result['max_bedrooms'] =
            $bedrooms['max'];

        /*
        |--------------------------------------------------------------------------
        | Location
        |--------------------------------------------------------------------------
        */

        $result['location_hint'] =
            $this->extractLocation($original);

        /*
        |--------------------------------------------------------------------------
        | Amenities / POI relations
        |--------------------------------------------------------------------------
        */

        $result['required_amenities'] =
            $this->extractAmenities($normalized);

        /*
        |--------------------------------------------------------------------------
        | Vibe
        |--------------------------------------------------------------------------
        */

        $result['vibe_tags'] =
            $this->extractVibeTags($normalized);

        /*
        |--------------------------------------------------------------------------
        | Confidence
        |--------------------------------------------------------------------------
        */

        $result['confidence'] =
            $this->calculateConfidence($result);

        /*
        |--------------------------------------------------------------------------
        | Contradictions
        |--------------------------------------------------------------------------
        */

        $result['needs_clarification'] =
            $this->hasContradiction(
                $result['required_amenities']
            );

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | Normalize text
    |--------------------------------------------------------------------------
    */

    private function normalizeText(string $text): string
    {
        $text =
            $this->convertArabicNumbers($text);

        $text =
            mb_strtolower(
                trim($text),
                'UTF-8'
            );

        $replacements = [
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ى' => 'ي',
            'ة' => 'ه',

            '،' => ' ',
            ',' => '',
            '؛' => ' ',
            ';' => ' ',
        ];

        $text =
            strtr(
                $text,
                $replacements
            );

        $text =
            preg_replace(
                '/\s+/u',
                ' ',
                $text
            );

        return trim($text);
    }

    private function convertArabicNumbers(string $text): string
    {
        return strtr(
            $text,
            [
                '٠' => '0',
                '١' => '1',
                '٢' => '2',
                '٣' => '3',
                '٤' => '4',
                '٥' => '5',
                '٦' => '6',
                '٧' => '7',
                '٨' => '8',
                '٩' => '9',

                '۰' => '0',
                '۱' => '1',
                '۲' => '2',
                '۳' => '3',
                '۴' => '4',
                '۵' => '5',
                '۶' => '6',
                '۷' => '7',
                '۸' => '8',
                '۹' => '9',
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Action type
    |--------------------------------------------------------------------------
    */

    private function extractActionType(string $text): ?string
    {
        $rentPatterns = [
            '/\bfor\s+rent\b/u',
            '/\brent\b/u',
            '/\brental\b/u',
            '/\blease\b/u',
            '/\bleasing\b/u',

            '/للايجار/u',
            '/للايجار/u',
            '/ايجار/u',
            '/استئجار/u',
            '/استاجر/u',
            '/اجر/u',
        ];

        foreach ($rentPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return 'rent';
            }
        }

        $buyPatterns = [
            '/\bfor\s+sale\b/u',
            '/\bbuy\b/u',
            '/\bbuying\b/u',
            '/\bpurchase\b/u',
            '/\bsale\b/u',

            '/للبيع/u',
            '/شراء/u',
            '/اشتري/u',
            '/للشراء/u',
            '/بيع/u',
        ];

        foreach ($buyPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return 'buy';
            }
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Property type
    |--------------------------------------------------------------------------
    */

    private function extractPropertyType(string $text): ?string
    {
        $types = [
            'Apartment' => [
                '/\bapartment\b/u',
                '/\bapartments\b/u',
                '/\bflat\b/u',
                '/\bflats\b/u',
                '/شقه/u',
                '/شقق/u',
            ],

            'Villa' => [
                '/\bvilla\b/u',
                '/\bvillas\b/u',
                '/فيلا/u',
                '/فلل/u',
            ],

            'Townhouse' => [
                '/\btownhouse\b/u',
                '/\btown\s+house\b/u',
                '/\btownhouses\b/u',
                '/تاون\s*هاوس/u',
            ],

            'Penthouse' => [
                '/\bpenthouse\b/u',
                '/\bpenthouses\b/u',
                '/بنتهاوس/u',
                '/بنت\s*هاوس/u',
            ],

            'Office' => [
                '/\boffice\b/u',
                '/\boffices\b/u',
                '/مكتب/u',
                '/مكاتب/u',
            ],

            'House' => [
                '/\bhouse\b/u',
                '/\bhouses\b/u',
                '/\bhome\b/u',
                '/\bhomes\b/u',
                '/منزل/u',
                '/بيت/u',
                '/بيوت/u',
            ],

            'Studio' => [
                '/\bstudio\b/u',
                '/\bstudios\b/u',
                '/استوديو/u',
                '/ستوديو/u',
            ],

            'Duplex' => [
                '/\bduplex\b/u',
                '/دوبلكس/u',
            ],

            'Land' => [
                '/\bland\b/u',
                '/\bplot\b/u',
                '/ارض/u',
                '/قطعه ارض/u',
            ],

            'Warehouse' => [
                '/\bwarehouse\b/u',
                '/\bwarehouses\b/u',
                '/مستودع/u',
                '/مخزن/u',
            ],
        ];

        foreach ($types as $type => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text)) {
                    return $type;
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Dynamic DB fallback
        |--------------------------------------------------------------------------
        |
        | If new property types are added to property_types later,
        | try to recognize their actual DB name automatically.
        |
        */

        try {
            $dbTypes =
                DB::table('property_types')
                    ->select('name')
                    ->whereNotNull('name')
                    ->get();

            foreach ($dbTypes as $type) {
                $name =
                    trim(
                        (string) $type->name
                    );

                if ($name === '') {
                    continue;
                }

                if (
                    mb_stripos(
                        $text,
                        mb_strtolower(
                            $name,
                            'UTF-8'
                        ),
                        0,
                        'UTF-8'
                    ) !== false
                ) {
                    return $name;
                }
            }
        } catch (\Throwable $e) {
            // Ignore DB fallback failure.
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Budget
    |--------------------------------------------------------------------------
    */

    private function extractBudget(string $text): array
    {
        $result = [
            'min' => null,
            'max' => null,
            'currency' =>
                $this->extractCurrency($text),
        ];

        /*
        |--------------------------------------------------------------------------
        | BETWEEN X AND Y
        |--------------------------------------------------------------------------
        */

        $rangePatterns = [
            '/\bbetween\s+([0-9]+(?:\.[0-9]+)?\s*(?:k|m|million|thousand)?)\s+(?:and|to)\s+([0-9]+(?:\.[0-9]+)?\s*(?:k|m|million|thousand)?)/u',

            '/\bfrom\s+([0-9]+(?:\.[0-9]+)?\s*(?:k|m|million|thousand)?)\s+to\s+([0-9]+(?:\.[0-9]+)?\s*(?:k|m|million|thousand)?)/u',

            '/من\s+([0-9]+(?:\.[0-9]+)?\s*(?:k|m|مليون|الف)?)\s+(?:الى|الي|ل)\s+([0-9]+(?:\.[0-9]+)?\s*(?:k|m|مليون|الف)?)/u',

            '/بين\s+([0-9]+(?:\.[0-9]+)?\s*(?:k|m|مليون|الف)?)\s+و\s*([0-9]+(?:\.[0-9]+)?\s*(?:k|m|مليون|الف)?)/u',
        ];

        foreach ($rangePatterns as $pattern) {
            if (
                preg_match(
                    $pattern,
                    $text,
                    $matches
                )
            ) {
                $first =
                    $this->parseMoney(
                        $matches[1]
                    );

                $second =
                    $this->parseMoney(
                        $matches[2]
                    );

                if (
                    $first !== null
                    &&
                    $second !== null
                ) {
                    $result['min'] =
                        min(
                            $first,
                            $second
                        );

                    $result['max'] =
                        max(
                            $first,
                            $second
                        );

                    return $result;
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | X - Y
        |--------------------------------------------------------------------------
        */

        if (
            preg_match(
                '/([0-9]+(?:\.[0-9]+)?\s*(?:k|m|million|thousand|مليون|الف)?)\s*-\s*([0-9]+(?:\.[0-9]+)?\s*(?:k|m|million|thousand|مليون|الف)?)/u',
                $text,
                $matches
            )
        ) {
            $first =
                $this->parseMoney(
                    $matches[1]
                );

            $second =
                $this->parseMoney(
                    $matches[2]
                );

            if (
                $first !== null
                &&
                $second !== null
            ) {
                $result['min'] =
                    min(
                        $first,
                        $second
                    );

                $result['max'] =
                    max(
                        $first,
                        $second
                    );

                return $result;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Maximum budget
        |--------------------------------------------------------------------------
        */

        $maxPatterns = [
            '/\bunder\s+([0-9]+(?:\.[0-9]+)?\s*(?:k|m|million|thousand)?)/u',
            '/\bbelow\s+([0-9]+(?:\.[0-9]+)?\s*(?:k|m|million|thousand)?)/u',
            '/\bless\s+than\s+([0-9]+(?:\.[0-9]+)?\s*(?:k|m|million|thousand)?)/u',
            '/\bup\s+to\s+([0-9]+(?:\.[0-9]+)?\s*(?:k|m|million|thousand)?)/u',
            '/\bmaximum\s+([0-9]+(?:\.[0-9]+)?\s*(?:k|m|million|thousand)?)/u',
            '/\bmax\s+([0-9]+(?:\.[0-9]+)?\s*(?:k|m|million|thousand)?)/u',

            '/اقل\s+من\s+([0-9]+(?:\.[0-9]+)?\s*(?:k|m|مليون|الف)?)/u',
            '/تحت\s+([0-9]+(?:\.[0-9]+)?\s*(?:k|m|مليون|الف)?)/u',
            '/حتى\s+([0-9]+(?:\.[0-9]+)?\s*(?:k|m|مليون|الف)?)/u',
            '/حد\s+اقصى\s+([0-9]+(?:\.[0-9]+)?\s*(?:k|m|مليون|الف)?)/u',
        ];

        foreach ($maxPatterns as $pattern) {
            if (
                preg_match(
                    $pattern,
                    $text,
                    $matches
                )
            ) {
                $result['max'] =
                    $this->parseMoney(
                        $matches[1]
                    );

                return $result;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Minimum budget
        |--------------------------------------------------------------------------
        */

        $minPatterns = [
            '/\bover\s+([0-9]+(?:\.[0-9]+)?\s*(?:k|m|million|thousand)?)/u',
            '/\babove\s+([0-9]+(?:\.[0-9]+)?\s*(?:k|m|million|thousand)?)/u',
            '/\bmore\s+than\s+([0-9]+(?:\.[0-9]+)?\s*(?:k|m|million|thousand)?)/u',
            '/\bat\s+least\s+([0-9]+(?:\.[0-9]+)?\s*(?:k|m|million|thousand)?)/u',
            '/\bminimum\s+([0-9]+(?:\.[0-9]+)?\s*(?:k|m|million|thousand)?)/u',
            '/\bmin\s+([0-9]+(?:\.[0-9]+)?\s*(?:k|m|million|thousand)?)/u',

            '/اكثر\s+من\s+([0-9]+(?:\.[0-9]+)?\s*(?:k|m|مليون|الف)?)/u',
            '/فوق\s+([0-9]+(?:\.[0-9]+)?\s*(?:k|m|مليون|الف)?)/u',
            '/على\s+الاقل\s+([0-9]+(?:\.[0-9]+)?\s*(?:k|m|مليون|الف)?)/u',
            '/حد\s+ادنى\s+([0-9]+(?:\.[0-9]+)?\s*(?:k|m|مليون|الف)?)/u',
        ];

        foreach ($minPatterns as $pattern) {
            if (
                preg_match(
                    $pattern,
                    $text,
                    $matches
                )
            ) {
                $result['min'] =
                    $this->parseMoney(
                        $matches[1]
                    );

                return $result;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Exact / explicit price
        |--------------------------------------------------------------------------
        |
        | Only consider a number an exact price when there is clear
        | price/currency context. This prevents "2 bedrooms" from
        | becoming a price of 2.
        |
        */

        $exactPatterns = [
            '/\b(?:price|budget|cost)\s*(?:is|of|=|:)?\s*([0-9]+(?:\.[0-9]+)?\s*(?:k|m|million|thousand)?)/u',

            '/([0-9]+(?:\.[0-9]+)?\s*(?:k|m|million|thousand)?)\s*(?:aed|dirham|dirhams)\b/u',

            '/(?:ميزانيه|السعر|سعر)\s*(?:هو|حوالي|=|:)?\s*([0-9]+(?:\.[0-9]+)?\s*(?:k|m|مليون|الف)?)/u',

            '/([0-9]+(?:\.[0-9]+)?\s*(?:k|m|مليون|الف)?)\s*(?:درهم|درهما|درهم اماراتي)/u',
        ];

        foreach ($exactPatterns as $pattern) {
            if (
                preg_match(
                    $pattern,
                    $text,
                    $matches
                )
            ) {
                $amount =
                    $this->parseMoney(
                        $matches[1]
                    );

                if ($amount !== null) {
                    $result['min'] =
                        $amount;

                    $result['max'] =
                        $amount;

                    return $result;
                }
            }
        }

        return $result;
    }

    private function parseMoney(string $value): ?float
    {
        $value =
            mb_strtolower(
                trim($value),
                'UTF-8'
            );

        $value =
            str_replace(
                [
                    ',',
                    ' ',
                ],
                '',
                $value
            );

        $multiplier = 1;

        if (
            preg_match(
                '/(million|مليون|m)$/u',
                $value
            )
        ) {
            $multiplier = 1000000;

            $value =
                preg_replace(
                    '/(million|مليون|m)$/u',
                    '',
                    $value
                );
        } elseif (
            preg_match(
                '/(thousand|الف|k)$/u',
                $value
            )
        ) {
            $multiplier = 1000;

            $value =
                preg_replace(
                    '/(thousand|الف|k)$/u',
                    '',
                    $value
                );
        }

        $value =
            trim($value);

        if (
            !is_numeric(
                $value
            )
        ) {
            return null;
        }

        $amount =
            (float) $value
            *
            $multiplier;

        if ($amount < 0) {
            return null;
        }

        return $amount;
    }

    private function extractCurrency(string $text): ?string
    {
        if (
            preg_match(
                '/\b(aed|dirham|dirhams)\b/u',
                $text
            )
            ||
            str_contains(
                $text,
                'درهم'
            )
        ) {
            return 'AED';
        }

        if (
            preg_match(
                '/\b(usd|dollar|dollars)\b/u',
                $text
            )
            ||
            str_contains(
                $text,
                'دولار'
            )
        ) {
            return 'USD';
        }

        if (
            preg_match(
                '/\b(eur|euro|euros)\b/u',
                $text
            )
            ||
            str_contains(
                $text,
                'يورو'
            )
        ) {
            return 'EUR';
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Bedrooms
    |--------------------------------------------------------------------------
    */

    private function extractBedrooms(string $text): array
    {
        $result = [
            'min' => null,
            'max' => null,
        ];

        /*
        |--------------------------------------------------------------------------
        | Range
        |--------------------------------------------------------------------------
        */

        $rangePatterns = [
            '/\b([0-9]+)\s*(?:-|to)\s*([0-9]+)\s*(?:bedrooms?|beds?|br)\b/u',

            '/(?:من|بين)\s*([0-9]+)\s*(?:الى|الي|و)\s*([0-9]+)\s*(?:غرف|غرفه|غرف نوم)/u',
        ];

        foreach ($rangePatterns as $pattern) {
            if (
                preg_match(
                    $pattern,
                    $text,
                    $matches
                )
            ) {
                $first =
                    (int) $matches[1];

                $second =
                    (int) $matches[2];

                $result['min'] =
                    min(
                        $first,
                        $second
                    );

                $result['max'] =
                    max(
                        $first,
                        $second
                    );

                return $result;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Minimum bedrooms
        |--------------------------------------------------------------------------
        */

        $minPatterns = [
            '/\bat\s+least\s+([0-9]+)\s*(?:bedrooms?|beds?|br)\b/u',
            '/\b([0-9]+)\+\s*(?:bedrooms?|beds?|br)\b/u',
            '/\b([0-9]+)\s+or\s+more\s+(?:bedrooms?|beds?)\b/u',

            '/على\s+الاقل\s+([0-9]+)\s*(?:غرف|غرفه|غرف نوم)/u',
            '/([0-9]+)\+\s*(?:غرف|غرفه|غرف نوم)/u',
            '/([0-9]+)\s*(?:غرف|غرفه|غرف نوم)\s*(?:او اكثر|فاكثر)/u',
        ];

        foreach ($minPatterns as $pattern) {
            if (
                preg_match(
                    $pattern,
                    $text,
                    $matches
                )
            ) {
                $result['min'] =
                    (int) $matches[1];

                return $result;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Maximum bedrooms
        |--------------------------------------------------------------------------
        */

        $maxPatterns = [
            '/\b(?:up\s+to|max(?:imum)?)\s+([0-9]+)\s*(?:bedrooms?|beds?|br)\b/u',

            '/(?:حتى|حد اقصى)\s+([0-9]+)\s*(?:غرف|غرفه|غرف نوم)/u',
        ];

        foreach ($maxPatterns as $pattern) {
            if (
                preg_match(
                    $pattern,
                    $text,
                    $matches
                )
            ) {
                $result['max'] =
                    (int) $matches[1];

                return $result;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Exact bedrooms
        |--------------------------------------------------------------------------
        */

        $exactPatterns = [
            '/\b([0-9]+)\s*(?:bedrooms?|beds?|br)\b/u',
            '/(?:bedrooms?|beds?)\s*[:=]?\s*([0-9]+)/u',

            '/([0-9]+)\s*(?:غرف نوم|غرف|غرفه)/u',
            '/(?:غرف نوم|غرف|غرفه)\s*[:=]?\s*([0-9]+)/u',
        ];

        foreach ($exactPatterns as $pattern) {
            if (
                preg_match(
                    $pattern,
                    $text,
                    $matches
                )
            ) {
                $value =
                    (int) $matches[1];

                $result['min'] =
                    $value;

                $result['max'] =
                    $value;

                return $result;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Arabic words
        |--------------------------------------------------------------------------
        */

        $arabicWords = [
            'غرفه واحده' => 1,
            'غرفتين' => 2,
            'غرفتان' => 2,
            'ثلاث غرف' => 3,
            'ثلاثه غرف' => 3,
            'اربع غرف' => 4,
            'اربعه غرف' => 4,
            'خمس غرف' => 5,
            'خمسه غرف' => 5,
        ];

        foreach ($arabicWords as $phrase => $value) {
            if (
                str_contains(
                    $text,
                    $phrase
                )
            ) {
                $result['min'] =
                    $value;

                $result['max'] =
                    $value;

                return $result;
            }
        }

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | Location
    |--------------------------------------------------------------------------
    */

    private function extractLocation(string $original): ?string
    {
        $normalized =
            $this->normalizeText($original);

        /*
        |--------------------------------------------------------------------------
        | First: use actual location names from the DB
        |--------------------------------------------------------------------------
        */

        $dbLocation =
            $this->findLocationInDatabase(
                $normalized
            );

        if ($dbLocation !== null) {
            return $dbLocation;
        }

        /*
        |--------------------------------------------------------------------------
        | Common UAE locations fallback
        |--------------------------------------------------------------------------
        */

        $locations = [
            'Dubai Marina' => [
                'dubai marina',
                'دبي مارينا',
            ],

            'Downtown Dubai' => [
                'downtown dubai',
                'downtown',
                'وسط مدينه دبي',
                'وسط دبي',
            ],

            'Palm Jumeirah' => [
                'palm jumeirah',
                'نخله جميرا',
                'النخله',
            ],

            'Jumeirah Village Circle' => [
                'jumeirah village circle',
                'jvc',
                'جميرا فيليج سيركل',
            ],

            'Business Bay' => [
                'business bay',
                'بزنس باي',
                'الخليج التجاري',
            ],

            'Dubai' => [
                'dubai',
                'دبي',
            ],

            'Abu Dhabi' => [
                'abu dhabi',
                'ابو ظبي',
                'ابوظبي',
            ],

            'Sharjah' => [
                'sharjah',
                'الشارقه',
                'شارقه',
            ],

            'Ajman' => [
                'ajman',
                'عجمان',
            ],

            'Ras Al Khaimah' => [
                'ras al khaimah',
                'راس الخيمه',
            ],

            'Fujairah' => [
                'fujairah',
                'الفجيره',
            ],

            'Umm Al Quwain' => [
                'umm al quwain',
                'ام القيوين',
            ],
        ];

        /*
         * Longest/specific locations are intentionally checked
         * before broad emirate names such as Dubai.
         */

        foreach ($locations as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                if (
                    str_contains(
                        $normalized,
                        $alias
                    )
                ) {
                    return $canonical;
                }
            }
        }

        return null;
    }

    private function findLocationInDatabase(
        string $text
    ): ?string {
        try {
            /*
            |--------------------------------------------------------------------------
            | Neighborhood translations
            |--------------------------------------------------------------------------
            */

            $translations =
                DB::table(
                    'neighborhood_translations'
                )
                    ->select(
                        'name'
                    )
                    ->whereNotNull(
                        'name'
                    )
                    ->get();

            $bestMatch = null;
            $bestLength = 0;

            foreach ($translations as $row) {
                $name =
                    trim(
                        (string) $row->name
                    );

                if ($name === '') {
                    continue;
                }

                $normalizedName =
                    $this->normalizeText(
                        $name
                    );

                if (
                    mb_strlen(
                        $normalizedName
                    ) < 3
                ) {
                    continue;
                }

                if (
                    str_contains(
                        $text,
                        $normalizedName
                    )
                ) {
                    $length =
                        mb_strlen(
                            $normalizedName
                        );

                    if (
                        $length
                        >
                        $bestLength
                    ) {
                        $bestLength =
                            $length;

                        $bestMatch =
                            $name;
                    }
                }
            }

            if ($bestMatch !== null) {
                return $bestMatch;
            }

            /*
            |--------------------------------------------------------------------------
            | Cities
            |--------------------------------------------------------------------------
            */

            $cities =
                DB::table(
                    'cities'
                )
                    ->select(
                        'name'
                    )
                    ->whereNotNull(
                        'name'
                    )
                    ->get();

            foreach ($cities as $row) {
                $name =
                    trim(
                        (string) $row->name
                    );

                if (
                    $name !== ''
                    &&
                    str_contains(
                        $text,
                        $this->normalizeText(
                            $name
                        )
                    )
                ) {
                    return $name;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Emirates
            |--------------------------------------------------------------------------
            */

            $emirates =
                DB::table(
                    'emirates'
                )
                    ->select(
                        'name'
                    )
                    ->whereNotNull(
                        'name'
                    )
                    ->get();

            foreach ($emirates as $row) {
                $name =
                    trim(
                        (string) $row->name
                    );

                if (
                    $name !== ''
                    &&
                    str_contains(
                        $text,
                        $this->normalizeText(
                            $name
                        )
                    )
                ) {
                    return $name;
                }
            }
        } catch (\Throwable $e) {
            /*
             * Location fallback below will still work if a location
             * table/column differs in a deployment.
             */
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Amenities
    |--------------------------------------------------------------------------
    */

    private function extractAmenities(
        string $text
    ): array {
        $amenities = [];

        $dictionary = [
            'school' => [
                'school',
                'schools',
                'مدرسه',
                'مدارس',
            ],

            'hospital' => [
                'hospital',
                'hospitals',
                'clinic',
                'clinics',
                'مستشفى',
                'مستشفيات',
                'عياده',
                'عيادات',
            ],

            'cafe' => [
                'cafe',
                'cafes',
                'coffee shop',
                'coffee shops',
                'كافيه',
                'كافيهات',
                'مقهى',
                'مقاهي',
            ],

            'restaurant' => [
                'restaurant',
                'restaurants',
                'مطعم',
                'مطاعم',
            ],

            'supermarket' => [
                'supermarket',
                'supermarkets',
                'grocery',
                'groceries',
                'سوبرماركت',
                'بقاله',
            ],

            'mall' => [
                'mall',
                'malls',
                'shopping mall',
                'مول',
                'مولات',
            ],

            'metro' => [
                'metro',
                'metro station',
                'subway',
                'مترو',
                'محطه مترو',
            ],

            'park' => [
                'park',
                'parks',
                'garden',
                'حديقه',
                'حدائق',
            ],

            'beach' => [
                'beach',
                'beaches',
                'شاطئ',
                'بحر',
            ],

            'gym' => [
                'gym',
                'fitness center',
                'نادي رياضي',
                'جيم',
            ],

            'mosque' => [
                'mosque',
                'mosques',
                'مسجد',
                'مساجد',
            ],

            'nightclub' => [
                'nightclub',
                'night club',
                'nightclubs',
                'club',
                'ملهى ليلي',
                'ملاهي ليليه',
            ],
        ];

        foreach (
            $dictionary
            as
            $canonical => $aliases
        ) {
            foreach ($aliases as $alias) {
                $position =
                    mb_stripos(
                        $text,
                        $alias,
                        0,
                        'UTF-8'
                    );

                if ($position === false) {
                    continue;
                }

                $before =
                    mb_substr(
                        $text,
                        max(
                            0,
                            $position - 45
                        ),
                        min(
                            45,
                            $position
                        ),
                        'UTF-8'
                    );

                $relation =
                    $this->detectAmenityRelation(
                        $before
                    );

                /*
                 * Only create a POI filter when the user actually
                 * expresses a spatial relation.
                 */

                if ($relation === null) {
                    continue;
                }

                $amenities[] = [
                    'name' =>
                        $canonical,

                    'relation' =>
                        $relation,
                ];

                break;
            }
        }

        return array_values(
            $this->uniqueAmenities(
                $amenities
            )
        );
    }

    private function detectAmenityRelation(
        string $context
    ): ?string {
        $nearPatterns = [
            '/\bnear\b/u',
            '/\bnearby\b/u',
            '/\bclose\s+to\b/u',
            '/\bclose\b/u',
            '/\bnext\s+to\b/u',
            '/\bwalking\s+distance\b/u',

            '/قريب\s+من/u',
            '/بالقرب\s+من/u',
            '/جنب/u',
            '/قريبه\s+من/u',
        ];

        foreach ($nearPatterns as $pattern) {
            if (
                preg_match(
                    $pattern,
                    $context
                )
            ) {
                return 'near';
            }
        }

        $farPatterns = [
            '/\bfar\s+from\b/u',
            '/\baway\s+from\b/u',
            '/\bnot\s+near\b/u',
            '/\bavoid\b/u',

            '/بعيد\s+عن/u',
            '/بعيده\s+عن/u',
            '/ليس\s+قريب/u',
            '/مش\s+قريب/u',
        ];

        foreach ($farPatterns as $pattern) {
            if (
                preg_match(
                    $pattern,
                    $context
                )
            ) {
                return 'far';
            }
        }

        return null;
    }

    private function uniqueAmenities(
        array $amenities
    ): array {
        $result = [];

        foreach ($amenities as $amenity) {
            $key =
                strtolower(
                    (string) $amenity['name']
                )
                .
                ':'
                .
                strtolower(
                    (string) $amenity['relation']
                );

            $result[$key] =
                $amenity;
        }

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | Vibe tags
    |--------------------------------------------------------------------------
    */

    private function extractVibeTags(
        string $text
    ): array {
        $dictionary = [
            'quiet' => [
                'quiet',
                'peaceful',
                'calm',
                'هادئ',
                'هادي',
                'هدوء',
            ],

            'family_friendly' => [
                'family friendly',
                'family-friendly',
                'for family',
                'families',
                'عائلي',
                'للعائلات',
                'مناسب للعائلات',
            ],

            'luxury' => [
                'luxury',
                'luxurious',
                'premium',
                'فاخر',
                'فخم',
                'راقي',
            ],

            'modern' => [
                'modern',
                'contemporary',
                'حديث',
                'عصري',
            ],

            'lively' => [
                'lively',
                'vibrant',
                'active area',
                'حيوي',
                'نشيط',
            ],
        ];

        $tags = [];

        foreach (
            $dictionary
            as
            $tag => $aliases
        ) {
            foreach ($aliases as $alias) {
                if (
                    str_contains(
                        $text,
                        $alias
                    )
                ) {
                    $tags[] =
                        $tag;

                    break;
                }
            }
        }

        return array_values(
            array_unique(
                $tags
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Confidence
    |--------------------------------------------------------------------------
    */

    private function calculateConfidence(array $result): float
{
    $recognized = 0;
    $possible = 0;

    // Property type
    $possible++;
    if (!empty($result['property_type'])) {
        $recognized++;
    }

    // Action type
    $possible++;
    if (!empty($result['action_type'])) {
        $recognized++;
    }

    // Budget
    $possible++;
    if (
        $result['min_budget'] !== null
        ||
        $result['max_budget'] !== null
    ) {
        $recognized++;
    }

    // Bedrooms are optional, so only count them when detected.
    if (
        $result['min_bedrooms'] !== null
        ||
        $result['max_bedrooms'] !== null
    ) {
        $possible++;
        $recognized++;
    }

    // Location is optional.
    if (!empty($result['location_hint'])) {
        $possible++;
        $recognized++;
    }

    // Amenities are optional.
    if (!empty($result['required_amenities'])) {
        $possible++;
        $recognized++;
    }

    // Vibe is optional.
    if (!empty($result['vibe_tags'])) {
        $possible++;
        $recognized++;
    }
// Furnishing status is optional.
if (!empty($result['furnishing_status'])) {
    $possible++;
    $recognized++;
}
    if ($possible === 0) {
        return 0.0;
    }

    return round(
        min(
            1.0,
            $recognized / $possible
        ),
        2
    );
}
    /*
    |--------------------------------------------------------------------------
    | Contradictions
    |--------------------------------------------------------------------------
    */

    private function hasContradiction(
        array $amenities
    ): bool {
        $relations = [];

        foreach ($amenities as $amenity) {
            $name =
                strtolower(
                    (string) (
                        $amenity['name']
                        ?? ''
                    )
                );

            $relation =
                strtolower(
                    (string) (
                        $amenity['relation']
                        ?? ''
                    )
                );

            if (
                $name === ''
                ||
                $relation === ''
            ) {
                continue;
            }

            $relations[$name][$relation] =
                true;
        }

        foreach ($relations as $items) {
            if (
                isset(
                    $items['near']
                )
                &&
                isset(
                    $items['far']
                )
            ) {
                return true;
            }
        }

        return false;
    }
}