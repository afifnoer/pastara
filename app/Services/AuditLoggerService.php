<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditActionType;
use App\Enums\ReportStatus;
use App\Models\AuditLog;
use App\Models\OpdUnit;
use App\Models\Report;
use App\Models\User;

class AuditLoggerService
{
    /**
     * Record an immutable insert-only entry into audit_logs.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function log(
        AuditActionType $actionType,
        ?Report $report = null,
        ?User $actor = null,
        ?ReportStatus $fromStatus = null,
        ?ReportStatus $toStatus = null,
        ?string $note = null,
        ?array $metadata = null,
        string $actorRoleOverride = 'SYSTEM',
        ?string $actorUnitNameOverride = null,
    ): AuditLog {
        $actorRole = $actor !== null ? $actor->role->value : $actorRoleOverride;

        $actorUnitName = $actorUnitNameOverride;
        if ($actorUnitName === null && $actor?->opd_unit_id !== null) {
            $actorUnitName = $actor->relationLoaded('opdUnit')
                ? $actor->opdUnit?->name
                : OpdUnit::query()->whereKey($actor->opd_unit_id)->value('name');
        }

        return AuditLog::create([
            'report_id' => $report?->id,
            'actor_user_id' => $actor?->id,
            'actor_role' => $actorRole,
            'actor_unit_name' => $actorUnitName,
            'action_type' => $actionType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
            'metadata' => $metadata,
            'is_public_visible' => $actionType->isPublicVisible(),
            'created_at' => now('UTC'),
        ]);
    }
}
