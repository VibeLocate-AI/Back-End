<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SessionsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->attributes->get('auth_user');
        $devices = DB::table('devices as d')
            ->leftJoin('refresh_tokens as rt', function ($join) {
                $join->on('rt.device_id', '=', 'd.id')
                    ->where('rt.is_revoked', 0)
                    ->where('rt.expires_at', '>', now());
            })
            ->where('d.user_id', $user['id'])
            ->groupBy('d.id', 'd.device_uuid', 'd.device_type', 'd.device_model', 'd.os_version', 'd.created_at', 'd.updated_at')
            ->orderByDesc('d.updated_at')
            ->select(
                'd.id', 'd.device_uuid', 'd.device_type', 'd.device_model', 'd.os_version',
                'd.created_at', 'd.updated_at', DB::raw('COUNT(rt.id) AS active_tokens')
            )->get();

        return response()->json(['success' => true, 'sessions' => $devices]);
    }

    public function destroy(Request $request)
    {
        $user = $request->attributes->get('auth_user');
        $deviceId = (int) $request->input('device_id', 0);
        if ($deviceId <= 0) {
            return response()->json(['success' => false, 'message' => 'Valid device_id is required'], 422);
        }

        DB::table('refresh_tokens')->where('user_id', $user['id'])->where('device_id', $deviceId)->update(['is_revoked' => 1]);
        DB::table('devices')->where('id', $deviceId)->where('user_id', $user['id'])->delete();
        return response()->json(['success' => true, 'message' => 'Session terminated successfully']);
    }
}
