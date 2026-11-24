<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Notification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $report = $this->relationLoaded('report') ? $this->report : null;

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'title' => $this->title,
            'message' => $this->message,
            'is_read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'report' => $report !== null ? [
                'id' => $report->id,
                'ticket_code' => $report->ticket_code,
                'title' => $report->title,
                'status' => $report->status->value,
            ] : null,
        ];
    }
}
