<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

class ResetPasswordController extends Controller
{
    public function __invoke(Request $request)
    {
        $email = strtolower(trim((string) $request->input('email', '')));

        $token = trim((string) (
            $request->input('token')
            ?? $request->input('otp')
            ?? $request->input('code')
            ?? ''
        ));

        $newPassword = (string) $request->input('new_password', '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('Invalid email address', 422);
        }

        if ($token === '') {
            return $this->error('Reset token is required', 422);
        }

        if (strlen($newPassword) < 8) {
            return $this->error(
                'Password must be at least 8 characters',
                422
            );
        }

        $record = DB::table('password_resets')
            ->where('email', $email)
            ->where('token', $token)
            ->where('expires_at', '>', now())
            ->first();

        if (!$record) {
            return $this->error(
                'Invalid or expired reset token',
                400
            );
        }

        $user = DB::table('users')
            ->where('email', $email)
            ->whereNull('deleted_at')
            ->select('id')
            ->first();

        if (!$user) {
            return $this->error('User not found', 404);
        }

        DB::beginTransaction();

        try {
            DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'password_hash' => Hash::make($newPassword),
                ]);

            DB::table('password_resets')
                ->where('email', $email)
                ->delete();

            DB::table('refresh_tokens')
                ->where('user_id', $user->id)
                ->update([
                    'is_revoked' => 1,
                ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Password reset successfully',
            ]);

        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Password reset failed',
                'details' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    private function error(string $message, int $status)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}