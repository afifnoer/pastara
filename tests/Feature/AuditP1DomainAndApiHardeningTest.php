<?php

declare(strict_types=1);

use App\Enums\PhotoType;
use App\Enums\ReportStatus;
use App\Enums\SlaZone;
use App\Models\Category;
use App\Models\OpdUnit;
use App\Models\Report;
use App\Models\ReportUpvote;
use App\Models\User;
use App\Models\Village;
use App\Services\GeotagValidationService;
use App\Services\ReportStateMachineService;
use App\Services\SlaEngineService;
use Carbon\CarbonImmutable;
use Database\Seeders\CilacapSimulationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('executes GET /public/scorecard strictly read-only with bounded SQL queries and aligned CLOSED_BY_AUDIT metrics (H-03, H-04)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);

    // Ensure opd_units.metrics_updated_at starts null so any unexpected UPDATE is immediately caught
    OpdUnit::query()->update(['metrics_updated_at' => null]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = $this->getJson('/api/v1/public/scorecard');

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $response->assertStatus(200);

    // Must execute ZERO UPDATE/INSERT/DELETE statements on GET
    $writeQueries = collect($queries)->filter(
        static fn (array $q): bool => preg_match('/^\s*(update|insert|delete)\b/i', $q['query']) === 1
    );
    expect($writeQueries)->toBeEmpty()
        ->and(count($queries))->toBeLessThanOrEqual(6);

    // metrics_updated_at must remain null (unmodified by public GET)
    expect(OpdUnit::query()->whereNotNull('metrics_updated_at')->count())->toBe(0);

    // Summary verified_closed_physical matches sum(opd_scorecard[].physical_closed_count)
    $summary = $response->json('data.summary');
    $scorecard = collect($response->json('data.opd_scorecard'));
    expect($summary['verified_closed_physical'])->toBe($scorecard->sum('physical_closed_count'))
        ->and($summary['closed_by_audit'])->toBe($scorecard->sum('closed_by_audit_count'))
        ->and($summary['overdue_reports'])->toBe($scorecard->sum('overdue_count'));
});

it('classifies SUBMITTED and ROUTED_TO_UNIT as STOPPED rather than PAUSED in SlaEngineService (H-08)', function (): void {
    $sla = new SlaEngineService;

    expect($sla->calculateZone(new Report(['status' => ReportStatus::SUBMITTED])))->toBe(SlaZone::STOPPED)
        ->and($sla->calculateZone(new Report(['status' => ReportStatus::ROUTED_TO_UNIT])))->toBe(SlaZone::STOPPED)
        ->and($sla->calculateZone(new Report(['status' => ReportStatus::PENDING_CITIZEN_SIGN_OFF])))->toBe(SlaZone::PAUSED)
        ->and($sla->calculateZone(new Report(['status' => ReportStatus::DISPUTED])))->toBe(SlaZone::PAUSED);
});

it('interprets EXIF timestamps without offset in Asia/Jakarta WIB and flags future EXIF timestamps (H-07, M-07)', function (): void {
    Storage::fake('public');
    $this->seed(CilacapSimulationSeeder::class);

    // Current server time: 2026-11-22 06:00:00 UTC = 2026-11-22 13:00:00 WIB
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 06:00:00', 'UTC'));

    $service = new GeotagValidationService;
    $report = Report::where('ticket_code', 'PST-ESCROW01')->firstOrFail();
    $officer = User::where('email', 'petugas.dishub@pastara.id')->firstOrFail();

    // Build JPEG with EXIF 12:00:00 WIB (1 hour ago = 05:00:00 UTC) -> valid, no warning
    $makeExifJpeg = static function (float $lat, float $lng, string $dateTime): string {
        $img = imagecreatetruecolor(80, 60);
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

        $dateBytes = $dateTime."\0";
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

        return substr($raw, 0, 2).$app1.substr($raw, 2);
    };

    $tmpValid = tempnam(sys_get_temp_dir(), 'pastara_p1_wib_');
    file_put_contents($tmpValid, $makeExifJpeg((float) $report->latitude, (float) $report->longitude, '2026:11:22 12:00:00'));

    $tmpFuture = tempnam(sys_get_temp_dir(), 'pastara_p1_fut_');
    file_put_contents($tmpFuture, $makeExifJpeg((float) $report->latitude, (float) $report->longitude, '2026:11:22 15:00:00'));

    try {
        $validPhoto = $service->processUpload(
            $report,
            new UploadedFile($tmpValid, 'wib.jpg', 'image/jpeg', null, true),
            PhotoType::AFTER,
            1,
            1,
            null,
            null,
            $officer->id
        );

        expect($validPhoto->has_geotag_warning)->toBeFalse()
            ->and($validPhoto->exif_taken_at?->toIso8601ZuluString())->toBe('2026-11-22T05:00:00Z');

        // Future timestamp (15:00 WIB = 2 hours in future) MUST trigger geotag_warning
        $futurePhoto = $service->processUpload(
            $report,
            new UploadedFile($tmpFuture, 'future.jpg', 'image/jpeg', null, true),
            PhotoType::AFTER,
            1,
            2,
            null,
            null,
            $officer->id
        );

        expect($futurePhoto->has_geotag_warning)->toBeTrue();
    } finally {
        @unlink($tmpValid);
        @unlink($tmpFuture);
        CarbonImmutable::setTestNow();
    }
});

