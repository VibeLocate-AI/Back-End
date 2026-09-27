<?php

namespace App\Http\Controllers\Api;
use Carbon\Carbon;
use App\Http\Controllers\Controller;
use App\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Get authenticated user ID.
     */
    private function getUserId(Request $request): ?int
    {
        $user = $request->attributes->get('auth_user');

        if (!$user) {
            return null;
        }

        if (is_array($user)) {
            return isset($user['id']) ? (int) $user['id'] : null;
        }

        if (is_object($user)) {
            return isset($user->id) ? (int) $user->id : null;
        }

        return null;
    }

    /**
     * Get all notifications.
     *
     * GET /api/notifications
     */
    public function index(Request $request)
    
    {
        $userId = $this->getUserId($request);

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $notifications = Notification::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($notification) {
                return $this->formatNotification($notification);
            });

        $unreadCount = Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->count();

        return response()->json([
            'success' => true,
            'unread_count' => $unreadCount,
            'notifications' => $notifications,
        ]);
    }

    /**
     * Get unread notifications count.
     *
     * GET /api/notifications/unread-count
     */
    public function unreadCount(Request $request)
    {
        $userId = $this->getUserId($request);

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $count = Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->count();

        return response()->json([
            'success' => true,
            'unread_count' => $count,
        ]);
    }

    /**
     * Mark one notification as read.
     *
     * PUT /api/notifications/{id}/read
     */
    public function markAsRead(Request $request, $id)
    {
        $userId = $this->getUserId($request);

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $notification = Notification::where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        }$notification->is_read = true;
$notification->read_at = now();
$notification->save();
$notification->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read',
            'notification' => $this->formatNotification($notification),
        ]);
    }

    /**
     * Mark all notifications as read.
     *
     * PUT /api/notifications/read-all
     */
    public function markAllAsRead(Request $request)
    {
        $userId = $this->getUserId($request);

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $updated = Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read',
            'updated_count' => $updated,
            'unread_count' => 0,
        ]);
    }
/**
 * Delete notification.
 *
 * DELETE /api/notifications/{id}
 */
public function destroy(Request $request, $id)
{
    $userId = $this->getUserId($request);

    if (!$userId) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized',
        ], 401);
    }

    $notification = Notification::where('id', $id)
        ->where('user_id', $userId)
        ->first();

    if (!$notification) {
        return response()->json([
            'success' => false,
            'message' => 'Notification not found',
        ], 404);
    }

    $notification->delete();

    return response()->json([
        'success' => true,
        'message' => 'Notification deleted successfully',
    ]);
}


/**
 * Admin send notification.
 *
 * POST /api/admin/notifications
 */
public function adminStore(Request $request)
{
    $adminId = $this->getUserId($request);

    if (!$adminId) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized',
        ], 401);
    }

    $isAdmin = \Illuminate\Support\Facades\DB::table('user_roles as ur')
        ->join('roles as r', 'r.id', '=', 'ur.role_id')
        ->where('ur.user_id', $adminId)
        ->whereIn('r.slug', [
            'admin',
            'super-admin',
        ])
        ->exists();

    if (!$isAdmin) {
        return response()->json([
            'success' => false,
            'message' => 'Only admin or super-admin can send notifications',
        ], 403);
    }

    $validator = validator(
        $request->all(),
        [
            'target' => 'required|in:user,role,all',
            'user_id' => 'nullable|integer|exists:users,id',
            'role' => 'nullable|string|exists:roles,slug',

            'type' => 'required|in:property,offer,event,review,system',
            'title' => 'required|string|max:255',
            'message' => 'required|string|max:2000',

            'image' => 'nullable|string|max:1000',
            'reference_id' => 'nullable|integer',
            'reference_type' => 'nullable|string|max:100',
            'action_url' => 'nullable|string|max:1000',
        ]
    );

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid notification data',
            'errors' => $validator->errors(),
        ], 422);
    }

    $target = $request->input('target');

    if ($target === 'user' && !$request->filled('user_id')) {
        return response()->json([
            'success' => false,
            'message' => 'user_id is required when target is user',
        ], 422);
    }

    if ($target === 'role' && !$request->filled('role')) {
        return response()->json([
            'success' => false,
            'message' => 'role is required when target is role',
        ], 422);
    }

    if ($target === 'user') {
        $users = \Illuminate\Support\Facades\DB::table('users')
            ->where('id', (int) $request->input('user_id'))
            ->whereNull('deleted_at')
            ->pluck('id');

    } elseif ($target === 'role') {
        $users = \Illuminate\Support\Facades\DB::table('users as u')
            ->join('user_roles as ur', 'ur.user_id', '=', 'u.id')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('r.slug', $request->input('role'))
            ->whereNull('u.deleted_at')
            ->where('u.status', 'active')
            ->distinct()
            ->pluck('u.id');

    } else {
        $users = \Illuminate\Support\Facades\DB::table('users')
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->pluck('id');
    }

    if ($users->isEmpty()) {
        return response()->json([
            'success' => false,
            'message' => 'No users found for this target',
        ], 404);
    }

    $sentCount = 0;

    foreach ($users as $userId) {
        Notification::create([
            'user_id' => (int) $userId,
            'type' => $request->input('type'),
            'title' => $request->input('title'),
            'message' => $request->input('message'),
            'image' => $request->input('image'),
            'reference_id' => $request->input('reference_id'),
            'reference_type' => $request->input('reference_type'),
            'action_url' => $request->input('action_url'),
            'is_read' => false,
            'read_at' => null,
        ]);

        $sentCount++;
    }

    return response()->json([
        'success' => true,
        'message' => 'Notification sent successfully',
        'target' => $target,
        'sent_count' => $sentCount,
    ], 201);
}


/**
 * Send notification automatically to all active users.
 */
public static function sendToAllUsers(
    string $type,
    string $title,
    string $message,
    ?string $referenceType = null,
    ?int $referenceId = null,
    ?string $actionUrl = null,
    ?string $image = null
): int {
    $allowedTypes = [
        'property',
        'offer',
        'event',
        'review',
        'system',
    ];

    if (!in_array($type, $allowedTypes, true)) {
        $type = 'system';
    }

    $users = \Illuminate\Support\Facades\DB::table('users')
        ->whereNull('deleted_at')
        ->where('status', 'active')
        ->pluck('id');

    $sentCount = 0;

    foreach ($users as $userId) {
        Notification::create([
            'user_id' => (int) $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'image' => $image,
            'reference_id' => $referenceId,
            'reference_type' => $referenceType,
            'action_url' => $actionUrl,
            'is_read' => false,
            'read_at' => null,
        ]);

        $sentCount++;
    }

    return $sentCount;
}


/**
 * Format notification response.
 */
private function formatNotification(Notification $notification): array
{
    return [
        'id' => $notification->id,
        'type' => $notification->type,

        'title' => $notification->title,
        'message' => $notification->message,

        'image' => $notification->image,

        'reference' => [
            'type' => $notification->reference_type,
            'id' => $notification->reference_id,
        ],

        'action_url' => $notification->action_url,

        'is_read' => (bool) $notification->is_read,

        'read_at' => $notification->read_at
            ? (string) $notification->read_at
            : null,

        'created_at' => $notification->created_at
            ? (string) $notification->created_at
            : null,
    ];
}
}
