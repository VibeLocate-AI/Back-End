<?php

namespace App\Http\Controllers\Api;

use App\Services\Auth\TotpService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TwoFactorController extends Controller
{
    public function __construct(private TotpService $totp) {}

    public function show(Request $request)
    {
        $user = $request->attributes->get('auth_user');
        $row = DB::table('two_factor_auth')
            ->where('user_id', $user['id'])
            ->select('method', 'is_enabled', 'verified_at')
            ->first();

        return response()->json([
            'success' => true,
            'two_factor' => $row ?: ['method' => null, 'is_enabled' => 0, 'verified_at' => null],
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->attributes->get('auth_user');
        $action = $request->input('action', 'start');

        if ($action === 'start') {
            $secret = $this->totp->base32Encode(random_bytes(20));
            DB::table('two_factor_auth')->updateOrInsert(
                ['user_id' => $user['id']],
                [
                    'method' => 'authenticator',
                    'secret_encrypted' => $this->totp->encrypt($secret),
                    'is_enabled' => 0,
                    'verified_at' => null,
                ]
            );
            $issuer = rawurlencode('VibeLocate');
            $account = rawurlencode($user['email']);
            return response()->json([
                'success' => true,
                'message' => 'Scan the QR data with an authenticator app',
                'secret' => $secret,
                'otpauth_url' => "otpauth://totp/{$issuer}:{$account}?secret={$secret}&issuer={$issuer}",
            ]);
        }

        if ($action === 'verify') {
            $code = trim((string) $request->input('code', ''));
            $row = DB::table('two_factor_auth')->where('user_id', $user['id'])->select('secret_encrypted')->first();
            if (!$row || !$row->secret_encrypted) {
                return response()->json(['success' => false, 'message' => 'Start two-factor setup first'], 400);
            }
            $secret = $this->totp->decrypt($row->secret_encrypted);
            if (!$secret || !$this->totp->valid($secret, $code)) {
                return response()->json(['success' => false, 'message' => 'Invalid two-factor code'], 422);
            }
            DB::table('two_factor_auth')->where('user_id', $user['id'])->update(['is_enabled' => 1, 'verified_at' => now()]);
            return response()->json(['success' => true, 'message' => 'Two-factor authentication enabled']);
        }

        return response()->json(['success' => false, 'message' => 'Invalid two-factor action'], 422);
    }

    public function destroy(Request $request)
    {
        $user = $request->attributes->get('auth_user');
        DB::table('two_factor_auth')->where('user_id', $user['id'])->update([
            'is_enabled' => 0,
            'secret_encrypted' => null,
            'verified_at' => null,
        ]);
        return response()->json(['success' => true, 'message' => 'Two-factor authentication disabled']);
    }
}
