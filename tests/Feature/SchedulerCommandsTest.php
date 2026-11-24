<?php

declare(strict_types=1);

use App\Enums\AuditActionType;
use App\Enums\NotificationType;
use App\Enums\ReportStatus;
use App\Enums\ResolutionTrack;
use App\Models\BudgetMilestone;
use App\Models\BudgetSchedule;
use App\Models\Category;
use App\Models\Notification;
use App\Models\OpdUnit;
use App\Models\Report;
use App\Models\User;
use App\Services\SlaEngineService;
use Carbon\CarbonImmutable;
use Database\Seeders\CilacapSimulationSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('evaluates SLA yellow warning at >=70% and red overdue at >=100% idempotently without changing report status (TASK-BE-046, TC-F-14, TC-I-04)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);

    $baseTime = CarbonImmutable::parse('2027-02-01 00:00:00', 'UTC');
    $this->travelTo($baseTime);

    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $officerDishub = User::where('email', 'petugas.dishub@pastara.id')->firstOrFail();
    $adminDishub = User::where('email', 'admin.dishub@pastara.id')->firstOrFail();
    $auditor = User::where('email', 'auditor.inspektorat@pastara.id')->firstOrFail();
    $cat02 = Category::where('code', 'CAT-02')->firstOrFail();
    $sample = Report::firstOrFail();

    $report = Report::create([
        'ticket_code' => 'PST-SLACMD01',
        'reporter_id' => $warga->id,
        'category_id' => $cat02->id,
        'opd_unit_id' => $adminDishub->opd_unit_id,
        'assigned_officer_id' => $officerDishub->id,
        'district_id' => $sample->district_id,
        'village_id' => $sample->village_id,
        'title' => 'Pengujian scheduler evaluasi SLA 24 jam',
        'description_sanitized' => 'Menguji transisi zona hijau, kuning, dan merah secara idempoten.',
        'latitude' => -7.7188,
        'longitude' => 109.0154,
        'status' => ReportStatus::IN_PROGRESS,
        'resolution_track' => ResolutionTrack::OPERATIONAL,
        'current_cycle' => 1,
        'issue_type_snapshot' => $cat02->issue_type,
        'duplicate_radius_snapshot_m' => $cat02->duplicate_radius_m,
        'geotag_tolerance_snapshot_m' => $cat02->geotag_tolerance_m,
        'sla_hours_snapshot' => 24,
        'sla_started_at' => $baseTime,
        'sla_cycle_started_at' => $baseTime,
        'sla_deadline_at' => $baseTime->addHours(24),
        'is_sla_overdue' => false,
        'is_late' => false,
    ]);

    // 1. At 69% elapsed (16h 30m) -> still GREEN, no warning or overdue
    $this->travelTo($baseTime->addHours(16)->addMinutes(30));
    $this->artisan('pastara:evaluate-sla')->assertSuccessful();

    $report->refresh();
    expect($report->sla_warning_notified_at)->toBeNull()
        ->and($report->is_sla_overdue)->toBeFalse()
        ->and($report->is_late)->toBeFalse();

    // 2. At 70% elapsed (16h 48m) -> YELLOW warning fired once
    $this->travelTo($baseTime->addHours(16)->addMinutes(48));
    $this->artisan('pastara:evaluate-sla')->assertSuccessful();

    $report->refresh();
    expect($report->sla_warning_notified_at)->not->toBeNull()
        ->and($report->is_sla_overdue)->toBeFalse()
        ->and($report->auditLogs()->where('action_type', AuditActionType::SLA_WARNING)->count())->toBe(1)
        ->and(Notification::where('report_id', $report->id)->where('type', NotificationType::SLA_WARNING_YELLOW->value)->where('user_id', $adminDishub->id)->exists())->toBeTrue()
        ->and(Notification::where('report_id', $report->id)->where('type', NotificationType::SLA_WARNING_YELLOW->value)->where('user_id', $officerDishub->id)->exists())->toBeTrue();

    $yellowNotifCount = Notification::where('report_id', $report->id)
        ->where('type', NotificationType::SLA_WARNING_YELLOW->value)
        ->count();

    // TC-I-04 Idempotency: re-running at 75% MUST NOT create duplicate warning audit/notifications
    $this->travelTo($baseTime->addHours(18));
    $this->artisan('pastara:evaluate-sla')->assertSuccessful();

    expect($report->auditLogs()->where('action_type', AuditActionType::SLA_WARNING)->count())->toBe(1)
        ->and(Notification::where('report_id', $report->id)->where('type', NotificationType::SLA_WARNING_YELLOW->value)->count())->toBe($yellowNotifCount);

    // 3. At 100%+ elapsed (24h 01m) -> RED overdue flag set, is_late = true, status remains IN_PROGRESS
    $this->travelTo($baseTime->addHours(24)->addMinute());
    $this->artisan('pastara:evaluate-sla')->assertSuccessful();

    $report->refresh();
    expect($report->status)->toBe(ReportStatus::IN_PROGRESS)
        ->and($report->is_sla_overdue)->toBeTrue()
        ->and($report->is_late)->toBeTrue()
        ->and($report->auditLogs()->where('action_type', AuditActionType::SLA_OVERDUE)->count())->toBe(1)
        ->and(Notification::where('report_id', $report->id)->where('type', NotificationType::SLA_OVERDUE_RED->value)->where('user_id', $auditor->id)->exists())->toBeTrue();

    $redNotifCount = Notification::where('report_id', $report->id)
        ->where('type', NotificationType::SLA_OVERDUE_RED->value)
        ->count();

    // TC-I-04 Idempotency: re-running after overdue MUST NOT duplicate audit or notifications
    $this->artisan('pastara:evaluate-sla')->assertSuccessful();
    expect($report->auditLogs()->where('action_type', AuditActionType::SLA_OVERDUE)->count())->toBe(1)
        ->and(Notification::where('report_id', $report->id)->where('type', NotificationType::SLA_OVERDUE_RED->value)->count())->toBe($redNotifCount);
});

