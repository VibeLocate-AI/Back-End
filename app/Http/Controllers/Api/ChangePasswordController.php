<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Throwable;

class ChangePasswordController extends Controller
{
    public function __invoke(Request $request)
    {
        $authUser = $request
            ->attributes
            ->get('auth_user');

        if (
            !$authUser ||
            empty($authUser['id'])
        ) {
            return $this->error(
                'Unauthenticated',
                401
            );
        }

        $userId = (int) $authUser['id'];

        $currentPassword =
            (string) $request->input(
                'current_password',
                ''
            );

        $newPassword =
            (string) $request->input(
                'new_password',
                ''
            );

        $confirmation =
            (string) $request->input(
                'new_password_confirmation',
                ''
            );

        $otp = trim(
            (string) (
                $request->input('otp')
                ?? $request->input('code')
                ?? ''
            )
        );

        if ($currentPassword === '') {
            return $this->error(
                'Current password is required',
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

        if ($currentPassword === $newPassword) {
            return $this->error(
                'New password must be different from current password',
                422
            );
        }

        $user = DB::table('users')
            ->where(
                'id',
                $userId
            )
            ->whereNull(
                'deleted_at'
            )
            ->select([
                'id',
                'first_name',
                'last_name',
                'email',
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
            !Hash::check(
                $currentPassword,
                $user->password_hash
            )
        ) {
            return $this->error(
                'Current password is incorrect',
                401
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Step 1 - Send OTP
        |--------------------------------------------------------------------------
        */

        if ($otp === '') {
            return $this->sendOtp(
                $user
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Step 2 - Verify OTP
        |--------------------------------------------------------------------------
        */

        if (
            strlen($otp) !== 6 ||
            !ctype_digit($otp)
        ) {
            return $this->error(
                'Verification code must be 6 digits',
                422
            );
        }

        $record = DB::table(
            'password_change_otps'
        )
            ->where(
                'user_id',
                $userId
            )
            ->where(
                'expires_at',
                '>',
                now()
            )
            ->first();

        if (!$record) {
            return $this->error(
                'Verification code is invalid or expired',
                400
            );
        }

        $otpHash = hash(
            'sha256',
            $otp
        );

        if (
            !hash_equals(
                $record->otp_hash,
                $otpHash
            )
        ) {
            return $this->error(
                'Verification code is incorrect',
                400
            );
        }

        DB::beginTransaction();

        try {
            DB::table('users')
                ->where(
                    'id',
                    $userId
                )
                ->update([
                    'password_hash' =>
                        Hash::make(
                            $newPassword
                        ),

                    'updated_at' =>
                        now(),
                ]);

            DB::table('password_change_otps')
                ->where(
                    'user_id',
                    $userId
                )
                ->delete();

            DB::table('refresh_tokens')
                ->where(
                    'user_id',
                    $userId
                )
                ->update([
                    'is_revoked' =>
                        1,
                ]);

            DB::commit();

            return response()->json([
                'success' => true,

                'message' =>
                    'Password changed successfully. Please log in again.',
            ]);

        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            report($e);

            return $this->error(
                'Could not change password',
                500
            );
        }
    }

    private function sendOtp(
        object $user
    ) {
        try {
            $otp = (string) random_int(
                100000,
                999999
            );

            $otpHash = hash(
                'sha256',
                $otp
            );

            DB::table('password_change_otps')
                ->where(
                    'user_id',
                    $user->id
                )
                ->delete();

            DB::table('password_change_otps')
                ->insert([
                    'user_id' =>
                        $user->id,

                    'otp_hash' =>
                        $otpHash,

                    'expires_at' =>
                        now()->addMinutes(10),

                    'created_at' =>
                        now(),

                    'updated_at' =>
                        now(),
                ]);

            $apiKey = env(
                'BREVO_API_KEY'
            );

            $senderEmail = env(
                'BREVO_SENDER_EMAIL'
            );

            $senderName = env(
                'BREVO_SENDER_NAME',
                'VibeLocate AI'
            );

            if (!$apiKey || !$senderEmail) {
                throw new \RuntimeException(
                    'Email service is not configured'
                );
            }

            $fullName = trim(
                ($user->first_name ?? '')
                . ' '
                . ($user->last_name ?? '')
            );

            if ($fullName === '') {
                $fullName = 'User';
            }

            $html = '
            <!DOCTYPE html>
            <html lang="en">
            <body style="
                font-family:Arial,sans-serif;
                background:#f4f7fb;
            ">

                <div style="
                    max-width:600px;
                    margin:40px auto;
                    padding:40px;
                    background:#fff;
                    text-align:center;
                    border-radius:16px;
                ">

                    <h1>
                        VibeLocate AI
                    </h1>

                    <h2>
                        Confirm Password Change
                    </h2>

                    <p>
                        Hello ' . e($fullName) . '
                    </p>

                    <div style="
                        font-size:36px;
                        font-weight:bold;
                        letter-spacing:8px;
                        padding:24px;
                    ">
                        ' . e($otp) . '
                    </div>

                    <p>
                        This code expires in 10 minutes.
                    </p>

                </div>
            </body>
            </html>
            ';

            $response = Http::timeout(20)
                ->withHeaders([
                    'api-key' =>
                        $apiKey,

                    'accept' =>
                        'application/json',

                    'content-type' =>
                        'application/json',
                ])
                ->post(
                    'https://api.brevo.com/v3/smtp/email',
                    [
                        'sender' => [
                            'name' =>
                                $senderName,

                            'email' =>
                                $senderEmail,
                        ],

                        'to' => [
                            [
                                'email' =>
                                    $user->email,

                                'name' =>
                                    $fullName,
                            ],
                        ],

                        'subject' =>
                            'VibeLocate AI Password Change Code',

                        'htmlContent' =>
                            $html,
                    ]
                );

            if (!$response->successful()) {
                DB::table(
                    'password_change_otps'
                )
                    ->where(
                        'user_id',
                        $user->id
                    )
                    ->delete();

                throw new \RuntimeException(
                    'Could not send verification email'
                );
            }

            return response()->json([
                'success' => true,

                'message' =>
                    'Verification code sent to your email.',

                'verification_required' =>
                    true,

                'otp_expires_in' =>
                    600,
            ]);

        } catch (Throwable $e) {
            DB::table('password_change_otps')
                ->where(
                    'user_id',
                    $user->id
                )
                ->delete();

            report($e);

            return response()->json([
                'success' => false,

                'message' =>
                    'Could not send verification code',

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