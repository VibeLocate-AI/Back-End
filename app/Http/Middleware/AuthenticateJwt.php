<?php

namespace App\Http\Middleware;

use App\Services\Auth\JwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateJwt
{
    public function __construct(private JwtService $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (!$token) {
            return response()->json(['success' => false, 'message' => 'Access token required'], 401);
        }

        $payload = $this->jwt->decode($token);
        if (!$payload || ($payload['type'] ?? '') !== 'access' || empty($payload['user_id'])) {
            return response()->json(['success' => false, 'message' => 'Invalid or expired token'], 401);
        }

        $user = DB::table('users as u')
            ->leftJoin('user_profiles as up', 'up.user_id', '=', 'u.id')
            ->where('u.id', (int) $payload['user_id'])
            ->whereNull('u.deleted_at')
            ->select('u.*', 'up.avatar_url', 'up.bio', 'up.preferred_language', 'up.currency', 'up.nationality', 'up.date_of_birth', 'up.gender')
            ->first();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'User not found'], 401);
        }
        if ($user->status === 'suspended') {
            return response()->json(['success' => false, 'message' => 'Account suspended'], 403);
        }
        if ($user->status === 'inactive') {
            return response()->json(['success' => false, 'message' => 'Account inactive'], 403);
        }
        if ($user->status === 'pending') {
            return response()->json(['success' => false, 'message' => 'Please verify your email first'], 403);
        }

        $request->attributes->set('auth_user', (array) $user);
        return $next($request);
    }
}
