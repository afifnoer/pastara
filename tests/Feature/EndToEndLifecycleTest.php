<?php

declare(strict_types=1);

use App\Enums\AuditActionType;
use App\Enums\DisputeStatus;
use App\Enums\IssueType;
use App\Enums\NotificationType;
use App\Enums\RejectionReasonCode;
use App\Enums\ReportStatus;
use App\Enums\ResolutionTrack;
use App\Enums\SlaZone;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Dispute;
use App\Models\District;
use App\Models\Notification;
use App\Models\OpdUnit;
use App\Models\Report;
use App\Models\User;
use App\Models\Village;
use Carbon\CarbonImmutable;
use Database\Seeders\CilacapSimulationSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Model::preventLazyLoading(false);
    $this->travelBack();
});

function makeE2eJpeg(string $name = 'photo.jpg', int $r = 90, int $g = 120, int $b = 150): UploadedFile
{
    $img = imagecreatetruecolor(120, 90);
    $bg = imagecolorallocate($img, $r, $g, $b);
    imagefill($img, 0, 0, $bg);
    ob_start();
    imagejpeg($img, null, 85);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return UploadedFile::fake()->createWithContent($name, $bytes);
}

function makeE2eExifJpeg(float $lat, float $lng, string $dateTimeWib, string $name = 'after.jpg'): UploadedFile
{
    $img = imagecreatetruecolor(120, 90);
    $bg = imagecolorallocate($img, 34, 139, 94);
    imagefill($img, 0, 0, $bg);
    ob_start();
    imagejpeg($img, null, 85);
    $raw = (string) ob_get_clean();
    imagedestroy($img);

    $toDms = static function (float $coord): array {
        $abs = abs($coord);
        $deg = (int) floor($abs);
        $minFloat = ($abs - $deg) * 60.0;
        $min = (int) floor($minFloat);
        $sec = (int) round(($minFloat - $min) * 60.0 * 100);

        return [[$deg, 1], [$min, 1], [$sec, 100]];
    };

    $packRationals = static function (array $rats): string {
        $bin = '';
        foreach ($rats as [$n, $d]) {
            $bin .= pack('NN', $n, $d);
        }

        return $bin;
    };

    $dateBytes = $dateTimeWib."\0";
    $ifd0Offset = 8;
    $exifIfdOffset = $ifd0Offset + 2 + (2 * 12) + 4;
    $gpsIfdOffset = $exifIfdOffset + 2 + 12 + 4;
    $dataOffset = $gpsIfdOffset + 2 + (4 * 12) + 4;

    $dateDataOffset = $dataOffset;
    $latDataOffset = $dateDataOffset + strlen($dateBytes);
    $lngDataOffset = $latDataOffset + 24;

    $ifd0 = pack('n', 2)
        .pack('nnNN', 0x8769, 4, 1, $exifIfdOffset)
        .pack('nnNN', 0x8825, 4, 1, $gpsIfdOffset)
        .pack('N', 0);

    $exifIfd = pack('n', 1)
        .pack('nnNN', 0x9003, 2, strlen($dateBytes), $dateDataOffset)
        .pack('N', 0);

    $latRef = ($lat < 0 ? 'S' : 'N')."\0\0\0";
    $lngRef = ($lng < 0 ? 'W' : 'E')."\0\0\0";

    $gpsIfd = pack('n', 4)
        .pack('nnN', 0x0001, 2, 2).$latRef
        .pack('nnNN', 0x0002, 5, 3, $latDataOffset)
        .pack('nnN', 0x0003, 2, 2).$lngRef
        .pack('nnNN', 0x0004, 5, 3, $lngDataOffset)
        .pack('N', 0);

    $tiff = "MM\x00\x2A".pack('N', $ifd0Offset)
        .$ifd0.$exifIfd.$gpsIfd
        .$dateBytes.$packRationals($toDms($lat)).$packRationals($toDms($lng));

    $app1Body = "Exif\x00\x00".$tiff;
    $app1 = "\xFF\xE1".pack('n', strlen($app1Body) + 2).$app1Body;

    return UploadedFile::fake()->createWithContent($name, substr($raw, 0, 2).$app1.substr($raw, 2));
}

function makeE2ePdf(string $name = 'rka.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n"
    );
}