it('sends 24h sign-off reminder once and auto-verifies expired 72h Escrow tickets idempotently (TASK-BE-046, TC-F-15, TC-I-04)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);

    $baseTime = CarbonImmutable::parse('2027-02-10 00:00:00', 'UTC');
    $this->travelTo($baseTime);

    $escrow = Report::where('ticket_code', 'PST-ESCROW01')->firstOrFail();
    $escrow->update([
        'status' => ReportStatus::PENDING_CITIZEN_SIGN_OFF,
        'signoff_started_at' => $baseTime,
        'signoff_deadline_at' => $baseTime->addHours(72),
        'signoff_reminder_sent_at' => null,
    ]);

    // 1. At +24h (48h left) -> no reminder, still pending
    $this->travelTo($baseTime->addHours(24));
    $this->artisan('pastara:auto-verify-signoff')->assertSuccessful();

    $escrow->refresh();
    expect($escrow->signoff_reminder_sent_at)->toBeNull()
        ->and($escrow->status)->toBe(ReportStatus::PENDING_CITIZEN_SIGN_OFF);

    // 2. At +48h 05m (23h 55m left <= 24h) -> sends SIGN_OFF_REMINDER_24H once
    $this->travelTo($baseTime->addHours(48)->addMinutes(5));
    $this->artisan('pastara:auto-verify-signoff')->assertSuccessful();

    $escrow->refresh();
    expect($escrow->signoff_reminder_sent_at)->not->toBeNull()
        ->and($escrow->status)->toBe(ReportStatus::PENDING_CITIZEN_SIGN_OFF)
        ->and(Notification::where('report_id', $escrow->id)->where('type', NotificationType::SIGN_OFF_REMINDER_24H->value)->count())->toBe(1);

    // TC-I-04 Idempotency: re-running at +60h sends zero extra reminders
    $this->travelTo($baseTime->addHours(60));
    $this->artisan('pastara:auto-verify-signoff')->assertSuccessful();
    expect(Notification::where('report_id', $escrow->id)->where('type', NotificationType::SIGN_OFF_REMINDER_24H->value)->count())->toBe(1);

    // 3. At +72h 01m -> TR-10 AUTO_VERIFIED_CLOSED + audit AUTO_VERIFIED + REPORT_CLOSED notification
    $this->travelTo($baseTime->addHours(72)->addMinute());
    $this->artisan('pastara:auto-verify-signoff')->assertSuccessful();

    $escrow->refresh();
    expect($escrow->status)->toBe(ReportStatus::AUTO_VERIFIED_CLOSED)
        ->and($escrow->sla_stopped_at)->not->toBeNull()
        ->and($escrow->is_sla_overdue)->toBeFalse()
        ->and($escrow->auditLogs()->where('action_type', AuditActionType::AUTO_VERIFIED)->count())->toBe(1)
        ->and(Notification::where('report_id', $escrow->id)->where('type', NotificationType::REPORT_CLOSED->value)->exists())->toBeTrue();

    // TC-I-04 Idempotency: re-running after auto-verification produces zero duplicate audits
    $this->artisan('pastara:auto-verify-signoff')->assertSuccessful();
    expect($escrow->auditLogs()->where('action_type', AuditActionType::AUTO_VERIFIED)->count())->toBe(1);
});

