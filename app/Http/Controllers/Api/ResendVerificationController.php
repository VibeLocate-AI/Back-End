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
            return $this->error(
                'Invalid email address',
                422
            );
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
                    'status',
                    'email_verified_at',
                ])
                ->first();

            if (!$user) {
                return $this->error(
                    'Account not found',
                    404
                );
            }

            if (!empty($user->email_verified_at)) {
                return $this->error(
                    'Email is already verified',
                    409
                );
            }

            if (
                in_array(
                    $user->status,
                    ['suspended', 'inactive'],
                    true
                )
            ) {
                return $this->error(
                    'Account is not available',
                    403
                );
            }

            $otp = (string) random_int(
                100000,
                999999
            );

            $otpHash = hash(
                'sha256',
                $otp
            );

            DB::table('email_verifications')
                ->where(
                    'user_id',
                    $user->id
                )
                ->delete();

            DB::table('email_verifications')
                ->insert([
                    'user_id' =>
                        $user->id,

                    'token' =>
                        $otpHash,

                    'expires_at' =>
                        now()->addMinutes(10),
                ]);

            $fullName = trim(
                ($user->first_name ?? '')
                . ' '
                . ($user->last_name ?? '')
            );

            if ($fullName === '') {
                $fullName = 'User';
            }

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
                DB::table('email_verifications')
                    ->where(
                        'user_id',
                        $user->id
                    )
                    ->delete();

                throw new \RuntimeException(
                    'Email service configuration error'
                );
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
                        New verification code
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
                            'Your New VibeLocate Verification Code',

                        'htmlContent' =>
                            $html,
                    ]
                );

            if (!$response->successful()) {
                DB::table('email_verifications')
                    ->where(
                        'user_id',
                        $user->id
                    )
                    ->delete();

                throw new \RuntimeException(
                    'Failed to resend verification code'
                );
            }

            return response()->json([
                'success' => true,

                'message' =>
                    'A new verification code has been sent to your email.',

                'expires_in' =>
                    600,
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,

                'message' =>
                    'Failed to resend verification code',

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