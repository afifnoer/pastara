<?php

declare(strict_types=1);

use App\Enums\DisputeStatus;
use App\Enums\IssueType;
use App\Enums\ReportStatus;
use App\Enums\UnitLevel;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Dispute;
use App\Models\District;
use App\Models\OpdUnit;
use App\Models\Report;
use App\Models\User;
use App\Models\Village;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

function createPolicyActors(): array
{
    $dpupr = OpdUnit::create(['code' => 'DPUPR', 'name' => 'DPUPR', 'level' => UnitLevel::OPD]);
    $dishub = OpdUnit::create(['code' => 'DISHUB', 'name' => 'Dishub', 'level' => UnitLevel::OPD]);
    $district = District::create(['code' => '33.01.22', 'name' => 'Cilacap Tengah']);
    $village = Village::create(['district_id' => $district->id, 'name' => 'Sidanegara', 'type' => 'KELURAHAN']);
    $cat = Category::create([
        'code' => 'CAT-01',
        'name' => 'Jalan',
        'default_opd_unit_id' => $dpupr->id,
        'default_sla_hours' => 72,
        'issue_type' => IssueType::LINEAR,
        'duplicate_radius_m' => 750,
        'geotag_tolerance_m' => 500,
    ]);

    $reporter = User::create(['name' => 'Pelapor', 'email' => 'rep.pol@pastara.id', 'password' => 'Pastara2027!', 'role' => UserRole::WARGA]);
    $otherWarga = User::create(['name' => 'Warga Lain', 'email' => 'w2.pol@pastara.id', 'password' => 'Pastara2027!', 'role' => UserRole::WARGA]);
    $adminDpupr = User::create(['name' => 'Admin DPUPR', 'email' => 'adm1.pol@pastara.id', 'password' => 'Pastara2027!', 'role' => UserRole::ADMIN_UNIT, 'opd_unit_id' => $dpupr->id]);
    $adminDishub = User::create(['name' => 'Admin Dishub', 'email' => 'adm2.pol@pastara.id', 'password' => 'Pastara2027!', 'role' => UserRole::ADMIN_UNIT, 'opd_unit_id' => $dishub->id]);
    $officerDpupr = User::create(['name' => 'Petugas 1', 'email' => 'pet1.pol@pastara.id', 'password' => 'Pastara2027!', 'role' => UserRole::PETUGAS, 'opd_unit_id' => $dpupr->id]);
    $otherOfficer = User::create(['name' => 'Petugas 2', 'email' => 'pet2.pol@pastara.id', 'password' => 'Pastara2027!', 'role' => UserRole::PETUGAS, 'opd_unit_id' => $dpupr->id]);
    $inspector = User::create(['name' => 'Auditor', 'email' => 'aud.pol@pastara.id', 'password' => 'Pastara2027!', 'role' => UserRole::INSPEKTORAT]);
    $superAdmin = User::create(['name' => 'Super Admin', 'email' => 'sa.pol@pastara.id', 'password' => 'Pastara2027!', 'role' => UserRole::SUPER_ADMIN]);

    return compact(
        'dpupr', 'dishub', 'district', 'village', 'cat',
        'reporter', 'otherWarga', 'adminDpupr', 'adminDishub',
        'officerDpupr', 'otherOfficer', 'inspector', 'superAdmin'
    );
}

