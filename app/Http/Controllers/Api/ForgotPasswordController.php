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
        $email = strtolower(
            trim(
                (string) $request->input(
                    'email',
                    ''
                )
            )
        );

        if (!filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Invalid email address',
            ], 422);
        }

        try {
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
                    'first_name',
                    'last_name',
                    'email',
                ])
                ->first();

            /*
             * Do not reveal whether email exists.
             */
            if (!$user) {
                return response()->json([
                    'success' => true,

                    'message' =>
                        'If the email exists, a verification code has been sent.',
                ]);
            }

            $otp = (string) random_int(
                100000,
                999999
            );

            $otpHash = hash(
                'sha256',
                $otp
            );

            DB::table('password_resets')
                ->where(
                    'email',
                    $email
                )
                ->delete();

            DB::table('password_resets')
                ->insert([
                    'email' =>
                        $email,

                    'token' =>
                        $otpHash,

                    'expires_at' =>
                        now()->addMinutes(10),
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
                DB::table('password_resets')
                    ->where(
                        'email',
                        $email
                    )
                    ->delete();

                throw new \RuntimeException(
                    'Email service configuration error'
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
                background:#f4f7fb;
                font-family:Arial,sans-serif;
            ">

                <div style="
                    max-width:600px;
                    margin:40px auto;
                    background:#fff;
                    padding:40px;
                    text-align:center;
                    border-radius:16px;
                ">

                    <h1 style="color:#17365f;">
                        VibeLocate AI
                    </h1>

                    <h2>
                        Reset your password
                    </h2>

                    <p>
                        Hello ' . e($fullName) . '
                    </p>

                    <p>
                        Use the verification code below:
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
                            'VibeLocate AI Password Reset Code',

                        'htmlContent' =>
                            $html,
                    ]
                );

            if (!$response->successful()) {
                DB::table('password_resets')
                    ->where(
                        'email',
                        $email
                    )
                    ->delete();

                throw new \RuntimeException(
                    'Brevo email delivery failed'
                );
            }

            return response()->json([
                'success' => true,

                'message' =>
                    'Verification code sent to your email.',

                'verification_required' =>
                    true,

                'email' =>
                    $email,

                'otp_expires_in' =>
                    600,
            ]);

        } catch (Throwable $e) {
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
}