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
use App\Models\User;
use App\Models\Village;
use App\Services\GeotagValidationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function createGeotagReportFixture(IssueType $issueType = IssueType::POINT, int $toleranceM = 100): array
{
    $unit = OpdUnit::create(['code' => 'DISHUB', 'name' => 'Dishub', 'level' => UnitLevel::OPD]);
    $district = District::create(['code' => '33.01.22', 'name' => 'Cilacap Tengah']);
    $village = Village::create(['district_id' => $district->id, 'name' => 'Sidanegara', 'type' => 'KELURAHAN']);
    $reporter = User::create([
        'name' => 'Warga Geotag',
        'email' => 'warga.geo@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::WARGA,
    ]);
    $officer = User::create([
        'name' => 'Petugas Geotag',
        'email' => 'petugas.geo@pastara.id',
        'password' => 'Pastara2027!',
        'role' => UserRole::PETUGAS,
        'opd_unit_id' => $unit->id,
    ]);
    $category = Category::create([
        'code' => 'CAT-02',
        'name' => 'PJU',
        'default_opd_unit_id' => $unit->id,
        'default_sla_hours' => 24,
        'issue_type' => $issueType,
        'duplicate_radius_m' => 200,
        'geotag_tolerance_m' => $toleranceM,
    ]);

    $report = Report::create([
        'ticket_code' => 'PST-GEO0001',
        'reporter_id' => $reporter->id,
        'category_id' => $category->id,
        'opd_unit_id' => $unit->id,
        'assigned_officer_id' => $officer->id,
        'district_id' => $district->id,
        'village_id' => $village->id,
        'title' => 'Laporan PJU Geotag Test',
        'description_sanitized' => 'Pengujian pembacaan EXIF GPS dan sanitasi WebP.',
        'latitude' => -7.7188000,
        'longitude' => 109.0154000,
        'status' => ReportStatus::IN_PROGRESS,
        'issue_type_snapshot' => $issueType,
        'duplicate_radius_snapshot_m' => 200,
        'geotag_tolerance_snapshot_m' => $toleranceM,
        'sla_hours_snapshot' => 24,
    ]);

    return compact('report', 'officer');
}

function buildJpegBinary(?float $lat = null, ?float $lng = null, ?string $dateTimeOriginal = null): string
{
    $img = imagecreatetruecolor(640, 480);
    $bg = imagecolorallocate($img, 45, 110, 180);
    imagefill($img, 0, 0, $bg);

    ob_start();
    imagejpeg($img, null, 90);
    $jpeg = (string) ob_get_clean();
    imagedestroy($img);

    if ($lat === null && $lng === null && $dateTimeOriginal === null) {
        return $jpeg;
    }

    $app1Payload = buildExifApp1Payload($lat, $lng, $dateTimeOriginal);
    $app1Segment = "\xFF\xE1".pack('n', strlen($app1Payload) + 2).$app1Payload;

    // Insert APP1 right after JPEG SOI marker (\xFF\xD8)
    return substr($jpeg, 0, 2).$app1Segment.substr($jpeg, 2);
}

