<?php

namespace App\Http\Controllers\Api;

use App\Services\Auth\JwtService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class RefreshTokenController extends Controller
{
    public function __construct(private JwtService $jwt) {}

    public function __invoke(Request $request)
    {
        $refreshToken = trim((string) $request->input('refresh_token', ''));
        if ($refreshToken === '') return $this->error('Refresh token is required', 422);

        $record = DB::table('refresh_tokens as rt')
            ->join('users as u', 'u.id', '=', 'rt.user_id')
            ->where('rt.token_hash', hash('sha256', $refreshToken))
            ->where('rt.is_revoked', 0)
            ->where('rt.expires_at', '>', now())
            ->where('u.status', 'active')
            ->whereNull('u.deleted_at')
            ->select('rt.id', 'rt.user_id', 'rt.device_id', 'u.email', 'u.status')
            ->first();

        if (!$record) return $this->error('Invalid or expired refresh token', 401);

        DB::beginTransaction();
        try {
            DB::table('refresh_tokens')->where('id', $record->id)->update(['is_revoked' => 1]);
            $newRefreshToken = $this->jwt->refreshToken();
            DB::table('refresh_tokens')->insert([
                'user_id' => $record->user_id,
                'device_id' => $record->device_id,
                'token_hash' => hash('sha256', $newRefreshToken),
                'expires_at' => now()->addDays(7),
            ]);
            $newAccessToken = $this->jwt->accessToken((int) $record->user_id, $record->email);
            DB::commit();
            return response()->json([
                'success' => true,
                'access_token' => $newAccessToken,
                'refresh_token' => $newRefreshToken,
                'expires_in' => 900,
            ]);
        } catch (Throwable) {
            DB::rollBack();
            return $this->error('Could not refresh token', 500);
        }
    }

    private function error(string $message, int $status)
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
