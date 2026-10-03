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
        $email = strtolower(
            trim(
                (string) $request->input(
                    'email',
                    ''
                )
            )
        );

        $resetToken = trim(
            (string) $request->input(
                'token',
                ''
            )
        );

        $newPassword = (string) $request->input(
            'new_password',
            ''
        );

        $confirmation =
            (string) $request->input(
                'new_password_confirmation',
                ''
            );

        if (!filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )) {
            return $this->error(
                'Invalid email address',
                422
            );
        }

        if (
            strlen($resetToken) !== 64 ||
            !ctype_xdigit($resetToken)
        ) {
            return $this->error(
                'Invalid reset token format',
                422
            );
        }

        if (strlen($newPassword) < 8) {
            return $this->error(
                'Password must be at least 8 characters',
                422
            );
        }

        if ($newPassword !== $confirmation) {
            return $this->error(
                'Password confirmation does not match',
                422
            );
        }

        $resetTokenHash = hash(
            'sha256',
            $resetToken
        );

        $record = DB::table('password_resets')
            ->where(
                'email',
                $email
            )
            ->where(
                'token',
                $resetTokenHash
            )
            ->where(
                'expires_at',
                '>',
                now()
            )
            ->first();

        if (!$record) {
            return $this->error(
                'Invalid or expired reset token',
                400
            );
        }

        $user = DB::table('users')
            ->where(
                'email',
                $email
            )
            ->whereNull(
                'deleted_at'
            )
            ->select([
                'id',
                'password_hash',
            ])
            ->first();

        if (!$user) {
            return $this->error(
                'User not found',
                404
            );
        }

        if (
            Hash::check(
                $newPassword,
                $user->password_hash
            )
        ) {
            return $this->error(
                'New password must be different from current password',
                422
            );
        }

        DB::beginTransaction();

        try {
            DB::table('users')
                ->where(
                    'id',
                    $user->id
                )
                ->update([
                    'password_hash' =>
                        Hash::make(
                            $newPassword
                        ),

                    'updated_at' =>
                        now(),
                ]);

            DB::table('password_resets')
                ->where(
                    'email',
                    $email
                )
                ->delete();

            DB::table('refresh_tokens')
                ->where(
                    'user_id',
                    $user->id
                )
                ->update([
                    'is_revoked' =>
                        1,
                ]);

            DB::table('password_change_otps')
                ->where(
                    'user_id',
                    $user->id
                )
                ->delete();

            DB::commit();

            return response()->json([
                'success' => true,

                'message' =>
                    'Password reset successfully. Please log in again.',
            ]);

        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            report($e);

            return response()->json([
                'success' => false,

                'message' =>
                    'Password reset failed',

                'details' =>
                    app()->environment('local')
                        ? $e->getMessage()
                        : null,
            ], 500);
        }
    }

    private function error(
        string $message,
        int $status
    ) {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}