<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\PhotoType;
use App\Models\AuditLog;
use App\Models\BudgetMilestone;
use App\Models\Dispute;
use App\Models\Report;
use App\Models\ReportPhoto;
use App\Services\SlaEngineService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin Report
 */
abstract class BaseReportResource extends JsonResource
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

        return [
            'id' => $report->id,
            'ticket_code' => $report->ticket_code,
            'title' => $report->title,
            'description_sanitized' => $report->description_sanitized,
            'status' => $report->status->value,
            'status_label' => $report->status->label(),
            'status_badge_color' => $report->status->badgeColor(),
            'resolution_track' => $report->resolution_track?->value,
            'current_cycle' => $report->current_cycle,
            'is_secret' => $report->is_secret,
            'upvote_count' => $report->upvote_count,
            'has_upvoted' => $this->resolveHasUpvoted($request, $report),
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
            'reporter' => $this->formatReporter($report, $request),
            'assigned_officer' => $this->formatAssignedOfficer($report),
            'sla' => [
                'sla_hours_snapshot' => $report->sla_hours_snapshot,
                'sla_started_at' => $report->sla_started_at?->toIso8601ZuluString(),
                'sla_cycle_started_at' => $report->sla_cycle_started_at?->toIso8601ZuluString(),
                'sla_paused_at' => $report->sla_paused_at?->toIso8601ZuluString(),
                'sla_deadline_at' => $report->sla_deadline_at?->toIso8601ZuluString(),
                'sla_stopped_at' => $report->sla_stopped_at?->toIso8601ZuluString(),
                'sla_zone' => $slaEngine->calculateZone($report)->value,
                'sla_remaining_seconds' => $slaEngine->remainingSeconds($report),
                'is_sla_overdue' => $report->is_sla_overdue,
                'is_milestone_overdue' => $report->is_milestone_overdue,
                'is_late' => $report->is_late,
            ],
            'signoff' => [
                'signoff_started_at' => $report->signoff_started_at?->toIso8601ZuluString(),
                'signoff_deadline_at' => $report->signoff_deadline_at?->toIso8601ZuluString(),
                'signoff_remaining_seconds' => $slaEngine->signoffRemainingSeconds($report),
            ],
            'geotag' => [
                'geotag_warning' => $report->geotag_warning,
                'geotag_distance_m' => $report->geotag_distance_m,
                'geotag_tolerance_snapshot_m' => $report->geotag_tolerance_snapshot_m,
            ],
            'officer_action_note' => $report->officer_action_note,
            'rejection_reason_code' => $report->rejection_reason_code?->value,
            'rejection_note' => $report->rejection_note,
            'photos_by_cycle' => $this->formatPhotosByCycle($report),
            'budget_schedule' => $this->formatBudgetSchedule($report),
            'disputes' => $this->formatDisputes($report),
            'rating' => $this->formatRating($report),
            'audit_timeline' => $this->formatAuditTimeline($report),
            'created_at' => $report->created_at?->toIso8601ZuluString(),
            'updated_at' => $report->updated_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    abstract protected function formatReporter(Report $report, Request $request): ?array;

    /**
     * @return array<string, mixed>|null
     */
    abstract protected function formatAssignedOfficer(Report $report): ?array;

    /**
     * @return list<array<string, mixed>>
     */
    abstract protected function formatAuditTimeline(Report $report): array;

    protected function maskName(?string $fullName): string
    {
        $clean = trim((string) $fullName);
        if ($clean === '') {
            return 'W***a';
        }

        $parts = preg_split('/\s+/', $clean) ?: [];
        $first = array_shift($parts) ?: $clean;
        $firstLen = mb_strlen($first);

        $maskedFirst = match (true) {
            $firstLen <= 1 => '*',
            $firstLen === 2 => mb_substr($first, 0, 1).'*',
            default => mb_substr($first, 0, 1).'***'.mb_substr($first, -1),
        };

        $maskedTail = array_map(
            static fn (string $word): string => mb_substr($word, 0, 1).'.',
            $parts
        );

        return trim($maskedFirst.' '.implode(' ', $maskedTail));
    }

    protected function maskPhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $trimmed = trim($phone);
        $len = strlen($trimmed);
        if ($len <= 6) {
            return str_repeat('*', $len);
        }

        return substr($trimmed, 0, 4).str_repeat('*', $len - 7).substr($trimmed, -3);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function buildAuditItems(Report $report, bool $onlyPublicVisible, bool $includeMetadata): array
    {
        if (! $report->relationLoaded('auditLogs')) {
            return [];
        }

        $logs = $report->auditLogs->sortBy('created_at');
        if ($onlyPublicVisible) {
            $logs = $logs->filter(static fn (AuditLog $log): bool => $log->is_public_visible);
        }

        return $logs->values()->map(function (AuditLog $log) use ($includeMetadata): array {
            $item = [
                'id' => $log->id,
                'action_type' => $log->action_type->value,
                'actor_role' => $log->actor_role,
                'actor_unit_name' => $log->actor_unit_name,
                'from_status' => $log->from_status?->value,
                'to_status' => $log->to_status?->value,
                'note' => $log->note,
                'created_at' => $log->created_at?->toIso8601ZuluString(),
            ];

            if ($includeMetadata) {
                $item['metadata'] = $log->metadata;
            }

            return $item;
        })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function formatPhotosByCycle(Report $report): array
    {
        if (! $report->relationLoaded('photos')) {
            return [];
        }

        $cycles = $report->photos
            ->pluck('cycle')
            ->unique()
            ->sort()
            ->values();

        return $cycles->map(function (int $cycle) use ($report): array {
            $cyclePhotos = $report->photos->where('cycle', $cycle)->sortBy('sequence');

            $mapPhoto = static function (ReportPhoto $photo): array {
                $payload = [
                    'id' => $photo->id,
                    'sequence' => $photo->sequence,
                    'url' => Storage::disk('public')->url($photo->file_path),
                    'thumbnail_url' => Storage::disk('public')->url($photo->thumbnail_path),
                ];

                if ($photo->photo_type === PhotoType::AFTER) {
                    $payload['distance_from_report_m'] = $photo->distance_from_report_m;
                    $payload['has_geotag_warning'] = $photo->has_geotag_warning;
                }

                return $payload;
            };

            return [
                'cycle' => $cycle,
                'before' => $cyclePhotos->where('photo_type', PhotoType::BEFORE)->values()->map($mapPhoto)->all(),
                'after' => $cyclePhotos->where('photo_type', PhotoType::AFTER)->values()->map($mapPhoto)->all(),
                'progress' => $cyclePhotos->where('photo_type', PhotoType::PROGRESS)->values()->map($mapPhoto)->all(),
                'dispute' => $cyclePhotos->where('photo_type', PhotoType::DISPUTE)->values()->map($mapPhoto)->all(),
            ];
        })->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function formatBudgetSchedule(Report $report): ?array
    {
        if (! $report->relationLoaded('budgetSchedule') || $report->budgetSchedule === null) {
            return null;
        }

        $schedule = $report->budgetSchedule;
        $milestones = $schedule->relationLoaded('milestones')
            ? $schedule->milestones->sortByDesc('created_at')->values()->map(static fn (BudgetMilestone $m): array => [
                'id' => $m->id,
                'quarter_label' => $m->quarter_label,
                'progress_note' => $m->progress_note,
                'attachment_url' => $m->attachment_path !== null ? Storage::disk('public')->url($m->attachment_path) : null,
                'created_at' => $m->created_at?->toIso8601ZuluString(),
            ])->all()
            : [];

        return [
            'fiscal_year' => $schedule->fiscal_year,
            'proposal_ref_number' => $schedule->proposal_ref_number,
            'target_quarter' => $schedule->target_quarter,
            'estimated_budget' => $schedule->estimated_budget,
            'document_proof_url' => $schedule->document_proof_path !== null
                ? Storage::disk('public')->url($schedule->document_proof_path)
                : null,
            'document_due_at' => $schedule->document_due_at?->toIso8601ZuluString(),
            'document_uploaded_at' => $schedule->document_uploaded_at?->toIso8601ZuluString(),
            'last_milestone_updated_at' => $schedule->last_milestone_updated_at?->toIso8601ZuluString(),
            'execution_target_date' => $schedule->execution_target_date?->format('Y-m-d'),
            'milestones' => $milestones,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function formatDisputes(Report $report): array
    {
        if (! $report->relationLoaded('disputes')) {
            return [];
        }

        return $report->disputes->sortBy('cycle')->values()->map(static fn (Dispute $dispute): array => [
            'id' => $dispute->id,
            'cycle' => $dispute->cycle,
            'citizen_reason' => $dispute->citizen_reason,
            'status' => $dispute->status->value,
            'audit_notes' => $dispute->audit_notes,
            'reopen_sla_hours' => $dispute->reopen_sla_hours,
            'resolved_at' => $dispute->resolved_at?->toIso8601ZuluString(),
            'created_at' => $dispute->created_at?->toIso8601ZuluString(),
        ])->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function formatRating(Report $report): ?array
    {
        if (! $report->relationLoaded('rating') || $report->rating === null) {
            return null;
        }

        return [
            'speed_score' => $report->rating->speed_score,
            'physical_quality_score' => $report->rating->physical_quality_score,
            'comment' => $report->rating->comment,
            'created_at' => $report->rating->created_at?->toIso8601ZuluString(),
        ];
    }

    private function resolveHasUpvoted(Request $request, Report $report): bool
    {
        if (isset($report->has_upvoted)) {
            return (bool) $report->has_upvoted;
        }

        $user = $request->user();
        if ($user === null || ! $report->relationLoaded('upvotes')) {
            return false;
        }

        return $report->upvotes->contains('user_id', $user->id);
    }
}
