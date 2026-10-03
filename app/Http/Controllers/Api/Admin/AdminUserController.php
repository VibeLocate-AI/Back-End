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
                ->leftJoin(
                    'user_profiles as up',
                    'up.user_id',
                    '=',
                    'u.id'
                )
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

            /*
            |--------------------------------------------------------------------------
            | Search
            |--------------------------------------------------------------------------
            */

            if ($request->filled('search')) {
                $search = trim(
                    (string) $request->query('search')
                );

                $query->where(function ($q) use ($search) {
                    $q->where(
                        'u.first_name',
                        'like',
                        '%' . $search . '%'
                    )
                    ->orWhere(
                        'u.last_name',
                        'like',
                        '%' . $search . '%'
                    )
                    ->orWhere(
                        'u.email',
                        'like',
                        '%' . $search . '%'
                    )
                    ->orWhere(
                        'u.phone',
                        'like',
                        '%' . $search . '%'
                    );
                });
            }

            /*
            |--------------------------------------------------------------------------
            | Status Filter
            |--------------------------------------------------------------------------
            */

            if ($request->filled('status')) {
                $query->where(
                    'u.status',
                    trim(
                        (string) $request->query('status')
                    )
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Role Filter
            |--------------------------------------------------------------------------
            */

            if ($request->filled('role')) {
                $role = trim(
                    (string) $request->query('role')
                );

                $query->whereExists(
                    function ($subQuery) use ($role) {
                        $subQuery
                            ->select(DB::raw(1))
                            ->from('user_roles as ur')
                            ->join(
                                'roles as r',
                                'r.id',
                                '=',
                                'ur.role_id'
                            )
                            ->whereColumn(
                                'ur.user_id',
                                'u.id'
                            )
                            ->where(
                                'r.slug',
                                $role
                            );
                    }
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Pagination
            |--------------------------------------------------------------------------
            */

            $users = $query
                ->orderByDesc('u.id')
                ->paginate($perPage);

            $items = collect(
                $users->items()
            );

            $userIds = $items
                ->pluck('id')
                ->all();

            /*
            |--------------------------------------------------------------------------
            | Load Roles
            |--------------------------------------------------------------------------
            */

            $roles = collect();

            if (!empty($userIds)) {
                $roles = DB::table('user_roles as ur')
                    ->join(
                        'roles as r',
                        'r.id',
                        '=',
                        'ur.role_id'
                    )
                    ->whereIn(
                        'ur.user_id',
                        $userIds
                    )
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

            /*
            |--------------------------------------------------------------------------
            | Attach Roles
            |--------------------------------------------------------------------------
            */

            $data = $items->map(
                function ($user) use ($roles) {
                    $user->roles = $roles
                        ->get(
                            $user->id,
                            collect()
                        )
                        ->map(
                            function ($role) {
                                return [
                                    'id' => $role->id,
                                    'name' => $role->name,
                                    'slug' => $role->slug,
                                ];
                            }
                        )
                        ->values();

                    return $user;
                }
            );

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
                'details' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
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
                ->leftJoin(
                    'user_profiles as up',
                    'up.user_id',
                    '=',
                    'u.id'
                )
                ->where(
                    'u.id',
                    $id
                )
                ->whereNull(
                    'u.deleted_at'
                )
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

            /*
            |--------------------------------------------------------------------------
            | Roles
            |--------------------------------------------------------------------------
            */

            $user->roles = DB::table(
                'user_roles as ur'
            )
                ->join(
                    'roles as r',
                    'r.id',
                    '=',
                    'ur.role_id'
                )
                ->where(
                    'ur.user_id',
                    $id
                )
                ->select([
                    'r.id',
                    'r.name',
                    'r.slug',
                ])
                ->get();

            /*
            |--------------------------------------------------------------------------
            | Stats
            |--------------------------------------------------------------------------
            */

            $user->stats = [
                'properties' => DB::table('properties')
                    ->where(
                        'owner_id',
                        $id
                    )
                    ->whereNull(
                        'deleted_at'
                    )
                    ->count(),

                'reviews' => DB::table('reviews')
                    ->where(
                        'user_id',
                        $id
                    )
                    ->count(),

                'successful_logins' => DB::table(
                    'login_history'
                )
                    ->where(
                        'user_id',
                        $id
                    )
                    ->where(
                        'login_status',
                        'success'
                    )
                    ->count(),

                'failed_logins' => DB::table(
                    'login_history'
                )
                    ->where(
                        'user_id',
                        $id
                    )
                    ->where(
                        'login_status',
                        'failed'
                    )
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
                'details' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    /**
     * Change account status.
     */
    public function updateStatus(
        Request $request,
        int $id
    ): JsonResponse {
        try {
            $admin = $request
                ->attributes
                ->get('auth_user');

            if (
                !$admin ||
                empty($admin['id'])
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $status = trim(
                (string) $request->input(
                    'status',
                    ''
                )
            );

            if ($status === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Status is required',
                ], 422);
            }

            $allowedStatuses =
                $this->allowedUserStatuses();

            if (
                !in_array(
                    $status,
                    $allowedStatuses,
                    true
                )
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid user status',
                    'allowed_statuses' => $allowedStatuses,
                ], 422);
            }

            $user = DB::table('users')
                ->where(
                    'id',
                    $id
                )
                ->whereNull(
                    'deleted_at'
                )
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | Admin Cannot Disable Himself
            |--------------------------------------------------------------------------
            */

            if (
                (int) $admin['id'] === $id &&
                $status !== 'active'
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot deactivate your own account',
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Protect Super Admin
            |--------------------------------------------------------------------------
            */

            $targetIsSuperAdmin =
                DB::table('user_roles as ur')
                    ->join(
                        'roles as r',
                        'r.id',
                        '=',
                        'ur.role_id'
                    )
                    ->where(
                        'ur.user_id',
                        $id
                    )
                    ->where(
                        'r.slug',
                        'super-admin'
                    )
                    ->exists();

            if (
                $targetIsSuperAdmin &&
                $status !== 'active'
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Super admin account cannot be deactivated',
                ], 403);
            }

            /*
            |--------------------------------------------------------------------------
            | Update Status
            |--------------------------------------------------------------------------
            */

            DB::table('users')
                ->where(
                    'id',
                    $id
                )
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
                'details' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    /**
     * Change user role.
     *
     * Supported roles for the admin UI:
     * - tenant
     * - agent
     * - super-admin
     *
     * "admin" is accepted as an alias for "super-admin"
     * so the frontend can safely send either value.
     */
    public function updateRole(
        Request $request,
        int $id
    ): JsonResponse {
        try {
            /*
            |--------------------------------------------------------------------------
            | Authenticated Admin
            |--------------------------------------------------------------------------
            */

            $admin = $request
                ->attributes
                ->get('auth_user');

            if (
                !$admin ||
                empty($admin['id'])
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            /*
            |--------------------------------------------------------------------------
            | Read Role
            |--------------------------------------------------------------------------
            */

            $roleSlug = strtolower(
                trim(
                    (string) $request->input(
                        'role',
                        ''
                    )
                )
            );

            if ($roleSlug === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Role is required',
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Frontend Alias
            |--------------------------------------------------------------------------
            |
            | Frontend may send "admin".
            | Database role is "super-admin".
            |
            */

            if ($roleSlug === 'admin') {
                $roleSlug = 'super-admin';
            }

            /*
            |--------------------------------------------------------------------------
            | Allowed Roles
            |--------------------------------------------------------------------------
            */

            $allowedRoles = [
                'tenant',
                'agent',
                'super-admin',
            ];

            if (
                !in_array(
                    $roleSlug,
                    $allowedRoles,
                    true
                )
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid user role',
                    'allowed_roles' => $allowedRoles,
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Target User
            |--------------------------------------------------------------------------
            */

            $user = DB::table('users')
                ->where(
                    'id',
                    $id
                )
                ->whereNull(
                    'deleted_at'
                )
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | Cannot Change Own Role
            |--------------------------------------------------------------------------
            */

            if (
                (int) $admin['id'] === $id
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot change your own role',
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Check Current Admin Privileges
            |--------------------------------------------------------------------------
            */

            $adminIsSuperAdmin =
                DB::table('user_roles as ur')
                    ->join(
                        'roles as r',
                        'r.id',
                        '=',
                        'ur.role_id'
                    )
                    ->where(
                        'ur.user_id',
                        (int) $admin['id']
                    )
                    ->where(
                        'r.slug',
                        'super-admin'
                    )
                    ->exists();

            /*
            |--------------------------------------------------------------------------
            | Check Target Super Admin
            |--------------------------------------------------------------------------
            */

            $targetIsSuperAdmin =
                DB::table('user_roles as ur')
                    ->join(
                        'roles as r',
                        'r.id',
                        '=',
                        'ur.role_id'
                    )
                    ->where(
                        'ur.user_id',
                        $id
                    )
                    ->where(
                        'r.slug',
                        'super-admin'
                    )
                    ->exists();

            /*
            |--------------------------------------------------------------------------
            | Only Super Admin Can Assign Super Admin
            |--------------------------------------------------------------------------
            */

            if (
                $roleSlug === 'super-admin' &&
                !$adminIsSuperAdmin
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only a super admin can assign the super admin role',
                ], 403);
            }

            /*
            |--------------------------------------------------------------------------
            | Only Super Admin Can Modify Another Super Admin
            |--------------------------------------------------------------------------
            */

            if (
                $targetIsSuperAdmin &&
                !$adminIsSuperAdmin
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only a super admin can change another super admin role',
                ], 403);
            }

            /*
            |--------------------------------------------------------------------------
            | Find Requested Role
            |--------------------------------------------------------------------------
            */

            $role = DB::table('roles')
                ->where(
                    'slug',
                    $roleSlug
                )
                ->first();

            if (!$role) {
                return response()->json([
                    'success' => false,
                    'message' => 'Role not found in database',
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Current Role
            |--------------------------------------------------------------------------
            */

            $currentRoles = DB::table(
                'user_roles as ur'
            )
                ->join(
                    'roles as r',
                    'r.id',
                    '=',
                    'ur.role_id'
                )
                ->where(
                    'ur.user_id',
                    $id
                )
                ->select([
                    'r.id',
                    'r.name',
                    'r.slug',
                ])
                ->get();

            /*
            |--------------------------------------------------------------------------
            | Already Same Role
            |--------------------------------------------------------------------------
            */

            if (
                $currentRoles->count() === 1 &&
                $currentRoles->first()->slug === $roleSlug
            ) {
                return response()->json([
                    'success' => true,
                    'message' => 'User already has this role',
                    'data' => [
                        'user_id' => $id,
                        'role' => [
                            'id' => $role->id,
                            'name' => $role->name,
                            'slug' => $role->slug,
                        ],
                    ],
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Update Role
            |--------------------------------------------------------------------------
            |
            | Admin UI uses one account role.
            | Therefore old role assignments are removed
            | and replaced with the selected role.
            |
            */

            DB::transaction(
                function () use (
                    $id,
                    $role
                ) {
                    DB::table('user_roles')
                        ->where(
                            'user_id',
                            $id
                        )
                        ->delete();

                    DB::table('user_roles')
                        ->insert([
                            'user_id' => $id,
                            'role_id' => $role->id,
                            'assigned_at' => now(),
                        ]);

                    DB::table('users')
                        ->where(
                            'id',
                            $id
                        )
                        ->update([
                            'updated_at' => now(),
                        ]);
                }
            );

            /*
            |--------------------------------------------------------------------------
            | Response
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'success' => true,
                'message' => 'User role updated successfully',

                'data' => [
                    'user_id' => $id,

                    'previous_roles' => $currentRoles
                        ->map(
                            function ($currentRole) {
                                return [
                                    'id' => $currentRole->id,
                                    'name' => $currentRole->name,
                                    'slug' => $currentRole->slug,
                                ];
                            }
                        )
                        ->values(),

                    'role' => [
                        'id' => $role->id,
                        'name' => $role->name,
                        'slug' => $role->slug,
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update user role',
                'details' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    /**
     * Read allowed values directly from users.status enum when possible.
     */
    private function allowedUserStatuses(): array
    {
        try {
            $column = DB::selectOne(
                "SHOW COLUMNS FROM users WHERE Field = 'status'"
            );

            if (
                $column &&
                isset($column->Type)
            ) {
                $type = (string) $column->Type;

                if (
                    preg_match(
                        '/^enum\((.*)\)$/',
                        $type,
                        $matches
                    )
                ) {
                    return array_map(
                        static fn ($value) =>
                            trim(
                                $value,
                                "'"
                            ),
                        str_getcsv(
                            $matches[1],
                            ',',
                            "'"
                        )
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