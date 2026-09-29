<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

class AgentRegisterController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = validator($request->all(), [
                'first_name' => 'required|string|max:50',
                'last_name' => 'required|string|max:50',
                'email' => 'required|email|max:150',
                'phone' => 'nullable|string|max:30',
                'password' => 'required|string|min:8|confirmed',

                'agency_name' => 'required|string|max:150',
                'license_number' => 'required|string|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $email = strtolower(trim($request->email));
            $phone = $request->filled('phone')
                ? trim($request->phone)
                : null;

            /*
            |--------------------------------------------------------------------------
            | Duplicate User
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
                        trim($request->license_number)
                    )
                    ->exists()
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'License number already exists',
                ], 409);
            }

            $result = DB::transaction(
                function () use (
                    $request,
                    $email,
                    $phone
                ) {
                    /*
                    |--------------------------------------------------------------------------
                    | Agent Role
                    |--------------------------------------------------------------------------
                    */

                    $agentRoleId = DB::table('roles')
                        ->where('slug', 'agent')
                        ->value('id');

                    if (!$agentRoleId) {
                        throw new \RuntimeException(
                            'Agent role not found'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Create User
                    |--------------------------------------------------------------------------
                    */

                    $userId = DB::table('users')
                        ->insertGetId([
                            'first_name' =>
                                trim($request->first_name),

                            'last_name' =>
                                trim($request->last_name),

                            'email' =>
                                $email,

                            'phone' =>
                                $phone,

                            'password_hash' =>
                                Hash::make(
                                    $request->password
                                ),

                            'status' =>
                                'pending',

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
                    | Create Agency / License
                    |--------------------------------------------------------------------------
                    */

                    $agencyName =
                        trim(
                            $request->agency_name
                        );

                    $baseSlug =
                        strtolower(
                            preg_replace(
                                '/[^A-Za-z0-9]+/',
                                '-',
                                $agencyName
                            )
                        );

                    $baseSlug =
                        trim(
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
                            $baseSlug
                            . '-'
                            . $counter;

                        $counter++;
                    }

                    $agencyId = DB::table('agencies')
                        ->insertGetId([
                            'owner_user_id' =>
                                $userId,

                            'name' =>
                                $agencyName,

                            'slug' =>
                                $slug,

                            'license_number' =>
                                trim(
                                    $request->license_number
                                ),

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

                    return [
                        'user_id' =>
                            $userId,

                        'agency_id' =>
                            $agencyId,
                    ];
                }
            );

            return response()->json([
                'success' => true,

                'message' =>
                    'Agent account created successfully',

                'agent' => [
                    'user_id' =>
                        $result['user_id'],

                    'agency_id' =>
                        $result['agency_id'],

                    'status' =>
                        'pending',

                    'next_step' =>
                        'license',
                ],
            ], 201);

        } catch (Throwable $e) {
            Log::error(
                'Agent registration failed',
                [
                    'error' =>
                        $e->getMessage(),
                ]
            );

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Agent registration failed',
            ], 500);
        }
    }
}