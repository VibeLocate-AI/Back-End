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
        $user = $request->attributes->get('auth_user');

        if (!$user || empty($user['id'])) {
            return $this->error(
                'Unauthenticated',
                401
            );
        }

        $userId = (int) $user['id'];

        $currentPassword = (string) $request->input(
            'current_password',
            ''
        );

        $newPassword = (string) $request->input(
            'new_password',
            ''
        );

        $otp = trim((string) (
            $request->input('otp')
            ?? $request->input('code')
            ?? ''
        ));

        /*
        |--------------------------------------------------------------------------
        | Basic Validation
        |--------------------------------------------------------------------------
        */

        if ($currentPassword === '') {
            return $this->error(
                'Current password is required',
                422
            );
        }

        if ($newPassword === '') {
            return $this->error(
                'New password is required',
                422
            );
        }

        if (strlen($newPassword) < 8) {
            return $this->error(
                'Password must be at least 8 characters',
                422
            );
        }

        if ($currentPassword === $newPassword) {
            return $this->error(
                'New password must be different from current password',
                422
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Get Current User
        |--------------------------------------------------------------------------
        */

        $currentUser = DB::table('users')
            ->where('id', $userId)
            ->whereNull('deleted_at')
            ->select(
                'id',
                'first_name',
                'last_name',
                'email',
                'password_hash'
            )
            ->first();

        if (!$currentUser) {
            return $this->error(
                'User not found',
                404
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Verify Current Password
        |--------------------------------------------------------------------------
        */

        if (
            !Hash::check(
                $currentPassword,
                $currentUser->password_hash
            )
        ) {
            return $this->error(
                'Current password is incorrect',
                401
            );
        }

        /*
        |--------------------------------------------------------------------------
        | STEP 1
        | No OTP supplied -> Send OTP
        |--------------------------------------------------------------------------
        */

        if ($otp === '') {
            return $this->sendOtp(
                $currentUser
            );
        }

        /*
        |--------------------------------------------------------------------------
        | STEP 2
        | OTP supplied -> Verify and change password
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

        $otpRecord = DB::table('password_change_otps')
            ->where('user_id', $userId)
            ->where('expires_at', '>', now())
            ->first();

        if (!$otpRecord) {
            return $this->error(
                'Verification code is invalid or expired',
                400
            );
        }

        $otpHash = hash('sha256', $otp);

        if (
            !hash_equals(
                $otpRecord->otp_hash,
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

            /*
            |--------------------------------------------------------------------------
            | Change Password
            |--------------------------------------------------------------------------
            */

            DB::table('users')
                ->where('id', $userId)
                ->update([
                    'password_hash' =>
                        Hash::make($newPassword),
                ]);

            /*
            |--------------------------------------------------------------------------
            | Remove Used OTP
            |--------------------------------------------------------------------------
            */

            DB::table('password_change_otps')
                ->where('user_id', $userId)
                ->delete();

            /*
            |--------------------------------------------------------------------------
            | Revoke Existing Refresh Tokens
            |--------------------------------------------------------------------------
            */

            DB::table('refresh_tokens')
                ->where('user_id', $userId)
                ->update([
                    'is_revoked' => 1,
                ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' =>
                    'Password changed successfully. Please log in again.',
            ], 200);

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

    /*
    |--------------------------------------------------------------------------
    | Send Password Change OTP
    |--------------------------------------------------------------------------
    */

    private function sendOtp(object $user)
    {
        try {

            $otp = (string) random_int(
                100000,
                999999
            );

            $otpHash = hash(
                'sha256',
                $otp
            );

            /*
            |--------------------------------------------------------------------------
            | Replace Old OTP
            |--------------------------------------------------------------------------
            */

            DB::table('password_change_otps')
                ->where('user_id', $user->id)
                ->delete();

            DB::table('password_change_otps')
                ->insert([
                    'user_id' => $user->id,
                    'otp_hash' => $otpHash,
                    'expires_at' =>
                        now()->addMinutes(10),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            /*
            |--------------------------------------------------------------------------
            | Brevo Configuration
            |--------------------------------------------------------------------------
            */

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
                ($user->first_name ?? '') .
                ' ' .
                ($user->last_name ?? '')
            );

            if ($fullName === '') {
                $fullName = 'User';
            }

            /*
            |--------------------------------------------------------------------------
            | Email Design
            |--------------------------------------------------------------------------
            */

            $html = '
            <!DOCTYPE html>
            <html lang="en">

            <head>
                <meta charset="UTF-8">
                <meta name="viewport"
                      content="width=device-width, initial-scale=1.0">

                <title>
                    Password Change Verification
                </title>
            </head>

            <body style="
                margin:0;
                padding:0;
                background:#f4f7fb;
                font-family:Arial,Helvetica,sans-serif;
            ">

                <div style="
                    max-width:600px;
                    margin:40px auto;
                    background:#ffffff;
                    border-radius:16px;
                    overflow:hidden;
                    box-shadow:0 6px 24px rgba(0,0,0,0.08);
                ">

                    <div style="
                        background:#17365f;
                        padding:32px;
                        text-align:center;
                    ">

                        <h1 style="
                            margin:0;
                            color:#ffffff;
                            font-size:28px;
                        ">
                            VibeLocate AI
                        </h1>

                    </div>

                    <div style="
                        padding:40px 32px;
                        text-align:center;
                    ">

                        <h2 style="
                            color:#17365f;
                            margin-bottom:20px;
                        ">
                            Confirm Password Change
                        </h2>

                        <p style="
                            color:#555555;
                            font-size:16px;
                            line-height:1.6;
                        ">
                            Hello ' . e($fullName) . ',
                        </p>

                        <p style="
                            color:#555555;
                            font-size:16px;
                            line-height:1.6;
                        ">
                            We received a request to change
                            your VibeLocate AI password.
                        </p>

                        <p style="
                            color:#555555;
                            font-size:16px;
                        ">
                            Use this verification code:
                        </p>

                        <div style="
                            display:inline-block;
                            margin:24px 0;
                            padding:18px 30px;
                            background:#eef4ff;
                            border-radius:12px;
                            color:#17365f;
                            font-size:36px;
                            font-weight:bold;
                            letter-spacing:8px;
                        ">
                            ' . e($otp) . '
                        </div>

                        <p style="
                            color:#777777;
                            font-size:14px;
                        ">
                            This code expires in
                            10 minutes.
                        </p>

                        <p style="
                            color:#777777;
                            font-size:14px;
                            line-height:1.6;
                        ">
                            If you did not request a
                            password change, you can
                            safely ignore this email.
                        </p>

                    </div>

                    <div style="
                        background:#f7f8fa;
                        padding:20px;
                        text-align:center;
                        color:#999999;
                        font-size:12px;
                    ">
                        © ' . date('Y') . '
                        VibeLocate AI
                    </div>

                </div>

            </body>
            </html>
            ';

            /*
            |--------------------------------------------------------------------------
            | Send With Brevo API
            |--------------------------------------------------------------------------
            */

            $brevoResponse = Http::timeout(20)
                ->withHeaders([
                    'api-key' => $apiKey,
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

            if (
                !$brevoResponse->successful()
            ) {

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
                'otp_expires_in' => 600,
            ], 200);

        } catch (Throwable $e) {

            report($e);

            return response()->json([
                'success' => false,
                'message' =>
                    'Could not send verification code',
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