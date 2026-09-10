<?php

namespace App\Http\Middleware;

use App\Services\Auth\AuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    public function __construct(private AuthorizationService $authorization) {}

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->attributes->get('auth_user');
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Access token required'], 401);
        }

        $slugs = $this->authorization->rolesForUser((int) $user['id']);
        if (!in_array('super-admin', $slugs, true)) {
            $allowed = false;
            foreach ($roles as $role) {
                if (in_array($role, $slugs, true)) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient permissions. Required: ' . implode(' or ', $roles),
                ], 403);
            }
        }

        return $next($request);
    }
}
