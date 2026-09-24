<?php

namespace App\Http\Controllers\Api;
use App\Services\VibeAiService;
use App\Http\Controllers\Controller;
use Cloudinary\Cloudinary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PropertyController extends Controller
{
    /**
     * Get property listings.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->query('per_page', 20);
            $perPage = max(1, min($perPage, 100));

            $sort = (string) $request->query('sort', '');
            $hasNearbySort = $sort === 'nearby';

            $latitude = $request->filled('latitude')
                ? (float) $request->query('latitude')
                : null;

            $longitude = $request->filled('longitude')
                ? (float) $request->query('longitude')
                : null;

            if ($hasNearbySort && ($latitude === null || $longitude === null)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Latitude and longitude are required for nearby sorting',
                ], 422);
            }

            if (
                $latitude !== null &&
                ($latitude < -90 || $latitude > 90)
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Latitude must be between -90 and 90',
                ], 422);
            }

            if (
                $longitude !== null &&
                ($longitude < -180 || $longitude > 180)
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Longitude must be between -180 and 180',
                ], 422);
            }

            $query = DB::table('properties as p')
                ->whereNull('p.deleted_at')
                ->where('p.moderation_status', 'approved');

            if ($hasNearbySort) {
                $query
                    ->join(
                        'property_locations as nearby_pl',
                        'nearby_pl.property_id',
                        '=',
                        'p.id'
                    )
                    ->whereNotNull('nearby_pl.latitude')
                    ->whereNotNull('nearby_pl.longitude');
            }

         $query->select([
    'p.id',
    'p.owner_id',
    'p.agency_id',
    'p.type_id',
    'p.category_id',
    'p.status_id',
    'p.moderation_status',
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
    'p.created_at',
    'p.updated_at',
]);
            if ($hasNearbySort) {
                $distanceSql = '6371 * ACOS(LEAST(1, GREATEST(-1, '
                    . 'COS(RADIANS(?)) * COS(RADIANS(nearby_pl.latitude)) '
                    . '* COS(RADIANS(nearby_pl.longitude) - RADIANS(?)) '
                    . '+ SIN(RADIANS(?)) * SIN(RADIANS(nearby_pl.latitude))'
                    . ')))';

                $query
                    ->selectRaw(
                        $distanceSql . ' AS distance_km',
                        [$latitude, $longitude, $latitude]
                    )
                    ->orderBy('distance_km')
                    ->orderByDesc('p.id');
            } else {
                $query->orderByDesc('p.id');
            }

            /*
            |--------------------------------------------------------------------------
            | Optional Filters
            |--------------------------------------------------------------------------
            */
if ($request->filled('neighborhood_id')) {

    $neighborhoodId = (int) $request->query(
        'neighborhood_id'
    );

    $query->whereExists(function ($subQuery) use (
        $neighborhoodId
    ) {
        $subQuery
            ->select(DB::raw(1))
            ->from('property_locations as pl')
            ->whereColumn(
                'pl.property_id',
                'p.id'
            )
            ->where(
                'pl.neighborhood_id',
                $neighborhoodId
            );
    });
}

            if ($request->filled('is_featured')) {
                $query->where(
                    'p.is_featured',
                    (int) $request->query('is_featured')
                );
            }

            if ($request->filled('type_id')) {
                $query->where(
                    'p.type_id',
                    (int) $request->query('type_id')
                );
            }

            if ($request->filled('category_id')) {
                $query->where(
                    'p.category_id',
                    (int) $request->query('category_id')
                );
            }

            if ($request->filled('status_id')) {
                $query->where(
                    'p.status_id',
                    (int) $request->query('status_id')
                );
            }

            if ($request->filled('bedrooms')) {
                $query->where(
                    'p.bedrooms',
                    (int) $request->query('bedrooms')
                );
            }

            if ($request->filled('bathrooms')) {
                $query->where(
                    'p.bathrooms',
                    (int) $request->query('bathrooms')
                );
            }

            if ($request->filled('min_price')) {
                $query->where(
                    'p.price',
                    '>=',
                    (float) $request->query('min_price')
                );
            }

            if ($request->filled('max_price')) {
                $query->where(
                    'p.price',
                    '<=',
                    (float) $request->query('max_price')
                );
            }

            if ($request->filled('search')) {
                $search = trim(
                    (string) $request->query('search')
                );

                $query->where(function ($q) use ($search) {
                    $q->where(
                        'p.title',
                        'like',
                        '%' . $search . '%'
                    )->orWhere(
                        'p.description',
                        'like',
                        '%' . $search . '%'
                    );
                });
            }

            $properties = $query->paginate($perPage);

            /*
            |--------------------------------------------------------------------------
            | Load related data
            |--------------------------------------------------------------------------
            */

            $items = collect($properties->items());

            $propertyIds = $items
                ->pluck('id')
                ->values()
                ->all();

            $images = collect();
            $locations = collect();
            $features = collect();
            $reviews = collect();

            if (!empty($propertyIds)) {

                $images = DB::table('property_images')
                    ->whereIn('property_id', $propertyIds)
                    ->select([
                        'id',
                        'property_id',
                        'image_url',
                        'is_primary',
                        'display_order',
                    ])
                    ->orderBy('display_order')
                    ->get()
                    ->groupBy('property_id');

                $locations = DB::table('property_locations as pl')
                    ->leftJoin('neighborhoods as n', 'n.id', '=', 'pl.neighborhood_id')
                    ->leftJoin('neighborhood_translations as nt_en', function ($join) {
                        $join->on('nt_en.neighborhood_id', '=', 'pl.neighborhood_id')
                            ->where('nt_en.language_code', '=', 'en');
                    })
                    ->leftJoin('neighborhood_translations as nt_ar', function ($join) {
                        $join->on('nt_ar.neighborhood_id', '=', 'pl.neighborhood_id')
                            ->where('nt_ar.language_code', '=', 'ar');
                    })
                    ->whereIn('pl.property_id', $propertyIds)
                    ->select([
                        'pl.id',
                        'pl.property_id',
                        'pl.address_line_1',
                        'pl.address_line_2',
                        'pl.building_name',
                        'pl.latitude',
                        'pl.longitude',
                        'pl.neighborhood_id',
                        'n.name as neighborhood_name',
                        DB::raw('COALESCE(nt_en.name, n.name) as neighborhood_en'),
                        'nt_ar.name as neighborhood_ar',
                        'pl.street_id',
                    ])
                    ->get()
                    ->keyBy('property_id');

                $features = DB::table(
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
                    ->orderBy('pf.id')
                    ->get()
                    ->groupBy('property_id');


                $reviews = DB::table('reviews')
                    ->whereIn('property_id', $propertyIds)
                    ->select([
                        'property_id',
                        'rating',
                        'review',
                        'created_at',
                    ])
                    ->orderByDesc('created_at')
                    ->get()
                    ->groupBy('property_id');            }

            $data = $items->map(function ($property) use (
                $images,
                $locations,
                $features,
                $reviews
            ) {
                $propertyId = $property->id;

                $property->images = $images
                    ->get($propertyId, collect())
                    ->values();

                $property->location = $locations
                    ->get($propertyId);

                $property->features = $features
                    ->get($propertyId, collect())
                    ->values();

                $propertyReviews = $reviews
                    ->get($propertyId, collect());

                $property->rating = $propertyReviews->isNotEmpty()
                    ? round((float) $propertyReviews->avg('rating'), 1)
                    : 0.0;

                $property->reviews = $propertyReviews
                    ->pluck('review')
                    ->filter(function ($review) {
                        return $review !== null && trim($review) !== '';
                    })
                    ->values()
                    ->all();

                if (isset($property->distance_km)) {
                    $property->distance_km = round(
                        (float) $property->distance_km,
                        2
                    );
                }

                return $property;
            });

            return response()->json([
                'success' => true,

                'data' => $data,

                'pagination' => [
                    'current_page' => $properties->currentPage(),
                    'per_page' => $properties->perPage(),
                    'total' => $properties->total(),
                    'last_page' => $properties->lastPage(),
                ],
            ]);

        } catch (Throwable $e) {

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load properties',
            ], 500);
        }
    }

