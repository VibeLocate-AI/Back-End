<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Throwable;

class RegisterController extends Controller
{
    public function __invoke(Request $request)
    {
        $firstName = trim(
            (string) $request->input('first_name', '')
        );

        $lastName = trim(
            (string) $request->input('last_name', '')
        );

        $email = strtolower(
            trim(
                (string) $request->input('email', '')
            )
        );

        $phone = trim(
            (string) $request->input('phone', '')
        );

        $city = trim(
            (string) $request->input('city', '')
        );

        $country = trim(
            (string) $request->input('country', '')
        );

        $password = (string) $request->input(
            'password',
            ''
        );

        $passwordConfirmation =
            (string) $request->input(
                'password_confirmation',
                ''
            );

        $roleSlug = trim(
            (string) $request->input(
                'role_slug',
                'tenant'
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        if ($firstName === '' || $lastName === '') {
            return $this->error(
                'First name and last name are required',
                422
            );
        }

        if (!filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )) {
            return $this->error(
                'Invalid email address',
                422
            );
        }

        if ($phone === '') {
            return $this->error(
                'Phone number is required',
                422
            );
        }

        if ($city === '') {
            return $this->error(
                'City is required',
                422
            );
        }

        if ($country === '') {
            return $this->error(
                'Country is required',
                422
            );
        }

        if (strlen($password) < 8) {
            return $this->error(
                'Password must be at least 8 characters',
                422
            );
        }

        if ($password !== $passwordConfirmation) {
            return $this->error(
                'Password confirmation does not match',
                422
            );
        }

        if (
            !in_array(
                $roleSlug,
                ['tenant', 'owner'],
                true
            )
        ) {
            return $this->error(
                'Invalid registration role',
                422
            );
        }

        try {
            if (
                DB::table('users')
                    ->where('email', $email)
                    ->exists()
            ) {
                return $this->error(
                    'Email already registered',
                    409
                );
            }

            if (
                DB::table('users')
                    ->where('phone', $phone)
                    ->exists()
            ) {
                return $this->error(
                    'Phone already registered',
                    409
                );
            }

            DB::beginTransaction();

            /*
            |--------------------------------------------------------------------------
            | User
            |--------------------------------------------------------------------------
            */

            $userId = DB::table('users')
                ->insertGetId([
                    'first_name' =>
                        $firstName,

                    'last_name' =>
                        $lastName,

                    'email' =>
                        $email,

                    'phone' =>
                        $phone,

                    'password_hash' =>
                        Hash::make($password),

                    'status' =>
                        'pending',
                ]);

            /*
            |--------------------------------------------------------------------------
            | Role
            |--------------------------------------------------------------------------
            */

            $role = DB::table('roles')
                ->where(
                    'slug',
                    $roleSlug
                )
                ->first();

            if (!$role) {
                throw new \RuntimeException(
                    'Role not found'
                );
            }

            DB::table('user_roles')
                ->insert([
                    'user_id' =>
                        $userId,

                    'role_id' =>
                        $role->id,
                ]);

            /*
            |--------------------------------------------------------------------------
            | Profile
            |--------------------------------------------------------------------------
            */

            DB::table('user_profiles')
                ->insert([
                    'user_id' =>
                        $userId,

                    'preferred_language' =>
                        'en',

                    'currency' =>
                        'AED',

                    'city' =>
                        $city,

                    'country' =>
                        $country,
                ]);

            /*
            |--------------------------------------------------------------------------
            | OTP
            |--------------------------------------------------------------------------
            */

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
                    $userId
                )
                ->delete();

            DB::table('email_verifications')
                ->insert([
                    'user_id' =>
                        $userId,

                    'token' =>
                        $otpHash,

                    'expires_at' =>
                        now()->addMinutes(10),
                ]);

            /*
            |--------------------------------------------------------------------------
            | Referral
            |--------------------------------------------------------------------------
            */

            $referralCode = strtoupper(
                'VIBE-'
                . $userId
                . '-'
                . bin2hex(
                    random_bytes(3)
                )
            );

            DB::table('referral_codes')
                ->insert([
                    'user_id' =>
                        $userId,

                    'code' =>
                        $referralCode,
                ]);

            /*
            |--------------------------------------------------------------------------
            | Email
            |--------------------------------------------------------------------------
            */

            $fullName = trim(
                $firstName . ' ' . $lastName
            );

            $this->sendVerificationEmail(
                $email,
                $fullName,
                $otp
            );

            DB::commit();

            return response()->json([
                'success' => true,

                'message' =>
                    'Registration successful. Verification code sent to your email.',

                'user_id' =>
                    $userId,

                'email' =>
                    $email,

                'status' =>
                    'pending',

                'verification_required' =>
                    true,

                'otp_expires_in' =>
                    600,
            ], 201);

        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            report($e);

            return response()->json([
                'success' => false,

                'message' =>
                    'Registration failed',

                'details' =>
                    app()->environment('local')
                        ? $e->getMessage()
                        : null,
            ], 500);
        }
    }

    private function sendVerificationEmail(
        string $email,
        string $name,
        string $otp
    ): void {
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
                'Email service configuration error'
            );
        }

        $html = '
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport"
                  content="width=device-width, initial-scale=1.0">
            <title>Verify Email</title>
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
            ">

                <div style="
                    background:#17365f;
                    padding:32px;
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

                    <h2>
                        Verify your email
                    </h2>

                    <p>
                        Hello ' . e($name) . ',
                    </p>

                    <p>
                        Use this verification code:
                    </p>

                    <div style="
                        font-size:36px;
                        font-weight:bold;
                        letter-spacing:8px;
                        padding:20px;
                    ">
                        ' . e($otp) . '
                    </div>

                    <p>
                        This code expires in 10 minutes.
                    </p>

                </div>
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
                                $email,

                            'name' =>
                                $name,
                        ],
                    ],

                    'subject' =>
                        'Your VibeLocate AI Verification Code',

                    'htmlContent' =>
                        $html,
                ]
            );

        if (!$response->successful()) {
            throw new \RuntimeException(
                'Verification email delivery failed'
            );
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