<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VerifyResetOtpController extends Controller
{
    public function __invoke(Request $request)
    {
        $email = strtolower(trim((string) $request->input('email', '')));

        $otp = trim((string) (
            $request->input('otp')
            ?? $request->input('code')
            ?? $request->input('token')
            ?? ''
        ));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid email address',
            ], 422);
        }

        if ($otp === '') {
            return response()->json([
                'success' => false,
                'message' => 'Verification code is required',
            ], 422);
        }

        if (!ctype_digit($otp) || strlen($otp) !== 6) {
            return response()->json([
                'success' => false,
                'message' => 'Verification code must be 6 digits',
            ], 422);
        }

        $record = DB::table('password_resets')
            ->where('email', $email)
            ->where('token', $otp)
            ->where('expires_at', '>', now())
            ->first();

        if (!$record) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired verification code',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Verification code confirmed',
            'email' => $email,
            'reset_token' => $otp,
        ]);
    }
}