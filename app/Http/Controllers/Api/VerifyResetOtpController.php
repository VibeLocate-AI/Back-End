<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class VerifyResetOtpController extends Controller
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

        $otp = trim(
            (string) (
                $request->input('otp')
                ?? $request->input('code')
                ?? ''
            )
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
            strlen($otp) !== 6 ||
            !ctype_digit($otp)
        ) {
            return $this->error(
                'Verification code must be 6 digits',
                422
            );
        }

        $otpHash = hash(
            'sha256',
            $otp
        );

        DB::beginTransaction();

        try {
            $record = DB::table(
                'password_resets'
            )
                ->where(
                    'email',
                    $email
                )
                ->where(
                    'token',
                    $otpHash
                )
                ->where(
                    'expires_at',
                    '>',
                    now()
                )
                ->lockForUpdate()
                ->first();

            if (!$record) {
                DB::rollBack();

                return $this->error(
                    'Invalid or expired verification code',
                    400
                );
            }

            /*
            |--------------------------------------------------------------------------
            | OTP -> Secure Reset Token
            |--------------------------------------------------------------------------
            */

            $resetToken = bin2hex(
                random_bytes(32)
            );

            $resetTokenHash = hash(
                'sha256',
                $resetToken
            );

            DB::table('password_resets')
                ->where(
                    'id',
                    $record->id
                )
                ->update([
                    'token' =>
                        $resetTokenHash,

                    'expires_at' =>
                        now()->addMinutes(15),
                ]);

            DB::commit();

            return response()->json([
                'success' => true,

                'message' =>
                    'Verification code confirmed',

                'email' =>
                    $email,

                'reset_token' =>
                    $resetToken,

                'expires_in' =>
                    900,
            ]);

        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            report($e);

            return response()->json([
                'success' => false,

                'message' =>
                    'Could not verify reset code',

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