<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Cloudinary\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProfileController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Get Profile
    |--------------------------------------------------------------------------
    */

    public function show(Request $request)
    {
        $user = $request->attributes->get('auth_user');

        $profile = DB::table('users as u')
            ->leftJoin('user_profiles as up', 'up.user_id', '=', 'u.id')
            ->where('u.id', $user['id'])
            ->select(
                'u.id',
                DB::raw(
                    "TRIM(CONCAT(u.first_name, ' ', u.last_name)) as full_name"
                ),
                'u.email',
                'u.phone',
                'u.status',
                'u.email_verified_at',
                'u.created_at',
                'up.avatar_url',
                'up.bio',
                'up.city',
                'up.country',
                'up.preferred_language',
                'up.currency',
                'up.nationality',
                'up.date_of_birth',
                'up.gender'
            )
            ->first();

        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'profile' => $profile
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Update Profile Data
    |--------------------------------------------------------------------------
    */

    public function update(Request $request)
    {
        $user = $request->attributes->get('auth_user');

        $currentUser = DB::table('users')
            ->where('id', $user['id'])
            ->select(
                'first_name',
                'last_name',
                'email',
                'phone'
            )
            ->first();

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $currentProfile = DB::table('user_profiles')
            ->where('user_id', $user['id'])
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Full Name
        |--------------------------------------------------------------------------
        */

        $currentFullName = trim(
            $currentUser->first_name . ' ' . $currentUser->last_name
        );

        $fullName = $request->exists('full_name')
            ? trim((string) $request->input('full_name'))
            : $currentFullName;

        if ($fullName === '') {
            return response()->json([
                'success' => false,
                'message' => 'Full name is required'
            ], 422);
        }

        $nameParts = preg_split('/\s+/', $fullName, 2);

        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? '';

        /*
        |--------------------------------------------------------------------------
        | User Data
        |--------------------------------------------------------------------------
        */

        $email = $request->exists('email')
            ? strtolower(trim((string) $request->input('email')))
            : $currentUser->email;

        $phone = $request->exists('phone')
            ? trim((string) $request->input('phone'))
            : $currentUser->phone;

        /*
        |--------------------------------------------------------------------------
        | Profile Data
        |--------------------------------------------------------------------------
        */

        $bio = $request->exists('bio')
            ? $request->input('bio')
            : ($currentProfile->bio ?? null);

        $city = $request->exists('city')
            ? trim((string) $request->input('city'))
            : ($currentProfile->city ?? null);

        $country = $request->exists('country')
            ? trim((string) $request->input('country'))
            : ($currentProfile->country ?? null);

        $preferredLanguage = $request->exists('preferred_language')
            ? trim((string) $request->input('preferred_language'))
            : ($currentProfile->preferred_language ?? 'en');

        $currency = $request->exists('currency')
            ? strtoupper(trim((string) $request->input('currency')))
            : ($currentProfile->currency ?? 'AED');

        $nationality = $request->exists('nationality')
            ? trim((string) $request->input('nationality'))
            : ($currentProfile->nationality ?? null);

        $dateOfBirth = $request->exists('date_of_birth')
            ? $request->input('date_of_birth')
            : ($currentProfile->date_of_birth ?? null);

        $gender = $request->exists('gender')
            ? $request->input('gender')
            : ($currentProfile->gender ?? null);

        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        if (
            $email === '' ||
            !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'A valid email address is required'
            ], 422);
        }

        $emailExists = DB::table('users')
            ->where('email', $email)
            ->where('id', '<>', $user['id'])
            ->exists();

        if ($emailExists) {
            return response()->json([
                'success' => false,
                'message' => 'Email already registered'
            ], 409);
        }

        if ($phone !== null && $phone !== '') {
            $phoneExists = DB::table('users')
                ->where('phone', $phone)
                ->where('id', '<>', $user['id'])
                ->exists();

            if ($phoneExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Phone already registered'
                ], 409);
            }
        }

        if ($bio !== null && mb_strlen((string) $bio) > 300) {
            return response()->json([
                'success' => false,
                'message' => 'Bio may not be greater than 300 characters'
            ], 422);
        }

        $allowedGenders = [
            'male',
            'female',
            'other',
            'prefer_not_to_say'
        ];

        if (
            $gender !== null &&
            $gender !== '' &&
            !in_array($gender, $allowedGenders, true)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid gender value'
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Update Database
        |--------------------------------------------------------------------------
        */

        DB::transaction(function () use (
            $user,
            $firstName,
            $lastName,
            $email,
            $phone,
            $bio,
            $city,
            $country,
            $preferredLanguage,
            $currency,
            $nationality,
            $dateOfBirth,
            $gender
        ) {
            DB::table('users')
                ->where('id', $user['id'])
                ->update([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'phone' => (
                        $phone !== null &&
                        $phone !== ''
                    ) ? $phone : null,
                    'updated_at' => now(),
                ]);

            $profileData = [
                'bio' => $bio,

                'city' => (
                    $city !== null &&
                    $city !== ''
                ) ? $city : null,

                'country' => (
                    $country !== null &&
                    $country !== ''
                ) ? $country : null,

                'preferred_language' => $preferredLanguage,
                'currency' => $currency,

                'nationality' => (
                    $nationality !== null &&
                    $nationality !== ''
                ) ? $nationality : null,

                'date_of_birth' => $dateOfBirth,

                'gender' => (
                    $gender !== ''
                ) ? $gender : null,

                'updated_at' => now(),
            ];

            $profileExists = DB::table('user_profiles')
                ->where('user_id', $user['id'])
                ->exists();

            if ($profileExists) {
                DB::table('user_profiles')
                    ->where('user_id', $user['id'])
                    ->update($profileData);
            } else {
                $profileData['user_id'] = $user['id'];
                $profileData['created_at'] = now();

                DB::table('user_profiles')
                    ->insert($profileData);
            }
        });

        $updatedProfile = $this->getProfile($user['id']);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'profile' => $updatedProfile
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Upload Profile Avatar
    |--------------------------------------------------------------------------
    */

    public function updateAvatar(Request $request)
    {
        $user = $request->attributes->get('auth_user');

        if (!$request->hasFile('avatar')) {
            return response()->json([
                'success' => false,
                'message' => 'Profile photo is required'
            ], 422);
        }

        $validator = validator($request->all(), [
            'avatar' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png',
                'max:5120',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid profile photo',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $cloudinaryUrl = env('CLOUDINARY_URL');

            if (!$cloudinaryUrl) {
                Log::error('Cloudinary profile photo upload failed', [
                    'user_id' => $user['id'],
                    'error' => 'CLOUDINARY_URL is not configured',
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Cloudinary is not configured'
                ], 500);
            }

            $cloudinary = new Cloudinary($cloudinaryUrl);

            $uploadResult = $cloudinary
                ->uploadApi()
                ->upload(
                    $request->file('avatar')->getRealPath(),
                    [
                        'folder' => 'vibelocate/profile-images',
                        'resource_type' => 'image',
                    ]
                );

            $avatarUrl = $uploadResult['secure_url'] ?? null;

            if (!$avatarUrl) {
                Log::error('Cloudinary profile photo upload failed', [
                    'user_id' => $user['id'],
                    'error' => 'Cloudinary response did not contain secure_url',
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Profile photo upload failed'
                ], 500);
            }

            $profileExists = DB::table('user_profiles')
                ->where('user_id', $user['id'])
                ->exists();

            if ($profileExists) {
                DB::table('user_profiles')
                    ->where('user_id', $user['id'])
                    ->update([
                        'avatar_url' => $avatarUrl,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('user_profiles')
                    ->insert([
                        'user_id' => $user['id'],
                        'avatar_url' => $avatarUrl,
                        'preferred_language' => 'en',
                        'currency' => 'AED',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Profile photo updated successfully',
                'avatar_url' => $avatarUrl,
                'profile' => $this->getProfile($user['id'])
            ]);

        } catch (Throwable $e) {

            Log::error('Cloudinary profile photo upload failed', [
                'user_id' => $user['id'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Profile photo upload failed'
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Profile Helper
    |--------------------------------------------------------------------------
    */

    private function getProfile(int $userId)
    {
        return DB::table('users as u')
            ->leftJoin('user_profiles as up', 'up.user_id', '=', 'u.id')
            ->where('u.id', $userId)
            ->select(
                'u.id',
                DB::raw(
                    "TRIM(CONCAT(u.first_name, ' ', u.last_name)) as full_name"
                ),
                'u.email',
                'u.phone',
                'u.status',
                'u.email_verified_at',
                'u.created_at',
                'up.avatar_url',
                'up.bio',
                'up.city',
                'up.country',
                'up.preferred_language',
                'up.currency',
                'up.nationality',
                'up.date_of_birth',
                'up.gender'
            )
            ->first();
    }
}