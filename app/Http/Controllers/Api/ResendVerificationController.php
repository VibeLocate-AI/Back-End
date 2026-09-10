<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class ResendVerificationController extends Controller
{
    public function __invoke(Request $request)
    {
        $email = strtolower(trim((string) $request->input('email', '')));

        /*
        |--------------------------------------------------------------------------
        | Validate Email
        |--------------------------------------------------------------------------
        */

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid email address',
            ], 422);
        }

        try {

            /*
            |--------------------------------------------------------------------------
            | Find User
            |--------------------------------------------------------------------------
            */

            $user = DB::table('users')
                ->where('email', $email)
                ->whereNull('deleted_at')
                ->select(
                    'id',
                    'first_name',
                    'last_name',
                    'email',
                    'status',
                    'email_verified_at'
                )
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Account not found',
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | Already Verified
            |--------------------------------------------------------------------------
            */

            if (!empty($user->email_verified_at)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email is already verified',
                ], 409);
            }

            /*
            |--------------------------------------------------------------------------
            | Generate New 6-Digit OTP
            |--------------------------------------------------------------------------
            */

            $otp = (string) random_int(100000, 999999);

            /*
            |--------------------------------------------------------------------------
            | Remove Old Verification Code
            |--------------------------------------------------------------------------
            */

            DB::table('email_verifications')
                ->where('user_id', $user->id)
                ->delete();

            /*
            |--------------------------------------------------------------------------
            | Save New OTP
            |--------------------------------------------------------------------------
            */

            DB::table('email_verifications')->insert([
                'user_id' => $user->id,
                'token' => $otp,
                'expires_at' => now()->addMinutes(10),
            ]);

            /*
            |--------------------------------------------------------------------------
            | User Name
            |--------------------------------------------------------------------------
            */

            $fullName = trim(
                ($user->first_name ?? '') . ' ' .
                ($user->last_name ?? '')
            );

            if ($fullName === '') {
                $fullName = 'User';
            }

            /*
            |--------------------------------------------------------------------------
            | Brevo Configuration
            |--------------------------------------------------------------------------
            */

            $apiKey = env('BREVO_API_KEY');
            $senderEmail = env('BREVO_SENDER_EMAIL');
            $senderName = env('BREVO_SENDER_NAME', 'VibeLocate AI');

            if (!$apiKey) {
                throw new \RuntimeException(
                    'BREVO_API_KEY is not configured'
                );
            }

            if (!$senderEmail) {
                throw new \RuntimeException(
                    'BREVO_SENDER_EMAIL is not configured'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Email HTML
            |--------------------------------------------------------------------------
            */

            $html = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
            </head>

            <body style="
                margin:0;
                padding:0;
                background:#f4f7fb;
                font-family:Arial,sans-serif;
            ">

                <div style="
                    max-width:600px;
                    margin:40px auto;
                    background:#ffffff;
                    border-radius:12px;
                    overflow:hidden;
                ">

                    <div style="
                        background:#1e416d;
                        padding:30px;
                        text-align:center;
                    ">
                        <h1 style="
                            color:#ffffff;
                            margin:0;
                        ">
                            VibeLocate AI
                        </h1>
                    </div>

                    <div style="
                        padding:40px;
                        text-align:center;
                    ">

                        <h2 style="
                            color:#0c376d;
                            margin-bottom:30px;
                        ">
                            Verify your email
                        </h2>

                        <p style="
                            font-size:18px;
                            color:#333333;
                        ">
                            Hello ' . e($fullName) . ',
                        </p>

                        <p style="
                            font-size:16px;
                            color:#555555;
                            line-height:1.6;
                        ">
                            You requested a new verification code.
                            Use the code below to complete your
                            VibeLocate AI registration.
                        </p>

                        <div style="
                            margin:35px auto;
                            background:#edf3ff;
                            padding:20px 30px;
                            border-radius:12px;
                            display:inline-block;
                        ">

                            <span style="
                                font-size:38px;
                                font-weight:bold;
                                letter-spacing:10px;
                                color:#0c376d;
                            ">
                                ' . $otp . '
                            </span>

                        </div>

                        <p style="
                            margin-top:30px;
                            color:#666666;
                            font-size:15px;
                        ">
                            This verification code expires in
                            <strong>10 minutes</strong>.
                        </p>

                        <p style="
                            color:#777777;
                            font-size:14px;
                            margin-top:25px;
                        ">
                            If you did not request this code,
                            you can safely ignore this email.
                        </p>

                    </div>

                </div>

            </body>
            </html>
            ';

            /*
            |--------------------------------------------------------------------------
            | Send OTP Through Brevo API
            |--------------------------------------------------------------------------
            */

            $brevoResponse = Http::timeout(15)
                ->withHeaders([
                    'api-key' => $apiKey,
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                ])
                ->post(
                    'https://api.brevo.com/v3/smtp/email',
                    [
                        'sender' => [
                            'name' => $senderName,
                            'email' => $senderEmail,
                        ],

                        'to' => [
                            [
                                'email' => $user->email,
                                'name' => $fullName,
                            ],
                        ],

                        'subject' => 'Your New VibeLocate Verification Code',

                        'htmlContent' => $html,
                    ]
                );

            /*
            |--------------------------------------------------------------------------
            | Brevo Error
            |--------------------------------------------------------------------------
            */

            if (!$brevoResponse->successful()) {

                // Remove OTP because email was not delivered
                DB::table('email_verifications')
                    ->where('user_id', $user->id)
                    ->where('token', $otp)
                    ->delete();

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to resend verification code',
                    'error' => app()->environment('production')
                        ? null
                        : $brevoResponse->body(),
                ], 500);
            }

            /*
            |--------------------------------------------------------------------------
            | Success
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'success' => true,
                'message' => 'A new verification code has been sent to your email.',
                'expires_in' => 600,
            ], 200);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to resend verification code',

                'error' => app()->environment('production')
                    ? null
                    : $e->getMessage(),

            ], 500);
        }
    }
}