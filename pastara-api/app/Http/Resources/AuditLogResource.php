<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $actor = $this->relationLoaded('actor') ? $this->actor : null;
        $report = $this->relationLoaded('report') ? $this->report : null;

        return [
            'id' => $this->id,
            'report_id' => $this->report_id,
            'report' => $report !== null ? [
                'id' => $report->id,
                'ticket_code' => $report->ticket_code,
                'title' => $report->title,
            ] : null,
            'actor_user_id' => $this->actor_user_id,
            'actor' => $actor !== null ? [
                'id' => $actor->id,
                'name' => $actor->name,
                'email' => $actor->email,
                'role' => $actor->role->value,
            ] : null,
            'actor_role' => $this->actor_role,
            'actor_unit_name' => $this->actor_unit_name,
            'action_type' => $this->action_type->value,
            'from_status' => $this->from_status?->value,
            'to_status' => $this->to_status?->value,
            'note' => $this->note,
            'metadata' => $this->metadata,
            'is_public_visible' => $this->is_public_visible,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
