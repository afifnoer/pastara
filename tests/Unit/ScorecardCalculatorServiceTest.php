<?php

declare(strict_types=1);

use App\Enums\AuditActionType;
use App\Enums\IssueType;
use App\Enums\ReportStatus;
use App\Enums\UnitLevel;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\District;
use App\Models\OpdUnit;
use App\Models\Rating;
use App\Models\Report;
use App\Models\User;
use App\Models\Village;
use App\Services\ScorecardCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeScorecardReport(
    OpdUnit $unit,
    User $reporter,
    Category $cat,
    District $district,
    Village $village,
    string $code,
    ReportStatus $status,
    bool $isLate = false,
    bool $isSlaOverdue = false,
    bool $withSlaStart = true,
    bool $withSignOff = false,
    bool $withDispute = false,
    ?int $qualityScore = null,
): Report {
    $report = Report::create([
        'ticket_code' => $code,
        'reporter_id' => $reporter->id,
        'category_id' => $cat->id,
        'opd_unit_id' => $unit->id,
        'district_id' => $district->id,
        'village_id' => $village->id,
        'title' => "Scorecard {$code}",
        'description_sanitized' => "Deskripsi {$code}",
        'latitude' => -7.7188,
        'longitude' => 109.0154,
        'status' => $status,
        'issue_type_snapshot' => IssueType::POINT,
        'duplicate_radius_snapshot_m' => 200,
        'geotag_tolerance_snapshot_m' => 100,
        'sla_hours_snapshot' => 24,
        'sla_started_at' => $withSlaStart ? now('UTC')->subDays(2) : null,
        'signoff_started_at' => $withSignOff ? now('UTC')->subDay() : null,
        'is_late' => $isLate,
        'is_sla_overdue' => $isSlaOverdue,
    ]);

    if ($withSignOff) {
        AuditLog::create([
            'report_id' => $report->id,
            'actor_role' => 'PETUGAS',
            'action_type' => AuditActionType::EVIDENCE_SUBMITTED,
            'from_status' => ReportStatus::IN_PROGRESS,
            'to_status' => ReportStatus::PENDING_CITIZEN_SIGN_OFF,
            'created_at' => now('UTC')->subDay(),
        ]);
    }

    if ($withDispute) {
        AuditLog::create([
            'report_id' => $report->id,
            'actor_role' => 'WARGA',
            'action_type' => AuditActionType::CITIZEN_REJECTED,
            'from_status' => ReportStatus::PENDING_CITIZEN_SIGN_OFF,
            'to_status' => ReportStatus::DISPUTED,
            'created_at' => now('UTC')->subHours(18),
        ]);
    }

    if ($qualityScore !== null) {
        Rating::create([
            'report_id' => $report->id,
            'user_id' => $reporter->id,
            'speed_score' => $qualityScore,
            'physical_quality_score' => $qualityScore,
            'created_at' => now('UTC')->subHours(2),
        ]);
    }

    return $report;
}

