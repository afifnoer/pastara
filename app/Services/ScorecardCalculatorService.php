<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditActionType;
use App\Enums\ReportStatus;
use App\Models\AuditLog;
use App\Models\Dispute;
use App\Models\OpdUnit;
use App\Models\Rating;
use App\Models\Report;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ScorecardCalculatorService
{
    /**
     * Recalculate metrics for all active OPD units and persist cache columns when unfiltered.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function recalculateAllUnits(?int $month = null, ?int $year = null): Collection
    {
        $units = OpdUnit::query()->active()->orderBy('id')->get();
        $isGlobalPeriod = ($month === null && $year === null);
        $now = CarbonImmutable::now('UTC');

        return $units->map(function (OpdUnit $unit) use ($month, $year, $isGlobalPeriod, $now): array {
            $metrics = $this->calculateForUnit($unit, $month, $year);

            if ($isGlobalPeriod) {
                $unit->update([
                    'cached_sla_compliance' => $metrics['sla_compliance_rate'],
                    'cached_physical_resolution' => $metrics['physical_resolution_rate'],
                    'cached_dispute_rate' => $metrics['dispute_rate'],
                    'cached_quality_index' => $metrics['quality_index'],
                    'cached_compliance_score' => $metrics['compliance_score'],
                    'metrics_updated_at' => $now,
                ]);
            }

            return $metrics;
        });
    }

    /**
     * Calculate all performance metrics and OPD compliance score for a single unit.
     *
     * @return array<string, mixed>
     */
    public function calculateForUnit(OpdUnit $unit, ?int $month = null, ?int $year = null): array
    {
        [$startUtc, $endUtc] = $this->resolvePeriodBounds($month, $year);

        $baseQuery = Report::query()->where('opd_unit_id', $unit->id);
        if ($startUtc !== null && $endUtc !== null) {
            $baseQuery->whereBetween('created_at', [$startUtc, $endUtc]);
        }

        $reports = $baseQuery->get([
            'id',
            'status',
            'sla_started_at',
            'signoff_started_at',
            'is_sla_overdue',
            'is_milestone_overdue',
            'is_late',
            'is_secret',
        ]);

        $rejectedCount = $reports->where('status', ReportStatus::REJECTED)->count();
        $validReports = $reports->where('status', '!=', ReportStatus::REJECTED);
        $validCount = $validReports->count();
        $budgetScheduledCount = $reports->where('status', ReportStatus::BUDGET_SCHEDULED)->count();
        $physicalClosedCount = $reports->whereIn('status', [
            ReportStatus::VERIFIED_CLOSED,
            ReportStatus::AUTO_VERIFIED_CLOSED,
        ])->count();

        if ($validCount === 0) {
            return [
                'opd_unit_id' => $unit->id,
                'code' => $unit->code,
                'name' => $unit->name,
                'level' => $unit->level->value,
                'has_data' => false,
                'valid_tickets_count' => 0,
                'rejected_count' => $rejectedCount,
                'budget_scheduled_count' => 0,
                'physical_closed_count' => 0,
                'overdue_count' => 0,
                'reopened_count' => 0,
                'secret_count' => 0,
                'sla_compliance_rate' => null,
                'physical_resolution_rate' => null,
                'dispute_rate' => null,
                'quality_index' => null,
                'compliance_score' => null,
            ];
        }

        $finalClosedStatuses = [
            ReportStatus::VERIFIED_CLOSED,
            ReportStatus::AUTO_VERIFIED_CLOSED,
            ReportStatus::CLOSED_BY_AUDIT,
        ];

        $finalOperational = $validReports->filter(
            fn (Report $r): bool => in_array($r->status, $finalClosedStatuses, true) && $r->sla_started_at !== null
        );
        $activeOverdue = $validReports->filter(
            fn (Report $r): bool => ! $r->status->isFinal() && $r->is_sla_overdue
        );

        $slaNumerator = $finalOperational->where('is_late', false)->count();
        $slaDenominator = $finalOperational->count() + $activeOverdue->count();
        $slaCompliance = $slaDenominator > 0 ? round(($slaNumerator / $slaDenominator) * 100, 2) : null;

        $physicalResolution = round(($physicalClosedCount / $validCount) * 100, 2);

        $validIds = $validReports->pluck('id')->all();
        [$everSignOffIds, $everDisputedIds] = $this->resolveSignOffAndDisputeReportIds($validReports, $validIds);

        $signOffCount = count($everSignOffIds);
        $disputedCount = count(array_intersect($everDisputedIds, $everSignOffIds));
        $disputeRate = $signOffCount > 0 ? round(($disputedCount / $signOffCount) * 100, 2) : null;

        $avgQuality = Rating::query()->whereIn('report_id', $validIds)->avg('physical_quality_score');
        $qualityIndex = $avgQuality !== null ? round((float) $avgQuality, 2) : null;

        $complianceScore = $this->calculateNormalizedComplianceScore(
            $slaCompliance,
            $physicalResolution,
            $disputeRate
        );

        $reopenedIds = AuditLog::query()
            ->whereIn('report_id', $validIds)
            ->where('action_type', AuditActionType::AUDIT_REOPENED)
            ->distinct()
            ->pluck('report_id')
            ->all();

        $reopenedFallbackIds = $validReports
            ->where('status', ReportStatus::REOPENED_BY_AUDIT)
            ->pluck('id')
            ->all();

        $reopenedCount = count(array_unique(array_merge($reopenedIds, $reopenedFallbackIds)));

        return [
            'opd_unit_id' => $unit->id,
            'code' => $unit->code,
            'name' => $unit->name,
            'level' => $unit->level->value,
            'has_data' => true,
            'valid_tickets_count' => $validCount,
            'rejected_count' => $rejectedCount,
            'budget_scheduled_count' => $budgetScheduledCount,
            'physical_closed_count' => $physicalClosedCount,
            'overdue_count' => $validReports->where('is_sla_overdue', true)->count(),
            'reopened_count' => $reopenedCount,
            'secret_count' => $validReports->where('is_secret', true)->count(),
            'sla_compliance_rate' => $slaCompliance,
            'physical_resolution_rate' => $physicalResolution,
            'dispute_rate' => $disputeRate,
            'quality_index' => $qualityIndex,
            'compliance_score' => $complianceScore,
        ];
    }

    private function calculateNormalizedComplianceScore(
        ?float $slaCompliance,
        ?float $physicalResolution,
        ?float $disputeRate,
    ): ?float {
        $components = [];

        if ($slaCompliance !== null) {
            $components[] = ['weight' => 0.50, 'value' => $slaCompliance];
        }

        if ($physicalResolution !== null) {
            $components[] = ['weight' => 0.30, 'value' => $physicalResolution];
        }

        if ($disputeRate !== null) {
            $components[] = ['weight' => 0.20, 'value' => 100.0 - $disputeRate];
        }

        if ($components === []) {
            return null;
        }

        $totalWeight = array_sum(array_column($components, 'weight'));
        $score = 0.0;

        foreach ($components as $component) {
            $score += ($component['weight'] / $totalWeight) * $component['value'];
        }

        return round($score, 2);
    }

    /**
     * @param  Collection<int, Report>  $validReports
     * @param  list<int>  $validIds
     * @return array{0: list<int>, 1: list<int>}
     */
    private function resolveSignOffAndDisputeReportIds(Collection $validReports, array $validIds): array
    {
        if ($validIds === []) {
            return [[], []];
        }

        $auditSignOffIds = AuditLog::query()
            ->whereIn('report_id', $validIds)
            ->where('to_status', ReportStatus::PENDING_CITIZEN_SIGN_OFF->value)
            ->distinct()
            ->pluck('report_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $auditDisputeIds = AuditLog::query()
            ->whereIn('report_id', $validIds)
            ->where('to_status', ReportStatus::DISPUTED->value)
            ->distinct()
            ->pluck('report_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $disputeTableIds = Dispute::query()
            ->whereIn('report_id', $validIds)
            ->distinct()
            ->pluck('report_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $columnSignOffIds = $validReports
            ->filter(fn (Report $r): bool => $r->signoff_started_at !== null
                || in_array($r->status, [
                    ReportStatus::PENDING_CITIZEN_SIGN_OFF,
                    ReportStatus::DISPUTED,
                    ReportStatus::REOPENED_BY_AUDIT,
                    ReportStatus::VERIFIED_CLOSED,
                    ReportStatus::AUTO_VERIFIED_CLOSED,
                    ReportStatus::CLOSED_BY_AUDIT,
                ], true))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $columnDisputeIds = $validReports
            ->filter(fn (Report $r): bool => $r->status === ReportStatus::DISPUTED)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $everDisputed = array_values(array_unique(array_merge($auditDisputeIds, $disputeTableIds, $columnDisputeIds)));
        $everSignOff = array_values(array_unique(array_merge($auditSignOffIds, $columnSignOffIds, $everDisputed)));

        return [$everSignOff, $everDisputed];
    }

    /**
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function resolvePeriodBounds(?int $month, ?int $year): array
    {
        if ($year === null && $month === null) {
            return [null, null];
        }

        $targetYear = $year ?? CarbonImmutable::now('Asia/Jakarta')->year;

        if ($month !== null) {
            $start = CarbonImmutable::create($targetYear, $month, 1, 0, 0, 0, 'Asia/Jakarta')->startOfMonth();
            $end = $start->endOfMonth();

            return [$start->utc(), $end->utc()];
        }

        $start = CarbonImmutable::create($targetYear, 1, 1, 0, 0, 0, 'Asia/Jakarta')->startOfYear();
        $end = $start->endOfYear();

        return [$start->utc(), $end->utc()];
    }
}
