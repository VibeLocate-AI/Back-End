<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\JwtService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class VerifyEmailController extends Controller
{
    public function __construct(
        private JwtService $jwt
    ) {}

    public function __invoke(Request $request)
    {
        $email = strtolower(
            trim(
                (string) $request->input(
                    'email',
                    ''
                )
            )
        );

        $otp = trim(
            (string) (
                $request->input('otp')
                ?? $request->input('code')
                ?? ''
            )
        );

        $deviceUuid = trim(
            (string) $request->input(
                'device_uuid',
                ''
            )
        );

        $deviceType = trim(
            (string) $request->input(
                'device_type',
                'web'
            )
        );

        if (!filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )) {
            return $this->error(
                'Invalid email address',
                422
            );
        }

        if (
            strlen($otp) !== 6 ||
            !ctype_digit($otp)
        ) {
            return $this->error(
                'Verification code must be 6 digits',
                422
            );
        }

        if (
            !in_array(
                $deviceType,
                [
                    'ios',
                    'android',
                    'web',
                    'desktop',
                ],
                true
            )
        ) {
            return $this->error(
                'Invalid device type',
                422
            );
        }

        $user = DB::table('users')
            ->where(
                'email',
                $email
            )
            ->whereNull(
                'deleted_at'
            )
            ->first();

        if (!$user) {
            return $this->error(
                'User not found',
                404
            );
        }

        if (!empty($user->email_verified_at)) {
            return $this->error(
                'Email is already verified',
                409
            );
        }

        $otpHash = hash(
            'sha256',
            $otp
        );

        $verification = DB::table(
            'email_verifications'
        )
            ->where(
                'user_id',
                $user->id
            )
            ->where(
                'token',
                $otpHash
            )
            ->where(
                'expires_at',
                '>',
                now()
            )
            ->first();

        if (!$verification) {
            return $this->error(
                'Invalid or expired verification code',
                400
            );
        }

        DB::beginTransaction();

        try {
            DB::table('users')
                ->where(
                    'id',
                    $user->id
                )
                ->update([
                    'email_verified_at' =>
                        now(),

                    'status' =>
                        'active',

                    'updated_at' =>
                        now(),
                ]);

            DB::table('email_verifications')
                ->where(
                    'user_id',
                    $user->id
                )
                ->delete();

            $deviceId = $this->resolveDevice(
                (int) $user->id,
                $deviceUuid,
                $deviceType
            );

            $accessToken =
                $this->jwt->accessToken(
                    (int) $user->id,
                    $user->email
                );

            $refreshToken =
                $this->jwt->refreshToken();

            DB::table('refresh_tokens')
                ->insert([
                    'user_id' =>
                        $user->id,

                    'device_id' =>
                        $deviceId,

                    'token_hash' =>
                        hash(
                            'sha256',
                            $refreshToken
                        ),

                    'expires_at' =>
                        now()->addDays(
                            (int) env(
                                'JWT_REFRESH_DAYS',
                                7
                            )
                        ),
                ]);

            DB::table('login_history')
                ->insert([
                    'user_id' =>
                        $user->id,

                    'device_id' =>
                        $deviceId,

                    'ip_address' =>
                        $request->ip()
                        ?: '127.0.0.1',

                    'login_status' =>
                        'success',
                ]);

            DB::commit();

            return response()->json([
                'success' => true,

                'message' =>
                    'Email verified successfully',

                'access_token' =>
                    $accessToken,

                'refresh_token' =>
                    $refreshToken,

                'token_type' =>
                    'Bearer',

                'expires_in' =>
                    900,

                'user' => [
                    'id' =>
                        (int) $user->id,

                    'email' =>
                        $user->email,

                    'status' =>
                        'active',
                ],
            ]);

        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            report($e);

            return response()->json([
                'success' => false,

                'message' =>
                    'Email verification failed',

                'details' =>
                    app()->environment('local')
                        ? $e->getMessage()
                        : null,
            ], 500);
        }
    }

    private function resolveDevice(
        int $userId,
        string $deviceUuid,
        string $deviceType
    ): ?int {
        if ($deviceUuid === '') {
            return null;
        }

        $device = DB::table('devices')
            ->where(
                'user_id',
                $userId
            )
            ->where(
                'device_uuid',
                $deviceUuid
            )
            ->first();

        if ($device) {
            DB::table('devices')
                ->where(
                    'id',
                    $device->id
                )
                ->update([
                    'device_type' =>
                        $deviceType,

                    'updated_at' =>
                        now(),
                ]);

            return (int) $device->id;
        }

        return DB::table('devices')
            ->insertGetId([
                'user_id' =>
                    $userId,

                'device_uuid' =>
                    $deviceUuid,

                'device_type' =>
                    $deviceType,
            ]);
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