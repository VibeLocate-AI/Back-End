<?php

namespace App\Http\Middleware;

use App\Services\Auth\CsrfService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireVibeCsrf
{
    public function __construct(private CsrfService $csrf) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-CSRF-Token') ?: $request->input('csrf_token');
        if (!$this->csrf->verify($token)) {
            return response()->json(['success' => false, 'message' => 'Invalid CSRF token'], 403);
        }
        return $next($request);
    }
}