it('enforces all ReportPolicy, DisputePolicy, UserPolicy, and OpdUnitPolicy boolean rules', function (): void {
    $fx = createPolicyActors();
    $now = CarbonImmutable::parse('2026-11-22 04:00:00', 'UTC');
    CarbonImmutable::setTestNow($now);

    $publicReport = Report::create([
        'ticket_code' => 'PST-POLPUB1',
        'reporter_id' => $fx['reporter']->id,
        'category_id' => $fx['cat']->id,
        'opd_unit_id' => $fx['dpupr']->id,
        'assigned_officer_id' => $fx['officerDpupr']->id,
        'district_id' => $fx['district']->id,
        'village_id' => $fx['village']->id,
        'title' => 'Laporan Publik Policy',
        'description_sanitized' => 'Deskripsi',
        'latitude' => -7.7188,
        'longitude' => 109.0154,
        'is_secret' => false,
        'status' => ReportStatus::ROUTED_TO_UNIT,
        'issue_type_snapshot' => IssueType::LINEAR,
        'duplicate_radius_snapshot_m' => 750,
        'geotag_tolerance_snapshot_m' => 500,
        'sla_hours_snapshot' => 72,
    ]);

    $secretSignOff = Report::create([
        'ticket_code' => 'PST-POLSEC1',
        'reporter_id' => $fx['reporter']->id,
        'category_id' => $fx['cat']->id,
        'opd_unit_id' => $fx['dpupr']->id,
        'assigned_officer_id' => $fx['officerDpupr']->id,
        'district_id' => $fx['district']->id,
        'village_id' => $fx['village']->id,
        'title' => 'Laporan Rahasia Policy',
        'description_sanitized' => 'Deskripsi rahasia',
        'latitude' => -7.7188,
        'longitude' => 109.0154,
        'is_secret' => true,
        'secret_token_hash' => Report::hashSecretToken('TKN-POL1-2027'),
        'status' => ReportStatus::PENDING_CITIZEN_SIGN_OFF,
        'signoff_started_at' => $now->subHours(10),
        'signoff_deadline_at' => $now->addHours(62),
        'issue_type_snapshot' => IssueType::LINEAR,
        'duplicate_radius_snapshot_m' => 750,
        'geotag_tolerance_snapshot_m' => 500,
        'sla_hours_snapshot' => 72,
    ]);

    // viewPublic
    expect(Gate::forUser(null)->allows('viewPublic', $publicReport))->toBeTrue()
        ->and(Gate::forUser(null)->allows('viewPublic', $secretSignOff))->toBeFalse();

    // viewUnit & manageUnit
    expect(Gate::forUser($fx['adminDpupr'])->allows('viewUnit', $publicReport))->toBeTrue()
        ->and(Gate::forUser($fx['adminDishub'])->allows('viewUnit', $publicReport))->toBeFalse()
        ->and(Gate::forUser($fx['adminDpupr'])->allows('manageUnit', $publicReport))->toBeTrue()
        ->and(Gate::forUser($fx['adminDpupr'])->allows('manageUnit', $secretSignOff))->toBeFalse();

    // viewOfficerTask & submitEvidence
    $publicReport->update(['status' => ReportStatus::IN_PROGRESS]);
    expect(Gate::forUser($fx['officerDpupr'])->allows('viewOfficerTask', $publicReport))->toBeTrue()
        ->and(Gate::forUser($fx['otherOfficer'])->allows('viewOfficerTask', $publicReport))->toBeFalse()
        ->and(Gate::forUser($fx['officerDpupr'])->allows('submitEvidence', $publicReport))->toBeTrue()
        ->and(Gate::forUser($fx['officerDpupr'])->allows('submitEvidence', $secretSignOff))->toBeFalse();

    // upvote (no self-upvote, no secret upvote, no final report upvote)
    expect(Gate::forUser($fx['otherWarga'])->allows('upvote', $publicReport))->toBeTrue()
        ->and(Gate::forUser($fx['reporter'])->allows('upvote', $publicReport))->toBeFalse()
        ->and(Gate::forUser($fx['otherWarga'])->allows('upvote', $secretSignOff))->toBeFalse()
        ->and(Gate::forUser($fx['adminDpupr'])->allows('upvote', $publicReport))->toBeFalse();

    // signOff dual auth: reporter Bearer OR guest + secret_token within 72h
    expect(Gate::forUser($fx['reporter'])->allows('signOff', [$secretSignOff, null]))->toBeTrue()
        ->and(Gate::forUser(null)->allows('signOff', [$secretSignOff, 'TKN-POL1-2027']))->toBeTrue()
        ->and(Gate::forUser(null)->allows('signOff', [$secretSignOff, 'TKN-BAD1-0000']))->toBeFalse()
        ->and(Gate::forUser($fx['otherWarga'])->allows('signOff', [$secretSignOff, null]))->toBeFalse();

    // Expired sign-off window (> 72h)
    CarbonImmutable::setTestNow($now->addHours(63));
    expect(Gate::forUser($fx['reporter'])->allows('signOff', [$secretSignOff, null]))->toBeFalse();

    // DisputePolicy::resolve
    $dispute = Dispute::create([
        'report_id' => $secretSignOff->id,
        'cycle' => 1,
        'raised_by_user_id' => $fx['reporter']->id,
        'citizen_reason' => 'Alasan veto warga.',
        'status' => DisputeStatus::PENDING,
    ]);
    expect(Gate::forUser($fx['inspector'])->allows('resolve', $dispute))->toBeTrue()
        ->and(Gate::forUser($fx['adminDpupr'])->allows('resolve', $dispute))->toBeFalse();

    $dispute->update(['status' => DisputeStatus::CLOSED]);
    expect(Gate::forUser($fx['inspector'])->allows('resolve', $dispute))->toBeFalse();

    // UserPolicy & OpdUnitPolicy
    expect(Gate::forUser($fx['superAdmin'])->allows('create', User::class))->toBeTrue()
        ->and(Gate::forUser($fx['superAdmin'])->allows('deactivate', $fx['adminDpupr']))->toBeTrue()
        ->and(Gate::forUser($fx['superAdmin'])->allows('deactivate', $fx['superAdmin']))->toBeFalse()
        ->and(Gate::forUser($fx['adminDpupr'])->allows('create', User::class))->toBeFalse()
        ->and(Gate::forUser($fx['superAdmin'])->allows('update', $fx['dpupr']))->toBeTrue()
        ->and(Gate::forUser($fx['inspector'])->allows('update', $fx['dpupr']))->toBeFalse();

    CarbonImmutable::setTestNow();
});

