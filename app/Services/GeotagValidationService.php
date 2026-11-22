<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PhotoType;
use App\Models\Report;
use App\Models\ReportPhoto;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

class GeotagValidationService
{
    private const MAX_IMAGE_WIDTH = 1920;

    private const THUMBNAIL_WIDTH = 480;

    private const THUMBNAIL_MAX_BYTES = 204800; // 200 KB

    private ImageManager $imageManager;

    public function __construct(?ImageManager $imageManager = null)
    {
        $this->imageManager = $imageManager ?? new ImageManager(Driver::class);
    }

    /**
     * Process an uploaded report photo: read EXIF (for AFTER), strip EXIF via WebP re-encoding,
     * generate a <= 200 KB thumbnail, store to public disk, and persist ReportPhoto.
     */
    public function processUpload(
        Report $report,
        UploadedFile $file,
        PhotoType $type,
        int $cycle,
        int $sequence,
        ?float $deviceLat = null,
        ?float $deviceLng = null,
        ?int $uploadedByUserId = null,
    ): ReportPhoto {
        $realPath = $file->getRealPath();
        if ($realPath === false) {
            $realPath = $file->getPathname();
        }

        $exifLat = null;
        $exifLng = null;
        $exifTakenAt = null;
        $distanceM = null;
        $hasWarning = false;

        if ($type === PhotoType::AFTER) {
            $extracted = $this->extractExifMetadata($realPath);
            $exifLat = $extracted['latitude'] ?? $deviceLat;
            $exifLng = $extracted['longitude'] ?? $deviceLng;
            $exifTakenAt = $extracted['taken_at'];

            if ($exifLat !== null && $exifLng !== null) {
                $distanceM = $this->haversineDistanceMeters(
                    (float) $report->latitude,
                    (float) $report->longitude,
                    $exifLat,
                    $exifLng
                );
            }

            $hasWarning = $this->evaluateGeotagWarning(
                $exifLat,
                $exifLng,
                $distanceM,
                $report->geotag_tolerance_snapshot_m,
                $exifTakenAt
            );
        }

        $now = CarbonImmutable::now('UTC');
        $uuid = (string) Str::uuid();
        $directory = sprintf('reports/%s/%s', $now->format('Y'), $now->format('m'));
        $filePath = "{$directory}/{$uuid}.webp";
        $thumbPath = "{$directory}/{$uuid}-thumb.webp";

        $image = $this->imageManager->decodePath($realPath);
        $image->orient();
        $image->scaleDown(width: self::MAX_IMAGE_WIDTH);

        $cleanBinary = (string) $image->encode(new WebpEncoder(quality: 85, strip: true));
        Storage::disk('public')->put($filePath, $cleanBinary);

        $thumbBinary = $this->encodeThumbnailWithinLimit(clone $image);
        Storage::disk('public')->put($thumbPath, $thumbBinary);

        return ReportPhoto::create([
            'report_id' => $report->id,
            'uploaded_by_user_id' => $uploadedByUserId,
            'photo_type' => $type,
            'cycle' => $cycle,
            'sequence' => $sequence,
            'file_path' => $filePath,
            'thumbnail_path' => $thumbPath,
            'exif_latitude' => $exifLat,
            'exif_longitude' => $exifLng,
            'exif_taken_at' => $exifTakenAt,
            'distance_from_report_m' => $distanceM,
            'has_geotag_warning' => $hasWarning,
            'created_at' => $now,
        ]);
    }

    /**
     * Process 1..3 AFTER photos and return all persisted ReportPhoto models.
     *
     * @param  list<UploadedFile>  $files
     * @return list<ReportPhoto>
     */
    public function processAfterPhotos(
        Report $report,
        array $files,
        ?float $deviceLat = null,
        ?float $deviceLng = null,
        ?int $uploadedByUserId = null,
        ?int $cycle = null,
    ): array {
        $targetCycle = $cycle ?? $report->current_cycle;
        $photos = [];

        foreach (array_values($files) as $index => $file) {
            $photos[] = $this->processUpload(
                report: $report,
                file: $file,
                type: PhotoType::AFTER,
                cycle: $targetCycle,
                sequence: $index + 1,
                deviceLat: $deviceLat,
                deviceLng: $deviceLng,
                uploadedByUserId: $uploadedByUserId,
            );
        }

        return $photos;
    }

