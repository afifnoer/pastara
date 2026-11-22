<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditActionType;
use App\Enums\PhotoType;
use App\Enums\ReportStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Report;
use App\Models\ReportPhoto;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class OfficerTaskService
{
    /**
     * Full relation graph needed by UnitReportResource without lazy loading.
     *
     * @var list<string>
     */
    private const OFFICER_RELATIONS = [
        'reporter',
        'category',
        'opdUnit',
        'assignedOfficer',
        'district',
        'village',
        'photos',
        'budgetSchedule.milestones',
        'disputes',
        'rating',
        'auditLogs',
    ];

    public function __construct(
        private readonly ReportStateMachineService $stateMachine,
        private readonly GeotagValidationService $geotagValidator,
        private readonly AuditLoggerService $auditLogger,
        private readonly TextSanitizerService $sanitizer,
    ) {}

    /**
     * List paginated tasks strictly isolated to the authenticated officer (BR-032).
     * Active tasks are ordered by nearest SLA deadline first.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listTasks(User $officer, array $filters): LengthAwarePaginator
    {
        $statusTab = (string) ($filters['status'] ?? 'active');

        $query = Report::query()
            ->with(self::OFFICER_RELATIONS)
            ->where('assigned_officer_id', $officer->id);

        if ($statusTab === 'history') {
            $query->whereIn('status', [
                ReportStatus::PENDING_CITIZEN_SIGN_OFF->value,
                ReportStatus::DISPUTED->value,
                ReportStatus::VERIFIED_CLOSED->value,
                ReportStatus::AUTO_VERIFIED_CLOSED->value,
                ReportStatus::CLOSED_BY_AUDIT->value,
                ReportStatus::REJECTED->value,
            ])->orderByDesc('updated_at')->orderByDesc('id');
        } else {
            $query->whereIn('status', [
                ReportStatus::IN_PROGRESS->value,
                ReportStatus::REOPENED_BY_AUDIT->value,
            ])
                ->orderByRaw('sla_deadline_at IS NULL ASC')
                ->orderBy('sla_deadline_at')
                ->orderBy('id');
        }

        return $query->paginate(20);
    }

    /**
     * Store field progress note and optional PROGRESS photos WITHOUT pausing or modifying SLA.
     *
     * @param  list<UploadedFile>  $photos
     */
    public function storeProgress(Report $report, User $officer, string $note, array $photos = []): Report
    {
        $writtenFiles = [];

        try {
            $updated = DB::transaction(function () use ($report, $officer, $note, $photos, &$writtenFiles): Report {
                $locked = Report::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();

                if (! $locked->status->isSlaRunning()) {
                    throw new InvalidStateTransitionException(
                        'Catatan progres hanya dapat ditambahkan saat tugas berstatus IN_PROGRESS atau REOPENED_BY_AUDIT.'
                    );
                }

                $nextSequence = (int) ReportPhoto::query()
                    ->where('report_id', $locked->id)
                    ->where('photo_type', PhotoType::PROGRESS->value)
                    ->where('cycle', $locked->current_cycle)
                    ->max('sequence');

                foreach (array_values($photos) as $photoFile) {
                    $nextSequence++;
                    $saved = $this->geotagValidator->processUpload(
                        report: $locked,
                        file: $photoFile,
                        type: PhotoType::PROGRESS,
                        cycle: $locked->current_cycle,
                        sequence: $nextSequence,
                        uploadedByUserId: $officer->id,
                    );
                    $writtenFiles[] = $saved->file_path;
                    $writtenFiles[] = $saved->thumbnail_path;
                }

                $sanitizedNote = $this->sanitizer->sanitize($note);

                $this->auditLogger->log(
                    actionType: AuditActionType::PROGRESS_NOTE,
                    report: $locked,
                    actor: $officer,
                    fromStatus: $locked->status,
                    toStatus: $locked->status,
                    note: $sanitizedNote,
                    metadata: [
                        'cycle' => $locked->current_cycle,
                        'photos_count' => count($photos),
                    ],
                );

                return $locked->fresh();
            });
        } catch (Throwable $e) {
            if ($writtenFiles !== []) {
                Storage::disk('public')->delete($writtenFiles);
            }

            throw $e;
        }

        return $updated->load(self::OFFICER_RELATIONS);
    }

    /**
     * Submit AFTER evidence photos, evaluate geotag warning, pause SLA, and lock Escrow (TR-07/TR-13).
     *
     * @param  list<UploadedFile>  $photos
     */
    public function submitEvidence(
        Report $report,
        User $officer,
        string $actionNote,
        array $photos,
        ?float $deviceLat = null,
        ?float $deviceLng = null,
    ): Report {
        $updated = $this->stateMachine->submitOfficerEvidence(
            report: $report,
            officer: $officer,
            actionNote: $actionNote,
            afterPhotos: $photos,
            deviceLat: $deviceLat,
            deviceLng: $deviceLng,
        );

        return $updated->load(self::OFFICER_RELATIONS);
    }
}
