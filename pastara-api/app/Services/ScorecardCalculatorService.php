<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditActionType;
use App\Enums\ReportStatus;
use App\Models\OpdUnit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ScorecardCalculatorService
{
    /**
     * Calculate metrics for all active OPD units (strictly read-only, zero DB writes).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function calculateAllUnits(?int $month = null, ?int $year = null): Collection
    {
        $units = OpdUnit::query()->active()->orderBy('id')->get(['id', 'code', 'name', 'level']);
        if ($units->isEmpty()) {
            return collect();
        }

        return $this->computeMetricsForUnits($units, $month, $year);
    }

    /**
     * Recalculate metrics for all active OPD units and persist cache columns when unfiltered.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function recalculateAllUnits(?int $month = null, ?int $year = null): Collection
    {
        $units = OpdUnit::query()->active()->orderBy('id')->get();
        if ($units->isEmpty()) {
            return collect();
        }

        $results = $this->computeMetricsForUnits($units, $month, $year);
        $isGlobalPeriod = ($month === null && $year === null);

        if ($isGlobalPeriod) {
            $now = CarbonImmutable::now('UTC');
            $metricsByUnitId = $results->keyBy('opd_unit_id');

            foreach ($units as $unit) {
                $metrics = $metricsByUnitId->get($unit->id);
                if ($metrics === null) {
                    continue;
                }

                $unit->update([
                    'cached_sla_compliance' => $metrics['sla_compliance_rate'],
                    'cached_physical_resolution' => $metrics['physical_resolution_rate'],
                    'cached_dispute_rate' => $metrics['dispute_rate'],
                    'cached_quality_index' => $metrics['quality_index'],
                    'cached_compliance_score' => $metrics['compliance_score'],
                    'metrics_updated_at' => $now,
                ]);
            }
        }

        return $results;
    }

    /**
     * Calculate all performance metrics and OPD compliance score for a single unit.
     *
     * @return array<string, mixed>
     */
    public function calculateForUnit(OpdUnit $unit, ?int $month = null, ?int $year = null): array
    {
        return $this->computeMetricsForUnits(collect([$unit]), $month, $year)->first();
    }

    /**
     * Resolve UTC period boundaries matching Asia/Jakarta calendar months/years.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public function resolvePeriodBounds(?int $month, ?int $year): array
    {
        if ($month !== null && ($month < 1 || $month > 12)) {
            throw new InvalidArgumentException("Bulan periode tidak valid: {$month}. Harus antara 1 hingga 12.");
        }

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

    /**
     * Compute scorecard metrics for a collection of OPD units using constant grouped SQL queries.
     *
     * @param  Collection<int, OpdUnit>  $units
     * @return Collection<int, array<string, mixed>>
     */
    private function computeMetricsForUnits(Collection $units, ?int $month, ?int $year): Collection
    {
        [$startUtc, $endUtc] = $this->resolvePeriodBounds($month, $year);
        $unitIds = $units->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        $reportAggregates = DB::table('reports')
            ->whereIn('opd_unit_id', $unitIds)
            ->when(
                $startUtc !== null && $endUtc !== null,
                fn ($query) => $query->whereBetween('created_at', [$startUtc, $endUtc])
            )
            ->groupBy('opd_unit_id')
            ->select('opd_unit_id')
            ->selectRaw('COUNT(*) FILTER (WHERE status != ?) AS valid_tickets_count', [ReportStatus::REJECTED->value])
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS rejected_count', [ReportStatus::REJECTED->value])
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS budget_scheduled_count', [ReportStatus::BUDGET_SCHEDULED->value])
            ->selectRaw('COUNT(*) FILTER (WHERE status IN (?, ?)) AS physical_closed_count', [
                ReportStatus::VERIFIED_CLOSED->value,
                ReportStatus::AUTO_VERIFIED_CLOSED->value,
            ])
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS closed_by_audit_count', [ReportStatus::CLOSED_BY_AUDIT->value])
            ->selectRaw('COUNT(*) FILTER (WHERE status IN (?, ?, ?) AND sla_started_at IS NOT NULL) AS final_op_total', [
                ReportStatus::VERIFIED_CLOSED->value,
                ReportStatus::AUTO_VERIFIED_CLOSED->value,
                ReportStatus::CLOSED_BY_AUDIT->value,
            ])
            ->selectRaw('COUNT(*) FILTER (WHERE status IN (?, ?, ?) AND sla_started_at IS NOT NULL AND is_late = FALSE) AS final_op_ontime', [
                ReportStatus::VERIFIED_CLOSED->value,
                ReportStatus::AUTO_VERIFIED_CLOSED->value,
                ReportStatus::CLOSED_BY_AUDIT->value,
            ])
            ->selectRaw('COUNT(*) FILTER (WHERE status NOT IN (?, ?, ?, ?) AND is_sla_overdue = TRUE) AS active_sla_overdue_count', [
                ReportStatus::REJECTED->value,
                ReportStatus::VERIFIED_CLOSED->value,
                ReportStatus::AUTO_VERIFIED_CLOSED->value,
                ReportStatus::CLOSED_BY_AUDIT->value,
            ])
            ->selectRaw('COUNT(*) FILTER (WHERE status NOT IN (?, ?, ?, ?) AND (is_sla_overdue = TRUE OR is_milestone_overdue = TRUE)) AS overdue_count', [
                ReportStatus::REJECTED->value,
                ReportStatus::VERIFIED_CLOSED->value,
                ReportStatus::AUTO_VERIFIED_CLOSED->value,
                ReportStatus::CLOSED_BY_AUDIT->value,
            ])
            ->selectRaw('COUNT(*) FILTER (WHERE status != ? AND is_secret = TRUE) AS secret_count', [ReportStatus::REJECTED->value])
            ->selectRaw(
                'COUNT(*) FILTER (WHERE status != ? AND (status = ? OR EXISTS (SELECT 1 FROM disputes d WHERE d.report_id = reports.id) OR EXISTS (SELECT 1 FROM audit_logs al WHERE al.report_id = reports.id AND al.to_status = ?))) AS ever_disputed_count',
                [
                    ReportStatus::REJECTED->value,
                    ReportStatus::DISPUTED->value,
                    ReportStatus::DISPUTED->value,
                ]
            )
            ->selectRaw(
                'COUNT(*) FILTER (WHERE status != ? AND (signoff_started_at IS NOT NULL OR status IN (?, ?, ?, ?, ?, ?) OR EXISTS (SELECT 1 FROM audit_logs al WHERE al.report_id = reports.id AND al.to_status IN (?, ?)) OR EXISTS (SELECT 1 FROM disputes d WHERE d.report_id = reports.id))) AS ever_signoff_count',
                [
                    ReportStatus::REJECTED->value,
                    ReportStatus::PENDING_CITIZEN_SIGN_OFF->value,
                    ReportStatus::DISPUTED->value,
                    ReportStatus::REOPENED_BY_AUDIT->value,
                    ReportStatus::VERIFIED_CLOSED->value,
                    ReportStatus::AUTO_VERIFIED_CLOSED->value,
                    ReportStatus::CLOSED_BY_AUDIT->value,
                    ReportStatus::PENDING_CITIZEN_SIGN_OFF->value,
                    ReportStatus::DISPUTED->value,
                ]
            )
            ->selectRaw(
                'COUNT(*) FILTER (WHERE status != ? AND (status = ? OR EXISTS (SELECT 1 FROM audit_logs al WHERE al.report_id = reports.id AND al.action_type = ?))) AS reopened_count',
                [
                    ReportStatus::REJECTED->value,
                    ReportStatus::REOPENED_BY_AUDIT->value,
                    AuditActionType::AUDIT_REOPENED->value,
                ]
            )
            ->get()
            ->keyBy('opd_unit_id');

        $qualityAggregates = DB::table('ratings')
            ->join('reports', 'ratings.report_id', '=', 'reports.id')
            ->whereIn('reports.opd_unit_id', $unitIds)
            ->where('reports.status', '!=', ReportStatus::REJECTED->value)
            ->when(
                $startUtc !== null && $endUtc !== null,
                fn ($query) => $query->whereBetween('reports.created_at', [$startUtc, $endUtc])
            )
            ->groupBy('reports.opd_unit_id')
            ->select('reports.opd_unit_id')
            ->selectRaw('AVG(ratings.physical_quality_score) AS avg_quality')
            ->get()
            ->keyBy('opd_unit_id');

        return $units->map(function (OpdUnit $unit) use ($reportAggregates, $qualityAggregates): array {
            $row = $reportAggregates->get($unit->id);
            $validCount = (int) ($row->valid_tickets_count ?? 0);
            $rejectedCount = (int) ($row->rejected_count ?? 0);

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
                    'closed_by_audit_count' => 0,
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

            $budgetScheduledCount = (int) ($row->budget_scheduled_count ?? 0);
            $physicalClosedCount = (int) ($row->physical_closed_count ?? 0);
            $closedByAuditCount = (int) ($row->closed_by_audit_count ?? 0);
            $finalOpTotal = (int) ($row->final_op_total ?? 0);
            $finalOpOnTime = (int) ($row->final_op_ontime ?? 0);
            $activeSlaOverdueCount = (int) ($row->active_sla_overdue_count ?? 0);
            $overdueCount = (int) ($row->overdue_count ?? 0);
            $everSignOffCount = (int) ($row->ever_signoff_count ?? 0);
            $everDisputedCount = (int) ($row->ever_disputed_count ?? 0);
            $reopenedCount = (int) ($row->reopened_count ?? 0);
            $secretCount = (int) ($row->secret_count ?? 0);

            $slaDenominator = $finalOpTotal + $activeSlaOverdueCount;
            $slaCompliance = $slaDenominator > 0 ? round(($finalOpOnTime / $slaDenominator) * 100, 2) : null;
            $physicalResolution = round(($physicalClosedCount / $validCount) * 100, 2);
            $disputeRate = $everSignOffCount > 0 ? round(($everDisputedCount / $everSignOffCount) * 100, 2) : null;

            $qualityRow = $qualityAggregates->get($unit->id);
            $qualityIndex = ($qualityRow !== null && $qualityRow->avg_quality !== null)
                ? round((float) $qualityRow->avg_quality, 2)
                : null;

            $complianceScore = $this->calculateNormalizedComplianceScore(
                $slaCompliance,
                $physicalResolution,
                $disputeRate
            );

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
                'closed_by_audit_count' => $closedByAuditCount,
                'overdue_count' => $overdueCount,
                'reopened_count' => $reopenedCount,
                'secret_count' => $secretCount,
                'sla_compliance_rate' => $slaCompliance,
                'physical_resolution_rate' => $physicalResolution,
                'dispute_rate' => $disputeRate,
                'quality_index' => $qualityIndex,
                'compliance_score' => $complianceScore,
            ];
        });
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
}
