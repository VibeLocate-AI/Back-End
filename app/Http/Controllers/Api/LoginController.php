<?php

namespace App\Http\Controllers\Api;

use App\Services\Auth\JwtService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

class LoginController extends Controller
{
    public function __construct(private JwtService $jwt) {}

    public function __invoke(Request $request)
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        $password = (string) $request->input('password', '');
        $deviceUuid = trim((string) $request->input('device_uuid', ''));
        $deviceType = (string) $request->input('device_type', 'web');
        $rememberMe = !empty($request->input('remember_me'));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return $this->error('Invalid email address', 422);
        if ($password === '') return $this->error('Password is required', 422);
        if (!in_array($deviceType, ['ios', 'android', 'web', 'desktop'], true)) return $this->error('Invalid device type', 422);

        $user = DB::table('users')->where('email', $email)->whereNull('deleted_at')->first();
        if (!$user || !Hash::check($password, $user->password_hash)) {
            if ($user) {
                DB::table('login_history')->insert([
                    'user_id' => $user->id,
                    'ip_address' => $request->ip() ?: '127.0.0.1',
                    'login_status' => 'failed',
                ]);
            }
            $this->recordApiLog('auth.login', 401, $request);
            return $this->error('Invalid email or password', 401);
        }

        if ($user->status === 'pending') return $this->error('Please verify your email first', 403);
        if ($user->status !== 'active') return $this->error('Account is not active', 403);

        DB::beginTransaction();
        try {
            $deviceId = null;
            if ($deviceUuid !== '') {
                $device = DB::table('devices')->where('user_id', $user->id)->where('device_uuid', $deviceUuid)->first();
                if ($device) {
                    $deviceId = (int) $device->id;
                    DB::table('devices')->where('id', $deviceId)->update(['device_type' => $deviceType, 'updated_at' => now()]);
                } else {
                    $deviceId = DB::table('devices')->insertGetId([
                        'user_id' => $user->id,
                        'device_uuid' => $deviceUuid,
                        'device_type' => $deviceType,
                    ]);
                }
            }

            DB::table('login_history')->insert([
                'user_id' => $user->id,
                'device_id' => $deviceId,
                'ip_address' => $request->ip() ?: '127.0.0.1',
                'login_status' => 'success',
            ]);

            $accessToken = $this->jwt->accessToken((int) $user->id, $user->email);
            $refreshToken = $this->jwt->refreshToken();
            $days = $rememberMe ? 30 : 7;
            DB::table('refresh_tokens')->insert([
                'user_id' => $user->id,
                'device_id' => $deviceId,
                'token_hash' => hash('sha256', $refreshToken),
                'expires_at' => now()->addDays($days),
            ]);

            DB::commit();
            $this->recordApiLog('auth.login', 200, $request);
            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_in' => 900,
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Login failed',
                'details' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function error(string $message, int $status)
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }

    private function recordApiLog(string $endpoint, int $code, Request $request): void
    {
        try {
            DB::table('api_logs')->insert([
                'endpoint' => $endpoint,
                'method' => $request->method(),
                'response_code' => $code,
                'execution_time_ms' => 0,
                'ip_address' => $request->ip() ?: '127.0.0.1',
            ]);
        } catch (Throwable) {}
    }
}
