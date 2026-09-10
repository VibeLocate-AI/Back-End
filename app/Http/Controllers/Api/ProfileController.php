<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->attributes->get('auth_user');

        $profile = DB::table('users as u')
            ->leftJoin('user_profiles as up', 'up.user_id', '=', 'u.id')
            ->where('u.id', $user['id'])
            ->select(
                'u.id',
                'u.first_name',
                'u.last_name',
                'u.email',
                'u.phone',
                'u.status',
                'u.email_verified_at',
                'u.created_at',
                'up.avatar_url',
                'up.bio',
                'up.preferred_language',
                'up.currency',
                'up.nationality',
                'up.date_of_birth',
                'up.gender'
            )
            ->first();

        return response()->json([
            'success' => true,
            'profile' => $profile
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->attributes->get('auth_user');

        $currentUser = DB::table('users')
            ->where('id', $user['id'])
            ->select('first_name', 'last_name', 'phone')
            ->first();

        $currentProfile = DB::table('user_profiles')
            ->where('user_id', $user['id'])
            ->first();

        $firstName = trim((string) $request->input(
            'first_name',
            $currentUser->first_name
        ));

        $lastName = trim((string) $request->input(
            'last_name',
            $currentUser->last_name
        ));

        $phone = $request->exists('phone')
            ? trim((string) $request->input('phone'))
            : $currentUser->phone;

        $bio = $request->exists('bio')
            ? $request->input('bio')
            : ($currentProfile->bio ?? null);

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

        $avatarUrl = $request->exists('avatar_url')
            ? $request->input('avatar_url')
            : ($currentProfile->avatar_url ?? null);

        if ($firstName === '' || $lastName === '') {
            return response()->json([
                'success' => false,
                'message' => 'First name and last name are required'
            ], 422);
        }

        if ($phone !== '') {
            $exists = DB::table('users')
                ->where('phone', $phone)
                ->where('id', '<>', $user['id'])
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Phone already registered'
                ], 409);
            }
        }

        $allowedGenders = [
            'male',
            'female',
            'other',
            'prefer_not_to_say'
        ];

        if (
            $gender !== null &&
            !in_array($gender, $allowedGenders, true)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid gender value'
            ], 422);
        }

        DB::transaction(function () use (
            $user,
            $firstName,
            $lastName,
            $phone,
            $bio,
            $preferredLanguage,
            $currency,
            $nationality,
            $dateOfBirth,
            $gender,
            $avatarUrl
        ) {
            DB::table('users')
                ->where('id', $user['id'])
                ->update([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'phone' => $phone !== '' ? $phone : null,
                    'updated_at' => now(),
                ]);

            DB::table('user_profiles')->updateOrInsert(
                ['user_id' => $user['id']],
                [
                    'avatar_url' => $avatarUrl,
                    'bio' => $bio,
                    'preferred_language' => $preferredLanguage,
                    'currency' => $currency,
                    'nationality' => $nationality !== ''
                        ? $nationality
                        : null,
                    'date_of_birth' => $dateOfBirth,
                    'gender' => $gender,
                    'updated_at' => now(),
                ]
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully'
        ]);
    }
}