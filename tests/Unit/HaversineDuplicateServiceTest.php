<?php

declare(strict_types=1);

use App\Enums\IssueType;
use App\Enums\PhotoType;
use App\Enums\ReportStatus;
use App\Enums\UnitLevel;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\District;
use App\Models\OpdUnit;
use App\Models\Report;
use App\Models\ReportPhoto;
use App\Models\User;
use App\Models\Village;
use App\Services\HaversineDuplicateService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createDuplicateFixture(): array
{
    $dpupr = OpdUnit::create(['code' => 'DPUPR', 'name' => 'DPUPR', 'level' => UnitLevel::OPD]);
    $dishub = OpdUnit::create(['code' => 'DISHUB', 'name' => 'Dishub', 'level' => UnitLevel::OPD]);
    $district = District::create(['code' => '33.01.22', 'name' => 'Cilacap Tengah']);
    $village = Village::create(['district_id' => $district->id, 'name' => 'Sidanegara', 'type' => 'KELURAHAN']);
    $reporter = User::create([
        'name' => 'Warga Uji',
        'email' => 'warga.haversine@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::WARGA,
    ]);

    $pointCat = Category::create([
        'code' => 'CAT-02',
        'name' => 'Lampu Jalan',
        'default_opd_unit_id' => $dishub->id,
        'default_sla_hours' => 24,
        'issue_type' => IssueType::POINT,
        'duplicate_radius_m' => 200,
        'geotag_tolerance_m' => 100,
    ]);

    $linearCat = Category::create([
        'code' => 'CAT-01',
        'name' => 'Jalan Rusak',
        'default_opd_unit_id' => $dpupr->id,
        'default_sla_hours' => 72,
        'issue_type' => IssueType::LINEAR,
        'duplicate_radius_m' => 750,
        'geotag_tolerance_m' => 500,
    ]);

    return compact('dpupr', 'dishub', 'district', 'village', 'reporter', 'pointCat', 'linearCat');
}

function makeReportAt(
    array $fx,
    Category $category,
    float $lat,
    float $lng,
    string $code,
    ReportStatus $status = ReportStatus::IN_PROGRESS,
    bool $isSecret = false
): Report {
    $report = Report::create([
        'ticket_code' => $code,
        'reporter_id' => $fx['reporter']->id,
        'category_id' => $category->id,
        'opd_unit_id' => $category->default_opd_unit_id,
        'district_id' => $fx['district']->id,
        'village_id' => $fx['village']->id,
        'title' => "Laporan {$code}",
        'description_sanitized' => "Deskripsi {$code}",
        'latitude' => $lat,
        'longitude' => $lng,
        'is_secret' => $isSecret,
        'status' => $status,
        'issue_type_snapshot' => $category->issue_type,
        'duplicate_radius_snapshot_m' => $category->duplicate_radius_m,
        'geotag_tolerance_snapshot_m' => $category->geotag_tolerance_m,
        'sla_hours_snapshot' => $category->default_sla_hours,
        'upvote_count' => 3,
    ]);

    ReportPhoto::create([
        'report_id' => $report->id,
        'uploaded_by_user_id' => $fx['reporter']->id,
        'photo_type' => PhotoType::BEFORE,
        'cycle' => 1,
        'sequence' => 1,
        'file_path' => 'reports/2026/11/seed-before.webp',
        'thumbnail_path' => 'reports/2026/11/seed-before-thumb.webp',
    ]);

    return $report;
}

it('detects nearby active POINT and LINEAR duplicates accurately while excluding secret, final, and distant reports (TC-F-05)', function (): void {
    $fx = createDuplicateFixture();
    $service = new HaversineDuplicateService;

    $baseLat = -7.7188;
    $baseLng = 109.0154;

    // 1° latitude ≈ 111,195 m → +0.00135° ≈ 150 m; +0.00270° ≈ 300 m; +0.00585° ≈ 650 m
    makeReportAt($fx, $fx['pointCat'], $baseLat + 0.00135, $baseLng, 'PST-PNT150M');
    makeReportAt($fx, $fx['pointCat'], $baseLat + 0.00270, $baseLng, 'PST-PNT300M');
    makeReportAt($fx, $fx['pointCat'], $baseLat + 0.00010, $baseLng, 'PST-PNTSECRET', ReportStatus::IN_PROGRESS, true);
    makeReportAt($fx, $fx['pointCat'], $baseLat + 0.00010, $baseLng, 'PST-PNTCLOSED', ReportStatus::VERIFIED_CLOSED, false);
    makeReportAt($fx, $fx['pointCat'], $baseLat + 0.00010, $baseLng, 'PST-PNTREJECT', ReportStatus::REJECTED, false);
    makeReportAt($fx, $fx['linearCat'], $baseLat + 0.00585, $baseLng, 'PST-LIN650M');
    makeReportAt($fx, $fx['linearCat'], $baseLat + 0.00810, $baseLng, 'PST-LIN900M');

    $pointResult = $service->findNearbyDuplicates($fx['pointCat']->id, $baseLat, $baseLng);
    expect($pointResult['radius_m'])->toBe(200)
        ->and($pointResult['matches'])->toHaveCount(1)
        ->and($pointResult['matches'][0]['ticket_code'])->toBe('PST-PNT150M')
        ->and($pointResult['matches'][0]['distance_m'])->toBeBetween(145, 155)
        ->and($pointResult['matches'][0]['thumbnail_url'])->toContain('seed-before-thumb.webp');

    $linearResult = $service->findNearbyDuplicates($fx['linearCat']->id, $baseLat, $baseLng);
    expect($linearResult['radius_m'])->toBe(750)
        ->and($linearResult['matches'])->toHaveCount(1)
        ->and($linearResult['matches'][0]['ticket_code'])->toBe('PST-LIN650M')
        ->and($linearResult['matches'][0]['distance_m'])->toBeBetween(640, 660);
});

it('limits nearby matches to 5 closest active tickets sorted by ascending distance', function (): void {
    $fx = createDuplicateFixture();
    $service = new HaversineDuplicateService;

    $baseLat = -7.6350;
    $baseLng = 109.0180;

    foreach ([180, 30, 120, 60, 150, 90, 10] as $idx => $meters) {
        $latOffset = $meters / 111195.0;
        makeReportAt($fx, $fx['pointCat'], $baseLat + $latOffset, $baseLng, "PST-SORT0{$idx}");
    }

    $result = $service->findNearbyDuplicates($fx['pointCat']->id, $baseLat, $baseLng);

    expect($result['matches'])->toHaveCount(5);

    $distances = array_column($result['matches'], 'distance_m');
    $sorted = $distances;
    sort($sorted);

    expect($distances)->toBe($sorted)
        ->and($result['matches'][0]['ticket_code'])->toBe('PST-SORT06');
});
