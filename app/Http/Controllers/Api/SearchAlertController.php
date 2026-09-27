<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class SearchAlertController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | List Search Alerts
    |--------------------------------------------------------------------------
    */

    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('auth_user');

        if (!$user || empty($user['id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        try {
            $alerts = DB::table('saved_searches')
                ->where('user_id', (int) $user['id'])
                ->orderByDesc('id')
                ->get()
                ->map(function ($alert) {
                    $alert->query_parameters = $this->decodeJson(
                        $alert->query_parameters
                    );

                    $alert->is_active = (bool) $alert->is_active;

                    return $alert;
                });

            return response()->json([
                'success' => true,
                'data' => $alerts,
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load search alerts',
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Create Search Alert
    |--------------------------------------------------------------------------
    */

    public function store(Request $request): JsonResponse
    {
        $user = $request->attributes->get('auth_user');

        if (!$user || empty($user['id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $validator = validator($request->all(), [
            'search_name' => [
                'required',
                'string',
                'max:100',
            ],

            'query_parameters' => [
                'required',
                'array',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid search alert data',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $id = DB::table('saved_searches')
                ->insertGetId([
                    'user_id' => (int) $user['id'],

                    'search_name' => trim(
                        (string) $request->input('search_name')
                    ),

                    'query_parameters' => json_encode(
                        $request->input('query_parameters'),
                        JSON_UNESCAPED_UNICODE
                    ),

                    'is_active' => $request->exists('is_active')
                        ? ($request->boolean('is_active') ? 1 : 0)
                        : 1,

                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            $alert = DB::table('saved_searches')
                ->where('id', $id)
                ->where('user_id', (int) $user['id'])
                ->first();

            if ($alert) {
                $alert->query_parameters = $this->decodeJson(
                    $alert->query_parameters
                );

                $alert->is_active = (bool) $alert->is_active;
            }

            return response()->json([
                'success' => true,
                'message' => 'Search alert created successfully',
                'data' => $alert,
            ], 201);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create search alert',
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Update Search Alert
    |--------------------------------------------------------------------------
    */

    public function update(
        Request $request,
        int $id
    ): JsonResponse {
        $user = $request->attributes->get('auth_user');

        if (!$user || empty($user['id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $alert = DB::table('saved_searches')
            ->where('id', $id)
            ->where('user_id', (int) $user['id'])
            ->first();

        if (!$alert) {
            return response()->json([
                'success' => false,
                'message' => 'Search alert not found',
            ], 404);
        }

        $validator = validator($request->all(), [
            'search_name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
            ],

            'query_parameters' => [
                'sometimes',
                'required',
                'array',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid search alert data',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = [];

            if ($request->exists('search_name')) {
                $data['search_name'] = trim(
                    (string) $request->input('search_name')
                );
            }

            if ($request->exists('query_parameters')) {
                $data['query_parameters'] = json_encode(
                    $request->input('query_parameters'),
                    JSON_UNESCAPED_UNICODE
                );
            }

            if ($request->exists('is_active')) {
                $data['is_active'] = $request->boolean('is_active')
                    ? 1
                    : 0;
            }

            if (empty($data)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No data provided for update',
                ], 422);
            }

            $data['updated_at'] = now();

            DB::table('saved_searches')
                ->where('id', $id)
                ->where('user_id', (int) $user['id'])
                ->update($data);

            $updatedAlert = DB::table('saved_searches')
                ->where('id', $id)
                ->where('user_id', (int) $user['id'])
                ->first();

            $updatedAlert->query_parameters = $this->decodeJson(
                $updatedAlert->query_parameters
            );

            $updatedAlert->is_active = (bool) $updatedAlert->is_active;

            return response()->json([
                'success' => true,
                'message' => 'Search alert updated successfully',
                'data' => $updatedAlert,
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update search alert',
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Delete Search Alert
    |--------------------------------------------------------------------------
    */

    public function destroy(
        Request $request,
        int $id
    ): JsonResponse {
        $user = $request->attributes->get('auth_user');

        if (!$user || empty($user['id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $alert = DB::table('saved_searches')
            ->where('id', $id)
            ->where('user_id', (int) $user['id'])
            ->first();

        if (!$alert) {
            return response()->json([
                'success' => false,
                'message' => 'Search alert not found',
            ], 404);
        }

        try {
            DB::table('saved_searches')
                ->where('id', $id)
                ->where('user_id', (int) $user['id'])
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Search alert deleted successfully',
            ]);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete search alert',
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Decode JSON
    |--------------------------------------------------------------------------
    */

    private function decodeJson(
        mixed $value
    ): array {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode(
            (string) $value,
            true
        );

        return is_array($decoded)
            ? $decoded
            : [];
    }
}