<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\PhotoType;
use App\Models\Report;
use App\Services\SlaEngineService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin Report
 */
class PublicReportCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SlaEngineService $slaEngine */
        $slaEngine = app(SlaEngineService::class);
        $report = $this->resource;

        $category = $report->relationLoaded('category') ? $report->category : null;
        $opdUnit = $report->relationLoaded('opdUnit') ? $report->opdUnit : null;
        $district = $report->relationLoaded('district') ? $report->district : null;
        $village = $report->relationLoaded('village') ? $report->village : null;

        $thumbnailUrl = null;
        if ($report->relationLoaded('photos')) {
            $firstBefore = $report->photos
                ->where('photo_type', PhotoType::BEFORE)
                ->sortBy(['cycle', 'sequence'])
                ->first();

            if ($firstBefore !== null) {
                $thumbnailUrl = Storage::disk('public')->url($firstBefore->thumbnail_path);
            }
        }

        return [
            'id' => $report->id,
            'ticket_code' => $report->ticket_code,
            'title' => $report->title,
            'status' => $report->status->value,
            'status_label' => $report->status->label(),
            'status_badge_color' => $report->status->badgeColor(),
            'resolution_track' => $report->resolution_track?->value,
            'current_cycle' => $report->current_cycle,
            'is_secret' => $report->is_secret,
            'upvote_count' => $report->upvote_count,
            'thumbnail_url' => $thumbnailUrl,
            'category' => $category !== null ? [
                'id' => $category->id,
                'code' => $category->code,
                'name' => $category->name,
                'issue_type' => $category->issue_type->value,
            ] : null,
            'opd_unit' => $opdUnit !== null ? [
                'id' => $opdUnit->id,
                'code' => $opdUnit->code,
                'name' => $opdUnit->name,
                'level' => $opdUnit->level->value,
            ] : null,
            'location' => [
                'latitude' => (float) $report->latitude,
                'longitude' => (float) $report->longitude,
                'district' => $district !== null ? [
                    'id' => $district->id,
                    'name' => $district->name,
                ] : null,
                'village' => $village !== null ? [
                    'id' => $village->id,
                    'name' => $village->name,
                    'type' => $village->type,
                ] : null,
                'address_detail' => $report->address_detail,
            ],
            'sla' => [
                'sla_deadline_at' => $report->sla_deadline_at?->toIso8601ZuluString(),
                'sla_zone' => $slaEngine->calculateZone($report)->value,
                'sla_remaining_seconds' => $slaEngine->remainingSeconds($report),
                'is_sla_overdue' => $report->is_sla_overdue,
                'is_milestone_overdue' => $report->is_milestone_overdue,
                'is_late' => $report->is_late,
            ],
            'created_at' => $report->created_at?->toIso8601ZuluString(),
        ];
    }
}
