<?php

declare(strict_types=1);

use App\Enums\ReportStatus;
use App\Enums\SlaZone;
use App\Models\Report;
use App\Services\SlaEngineService;
use Carbon\CarbonImmutable;

it('transitions SLA zones accurately at 69%, 70%, and 100% including reopened cycles (TC-F-14)', function (): void {
    $service = new SlaEngineService;
    $cycleStart = CarbonImmutable::parse('2026-11-20 00:00:00', 'UTC');
    $deadline = $cycleStart->addHours(100); // 100h cycle for exact percentage math

    $report = new Report([
        'status' => ReportStatus::IN_PROGRESS,
        'sla_started_at' => $cycleStart,
        'sla_cycle_started_at' => $cycleStart,
        'sla_deadline_at' => $deadline,
    ]);

    // 69% -> GREEN
    CarbonImmutable::setTestNow($cycleStart->addHours(69));
    expect($service->calculateZone($report))->toBe(SlaZone::GREEN)
        ->and($service->remainingSeconds($report))->toBe(31 * 3600);

    // 70% -> YELLOW
    CarbonImmutable::setTestNow($cycleStart->addHours(70));
    expect($service->calculateZone($report))->toBe(SlaZone::YELLOW)
        ->and($service->remainingSeconds($report))->toBe(30 * 3600);

    // 100% -> RED
    CarbonImmutable::setTestNow($cycleStart->addHours(100));
    expect($service->calculateZone($report))->toBe(SlaZone::RED)
        ->and($service->remainingSeconds($report))->toBe(0);

    // Overdue 110% -> RED with negative remaining seconds
    CarbonImmutable::setTestNow($cycleStart->addHours(110));
    expect($service->calculateZone($report))->toBe(SlaZone::RED)
        ->and($service->remainingSeconds($report))->toBe(-10 * 3600);

    // Reopened by Audit uses new sla_cycle_started_at (D-03)
    $reopenStart = CarbonImmutable::parse('2026-11-25 00:00:00', 'UTC');
    $report->status = ReportStatus::REOPENED_BY_AUDIT;
    $report->sla_cycle_started_at = $reopenStart;
    $report->sla_deadline_at = $reopenStart->addHours(48);

    CarbonImmutable::setTestNow($reopenStart->addHours(12)); // 25% of 48h
    expect($service->calculateZone($report))->toBe(SlaZone::GREEN)
        ->and($service->remainingSeconds($report))->toBe(36 * 3600);

    CarbonImmutable::setTestNow();
});

it('handles PAUSED, APBD_SCHEDULED, and STOPPED states properly', function (): void {
    $service = new SlaEngineService;
    $start = CarbonImmutable::parse('2026-11-20 00:00:00', 'UTC');
    $deadline = $start->addHours(24);
    $pausedAt = $start->addHours(10);

    $pausedReport = new Report([
        'status' => ReportStatus::PENDING_CITIZEN_SIGN_OFF,
        'sla_cycle_started_at' => $start,
        'sla_paused_at' => $pausedAt,
        'sla_deadline_at' => $deadline,
        'signoff_deadline_at' => $pausedAt->addHours(72),
    ]);

    CarbonImmutable::setTestNow($pausedAt->addHours(12));
    expect($service->calculateZone($pausedReport))->toBe(SlaZone::PAUSED)
        ->and($service->remainingSeconds($pausedReport))->toBe(14 * 3600)
        ->and($service->signoffRemainingSeconds($pausedReport))->toBe(60 * 3600);

    $apbdReport = new Report(['status' => ReportStatus::BUDGET_SCHEDULED]);
    expect($service->calculateZone($apbdReport))->toBe(SlaZone::APBD_SCHEDULED)
        ->and($service->remainingSeconds($apbdReport))->toBeNull();

    foreach ([
        ReportStatus::VERIFIED_CLOSED,
        ReportStatus::AUTO_VERIFIED_CLOSED,
        ReportStatus::CLOSED_BY_AUDIT,
        ReportStatus::REJECTED,
    ] as $finalStatus) {
        $finalReport = new Report(['status' => $finalStatus]);
        expect($service->calculateZone($finalReport))->toBe(SlaZone::STOPPED);
    }

    CarbonImmutable::setTestNow();
});

it('calculates 7 weekday APBD document due date in Asia/Jakarta skipping weekends (BR-010, BR-018)', function (): void {
    $service = new SlaEngineService;

    // Monday 2026-11-23 10:00 WIB (03:00 UTC)
    // +7 weekdays = Tue(1), Wed(2), Thu(3), Fri(4), [Sat/Sun skipped], Mon(5), Tue(6), Wed 2026-12-02 (7)
    // End of day 2026-12-02 23:59:59 WIB = 2026-12-02 16:59:59 UTC
    $scheduledAt = CarbonImmutable::parse('2026-11-23 03:00:00', 'UTC');
    $dueAt = $service->calculateApbdDocumentDueAt($scheduledAt, 7);

    expect($dueAt->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s'))->toBe('2026-12-02 23:59:59')
        ->and($dueAt->timezoneName)->toBe('UTC');
});