it('flags APBD milestone overdue across BR-010 (7-weekday doc), BR-011 (quarterly update), and BR-012 (target quarter elapsed) idempotently (TASK-BE-047, AC-055)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);

    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $adminDpupr = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $auditor = User::where('email', 'auditor.inspektorat@pastara.id')->firstOrFail();
    $cat01 = Category::where('code', 'CAT-01')->firstOrFail();
    $sample = Report::firstOrFail();
    $slaEngine = app(SlaEngineService::class);

    $createApbdTicket = function (string $code, CarbonImmutable $scheduledAtUtc, string $targetQuarter, ?CarbonImmutable $docUploadedAt, ?CarbonImmutable $lastMilestoneAt) use ($warga, $adminDpupr, $cat01, $sample, $slaEngine): Report {
        $report = Report::create([
            'ticket_code' => $code,
            'reporter_id' => $warga->id,
            'category_id' => $cat01->id,
            'opd_unit_id' => $adminDpupr->opd_unit_id,
            'district_id' => $sample->district_id,
            'village_id' => $sample->village_id,
            'title' => "Pengujian APBD {$code}",
            'description_sanitized' => 'Pengujian kewajiban milestone Jalur 2 APBD.',
            'latitude' => -7.7188,
            'longitude' => 109.0154,
            'status' => ReportStatus::BUDGET_SCHEDULED,
            'resolution_track' => ResolutionTrack::APBD,
            'current_cycle' => 1,
            'issue_type_snapshot' => $cat01->issue_type,
            'duplicate_radius_snapshot_m' => $cat01->duplicate_radius_m,
            'geotag_tolerance_snapshot_m' => $cat01->geotag_tolerance_m,
            'sla_hours_snapshot' => $cat01->default_sla_hours,
            'is_milestone_overdue' => false,
        ]);

        $schedule = BudgetSchedule::create([
            'report_id' => $report->id,
            'fiscal_year' => 2027,
            'proposal_ref_number' => "RKA-{$code}",
            'target_quarter' => $targetQuarter,
            'estimated_budget' => 500000000,
            'document_due_at' => $slaEngine->calculateApbdDocumentDueAt($scheduledAtUtc, 7),
            'document_uploaded_at' => $docUploadedAt,
            'last_milestone_updated_at' => $lastMilestoneAt ?? $scheduledAtUtc,
            'created_by_user_id' => $adminDpupr->id,
        ]);
        // Explicitly set created_at to simulated scheduling timestamp
        BudgetSchedule::whereKey($schedule->id)->update(['created_at' => $scheduledAtUtc]);

        if ($lastMilestoneAt !== null) {
            BudgetMilestone::create([
                'budget_schedule_id' => $schedule->id,
                'updated_by_user_id' => $adminDpupr->id,
                'quarter_label' => '2027-Q1',
                'progress_note' => 'Pembaruan milestone triwulanan telah diunggah tepat waktu.',
                'created_at' => $lastMilestoneAt,
            ]);
        }

        return $report;
    };

    // 1. BR-010: Scheduled Mon 2027-02-01 09:00 WIB (02:00 UTC) without RKA doc -> 7 weekdays = Wed 2027-02-10 23:59:59 WIB
    $scheduledQ1 = CarbonImmutable::parse('2027-02-01 02:00:00', 'UTC');
    $br010Report = $createApbdTicket('PST-APBD010', $scheduledQ1, 'Q3', null, null);

    // Still within 7 weekdays (Wed 2027-02-10 12:00 WIB) -> NOT overdue
    $this->travelTo(CarbonImmutable::parse('2027-02-10 05:00:00', 'UTC'));
    $this->artisan('pastara:evaluate-apbd-milestones')->assertSuccessful();
    expect($br010Report->fresh()->is_milestone_overdue)->toBeFalse();

    // Past 7 weekdays (Thu 2027-02-11 00:05 WIB == 2027-02-10 17:05 UTC) -> flagged BR-010
    $this->travelTo(CarbonImmutable::parse('2027-02-10 17:05:00', 'UTC'));
    $this->artisan('pastara:evaluate-apbd-milestones')->assertSuccessful();

    $br010Report->refresh();
    $audit010 = $br010Report->auditLogs()->where('action_type', AuditActionType::MILESTONE_OVERDUE)->first();
    expect($br010Report->is_milestone_overdue)->toBeTrue()
        ->and($audit010)->not->toBeNull()
        ->and($audit010->metadata['violations'] ?? [])->toContain('BR-010')
        ->and(Notification::where('report_id', $br010Report->id)->where('type', NotificationType::APBD_MILESTONE_OVERDUE->value)->where('user_id', $auditor->id)->exists())->toBeTrue();

    // Idempotent re-run -> still 1 audit log
    $this->artisan('pastara:evaluate-apbd-milestones')->assertSuccessful();
    expect($br010Report->auditLogs()->where('action_type', AuditActionType::MILESTONE_OVERDUE)->count())->toBe(1);

    // 2. BR-011: Missing Q1 milestone vs compliant Q1 milestone checked on 2027-04-01 00:05 WIB
    $br011Missed = $createApbdTicket('PST-APBD011A', $scheduledQ1, 'Q3', $scheduledQ1->addDay(), null);
    $br011Compliant = $createApbdTicket(
        'PST-APBD011B',
        $scheduledQ1,
        'Q2',
        $scheduledQ1->addDay(),
        CarbonImmutable::parse('2027-03-15 05:00:00', 'UTC')
    );

    $this->travelTo(CarbonImmutable::parse('2027-03-31 17:05:00', 'UTC')); // 2027-04-01 00:05 WIB
    $this->artisan('pastara:evaluate-apbd-milestones')->assertSuccessful();

    expect($br011Missed->fresh()->is_milestone_overdue)->toBeTrue()
        ->and($br011Missed->auditLogs()->where('action_type', AuditActionType::MILESTONE_OVERDUE)->first()?->metadata['violations'] ?? [])->toContain('BR-011')
        ->and($br011Compliant->fresh()->is_milestone_overdue)->toBeFalse();

    // 3. BR-012: Target quarter 2027-Q2 ended without start-execution checked on 2027-07-01 00:05 WIB
    $q2MilestoneTime = CarbonImmutable::parse('2027-06-15 05:00:00', 'UTC');
    $br011Compliant->budgetSchedule->update([
        'last_milestone_updated_at' => $q2MilestoneTime,
    ]);
    BudgetMilestone::create([
        'budget_schedule_id' => $br011Compliant->budgetSchedule->id,
        'updated_by_user_id' => $adminDpupr->id,
        'quarter_label' => '2027-Q2',
        'progress_note' => 'Pembaruan milestone Q2 sebelum batas kuartal pelaksanaan berakhir.',
        'created_at' => $q2MilestoneTime,
    ]);
    $this->travelTo(CarbonImmutable::parse('2027-06-30 17:05:00', 'UTC')); // 2027-07-01 00:05 WIB
    $this->artisan('pastara:evaluate-apbd-milestones')->assertSuccessful();

    $br011Compliant->refresh();
    expect($br011Compliant->is_milestone_overdue)->toBeTrue()
        ->and($br011Compliant->auditLogs()->where('action_type', AuditActionType::MILESTONE_OVERDUE)->first()?->metadata['violations'] ?? [])->toContain('BR-012');
});

it('recalculates opd_units scorecard cache and registers all 4 scheduler commands in console schedule (TASK-BE-047)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);

    OpdUnit::query()->update([
        'cached_compliance_score' => null,
        'metrics_updated_at' => null,
    ]);

    $this->artisan('pastara:recalculate-scorecard')->assertSuccessful();

    $updatedCount = OpdUnit::active()->whereNotNull('metrics_updated_at')->count();
    expect($updatedCount)->toBe(OpdUnit::active()->count());

    // Verify all 4 scheduled commands are registered in routes/console.php
    $schedule = app(Schedule::class);
    $commands = collect($schedule->events())
        ->map(static fn ($event): string => (string) $event->command)
        ->implode("\n");

    expect($commands)
        ->toContain('pastara:evaluate-sla')
        ->toContain('pastara:auto-verify-signoff')
        ->toContain('pastara:evaluate-apbd-milestones')
        ->toContain('pastara:recalculate-scorecard');
});
