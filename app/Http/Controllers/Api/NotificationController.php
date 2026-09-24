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