<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class LogoutController extends Controller
{
    public function __invoke(Request $request)
    {
        $refreshToken = trim(
            (string) $request->input(
                'refresh_token',
                ''
            )
        );

        if ($refreshToken === '') {
            return response()->json([
                'success' => false,
                'message' =>
                    'Refresh token is required',
            ], 422);
        }

        try {
            $tokenHash = hash(
                'sha256',
                $refreshToken
            );

            DB::table('refresh_tokens')
                ->where(
                    'token_hash',
                    $tokenHash
                )
                ->where(
                    'is_revoked',
                    0
                )
                ->update([
                    'is_revoked' =>
                        1,
                ]);

            return response()->json([
                'success' => true,

                'message' =>
                    'Logged out successfully',
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,

                'message' =>
                    'Logout failed',

                'details' =>
                    app()->environment('local')
                        ? $e->getMessage()
                        : null,
            ], 500);
        }
    }
}