it('accepts overdue_only=true query string, resolves has_upvoted via Sanctum bearer, and avoids pre-auth eager loading on secret detail (M-02, M-03, M-03b)', function (): void {
    $this->seed(CilacapSimulationSeeder::class);

    // 1. overdue_only=true & false accepted cleanly (not 422)
    $this->getJson('/api/v1/public/reports?overdue_only=true')->assertStatus(200);
    $this->getJson('/api/v1/public/reports?overdue_only=false')->assertStatus(200);

    // 2. has_upvoted resolves accurately for authenticated Sanctum bearer user on public detail
    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $otherWarga = User::where('role', 'WARGA')->where('id', '!=', $warga->id)->firstOrFail();
    $escrow = Report::where('ticket_code', 'PST-ESCROW01')->firstOrFail();

    ReportUpvote::firstOrCreate(
        ['report_id' => $escrow->id, 'user_id' => $otherWarga->id],
        ['is_watching' => true, 'created_at' => now('UTC')]
    );

    $this->getJson('/api/v1/public/reports/PST-ESCROW01')
        ->assertStatus(200)
        ->assertJsonPath('data.has_upvoted', false);

    $token = $otherWarga->createToken('test')->plainTextToken;
    $this->withToken($token)
        ->getJson('/api/v1/public/reports/PST-ESCROW01')
        ->assertStatus(200)
        ->assertJsonPath('data.has_upvoted', true);

    $this->flushHeaders();

    // 3. Secret ticket detail denies 404 BEFORE eager loading 11 detail relations (max 2 queries)
    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->getJson('/api/v1/public/reports/PST-PURW2027')->assertStatus(404);

    $secretQueries = DB::getQueryLog();
    DB::disableQueryLog();

    expect(count($secretQueries))->toBeLessThanOrEqual(2);
});

it('preserves phone when omitted on PATCH /auth/profile, omits officer real name in TR-04 public audit note, and removes Notifiable conflict (M-01, M-04, M-14)', function (): void {
    Storage::fake('public');
    $this->seed(CilacapSimulationSeeder::class);

    // 1. PATCH /auth/profile without phone preserves existing phone
    $warga = User::where('email', 'warga.demo@pastara.id')->firstOrFail();
    $originalPhone = $warga->phone;
    expect($originalPhone)->not->toBeNull();

    $token = $warga->createToken('test')->plainTextToken;
    $this->withToken($token)
        ->patchJson('/api/v1/auth/profile', ['name' => 'Warga Nama Baru'])
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'Warga Nama Baru')
        ->assertJsonPath('data.phone', $originalPhone);

    // 2. TR-04 default public audit note does not expose officer personal name (BR-039)
    $service = app(ReportStateMachineService::class);
    $admin = User::where('email', 'admin.dpupr@pastara.id')->firstOrFail();
    $officer = User::where('email', 'petugas.dpupr@pastara.id')->firstOrFail();
    $cat = Category::where('code', 'CAT-01')->firstOrFail();
    $village = Village::query()->firstOrFail();

    $img = imagecreatetruecolor(60, 60);
    ob_start();
    imagejpeg($img, null, 85);
    $jpeg = (string) ob_get_clean();
    imagedestroy($img);
    $tmp = tempnam(sys_get_temp_dir(), 'pastara_p1_tr04_');
    file_put_contents($tmp, $jpeg);

    try {
        $created = $service->createAndRoute($warga, [
            'category_id' => $cat->id,
            'district_id' => $village->district_id,
            'village_id' => $village->id,
            'title' => 'Test anonim petugas TR-04',
            'description' => 'Memastikan nama petugas tidak bocor di audit publik.',
            'latitude' => -7.7188,
            'longitude' => 109.0154,
        ], [new UploadedFile($tmp, 'b.jpg', 'image/jpeg', null, true)]);

        $assigned = $service->assignOperational($created['report'], $admin, $officer);
        $assignedLog = $assigned->auditLogs()->where('action_type', 'ASSIGNED')->firstOrFail();
        expect($assignedLog->note)->not->toContain($officer->name);
    } finally {
        @unlink($tmp);
    }

    // 3. User does not use conflicting Illuminate\Notifications\Notifiable trait
    expect(class_uses_recursive(User::class))->not->toContain(Notifiable::class);
});