it('calculates SLA, Physical Resolution, Dispute Rate, Quality Index, and Compliance Score accurately (TC-F-16)', function (): void {
    $dpupr = OpdUnit::create(['code' => 'DPUPR', 'name' => 'DPUPR', 'level' => UnitLevel::OPD]);
    $dishub = OpdUnit::create(['code' => 'DISHUB', 'name' => 'Dishub', 'level' => UnitLevel::OPD]);
    $dlh = OpdUnit::create(['code' => 'DLH', 'name' => 'DLH', 'level' => UnitLevel::OPD]);

    $district = District::create(['code' => '33.01.22', 'name' => 'Cilacap Tengah']);
    $village = Village::create(['district_id' => $district->id, 'name' => 'Sidanegara', 'type' => 'KELURAHAN']);
    $reporter = User::create([
        'name' => 'Warga Scorecard',
        'email' => 'warga.score@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::WARGA,
    ]);
    $cat = Category::create([
        'code' => 'CAT-01',
        'name' => 'Jalan',
        'default_opd_unit_id' => $dpupr->id,
        'default_sla_hours' => 72,
        'issue_type' => IssueType::LINEAR,
        'duplicate_radius_m' => 750,
        'geotag_tolerance_m' => 500,
    ]);

    // DPUPR: 8 valid reports + 1 rejected
    // 4 VERIFIED_CLOSED on-time
    for ($i = 1; $i <= 4; $i++) {
        makeScorecardReport($dpupr, $reporter, $cat, $district, $village, "PST-SC0{$i}", ReportStatus::VERIFIED_CLOSED, false, false, true, true, false, $i === 1 ? 5 : ($i === 2 ? 4 : null));
    }
    // 1 AUTO_VERIFIED_CLOSED late + disputed earlier
    makeScorecardReport($dpupr, $reporter, $cat, $district, $village, 'PST-SC05', ReportStatus::AUTO_VERIFIED_CLOSED, true, false, true, true, true);
    // 1 CLOSED_BY_AUDIT on-time + disputed earlier (not counted as Physical Closed!)
    makeScorecardReport($dpupr, $reporter, $cat, $district, $village, 'PST-SC06', ReportStatus::CLOSED_BY_AUDIT, false, false, true, true, true);
    // 1 active IN_PROGRESS overdue
    makeScorecardReport($dpupr, $reporter, $cat, $district, $village, 'PST-SC07', ReportStatus::IN_PROGRESS, true, true, true, false, false);
    // 1 BUDGET_SCHEDULED (counts in Valid tickets, NOT Physical Closed)
    makeScorecardReport($dpupr, $reporter, $cat, $district, $village, 'PST-SC08', ReportStatus::BUDGET_SCHEDULED, false, false, false, false, false);
    // 1 REJECTED (excluded from Valid tickets)
    makeScorecardReport($dpupr, $reporter, $cat, $district, $village, 'PST-SC09', ReportStatus::REJECTED, false, false, false, false, false);

    // DISHUB: 2 valid tickets (1 active overdue, 1 routed) -> Dispute denominator = 0 (proportional normalization)
    makeScorecardReport($dishub, $reporter, $cat, $district, $village, 'PST-DH01', ReportStatus::IN_PROGRESS, true, true, true, false, false);
    makeScorecardReport($dishub, $reporter, $cat, $district, $village, 'PST-DH02', ReportStatus::ROUTED_TO_UNIT, false, false, false, false, false);

    // DLH: only 1 REJECTED -> has_data = false
    makeScorecardReport($dlh, $reporter, $cat, $district, $village, 'PST-DL01', ReportStatus::REJECTED, false, false, false, false, false);

    $service = new ScorecardCalculatorService;
    $results = $service->recalculateAllUnits()->keyBy('code');

    $dpuprMetrics = $results['DPUPR'];
    // SLA: 5 / 7 = 71.43%; Physical: 5 / 8 = 62.50%; Dispute: 2 / 6 = 33.33%
    // Compliance = 0.5*71.43 + 0.3*62.50 + 0.2*(100 - 33.33) = 67.80
    expect($dpuprMetrics['has_data'])->toBeTrue()
        ->and($dpuprMetrics['valid_tickets_count'])->toBe(8)
        ->and($dpuprMetrics['rejected_count'])->toBe(1)
        ->and($dpuprMetrics['budget_scheduled_count'])->toBe(1)
        ->and($dpuprMetrics['physical_closed_count'])->toBe(5)
        ->and($dpuprMetrics['sla_compliance_rate'])->toBe(71.43)
        ->and($dpuprMetrics['physical_resolution_rate'])->toBe(62.5)
        ->and($dpuprMetrics['dispute_rate'])->toBe(33.33)
        ->and($dpuprMetrics['quality_index'])->toBe(4.5)
        ->and(abs($dpuprMetrics['compliance_score'] - 67.80))->toBeLessThanOrEqual(0.01);

    // Proportional weight normalization on DISHUB (dispute_rate = null => score = 0.0, not 20.0)
    $dishubMetrics = $results['DISHUB'];
    expect($dishubMetrics['sla_compliance_rate'])->toBe(0.0)
        ->and($dishubMetrics['physical_resolution_rate'])->toBe(0.0)
        ->and($dishubMetrics['dispute_rate'])->toBeNull()
        ->and($dishubMetrics['compliance_score'])->toBe(0.0);

    // DLH has no valid tickets
    $dlhMetrics = $results['DLH'];
    expect($dlhMetrics['has_data'])->toBeFalse()
        ->and($dlhMetrics['compliance_score'])->toBeNull();

    // Cached columns persisted on OpdUnit
    $dpupr->refresh();
    expect($dpupr->cached_compliance_score)->toBe(67.8)
        ->and($dpupr->cached_quality_index)->toBe(4.5)
        ->and($dpupr->metrics_updated_at)->not->toBeNull();
});

it('normalizes non-zero weights proportionally when dispute_rate is null (TC-F-16)', function (): void {
    $unit = OpdUnit::create(['code' => 'NORM_UNIT', 'name' => 'Unit Normalisasi', 'level' => UnitLevel::OPD]);
    $reporter = User::create([
        'name' => 'Warga Norm',
        'email' => 'warga.norm@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::WARGA,
    ]);
    $cat = Category::create([
        'code' => 'CAT-NORM',
        'name' => 'Kategori Norm',
        'default_opd_unit_id' => $unit->id,
        'default_sla_hours' => 72,
        'issue_type' => IssueType::POINT,
        'duplicate_radius_m' => 200,
        'geotag_tolerance_m' => 100,
    ]);
    $district = District::create(['code' => '330198', 'name' => 'Kec Norm']);
    $village = Village::create(['district_id' => $district->id, 'name' => 'Desa Norm', 'type' => 'DESA']);

    // Create 1 active overdue (SLA = 0/1 = 0% — wait: use 1 on-time IN_PROGRESS? No, need non-zero SLA & Physical with dispute_rate = null):
    // 1 BUDGET_SCHEDULED + 1 IN_PROGRESS (not overdue, sla_started_at = null on closed? Wait: VERIFIED_CLOSED implies everSignOff).
    // If 1 ticket is IN_PROGRESS overdue (not signoff) -> sla = 0. Instead test sla_compliance_rate = null & dispute_rate = null
    // (1 BUDGET_SCHEDULED + 1 ROUTED_TO_UNIT -> valid = 2, physical = 0%, sla = null, dispute = null -> score = 0)
    // AND test sla = null, physical = 50%, dispute = 0% (1 VERIFIED_CLOSED with sla_started_at = null + 1 ROUTED_TO_UNIT):
    // weights remaining: physical (0.30) + dispute (0.20) = 0.50 => (0.30/0.50)*50 + (0.20/0.50)*100 = 30 + 40 = 70.00 (unnormalized would be 35.00!)
    makeScorecardReport($unit, $reporter, $cat, $district, $village, 'PST-NM01', ReportStatus::VERIFIED_CLOSED, false, false, false, true, false);
    makeScorecardReport($unit, $reporter, $cat, $district, $village, 'PST-NM02', ReportStatus::ROUTED_TO_UNIT, false, false, false, false, false);

    $service = new ScorecardCalculatorService;
    $metrics = $service->calculateForUnit($unit);

    expect($metrics['sla_compliance_rate'])->toBeNull()
        ->and($metrics['physical_resolution_rate'])->toBe(50.0)
        ->and($metrics['dispute_rate'])->toBe(0.0)
        ->and($metrics['compliance_score'])->toBe(70.0);
});