function buildExifApp1Payload(?float $lat, ?float $lng, ?string $dateTimeOriginal): string
{
    // Little-endian TIFF header: "II", 42, offset to IFD0 = 8
    $tiff = "II\x2A\x00".pack('V', 8);

    $ifd0Entries = [];
    $extraData = '';
    $baseOffset = 8; // Start of IFD0

    $hasExif = $dateTimeOriginal !== null;
    $hasGps = $lat !== null && $lng !== null;

    $ifd0Count = ($hasExif ? 1 : 0) + ($hasGps ? 1 : 0);
    $ifd0Size = 2 + ($ifd0Count * 12) + 4;

    $exifIfdOffset = $baseOffset + $ifd0Size;
    $exifIfdData = '';
    $exifExtra = '';

    if ($hasExif) {
        $dtStr = $dateTimeOriginal."\x00";
        $exifIfdSize = 2 + (1 * 12) + 4;
        $dtOffset = $exifIfdOffset + $exifIfdSize;
        // Tag 0x9003 DateTimeOriginal, ASCII (2), count 20
        $exifIfdData = pack('v', 1)
            .pack('vvVV', 0x9003, 2, strlen($dtStr), $dtOffset)
            .pack('V', 0);
        $exifExtra = $dtStr;
        $ifd0Entries[] = pack('vvVV', 0x8769, 4, 1, $exifIfdOffset);
    }

    $gpsIfdOffset = $exifIfdOffset + strlen($exifIfdData) + strlen($exifExtra);
    $gpsIfdData = '';
    $gpsExtra = '';

    if ($hasGps) {
        // 4 tags: 0x0001 GPSLatitudeRef, 0x0002 GPSLatitude, 0x0003 GPSLongitudeRef, 0x0004 GPSLongitude
        $gpsIfdSize = 2 + (4 * 12) + 4;
        $latRef = ($lat < 0 ? 'S' : 'N')."\x00\x00\x00"; // inline 4-byte padded ASCII count=2
        $lngRef = ($lng < 0 ? 'W' : 'E')."\x00\x00\x00";

        $latDmsOffset = $gpsIfdOffset + $gpsIfdSize;
        $lngDmsOffset = $latDmsOffset + 24; // 3 rationals * 8 bytes

        $gpsIfdData = pack('v', 4)
            .pack('vvV', 0x0001, 2, 2).$latRef
            .pack('vvVV', 0x0002, 5, 3, $latDmsOffset)
            .pack('vvV', 0x0003, 2, 2).$lngRef
            .pack('vvVV', 0x0004, 5, 3, $lngDmsOffset)
            .pack('V', 0);

        $gpsExtra = packDmsRationals(abs($lat)).packDmsRationals(abs($lng));
        $ifd0Entries[] = pack('vvVV', 0x8825, 4, 1, $gpsIfdOffset);
    }

    $ifd0 = pack('v', $ifd0Count).implode('', $ifd0Entries).pack('V', 0);

    return "Exif\x00\x00".$tiff.$ifd0.$exifIfdData.$exifExtra.$gpsIfdData.$gpsExtra;
}

function packDmsRationals(float $absDecimal): string
{
    $deg = (int) floor($absDecimal);
    $remMin = ($absDecimal - $deg) * 60.0;
    $min = (int) floor($remMin);
    $secScaled = (int) round(($remMin - $min) * 60.0 * 10000);

    return pack('VVVVVV', $deg, 1, $min, 1, $secScaled, 10000);
}

function makeTempUploadedJpeg(string $binary, string $name = 'evidence.jpg'): UploadedFile
{
    $tmpPath = tempnam(sys_get_temp_dir(), 'pastara_img_');
    file_put_contents($tmpPath, $binary);

    return new UploadedFile($tmpPath, $name, 'image/jpeg', null, true);
}

it('reads EXIF GPS & timestamp, passes within tolerance, strips EXIF in WebP, and builds <= 200KB thumbnail (TC-F-12 & TC-I-03)', function (): void {
    Storage::fake('public');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 06:00:00', 'UTC'));

    ['report' => $report, 'officer' => $officer] = createGeotagReportFixture(IssueType::POINT, 100);
    $service = new GeotagValidationService;

    // ~20 meters from report (-7.7188, 109.0154), taken 2 hours ago in local WIB (11:00 WIB = 04:00 UTC)
    $jpeg = buildJpegBinary(-7.71862, 109.01540, '2026:11:22 11:00:00');
    $file = makeTempUploadedJpeg($jpeg);

    $photo = $service->processUpload(
        report: $report,
        file: $file,
        type: PhotoType::AFTER,
        cycle: 1,
        sequence: 1,
        uploadedByUserId: $officer->id,
    );

    expect($photo->has_geotag_warning)->toBeFalse()
        ->and($photo->distance_from_report_m)->toBeBetween(15, 25)
        ->and($photo->exif_taken_at?->toIso8601ZuluString())->toBe('2026-11-22T04:00:00Z')
        ->and(Storage::disk('public')->exists($photo->file_path))->toBeTrue()
        ->and(Storage::disk('public')->exists($photo->thumbnail_path))->toBeTrue();

    $storedClean = Storage::disk('public')->get($photo->file_path);
    $storedThumb = Storage::disk('public')->get($photo->thumbnail_path);

    expect(substr($storedClean, 0, 4))->toBe('RIFF')
        ->and(substr($storedClean, 8, 4))->toBe('WEBP')
        ->and(strlen($storedThumb))->toBeLessThanOrEqual(204800);

    // Ensure saved WebP contains no EXIF segment
    $tmpSaved = tempnam(sys_get_temp_dir(), 'pastara_webp_');
    file_put_contents($tmpSaved, $storedClean);
    expect(@exif_read_data($tmpSaved))->toBeFalse();
    @unlink($tmpSaved);

    CarbonImmutable::setTestNow();
});

