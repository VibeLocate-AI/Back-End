<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\JwtService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

class LoginController extends Controller
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

        $password = (string) $request->input(
            'password',
            ''
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

        $rememberMe = filter_var(
            $request->input(
                'remember_me',
                false
            ),
            FILTER_VALIDATE_BOOLEAN
        );

        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        if (!filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )) {
            return $this->error(
                'Invalid email address',
                422
            );
        }

        if ($password === '') {
            return $this->error(
                'Password is required',
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

        /*
        |--------------------------------------------------------------------------
        | Find User
        |--------------------------------------------------------------------------
        */

        $user = DB::table('users')
            ->where(
                'email',
                $email
            )
            ->whereNull(
                'deleted_at'
            )
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Credentials
        |--------------------------------------------------------------------------
        */

        if (
            !$user ||
            !Hash::check(
                $password,
                $user->password_hash
            )
        ) {
            if ($user) {
                try {
                    DB::table('login_history')
                        ->insert([
                            'user_id' =>
                                $user->id,

                            'device_id' =>
                                null,

                            'ip_address' =>
                                $request->ip()
                                ?: '127.0.0.1',

                            'login_status' =>
                                'failed',
                        ]);
                } catch (Throwable) {
                    //
                }
            }

            $this->recordApiLog(
                'auth.login',
                401,
                $request
            );

            return $this->error(
                'Invalid email or password',
                401
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Account Status
        |--------------------------------------------------------------------------
        */

        if ($user->status === 'pending') {
            return $this->error(
                'Please verify your email first',
                403
            );
        }

        if ($user->status !== 'active') {
            return $this->error(
                'Account is not active',
                403
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Roles
        |--------------------------------------------------------------------------
        */

        $roles = DB::table('user_roles as ur')
            ->join(
                'roles as r',
                'r.id',
                '=',
                'ur.role_id'
            )
            ->where(
                'ur.user_id',
                $user->id
            )
            ->pluck(
                'r.slug'
            )
            ->map(
                fn ($role) =>
                    (string) $role
            )
            ->values()
            ->all();

        $primaryRole =
            $this->getPrimaryRole(
                $roles
            );

        /*
        |--------------------------------------------------------------------------
        | Session Transaction
        |--------------------------------------------------------------------------
        */

        DB::beginTransaction();

        try {
            /*
            |--------------------------------------------------------------------------
            | Device
            |--------------------------------------------------------------------------
            */

            $deviceId = null;

            if ($deviceUuid !== '') {
                /*
                 * Lock the device row during login.
                 *
                 * This helps prevent two simultaneous logins on the same
                 * device from generating multiple active refresh tokens.
                 */
                $device = DB::table('devices')
                    ->where(
                        'user_id',
                        $user->id
                    )
                    ->where(
                        'device_uuid',
                        $deviceUuid
                    )
                    ->lockForUpdate()
                    ->first();

                if ($device) {
                    $deviceId =
                        (int) $device->id;

                    DB::table('devices')
                        ->where(
                            'id',
                            $deviceId
                        )
                        ->update([
                            'device_type' =>
                                $deviceType,

                            'updated_at' =>
                                now(),
                        ]);

                } else {
                    $deviceId =
                        DB::table('devices')
                            ->insertGetId([
                                'user_id' =>
                                    $user->id,

                                'device_uuid' =>
                                    $deviceUuid,

                                'device_type' =>
                                    $deviceType,
                            ]);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Revoke Previous Session Token
            |--------------------------------------------------------------------------
            |
            | Final rule:
            |
            | One device = one active refresh token.
            |
            | Logging in again from the same device invalidates any previous
            | refresh token belonging to that device.
            |
            */

            if ($deviceId !== null) {
                DB::table('refresh_tokens')
                    ->where(
                        'user_id',
                        $user->id
                    )
                    ->where(
                        'device_id',
                        $deviceId
                    )
                    ->where(
                        'is_revoked',
                        0
                    )
                    ->update([
                        'is_revoked' => 1,
                    ]);
            } else {
                /*
                 * Requests without device_uuid share one anonymous session.
                 */
                DB::table('refresh_tokens')
                    ->where(
                        'user_id',
                        $user->id
                    )
                    ->whereNull(
                        'device_id'
                    )
                    ->where(
                        'is_revoked',
                        0
                    )
                    ->update([
                        'is_revoked' => 1,
                    ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Access Token
            |--------------------------------------------------------------------------
            */

            $accessToken =
                $this->jwt->accessToken(
                    (int) $user->id,
                    $user->email
                );

            /*
            |--------------------------------------------------------------------------
            | Refresh Token
            |--------------------------------------------------------------------------
            */

            $refreshToken =
                $this->jwt->refreshToken();

            $refreshDays =
                $rememberMe
                    ? 30
                    : 7;

            $refreshExpiresAt =
                now()->addDays(
                    $refreshDays
                );

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

                    'is_revoked' =>
                        0,

                    'expires_at' =>
                        $refreshExpiresAt,
                ]);

            /*
            |--------------------------------------------------------------------------
            | Login History
            |--------------------------------------------------------------------------
            */

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

            /*
            |--------------------------------------------------------------------------
            | API Log
            |--------------------------------------------------------------------------
            */

            $this->recordApiLog(
                'auth.login',
                200,
                $request
            );

            /*
            |--------------------------------------------------------------------------
            | Response
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'success' =>
                    true,

                'message' =>
                    'Login successful',

                'access_token' =>
                    $accessToken,

                'refresh_token' =>
                    $refreshToken,

                'token_type' =>
                    'Bearer',

                /*
                 * Access JWT = 15 minutes.
                 */
                'expires_in' =>
                    900,

                /*
                 * 7 days normally.
                 * 30 days with Remember Me.
                 */
                'refresh_expires_in' =>
                    $refreshDays
                    * 24
                    * 60
                    * 60,

                'refresh_expires_at' =>
                    $refreshExpiresAt
                        ->toDateTimeString(),

                'remember_me' =>
                    $rememberMe,

                'device_id' =>
                    $deviceId,

                'user' => [
                    'id' =>
                        (int) $user->id,

                    'first_name' =>
                        $user->first_name
                        ?? null,

                    'last_name' =>
                        $user->last_name
                        ?? null,

                    'email' =>
                        $user->email,

                    'phone' =>
                        $user->phone
                        ?? null,

                    'status' =>
                        $user->status,

                    'role' =>
                        $primaryRole,

                    'roles' =>
                        $roles,
                ],

                'redirect_to' =>
                    $this->getRedirectPath(
                        $primaryRole
                    ),
            ]);

        } catch (Throwable $e) {
            if (
                DB::transactionLevel() > 0
            ) {
                DB::rollBack();
            }

            report($e);

            return response()->json([
                'success' =>
                    false,

                'message' =>
                    'Login failed',

                'details' =>
                    app()->environment('local')
                        ? $e->getMessage()
                        : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Primary Role
    |--------------------------------------------------------------------------
    */

    private function getPrimaryRole(
        array $roles
    ): string {
        if (
            in_array(
                'super-admin',
                $roles,
                true
            )
        ) {
            return 'super-admin';
        }

        if (
            in_array(
                'admin',
                $roles,
                true
            )
        ) {
            return 'admin';
        }

        if (
            in_array(
                'agent',
                $roles,
                true
            )
        ) {
            return 'agent';
        }

        if (!empty($roles)) {
            return (string) $roles[0];
        }

        return 'user';
    }

    /*
    |--------------------------------------------------------------------------
    | Frontend Redirect
    |--------------------------------------------------------------------------
    */

    private function getRedirectPath(
        string $role
    ): string {
        return match ($role) {
            'admin',
            'super-admin'
                => '/admin/dashboard',

            'agent'
                => '/agent/dashboard',

            default
                => '/home',
        };
    }

    /*
    |--------------------------------------------------------------------------
    | API Log
    |--------------------------------------------------------------------------
    */

    private function recordApiLog(
        string $endpoint,
        int $code,
        Request $request
    ): void {
        try {
            DB::table('api_logs')
                ->insert([
                    'endpoint' =>
                        $endpoint,

                    'method' =>
                        $request->method(),

                    'response_code' =>
                        $code,

                    'execution_time_ms' =>
                        0,

                    'ip_address' =>
                        $request->ip()
                        ?: '127.0.0.1',
                ]);

        } catch (Throwable) {
            /*
             * Authentication should never fail
             * because API logging failed.
             */
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Standard Error
    |--------------------------------------------------------------------------
    */

    private function error(
        string $message,
        int $status
    ) {
        return response()->json([
            'success' =>
                false,

            'message' =>
                $message,
        ], $status);
    }
}