<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class NotificationService
{
    /**
     * List paginated in-app notifications strictly owned by the authenticated user (BR-041).
     *
     * @param  array<string, mixed>  $filters
     * @return array{paginator: LengthAwarePaginator, unread_count: int}
     */
    public function listUserNotifications(User $user, array $filters): array
    {
        $query = Notification::query()
            ->with(['report:id,ticket_code,title,status'])
            ->where('user_id', $user->id);

        if (filter_var($filters['unread_only'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereNull('read_at');
        }

        $paginator = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(20);

        $unreadCount = Notification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        return [
            'paginator' => $paginator,
            'unread_count' => $unreadCount,
        ];
    }

    /**
     * Mark specific or all unread notifications owned by the user as read.
     *
     * @param  list<int>|null  $notificationIds
     * @return array{marked_count: int, unread_count: int}
     */
    public function markAsRead(User $user, ?array $notificationIds = null): array
    {
        $query = Notification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at');

        if ($notificationIds !== null && $notificationIds !== []) {
            $query->whereIn('id', array_values(array_unique(array_map('intval', $notificationIds))));
        }

        $markedCount = $query->update([
            'read_at' => CarbonImmutable::now('UTC'),
        ]);

        $remainingUnread = Notification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        return [
            'marked_count' => $markedCount,
            'unread_count' => $remainingUnread,
        ];
    }
}
