<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditActionType;
use App\Enums\UserRole;
use App\Models\Report;
use App\Models\ReportUpvote;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CitizenReportService
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
        private readonly HaversineDuplicateService $duplicateService,
        private readonly ReportStateMachineService $stateMachine,
        private readonly AuditLoggerService $auditLogger,
    ) {}

    /**
     * Detect nearby active non-secret duplicate tickets using dynamic category radius.
     *
     * @return array{radius_m: int, matches: list<array<string, mixed>>}
     */
    public function findNearbyDuplicates(int $categoryId, float $lat, float $lng): array
    {
        return $this->duplicateService->findNearbyDuplicates($categoryId, $lat, $lng);
    }

    /**
     * Submit a new geospatial report via ReportStateMachineService (TR-01).
     *
     * @param  array<string, mixed>  $payload
     * @param  list<UploadedFile>  $photos
     * @return array<string, mixed>
     */
    public function createReport(User $reporter, array $payload, array $photos): array
    {
        $result = $this->stateMachine->createAndRoute($reporter, $payload, $photos);
        $report = $result['report'];

        $response = [
            'id' => $report->id,
            'ticket_code' => $report->ticket_code,
            'status' => $report->status->value,
            'status_label' => $report->status->label(),
            'is_secret' => $report->is_secret,
            'opd_unit' => $report->opdUnit !== null ? [
                'id' => $report->opdUnit->id,
                'code' => $report->opdUnit->code,
                'name' => $report->opdUnit->name,
            ] : null,
        ];

        if ($result['secret_token'] !== null) {
            $response['secret_token'] = $result['secret_token'];
        }

        return $response;
    }

    /**
     * Atomically record a +1 citizen upvote, enable watching, increment counter, and log UPVOTED audit.
     *
     * @return array<string, mixed>
     */
    public function upvoteReport(Report $report, User $citizen): array
    {
        try {
            return DB::transaction(function () use ($report, $citizen): array {
                $locked = Report::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();

                $freshCitizen = User::query()->whereKey($citizen->id)->first();
                if (
                    $freshCitizen === null
                    || ! $freshCitizen->is_active
                    || $freshCitizen->role !== UserRole::WARGA
                    || $locked->is_secret
                    || (int) $locked->reporter_id === (int) $freshCitizen->id
                    || $locked->status->isFinal()
                ) {
                    throw new AuthorizationException('Anda tidak diizinkan memberi dukungan pada laporan ini.');
                }

                $alreadyUpvoted = ReportUpvote::query()
                    ->where('report_id', $locked->id)
                    ->where('user_id', $freshCitizen->id)
                    ->exists();

                if ($alreadyUpvoted) {
                    throw new HttpException(409, 'Anda sudah memberikan dukungan pada laporan ini.');
                }

                ReportUpvote::create([
                    'report_id' => $locked->id,
                    'user_id' => $freshCitizen->id,
                    'is_watching' => true,
                    'created_at' => CarbonImmutable::now('UTC'),
                ]);

                $locked->increment('upvote_count');
                $locked->refresh();

                $this->auditLogger->log(
                    actionType: AuditActionType::UPVOTED,
                    report: $locked,
                    actor: $freshCitizen,
                    fromStatus: $locked->status,
                    toStatus: $locked->status,
                    note: 'Warga memberikan dukungan (+1 Upvote) dan memantau tiket.',
                );

                return [
                    'report_id' => $locked->id,
                    'ticket_code' => $locked->ticket_code,
                    'upvote_count' => $locked->upvote_count,
                    'is_watching' => true,
                ];
            });
        } catch (UniqueConstraintViolationException) {
            throw new HttpException(409, 'Anda sudah memberikan dukungan pada laporan ini.');
        }
    }

    /**
     * Toggle notification watch preference for a report the citizen has previously upvoted.
     *
     * @return array<string, mixed>
     */
    public function updateWatchStatus(Report $report, User $citizen, bool $isWatching): array
    {
        $upvote = ReportUpvote::query()
            ->where('report_id', $report->id)
            ->where('user_id', $citizen->id)
            ->firstOrFail();

        $upvote->update(['is_watching' => $isWatching]);

        return [
            'report_id' => $report->id,
            'ticket_code' => $report->ticket_code,
            'is_watching' => $isWatching,
        ];
    }

    /**
     * List paginated reports owned by or watched by the authenticated citizen.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listCitizenReports(User $citizen, array $filters): LengthAwarePaginator
    {
        $tab = (string) ($filters['tab'] ?? 'mine');

        $query = Report::query()
            ->with(self::CITIZEN_RELATIONS)
            ->withExists([
                'upvotes as has_upvoted' => static fn (Builder $q): Builder => $q->where('user_id', $citizen->id),
            ]);

        if ($tab === 'watching') {
            $query->where('is_secret', false)
                ->whereHas(
                    'upvotes',
                    static fn (Builder $q): Builder => $q->where('user_id', $citizen->id)->where('is_watching', true)
                );
        } else {
            $query->where('reporter_id', $citizen->id);
        }

        if (isset($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate(20);
    }
}