it('executes SC-01 / TC-S-01 end-to-end: Track 1 operational PJU report closed on-time with rating and Dishub scorecard increment (TASK-BE-054)', function (): void {
    Storage::fake('public');
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $baseTime = CarbonImmutable::parse('2026-11-26 02:00:00', 'UTC');
    $this->travelTo($baseTime);

    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $adminDishub = User::where('email', 'admin.dishub@pastara.id')->firstOrFail();
    $officerDishub = User::where('email', 'petugas.dishub@pastara.id')->firstOrFail();
    $cat02 = Category::where('code', 'CAT-02')->firstOrFail();
    $district = District::where('name', 'Cilacap Tengah')->firstOrFail();
    $village = Village::where('district_id', $district->id)->firstOrFail();

    // Baseline Dishub physical_closed_count on public scorecard
    $baselineScorecard = $this->getJson('/api/v1/public/scorecard')->assertStatus(200);
    $baselineDishub = collect($baselineScorecard->json('data.opd_scorecard'))->firstWhere('code', 'DISHUB');
    $baselineDishubClosed = (int) $baselineDishub['physical_closed_count'];

    // 1. Citizen submits CAT-02 report -> ROUTED_TO_UNIT (DISHUB, 24h SLA)
    $lat = -7.7215;
    $lng = 109.0185;
    $createRes = $this->actingAs($warga, 'sanctum')
        ->post('/api/v1/reports', [
            'category_id' => $cat02->id,
            'district_id' => $district->id,
            'village_id' => $village->id,
            'latitude' => $lat,
            'longitude' => $lng,
            'address_detail' => 'Simpang Jl. Gatot Subroto depan pertokoan',
            'title' => 'Lampu PJU padam total sepanjang persimpangan',
            'description' => 'Tiga tiang lampu penerangan jalan umum mati sejak kemarin malam dan gelap sekali.',
            'is_secret' => 'false',
            'photos' => [makeE2eJpeg('pju-before.jpg')],
        ], ['Accept' => 'application/json']);

    $createRes->assertStatus(201)
        ->assertJsonPath('data.status', ReportStatus::ROUTED_TO_UNIT->value)
        ->assertJsonPath('data.opd_unit.code', 'DISHUB');

    $reportId = (int) $createRes->json('data.id');
    $ticketCode = (string) $createRes->json('data.ticket_code');
    expect(Report::findOrFail($reportId)->sla_hours_snapshot)->toBe(24);

    // 2. Admin Dishub assigns officer (TR-04 -> IN_PROGRESS, SLA 24h)
    $this->actingAs($adminDishub, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$reportId}/assign", [
            'officer_id' => $officerDishub->id,
            'note' => 'Segera periksa MCB panel dan ganti lampu LED PJU.',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.status', ReportStatus::IN_PROGRESS->value)
        ->assertJsonPath('data.resolution_track', ResolutionTrack::OPERATIONAL->value)
        ->assertJsonPath('data.assigned_officer.id', $officerDishub->id);

    // 3. After 10 hours, officer uploads valid geotagged AFTER evidence (TR-07 -> PENDING_CITIZEN_SIGN_OFF)
    $afterTime = $baseTime->addHours(10);
    $this->travelTo($afterTime);
    $exifWib = $afterTime->setTimezone('Asia/Jakarta')->subMinutes(15)->format('Y:m:d H:i:s');

    $this->actingAs($officerDishub, 'sanctum')
        ->post("/api/v1/officer/tasks/{$reportId}/evidence", [
            'action_note' => 'Kontaktor panel PJU telah diganti dan seluruh lampu kembali menyala terang.',
            'photos' => [makeE2eExifJpeg($lat + 0.0001, $lng, $exifWib, 'pju-after.jpg')],
        ], ['Accept' => 'application/json'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', ReportStatus::PENDING_CITIZEN_SIGN_OFF->value)
        ->assertJsonPath('data.sla.sla_zone', SlaZone::PAUSED->value)
        ->assertJsonPath('data.geotag.geotag_warning', false);

    // 4. Citizen approves sign-off (TR-08 -> VERIFIED_CLOSED) and submits 5/5 micro-rating
    $this->actingAs($warga, 'sanctum')
        ->postJson("/api/v1/reports/{$ticketCode}/sign-off/approve")
        ->assertStatus(200)
        ->assertJsonPath('data.status', ReportStatus::VERIFIED_CLOSED->value)
        ->assertJsonPath('data.sla.is_late', false)
        ->assertJsonPath('data.sla.is_sla_overdue', false);

    $this->actingAs($warga, 'sanctum')
        ->postJson("/api/v1/reports/{$ticketCode}/rating", [
            'speed_score' => 5,
            'physical_quality_score' => 5,
            'comment' => 'Respons Dishub cepat sekali, jalanan sudah terang kembali.',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.physical_quality_score', 5);

    // 5. Recalculate scorecard & verify Dishub physical resolution incremented
    $this->artisan('pastara:recalculate-scorecard')->assertSuccessful();
    $updatedScorecard = $this->getJson('/api/v1/public/scorecard')->assertStatus(200);
    $updatedDishub = collect($updatedScorecard->json('data.opd_scorecard'))->firstWhere('code', 'DISHUB');

    expect((int) $updatedDishub['physical_closed_count'])->toBe($baselineDishubClosed + 1)
        ->and(Report::findOrFail($reportId)->status)->toBe(ReportStatus::VERIFIED_CLOSED);
});

it('executes SC-02 / TC-S-02 end-to-end: duplicate report detected within 120m triggers +1 Upvote and watcher enrollment without creating a second ticket (TASK-BE-054)', function (): void {
    Storage::fake('public');
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $warga1 = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $warga2 = User::where('email', 'warga.sim01@pastara.id')->firstOrFail();
    $cat02 = Category::where('code', 'CAT-02')->firstOrFail();
    $district = District::where('name', 'Kroya')->firstOrFail();
    $village = Village::where('district_id', $district->id)->firstOrFail();

    // 1. Citizen 1 creates PJU report
    $createRes = $this->actingAs($warga1, 'sanctum')
        ->post('/api/v1/reports', [
            'category_id' => $cat02->id,
            'district_id' => $district->id,
            'village_id' => $village->id,
            'latitude' => -7.6320,
            'longitude' => 109.2450,
            'address_detail' => 'Dekat Stasiun Kroya sisi selatan',
            'title' => 'Lampu PJU dekat persimpangan Kroya mati',
            'description' => 'Penerangan jalan umum padam total sehingga persimpangan rawan kecelakaan.',
            'is_secret' => 'false',
            'photos' => [makeE2eJpeg('kroya-pju.jpg')],
        ], ['Accept' => 'application/json']);

    $createRes->assertStatus(201);
    $reportId = (int) $createRes->json('data.id');
    $ticketCode = (string) $createRes->json('data.ticket_code');
    $totalReportsAfterFirst = Report::count();

    // 2. Citizen 2 queries /reports/nearby ~120m away -> Soft Confirmation matches Citizen 1's report
    $nearbyRes = $this->actingAs($warga2, 'sanctum')
        ->getJson('/api/v1/reports/nearby?'.http_build_query([
            'category_id' => $cat02->id,
            'latitude' => -7.63308,
            'longitude' => 109.2450,
        ]));

    $nearbyRes->assertStatus(200)
        ->assertJsonPath('data.radius_m', 200);

    $matched = collect($nearbyRes->json('data.matches'))->firstWhere('ticket_code', $ticketCode);
    expect($matched)->not->toBeNull()
        ->and($matched['distance_m'])->toBeBetween(110, 130);

    // 3. Citizen 2 chooses "+1 Dukung" instead of creating a duplicate ticket
    $this->actingAs($warga2, 'sanctum')
        ->postJson("/api/v1/reports/{$reportId}/upvote")
        ->assertStatus(200)
        ->assertJsonPath('data.upvote_count', 1)
        ->assertJsonPath('data.is_watching', true);

    // Duplicate upvote rejected with 409
    $this->actingAs($warga2, 'sanctum')
        ->postJson("/api/v1/reports/{$reportId}/upvote")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ALREADY_UPVOTED');

    // No new ticket created; Citizen 2 automatically sees ticket under watching tab
    expect(Report::count())->toBe($totalReportsAfterFirst);

    $watchingRes = $this->actingAs($warga2, 'sanctum')
        ->getJson('/api/v1/citizen/reports?tab=watching')
        ->assertStatus(200);

    expect(collect($watchingRes->json('data'))->pluck('ticket_code')->all())->toContain($ticketCode);
});

it('executes SC-03 / TC-S-03 end-to-end: Track 2 APBD lifecycle keeps scorecard honest across BUDGET_SCHEDULED, milestones, execution, and VERIFIED_CLOSED (TASK-BE-054)', function (): void {
    Storage::fake('public');
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $baseTime = CarbonImmutable::parse('2026-11-27 02:00:00', 'UTC');
    $this->travelTo($baseTime);

    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $adminDpupr = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $officerDpupr = User::where('email', 'petugas.dpupr@pastara.id')->firstOrFail();
    $cat01 = Category::where('code', 'CAT-01')->firstOrFail();
    $district = District::where('name', 'Majenang')->firstOrFail();
    $village = Village::where('district_id', $district->id)->where('name', 'Sadabumi')->firstOrFail();

    $baselineGlobal = $this->getJson('/api/v1/public/scorecard')->assertStatus(200);
    $baselineApbd = (int) $baselineGlobal->json('data.summary.budget_scheduled_apbd');
    $baselineClosed = (int) $baselineGlobal->json('data.summary.verified_closed_physical');

    // 1. Citizen reports damaged Sadabumi road (CAT-01 -> DPUPR)
    $lat = -7.2890;
    $lng = 108.7650;
    $createRes = $this->actingAs($warga, 'sanctum')
        ->post('/api/v1/reports', [
            'category_id' => $cat01->id,
            'district_id' => $district->id,
            'village_id' => $village->id,
            'latitude' => $lat,
            'longitude' => $lng,
            'address_detail' => 'Ruas jalan kabupaten KM 4 Desa Sadabumi',
            'title' => 'Jalan rusak parah butuh rekonstruksi beton APBD',
            'description' => 'Struktur badan jalan sepanjang 400 meter amblas dan memerlukan penganggaran APBD 2027.',
            'is_secret' => 'false',
            'photos' => [makeE2eJpeg('sadabumi-before.jpg')],
        ], ['Accept' => 'application/json']);

    $createRes->assertStatus(201)
        ->assertJsonPath('data.status', ReportStatus::ROUTED_TO_UNIT->value)
        ->assertJsonPath('data.opd_unit.code', 'DPUPR');

    $reportId = (int) $createRes->json('data.id');
    $ticketCode = (string) $createRes->json('data.ticket_code');

    // 2. DPUPR schedules Track 2 APBD (TR-05 -> BUDGET_SCHEDULED)
    $this->actingAs($adminDpupr, 'sanctum')
        ->post("/api/v1/unit/reports/{$reportId}/schedule-apbd", [
            'fiscal_year' => 2027,
            'proposal_ref_number' => 'RKA-DPUPR-2027-SADA-E2E',
            'target_quarter' => 'Q2',
            'estimated_budget' => 1250000000,
            'note' => 'Dimasukkan dalam usulan rekonstruksi APBD Kabupaten Cilacap TA 2027 Q2.',
            'document_proof' => makeE2ePdf('rka-sada.pdf'),
        ], ['Accept' => 'application/json'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', ReportStatus::BUDGET_SCHEDULED->value)
        ->assertJsonPath('data.resolution_track', ResolutionTrack::APBD->value);

    // Honest scorecard check: budget_scheduled_apbd +1, verified_closed_physical unchanged!
    $midScorecard = $this->getJson('/api/v1/public/scorecard')->assertStatus(200);
    expect((int) $midScorecard->json('data.summary.budget_scheduled_apbd'))->toBe($baselineApbd + 1)
        ->and((int) $midScorecard->json('data.summary.verified_closed_physical'))->toBe($baselineClosed);

    // 3. Quarterly milestone update
    $this->actingAs($adminDpupr, 'sanctum')
        ->post("/api/v1/unit/reports/{$reportId}/apbd-milestones", [
            'quarter_label' => '2027-Q1',
            'progress_note' => 'Dokumen lelang konstruksi sudah tayang di LPSE Cilacap dan menunggu penetapan pemenang.',
            'attachment' => makeE2ePdf('lpse-tahap1.pdf'),
        ], ['Accept' => 'application/json'])
        ->assertStatus(201)
        ->assertJsonPath('data.status', ReportStatus::BUDGET_SCHEDULED->value);

    // 4. Start physical execution (TR-06 -> IN_PROGRESS)
    $targetDate = $baseTime->setTimezone('Asia/Jakarta')->addDays(20)->toDateString();
    $this->actingAs($adminDpupr, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$reportId}/start-execution", [
            'execution_target_date' => $targetDate,
            'officer_id' => $officerDpupr->id,
            'note' => 'Pekerjaan pengaspalan dan perkerasan beton resmi dimulai di lapangan.',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.status', ReportStatus::IN_PROGRESS->value);

    // 5. Officer uploads AFTER evidence (TR-07 -> PENDING_CITIZEN_SIGN_OFF)
    $execDoneTime = $baseTime->addDays(10);
    $this->travelTo($execDoneTime);
    $exifWib = $execDoneTime->setTimezone('Asia/Jakarta')->subMinutes(20)->format('Y:m:d H:i:s');

    $this->actingAs($officerDpupr, 'sanctum')
        ->post("/api/v1/officer/tasks/{$reportId}/evidence", [
            'action_note' => 'Rekonstruksi badan jalan sepanjang 400 meter telah selesai dan dibuka untuk umum.',
            'photos' => [makeE2eExifJpeg($lat + 0.0005, $lng, $exifWib, 'sadabumi-after.jpg')],
        ], ['Accept' => 'application/json'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', ReportStatus::PENDING_CITIZEN_SIGN_OFF->value)
        ->assertJsonPath('data.geotag.geotag_warning', false);

    // 6. Citizen approves sign-off (TR-08 -> VERIFIED_CLOSED)
    $this->actingAs($warga, 'sanctum')
        ->postJson("/api/v1/reports/{$ticketCode}/sign-off/approve")
        ->assertStatus(200)
        ->assertJsonPath('data.status', ReportStatus::VERIFIED_CLOSED->value);

    // Final honest scorecard: APBD back to baseline, verified_closed_physical +1
    $finalScorecard = $this->getJson('/api/v1/public/scorecard')->assertStatus(200);
    expect((int) $finalScorecard->json('data.summary.budget_scheduled_apbd'))->toBe($baselineApbd)
        ->and((int) $finalScorecard->json('data.summary.verified_closed_physical'))->toBe($baselineClosed + 1);
});

it('executes SC-04 / TC-S-04 end-to-end: geotag-warned false claim -> citizen veto -> Inspectorate REOPEN (48h) -> cycle 2 fix -> VERIFIED_CLOSED with late penalty (TASK-BE-054)', function (): void {
    Storage::fake('public');
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $baseTime = CarbonImmutable::parse('2026-11-28 03:00:00', 'UTC');
    $this->travelTo($baseTime);

    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $adminDishub = User::where('email', 'admin.dishub@pastara.id')->firstOrFail();
    $officerDishub = User::where('email', 'petugas.dishub@pastara.id')->firstOrFail();
    $auditor = User::where('email', 'auditor.inspektorat@pastara.id')->firstOrFail();
    $cat02 = Category::where('code', 'CAT-02')->firstOrFail();
    $district = District::where('name', 'Cilacap Utara')->firstOrFail();
    $village = Village::where('district_id', $district->id)->firstOrFail();

    $lat = -7.6900;
    $lng = 109.0350;

    // 1. Report + Assign
    $createRes = $this->actingAs($warga, 'sanctum')
        ->post('/api/v1/reports', [
            'category_id' => $cat02->id,
            'district_id' => $district->id,
            'village_id' => $village->id,
            'latitude' => $lat,
            'longitude' => $lng,
            'address_detail' => 'Jalan Tentara Pelajar Gumilir',
            'title' => 'Lampu PJU depan gang mati berhari-hari',
            'description' => 'Lampu PJU nomor tiang 14 tidak menyala sama sekali di malam hari.',
            'is_secret' => 'false',
            'photos' => [makeE2eJpeg('veto-before.jpg')],
        ], ['Accept' => 'application/json'])
        ->assertStatus(201);

    $reportId = (int) $createRes->json('data.id');
    $ticketCode = (string) $createRes->json('data.ticket_code');

    $this->actingAs($adminDishub, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$reportId}/assign", ['officer_id' => $officerDishub->id])
        ->assertStatus(200);

    // 2. Cycle 1 AFTER evidence with ~850m geotag deviation -> geotag_warning = true
    $cycle1Time = $baseTime->addHours(4);
    $this->travelTo($cycle1Time);
    $exif1 = $cycle1Time->setTimezone('Asia/Jakarta')->subMinutes(5)->format('Y:m:d H:i:s');

    $this->actingAs($officerDishub, 'sanctum')
        ->post("/api/v1/officer/tasks/{$reportId}/evidence", [
            'action_note' => 'Lampu PJU sudah diperbaiki dan menyala normal.',
            'photos' => [makeE2eExifJpeg($lat + 0.0077, $lng, $exif1, 'after-far-850m.jpg')],
        ], ['Accept' => 'application/json'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', ReportStatus::PENDING_CITIZEN_SIGN_OFF->value)
        ->assertJsonPath('data.geotag.geotag_warning', true);

    // 3. Citizen vetoes with counter-evidence photo (TR-09 -> DISPUTED)
    $this->actingAs($warga, 'sanctum')
        ->post("/api/v1/reports/{$ticketCode}/sign-off/reject", [
            'reason' => 'Foto petugas bukan di lokasi tiang 14, lampu PJU di depan gang kami masih padam total.',
            'photos' => [makeE2eJpeg('citizen-veto-proof.jpg')],
        ], ['Accept' => 'application/json'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', ReportStatus::DISPUTED->value);

    // Appears on Inspectorate disputed tab
    $disputedList = $this->actingAs($auditor, 'sanctum')
        ->getJson('/api/v1/inspectorate/reports?tab=disputed')
        ->assertStatus(200);
    expect(collect($disputedList->json('data'))->pluck('ticket_code')->all())->toContain($ticketCode);

    // 4. Inspectorate decides REOPENED (48h) (TR-11 -> REOPENED_BY_AUDIT, cycle 2, is_late = true)
    $dispute = Dispute::where('report_id', $reportId)->where('status', DisputeStatus::PENDING)->firstOrFail();

    $this->actingAs($auditor, 'sanctum')
        ->postJson("/api/v1/inspectorate/disputes/{$dispute->id}/resolve", [
            'decision' => 'REOPENED',
            'audit_notes' => 'Geotag foto bukti melenceng lebih dari 800 meter. Dishub wajib memperbaiki ulang dalam 48 jam.',
            'reopen_sla_hours' => 48,
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.status', ReportStatus::REOPENED_BY_AUDIT->value)
        ->assertJsonPath('data.current_cycle', 2)
        ->assertJsonPath('data.sla.is_late', true);

    // 5. Officer uploads Cycle 2 AFTER evidence at exact location (TR-13 -> PENDING_CITIZEN_SIGN_OFF)
    $cycle2Time = $cycle1Time->addHours(6);
    $this->travelTo($cycle2Time);
    $exif2 = $cycle2Time->setTimezone('Asia/Jakarta')->subMinutes(10)->format('Y:m:d H:i:s');

    $cycle2Res = $this->actingAs($officerDishub, 'sanctum')
        ->post("/api/v1/officer/tasks/{$reportId}/evidence", [
            'action_note' => 'Tiang PJU nomor 14 telah diganti ballast dan lampu baru langsung di titik aduan.',
            'photos' => [makeE2eExifJpeg($lat + 0.0001, $lng, $exif2, 'after-cycle2-valid.jpg')],
        ], ['Accept' => 'application/json']);

    $cycle2Res->assertStatus(200)
        ->assertJsonPath('data.status', ReportStatus::PENDING_CITIZEN_SIGN_OFF->value)
        ->assertJsonPath('data.current_cycle', 2)
        ->assertJsonPath('data.geotag.geotag_warning', false);

    // 6. Citizen approves -> VERIFIED_CLOSED, penalty is_late remains true, full audit timeline recorded
    $this->actingAs($warga, 'sanctum')
        ->postJson("/api/v1/reports/{$ticketCode}/sign-off/approve")
        ->assertStatus(200)
        ->assertJsonPath('data.status', ReportStatus::VERIFIED_CLOSED->value)
        ->assertJsonPath('data.sla.is_late', true);

    $report = Report::findOrFail($reportId);
    $actions = $report->auditLogs()->orderBy('id')->pluck('action_type')->map(fn ($a) => $a->value)->all();

    expect($actions)->toContain(
        AuditActionType::SUBMITTED->value,
        AuditActionType::ROUTED->value,
        AuditActionType::ASSIGNED->value,
        AuditActionType::EVIDENCE_SUBMITTED->value,
        AuditActionType::CITIZEN_REJECTED->value,
        AuditActionType::AUDIT_REOPENED->value,
        AuditActionType::CITIZEN_APPROVED->value,
    );
});

it('executes SC-05 / TC-S-05 end-to-end: secret school report hidden from public, masked from unit, visible to Inspectorate, and closed via secret_token (TASK-BE-054)', function (): void {
    Storage::fake('public');
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $baseTime = CarbonImmutable::parse('2026-11-29 03:00:00', 'UTC');
    $this->travelTo($baseTime);

    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $adminDisdikbud = User::where('email', 'admin.disdikbud@pastara.id')->firstOrFail();
    $auditor = User::where('email', 'auditor.inspektorat@pastara.id')->firstOrFail();
    $disdikbudUnit = OpdUnit::where('code', 'DISDIKBUD')->firstOrFail();
    $cat06 = Category::where('code', 'CAT-06')->firstOrFail();
    $district = District::where('name', 'Patimuan')->firstOrFail();
    $village = Village::where('district_id', $district->id)->where('name', 'Purwodadi')->firstOrFail();

    $officerDisdikbud = User::create([
        'name' => 'Petugas Pengawas Disdikbud',
        'email' => 'petugas.disdikbud.e2e@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::PETUGAS,
        'opd_unit_id' => $disdikbudUnit->id,
        'is_active' => true,
    ]);

    $lat = -7.5890;
    $lng = 108.7740;

    // 1. Parent submits secret CAT-06 report -> receives one-time secret_token
    $createRes = $this->actingAs($warga, 'sanctum')
        ->post('/api/v1/reports', [
            'category_id' => $cat06->id,
            'district_id' => $district->id,
            'village_id' => $village->id,
            'latitude' => $lat,
            'longitude' => $lng,
            'address_detail' => 'Lingkungan SD Negeri Purwodadi',
            'title' => 'Laporan rahasia keamanan lingkungan sekolah',
            'description' => 'Memohon tindak lanjut cepat atas indikasi perundungan di sekolah tanpa membuka identitas pelapor.',
            'is_secret' => 'true',
            'photos' => [makeE2eJpeg('secret-before.jpg')],
        ], ['Accept' => 'application/json']);

    $createRes->assertStatus(201)
        ->assertJsonPath('data.is_secret', true)
        ->assertJsonPath('data.opd_unit.code', 'DISDIKBUD');

    $reportId = (int) $createRes->json('data.id');
    $ticketCode = (string) $createRes->json('data.ticket_code');
    $secretToken = (string) $createRes->json('data.secret_token');

    expect($secretToken)->toMatch('/^TKN-[A-Z0-9]{4}-[A-Z0-9]{4}$/');

    // 2. Strictly absent from public map, public listing, public detail (404), and citizen nearby duplicate check
    $mapCodes = collect($this->getJson('/api/v1/public/map-markers')->assertStatus(200)->json('data'))->pluck('ticket_code')->all();
    $listCodes = collect($this->getJson('/api/v1/public/reports?q='.$ticketCode)->assertStatus(200)->json('data'))->pluck('ticket_code')->all();
    $this->getJson("/api/v1/public/reports/{$ticketCode}")->assertStatus(404);

    $nearbyCodes = collect(
        $this->actingAs($warga, 'sanctum')
            ->getJson('/api/v1/reports/nearby?'.http_build_query([
                'category_id' => $cat06->id,
                'latitude' => $lat,
                'longitude' => $lng,
            ]))
            ->assertStatus(200)
            ->json('data.matches')
    )->pluck('ticket_code')->all();

    expect($mapCodes)->not->toContain($ticketCode)
        ->and($listCodes)->not->toContain($ticketCode)
        ->and($nearbyCodes)->not->toContain($ticketCode);

    // 3. Disdikbud Admin views masked reporter (Hukum 4), assigns officer, officer uploads AFTER evidence
    $this->actingAs($adminDisdikbud, 'sanctum')
        ->getJson("/api/v1/unit/reports/{$reportId}")
        ->assertStatus(200)
        ->assertJsonPath('data.reporter.name', 'Pelapor Rahasia')
        ->assertJsonPath('data.reporter.email', null)
        ->assertJsonPath('data.reporter.phone', null);

    $this->actingAs($adminDisdikbud, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$reportId}/assign", ['officer_id' => $officerDisdikbud->id])
        ->assertStatus(200);

    $afterTime = $baseTime->addHours(5);
    $this->travelTo($afterTime);
    $exifWib = $afterTime->setTimezone('Asia/Jakarta')->subMinutes(10)->format('Y:m:d H:i:s');

    $this->actingAs($officerDisdikbud, 'sanctum')
        ->post("/api/v1/officer/tasks/{$reportId}/evidence", [
            'action_note' => 'Tim pendampingan Disdikbud telah melaksanakan mediasi dan pengamanan sekolah.',
            'photos' => [makeE2eExifJpeg($lat, $lng, $exifWib, 'secret-after.jpg')],
        ], ['Accept' => 'application/json'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', ReportStatus::PENDING_CITIZEN_SIGN_OFF->value);

    // 4. Inspectorate sees real reporter identity
    $inspList = $this->actingAs($auditor, 'sanctum')
        ->getJson("/api/v1/inspectorate/reports?tab=all&opd_unit_id={$disdikbudUnit->id}&category_id={$cat06->id}")
        ->assertStatus(200);

    $inspItem = collect($inspList->json('data'))->firstWhere('ticket_code', $ticketCode);
    expect($inspItem)->not->toBeNull()
        ->and($inspItem['reporter']['email'])->toBe('warga.demo@pastara.id');

    // 5. Anonymous tracking & sign-off using secret_token only (no Bearer auth)
    Auth::forgetGuards();
    $this->postJson('/api/v1/public/track', ['ticket_code' => $ticketCode])
        ->assertStatus(200)
        ->assertJsonPath('data.requires_secret_token', true);

    $this->postJson('/api/v1/public/track', ['ticket_code' => $ticketCode, 'secret_token' => 'TKN-0000-0000'])
        ->assertStatus(404);

    $this->postJson('/api/v1/public/track', ['ticket_code' => $ticketCode, 'secret_token' => $secretToken])
        ->assertStatus(200)
        ->assertJsonPath('data.report.ticket_code', $ticketCode)
        ->assertJsonPath('data.report.status', ReportStatus::PENDING_CITIZEN_SIGN_OFF->value);

    $this->postJson("/api/v1/reports/{$ticketCode}/sign-off/approve", ['secret_token' => $secretToken])
        ->assertStatus(200)
        ->assertJsonPath('data.status', ReportStatus::VERIFIED_CLOSED->value)
        ->assertJsonPath('data.reporter.name', 'Pelapor Rahasia');
});

it('executes SC-06 / TC-S-06 end-to-end: SLA overdue escalates to Inspectorate dashboard and lowers DLH compliance score (TASK-BE-054)', function (): void {
    Storage::fake('public');
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $baseTime = CarbonImmutable::parse('2026-11-30 01:00:00', 'UTC');
    $this->travelTo($baseTime);

    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $adminDlh = User::where('email', 'admin.dlh@pastara.id')->firstOrFail();
    $officerDlh = User::where('email', 'petugas.dlh@pastara.id')->firstOrFail();
    $auditor = User::where('email', 'auditor.inspektorat@pastara.id')->firstOrFail();
    $cat03 = Category::where('code', 'CAT-03')->firstOrFail();
    $district = District::where('name', 'Jeruklegi')->firstOrFail();
    $village = Village::where('district_id', $district->id)->firstOrFail();

    // Recalculate baseline DLH score
    $this->artisan('pastara:recalculate-scorecard')->assertSuccessful();
    $baselineScorecard = $this->getJson('/api/v1/public/scorecard')->assertStatus(200);
    $baselineDlhScore = (float) collect($baselineScorecard->json('data.opd_scorecard'))
        ->firstWhere('code', 'DLH')['compliance_score'];

    // 1. Citizen creates CAT-03 report (DLH, 48h SLA) & DLH assigns officer
    $lat = -7.6410;
    $lng = 109.0210;
    $createRes = $this->actingAs($warga, 'sanctum')
        ->post('/api/v1/reports', [
            'category_id' => $cat03->id,
            'district_id' => $district->id,
            'village_id' => $village->id,
            'latitude' => $lat,
            'longitude' => $lng,
            'address_detail' => 'Pinggir jalan pasar Jeruklegi',
            'title' => 'Tumpukan sampah menumpuk berhari-hari di pasar',
            'description' => 'Sampah meluber hingga bahu jalan dan menimbulkan bau menyengat.',
            'is_secret' => 'false',
            'photos' => [makeE2eJpeg('dlh-before.jpg')],
        ], ['Accept' => 'application/json'])
        ->assertStatus(201);

    $reportId = (int) $createRes->json('data.id');
    $ticketCode = (string) $createRes->json('data.ticket_code');

    $this->actingAs($adminDlh, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$reportId}/assign", ['officer_id' => $officerDlh->id])
        ->assertStatus(200);

    // 2. Advance 35h (>=70% of 48h) -> Yellow warning
    $this->travelTo($baseTime->addHours(35));
    $this->artisan('pastara:evaluate-sla')->assertSuccessful();
    expect(Report::findOrFail($reportId)->sla_warning_notified_at)->not->toBeNull()
        ->and(Report::findOrFail($reportId)->is_sla_overdue)->toBeFalse();

    // 3. Advance 50h (>48h) -> Red overdue, visible in Inspectorate sla_overdue tab
    $overdueTime = $baseTime->addHours(50);
    $this->travelTo($overdueTime);
    $this->artisan('pastara:evaluate-sla')->assertSuccessful();

    $overdueReport = Report::findOrFail($reportId);
    expect($overdueReport->is_sla_overdue)->toBeTrue()
        ->and($overdueReport->is_late)->toBeTrue()
        ->and($overdueReport->status)->toBe(ReportStatus::IN_PROGRESS);

    $inspOverdue = $this->actingAs($auditor, 'sanctum')
        ->getJson('/api/v1/inspectorate/reports?tab=sla_overdue')
        ->assertStatus(200);
    expect(collect($inspOverdue->json('data'))->pluck('ticket_code')->all())->toContain($ticketCode);

    // 4. Late evidence upload & citizen approval still succeeds while preserving is_late = true
    $exifWib = $overdueTime->setTimezone('Asia/Jakarta')->subMinutes(10)->format('Y:m:d H:i:s');
    $this->actingAs($officerDlh, 'sanctum')
        ->post("/api/v1/officer/tasks/{$reportId}/evidence", [
            'action_note' => 'Tumpukan sampah telah dibersihkan meskipun melewati batas waktu SLA.',
            'photos' => [makeE2eExifJpeg($lat, $lng, $exifWib, 'dlh-after-late.jpg')],
        ], ['Accept' => 'application/json'])
        ->assertStatus(200);

    $this->actingAs($warga, 'sanctum')
        ->postJson("/api/v1/reports/{$ticketCode}/sign-off/approve")
        ->assertStatus(200)
        ->assertJsonPath('data.status', ReportStatus::VERIFIED_CLOSED->value)
        ->assertJsonPath('data.sla.is_late', true);

    // 5. Recalculate scorecard -> DLH compliance score decreases due to late completion
    $this->artisan('pastara:recalculate-scorecard')->assertSuccessful();
    $afterScorecard = $this->getJson('/api/v1/public/scorecard')->assertStatus(200);
    $afterDlhScore = (float) collect($afterScorecard->json('data.opd_scorecard'))
        ->firstWhere('code', 'DLH')['compliance_score'];

    expect($afterDlhScore)->toBeLessThan($baselineDlhScore);
});

it('executes SC-07 & SC-08 / TC-S-07 end-to-end: village-to-DPUPR reroute recalculates snapshots and hoax rejection notifies reporter (TASK-BE-054)', function (): void {
    Storage::fake('public');
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $adminDesa = User::where('email', 'admin.sadabumi@pastara.id')->firstOrFail();
    $adminDpupr = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $dpuprUnit = OpdUnit::where('code', 'DPUPR')->firstOrFail();
    $cat01 = Category::where('code', 'CAT-01')->firstOrFail();
    $cat05 = Category::where('code', 'CAT-05')->firstOrFail();
    $district = District::where('name', 'Majenang')->firstOrFail();
    $sadabumi = Village::where('district_id', $district->id)->where('name', 'Sadabumi')->firstOrFail();

    // --- SC-07: Reroute CAT-05 (Desa Sadabumi) -> CAT-01 (DPUPR) ---
    $rerouteCreate = $this->actingAs($warga, 'sanctum')
        ->post('/api/v1/reports', [
            'category_id' => $cat05->id,
            'district_id' => $district->id,
            'village_id' => $sadabumi->id,
            'latitude' => -7.2910,
            'longitude' => 108.7680,
            'address_detail' => 'Jalan aspal penghubung antar desa di Sadabumi',
            'title' => 'Jalan aspal penghubung antar desa rusak',
            'description' => 'Aspal mengelupas sepanjang ruas jalan utama yang ternyata kewenangan kabupaten.',
            'is_secret' => 'false',
            'photos' => [makeE2eJpeg('reroute-before.jpg')],
        ], ['Accept' => 'application/json']);

    $rerouteCreate->assertStatus(201)
        ->assertJsonPath('data.opd_unit.code', 'DESA_SADABUMI');

    $rerouteReportId = (int) $rerouteCreate->json('data.id');
    $rerouteCode = (string) $rerouteCreate->json('data.ticket_code');
    expect(Report::findOrFail($rerouteReportId)->sla_hours_snapshot)->toBe($cat05->default_sla_hours);

    $this->actingAs($adminDesa, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$rerouteReportId}/reroute", [
            'target_opd_unit_id' => $dpuprUnit->id,
            'new_category_id' => $cat01->id,
            'note' => 'Status ruas jalan merupakan kewenangan DPUPR Kabupaten Cilacap, diteruskan ke DPUPR.',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.status', ReportStatus::ROUTED_TO_UNIT->value)
        ->assertJsonPath('data.opd_unit.code', 'DPUPR')
        ->assertJsonPath('data.category.code', 'CAT-01')
        ->assertJsonPath('data.sla.sla_hours_snapshot', $cat01->default_sla_hours)
        ->assertJsonPath('data.geotag.geotag_tolerance_snapshot_m', $cat01->geotag_tolerance_m);

    $rerouted = Report::findOrFail($rerouteReportId);
    expect($rerouted->issue_type_snapshot)->toBe(IssueType::LINEAR)
        ->and($rerouted->duplicate_radius_snapshot_m)->toBe($cat01->duplicate_radius_m);

    // Public detail audit_timeline displays REROUTED event & Admin Desa loses access (404)
    $publicDetail = $this->getJson("/api/v1/public/reports/{$rerouteCode}")->assertStatus(200);
    expect(collect($publicDetail->json('data.audit_timeline'))->pluck('action_type')->all())
        ->toContain(AuditActionType::REROUTED->value);

    $this->actingAs($adminDesa, 'sanctum')
        ->getJson("/api/v1/unit/reports/{$rerouteReportId}")
        ->assertStatus(404);

    $this->actingAs($adminDpupr, 'sanctum')
        ->getJson("/api/v1/unit/reports/{$rerouteReportId}")
        ->assertStatus(200);

    // --- SC-08: Reject Hoaks ---
    $hoaxCreate = $this->actingAs($warga, 'sanctum')
        ->post('/api/v1/reports', [
            'category_id' => $cat01->id,
            'district_id' => $district->id,
            'village_id' => $sadabumi->id,
            'latitude' => -7.2950,
            'longitude' => 108.7710,
            'address_detail' => 'Titik laporan tidak sesuai fakta',
            'title' => 'Laporan fiktif jembatan putus total',
            'description' => 'Laporan yang foto buktinya diambil dari internet dan tidak sesuai lokasi.',
            'is_secret' => 'false',
            'photos' => [makeE2eJpeg('hoax-before.jpg')],
        ], ['Accept' => 'application/json'])
        ->assertStatus(201);

    $hoaxId = (int) $hoaxCreate->json('data.id');

    $this->actingAs($adminDpupr, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$hoaxId}/reject", [
            'reason_code' => RejectionReasonCode::HOAX->value,
            'note' => 'Hasil pengecekan lapangan membuktikan kondisi jembatan normal dan foto bukan dari Cilacap.',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.status', ReportStatus::REJECTED->value)
        ->assertJsonPath('data.rejection_reason_code', RejectionReasonCode::HOAX->value);

    // Reporter receives REPORT_REJECTED in-app notification
    $notifRes = $this->actingAs($warga, 'sanctum')
        ->getJson('/api/v1/notifications')
        ->assertStatus(200);

    $rejectedNotif = collect($notifRes->json('data.items'))
        ->first(fn (array $n): bool => (int) ($n['report']['id'] ?? 0) === $hoaxId && $n['type'] === NotificationType::REPORT_REJECTED->value);

    expect($rejectedNotif)->not->toBeNull();
});

it('executes SC-09 / TC-S-08 end-to-end: passive reporter receives 24h reminder and ticket auto-verifies after 72h (TASK-BE-054)', function (): void {
    Storage::fake('public');
    $this->seed(CilacapSimulationSeeder::class);
    Model::preventLazyLoading(true);

    $baseTime = CarbonImmutable::parse('2026-12-01 02:00:00', 'UTC');
    $this->travelTo($baseTime);

    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $adminDishub = User::where('email', 'admin.dishub@pastara.id')->firstOrFail();
    $officerDishub = User::where('email', 'petugas.dishub@pastara.id')->firstOrFail();
    $cat02 = Category::where('code', 'CAT-02')->firstOrFail();
    $district = District::where('name', 'Cilacap Selatan')->firstOrFail();
    $village = Village::where('district_id', $district->id)->firstOrFail();

    $lat = -7.7350;
    $lng = 109.0120;

    // 1. Report -> Assign -> Officer uploads AFTER evidence
    $createRes = $this->actingAs($warga, 'sanctum')
        ->post('/api/v1/reports', [
            'category_id' => $cat02->id,
            'district_id' => $district->id,
            'village_id' => $village->id,
            'latitude' => $lat,
            'longitude' => $lng,
            'address_detail' => 'Jalan Jenderal Sudirman Tambakreja',
            'title' => 'Lampu PJU berkedip dan hampir padam',
            'description' => 'Lampu PJU di trotoar selatan berkedip terus menerus sepanjang malam.',
            'is_secret' => 'false',
            'photos' => [makeE2eJpeg('passive-before.jpg')],
        ], ['Accept' => 'application/json'])
        ->assertStatus(201);

    $reportId = (int) $createRes->json('data.id');
    $ticketCode = (string) $createRes->json('data.ticket_code');

    $this->actingAs($adminDishub, 'sanctum')
        ->postJson("/api/v1/unit/reports/{$reportId}/assign", ['officer_id' => $officerDishub->id])
        ->assertStatus(200);

    $submittedAt = $baseTime->addHours(3);
    $this->travelTo($submittedAt);
    $exifWib = $submittedAt->setTimezone('Asia/Jakarta')->subMinutes(5)->format('Y:m:d H:i:s');

    $this->actingAs($officerDishub, 'sanctum')
        ->post("/api/v1/officer/tasks/{$reportId}/evidence", [
            'action_note' => 'Lampu PJU telah diganti baru dan menyala stabil.',
            'photos' => [makeE2eExifJpeg($lat, $lng, $exifWib, 'passive-after.jpg')],
        ], ['Accept' => 'application/json'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', ReportStatus::PENDING_CITIZEN_SIGN_OFF->value);

    // 2. Advance +50h (22h remaining <= 24h) -> Reminder fired once
    $this->travelTo($submittedAt->addHours(50));
    $this->artisan('pastara:auto-verify-signoff')->assertSuccessful();

    $report = Report::findOrFail($reportId);
    expect($report->status)->toBe(ReportStatus::PENDING_CITIZEN_SIGN_OFF)
        ->and($report->signoff_reminder_sent_at)->not->toBeNull()
        ->and(Notification::where('report_id', $reportId)->where('type', NotificationType::SIGN_OFF_REMINDER_24H->value)->count())->toBe(1);

    // 3. Advance +73h (>72h) -> TR-10 AUTO_VERIFIED_CLOSED
    $this->travelTo($submittedAt->addHours(73));
    $this->artisan('pastara:auto-verify-signoff')->assertSuccessful();

    $report->refresh();
    expect($report->status)->toBe(ReportStatus::AUTO_VERIFIED_CLOSED)
        ->and($report->sla_stopped_at)->not->toBeNull()
        ->and($report->auditLogs()->where('action_type', AuditActionType::AUTO_VERIFIED)->count())->toBe(1)
        ->and(Notification::where('report_id', $reportId)->where('type', NotificationType::REPORT_CLOSED->value)->exists())->toBeTrue();

    // 4. Subsequent sign-off or micro-rating on AUTO_VERIFIED_CLOSED is rejected with 409
    $this->actingAs($warga, 'sanctum')
        ->postJson("/api/v1/reports/{$ticketCode}/sign-off/approve")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');

    $this->actingAs($warga, 'sanctum')
        ->postJson("/api/v1/reports/{$ticketCode}/rating", [
            'speed_score' => 5,
            'physical_quality_score' => 5,
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');
});
