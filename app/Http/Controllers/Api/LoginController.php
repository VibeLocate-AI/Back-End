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
    public function __construct(private JwtService $jwt) {}

    /**
     * Normal user login.
     */
    public function __invoke(Request $request)
    {
        return $this->login(
            $request,
            null,
            'user'
        );
    }

    /**
     * Admin login.
     */
    public function adminLogin(Request $request)
    {
        $email = strtolower(
            trim((string) $request->input('email', ''))
        );

        if ($email !== 'admin@vibelocate.ai') {
            return response()->json([
                'success' => false,
                'message' => 'This account is not allowed to access admin login',
            ], 403);
        }

        return $this->login(
            $request,
            ['admin', 'super-admin'],
            'admin'
        );
    }

    /**
     * Agent login.
     */
    public function agentLogin(Request $request)
    {
        return $this->login(
            $request,
            ['agent'],
            'agent'
        );
    }

    /**
     * Shared login logic.
     */
    private function login(
        Request $request,
        ?array $requiredRoles = null,
        string $loginType = 'user'
    ) {
        $email = strtolower(
            trim((string) $request->input('email', ''))
        );

        $password = (string) $request->input(
            'password',
            ''
        );

        $deviceUuid = trim(
            (string) $request->input('device_uuid', '')
        );

        $deviceType = (string) $request->input(
            'device_type',
            'web'
        );

        $rememberMe = !empty(
            $request->input('remember_me')
        );

        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
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

        if (!in_array(
            $deviceType,
            ['ios', 'android', 'web', 'desktop'],
            true
        )) {
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
            ->where('email', $email)
            ->whereNull('deleted_at')
            ->first();

        if (
            !$user ||
            !Hash::check(
                $password,
                $user->password_hash
            )
        ) {
            if ($user) {
                DB::table('login_history')->insert([
                    'user_id' => $user->id,
                    'ip_address' =>
                        $request->ip() ?: '127.0.0.1',
                    'login_status' => 'failed',
                ]);
            }

            $this->recordApiLog(
                'auth.' . $loginType . '.login',
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
        | Load Roles
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
            ->pluck('r.slug')
            ->values()
            ->all();

        /*
        |--------------------------------------------------------------------------
        | Required Role Check
        |--------------------------------------------------------------------------
        */

        if ($requiredRoles !== null) {
            $hasRequiredRole = count(
                array_intersect(
                    $roles,
                    $requiredRoles
                )
            ) > 0;

            if (!$hasRequiredRole) {
                $this->recordApiLog(
                    'auth.' . $loginType . '.login',
                    403,
                    $request
                );

                return response()->json([
                    'success' => false,
                    'message' => $loginType === 'admin'
                        ? 'This account is not an admin account'
                        : 'This account is not an agent account',
                ], 403);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Login Transaction
        |--------------------------------------------------------------------------
        */

        DB::beginTransaction();

        try {
            $deviceId = null;

            /*
            |--------------------------------------------------------------------------
            | Device
            |--------------------------------------------------------------------------
            */

            if ($deviceUuid !== '') {
                $device = DB::table('devices')
                    ->where(
                        'user_id',
                        $user->id
                    )
                    ->where(
                        'device_uuid',
                        $deviceUuid
                    )
                    ->first();

                if ($device) {
                    $deviceId = (int) $device->id;

                    DB::table('devices')
                        ->where(
                            'id',
                            $deviceId
                        )
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
            | Login History
            |--------------------------------------------------------------------------
            */

            DB::table('login_history')
                ->insert([
                    'user_id' => $user->id,
                    'device_id' => $deviceId,
                    'ip_address' =>
                        $request->ip() ?: '127.0.0.1',
                    'login_status' => 'success',
                ]);

            /*
            |--------------------------------------------------------------------------
            | JWT
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

            $refreshToken = $this->jwt
                ->refreshToken();

            $days = $rememberMe
                ? 30
                : 7;

            DB::table('refresh_tokens')
                ->insert([
                    'user_id' => $user->id,
                    'device_id' => $deviceId,
                    'token_hash' => hash(
                        'sha256',
                        $refreshToken
                    ),
                    'expires_at' =>
                        now()->addDays($days),
                ]);

            DB::commit();

            /*
            |--------------------------------------------------------------------------
            | Determine Primary Role
            |--------------------------------------------------------------------------
            */

            $primaryRole = $this->getPrimaryRole(
                $roles
            );

            /*
            |--------------------------------------------------------------------------
            | API Log
            |--------------------------------------------------------------------------
            */

            $this->recordApiLog(
                'auth.' . $loginType . '.login',
                200,
                $request
            );

            /*
            |--------------------------------------------------------------------------
            | Response
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'success' => true,
                'message' => 'Login successful',

                'access_token' =>
                    $accessToken,

                'refresh_token' =>
                    $refreshToken,

                'expires_in' => 900,

                'user' => [
                    'id' => (int) $user->id,

                    'first_name' =>
                        $user->first_name ?? null,

                    'last_name' =>
                        $user->last_name ?? null,

                    'email' =>
                        $user->email,

                    'phone' =>
                        $user->phone ?? null,

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
            DB::rollBack();

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Login failed',

                'details' =>
                    app()->environment('local')
                        ? $e->getMessage()
                        : null,

            ], 500);
        }
    }

    /**
     * Determine primary role.
     */
    private function getPrimaryRole(array $roles): string
    {
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

    /**
     * Frontend redirect hint.
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

    /**
     * Standard error response.
     */
    private function error(
        string $message,
        int $status
    ) {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }

    /**
     * API logging.
     */
    private function recordApiLog(
        string $endpoint,
        int $code,
        Request $request
    ): void {
        try {
            DB::table('api_logs')->insert([
                'endpoint' =>
                    $endpoint,

                'method' =>
                    $request->method(),

                'response_code' =>
                    $code,

                'execution_time_ms' =>
                    0,

                'ip_address' =>
                    $request->ip() ?: '127.0.0.1',
            ]);
        } catch (Throwable) {
            // API logging should never prevent login.
        }
    }
}