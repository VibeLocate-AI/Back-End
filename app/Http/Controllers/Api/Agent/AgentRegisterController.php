<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Api\ResendVerificationController;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AgentRegisterController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        $validator = validator(
            $request->all(),
            [
                'first_name' =>
                    'required|string|max:50',

                'last_name' =>
                    'required|string|max:50',

                'email' =>
                    'required|email|max:150',

                'phone' =>
                    'nullable|string|max:30',

                'password' =>
                    'required|string|min:8|confirmed',

                'agency_name' =>
                    'required|string|max:150',

                'license_number' =>
                    'required|string|max:100',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $firstName = trim(
            (string) $request->input('first_name')
        );

        $lastName = trim(
            (string) $request->input('last_name')
        );

        $email = strtolower(
            trim(
                (string) $request->input('email')
            )
        );

        $phone = $request->filled('phone')
            ? trim(
                (string) $request->input('phone')
            )
            : null;

        $password = (string)
            $request->input('password');

        $agencyName = trim(
            (string) $request->input('agency_name')
        );

        $licenseNumber = trim(
            (string) $request->input(
                'license_number'
            )
        );

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
            return response()->json([
                'success' => false,
                'message' => 'Email already exists',
            ], 409);
        }

        /*
        |--------------------------------------------------------------------------
        | Duplicate Phone
        |--------------------------------------------------------------------------
        */

        if (
            $phone !== null &&
            DB::table('users')
                ->where('phone', $phone)
                ->exists()
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Phone already exists',
            ], 409);
        }

        /*
        |--------------------------------------------------------------------------
        | Duplicate License
        |--------------------------------------------------------------------------
        */

        if (
            DB::table('agencies')
                ->where(
                    'license_number',
                    $licenseNumber
                )
                ->exists()
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'License number already exists',
            ], 409);
        }

        DB::beginTransaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | Agent Role
            |--------------------------------------------------------------------------
            */

            $agentRoleId = DB::table('roles')
                ->where('slug', 'agent')
                ->value('id');

            if (!$agentRoleId) {
                throw new RuntimeException(
                    'Agent role not found'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Create Pending User
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            | Agent cannot login until email OTP verification succeeds.
            |
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

                    'email_verified_at' =>
                        null,

                    'created_at' =>
                        now(),

                    'updated_at' =>
                        now(),
                ]);

            /*
            |--------------------------------------------------------------------------
            | Assign Agent Role
            |--------------------------------------------------------------------------
            */

            DB::table('user_roles')
                ->insert([
                    'user_id' =>
                        $userId,

                    'role_id' =>
                        $agentRoleId,

                    'assigned_at' =>
                        now(),
                ]);

            /*
            |--------------------------------------------------------------------------
            | Generate Unique Agency Slug
            |--------------------------------------------------------------------------
            */

            $baseSlug = strtolower(
                preg_replace(
                    '/[^A-Za-z0-9]+/',
                    '-',
                    $agencyName
                )
            );

            $baseSlug = trim(
                $baseSlug,
                '-'
            );

            if ($baseSlug === '') {
                $baseSlug =
                    'agency-' . $userId;
            }

            $slug = $baseSlug;
            $counter = 1;

            while (
                DB::table('agencies')
                    ->where('slug', $slug)
                    ->exists()
            ) {
                $slug =
                    $baseSlug .
                    '-' .
                    $counter;

                $counter++;
            }

            /*
            |--------------------------------------------------------------------------
            | Create Pending Agency
            |--------------------------------------------------------------------------
            |
            | Email verification activates the USER only.
            | Agency stays pending until Admin approval.
            |
            */

            $agencyId = DB::table('agencies')
                ->insertGetId([
                    'owner_user_id' =>
                        $userId,

                    'name' =>
                        $agencyName,

                    'slug' =>
                        $slug,

                    'license_number' =>
                        $licenseNumber,

                    'phone' =>
                        $phone,

                    'email' =>
                        $email,

                    'status' =>
                        'pending',

                    'created_at' =>
                        now(),

                    'updated_at' =>
                        now(),
                ]);

            /*
            |--------------------------------------------------------------------------
            | Link Agent To Agency
            |--------------------------------------------------------------------------
            */

            DB::table('agency_agents')
                ->insert([
                    'agency_id' =>
                        $agencyId,

                    'user_id' =>
                        $userId,

                    'is_manager' =>
                        1,

                    'joined_at' =>
                        now(),
                ]);

            /*
            |--------------------------------------------------------------------------
            | Send Verification OTP
            |--------------------------------------------------------------------------
            |
            | Reuse the existing authentication OTP controller.
            | This keeps Agent registration compatible with /api/verify-otp
            | and avoids maintaining two different OTP implementations.
            |
            */

            $otpRequest = Request::create(
                '/api/resend-otp',
                'POST',
                [
                    'email' => $email,
                ]
            );

            $otpResponse = app(
                ResendVerificationController::class
            )($otpRequest);

            $otpStatus =
                $otpResponse->getStatusCode();

            $otpPayload =
                $otpResponse->getData(true);

            if (
                $otpStatus < 200 ||
                $otpStatus >= 300 ||
                empty($otpPayload['success'])
            ) {
                throw new RuntimeException(
                    $otpPayload['message']
                    ?? 'Failed to send verification code'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Commit Registration
            |--------------------------------------------------------------------------
            */

            DB::commit();

            /*
            |--------------------------------------------------------------------------
            | Response
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'success' => true,

                'message' =>
                    'Agent account created. Please verify your email.',

                'agent' => [
                    'user_id' =>
                        $userId,

                    'agency_id' =>
                        $agencyId,

                    'user_status' =>
                        'pending',

                    'agency_status' =>
                        'pending',

                    'email_verified' =>
                        false,

                    'next_step' =>
                        'verify_email',

                    'after_verification' =>
                        'license',
                ],

                'verification' => [
                    'required' => true,
                    'expires_in' => 600,
                ],

            ], 201);

        } catch (Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | Rollback Everything
            |--------------------------------------------------------------------------
            */

            if (
                DB::transactionLevel() > 0
            ) {
                DB::rollBack();
            }

            Log::error(
                'Agent registration failed',
                [
                    'email' => $email,
                    'error' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),
                ]
            );

            report($e);

            return response()->json([
                'success' => false,

                'message' =>
                    'Agent registration failed',

                'details' =>
                    app()->environment('local')
                        ? $e->getMessage()
                        : null,

            ], 500);
        }
    }
}