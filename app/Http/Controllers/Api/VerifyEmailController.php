<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\JwtService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class VerifyEmailController extends Controller
{
    public function __construct(private JwtService $jwt)
    {
    }

    public function __invoke(Request $request)
    {
        /*
        |--------------------------------------------------------------------------
        | Read Verification Code
        |--------------------------------------------------------------------------
        |
        | ندعم أكثر من اسم للحقل حتى يشتغل مع الفرونت الحالي:
        | token
        | otp
        | code
        |
        */

        if ($request->isMethod('get')) {
            $verificationCode = trim((string) (
                $request->query('token')
                ?? $request->query('otp')
                ?? $request->query('code')
                ?? ''
            ));
        } else {
            $verificationCode = trim((string) (
                $request->input('token')
                ?? $request->input('otp')
                ?? $request->input('code')
                ?? ''
            ));
        }

        $email = strtolower(trim((string) $request->input('email', '')));

        $deviceUuid = trim((string) $request->input(
            'device_uuid',
            ''
        ));

        $deviceType = (string) $request->input(
            'device_type',
            'web'
        );

        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        if ($verificationCode === '') {
            return $this->error(
                'Verification code is required',
                422
            );
        }

        if (
            strlen($verificationCode) !== 6 ||
            !ctype_digit($verificationCode)
        ) {
            return $this->error(
                'Verification code must be 6 digits',
                422
            );
        }

        if (
            !in_array(
                $deviceType,
                ['ios', 'android', 'web', 'desktop'],
                true
            )
        ) {
            return $this->error(
                'Invalid device type',
                422
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Find Verification
        |--------------------------------------------------------------------------
        */

        $verificationQuery = DB::table('email_verifications')
            ->where('token', $verificationCode)
            ->where('expires_at', '>', now());

        /*
        | لو الفرونت بعت الإيميل، نربط الكود بنفس المستخدم
        | حتى ما يتم استخدام كود مستخدم آخر بالخطأ.
        */

        if ($email !== '') {
            $userForEmail = DB::table('users')
                ->where('email', $email)
                ->whereNull('deleted_at')
                ->select('id')
                ->first();

            if (!$userForEmail) {
                return $this->error(
                    'User not found',
                    404
                );
            }

            $verificationQuery->where(
                'user_id',
                $userForEmail->id
            );
        }

        $verification = $verificationQuery
            ->select('user_id')
            ->first();

        if (!$verification) {
            return $this->error(
                'Invalid or expired verification code',
                400
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Find User
        |--------------------------------------------------------------------------
        */

        $user = DB::table('users')
            ->where('id', $verification->user_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$user) {
            return $this->error(
                'User not found',
                404
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Already Verified
        |--------------------------------------------------------------------------
        */

        if (!empty($user->email_verified_at)) {
            return $this->error(
                'Email is already verified',
                409
            );
        }

        DB::beginTransaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | Activate Account
            |--------------------------------------------------------------------------
            */

            DB::table('users')
                ->where('id', $verification->user_id)
                ->update([
                    'email_verified_at' => now(),
                    'status' => 'active',
                ]);

            /*
            |--------------------------------------------------------------------------
            | Delete Used OTP
            |--------------------------------------------------------------------------
            */

            DB::table('email_verifications')
                ->where(
                    'user_id',
                    $verification->user_id
                )
                ->delete();

            /*
            |--------------------------------------------------------------------------
            | Register Device
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
            | Access Token
            |--------------------------------------------------------------------------
            */

            $accessToken = $this->jwt->accessToken(
                (int) $user->id,
                $user->email
            );

            /*
            |--------------------------------------------------------------------------
            | Refresh Token
            |--------------------------------------------------------------------------
            */

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
            | Success
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'success' => true,
                'message' =>
                    'Email verified successfully',

                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type' => 'Bearer',
                'expires_in' => 900,

                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                ],

            ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        } catch (Throwable $e) {

            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            report($e);

            return response()->json([
                'success' => false,
                'message' =>
                    'Email verification failed',
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