<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PhotoType;
use App\Enums\ReportStatus;
use App\Models\Category;
use App\Models\ReportPhoto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class HaversineDuplicateService
{
    /**
     * Find up to 5 active non-secret reports in the same category within dynamic radius.
     *
     * @return array{radius_m: int, matches: list<array<string, mixed>>}
     */
    public function findNearbyDuplicates(int $categoryId, float $lat, float $lng): array
    {
        $category = Category::query()->findOrFail($categoryId);
        $radiusM = $category->duplicate_radius_m;

        $haversineExpr = <<<'SQL'
            6371000 * 2 * ASIN(
                SQRT(
                    LEAST(
                        1.0,
                        POWER(SIN(RADIANS(r.latitude - o.lat) / 2), 2) +
                        COS(RADIANS(o.lat)) * COS(RADIANS(r.latitude)) *
                        POWER(SIN(RADIANS(r.longitude - o.lng) / 2), 2)
                    )
                )
            )
        SQL;

        $finalStatuses = [
            ReportStatus::REJECTED->value,
            ReportStatus::VERIFIED_CLOSED->value,
            ReportStatus::AUTO_VERIFIED_CLOSED->value,
            ReportStatus::CLOSED_BY_AUDIT->value,
        ];
        $statusPlaceholders = implode(', ', array_map(
            static fn (int $idx): string => ":final_status_{$idx}",
            array_keys($finalStatuses)
        ));

        $sql = <<<SQL
            WITH origin AS (
                SELECT
                    CAST(:lat AS DOUBLE PRECISION) AS lat,
                    CAST(:lng AS DOUBLE PRECISION) AS lng
            )
            SELECT
                r.id,
                r.ticket_code,
                r.title,
                r.status,
                r.upvote_count,
                r.latitude,
                r.longitude,
                r.created_at,
                ROUND({$haversineExpr})::INTEGER AS distance_m
            FROM reports r
            CROSS JOIN origin o
            WHERE r.category_id = :category_id
              AND r.is_secret = FALSE
              AND r.status NOT IN ({$statusPlaceholders})
              AND r.latitude BETWEEN (o.lat - 0.01) AND (o.lat + 0.01)
              AND r.longitude BETWEEN (o.lng - 0.01) AND (o.lng + 0.01)
              AND ({$haversineExpr}) <= :radius_m
            ORDER BY distance_m ASC
            LIMIT 5
        SQL;

        $bindings = [
            'category_id' => $categoryId,
            'lat' => $lat,
            'lng' => $lng,
            'radius_m' => $radiusM,
        ];

        foreach ($finalStatuses as $idx => $status) {
            $bindings["final_status_{$idx}"] = $status;
        }

        $rows = DB::select($sql, $bindings);

        if ($rows === []) {
            return [
                'radius_m' => $radiusM,
                'matches' => [],
            ];
        }

        $reportIds = array_map(static fn (object $row): int => (int) $row->id, $rows);
        $thumbnails = ReportPhoto::query()
            ->whereIn('report_id', $reportIds)
            ->where('photo_type', PhotoType::BEFORE)
            ->where('cycle', 1)
            ->where('sequence', 1)
            ->pluck('thumbnail_path', 'report_id');

        $matches = [];
        foreach ($rows as $row) {
            $reportId = (int) $row->id;
            $thumbPath = $thumbnails->get($reportId);
            $statusEnum = ReportStatus::from((string) $row->status);

            $matches[] = [
                'id' => $reportId,
                'ticket_code' => (string) $row->ticket_code,
                'title' => (string) $row->title,
                'status' => $statusEnum->value,
                'status_label' => $statusEnum->label(),
                'upvote_count' => (int) $row->upvote_count,
                'latitude' => (float) $row->latitude,
                'longitude' => (float) $row->longitude,
                'distance_m' => (int) $row->distance_m,
                'thumbnail_url' => $thumbPath !== null ? Storage::disk('public')->url($thumbPath) : null,
                'created_at' => (string) $row->created_at,
            ];
        }

        return [
            'radius_m' => $radiusM,
            'matches' => $matches,
        ];
    }
}
