<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\DB;

class AuthorizationService
{
    public function userHasPermission(int $userId, string $permission): bool
    {
        return DB::table('permissions as p')
            ->join('role_permissions as rp', 'rp.permission_id', '=', 'p.id')
            ->join('user_roles as ur', 'ur.role_id', '=', 'rp.role_id')
            ->where('ur.user_id', $userId)
            ->where('p.slug', $permission)
            ->exists();
    }

    public function rolesForUser(int $userId): array
    {
        return DB::table('roles as r')
            ->join('user_roles as ur', 'ur.role_id', '=', 'r.id')
            ->where('ur.user_id', $userId)
            ->pluck('r.slug')
            ->all();
    }
}