    /**
     * Extract GPS coordinates and capture timestamp from EXIF headers in memory.
     *
     * @return array{latitude: ?float, longitude: ?float, taken_at: ?CarbonImmutable}
     */
    public function extractExifMetadata(string $filePath): array
    {
        if (! function_exists('exif_read_data') || ! is_file($filePath)) {
            return ['latitude' => null, 'longitude' => null, 'taken_at' => null];
        }

        try {
            $exif = @exif_read_data($filePath, 'EXIF,GPS,IFD0', true);
        } catch (Throwable) {
            return ['latitude' => null, 'longitude' => null, 'taken_at' => null];
        }

        if (! is_array($exif)) {
            return ['latitude' => null, 'longitude' => null, 'taken_at' => null];
        }

        $gps = is_array($exif['GPS'] ?? null) ? $exif['GPS'] : [];
        $exifSection = is_array($exif['EXIF'] ?? null) ? $exif['EXIF'] : [];
        $ifd0 = is_array($exif['IFD0'] ?? null) ? $exif['IFD0'] : [];

        $lat = null;
        $lng = null;

        if (isset($gps['GPSLatitude'], $gps['GPSLatitudeRef']) && is_array($gps['GPSLatitude'])) {
            $lat = $this->parseGpsDms($gps['GPSLatitude'], (string) $gps['GPSLatitudeRef']);
        }

        if (isset($gps['GPSLongitude'], $gps['GPSLongitudeRef']) && is_array($gps['GPSLongitude'])) {
            $lng = $this->parseGpsDms($gps['GPSLongitude'], (string) $gps['GPSLongitudeRef']);
        }

        $rawDate = $exifSection['DateTimeOriginal'] ?? $ifd0['DateTime'] ?? null;
        $offset = $exifSection['OffsetTimeOriginal'] ?? $exifSection['OffsetTime'] ?? null;
        $takenAt = $this->parseExifTimestamp(
            is_string($rawDate) ? $rawDate : null,
            is_string($offset) ? $offset : null
        );

        return [
            'latitude' => $lat,
            'longitude' => $lng,
            'taken_at' => $takenAt,
        ];
    }

    /**
     * Calculate Haversine ground distance in meters between two coordinates.
     */
    public function haversineDistanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $earthRadiusM = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        $c = 2 * asin(min(1.0, sqrt($a)));

        return (int) round($earthRadiusM * $c);
    }

    private function evaluateGeotagWarning(
        ?float $lat,
        ?float $lng,
        ?int $distanceM,
        int $toleranceM,
        ?CarbonImmutable $takenAt,
    ): bool {
        if ($lat === null || $lng === null || $distanceM === null) {
            return true;
        }

        if ($distanceM > $toleranceM) {
            return true;
        }

        if ($takenAt === null) {
            return true;
        }

        $maxAgeSeconds = (int) config('pastara.exif_max_age_hours', 24) * 3600;

        return abs(CarbonImmutable::now('UTC')->diffInSeconds($takenAt)) > $maxAgeSeconds;
    }

    /**
     * @param  array<int, mixed>  $dms
     */
    private function parseGpsDms(array $dms, string $hemisphere): ?float
    {
        if (count($dms) < 3) {
            return null;
        }

        $degrees = $this->parseExifRational($dms[0]);
        $minutes = $this->parseExifRational($dms[1]);
        $seconds = $this->parseExifRational($dms[2]);

        if ($degrees === null || $minutes === null || $seconds === null) {
            return null;
        }

        $decimal = $degrees + ($minutes / 60.0) + ($seconds / 3600.0);
        $hemi = strtoupper(trim($hemisphere));

        if ($hemi === 'S' || $hemi === 'W') {
            $decimal *= -1;
        }

        return round($decimal, 7);
    }

    private function parseExifRational(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $parts = explode('/', $value);
        if (count($parts) === 2) {
            $numerator = (float) $parts[0];
            $denominator = (float) $parts[1];

            return $denominator != 0.0 ? $numerator / $denominator : null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function parseExifTimestamp(?string $rawDate, ?string $offset): ?CarbonImmutable
    {
        if ($rawDate === null || trim($rawDate) === '') {
            return null;
        }

        $timezone = ($offset !== null && preg_match('/^[+-]\d{2}:\d{2}$/', trim($offset)) === 1)
            ? trim($offset)
            : 'UTC';

        try {
            $parsed = CarbonImmutable::createFromFormat('Y:m:d H:i:s', trim($rawDate), $timezone);

            return $parsed?->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function encodeThumbnailWithinLimit(ImageInterface $thumbImage): string
    {
        $thumbImage->scaleDown(width: self::THUMBNAIL_WIDTH);

        foreach ([80, 65, 50, 35] as $quality) {
            $binary = (string) $thumbImage->encode(new WebpEncoder(quality: $quality, strip: true));
            if (strlen($binary) <= self::THUMBNAIL_MAX_BYTES) {
                return $binary;
            }
        }

        return (string) $thumbImage->encode(new WebpEncoder(quality: 25, strip: true));
    }
}
