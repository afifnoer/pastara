<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ReportStatus;
use App\Enums\SlaZone;
use App\Models\Report;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class SlaEngineService
{
    /**
     * Calculate the current SLA zone for a report using server UTC time.
     */
    public function calculateZone(Report $report): SlaZone
    {
        if ($report->status->isFinal()) {
            return SlaZone::STOPPED;
        }

        if ($report->status === ReportStatus::BUDGET_SCHEDULED) {
            return SlaZone::APBD_SCHEDULED;
        }

        if (in_array($report->status, [ReportStatus::PENDING_CITIZEN_SIGN_OFF, ReportStatus::DISPUTED], true)) {
            return SlaZone::PAUSED;
        }

        if (! $report->status->isSlaRunning()) {
            return SlaZone::STOPPED;
        }

        $ratio = $this->calculateRatio($report);
        if ($ratio === null) {
            return SlaZone::GREEN;
        }

        if ($ratio >= 1.0) {
            return SlaZone::RED;
        }

        if ($ratio >= 0.70) {
            return SlaZone::YELLOW;
        }

        return SlaZone::GREEN;
    }

    /**
     * Calculate active SLA cycle elapsed ratio: (now - sla_cycle_started_at) / (sla_deadline_at - sla_cycle_started_at).
     */
    public function calculateRatio(Report $report): ?float
    {
        if (! $report->status->isSlaRunning()) {
            return null;
        }

        if ($report->sla_cycle_started_at === null || $report->sla_deadline_at === null) {
            return null;
        }

        $totalSeconds = $report->sla_cycle_started_at->diffInSeconds($report->sla_deadline_at, false);
        if ($totalSeconds <= 0) {
            return 1.0;
        }

        $elapsedSeconds = $report->sla_cycle_started_at->diffInSeconds(now('UTC'), false);

        return max(0.0, $elapsedSeconds / $totalSeconds);
    }

    /**
     * Remaining seconds on active SLA (or frozen remaining seconds when paused in Escrow).
     */
    public function remainingSeconds(Report $report): ?int
    {
        if ($report->sla_deadline_at === null) {
            return null;
        }

        if ($report->status->isSlaRunning()) {
            return (int) round(now('UTC')->diffInSeconds($report->sla_deadline_at, false));
        }

        if (
            in_array($report->status, [ReportStatus::PENDING_CITIZEN_SIGN_OFF, ReportStatus::DISPUTED], true)
            && $report->sla_paused_at !== null
        ) {
            return (int) round($report->sla_paused_at->diffInSeconds($report->sla_deadline_at, false));
        }

        return null;
    }

    /**
     * Remaining seconds in the 72-hour citizen sign-off window.
     */
    public function signoffRemainingSeconds(Report $report): ?int
    {
        if ($report->status !== ReportStatus::PENDING_CITIZEN_SIGN_OFF || $report->signoff_deadline_at === null) {
            return null;
        }

        return (int) round(now('UTC')->diffInSeconds($report->signoff_deadline_at, false));
    }

    /**
     * Calculate APBD proposal/RKA document deadline: N weekdays (Mon-Fri) in Asia/Jakarta, end of day UTC.
     */
    public function calculateApbdDocumentDueAt(CarbonInterface $scheduledAt, ?int $weekdays = null): CarbonImmutable
    {
        $targetWeekdays = $weekdays ?? (int) config('pastara.apbd_doc_due_weekdays', 7);
        $cursor = CarbonImmutable::instance($scheduledAt)->setTimezone('Asia/Jakarta');

        $added = 0;
        while ($added < $targetWeekdays) {
            $cursor = $cursor->addDay();
            if ($cursor->isWeekday()) {
                $added++;
            }
        }

        return $cursor->endOfDay()->utc();
    }
}
