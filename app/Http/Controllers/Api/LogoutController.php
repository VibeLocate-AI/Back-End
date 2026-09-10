<?php

namespace App\Http\Controllers\Api;

use App\Services\Auth\JwtService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LogoutController extends Controller
{
    public function __construct(private JwtService $jwt) {}

    public function __invoke(Request $request)
    {
        $refreshToken = trim((string) $request->input('refresh_token', ''));
        if ($refreshToken !== '') {
            DB::table('refresh_tokens')->where('token_hash', hash('sha256', $refreshToken))->update(['is_revoked' => 1]);
        } else {
            $accessToken = $request->bearerToken();
            if ($accessToken) {
                $payload = $this->jwt->decode($accessToken);
                if ($payload && !empty($payload['user_id'])) {
                    DB::table('refresh_tokens')->where('user_id', $payload['user_id'])->update(['is_revoked' => 1]);
                }
            }
        }
        return response()->json(['success' => true, 'message' => 'Logged out successfully']);
    }
}
