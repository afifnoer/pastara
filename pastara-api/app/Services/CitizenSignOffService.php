<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditActionType;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\OpdUnit;
use App\Models\Rating;
use App\Models\Report;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class CitizenSignOffService
{
    /**
     * Relation graph required by CitizenReportResource without lazy loading.
     *
     * @var list<string>
     */
    private const CITIZEN_RELATIONS = [
        'reporter',
        'category',
        'opdUnit',
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
        private readonly AuditLoggerService $auditLogger,
        private readonly TextSanitizerService $sanitizer,
        private readonly ScorecardCalculatorService $scorecardCalculator,
    ) {}

    /**
     * Approve completion within 72-hour Escrow sign-off window (TR-08 -> VERIFIED_CLOSED).
     */
    public function approveSignOff(Report $report, ?User $actor = null, ?string $secretToken = null): Report
    {
        $updated = $this->stateMachine->citizenApprove($report, $actor, $secretToken);

        return $updated->load(self::CITIZEN_RELATIONS);
    }

    /**
     * Reject/veto completion claim with 1..3 counter-evidence photos (TR-09 -> DISPUTED).
     *
     * @param  list<UploadedFile>  $photos
     */
    public function rejectSignOff(
        Report $report,
        ?User $actor,
        ?string $secretToken,
        string $reason,
        array $photos,
    ): Report {
        $updated = $this->stateMachine->citizenReject($report, $actor, $secretToken, $reason, $photos);

        return $updated->load(self::CITIZEN_RELATIONS);
    }

    /**
     * Record citizen micro-rating once for a VERIFIED_CLOSED ticket and refresh OPD physical quality index.
     *
     * @return array<string, mixed>
     */
    public function storeRating(
        Report $report,
        ?User $actor,
        ?string $secretToken,
        int $speedScore,
        int $physicalQualityScore,
        ?string $comment = null,
    ): array {
        try {
            return DB::transaction(function () use (
                $report,
                $actor,
                $secretToken,
                $speedScore,
                $physicalQualityScore,
                $comment,
            ): array {
                $locked = Report::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();

                $isReporter = false;
                if ($actor !== null) {
                    $freshActor = User::query()->whereKey($actor->id)->first();
                    if (
                        $freshActor !== null
                        && $freshActor->is_active
                        && $freshActor->role === UserRole::WARGA
                        && (int) $freshActor->id === (int) $locked->reporter_id
                    ) {
                        $isReporter = true;
                        $actor = $freshActor;
                    }
                }

                $authorizedByToken = ! $isReporter && $locked->is_secret && $locked->verifySecretToken($secretToken);
                if (! $isReporter && ! $authorizedByToken) {
                    throw new AuthorizationException('Anda tidak memiliki izin untuk memberi penilaian pada laporan ini.');
                }

                if ($locked->status !== ReportStatus::VERIFIED_CLOSED) {
                    throw new InvalidStateTransitionException(
                        'Penilaian hanya dapat diberikan pada laporan yang telah dikonfirmasi tuntas oleh warga.'
                    );
                }

                if (Rating::query()->where('report_id', $locked->id)->exists()) {
                    throw new InvalidStateTransitionException(
                        'Penilaian untuk laporan ini sudah pernah diberikan.'
                    );
                }

                $now = CarbonImmutable::now('UTC');
                $rawComment = $comment !== null ? trim($comment) : '';
                $sanitizedComment = $rawComment !== ''
                    ? mb_substr($this->sanitizer->sanitize($rawComment), 0, 500)
                    : null;

                $rating = Rating::create([
                    'report_id' => $locked->id,
                    'user_id' => $locked->reporter_id,
                    'speed_score' => $speedScore,
                    'physical_quality_score' => $physicalQualityScore,
                    'comment' => $sanitizedComment,
                    'created_at' => $now,
                ]);

                $this->auditLogger->log(
                    actionType: AuditActionType::RATED,
                    report: $locked,
                    actor: $isReporter ? $actor : null,
                    fromStatus: ReportStatus::VERIFIED_CLOSED,
                    toStatus: ReportStatus::VERIFIED_CLOSED,
                    note: $sanitizedComment ?? 'Pelapor memberikan penilaian kecepatan dan mutu fisik perbaikan.',
                    metadata: [
                        'speed_score' => $speedScore,
                        'physical_quality_score' => $physicalQualityScore,
                    ],
                    actorRoleOverride: 'WARGA',
                );

                $unitQualityIndex = null;
                $unit = OpdUnit::query()->whereKey($locked->opd_unit_id)->first();
                if ($unit !== null) {
                    $metrics = $this->scorecardCalculator->calculateForUnit($unit);
                    $unitQualityIndex = $metrics['quality_index'] ?? null;
                    $unit->update([
                        'cached_quality_index' => $unitQualityIndex,
                        'metrics_updated_at' => $now,
                    ]);
                }

                return [
                    'id' => $rating->id,
                    'report_id' => $locked->id,
                    'ticket_code' => $locked->ticket_code,
                    'speed_score' => $rating->speed_score,
                    'physical_quality_score' => $rating->physical_quality_score,
                    'comment' => $rating->comment,
                    'unit_quality_index' => $unitQualityIndex,
                    'created_at' => $rating->created_at?->toIso8601ZuluString(),
                ];
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvalidStateTransitionException('Penilaian untuk laporan ini sudah pernah diberikan.');
        }
    }
}
