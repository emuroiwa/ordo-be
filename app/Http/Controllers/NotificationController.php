<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    /**
     * Get all notifications for the authenticated user.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        
        $query = $user->notifications()->active();
        
        // Filter by read status
        if ($request->has('unread_only') && $request->boolean('unread_only')) {
            $query->unread();
        }
        
        // Filter by type
        if ($request->has('type')) {
            $query->type($request->type);
        }
        
        // Filter by priority
        if ($request->has('priority')) {
            $query->priority($request->priority);
        }
        
        $notifications = $query->paginate($request->get('per_page', 15));
        
        return response()->json([
            'notifications' => $notifications->items(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'unread_count' => $user->unreadNotificationsCount(),
            ]
        ]);
    }

    /**
     * Get unread notifications count.
     */
    public function unreadCount()
    {
        return response()->json([
            'unread_count' => Auth::user()->unreadNotificationsCount()
        ]);
    }

    /**
     * Get recent notifications (for dropdown/preview).
     */
    public function recent(Request $request)
    {
        $limit = $request->get('limit', 5);
        $notifications = Auth::user()->recentNotifications($limit);
        
        return response()->json([
            'notifications' => $notifications,
            'unread_count' => Auth::user()->unreadNotificationsCount(),
        ]);
    }

    /**
     * Mark notification as read.
     */
    public function markAsRead(Notification $notification)
    {
        // Ensure the notification belongs to the authenticated user
        if ($notification->notifiable_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        $notification->markAsRead();
        
        return response()->json([
            'message' => 'Notification marked as read',
            'notification' => $notification->fresh()
        ]);
    }

    /**
     * Mark notification as unread.
     */
    public function markAsUnread(Notification $notification)
    {
        // Ensure the notification belongs to the authenticated user
        if ($notification->notifiable_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        $notification->markAsUnread();
        
        return response()->json([
            'message' => 'Notification marked as unread',
            'notification' => $notification->fresh()
        ]);
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead()
    {
        Auth::user()->markAllNotificationsAsRead();
        
        return response()->json([
            'message' => 'All notifications marked as read'
        ]);
    }

    /**
     * Delete a notification.
     */
    public function destroy(Notification $notification)
    {
        // Ensure the notification belongs to the authenticated user
        if ($notification->notifiable_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        $notification->delete();
        
        return response()->json([
            'message' => 'Notification deleted successfully'
        ]);
    }

    /**
     * Bulk actions on notifications.
     */
    public function bulkAction(Request $request)
    {
        $request->validate([
            'action' => ['required', Rule::in(['mark_read', 'mark_unread', 'delete'])],
            'notification_ids' => ['required', 'array'],
            'notification_ids.*' => ['string', 'exists:notifications,id'],
        ]);

        $user = Auth::user();
        $notifications = $user->notifications()
            ->whereIn('id', $request->notification_ids)
            ->get();

        if ($notifications->isEmpty()) {
            return response()->json(['error' => 'No notifications found'], 404);
        }

        switch ($request->action) {
            case 'mark_read':
                $notifications->each->markAsRead();
                $message = 'Notifications marked as read';
                break;
            case 'mark_unread':
                $notifications->each->markAsUnread();
                $message = 'Notifications marked as unread';
                break;
            case 'delete':
                $notifications->each->delete();
                $message = 'Notifications deleted';
                break;
        }

        return response()->json([
            'message' => $message,
            'affected_count' => $notifications->count()
        ]);
    }

    /**
     * Create a notification (for testing purposes or admin actions).
     */
    public function store(Request $request)
    {
        $request->validate([
            'type' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string'],
            'priority' => ['string', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'action_url' => ['nullable', 'url'],
            'icon' => ['nullable', 'string'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $user = Auth::user();
        
        $notification = $user->notifications()->create([
            'type' => $request->type,
            'data' => [
                'title' => $request->title,
                'message' => $request->message,
            ],
            'priority' => $request->get('priority', 'normal'),
            'metadata' => [
                'action_url' => $request->action_url,
                'icon' => $request->icon,
            ],
            'expires_at' => $request->expires_at,
        ]);

        return response()->json([
            'message' => 'Notification created successfully',
            'notification' => $notification
        ], 201);
    }
}