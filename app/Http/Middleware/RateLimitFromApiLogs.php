<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class RateLimitFromApiLogs
{
    public function handle(Request $request, Closure $next, string $endpoint = 'auth.login', int $maxAttempts = 5, int $windowMinutes = 15): Response
    {
        $windowMinutes = max(1, $windowMinutes);
        $attempts = DB::table('api_logs')
            ->where('endpoint', $endpoint)
            ->where('ip_address', $request->ip() ?: '127.0.0.1')
            ->where('response_code', 401)
            ->where('created_at', '>', now()->subMinutes($windowMinutes))
            ->count();

        if ($attempts >= $maxAttempts) {
            return response()->json(['success' => false, 'message' => 'Too many attempts. Try again later.'], 429);
        }

        return $next($request);
    }
}
