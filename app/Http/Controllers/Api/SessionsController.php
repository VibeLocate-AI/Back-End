<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class SessionsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->attributes->get('auth_user');

        if (!$user || empty($user['id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $userId = (int) $user['id'];

        /*
        |--------------------------------------------------------------------------
        | Active Sessions Only
        |--------------------------------------------------------------------------
        |
        | A session exists only when the device has at least one
        | non-revoked, non-expired refresh token.
        |
        */

        $sessions = DB::table('devices as d')
            ->join(
                'refresh_tokens as rt',
                'rt.device_id',
                '=',
                'd.id'
            )
            ->where(
                'd.user_id',
                $userId
            )
            ->where(
                'rt.user_id',
                $userId
            )
            ->where(
                'rt.is_revoked',
                0
            )
            ->where(
                'rt.expires_at',
                '>',
                now()
            )
            ->groupBy(
                'd.id',
                'd.device_uuid',
                'd.device_type',
                'd.device_model',
                'd.os_version',
                'd.created_at',
                'd.updated_at'
            )
            ->orderByDesc(
                'd.updated_at'
            )
            ->select([
                'd.id',
                'd.device_uuid',
                'd.device_type',
                'd.device_model',
                'd.os_version',
                'd.created_at',
                'd.updated_at',

                DB::raw(
                    'COUNT(rt.id) AS active_tokens'
                ),

                DB::raw(
                    'MAX(rt.expires_at) AS expires_at'
                ),
            ])
            ->get();

        return response()->json([
            'success' => true,
            'sessions' => $sessions,
        ]);
    }

    public function destroy(Request $request)
    {
        $user = $request->attributes->get('auth_user');

        if (!$user || empty($user['id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $userId = (int) $user['id'];

        $deviceId = (int) $request->input(
            'device_id',
            0
        );

        if ($deviceId <= 0) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Valid device_id is required',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Verify Device Ownership
        |--------------------------------------------------------------------------
        */

        $device = DB::table('devices')
            ->where(
                'id',
                $deviceId
            )
            ->where(
                'user_id',
                $userId
            )
            ->first();

        if (!$device) {
            return response()->json([
                'success' => false,
                'message' => 'Session not found',
            ], 404);
        }

        try {
            /*
            |--------------------------------------------------------------------------
            | Revoke Session Tokens
            |--------------------------------------------------------------------------
            |
            | Do NOT delete the device record.
            |
            | Keeping it preserves device metadata/history and allows
            | the same device_uuid to be reused on the next login.
            |
            */

            DB::table('refresh_tokens')
                ->where(
                    'user_id',
                    $userId
                )
                ->where(
                    'device_id',
                    $deviceId
                )
                ->where(
                    'is_revoked',
                    0
                )
                ->update([
                    'is_revoked' => 1,
                ]);

            return response()->json([
                'success' => true,
                'message' =>
                    'Session terminated successfully',
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,

                'message' =>
                    'Could not terminate session',

                'details' =>
                    app()->environment('local')
                        ? $e->getMessage()
                        : null,
            ], 500);
        }
    }
}