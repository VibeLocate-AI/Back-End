<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AdminUserController extends Controller
{
    /**
     * List users for the admin dashboard.
     *
     * Supported query params:
     * - search
     * - role
     * - status
     * - per_page
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->query('per_page', 20);
            $perPage = max(1, min($perPage, 100));

            $query = DB::table('users as u')
                ->leftJoin('user_profiles as up', 'up.user_id', '=', 'u.id')
                ->whereNull('u.deleted_at')
                ->select([
                    'u.id',
                    'u.first_name',
                    'u.last_name',
                    'u.email',
                    'u.phone',
                    'u.status',
                    'u.created_at',
                    'u.updated_at',
                    'up.city',
                    'up.country',
                    'up.preferred_language',
                    'up.currency',
                ]);

            if ($request->filled('search')) {
                $search = trim((string) $request->query('search'));

                $query->where(function ($q) use ($search) {
                    $q->where('u.first_name', 'like', '%' . $search . '%')
                        ->orWhere('u.last_name', 'like', '%' . $search . '%')
                        ->orWhere('u.email', 'like', '%' . $search . '%')
                        ->orWhere('u.phone', 'like', '%' . $search . '%');
                });
            }

            if ($request->filled('status')) {
                $query->where(
                    'u.status',
                    trim((string) $request->query('status'))
                );
            }

            if ($request->filled('role')) {
                $role = trim((string) $request->query('role'));

                $query->whereExists(function ($subQuery) use ($role) {
                    $subQuery
                        ->select(DB::raw(1))
                        ->from('user_roles as ur')
                        ->join('roles as r', 'r.id', '=', 'ur.role_id')
                        ->whereColumn('ur.user_id', 'u.id')
                        ->where('r.slug', $role);
                });
            }

            $users = $query
                ->orderByDesc('u.id')
                ->paginate($perPage);

            $items = collect($users->items());
            $userIds = $items->pluck('id')->all();

            $roles = collect();

            if (!empty($userIds)) {
                $roles = DB::table('user_roles as ur')
                    ->join('roles as r', 'r.id', '=', 'ur.role_id')
                    ->whereIn('ur.user_id', $userIds)
                    ->select([
                        'ur.user_id',
                        'r.id',
                        'r.name',
                        'r.slug',
                    ])
                    ->orderBy('r.id')
                    ->get()
                    ->groupBy('user_id');
            }

            $data = $items->map(function ($user) use ($roles) {
                $user->roles = $roles
                    ->get($user->id, collect())
                    ->map(function ($role) {
                        return [
                            'id' => $role->id,
                            'name' => $role->name,
                            'slug' => $role->slug,
                        ];
                    })
                    ->values();

                return $user;
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'filters' => [
                    'search' => $request->query('search'),
                    'role' => $request->query('role'),
                    'status' => $request->query('status'),
                ],
                'pagination' => [
                    'current_page' => $users->currentPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                    'last_page' => $users->lastPage(),
                ],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load users',
                'details' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get one user with roles and basic activity counts.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $user = DB::table('users as u')
                ->leftJoin('user_profiles as up', 'up.user_id', '=', 'u.id')
                ->where('u.id', $id)
                ->whereNull('u.deleted_at')
                ->select([
                    'u.id',
                    'u.first_name',
                    'u.last_name',
                    'u.email',
                    'u.phone',
                    'u.status',
                    'u.created_at',
                    'u.updated_at',
                    'up.city',
                    'up.country',
                    'up.preferred_language',
                    'up.currency',
                ])
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            $user->roles = DB::table('user_roles as ur')
                ->join('roles as r', 'r.id', '=', 'ur.role_id')
                ->where('ur.user_id', $id)
                ->select([
                    'r.id',
                    'r.name',
                    'r.slug',
                ])
                ->get();

            $user->stats = [
                'properties' => DB::table('properties')
                    ->where('owner_id', $id)
                    ->whereNull('deleted_at')
                    ->count(),

                'reviews' => DB::table('reviews')
                    ->where('user_id', $id)
                    ->count(),

                'successful_logins' => DB::table('login_history')
                    ->where('user_id', $id)
                    ->where('login_status', 'success')
                    ->count(),

                'failed_logins' => DB::table('login_history')
                    ->where('user_id', $id)
                    ->where('login_status', 'failed')
                    ->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $user,
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load user',
                'details' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Change account status.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        try {
            $admin = $request->attributes->get('auth_user');

            if (!$admin || empty($admin['id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $status = trim((string) $request->input('status', ''));

            if ($status === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Status is required',
                ], 422);
            }

            $allowedStatuses = $this->allowedUserStatuses();

            if (!in_array($status, $allowedStatuses, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid user status',
                    'allowed_statuses' => $allowedStatuses,
                ], 422);
            }

            $user = DB::table('users')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            if ((int) $admin['id'] === $id && $status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot deactivate your own account',
                ], 422);
            }

            $targetIsSuperAdmin = DB::table('user_roles as ur')
                ->join('roles as r', 'r.id', '=', 'ur.role_id')
                ->where('ur.user_id', $id)
                ->where('r.slug', 'super-admin')
                ->exists();

            if ($targetIsSuperAdmin && $status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Super admin account cannot be deactivated',
                ], 403);
            }

            DB::table('users')
                ->where('id', $id)
                ->update([
                    'status' => $status,
                    'updated_at' => now(),
                ]);

            return response()->json([
                'success' => true,
                'message' => 'User status updated successfully',
                'user_id' => $id,
                'status' => $status,
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update user status',
                'details' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Read allowed values directly from the users.status enum when possible.
     */
    private function allowedUserStatuses(): array
    {
        try {
            $column = DB::selectOne(
                "SHOW COLUMNS FROM users WHERE Field = 'status'"
            );

            if ($column && isset($column->Type)) {
                $type = (string) $column->Type;

                if (preg_match('/^enum\((.*)\)$/', $type, $matches)) {
                    return array_map(
                        static fn ($value) => trim($value, "'"),
                        str_getcsv($matches[1], ',', "'")
                    );
                }
            }
        } catch (Throwable) {
            // Fall back below.
        }

        return [
            'pending',
            'active',
            'inactive',
            'suspended',
            'blocked',
        ];
    }
}
