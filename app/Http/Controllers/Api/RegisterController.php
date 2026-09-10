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
        $firstName = trim((string) $request->input('first_name', ''));
        $lastName  = trim((string) $request->input('last_name', ''));
        $email     = strtolower(trim((string) $request->input('email', '')));
        $phone     = trim((string) $request->input('phone', ''));
        $city      = trim((string) $request->input('city', ''));
        $country   = trim((string) $request->input('country', ''));
        $password  = (string) $request->input('password', '');
        $roleSlug  = (string) $request->input('role_slug', 'tenant');

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

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
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

        if (!in_array($roleSlug, ['tenant', 'owner'], true)) {
            return $this->error(
                'Invalid registration role',
                422
            );
        }

        try {

            /*
            |--------------------------------------------------------------------------
            | Duplicate Email
            |--------------------------------------------------------------------------
            */

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

            /*
            |--------------------------------------------------------------------------
            | Duplicate Phone
            |--------------------------------------------------------------------------
            */

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
            | Create User
            |--------------------------------------------------------------------------
            */

            $userId = DB::table('users')->insertGetId([
                'first_name'    => $firstName,
                'last_name'     => $lastName,
                'email'         => $email,
                'phone'         => $phone,
                'password_hash' => Hash::make($password),
                'status'        => 'pending',
            ]);

            /*
            |--------------------------------------------------------------------------
            | Role
            |--------------------------------------------------------------------------
            */

            $role = DB::table('roles')
                ->where('slug', $roleSlug)
                ->first();

            if (!$role) {
                throw new \RuntimeException(
                    'Role not found'
                );
            }

            DB::table('user_roles')->insert([
                'user_id' => $userId,
                'role_id' => $role->id,
            ]);

            /*
            |--------------------------------------------------------------------------
            | User Profile
            |--------------------------------------------------------------------------
            */

            DB::table('user_profiles')->insert([
                'user_id'            => $userId,
                'preferred_language' => 'en',
                'currency'           => 'AED',
                'city'               => $city,
                'country'            => $country,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Generate Verification OTP
            |--------------------------------------------------------------------------
            */

            $otp = (string) random_int(
                100000,
                999999
            );

            DB::table('email_verifications')
                ->where('user_id', $userId)
                ->delete();

            DB::table('email_verifications')->insert([
                'user_id'    => $userId,
                'token'      => $otp,
                'expires_at' => now()->addMinutes(10),
            ]);

            /*
            |--------------------------------------------------------------------------
            | Referral Code
            |--------------------------------------------------------------------------
            */

            $referralCode = strtoupper(
                'VIBE-' .
                $userId .
                '-' .
                bin2hex(random_bytes(3))
            );

            DB::table('referral_codes')->insert([
                'user_id' => $userId,
                'code'    => $referralCode,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Verification Email
            |--------------------------------------------------------------------------
            */

            $fullName = trim(
                $firstName . ' ' . $lastName
            );

            $htmlContent = '
            <!DOCTYPE html>
            <html lang="en">

            <head>
                <meta charset="UTF-8">
                <meta name="viewport"
                      content="width=device-width, initial-scale=1.0">

                <title>
                    VibeLocate AI Verification
                </title>
            </head>

            <body style="
                margin:0;
                padding:0;
                background-color:#f4f7fb;
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
                            margin:0 0 20px 0;
                            color:#17365f;
                            font-size:24px;
                        ">
                            Verify your email
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
                            to complete your VibeLocate AI registration.
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
                            line-height:1.6;
                        ">
                            This verification code expires in 10 minutes.
                        </p>

                        <p style="
                            color:#777777;
                            font-size:14px;
                            line-height:1.6;
                        ">
                            If you did not create this account,
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
                    'Email service configuration error'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Send Verification Email
            |--------------------------------------------------------------------------
            */

            $brevoResponse = Http::timeout(30)
                ->withHeaders([
                    'api-key'      => $apiKey,
                    'accept'       => 'application/json',
                    'content-type' => 'application/json',
                ])
                ->post(
                    'https://api.brevo.com/v3/smtp/email',
                    [
                        'sender' => [
                            'name'  => $senderName,
                            'email' => $senderEmail,
                        ],

                        'to' => [
                            [
                                'email' => $email,
                                'name'  => $fullName,
                            ],
                        ],

                        'subject' =>
                            'Your VibeLocate AI Verification Code',

                        'htmlContent' =>
                            $htmlContent,
                    ]
                );

            if (!$brevoResponse->successful()) {
                throw new \RuntimeException(
                    'Email delivery failed'
                );
            }

            DB::commit();

            /*
            |--------------------------------------------------------------------------
            | Response
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'success' => true,

                'message' =>
                    'Registration successful. Verification code sent to your email.',

                'user_id' => $userId,

                'email' => $email,

                'city' => $city,

                'country' => $country,

                'verification_required' => true,

                'otp_expires_in' => 600,

            ], 201, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        } catch (Throwable $e) {

            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Registration failed',
            ], 500, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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