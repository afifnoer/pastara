<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditActionType;
use App\Enums\NotificationType;
use App\Enums\RejectionReasonCode;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Report;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UnitReportService
{
    /**
     * Full relation graph needed by UnitReportResource without lazy loading.
     *
     * @var list<string>
     */
    private const UNIT_RELATIONS = [
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
        private readonly AuditLoggerService $auditLogger,
        private readonly NotificationDispatcherService $notifier,
        private readonly TextSanitizerService $sanitizer,
    ) {}

    /**
     * List paginated reports strictly isolated to the Admin Unit's opd_unit_id (BR-031, SR-007).
     *
     * @param  array<string, mixed>  $filters
     */
    public function listUnitReports(User $admin, array $filters): LengthAwarePaginator
    {
        $query = Report::query()
            ->with(self::UNIT_RELATIONS)
            ->where('opd_unit_id', (int) $admin->opd_unit_id);

        if (! empty($filters['tab'])) {
            $statuses = match ((string) $filters['tab']) {
                'verification' => [ReportStatus::ROUTED_TO_UNIT->value],
                'in_progress' => [ReportStatus::IN_PROGRESS->value, ReportStatus::REOPENED_BY_AUDIT->value],
                'apbd' => [ReportStatus::BUDGET_SCHEDULED->value],
                'sign_off' => [ReportStatus::PENDING_CITIZEN_SIGN_OFF->value],
                'disputed' => [ReportStatus::DISPUTED->value],
                'closed' => [
                    ReportStatus::VERIFIED_CLOSED->value,
                    ReportStatus::AUTO_VERIFIED_CLOSED->value,
                    ReportStatus::CLOSED_BY_AUDIT->value,
                ],
                'rejected' => [ReportStatus::REJECTED->value],
                default => [],
            };

            if ($statuses !== []) {
                $query->whereIn('status', $statuses);
            }
        }

        if (isset($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        if (filter_var($filters['overdue_only'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereNotIn('status', [
                ReportStatus::REJECTED->value,
                ReportStatus::VERIFIED_CLOSED->value,
                ReportStatus::AUTO_VERIFIED_CLOSED->value,
                ReportStatus::CLOSED_BY_AUDIT->value,
            ])->where(static function (Builder $sub): void {
                $sub->where('is_sla_overdue', true)
                    ->orWhere('is_milestone_overdue', true);
            });
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate(20);
    }

    /**
     * Load UnitReportResource relation graph AFTER policy authorization passes.
     */
    public function loadUnitDetail(Report $report): Report
    {
        return $report->load(self::UNIT_RELATIONS);
    }

    /**
     * List active field officers belonging to the Admin Unit with active task counts.
     *
     * @return list<array<string, mixed>>
     */
    public function listUnitOfficers(User $admin): array
    {
        $activeTaskStatuses = [
            ReportStatus::IN_PROGRESS->value,
            ReportStatus::REOPENED_BY_AUDIT->value,
            ReportStatus::PENDING_CITIZEN_SIGN_OFF->value,
            ReportStatus::DISPUTED->value,
        ];

        return User::query()
            ->where('role', UserRole::PETUGAS->value)
            ->where('opd_unit_id', (int) $admin->opd_unit_id)
            ->where('is_active', true)
            ->withCount([
                'assignedTasks as active_tasks_count' => static fn (Builder $q): Builder => $q->whereIn('status', $activeTaskStatuses),
            ])
            ->orderBy('active_tasks_count')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone'])
            ->map(static fn (User $officer): array => [
                'id' => $officer->id,
                'name' => $officer->name,
                'email' => $officer->email,
                'phone' => $officer->phone,
                'active_tasks_count' => (int) $officer->active_tasks_count,
            ])
            ->values()
            ->all();
    }

    /**
     * Approve Track 1 (Operational) and assign a same-unit active field officer (TR-04).
     */
    public function assignOperational(Report $report, User $admin, int $officerId, ?string $note = null): Report
    {
        $officer = User::query()->findOrFail($officerId);
        $updated = $this->stateMachine->assignOperational($report, $admin, $officer, $note);

        return $updated->load(self::UNIT_RELATIONS);
    }

    /**
     * Reassign active field officer without modifying SLA deadline (BR-030, AC-037).
     */
    public function reassignOfficer(Report $report, User $admin, int $newOfficerId, string $reason): Report
    {
        $updated = DB::transaction(function () use ($report, $admin, $newOfficerId, $reason): Report {
            $locked = Report::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isSlaRunning()) {
                throw new InvalidStateTransitionException(
                    'Petugas hanya dapat diganti saat tiket berstatus IN_PROGRESS atau REOPENED_BY_AUDIT.'
                );
            }

            if ((int) $locked->assigned_officer_id === $newOfficerId) {
                throw ValidationException::withMessages([
                    'officer_id' => ['Petugas pengganti harus berbeda dari petugas saat ini.'],
                ]);
            }

            $newOfficer = User::query()->whereKey($newOfficerId)->lockForUpdate()->first();
            if (
                $newOfficer === null
                || $newOfficer->role !== UserRole::PETUGAS
                || ! $newOfficer->is_active
                || (int) $newOfficer->opd_unit_id !== (int) $locked->opd_unit_id
            ) {
                throw ValidationException::withMessages([
                    'officer_id' => ['Petugas pengganti harus berstatus aktif dan berasal dari unit kerja yang sama.'],
                ]);
            }

            $previousOfficerId = $locked->assigned_officer_id;
            $sanitizedReason = $this->sanitizer->sanitize($reason);

            // Strictly preserve sla_deadline_at, sla_started_at, and sla_cycle_started_at (BR-030)
            $locked->update([
                'assigned_officer_id' => $newOfficer->id,
            ]);

            $this->auditLogger->log(
                actionType: AuditActionType::OFFICER_CHANGED,
                report: $locked,
                actor: $admin,
                fromStatus: $locked->status,
                toStatus: $locked->status,
                note: $sanitizedReason,
                metadata: [
                    'from_officer_id' => $previousOfficerId,
                    'to_officer_id' => $newOfficer->id,
                ],
            );

            $recipients = [$newOfficer->id];
            if ($previousOfficerId !== null) {
                $recipients[] = (int) $previousOfficerId;
            }

            $this->notifier->notifyUsers(
                $recipients,
                NotificationType::REPORT_ASSIGNED,
                $locked,
                "Penugasan lapangan untuk tiket {$locked->ticket_code} telah diperbarui oleh Admin Unit."
            );

            return $locked->fresh();
        });

        return $updated->load(self::UNIT_RELATIONS);
    }

    /**
     * Reject report during initial unit verification (TR-03).
     */
    public function rejectReport(Report $report, User $admin, RejectionReasonCode $reasonCode, string $note): Report
    {
        $updated = $this->stateMachine->reject($report, $admin, $reasonCode, $note);

        return $updated->load(self::UNIT_RELATIONS);
    }

    /**
     * Reroute report to another active unit (TR-02), optionally recalculating category snapshots.
     */
    public function rerouteReport(
        Report $report,
        User $admin,
        int $targetUnitId,
        ?int $newCategoryId,
        string $note,
    ): Report {
        $updated = $this->stateMachine->reroute($report, $admin, $targetUnitId, $newCategoryId, $note);

        return $updated->load(self::UNIT_RELATIONS);
    }
}
