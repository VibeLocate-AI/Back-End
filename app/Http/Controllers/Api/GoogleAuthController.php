<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\JwtService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Throwable;

class GoogleAuthController extends Controller
{
    public function __construct(private JwtService $jwt)
    {
    }

    public function __invoke(Request $request)
    {
        $idToken = trim((string) $request->input('id_token', ''));
        $deviceUuid = trim((string) $request->input('device_uuid', ''));
        $deviceType = trim((string) $request->input('device_type', 'web'));

        if ($idToken === '') {
            return $this->error('Google ID token is required', 422);
        }

        if (!in_array($deviceType, ['ios', 'android', 'web', 'desktop'], true)) {
            return $this->error('Invalid device type', 422);
        }

        $googleClientId = (string) env('GOOGLE_CLIENT_ID');

        if ($googleClientId === '') {
            return $this->error('Google authentication is not configured', 500);
        }

        try {

            /*
            |--------------------------------------------------------------------------
            | Verify Google ID Token
            |--------------------------------------------------------------------------
            */

            $googleResponse = Http::timeout(20)
                ->get('https://oauth2.googleapis.com/tokeninfo', [
                    'id_token' => $idToken,
                ]);

            if (!$googleResponse->successful()) {
                return $this->error('Invalid Google token', 401);
            }

            $googleUser = $googleResponse->json();

            if (
                !isset($googleUser['aud']) ||
                !hash_equals($googleClientId, (string) $googleUser['aud'])
            ) {
                return $this->error('Invalid Google token audience', 401);
            }

            if (
                !isset($googleUser['iss']) ||
                !in_array(
                    $googleUser['iss'],
                    [
                        'accounts.google.com',
                        'https://accounts.google.com',
                    ],
                    true
                )
            ) {
                return $this->error('Invalid Google token issuer', 401);
            }

            if (
                empty($googleUser['email']) ||
                filter_var(
                    $googleUser['email'],
                    FILTER_VALIDATE_EMAIL
                ) === false
            ) {
                return $this->error('Google account email is invalid', 401);
            }

            $emailVerified =
                ($googleUser['email_verified'] ?? false) === true ||
                ($googleUser['email_verified'] ?? '') === 'true' ||
                ($googleUser['email_verified'] ?? '') === '1';

            if (!$emailVerified) {
                return $this->error('Google email is not verified', 401);
            }

            $email = strtolower(
                trim((string) $googleUser['email'])
            );

            $fullName = trim(
                (string) ($googleUser['name'] ?? '')
            );

            $firstName = trim(
                (string) ($googleUser['given_name'] ?? '')
            );

            $lastName = trim(
                (string) ($googleUser['family_name'] ?? '')
            );

            $avatar = trim(
                (string) ($googleUser['picture'] ?? '')
            );

            if ($firstName === '') {
                $nameParts = preg_split(
                    '/\s+/',
                    $fullName,
                    2
                );

                $firstName = $nameParts[0] ?? 'Google';
                $lastName = $nameParts[1] ?? 'User';
            }

            if ($lastName === '') {
                $lastName = 'User';
            }

            /*
            |--------------------------------------------------------------------------
            | Find Existing User
            |--------------------------------------------------------------------------
            */

            $user = DB::table('users')
                ->where('email', $email)
                ->whereNull('deleted_at')
                ->first();

            DB::beginTransaction();

            /*
            |--------------------------------------------------------------------------
            | Create New User
            |--------------------------------------------------------------------------
            */

            if (!$user) {

                $userId = DB::table('users')->insertGetId([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,

                    /*
                     * Google users do not need a local password,
                     * but your DB requires password_hash NOT NULL.
                     */
                    'password_hash' => Hash::make(
                        bin2hex(random_bytes(32))
                    ),

                    'status' => 'active',
                    'email_verified_at' => now(),
                ]);

                /*
                |--------------------------------------------------------------------------
                | Default Tenant Role
                |--------------------------------------------------------------------------
                */

                $tenantRole = DB::table('roles')
                    ->where('slug', 'tenant')
                    ->first();

                if (!$tenantRole) {
                    throw new \RuntimeException(
                        'Tenant role not found'
                    );
                }

                DB::table('user_roles')->insert([
                    'user_id' => $userId,
                    'role_id' => $tenantRole->id,
                ]);

                /*
                |--------------------------------------------------------------------------
                | User Profile
                |--------------------------------------------------------------------------
                */

                DB::table('user_profiles')->insert([
                    'user_id' => $userId,
                    'avatar_url' => $avatar !== ''
                        ? $avatar
                        : null,
                    'preferred_language' => 'en',
                    'currency' => 'AED',
                ]);

                /*
                |--------------------------------------------------------------------------
                | Referral Code
                |--------------------------------------------------------------------------
                */

                $referralCode = strtoupper(
                    'VIBE-' .
                    $userId .
                    '-' .
                    bin2hex(random_bytes(3))
                );

                DB::table('referral_codes')->insert([
                    'user_id' => $userId,
                    'code' => $referralCode,
                ]);

                $user = DB::table('users')
                    ->where('id', $userId)
                    ->first();

            } else {

                /*
                |--------------------------------------------------------------------------
                | Existing User
                |--------------------------------------------------------------------------
                */

                if ($user->status === 'suspended') {
                    DB::rollBack();

                    return $this->error(
                        'Account suspended',
                        403
                    );
                }

                /*
                 * Google has verified this email, so mark the existing
                 * VibeLocate account as verified.
                 */
                DB::table('users')
                    ->where('id', $user->id)
                    ->update([
                        'status' => 'active',
                        'email_verified_at' =>
                            $user->email_verified_at ?: now(),
                    ]);

                /*
                 * Update Google profile picture when available.
                 */
                if ($avatar !== '') {
                    DB::table('user_profiles')
                        ->where('user_id', $user->id)
                        ->update([
                            'avatar_url' => $avatar,
                        ]);
                }

                $user = DB::table('users')
                    ->where('id', $user->id)
                    ->first();
            }

            /*
            |--------------------------------------------------------------------------
            | Device
            |--------------------------------------------------------------------------
            */

            $deviceId = null;

            if ($deviceUuid !== '') {

                $device = DB::table('devices')
                    ->where('user_id', $user->id)
                    ->where('device_uuid', $deviceUuid)
                    ->first();

                if ($device) {

                    $deviceId = (int) $device->id;

                    DB::table('devices')
                        ->where('id', $deviceId)
                        ->update([
                            'device_type' => $deviceType,
                            'updated_at' => now(),
                        ]);

                } else {

                    $deviceId = DB::table('devices')
                        ->insertGetId([
                            'user_id' => $user->id,
                            'device_uuid' => $deviceUuid,
                            'device_type' => $deviceType,
                        ]);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Create VibeLocate Tokens
            |--------------------------------------------------------------------------
            */

            $accessToken = $this->jwt->accessToken(
                (int) $user->id,
                $user->email
            );

            $refreshToken = $this->jwt->refreshToken();

            DB::table('refresh_tokens')->insert([
                'user_id' => $user->id,
                'device_id' => $deviceId,
                'token_hash' => hash(
                    'sha256',
                    $refreshToken
                ),
                'expires_at' => now()->addDays(7),
            ]);

            /*
            |--------------------------------------------------------------------------
            | Login History
            |--------------------------------------------------------------------------
            */

            DB::table('login_history')->insert([
                'user_id' => $user->id,
                'device_id' => $deviceId,
                'ip_address' =>
                    $request->ip() ?: '127.0.0.1',
                'login_status' => 'success',
            ]);

            DB::commit();

            /*
            |--------------------------------------------------------------------------
            | Response
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'success' => true,

                'message' =>
                    'Google login successful',

                'access_token' =>
                    $accessToken,

                'refresh_token' =>
                    $refreshToken,

                'expires_in' =>
                    900,

                'user' => [
                    'id' =>
                        (int) $user->id,

                    'first_name' =>
                        $user->first_name,

                    'last_name' =>
                        $user->last_name,

                    'email' =>
                        $user->email,

                    'avatar_url' =>
                        $avatar !== ''
                            ? $avatar
                            : null,
                ],
            ]);

        } catch (Throwable $e) {

            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Google login failed',

                'details' =>
                    app()->environment('local')
                        ? $e->getMessage()
                        : null,

            ], 500);
        }
    }

    private function error(
        string $message,
        int $status
    ) {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}