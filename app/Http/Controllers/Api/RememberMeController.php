<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RememberMeController extends Controller
{
    public function __invoke(Request $request)
    {
        $refreshToken = trim((string) $request->input('refresh_token', ''));
        $remember = !empty($request->input('remember_me'));
        if ($refreshToken === '') return $this->error('Refresh token is required', 422);

        $token = DB::table('refresh_tokens')
            ->where('token_hash', hash('sha256', $refreshToken))
            ->where('is_revoked', 0)
            ->where('expires_at', '>', now())
            ->first();
        if (!$token) return $this->error('Invalid or expired refresh token', 401);

        DB::table('refresh_tokens')->where('id', $token->id)->update(['expires_at' => now()->addDays($remember ? 30 : 7)]);
        return response()->json([
            'success' => true,
            'message' => $remember ? 'Remember me enabled' : 'Remember me disabled',
        ]);
    }

    private function error(string $message, int $status)
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
