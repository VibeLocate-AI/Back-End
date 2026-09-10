<?php

namespace App\Http\Middleware;

use App\Services\Auth\AuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function __construct(private AuthorizationService $authorization) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->attributes->get('auth_user');
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Access token required'], 401);
        }

        if (!$this->authorization->userHasPermission((int) $user['id'], $permission)) {
            return response()->json(['success' => false, 'message' => "Permission denied: {$permission}"], 403);
        }

        return $next($request);
    }
}
