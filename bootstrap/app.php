<?php

use App\Http\Middleware\AuthenticateJwt;
use App\Http\Middleware\OptionalJwt;
use App\Http\Middleware\RateLimitFromApiLogs;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\RequireVibeCsrf;
use App\Http\Middleware\ValidateJsonBody;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;

use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;


return Application::configure(basePath: dirname(__DIR__))

    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )

    ->withMiddleware(function (Middleware $middleware): void {

        /*
        |--------------------------------------------------------------------------
        | CORS
        |--------------------------------------------------------------------------
        |
        | Handle CORS before any API middleware.
        | This is especially important for browser OPTIONS preflight requests
        | coming from the Vercel frontend.
        |
        */
        $middleware->prepend(HandleCors::class);

        /*
        |--------------------------------------------------------------------------
        | API Middleware
        |--------------------------------------------------------------------------
        */
        $middleware->api(prepend: [
            ValidateJsonBody::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Middleware Aliases
        |--------------------------------------------------------------------------
        */
        $middleware->alias([
            'jwt'        => AuthenticateJwt::class,
            'jwt.optional' => OptionalJwt::class,
            'vibe.rate'  => RateLimitFromApiLogs::class,
            'role'       => RequireRole::class,
            'permission' => RequirePermission::class,
            'vibe.csrf'  => RequireVibeCsrf::class,
        ]);
    })

    ->withExceptions(function (Exceptions $exceptions): void {

        /*
        |--------------------------------------------------------------------------
        | Force JSON responses for API requests
        |--------------------------------------------------------------------------
        */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) =>
                $request->is('api/*') || $request->expectsJson()
        );

        /*
        |--------------------------------------------------------------------------
        | Method Not Allowed
        |--------------------------------------------------------------------------
        */
        $exceptions->render(
            function (MethodNotAllowedHttpException $e, Request $request) {

                if ($request->is('api/*')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Method not allowed',
                    ], 405);
                }

                return null;
            }
        );
    })

    ->create();