it('renders HTTP 404 on cross-unit isolation and HTTP 409 on wrong status via Gate::authorize', function (): void {
    $fx = createPolicyActors();

    $report = Report::create([
        'ticket_code' => 'PST-POLHTTP',
        'reporter_id' => $fx['reporter']->id,
        'category_id' => $fx['cat']->id,
        'opd_unit_id' => $fx['dpupr']->id,
        'district_id' => $fx['district']->id,
        'village_id' => $fx['village']->id,
        'title' => 'Laporan Isolasi HTTP',
        'description_sanitized' => 'Deskripsi lengkap',
        'latitude' => -7.7188,
        'longitude' => 109.0154,
        'status' => ReportStatus::IN_PROGRESS,
        'issue_type_snapshot' => IssueType::LINEAR,
        'duplicate_radius_snapshot_m' => 750,
        'geotag_tolerance_snapshot_m' => 500,
        'sla_hours_snapshot' => 72,
    ]);

    Route::middleware(['api', 'auth:sanctum'])->get('/api/v1/_test/policy-unit/{report}', function (Report $report): JsonResponse {
        Gate::authorize('manageUnit', $report);

        return ApiResponse::success();
    });

    // Cross-unit admin gets 404 NOT_FOUND
    $this->actingAs($fx['adminDishub'], 'sanctum')
        ->getJson("/api/v1/_test/policy-unit/{$report->id}")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    // Same-unit admin on IN_PROGRESS (wrong status for manageUnit) gets 409 INVALID_STATE_TRANSITION
    $this->actingAs($fx['adminDpupr'], 'sanctum')
        ->getJson("/api/v1/_test/policy-unit/{$report->id}")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');
});
