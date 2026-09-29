<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AgentOnboardingController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Step 2 - License & Agency
    |--------------------------------------------------------------------------
    */

    public function license(Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('auth_user');

            if (!$user || empty($user['id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $userId = (int) $user['id'];

            $isAgent = DB::table('user_roles as ur')
                ->join('roles as r', 'r.id', '=', 'ur.role_id')
                ->where('ur.user_id', $userId)
                ->where('r.slug', 'agent')
                ->exists();

            if (!$isAgent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Agent access required',
                ], 403);
            }

            $agency = DB::table('agency_agents as aa')
                ->join('agencies as a', 'a.id', '=', 'aa.agency_id')
                ->where('aa.user_id', $userId)
                ->select([
                    'a.id',
                    'a.name',
                    'a.license_number',
                    'a.license_document_path',
                    'a.company_document_path',
                ])
                ->first();

            if (!$agency) {
                return response()->json([
                    'success' => false,
                    'message' => 'Agency not found for this agent',
                ], 404);
            }

            $validator = validator(
                $request->all(),
                [
                    'agency_name' =>
                        'required|string|max:150',

                    'license_number' =>
                        'required|string|max:100',

                    'license_authority' =>
                        'required|string|max:150',

                    'license_expiry_date' =>
                        'required|date|after:today',

                    'address' =>
                        'required|string|max:1000',

                    'license_document' =>
                        'required|file|mimes:pdf,jpg,jpeg,png|max:5120',

                    'company_document' =>
                        'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
                ]
            );

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $licenseNumber = trim(
                (string) $request->license_number
            );

            $duplicateLicense = DB::table('agencies')
                ->where(
                    'license_number',
                    $licenseNumber
                )
                ->where(
                    'id',
                    '<>',
                    $agency->id
                )
                ->exists();

            if ($duplicateLicense) {
                return response()->json([
                    'success' => false,
                    'message' => 'License number already exists',
                ], 409);
            }

            $licensePath = $request
                ->file('license_document')
                ->store(
                    'agents/licenses',
                    'public'
                );

            $companyPath = null;

            if ($request->hasFile('company_document')) {
                $companyPath = $request
                    ->file('company_document')
                    ->store(
                        'agents/company-documents',
                        'public'
                    );
            }

            if (
                !empty($agency->license_document_path) &&
                Storage::disk('public')->exists(
                    $agency->license_document_path
                )
            ) {
                Storage::disk('public')->delete(
                    $agency->license_document_path
                );
            }

            if (
                $companyPath !== null &&
                !empty($agency->company_document_path) &&
                Storage::disk('public')->exists(
                    $agency->company_document_path
                )
            ) {
                Storage::disk('public')->delete(
                    $agency->company_document_path
                );
            }

            $updateData = [
                'name' =>
                    trim((string) $request->agency_name),

                'license_number' =>
                    $licenseNumber,

                'license_authority' =>
                    trim((string) $request->license_authority),

                'license_expiry_date' =>
                    $request->license_expiry_date,

                'address' =>
                    trim((string) $request->address),

                'license_document_path' =>
                    $licensePath,

                'updated_at' =>
                    now(),
            ];

            if ($companyPath !== null) {
                $updateData['company_document_path'] =
                    $companyPath;
            }

            DB::table('agencies')
                ->where('id', $agency->id)
                ->update($updateData);

            $updatedAgency = DB::table('agencies')
                ->where('id', $agency->id)
                ->select([
                    'id',
                    'name',
                    'license_number',
                    'license_authority',
                    'license_expiry_date',
                    'address',
                    'license_document_path',
                    'company_document_path',
                    'status',
                ])
                ->first();

            return response()->json([
                'success' => true,

                'message' =>
                    'License and agency information saved successfully',

                'agency' =>
                    $updatedAgency,

                'onboarding' => [
                    'completed_step' =>
                        2,

                    'next_step' =>
                        'expertise',
                ],
            ]);

        } catch (Throwable $e) {

            Log::error(
                'Agent license onboarding failed',
                [
                    'user_id' =>
                        $user['id'] ?? null,

                    'error' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),
                ]
            );

            report($e);

            return response()->json([
                'success' => false,
                'message' =>
                    'Failed to save license information',
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Step 3 - Expertise
    |--------------------------------------------------------------------------
    */

    public function expertise(Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('auth_user');

            if (!$user || empty($user['id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $userId = (int) $user['id'];

            $isAgent = DB::table('user_roles as ur')
                ->join('roles as r', 'r.id', '=', 'ur.role_id')
                ->where('ur.user_id', $userId)
                ->where('r.slug', 'agent')
                ->exists();

            if (!$isAgent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Agent access required',
                ], 403);
            }

            $validator = validator(
                $request->all(),
                [
                    'community_ids' =>
                        'required|array|min:1',

                    'community_ids.*' =>
                        'required|integer|distinct|exists:communities,id',

                    'property_type_ids' =>
                        'required|array|min:1',

                    'property_type_ids.*' =>
                        'required|integer|distinct|exists:property_types,id',
                ]
            );

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $communityIds = array_values(
                array_unique(
                    array_map(
                        'intval',
                        $request->input(
                            'community_ids',
                            []
                        )
                    )
                )
            );

            $propertyTypeIds = array_values(
                array_unique(
                    array_map(
                        'intval',
                        $request->input(
                            'property_type_ids',
                            []
                        )
                    )
                )
            );

            DB::beginTransaction();

            DB::table('agent_communities')
                ->where(
                    'user_id',
                    $userId
                )
                ->delete();

            $communityRows = [];

            foreach ($communityIds as $communityId) {
                $communityRows[] = [
                    'user_id' =>
                        $userId,

                    'community_id' =>
                        $communityId,
                ];
            }

            if (!empty($communityRows)) {
                DB::table('agent_communities')
                    ->insert(
                        $communityRows
                    );
            }

            DB::table('agent_property_types')
                ->where(
                    'user_id',
                    $userId
                )
                ->delete();

            $propertyTypeRows = [];

            foreach ($propertyTypeIds as $propertyTypeId) {
                $propertyTypeRows[] = [
                    'user_id' =>
                        $userId,

                    'property_type_id' =>
                        $propertyTypeId,
                ];
            }

            if (!empty($propertyTypeRows)) {
                DB::table('agent_property_types')
                    ->insert(
                        $propertyTypeRows
                    );
            }

            DB::commit();

            $communities = DB::table('communities')
                ->whereIn(
                    'id',
                    $communityIds
                )
                ->select([
                    'id',
                    'name',
                ])
                ->orderBy('name')
                ->get();

            $propertyTypes = DB::table('property_types')
                ->whereIn(
                    'id',
                    $propertyTypeIds
                )
                ->select([
                    'id',
                    'name',
                    'slug',
                ])
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,

                'message' =>
                    'Agent expertise saved successfully',

                'expertise' => [
                    'communities' =>
                        $communities,

                    'property_types' =>
                        $propertyTypes,
                ],

                'onboarding' => [
                    'completed_step' =>
                        3,

                    'next_step' =>
                        'profile',
                ],
            ]);

        } catch (Throwable $e) {

            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::error(
                'Agent expertise onboarding failed',
                [
                    'user_id' =>
                        $user['id'] ?? null,

                    'error' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),
                ]
            );

            report($e);

            return response()->json([
                'success' => false,
                'message' =>
                    'Failed to save agent expertise',
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Step 4 - Profile
    |--------------------------------------------------------------------------
    */

    public function profile(Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('auth_user');

            if (!$user || empty($user['id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $userId = (int) $user['id'];

            $isAgent = DB::table('user_roles as ur')
                ->join('roles as r', 'r.id', '=', 'ur.role_id')
                ->where('ur.user_id', $userId)
                ->where('r.slug', 'agent')
                ->exists();

            if (!$isAgent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Agent access required',
                ], 403);
            }

            $validator = validator(
                $request->all(),
                [
                    'bio' =>
                        'required|string|max:2000',

                    'job_title' =>
                        'required|string|max:150',

                    'years_experience' =>
                        'required|integer|min:0|max:80',

                    'profile_image' =>
                        'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
                ]
            );

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $profile = DB::table('user_profiles')
                ->where(
                    'user_id',
                    $userId
                )
                ->first();

            $avatarPath =
                $profile->avatar_url ?? null;

            if ($request->hasFile('profile_image')) {

                $newAvatarPath = $request
                    ->file('profile_image')
                    ->store(
                        'agents/profile-images',
                        'public'
                    );

                if (
                    !empty($avatarPath) &&
                    Storage::disk('public')->exists(
                        $avatarPath
                    )
                ) {
                    Storage::disk('public')->delete(
                        $avatarPath
                    );
                }

                $avatarPath =
                    $newAvatarPath;
            }

            DB::table('user_profiles')
                ->updateOrInsert(
                    [
                        'user_id' =>
                            $userId,
                    ],
                    [
                        'avatar_url' =>
                            $avatarPath,

                        'bio' =>
                            trim(
                                (string) $request->bio
                            ),

                        'job_title' =>
                            trim(
                                (string) $request->job_title
                            ),

                        'years_experience' =>
                            (int) $request->years_experience,

                        'updated_at' =>
                            now(),
                    ]
                );

            $updatedProfile = DB::table('users as u')
                ->leftJoin(
                    'user_profiles as up',
                    'up.user_id',
                    '=',
                    'u.id'
                )
                ->where(
                    'u.id',
                    $userId
                )
                ->select([
                    'u.id',
                    'u.first_name',
                    'u.last_name',
                    'u.email',
                    'up.avatar_url',
                    'up.bio',
                    'up.job_title',
                    'up.years_experience',
                ])
                ->first();

            return response()->json([
                'success' => true,

                'message' =>
                    'Agent profile completed successfully',

                'profile' =>
                    $updatedProfile,

                'onboarding' => [
                    'completed_step' =>
                        4,

                    'completed' =>
                        true,

                    'next_step' =>
                        'dashboard',
                ],
            ]);

        } catch (Throwable $e) {

            Log::error(
                'Agent profile onboarding failed',
                [
                    'user_id' =>
                        $user['id'] ?? null,

                    'error' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),
                ]
            );

            report($e);

            return response()->json([
                'success' => false,
                'message' =>
                    'Failed to complete agent profile',
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Agent Onboarding Status
    |--------------------------------------------------------------------------
    */

    public function status(Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('auth_user');

            if (!$user || empty($user['id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $userId = (int) $user['id'];

            $isAgent = DB::table('user_roles as ur')
                ->join(
                    'roles as r',
                    'r.id',
                    '=',
                    'ur.role_id'
                )
                ->where(
                    'ur.user_id',
                    $userId
                )
                ->where(
                    'r.slug',
                    'agent'
                )
                ->exists();

            if (!$isAgent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Agent access required',
                ], 403);
            }

            /*
            |--------------------------------------------------------------------------
            | Step 1
            |--------------------------------------------------------------------------
            */

            $accountCompleted = DB::table('users')
                ->where(
                    'id',
                    $userId
                )
                ->exists();

            /*
            |--------------------------------------------------------------------------
            | Step 2
            |--------------------------------------------------------------------------
            */

            $agency = DB::table('agency_agents as aa')
                ->join(
                    'agencies as a',
                    'a.id',
                    '=',
                    'aa.agency_id'
                )
                ->where(
                    'aa.user_id',
                    $userId
                )
                ->select([
                    'a.id',
                    'a.name',
                    'a.license_number',
                    'a.license_authority',
                    'a.license_expiry_date',
                    'a.license_document_path',
                    'a.company_document_path',
                    'a.address',
                    'a.status',
                ])
                ->first();

            $licenseCompleted =
                $agency &&
                !empty($agency->name) &&
                !empty($agency->license_number) &&
                !empty($agency->license_authority) &&
                !empty($agency->license_expiry_date) &&
                !empty($agency->license_document_path);

            /*
            |--------------------------------------------------------------------------
            | Step 3
            |--------------------------------------------------------------------------
            */

            $communitiesCount = DB::table('agent_communities')
                ->where(
                    'user_id',
                    $userId
                )
                ->count();

            $propertyTypesCount = DB::table('agent_property_types')
                ->where(
                    'user_id',
                    $userId
                )
                ->count();

            $expertiseCompleted =
                $communitiesCount > 0 &&
                $propertyTypesCount > 0;

            /*
            |--------------------------------------------------------------------------
            | Step 4
            |--------------------------------------------------------------------------
            */

            $profile = DB::table('user_profiles')
                ->where(
                    'user_id',
                    $userId
                )
                ->select([
                    'avatar_url',
                    'bio',
                    'job_title',
                    'years_experience',
                ])
                ->first();

            $profileCompleted =
                $profile &&
                !empty($profile->bio) &&
                !empty($profile->job_title) &&
                $profile->years_experience !== null;

            /*
            |--------------------------------------------------------------------------
            | Determine Progress
            |--------------------------------------------------------------------------
            */

            $completedStep = 0;
            $nextStep = 'account';
            $completed = false;

            if ($accountCompleted) {
                $completedStep = 1;
                $nextStep = 'license';
            }

            if ($licenseCompleted) {
                $completedStep = 2;
                $nextStep = 'expertise';
            }

            if ($expertiseCompleted) {
                $completedStep = 3;
                $nextStep = 'profile';
            }

            if ($profileCompleted) {
                $completedStep = 4;
                $nextStep = 'dashboard';
                $completed = true;
            }

            /*
            |--------------------------------------------------------------------------
            | Response
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'success' => true,

                'onboarding' => [
                    'completed_step' =>
                        $completedStep,

                    'completed' =>
                        $completed,

                    'next_step' =>
                        $nextStep,

                    'steps' => [
                        'account' => [
                            'step' =>
                                1,

                            'completed' =>
                                $accountCompleted,
                        ],

                        'license' => [
                            'step' =>
                                2,

                            'completed' =>
                                $licenseCompleted,
                        ],

                        'expertise' => [
                            'step' =>
                                3,

                            'completed' =>
                                $expertiseCompleted,

                            'communities_count' =>
                                $communitiesCount,

                            'property_types_count' =>
                                $propertyTypesCount,
                        ],

                        'profile' => [
                            'step' =>
                                4,

                            'completed' =>
                                $profileCompleted,
                        ],
                    ],
                ],

                'agency' => $agency ? [
                    'id' =>
                        $agency->id,

                    'name' =>
                        $agency->name,

                    'status' =>
                        $agency->status,
                ] : null,
            ]);

        } catch (Throwable $e) {

            Log::error(
                'Agent onboarding status failed',
                [
                    'user_id' =>
                        $user['id'] ?? null,

                    'error' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),
                ]
            );

            report($e);

            return response()->json([
                'success' => false,
                'message' =>
                    'Failed to load onboarding status',
            ], 500);
        }
    }
}