it('flags geotag_warning when EXIF distance exceeds tolerance, EXIF age > 24h, or GPS/timestamp is missing (TC-F-12)', function (): void {
    Storage::fake('public');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 06:00:00', 'UTC'));

    ['report' => $report, 'officer' => $officer] = createGeotagReportFixture(IssueType::POINT, 100);
    $service = new GeotagValidationService;

    // 1. Out of tolerance (~620 m away)
    $farFile = makeTempUploadedJpeg(buildJpegBinary(-7.71320, 109.01540, '2026:11:22 05:00:00'), 'far.jpg');
    $farPhoto = $service->processUpload($report, $farFile, PhotoType::AFTER, 1, 1, null, null, $officer->id);
    expect($farPhoto->has_geotag_warning)->toBeTrue()
        ->and($farPhoto->distance_from_report_m)->toBeGreaterThan(500);

    // 2. Old EXIF timestamp (> 24 hours ago) even within 20 m
    $oldFile = makeTempUploadedJpeg(buildJpegBinary(-7.71865, 109.01540, '2026:11:20 04:00:00'), 'old.jpg');
    $oldPhoto = $service->processUpload($report, $oldFile, PhotoType::AFTER, 1, 2, null, null, $officer->id);
    expect($oldPhoto->has_geotag_warning)->toBeTrue()
        ->and($oldPhoto->distance_from_report_m)->toBeLessThan(100);

    // 3. No EXIF at all, with fallback device GPS within 20 m -> still warns due to missing EXIF timestamp (BR-019)
    $noExifFallback = makeTempUploadedJpeg(buildJpegBinary(), 'noexif-dev.jpg');
    $fallbackPhoto = $service->processUpload($report, $noExifFallback, PhotoType::AFTER, 1, 3, -7.71875, 109.01540, $officer->id);
    expect($fallbackPhoto->has_geotag_warning)->toBeTrue()
        ->and($fallbackPhoto->exif_latitude)->toBe(-7.71875)
        ->and($fallbackPhoto->distance_from_report_m)->toBeLessThan(20);

    // 4. No EXIF and no device GPS -> warns and distance is null
    $emptyGeo = makeTempUploadedJpeg(buildJpegBinary(), 'empty.jpg');
    $emptyPhoto = $service->processUpload($report, $emptyGeo, PhotoType::AFTER, 2, 1, null, null, $officer->id);
    expect($emptyPhoto->has_geotag_warning)->toBeTrue()
        ->and($emptyPhoto->distance_from_report_m)->toBeNull();

    // 5. BEFORE photo does not trigger geotag warning
    $beforeFile = makeTempUploadedJpeg(buildJpegBinary(), 'before.jpg');
    $beforePhoto = $service->processUpload($report, $beforeFile, PhotoType::BEFORE, 1, 1, null, null, $officer->id);
    expect($beforePhoto->has_geotag_warning)->toBeFalse();

    CarbonImmutable::setTestNow();
});
