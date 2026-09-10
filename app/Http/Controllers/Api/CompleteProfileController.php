<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompleteProfileController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->attributes->get('auth_user');

        $phone = trim((string) $request->input('phone', ''));
        $language = trim((string) $request->input('preferred_language', 'en'));
        $currency = strtoupper(trim((string) $request->input('currency', 'AED')));
        $nationality = trim((string) $request->input('nationality', ''));
        $dob = $request->input('date_of_birth');
        $gender = $request->input('gender');
        $bio = $request->input('bio');
        $avatarUrl = $request->input('avatar_url');

        $allowed = ['male', 'female', 'other', 'prefer_not_to_say'];

        if ($gender !== null && !in_array($gender, $allowed, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid gender value'
            ], 422);
        }

        DB::transaction(function () use (
            $user,
            $phone,
            $language,
            $currency,
            $nationality,
            $dob,
            $gender,
            $bio,
            $avatarUrl
        ) {
            DB::table('users')
                ->where('id', $user['id'])
                ->update([
                    'phone' => $phone !== '' ? $phone : null,
                    'updated_at' => now(),
                ]);

            DB::table('user_profiles')->updateOrInsert(
                ['user_id' => $user['id']],
                [
                    'avatar_url' => $avatarUrl,
                    'bio' => $bio,
                    'preferred_language' => $language,
                    'currency' => $currency,
                    'nationality' => $nationality !== '' ? $nationality : null,
                    'date_of_birth' => $dob,
                    'gender' => $gender,
                    'updated_at' => now(),
                ]
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Profile completed successfully'
        ]);
    }
}