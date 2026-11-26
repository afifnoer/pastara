<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ReportStatus;
use App\Models\Dispute;
use App\Models\Report;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class InspectorateService
{
    /**
     * Full relation graph required by AuditReportResource without lazy loading.
     *
     * @var list<string>
     */
    private const AUDIT_RELATIONS = [
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
        private readonly ScorecardCalculatorService $scorecardCalculator,
    ) {}

    /**
     * List cross-OPD oversight reports (including secret tickets with full reporter identity for Inspectorate).
     *
     * @param  array<string, mixed>  $filters
     */
    public function listReports(array $filters): LengthAwarePaginator
    {
        $tab = (string) ($filters['tab'] ?? 'all');

        $query = Report::query()->with(self::AUDIT_RELATIONS);

        if ($tab === 'sla_overdue') {
            $query->whereNotIn('status', [
                ReportStatus::REJECTED->value,
                ReportStatus::VERIFIED_CLOSED->value,
                ReportStatus::AUTO_VERIFIED_CLOSED->value,
                ReportStatus::CLOSED_BY_AUDIT->value,
            ])->where('is_sla_overdue', true)
                ->orderBy('sla_deadline_at')
                ->orderBy('id');
        } elseif ($tab === 'milestone_overdue') {
            $query->where('status', ReportStatus::BUDGET_SCHEDULED->value)
                ->where('is_milestone_overdue', true)
                ->orderByDesc('created_at')
                ->orderByDesc('id');
        } elseif ($tab === 'disputed') {
            $query->where('status', ReportStatus::DISPUTED->value)
                ->orderBy('updated_at')
                ->orderBy('id');
        } else {
            $query->orderByDesc('created_at')->orderByDesc('id');
        }

        if (isset($filters['opd_unit_id'])) {
            $query->where('opd_unit_id', (int) $filters['opd_unit_id']);
        }

        if (isset($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        return $query->paginate(20);
    }

    /**
     * Execute Inspectorate dispute verdict (TR-11 REOPENED or TR-12 CLOSED) via ReportStateMachineService.
     */
    public function resolveDispute(
        Dispute $dispute,
        User $auditor,
        string $decision,
        string $auditNotes,
        ?int $reopenSlaHours = null,
    ): Report {
        $updated = $this->stateMachine->resolveDispute(
            dispute: $dispute,
            auditor: $auditor,
            decision: $decision,
            auditNotes: $auditNotes,
            reopenSlaHours: $reopenSlaHours,
        );

        return $updated->load(self::AUDIT_RELATIONS);
    }

    /**
     * Compute internal cross-OPD scorecard for Inspectorate (strictly read-only, includes secret_count).
     *
     * @return array<string, mixed>
     */
    public function getInternalScorecard(?int $month, ?int $year, ?string $sort = null): array
    {
        [$startUtc, $endUtc] = $this->scorecardCalculator->resolvePeriodBounds($month, $year);

        $summaryRow = Report::query()
            ->when(
                $startUtc !== null && $endUtc !== null,
                fn (Builder $q): Builder => $q->whereBetween('created_at', [$startUtc, $endUtc])
            )
            ->selectRaw('COUNT(*) AS total_reports')
            ->selectRaw('COUNT(*) FILTER (WHERE is_secret = TRUE) AS secret_reports')
            ->selectRaw('COUNT(*) FILTER (WHERE status != ?) AS valid_tickets', [ReportStatus::REJECTED->value])
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS rejected_tickets', [ReportStatus::REJECTED->value])
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS budget_scheduled_apbd', [ReportStatus::BUDGET_SCHEDULED->value])
            ->selectRaw('COUNT(*) FILTER (WHERE status IN (?, ?)) AS verified_closed_physical', [
                ReportStatus::VERIFIED_CLOSED->value,
                ReportStatus::AUTO_VERIFIED_CLOSED->value,
            ])
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS closed_by_audit', [ReportStatus::CLOSED_BY_AUDIT->value])
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS disputed_reports', [ReportStatus::DISPUTED->value])
            ->selectRaw('COUNT(*) FILTER (WHERE status NOT IN (?, ?, ?, ?) AND is_sla_overdue = TRUE) AS sla_overdue_reports', [
                ReportStatus::REJECTED->value,
                ReportStatus::VERIFIED_CLOSED->value,
                ReportStatus::AUTO_VERIFIED_CLOSED->value,
                ReportStatus::CLOSED_BY_AUDIT->value,
            ])
            ->selectRaw('COUNT(*) FILTER (WHERE status = ? AND is_milestone_overdue = TRUE) AS milestone_overdue_reports', [
                ReportStatus::BUDGET_SCHEDULED->value,
            ])
            ->first();

        $units = $this->scorecardCalculator->calculateAllUnits($month, $year);

        $sortedUnits = match ($sort ?? 'compliance_asc') {
            'compliance_desc' => $units->sortByDesc(static fn (array $u): float => (float) ($u['compliance_score'] ?? -1)),
            'overdue_desc' => $units->sortByDesc(static fn (array $u): int => (int) ($u['overdue_count'] ?? 0)),
            'dispute_desc' => $units->sortByDesc(static fn (array $u): float => (float) ($u['dispute_rate'] ?? -1)),
            'sla_asc' => $units->sortBy(static fn (array $u): float => (float) ($u['sla_compliance_rate'] ?? 999)),
            'name_asc' => $units->sortBy('name'),
            default => $units->sortBy(static fn (array $u): float => (float) ($u['compliance_score'] ?? 999)),
        };

        $resolvedYear = $year ?? ($month !== null ? CarbonImmutable::now('Asia/Jakarta')->year : null);

        return [
            'period' => [
                'month' => $month,
                'year' => $resolvedYear,
                'timezone' => 'Asia/Jakarta',
            ],
            'summary' => [
                'total_reports' => (int) ($summaryRow->total_reports ?? 0),
                'secret_reports' => (int) ($summaryRow->secret_reports ?? 0),
                'valid_tickets' => (int) ($summaryRow->valid_tickets ?? 0),
                'rejected_tickets' => (int) ($summaryRow->rejected_tickets ?? 0),
                'budget_scheduled_apbd' => (int) ($summaryRow->budget_scheduled_apbd ?? 0),
                'verified_closed_physical' => (int) ($summaryRow->verified_closed_physical ?? 0),
                'closed_by_audit' => (int) ($summaryRow->closed_by_audit ?? 0),
                'disputed_reports' => (int) ($summaryRow->disputed_reports ?? 0),
                'sla_overdue_reports' => (int) ($summaryRow->sla_overdue_reports ?? 0),
                'milestone_overdue_reports' => (int) ($summaryRow->milestone_overdue_reports ?? 0),
            ],
            'opd_scorecard' => $sortedUnits->values()->all(),
        ];
    }
}