/**
 * Create a new property.
 */
public function store(Request $request): JsonResponse
{
    $user = $request->attributes->get('auth_user');

    if (!$user || empty($user['id'])) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthenticated',
        ], 401);
    }

    $validator = validator($request->all(), [
        'title' => 'required|string|max:255',
        'type_id' => 'required|integer|exists:property_types,id',

        'listing_type' => 'required|in:sale,rent',
        'action_type' => 'nullable|in:buy,rent,booking',

        'price' => 'required|numeric|min:0',
        'rent_frequency' => 'nullable|in:monthly,yearly',

        'description' => 'required|string',
        'virtual_tour_url' => 'nullable|url|max:500',

        'neighborhood_id' => 'required|integer|exists:neighborhoods,id',

        'address_line_1' => 'required|string|max:255',
        'address_line_2' => 'nullable|string|max:255',
        'building_name' => 'nullable|string|max:150',

        'latitude' => 'required|numeric|between:-90,90',
        'longitude' => 'required|numeric|between:-180,180',

        'area_sqft' => 'required|numeric|min:0.01',
        'bedrooms' => 'required|integer|min:0',
        'bathrooms' => 'required|integer|min:0',

        'property_condition' => 'required|in:ready,off_plan',

        'features' => 'nullable|array',
        'features.*' => 'integer|distinct|exists:property_features,id',

        'cover_image' => [
            'required',
            'file',
            'image',
            'mimes:jpg,jpeg,png,webp',
            'max:10240',
        ],

        'gallery_images' => 'nullable|array|max:20',

        'gallery_images.*' => [
            'file',
            'image',
            'mimes:jpg,jpeg,png,webp',
            'max:10240',
        ],
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid property data',
            'errors' => $validator->errors(),
        ], 422);
    }

    $ownerId = (int) $user['id'];

    /*
    |--------------------------------------------------------------------------
    | Get Listing Category
    |--------------------------------------------------------------------------
    */

    $categoryName = $request->input('listing_type') === 'sale'
        ? 'For Sale'
        : 'For Rent';

    $categoryId = DB::table('property_categories')
        ->where('name', $categoryName)
        ->value('id');

    if (!$categoryId) {
        return response()->json([
            'success' => false,
            'message' => 'Property category not found',
        ], 500);
    }

    /*
    |--------------------------------------------------------------------------
    | Default Property Status
    |--------------------------------------------------------------------------
    */

    $statusId = DB::table('property_status')
        ->where('name', 'Active')
        ->value('id');

    if (!$statusId) {
        return response()->json([
            'success' => false,
            'message' => 'Active property status not found',
        ], 500);
    }

    /*
    |--------------------------------------------------------------------------
    | Get User Agency
    |--------------------------------------------------------------------------
    */

    $agencyId = DB::table('agency_agents')
        ->where('user_id', $ownerId)
        ->value('agency_id');

    /*
    |--------------------------------------------------------------------------
    | Cloudinary
    |--------------------------------------------------------------------------
    */

    $cloudinaryUrl = env('CLOUDINARY_URL');

    if (!$cloudinaryUrl) {
        return response()->json([
            'success' => false,
            'message' => 'Cloudinary is not configured',
        ], 500);
    }

    $cloudinary = new Cloudinary($cloudinaryUrl);

    $uploadedPublicIds = [];

    try {

        /*
        |--------------------------------------------------------------------------
        | Upload Cover Image
        |--------------------------------------------------------------------------
        */

        $coverUpload = $cloudinary
            ->uploadApi()
            ->upload(
                $request->file('cover_image')->getRealPath(),
                [
                    'folder' => 'vibelocate/property-images',
                    'resource_type' => 'image',
                ]
            );

        $coverUrl = $coverUpload['secure_url'] ?? null;

        if (!$coverUrl) {
            throw new \RuntimeException(
                'Cover image upload failed'
            );
        }

        if (!empty($coverUpload['public_id'])) {
            $uploadedPublicIds[] =
                $coverUpload['public_id'];
        }

        /*
        |--------------------------------------------------------------------------
        | Upload Gallery Images
        |--------------------------------------------------------------------------
        */

        $galleryUrls = [];

        foreach (
            $request->file('gallery_images', [])
            as $galleryImage
        ) {
            $upload = $cloudinary
                ->uploadApi()
                ->upload(
                    $galleryImage->getRealPath(),
                    [
                        'folder' => 'vibelocate/property-images',
                        'resource_type' => 'image',
                    ]
                );

            $galleryUrl =
                $upload['secure_url'] ?? null;

            if (!$galleryUrl) {
                throw new \RuntimeException(
                    'Gallery image upload failed'
                );
            }

            $galleryUrls[] = $galleryUrl;

            if (!empty($upload['public_id'])) {
                $uploadedPublicIds[] =
                    $upload['public_id'];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Save Everything In Transaction
        |--------------------------------------------------------------------------
        */

        $propertyId = DB::transaction(
            function () use (
                $request,
                $ownerId,
                $agencyId,
                $categoryId,
                $statusId,
                $coverUrl,
                $galleryUrls
            ) {

                /*
                |--------------------------------------------------------------------------
                | Generate Slug
                |--------------------------------------------------------------------------
                */

                $slugBase = Str::slug(
                    $request->input('title')
                );

                if (!$slugBase) {
                    $slugBase = 'property';
                }

                $slug = $slugBase
                    . '-'
                    . Str::lower(Str::random(8));

                /*
                |--------------------------------------------------------------------------
                | Add Property
                |--------------------------------------------------------------------------
                */

                $propertyId = DB::table('properties')
                    ->insertGetId([
                        'owner_id' => $ownerId,

                        'agency_id' =>
                            $agencyId ?: null,

                        'type_id' =>
                            (int) $request->input(
                                'type_id'
                            ),

                        'category_id' =>
                            (int) $categoryId,

                        'status_id' =>
                            (int) $statusId,

                        // Every newly submitted property must be reviewed by admin.
                        'moderation_status' => 'pending',
                        'rejection_reason' => null,
                        'reviewed_by' => null,
                        'reviewed_at' => null,

                        'property_condition' =>
                            $request->input(
                                'property_condition'
                            ),
'action_type' =>
    $request->filled('action_type')
        ? $request->input('action_type')
        : (
            $request->input('listing_type') === 'sale'
                ? 'buy'
                : 'rent'
        ),
                        'is_featured' => 0,

                        'title' =>
                            trim(
                                $request->input('title')
                            ),

                        'slug' => $slug,

                        'description' =>
                            trim(
                                $request->input(
                                    'description'
                                )
                            ),

                        'virtual_tour_url' =>
                            $request->filled(
                                'virtual_tour_url'
                            )
                                ? trim(
                                    $request->input(
                                        'virtual_tour_url'
                                    )
                                )
                                : null,

                        'price' =>
                            $request->input('price'),

                        'currency' => 'AED',

                        'rent_frequency' =>
                            $request->input(
                                'listing_type'
                            ) === 'rent'
                                ? (
                                    $request->input(
                                        'rent_frequency'
                                    ) ?: 'yearly'
                                )
                                : 'yearly',

                        'area_sqft' =>
                            $request->input(
                                'area_sqft'
                            ),

                        'bedrooms' =>
                            (int) $request->input(
                                'bedrooms'
                            ),

                        'bathrooms' =>
                            (int) $request->input(
                                'bathrooms'
                            ),

                        'is_furnished' =>
                            'unfurnished',

                        'availability_date' =>
                            now()->toDateString(),

                        'listing_date' => now(),

                        'created_at' => now(),

                        'updated_at' => now(),
                    ]);

                /*
                |--------------------------------------------------------------------------
                | Add Location
                |--------------------------------------------------------------------------
                */

                $latitude =
                    (float) $request->input(
                        'latitude'
                    );

                $longitude =
                    (float) $request->input(
                        'longitude'
                    );

                DB::table('property_locations')
                    ->insert([
                        'property_id' =>
                            $propertyId,

                        'street_id' => null,

                        'neighborhood_id' =>
                            (int) $request->input(
                                'neighborhood_id'
                            ),

                        'address_line_1' =>
                            trim(
                                $request->input(
                                    'address_line_1'
                                )
                            ),

                        'address_line_2' =>
                            $request->filled(
                                'address_line_2'
                            )
                                ? trim(
                                    $request->input(
                                        'address_line_2'
                                    )
                                )
                                : null,

                        'building_name' =>
                            $request->filled(
                                'building_name'
                            )
                                ? trim(
                                    $request->input(
                                        'building_name'
                                    )
                                )
                                : null,

                        'latitude' =>
                            $latitude,

                        'longitude' =>
                            $longitude,

                        'coordinates' =>
                            DB::raw(
                                sprintf(
                                    'POINT(%F, %F)',
                                    $longitude,
                                    $latitude
                                )
                            ),

                        'created_at' => now(),

                        'updated_at' => now(),
                    ]);

                /*
                |--------------------------------------------------------------------------
                | Cover Image
                |--------------------------------------------------------------------------
                */

                DB::table('property_images')
                    ->insert([
                        'property_id' =>
                            $propertyId,

                        'image_url' =>
                            $coverUrl,

                        'is_primary' => 1,

                        'display_order' => 0,

                        'created_at' => now(),
                    ]);

                /*
                |--------------------------------------------------------------------------
                | Gallery Images
                |--------------------------------------------------------------------------
                */

                foreach (
                    $galleryUrls
                    as $index => $galleryUrl
                ) {
                    DB::table('property_images')
                        ->insert([
                            'property_id' =>
                                $propertyId,

                            'image_url' =>
                                $galleryUrl,

                            'is_primary' => 0,

                            'display_order' =>
                                $index + 1,

                            'created_at' => now(),
                        ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Amenities
                |--------------------------------------------------------------------------
                */

                foreach (
                    $request->input(
                        'features',
                        []
                    ) as $featureId
                ) {
                    DB::table(
                        'property_feature_values'
                    )->insert([
                        'property_id' =>
                            $propertyId,

                        'feature_id' =>
                            (int) $featureId,

                        'feature_value' => '1',
                    ]);
                }

                return $propertyId;
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Success
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'success' => true,
            'message' =>
                'Property submitted successfully and is pending admin approval',
            'property_id' =>
                $propertyId,
            'moderation_status' => 'pending',
        ], 201);

    } catch (Throwable $e) {

        /*
        |--------------------------------------------------------------------------
        | Remove Uploaded Images If Something Failed
        |--------------------------------------------------------------------------
        */

        foreach ($uploadedPublicIds as $publicId) {
            try {
                $cloudinary
                    ->uploadApi()
                    ->destroy(
                        $publicId,
                        [
                            'resource_type' => 'image',
                        ]
                    );
            } catch (Throwable $cleanupException) {
                Log::warning(
                    'Property image cleanup failed',
                    [
                        'public_id' => $publicId,
                        'error' =>
                            $cleanupException
                                ->getMessage(),
                    ]
                );
            }
        }

        Log::error(
            'Property creation failed',
            [
                'user_id' => $ownerId,
                'error' => $e->getMessage(),
            ]
        );

        report($e);

        return response()->json([
            'success' => false,
            'message' =>
                'Failed to create property',
        ], 500);
    }
}
  /**
 * Get one property with its related data.
 */
public function show(Request $request, int $id): JsonResponse{
    try {
$user = $request->attributes->get('auth_user');

if (!$user || empty($user['id'])) {
    return response()->json([
        'success' => false,
        'message' => 'Unauthenticated',
    ], 401);
}
        $property = DB::table('properties as p')
            ->leftJoin(
                'property_types as pt',
                'pt.id',
                '=',
                'p.type_id'
            )
            ->leftJoin(
                'property_categories as pc',
                'pc.id',
                '=',
                'p.category_id'
            )
            ->leftJoin(
                'property_status as ps',
                'ps.id',
                '=',
                'p.status_id'
            )
            ->leftJoin(
                'users as u',
                'u.id',
                '=',
                'p.owner_id'
            )
            ->leftJoin(
                'agencies as a',
                'a.id',
                '=',
                'p.agency_id'
            )
            ->where('p.id', $id)
            ->whereNull('p.deleted_at')
            ->select([
                'p.id',
                'p.owner_id',
                'p.agency_id',
                'p.type_id',
                'pt.name as type_name',
                'p.category_id',
                'pc.name as category_name',
                'p.status_id',
                'ps.name as status_name',
                'p.moderation_status',
                'p.rejection_reason',
                'p.reviewed_by',
                'p.reviewed_at',
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
                'p.created_at',
                'p.updated_at',
                'u.first_name as owner_first_name',
                'u.last_name as owner_last_name',
                'u.email as owner_email',
                'u.phone as owner_phone',
                'a.name as agency_name',
            ])
            ->first();

        if (!$property) {
            return response()->json([
                'success' => false,
                'message' => 'Property not found',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Moderation Visibility
        |--------------------------------------------------------------------------
        */

        if ($property->moderation_status !== 'approved') {
            $currentUserId = (int) $user['id'];

            $isAdmin = DB::table('roles as r')
                ->join('user_roles as ur', 'ur.role_id', '=', 'r.id')
                ->where('ur.user_id', $currentUserId)
                ->whereIn('r.slug', ['admin', 'super-admin'])
                ->exists();

            $isOwner = (int) $property->owner_id === $currentUserId;

            if (!$isAdmin && !$isOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Property not found',
                ], 404);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Images
        |--------------------------------------------------------------------------
        */

        $property->images = DB::table('property_images')
            ->where('property_id', $property->id)
            ->select([
                'id',
                'image_url',
                'is_primary',
                'display_order',
            ])
            ->orderBy('display_order')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Location
        |--------------------------------------------------------------------------
        */

        $property->location = DB::table('property_locations as pl')
            ->leftJoin(
                'neighborhoods as n',
                'n.id',
                '=',
                'pl.neighborhood_id'
            )
            ->leftJoin('neighborhood_translations as nt_en', function ($join) {
                $join->on('nt_en.neighborhood_id', '=', 'pl.neighborhood_id')
                    ->where('nt_en.language_code', '=', 'en');
            })
            ->leftJoin('neighborhood_translations as nt_ar', function ($join) {
                $join->on('nt_ar.neighborhood_id', '=', 'pl.neighborhood_id')
                    ->where('nt_ar.language_code', '=', 'ar');
            })
            ->where('pl.property_id', $property->id)
            ->select([
                'pl.id',
                'pl.address_line_1',
                'pl.address_line_2',
                'pl.building_name',
                'pl.latitude',
                'pl.longitude',
                'pl.neighborhood_id',
                'n.name as neighborhood_name',
                DB::raw('COALESCE(nt_en.name, n.name) as neighborhood_en'),
                'nt_ar.name as neighborhood_ar',
                'pl.street_id',
            ])
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Features
        |--------------------------------------------------------------------------
        */

        $property->features = DB::table(
            'property_feature_values as pfv'
        )
            ->join(
                'property_features as pf',
                'pf.id',
                '=',
                'pfv.feature_id'
            )
            ->where(
                'pfv.property_id',
                $property->id
            )
            ->select([
                'pf.id',
                'pf.name',
                'pf.category',
                'pfv.feature_value',
            ])
            ->orderBy('pf.id')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Owner
        |--------------------------------------------------------------------------
        */

        $property->owner = $property->owner_id
            ? [
                'id' => $property->owner_id,
                'first_name' => $property->owner_first_name,
                'last_name' => $property->owner_last_name,
                'email' => $property->owner_email,
                'phone' => $property->owner_phone,
            ]
            : null;

        /*
        |--------------------------------------------------------------------------
        | Agency
        |--------------------------------------------------------------------------
        */

        $property->agency = $property->agency_id
            ? [
                'id' => $property->agency_id,
                'name' => $property->agency_name,
            ]
            : null;

        unset(
            $property->owner_first_name,
            $property->owner_last_name,
            $property->owner_email,
            $property->owner_phone,
            $property->agency_name
        );
        /*
|--------------------------------------------------------------------------
| Favorite Status
|--------------------------------------------------------------------------
*/

$property->is_favorite = DB::table('favorites')
    ->where('user_id', (int) $user['id'])
    ->where('property_id', $id)
    ->exists();
/*
|--------------------------------------------------------------------------
| Primary Action
|--------------------------------------------------------------------------
*/

$property->primary_action = match ($property->action_type) {
    'buy' => [
        'type' => 'buy',
        'label' => 'Buy Now',
    ],

    'rent' => [
        'type' => 'rent',
        'label' => 'Rent Now',
    ],

    'booking' => [
        'type' => 'booking',
        'label' => 'Book Now',
    ],

    default => null,
};
        /*
        |--------------------------------------------------------------------------
        | Rating & Reviews
        |--------------------------------------------------------------------------
        */

        $reviews = DB::table('reviews')
            ->where('property_id', $id)
            ->orderByDesc('created_at')
            ->get();

        $property->rating = $reviews->isNotEmpty()
            ? round((float) $reviews->avg('rating'), 1)
            : 0.0;

        $property->reviews = $reviews
            ->pluck('review')
            ->filter(function ($review) {
                return $review !== null && trim($review) !== '';
            })
            ->values()
            ->all();
            /*
|--------------------------------------------------------------------------
| AI Vibe Report
|--------------------------------------------------------------------------
*/

$property->vibe_report = null;

if (
    $property->location &&
    $property->location->latitude !== null &&
    $property->location->longitude !== null
) {
    try {
        $vibeAiService = app(VibeAiService::class);

        $property->vibe_report = $vibeAiService->getVibeReport(
            $property->id,
            (float) $property->location->latitude,
            (float) $property->location->longitude
        );
    } catch (Throwable $e) {
        Log::warning('Vibe Report could not be generated', [
            'property_id' => $property->id,
            'error' => $e->getMessage(),
        ]);

        $property->vibe_report = null;
    }
}

        return response()->json([
            'success' => true,
            'data' => $property,
        ]);

    } catch (Throwable $e) {

        Log::error('Failed to load property', [
            'property_id' => $id,
            'error' => $e->getMessage(),
        ]);

        report($e);

        return response()->json([
            'success' => false,
            'message' => 'Failed to load property',
        ], 500);
    }
}
}