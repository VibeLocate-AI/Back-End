<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class ForgotPasswordController extends Controller
{
    public function __invoke(Request $request)
    {
        $email = strtolower(trim((string) $request->input('email', '')));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid email address',
            ], 422);
        }

        try {

            $user = DB::table('users')
                ->where('email', $email)
                ->whereNull('deleted_at')
                ->select(
                    'id',
                    'first_name',
                    'last_name',
                    'email'
                )
                ->first();

            // لا نكشف إذا كان الإيميل موجود أو لا
            if (!$user) {
                return response()->json([
                    'success' => true,
                    'message' => 'If the email exists, a verification code has been sent.',
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Generate OTP
            |--------------------------------------------------------------------------
            */

            $otp = (string) random_int(100000, 999999);

            DB::table('password_resets')
                ->where('email', $email)
                ->delete();

            DB::table('password_resets')->insert([
                'email' => $email,
                'token' => $otp,
                'expires_at' => now()->addMinutes(10),
            ]);

            /*
            |--------------------------------------------------------------------------
            | Brevo Settings
            |--------------------------------------------------------------------------
            */

            $apiKey = env('BREVO_API_KEY');
            $senderEmail = env('BREVO_SENDER_EMAIL');
            $senderName = env('BREVO_SENDER_NAME', 'VibeLocate AI');

            if (!$apiKey || !$senderEmail) {

                DB::table('password_resets')
                    ->where('email', $email)
                    ->delete();

                throw new \RuntimeException(
                    'Email service configuration error'
                );
            }

            $fullName = trim(
                ($user->first_name ?? '') . ' ' .
                ($user->last_name ?? '')
            );

            if ($fullName === '') {
                $fullName = 'User';
            }

            /*
            |--------------------------------------------------------------------------
            | Email HTML
            |--------------------------------------------------------------------------
            */

            $html = '
            <!DOCTYPE html>
            <html lang="en">

            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Password Reset</title>
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
                            margin:0 0 20px;
                            color:#17365f;
                            font-size:24px;
                        ">
                            Reset your password
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
                            Use the verification code below
                            to reset your VibeLocate AI password.
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
                            This verification code expires in 10 minutes.
                        </p>

                        <p style="
                            color:#777777;
                            font-size:14px;
                            line-height:1.6;
                        ">
                            If you did not request a password reset,
                            you can safely ignore this email.
                        </p>

                    </div>

                    <div style="
                        background:#f7f8fa;
                        padding:20px;
                        text-align:center;
                        color:#999999;
                        font-size:12px;
                    ">
                        © ' . date('Y') . ' VibeLocate AI
                    </div>

                </div>

            </body>
            </html>
            ';

            /*
            |--------------------------------------------------------------------------
            | Send Through Brevo API
            |--------------------------------------------------------------------------
            */

            $brevoResponse = Http::timeout(20)
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

                        'subject' =>
                            'VibeLocate AI Password Reset Code',

                        'htmlContent' => $html,
                    ]
                );

            if (!$brevoResponse->successful()) {

                DB::table('password_resets')
                    ->where('email', $email)
                    ->delete();

                throw new \RuntimeException(
                    'Brevo email delivery failed'
                );
            }

            return response()->json([
                'success' => true,
                'message' =>
                    'Verification code sent to your email.',
                'verification_required' => true,
                'email' => $email,
                'otp_expires_in' => 600,
            ], 200);

        } catch (Throwable $e) {

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Could not send verification code',
            ], 500);
        }
    }
}