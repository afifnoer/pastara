<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\District;
use App\Models\OpdUnit;
use App\Models\Report;
use App\Models\Village;

class PublicPortalService
{
    public function __construct(
        private readonly SlaEngineService $slaEngine,
    ) {}

    /**
     * Return active reference master data for public portal filters and report submission forms.
     *
     * @return array<string, mixed>
     */
    public function getReferenceData(): array
    {
        $categories = Category::query()
            ->active()
            ->orderBy('code')
            ->get([
                'id',
                'code',
                'name',
                'description',
                'default_opd_unit_id',
                'route_by_village',
                'default_sla_hours',
                'issue_type',
                'duplicate_radius_m',
                'geotag_tolerance_m',
                'recommend_secret',
            ]);

        $opdUnits = OpdUnit::query()
            ->active()
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'level', 'description']);

        $districts = District::query()
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $villages = Village::query()
            ->orderBy('name')
            ->get(['id', 'district_id', 'opd_unit_id', 'name', 'type']);

        return [
            'categories' => $categories,
            'opd_units' => $opdUnits,
            'districts' => $districts,
            'villages' => $villages,
            'cilacap_bounds' => config('pastara.cilacap'),
        ];
    }

    /**
     * Return public map markers, strictly excluding secret reports (Hukum 4 — BR-026).
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function getMapMarkers(array $filters): array
    {
        $query = Report::query()
            ->with([
                'category:id,code,name,issue_type',
                'opdUnit:id,code,name',
            ])
            ->where('is_secret', false);

        if (isset($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        if (isset($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (isset($filters['opd_unit_id'])) {
            $query->where('opd_unit_id', (int) $filters['opd_unit_id']);
        }

        if (isset($filters['district_id'])) {
            $query->where('district_id', (int) $filters['district_id']);
        }

        if (! empty($filters['bbox'])) {
            $parts = array_map('floatval', explode(',', (string) $filters['bbox']));
            if (count($parts) === 4) {
                [$minLat, $minLng, $maxLat, $maxLng] = $parts;
                $query->whereBetween('latitude', [min($minLat, $maxLat), max($minLat, $maxLat)])
                    ->whereBetween('longitude', [min($minLng, $maxLng), max($minLng, $maxLng)]);
            }
        }

        return $query
            ->orderByDesc('created_at')
            ->get()
            ->map(function (Report $report): array {
                return [
                    'id' => $report->id,
                    'ticket_code' => $report->ticket_code,
                    'title' => $report->title,
                    'status' => $report->status->value,
                    'status_label' => $report->status->label(),
                    'status_badge_color' => $report->status->badgeColor(),
                    'latitude' => (float) $report->latitude,
                    'longitude' => (float) $report->longitude,
                    'upvote_count' => $report->upvote_count,
                    'sla_zone' => $this->slaEngine->calculateZone($report)->value,
                    'sla_remaining_seconds' => $this->slaEngine->remainingSeconds($report),
                    'is_sla_overdue' => $report->is_sla_overdue,
                    'is_milestone_overdue' => $report->is_milestone_overdue,
                    'category' => $report->category !== null ? [
                        'id' => $report->category->id,
                        'code' => $report->category->code,
                        'name' => $report->category->name,
                        'issue_type' => $report->category->issue_type->value,
                    ] : null,
                    'opd_unit' => $report->opdUnit !== null ? [
                        'id' => $report->opdUnit->id,
                        'code' => $report->opdUnit->code,
                        'name' => $report->opdUnit->name,
                    ] : null,
                    'created_at' => $report->created_at?->toIso8601ZuluString(),
                ];
            })
            ->values()
            ->all();
    }
}
