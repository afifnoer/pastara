<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Models\Notification;
use App\Models\Report;
use App\Models\ReportUpvote;
use App\Models\User;

class NotificationDispatcherService
{
    /**
     * Bulk-insert in-app notifications for unique active target users.
     *
     * @param  iterable<int|string|null>  $userIds
     */
    public function notifyUsers(
        iterable $userIds,
        NotificationType $type,
        ?Report $report,
        string $message,
        ?string $title = null,
    ): int {
        $uniqueIds = [];
        foreach ($userIds as $id) {
            if ($id !== null && (int) $id > 0) {
                $uniqueIds[(int) $id] = (int) $id;
            }
        }

        if ($uniqueIds === []) {
            return 0;
        }

        $activeUserIds = User::query()
            ->whereIn('id', array_values($uniqueIds))
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        if ($activeUserIds === []) {
            return 0;
        }

        $now = now('UTC');
        $resolvedTitle = $title ?? $type->defaultTitle();
        $rows = [];

        foreach ($activeUserIds as $userId) {
            $rows[] = [
                'user_id' => $userId,
                'report_id' => $report?->id,
                'type' => $type->value,
                'title' => $resolvedTitle,
                'message' => $message,
                'read_at' => null,
                'created_at' => $now,
            ];
        }

        Notification::insert($rows);

        return count($rows);
    }

    /**
     * Get active ADMIN_UNIT user IDs belonging to the given unit.
     *
     * @return list<int>
     */
    public function getUnitAdminIds(int $opdUnitId): array
    {
        return User::query()
            ->where('role', UserRole::ADMIN_UNIT)
            ->where('opd_unit_id', $opdUnitId)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Get all active INSPEKTORAT user IDs.
     *
     * @return list<int>
     */
    public function getInspectorateIds(): array
    {
        return User::query()
            ->where('role', UserRole::INSPEKTORAT)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Get user IDs of citizens watching the given report.
     *
     * @return list<int>
     */
    public function getWatcherIds(Report $report): array
    {
        return ReportUpvote::query()
            ->where('report_id', $report->id)
            ->where('is_watching', true)
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Get reporter plus watching citizens for a report.
     *
     * @return list<int>
     */
    public function getReporterAndWatcherIds(Report $report): array
    {
        $ids = array_merge([(int) $report->reporter_id], $this->getWatcherIds($report));

        return array_values(array_unique($ids));
    }
}
