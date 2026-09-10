<?php

namespace App\Http\Middleware;

use App\Services\Auth\JwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class OptionalJwt
{
    public function __construct(private JwtService $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if ($token) {
            $payload = $this->jwt->decode($token);
            if ($payload && ($payload['type'] ?? '') === 'access' && !empty($payload['user_id'])) {
                $user = DB::table('users')
                    ->where('id', (int) $payload['user_id'])
                    ->where('status', 'active')
                    ->whereNull('deleted_at')
                    ->first();
                if ($user) {
                    $request->attributes->set('auth_user', (array) $user);
                }
            }
        }
        return $next($request);
    }
}
