<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditActionType;
use App\Enums\NotificationType;
use App\Enums\PhotoType;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Report;
use App\Models\ReportPhoto;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
        private readonly NotificationDispatcherService $notifier,
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

                $freshOfficer = User::query()->whereKey($officer->id)->first();
                if ($freshOfficer === null || ! $freshOfficer->is_active || $freshOfficer->role !== UserRole::PETUGAS) {
                    throw new AuthorizationException('Anda tidak memiliki izin untuk memperbarui progres tugas.');
                }

                if ((int) $locked->assigned_officer_id !== (int) $freshOfficer->id) {
                    throw new NotFoundHttpException('Data tidak ditemukan.');
                }

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
                        uploadedByUserId: $freshOfficer->id,
                    );
                    $writtenFiles[] = $saved->file_path;
                    $writtenFiles[] = $saved->thumbnail_path;
                }

                $sanitizedNote = $this->sanitizer->sanitize($note);

                $this->auditLogger->log(
                    actionType: AuditActionType::PROGRESS_NOTE,
                    report: $locked,
                    actor: $freshOfficer,
                    fromStatus: $locked->status,
                    toStatus: $locked->status,
                    note: $sanitizedNote,
                    metadata: [
                        'cycle' => $locked->current_cycle,
                        'photos_count' => count($photos),
                    ],
                );

                $this->notifier->notifyUsers(
                    $this->notifier->getReporterAndWatcherIds($locked),
                    NotificationType::REPORT_ASSIGNED,
                    $locked,
                    "Pembaruan progres lapangan untuk tiket {$locked->ticket_code}: {$sanitizedNote}",
                    'Pembaruan Progres Lapangan'
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
