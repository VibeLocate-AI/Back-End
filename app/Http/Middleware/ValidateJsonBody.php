<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateJsonBody
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isJson()) {
            $raw = $request->getContent();
            if (trim($raw) !== '') {
                json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return response()->json(['success' => false, 'message' => 'Invalid JSON body'], 400);
                }
            }
        }
        return $next($request);
    }
}
